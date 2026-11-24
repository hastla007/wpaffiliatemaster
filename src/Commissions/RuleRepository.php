<?php
/**
 * Commission rules storage.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Commissions;

use WPAffiliateCaptain\Core\Audit;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Money;
use WPAffiliateCaptain\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class RuleRepository {

	/**
	 * Active rules as arrays (for the calculator).
	 *
	 * @return array
	 */
	public static function active() {
		global $wpdb;
		$t = Db::table( 'rules' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( "SELECT * FROM {$t} WHERE active = 1", ARRAY_A );
	}

	/**
	 * Active rules that can apply to one partner: global rules plus the rules of the partner and
	 * of its group (the calculator would skip the rules of all other partners anyway; after an
	 * import there can be thousands of them).
	 *
	 * @param int $affiliate_id Partner.
	 * @param int $group_id     Group (0 = none).
	 * @return array
	 */
	public static function active_for( $affiliate_id, $group_id = 0 ) {
		global $wpdb;
		$t = Db::table( 'rules' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE active = 1 AND ( scope = 'global' OR ( scope = 'affiliate' AND scope_id = %d ) OR ( scope = 'group' AND scope_id = %d AND scope_id > 0 ) )", (int) $affiliate_id, (int) $group_id ), ARRAY_A );
	}

	/**
	 * Rule precedence setting for the calculator (scope|target), see Calculator.
	 *
	 * @return string
	 */
	public static function precedence() {
		$mode = (string) Settings::get( 'rule_precedence' );
		/**
		 * Rule precedence: "scope" (partner > group > global first, exclusions always win) or
		 * "target" (product/SKU > category/tag > all first; exclusions compete like other rules).
		 *
		 * @param string $mode Mode.
		 */
		$mode = (string) apply_filters( 'wpac_rule_precedence', $mode );
		return in_array( $mode, Calculator::PRECEDENCES, true ) ? $mode : 'scope';
	}

	/**
	 * All rules.
	 *
	 * @return array
	 */
	public static function all() {
		global $wpdb;
		$t = Db::table( 'rules' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( "SELECT * FROM {$t} ORDER BY scope, scope_id, target, priority DESC, id DESC" );
	}

	/**
	 * Find one rule.
	 *
	 * @param int $id ID.
	 * @return object|null
	 */
	public static function find( $id ) {
		global $wpdb;
		$t = Db::table( 'rules' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) );
	}

	/**
	 * Sanitize raw rule input. Fixed rates, the cap per line (max_amount) and the minimum base
	 * (min_base) are entered as decimal amounts and stored as minor units of the program
	 * currency; valid_from/valid_until as YYYY-MM-DD (site time zone, inclusive; empty = open).
	 *
	 * @param array $in Input.
	 * @return array|\WP_Error The first problem (see field_errors() for all of them).
	 */
	public static function sanitize( array $in ) {
		$checked = self::check( $in );
		if ( $checked['errors'] ) {
			$first = reset( $checked['errors'] );
			return new \WP_Error( $first[0], $first[1] );
		}
		return $checked['data'];
	}

	/**
	 * All problems of raw rule input, per form field (scope, target_value, rate, max_amount,
	 * min_base, valid_from, valid_until), so a form can show each one at its field.
	 *
	 * @param array $in Input (as for sanitize()).
	 * @return array<string,string> Field => message; empty when the input is valid.
	 */
	public static function field_errors( array $in ) {
		$out = array();
		foreach ( self::check( $in )['errors'] as $field => $error ) {
			$out[ $field ] = $error[1];
		}
		return $out;
	}

	/**
	 * Check and sanitize raw rule input.
	 *
	 * @param array $in Input.
	 * @return array{data: array|null, errors: array<string,array{0:string,1:string}>} Errors per field (code, message), in form order.
	 */
	private static function check( array $in ) {
		$errors    = array();
		$scope     = isset( $in['scope'] ) && in_array( $in['scope'], array( 'global', 'group', 'affiliate' ), true ) ? $in['scope'] : 'global';
		$target    = isset( $in['target'] ) && in_array( $in['target'], array( 'all', 'product', 'category', 'tag', 'sku' ), true ) ? $in['target'] : 'all';
		$rate_type = isset( $in['rate_type'] ) && in_array( $in['rate_type'], array( 'percent', 'fixed', 'exclude' ), true ) ? $in['rate_type'] : 'percent';
		$rate      = '0';
		if ( 'exclude' !== $rate_type ) {
			$dec = Money::normalize_decimal( isset( $in['rate'] ) ? (string) $in['rate'] : '' );
			if ( null === $dec || (float) $dec < 0 ) {
				$errors['rate'] = array( 'wpac_rate', __( 'Please enter a valid rate.', 'affiliate-captain' ) );
			} elseif ( 'percent' === $rate_type && (float) $dec > 100 ) {
				$errors['rate'] = array( 'wpac_rate', __( 'A percentage cannot exceed 100.', 'affiliate-captain' ) );
			} else {
				$rate = 'fixed' === $rate_type ? (string) Money::to_minor( $dec, Settings::get( 'currency' ) ) : $dec;
			}
		}
		$scope_id = 'global' === $scope ? 0 : ( isset( $in['scope_id'] ) ? absint( $in['scope_id'] ) : 0 );
		if ( 'global' !== $scope && ! $scope_id ) {
			$errors['scope'] = array( 'wpac_scope', __( 'Please choose a partner or group for this rule.', 'affiliate-captain' ) );
		}
		$value = 'all' === $target ? '' : sanitize_text_field( isset( $in['target_value'] ) ? (string) $in['target_value'] : '' );
		if ( 'all' !== $target && '' === $value ) {
			$errors['target_value'] = array( 'wpac_target', __( 'Please enter product IDs, category/tag IDs or SKUs/course codes.', 'affiliate-captain' ) );
		}
		// SET-AC-22: cap per line and minimum base (decimal amounts in the program currency,
		// empty = none), validity in calendar days of the site's time zone (empty = open).
		$amounts = array(
			'max_amount_minor' => 0,
			'min_base_minor'   => 0,
		);
		foreach ( array( 'max_amount' => 'max_amount_minor', 'min_base' => 'min_base_minor' ) as $field => $column ) {
			$raw = isset( $in[ $field ] ) ? trim( (string) $in[ $field ] ) : '';
			if ( '' === $raw ) {
				continue;
			}
			$dec = Money::normalize_decimal( $raw );
			if ( null === $dec || (float) $dec < 0 ) {
				$errors[ $field ] = array( 'wpac_rule_' . $field, 'max_amount' === $field ? __( 'Please enter a valid maximum commission per line (or leave it empty).', 'affiliate-captain' ) : __( 'Please enter a valid minimum amount (or leave it empty).', 'affiliate-captain' ) );
				continue;
			}
			$amounts[ $column ] = Money::to_minor( $dec, Settings::get( 'currency' ) );
		}
		if ( 'exclude' === $rate_type ) {
			$amounts['max_amount_minor'] = 0; // Nothing to cap.
		}
		$days = array(
			'valid_from'  => null,
			'valid_until' => null,
		);
		foreach ( array( 'valid_from', 'valid_until' ) as $field ) {
			$raw = isset( $in[ $field ] ) ? trim( (string) $in[ $field ] ) : '';
			if ( '' === $raw ) {
				continue;
			}
			$day = Calculator::day( $raw );
			$dt  = '' !== $day ? \DateTimeImmutable::createFromFormat( '!Y-m-d', $day ) : false;
			if ( ! $dt || $dt->format( 'Y-m-d' ) !== $day ) {
				$errors[ $field ] = array( 'wpac_rule_' . $field, __( 'Please enter the validity dates as YYYY-MM-DD (or leave them empty).', 'affiliate-captain' ) );
				continue;
			}
			$days[ $field ] = $day;
		}
		if ( null !== $days['valid_from'] && null !== $days['valid_until'] && $days['valid_until'] < $days['valid_from'] ) {
			$errors['valid_until'] = array( 'wpac_rule_valid_until', __( 'The end of the validity cannot be before its start.', 'affiliate-captain' ) );
		}
		if ( $errors ) {
			return array(
				'data'   => null,
				'errors' => $errors,
			);
		}
		return array(
			'errors' => array(),
			'data'   => array(
				'scope'            => $scope,
				'scope_id'         => $scope_id,
				'target'           => $target,
				'target_value'     => $value,
				'rate_type'        => $rate_type,
				'rate'             => $rate,
				'max_amount_minor' => $amounts['max_amount_minor'],
				'min_base_minor'   => $amounts['min_base_minor'],
				'valid_from'       => $days['valid_from'],
				'valid_until'      => $days['valid_until'],
				'priority'         => isset( $in['priority'] ) ? (int) $in['priority'] : 0,
				'active'           => empty( $in['active'] ) ? 0 : 1,
				'note'             => sanitize_text_field( isset( $in['note'] ) ? (string) $in['note'] : '' ),
			),
		);
	}

	/**
	 * Create or update. Each change bumps the version and is audited with a reason.
	 * Existing commissions keep their snapshot and are never recalculated.
	 *
	 * @param array  $data   Sanitized data.
	 * @param int    $id     Existing ID or 0.
	 * @param string $reason Change reason.
	 * @return int
	 */
	public static function save( array $data, $id = 0, $reason = '' ) {
		global $wpdb;
		$t   = Db::table( 'rules' );
		$now = Db::now();
		if ( $id ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET version = version + 1 WHERE id = %d", $id ) );
			$wpdb->update( $t, $data + array( 'updated_at' => $now ), array( 'id' => (int) $id ) );
			Audit::log( 'rule.updated', 'rule', (int) $id, $data + array( 'reason' => $reason ) );
			return (int) $id;
		}
		$wpdb->insert(
			$t,
			$data + array(
				'version'    => 1,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		$id = (int) $wpdb->insert_id;
		Audit::log( 'rule.created', 'rule', $id, $data + array( 'reason' => $reason ) );
		return $id;
	}

	/**
	 * Delete a rule.
	 *
	 * @param int    $id     ID.
	 * @param string $reason Reason.
	 */
	public static function delete( $id, $reason = '' ) {
		global $wpdb;
		$wpdb->delete( Db::table( 'rules' ), array( 'id' => (int) $id ) );
		Audit::log( 'rule.deleted', 'rule', (int) $id, array( 'reason' => $reason ) );
	}

	/**
	 * Program default as calculator input.
	 *
	 * @param int $affiliate_id Partner the rate is for (0 = none; with a partner the values pass
	 *                          the filter wpac_setting with the partner's program).
	 * @return array
	 */
	public static function default_rate( $affiliate_id = 0 ) {
		$type = Settings::for_affiliate( 'default_rate_type', (int) $affiliate_id );
		$rate = Settings::for_affiliate( 'default_rate', (int) $affiliate_id );
		if ( ! in_array( $type, array( 'percent', 'fixed' ), true ) || ! is_numeric( $rate ) || (float) $rate < 0 ) {
			// A filter returned something the calculator cannot use: the stored program default.
			$type = Settings::get( 'default_rate_type' );
			$rate = Settings::get( 'default_rate' );
		}
		return array(
			'rate_type' => $type,
			'rate'      => (string) $rate,
		);
	}

	/**
	 * Human readable conditions of a rule (cap, minimum base, validity); '' without any.
	 *
	 * @param object|array $rule     Rule.
	 * @param string       $currency Currency.
	 * @return string
	 */
	public static function format_conditions( $rule, $currency = '' ) {
		$rule     = (array) $rule;
		$currency = $currency ? $currency : (string) Settings::get( 'currency' );
		$out      = array();
		if ( ! empty( $rule['max_amount_minor'] ) ) {
			/* translators: %s: amount */
			$out[] = sprintf( __( 'max. %s per line', 'affiliate-captain' ), Money::format( (int) $rule['max_amount_minor'], $currency ) );
		}
		if ( ! empty( $rule['min_base_minor'] ) ) {
			/* translators: %s: amount */
			$out[] = sprintf( __( 'from a line amount of %s', 'affiliate-captain' ), Money::format( (int) $rule['min_base_minor'], $currency ) );
		}
		$from  = Calculator::day( isset( $rule['valid_from'] ) ? $rule['valid_from'] : '' );
		$until = Calculator::day( isset( $rule['valid_until'] ) ? $rule['valid_until'] : '' );
		$fmt   = static function ( $day ) {
			// A calendar day, not a point in time: formatted as is (no time zone shift).
			$text = function_exists( 'wp_date' ) ? wp_date( (string) get_option( 'date_format', 'Y-m-d' ), (int) strtotime( $day . ' 12:00:00 UTC' ), new \DateTimeZone( 'UTC' ) ) : false;
			return $text ? $text : $day;
		};
		if ( '' !== $from && '' !== $until ) {
			/* translators: 1: first day, 2: last day */
			$out[] = sprintf( __( 'valid %1$s – %2$s', 'affiliate-captain' ), $fmt( $from ), $fmt( $until ) );
		} elseif ( '' !== $from ) {
			/* translators: %s: first day */
			$out[] = sprintf( __( 'valid from %s', 'affiliate-captain' ), $fmt( $from ) );
		} elseif ( '' !== $until ) {
			/* translators: %s: last day */
			$out[] = sprintf( __( 'valid until %s', 'affiliate-captain' ), $fmt( $until ) );
		}
		return implode( ', ', $out );
	}

	/**
	 * Human readable rate.
	 *
	 * @param string $rate_type Type.
	 * @param string $rate      Rate.
	 * @param string $currency  Currency.
	 * @return string
	 */
	public static function format_rate( $rate_type, $rate, $currency = '' ) {
		if ( 'exclude' === $rate_type ) {
			return __( 'excluded', 'affiliate-captain' );
		}
		if ( 'fixed' === $rate_type ) {
			return Money::format( (int) $rate, $currency ? $currency : Settings::get( 'currency' ) );
		}
		return \WPAffiliateCaptain\Core\Locale::percent( (string) $rate ); // Localized: "12,5 %" (R6-MS13).
	}
}
