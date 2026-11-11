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
	 * Sanitize raw rule input. Fixed rates are entered as decimal amounts and
	 * stored as minor units of the program currency.
	 *
	 * @param array $in Input.
	 * @return array|\WP_Error
	 */
	public static function sanitize( array $in ) {
		$scope     = isset( $in['scope'] ) && in_array( $in['scope'], array( 'global', 'group', 'affiliate' ), true ) ? $in['scope'] : 'global';
		$target    = isset( $in['target'] ) && in_array( $in['target'], array( 'all', 'product', 'category', 'sku' ), true ) ? $in['target'] : 'all';
		$rate_type = isset( $in['rate_type'] ) && in_array( $in['rate_type'], array( 'percent', 'fixed', 'exclude' ), true ) ? $in['rate_type'] : 'percent';
		$rate      = '0';
		if ( 'exclude' !== $rate_type ) {
			$dec = Money::normalize_decimal( isset( $in['rate'] ) ? (string) $in['rate'] : '' );
			if ( null === $dec || (float) $dec < 0 ) {
				return new \WP_Error( 'wpac_rate', __( 'Please enter a valid rate.', 'wp-affiliate-captain' ) );
			}
			if ( 'percent' === $rate_type && (float) $dec > 100 ) {
				return new \WP_Error( 'wpac_rate', __( 'A percentage cannot exceed 100.', 'wp-affiliate-captain' ) );
			}
			$rate = 'fixed' === $rate_type ? (string) Money::to_minor( $dec, Settings::get( 'currency' ) ) : $dec;
		}
		$scope_id = 'global' === $scope ? 0 : ( isset( $in['scope_id'] ) ? absint( $in['scope_id'] ) : 0 );
		if ( 'global' !== $scope && ! $scope_id ) {
			return new \WP_Error( 'wpac_scope', __( 'Please choose a partner or group for this rule.', 'wp-affiliate-captain' ) );
		}
		$value = 'all' === $target ? '' : sanitize_text_field( isset( $in['target_value'] ) ? (string) $in['target_value'] : '' );
		if ( 'all' !== $target && '' === $value ) {
			return new \WP_Error( 'wpac_target', __( 'Please enter product IDs, category IDs or SKUs/course codes.', 'wp-affiliate-captain' ) );
		}
		return array(
			'scope'        => $scope,
			'scope_id'     => $scope_id,
			'target'       => $target,
			'target_value' => $value,
			'rate_type'    => $rate_type,
			'rate'         => $rate,
			'priority'     => isset( $in['priority'] ) ? (int) $in['priority'] : 0,
			'active'       => empty( $in['active'] ) ? 0 : 1,
			'note'         => sanitize_text_field( isset( $in['note'] ) ? (string) $in['note'] : '' ),
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
	 * @return array
	 */
	public static function default_rate() {
		return array(
			'rate_type' => Settings::get( 'default_rate_type' ),
			'rate'      => (string) Settings::get( 'default_rate' ),
		);
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
			return __( 'excluded', 'wp-affiliate-captain' );
		}
		if ( 'fixed' === $rate_type ) {
			return Money::format( (int) $rate, $currency ? $currency : Settings::get( 'currency' ) );
		}
		$rate = (string) $rate;
		if ( false !== strpos( $rate, '.' ) ) {
			$rate = rtrim( rtrim( $rate, '0' ), '.' );
		}
		return $rate . ' %';
	}
}
