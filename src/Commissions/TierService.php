<?php
/**
 * Monthly tier plans: storage and month closing (bonus booking).
 *
 * A month is closed automatically once the hold period of its last day has
 * passed, so refunds within the hold period are already reflected. The bonus is
 * booked as an approved ledger row of kind "bonus" with a frozen snapshot of the
 * plan; it is idempotent per partner and month. Refunds after closing do not
 * change a booked bonus.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Commissions;

use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Core\Audit;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Money;
use WPAffiliateCaptain\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class TierService {

	/**
	 * All plans.
	 *
	 * @return array
	 */
	public static function all() {
		global $wpdb;
		$t = Db::table( 'tiers' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( "SELECT * FROM {$t} ORDER BY scope, scope_id, id DESC" );
	}

	/**
	 * Create a plan.
	 *
	 * @param array  $data   scope, scope_id, basis, mode, steps (array), note.
	 * @param string $reason Reason.
	 * @return int
	 */
	public static function create( array $data, $reason = '' ) {
		global $wpdb;
		$row = array(
			'scope'      => in_array( $data['scope'], array( 'global', 'group', 'affiliate' ), true ) ? $data['scope'] : 'global',
			'scope_id'   => 'global' === $data['scope'] ? 0 : (int) $data['scope_id'],
			'basis'      => 'orders' === $data['basis'] ? 'orders' : 'revenue',
			'mode'       => 'retroactive' === $data['mode'] ? 'retroactive' : 'marginal',
			'steps'      => wp_json_encode( $data['steps'] ),
			'active'     => 1,
			'note'       => sanitize_text_field( isset( $data['note'] ) ? $data['note'] : '' ),
			'created_at' => Db::now(),
			'updated_at' => Db::now(),
		);
		$wpdb->insert( Db::table( 'tiers' ), $row );
		$id = (int) $wpdb->insert_id;
		Audit::log( 'tier.created', 'tier', $id, $row + array( 'reason' => $reason ) );
		return $id;
	}

	/**
	 * Deactivate a plan (kept for history).
	 *
	 * @param int    $id     Plan.
	 * @param string $reason Reason.
	 */
	public static function deactivate( $id, $reason ) {
		global $wpdb;
		$wpdb->update(
			Db::table( 'tiers' ),
			array(
				'active'     => 0,
				'updated_at' => Db::now(),
			),
			array( 'id' => (int) $id )
		);
		Audit::log( 'tier.deactivated', 'tier', (int) $id, array( 'reason' => $reason ) );
	}

	/**
	 * Most specific active plan for an affiliate.
	 *
	 * @param object $aff Affiliate.
	 * @return object|null
	 */
	public static function plan_for( $aff ) {
		$best = null;
		$rank = array(
			'global'    => 1,
			'group'     => 2,
			'affiliate' => 3,
		);
		foreach ( self::all() as $plan ) {
			if ( ! $plan->active ) {
				continue;
			}
			$match = 'global' === $plan->scope
				|| ( 'group' === $plan->scope && (int) $aff->group_id && (int) $plan->scope_id === (int) $aff->group_id )
				|| ( 'affiliate' === $plan->scope && (int) $plan->scope_id === (int) $aff->id );
			if ( $match && ( ! $best || $rank[ $plan->scope ] > $rank[ $best->scope ] || ( $rank[ $plan->scope ] === $rank[ $best->scope ] && (int) $plan->id > (int) $best->id ) ) ) {
				$best = $plan;
			}
		}
		return $best;
	}

	/**
	 * Close all months that are due (cron).
	 */
	public static function close_due_months() {
		if ( ! self::all() ) {
			return;
		}
		$hold = (int) Settings::get( 'hold_days' );
		$last = (string) Settings::get( 'tier_month_closed' );
		// Without history: start with the previous month.
		$month = $last ? gmdate( 'Y-m', strtotime( $last . '-01 +1 month' ) ) : gmdate( 'Y-m', strtotime( 'first day of previous month' ) );
		for ( $guard = 0; $guard < 24; $guard++ ) {
			$end = strtotime( gmdate( 'Y-m-t 23:59:59', strtotime( $month . '-01' ) ) . ' UTC' );
			if ( time() < $end + ( $hold + 1 ) * DAY_IN_SECONDS ) {
				break;
			}
			self::close_month( $month );
			Settings::update( array( 'tier_month_closed' => $month ) );
			$month = gmdate( 'Y-m', strtotime( $month . '-01 +1 month' ) );
		}
	}

	/**
	 * Book bonuses for one month (idempotent).
	 *
	 * @param string $month YYYY-MM.
	 * @return int Number of bonus rows created.
	 */
	public static function close_month( $month ) {
		global $wpdb;
		if ( ! preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
			return 0;
		}
		$c        = Db::table( 'commissions' );
		$currency = strtoupper( (string) Settings::get( 'currency' ) );
		$start    = $month . '-01 00:00:00';
		$end      = gmdate( 'Y-m-t 23:59:59', strtotime( $month . '-01' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$affiliates = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT affiliate_id FROM {$c} WHERE kind = 'sale' AND currency = %s AND created_at BETWEEN %s AND %s", $currency, $start, $end ) );
		$created    = 0;

		foreach ( $affiliates as $affiliate_id ) {
			$aff  = AffiliateRepository::find( (int) $affiliate_id );
			$plan = $aff ? self::plan_for( $aff ) : null;
			if ( ! $plan ) {
				continue;
			}
			$key = 'bonus:tier:' . (int) $affiliate_id . ':' . $month;
			if ( Ledger::find_by_key( $key ) ) {
				continue;
			}
			// Net revenue per order whose sale was booked in the month, incl. later corrections.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$orders = $wpdb->get_results( $wpdb->prepare( "SELECT source, source_order_id, SUM(base_minor) AS revenue, MIN(CASE WHEN kind = 'sale' THEN created_at END) AS sold_at FROM {$c} WHERE affiliate_id = %d AND currency = %s AND status <> 'rejected' AND kind <> 'bonus' GROUP BY source, source_order_id HAVING sold_at BETWEEN %s AND %s ORDER BY sold_at ASC", $affiliate_id, $currency, $start, $end ), ARRAY_A );
			$steps  = json_decode( (string) $plan->steps, true );
			$result = Tiers::bonus( $orders, is_array( $steps ) ? $steps : array(), $plan->basis, $plan->mode );
			if ( $result['amount'] <= 0 ) {
				continue;
			}
			$snapshot = array(
				'plan'    => array(
					'id'      => (int) $plan->id,
					'version' => (int) $plan->version,
					'scope'   => $plan->scope,
					'basis'   => $plan->basis,
					'mode'    => $plan->mode,
					'steps'   => $steps,
				),
				'month'   => $month,
				'revenue' => $result['revenue'],
				'orders'  => $result['orders'],
				'reached' => $result['reached'],
			);
			$ok = $wpdb->insert(
				$c,
				array(
					'affiliate_id'    => (int) $affiliate_id,
					'source'          => 'wpac',
					'source_order_id' => 'tier-' . $month,
					'source_item_id'  => '',
					'kind'            => 'bonus',
					'parent_id'       => 0,
					'product_ref'     => '',
					/* translators: 1: month, 2: rate */
					'description'     => sprintf( __( 'Monthly bonus %1$s (tier %2$s %%)', 'wp-affiliate-captain' ), $month, $result['reached']['rate'] ),
					'currency'        => $currency,
					'base_minor'      => $result['revenue'],
					'amount_minor'    => $result['amount'],
					'rate_type'       => 'percent',
					'rate'            => (string) $result['reached']['rate'],
					'rule_id'         => (int) $plan->id,
					'rule_snapshot'   => wp_json_encode( $snapshot ),
					'status'          => 'approved',
					'hold_until'      => Db::now(),
					'idempotency_key' => $key,
					'created_at'      => Db::now(),
					'updated_at'      => Db::now(),
				)
			);
			if ( $ok ) {
				$id = (int) $wpdb->insert_id;
				Ledger::event( $id, 'created', '', 'approved', $result['amount'], 'tier_bonus ' . $month, $key, 0 );
				do_action( 'wpac_commission_created', $id );
				++$created;
			}
		}
		Audit::log(
			'tier.month_closed',
			'tier',
			0,
			array(
				'month'   => $month,
				'bonuses' => $created,
			)
		);
		return $created;
	}

	/**
	 * Steps as editable text.
	 *
	 * @param object $plan     Plan.
	 * @param string $currency Currency.
	 * @return string
	 */
	public static function steps_text( $plan, $currency ) {
		$steps = json_decode( (string) $plan->steps, true );
		$out   = array();
		foreach ( (array) $steps as $s ) {
			$out[] = ( 'orders' === $plan->basis ? (int) $s['threshold'] : Money::format( (int) $s['threshold'], $currency ) ) . ' → +' . $s['rate'] . ' %';
		}
		return implode( ', ', $out );
	}
}
