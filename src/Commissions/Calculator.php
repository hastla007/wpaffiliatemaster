<?php
/**
 * Pure commission calculation (no WordPress dependency → unit tested).
 *
 * Rule shape: [ id, scope (global|group|affiliate), scope_id, target (all|product|category|sku),
 *               target_value, rate_type (percent|fixed|exclude), rate, priority, active ]
 * Item shape: [ product_id, parent_id, sku, category_ids[], quantity, net_minor ]
 *
 * Resolution:
 *  - A matching "exclude" rule on any level excludes the item (explicit exclusions win).
 *  - Otherwise the most specific matching rule wins: affiliate > group > global,
 *    then product/SKU > category > all, then priority, then newest rule.
 *  - Without a matching rule the program default applies.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Commissions;

use WPAffiliateCaptain\Core\Money;

final class Calculator {

	const SCOPE_WEIGHT  = array(
		'affiliate' => 300,
		'group'     => 200,
		'global'    => 100,
	);
	const TARGET_WEIGHT = array(
		'product'  => 30,
		'sku'      => 30,
		'category' => 20,
		'all'      => 10,
	);

	/**
	 * Does a rule match an item/affiliate?
	 *
	 * @param array $rule         Rule.
	 * @param array $item         Item.
	 * @param int   $affiliate_id Affiliate.
	 * @param int   $group_id     Group.
	 * @return bool
	 */
	public static function matches( array $rule, array $item, $affiliate_id, $group_id ) {
		if ( isset( $rule['active'] ) && ! $rule['active'] ) {
			return false;
		}
		switch ( $rule['scope'] ) {
			case 'affiliate':
				if ( (int) $rule['scope_id'] !== (int) $affiliate_id ) {
					return false;
				}
				break;
			case 'group':
				if ( ! $group_id || (int) $rule['scope_id'] !== (int) $group_id ) {
					return false;
				}
				break;
			case 'global':
				break;
			default:
				return false;
		}
		$value = (string) $rule['target_value'];
		switch ( $rule['target'] ) {
			case 'all':
				return true;
			case 'product':
				$ids = array_map( 'intval', array_filter( array_map( 'trim', explode( ',', $value ) ) ) );
				return in_array( (int) $item['product_id'], $ids, true ) || ( ! empty( $item['parent_id'] ) && in_array( (int) $item['parent_id'], $ids, true ) );
			case 'category':
				$ids = array_map( 'intval', array_filter( array_map( 'trim', explode( ',', $value ) ) ) );
				return (bool) array_intersect( $ids, array_map( 'intval', (array) $item['category_ids'] ) );
			case 'sku':
				// Also used for course codes. Supports comma separated lists and trailing * wildcard.
				$sku = strtoupper( trim( (string) $item['sku'] ) );
				if ( '' === $sku ) {
					return false;
				}
				foreach ( array_filter( array_map( 'trim', explode( ',', strtoupper( $value ) ) ) ) as $pattern ) {
					if ( '*' === substr( $pattern, -1 ) ? 0 === strpos( $sku, substr( $pattern, 0, -1 ) ) : $sku === $pattern ) {
						return true;
					}
				}
				return false;
		}
		return false;
	}

	/**
	 * Pick the applicable rule.
	 *
	 * @param array $rules        Rules.
	 * @param array $item         Item.
	 * @param int   $affiliate_id Affiliate.
	 * @param int   $group_id     Group.
	 * @return array|null Rule or null (= default).
	 */
	public static function pick_rule( array $rules, array $item, $affiliate_id, $group_id ) {
		$best       = null;
		$best_score = null;
		foreach ( $rules as $rule ) {
			if ( ! self::matches( $rule, $item, $affiliate_id, $group_id ) ) {
				continue;
			}
			if ( 'exclude' === $rule['rate_type'] ) {
				return $rule;
			}
			$score = array(
				self::SCOPE_WEIGHT[ $rule['scope'] ] + self::TARGET_WEIGHT[ $rule['target'] ],
				(int) $rule['priority'],
				(int) $rule['id'],
			);
			if ( null === $best_score || $score > $best_score ) {
				$best       = $rule;
				$best_score = $score;
			}
		}
		return $best;
	}

	/**
	 * Calculate the commission for one line item.
	 *
	 * @param array  $item         Item (net_minor = net after discount, excl. tax).
	 * @param array  $rules        Rules.
	 * @param int    $affiliate_id Affiliate.
	 * @param int    $group_id     Group.
	 * @param array  $default      [ rate_type, rate ] program default (fixed = minor units).
	 * @return array amount_minor, base_minor, rate_type, rate, rule_id, excluded, rule
	 */
	public static function calculate( array $item, array $rules, $affiliate_id, $group_id, array $default ) {
		$base = max( 0, (int) $item['net_minor'] );
		$rule = self::pick_rule( $rules, $item, $affiliate_id, $group_id );

		if ( $rule && 'exclude' === $rule['rate_type'] ) {
			return array(
				'amount_minor' => 0,
				'base_minor'   => $base,
				'rate_type'    => 'exclude',
				'rate'         => '0',
				'rule_id'      => (int) $rule['id'],
				'excluded'     => true,
				'rule'         => $rule,
			);
		}

		$rate_type = $rule ? $rule['rate_type'] : $default['rate_type'];
		$rate      = $rule ? (string) $rule['rate'] : (string) $default['rate'];

		if ( 'fixed' === $rate_type ) {
			$qty    = max( 1, (int) ( isset( $item['quantity'] ) ? $item['quantity'] : 1 ) );
			$amount = (int) $rate * $qty;
			// A fixed commission never exceeds the net base of the line.
			$amount = min( $amount, $base );
		} else {
			$amount = Money::percent_of( $base, $rate );
		}

		return array(
			'amount_minor' => max( 0, $amount ),
			'base_minor'   => $base,
			'rate_type'    => $rate_type,
			'rate'         => $rate,
			'rule_id'      => $rule ? (int) $rule['id'] : 0,
			'excluded'     => false,
			'rule'         => $rule,
		);
	}

	/**
	 * Adjustment for a (partial) refund of a line: negative, pro rata, capped so
	 * the total of all adjustments never exceeds the original commission.
	 *
	 * @param int $original_amount    Original commission (minor).
	 * @param int $original_base      Original base (minor).
	 * @param int $already_adjusted   Sum of earlier adjustments (<= 0).
	 * @param int $refunded_net_minor Refunded net amount of the line (positive).
	 * @param bool $full              Reverse everything that remains.
	 * @return int Adjustment (<= 0).
	 */
	public static function refund_adjustment( $original_amount, $original_base, $already_adjusted, $refunded_net_minor, $full = false ) {
		$remaining = max( 0, (int) $original_amount + (int) $already_adjusted );
		if ( 0 === $remaining ) {
			return 0;
		}
		if ( $full || (int) $original_base <= 0 ) {
			return -$remaining;
		}
		$adj = Money::prorate( (int) $original_amount, abs( (int) $refunded_net_minor ), (int) $original_base );
		return -min( $remaining, max( 0, $adj ) );
	}
}
