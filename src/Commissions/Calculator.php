<?php
/**
 * Pure commission calculation (no WordPress dependency → unit tested).
 *
 * Rule shape: [ id, scope (global|group|affiliate), scope_id, target (all|product|category|tag|sku),
 *               target_value, rate_type (percent|fixed|exclude), rate, priority, active ]
 * Item shape: [ product_id, parent_id, sku, category_ids[], category_ancestor_ids[], quantity, net_minor ]
 *
 * Resolution (precedence "scope", the default):
 *  - A matching "exclude" rule on any level excludes the item (explicit exclusions win).
 *  - Otherwise the most specific matching rule wins: affiliate > group > global,
 *    then product/SKU > category/tag > all, then the finer match (variation before its parent
 *    product, exact SKU before a wildcard – longer prefix first –, own category before a parent
 *    category), then priority, then newest rule.
 *  - Without a matching rule the program default applies.
 *
 * Precedence "target" (setting rule_precedence): the target counts first (product/SKU >
 * category/tag > all), the scope second, and exclusions compete like any other rule – so a
 * partner rule for a product or category overrides a global exclusion of the same target.
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
		'tag'      => 20,
		'all'      => 10,
	);

	/** Rule precedence: scope first (default) or target first. */
	const PRECEDENCES = array( 'scope', 'target' );

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
				// Rules on a parent category apply to products of its subcategories as well.
				return self::precision( $rule, $item ) > 0;
			case 'tag':
				$ids = array_map( 'intval', array_filter( array_map( 'trim', explode( ',', $value ) ) ) );
				return (bool) array_intersect( $ids, array_map( 'intval', isset( $item['tag_ids'] ) ? (array) $item['tag_ids'] : array() ) );
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
	 * How exactly a matching rule fits the item within its target type (higher = finer):
	 * product: variation itself 2, its parent product 1; SKU: exact 1000, wildcard by prefix
	 * length; category: own category 2, a parent category 1. 0 = no match (category only).
	 *
	 * @param array $rule Rule.
	 * @param array $item Item.
	 * @return int
	 */
	public static function precision( array $rule, array $item ) {
		$value = (string) $rule['target_value'];
		switch ( $rule['target'] ) {
			case 'product':
				$ids = array_map( 'intval', array_filter( array_map( 'trim', explode( ',', $value ) ) ) );
				return in_array( (int) $item['product_id'], $ids, true ) ? 2 : 1;
			case 'sku':
				$sku  = strtoupper( trim( (string) $item['sku'] ) );
				$best = 0;
				foreach ( array_filter( array_map( 'trim', explode( ',', strtoupper( $value ) ) ) ) as $pattern ) {
					if ( $sku === $pattern ) {
						return 1000;
					}
					if ( '*' === substr( $pattern, -1 ) && 0 === strpos( $sku, substr( $pattern, 0, -1 ) ) ) {
						$best = max( $best, min( 999, strlen( $pattern ) ) );
					}
				}
				return $best;
			case 'category':
				$ids = array_map( 'intval', array_filter( array_map( 'trim', explode( ',', $value ) ) ) );
				if ( array_intersect( $ids, array_map( 'intval', isset( $item['category_ids'] ) ? (array) $item['category_ids'] : array() ) ) ) {
					return 2;
				}
				return array_intersect( $ids, array_map( 'intval', isset( $item['category_ancestor_ids'] ) ? (array) $item['category_ancestor_ids'] : array() ) ) ? 1 : 0;
		}
		return 0;
	}

	/**
	 * Pick the applicable rule.
	 *
	 * @param array  $rules        Rules.
	 * @param array  $item         Item.
	 * @param int    $affiliate_id Affiliate.
	 * @param int    $group_id     Group.
	 * @param string $precedence   scope (default) or target, see the class comment.
	 * @return array|null Rule or null (= default).
	 */
	public static function pick_rule( array $rules, array $item, $affiliate_id, $group_id, $precedence = 'scope' ) {
		$best       = null;
		$best_score = null;
		$by_target  = 'target' === $precedence;
		foreach ( $rules as $rule ) {
			if ( ! self::matches( $rule, $item, $affiliate_id, $group_id ) ) {
				continue;
			}
			if ( 'exclude' === $rule['rate_type'] && ! $by_target ) {
				return $rule;
			}
			$scope  = self::SCOPE_WEIGHT[ $rule['scope'] ];
			$target = self::TARGET_WEIGHT[ $rule['target'] ];
			$score  = array(
				$by_target ? $target * 100 + $scope : $scope + $target,
				self::precision( $rule, $item ),
				(int) $rule['priority'],
				// Same rank in "target" mode: an exclusion wins over a rate.
				$by_target && 'exclude' === $rule['rate_type'] ? 1 : 0,
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
	 * @param bool   $filter       Apply the wpac_commission_amount filter (false: the caller does it once, see filter_amount()).
	 * @param string $precedence   Rule precedence (scope|target).
	 * @return array amount_minor, base_minor, rate_type, rate, rule_id, excluded, rule
	 */
	public static function calculate( array $item, array $rules, $affiliate_id, $group_id, array $default, $filter = true, $precedence = 'scope' ) {
		$base = max( 0, (int) $item['net_minor'] );
		$rule = self::pick_rule( $rules, $item, $affiliate_id, $group_id, $precedence );

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

		$amount = max( 0, $amount );
		if ( $filter ) {
			$amount = self::filter_amount( $amount, $item, $affiliate_id, $rule, $base );
		}

		return array(
			'amount_minor' => $amount,
			'base_minor'   => $base,
			'rate_type'    => $rate_type,
			'rate'         => $rate,
			'rule_id'      => $rule ? (int) $rule['id'] : 0,
			'excluded'     => false,
			'rule'         => $rule,
		);
	}

	/**
	 * Apply the wpac_commission_amount filter (once per line). The result is never negative and
	 * never more than the net base of the line.
	 *
	 * @param int        $amount       Commission (minor units).
	 * @param array      $item         Item.
	 * @param int        $affiliate_id Partner.
	 * @param array|null $rule         Applied rule.
	 * @param int        $base         Net base of the line.
	 * @return int
	 */
	public static function filter_amount( $amount, array $item, $affiliate_id, $rule, $base ) {
		if ( ! function_exists( 'apply_filters' ) ) {
			return (int) $amount;
		}
		/**
		 * Commission of one line item (minor units) after the rule was applied, e.g. for
		 * add-on rules. Never negative and never above the net base of the line; excluded
		 * items are not filtered. Runs once per line (after wpac_commission_base).
		 *
		 * @since contract 1.2.0
		 * @param int        $amount       Commission (minor units).
		 * @param array      $item         Line item (net_minor, product_id, quantity, …).
		 * @param int        $affiliate_id Partner.
		 * @param array|null $rule         Applied rule (null = program default).
		 */
		$filtered = apply_filters( 'wpac_commission_amount', $amount, $item, (int) $affiliate_id, $rule );
		return min( max( 0, (int) $base ), max( 0, (int) $filtered ) );
	}

	/**
	 * Adjustment for a (partial) refund of a line: negative, pro rata on the
	 * CUMULATIVE refunded net (so rounding never leaves cents behind), capped so
	 * the total of all adjustments never exceeds the original commission.
	 *
	 * @param int  $original_amount      Original commission (minor).
	 * @param int  $original_base        Original base (minor).
	 * @param int  $already_adjusted     Sum of earlier adjustments (<= 0).
	 * @param int  $refunded_net_minor   Net amount refunded now (positive).
	 * @param bool $full                 Reverse everything that remains.
	 * @param int  $already_refunded_net Net amount refunded earlier (positive).
	 * @return int Adjustment (<= 0).
	 */
	public static function refund_adjustment( $original_amount, $original_base, $already_adjusted, $refunded_net_minor, $full = false, $already_refunded_net = 0 ) {
		$remaining = max( 0, (int) $original_amount + (int) $already_adjusted );
		if ( 0 === $remaining ) {
			return 0;
		}
		if ( $full || (int) $original_base <= 0 ) {
			return -$remaining;
		}
		$cumulative = min( (int) $original_base, abs( (int) $already_refunded_net ) + abs( (int) $refunded_net_minor ) );
		$target     = Money::prorate( (int) $original_amount, $cumulative, (int) $original_base );
		$adj        = $target - abs( min( 0, (int) $already_adjusted ) );
		return -min( $remaining, max( 0, $adj ) );
	}
}
