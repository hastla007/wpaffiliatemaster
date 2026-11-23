<?php
/**
 * Monthly tier plans: storage and month closing (bonus booking).
 *
 * A month is closed automatically once the hold period of its last day has
 * passed, so refunds within the hold period are already reflected. The bonus is
 * booked as an approved ledger row of kind "bonus" with a frozen snapshot of the
 * plan; it is idempotent per partner and month. Later changes of a closed month (refunds,
 * rejections, late approvals) are booked as bonus corrections by recalculate_closed_months(),
 * counted in the month of the original sale.
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
	 * Whether a month can be closed: it has ended and its hold period has passed
	 * (before that, the result is still incomplete).
	 *
	 * With a partner, the hold period of the partner's program counts (filter wpac_setting,
	 * contract 1.5.0); without one, the hold period of the settings (the month as a whole).
	 *
	 * @param string $month        YYYY-MM.
	 * @param int    $affiliate_id Partner (0 = the month as a whole).
	 * @return bool
	 */
	public static function is_due( $month, $affiliate_id = 0 ) {
		if ( ! preg_match( '/^\d{4}-\d{2}$/', (string) $month ) ) {
			return false;
		}
		$end = strtotime( self::range( $month )[1] . ' UTC' );
		return time() >= $end + ( max( 0, (int) Settings::for_affiliate( 'hold_days', (int) $affiliate_id ) ) + 1 ) * DAY_IN_SECONDS;
	}

	/**
	 * Whether a partner of a month still waits for the longer hold period of their program
	 * (filter wpac_setting): the month as a whole may be closed, the partner's bonus follows in the
	 * recalculation once due. Never true without a longer program hold period.
	 *
	 * @param string $month        YYYY-MM.
	 * @param int    $affiliate_id Partner.
	 * @return bool
	 */
	public static function waits( $month, $affiliate_id ) {
		if ( (int) Settings::for_affiliate( 'hold_days', (int) $affiliate_id ) <= (int) Settings::get( 'hold_days' ) ) {
			return false;
		}
		return ! self::is_due( $month, (int) $affiliate_id );
	}

	/** Option: months closed while partners with a longer hold period of their program still waited. */
	const DEFERRED = 'wpac_tier_deferred';

	/**
	 * Months closed while some partners still had a longer hold period of their program.
	 *
	 * @return string[] YYYY-MM.
	 */
	public static function deferred_months() {
		return array_values(
			array_filter(
				array_map( 'strval', (array) get_option( self::DEFERRED, array() ) ),
				static function ( $m ) {
					return (bool) preg_match( '/^\d{4}-\d{2}$/', $m );
				}
			)
		);
	}

	/**
	 * Remember or forget a month with deferred partners.
	 *
	 * @param string $month    YYYY-MM.
	 * @param bool   $deferred Whether partners of the month still wait.
	 */
	private static function set_deferred( $month, $deferred ) {
		$months = self::deferred_months();
		$has    = in_array( $month, $months, true );
		if ( $deferred && ! $has ) {
			$months[] = $month;
			sort( $months );
			update_option( self::DEFERRED, $months, false );
		} elseif ( ! $deferred && $has ) {
			$months = array_values( array_diff( $months, array( $month ) ) );
			if ( $months ) {
				update_option( self::DEFERRED, $months, false );
			} else {
				delete_option( self::DEFERRED );
			}
		}
	}

	/** Option: first month whose boundaries are the site's local month (earlier closed months keep UTC). */
	const LOCAL_FROM = 'wpac_tier_local_from';

	/**
	 * UTC range of a bonus month. Months are the site's calendar months (time zone in the WordPress
	 * settings); months closed before this changed keep their UTC boundaries, so recalculating
	 * them books no corrections only because orders around midnight moved to another month.
	 *
	 * @param string $month YYYY-MM.
	 * @return string[] [ start, end ] UTC.
	 */
	public static function range( $month ) {
		$from = (string) get_option( self::LOCAL_FROM, '' );
		if ( '' !== $from && $month < $from ) {
			return array( $month . '-01 00:00:00', gmdate( 'Y-m-t 23:59:59', strtotime( $month . '-01' ) ) );
		}
		return Db::month_range( $month );
	}

	/** Single event that continues closing/recalculating months that did not fit into the time budget. */
	const CONTINUE_HOOK = 'wpac_tier_continue';

	/** Option: [ month, last partner ID ] of a month whose closing or recalculation stopped halfway. */
	const CURSOR = 'wpac_tier_cursor';

	/** @var bool Whether the last close_month()/recalculate_month() stopped at its time limit. */
	private static $stopped = false;

	/**
	 * Seconds one run of closing/recalculating may take before it continues separately.
	 *
	 * @return float
	 */
	public static function budget() {
		/**
		 * Seconds the monthly bonus closing/recalculation may run in one request (0 = no limit).
		 *
		 * @param float $budget Default 20.
		 */
		return (float) apply_filters( 'wpac_tier_budget', 20 );
	}

	/**
	 * Continuation event: finish closing and recalculating what the last run left.
	 */
	public static function continue_run() {
		if ( ! \WPAffiliateCaptain\Core\Installer::schema_current() || Settings::get( 'maintenance_mode' ) ) {
			return; // The daily run picks it up again.
		}
		if ( self::close_due_months() ) {
			self::recalculate_closed_months( self::budget() );
		}
	}

	/**
	 * Remember where a month stopped and schedule the continuation.
	 *
	 * @param string $kind  close|recalc.
	 * @param string $month Month.
	 * @param int    $after Last partner done.
	 */
	private static function stop_at( $kind, $month, $after ) {
		update_option(
			self::CURSOR,
			array(
				'kind'  => $kind,
				'month' => $month,
				'after' => (int) $after,
			),
			false
		);
		self::$stopped = true;
		\WPAffiliateCaptain\Core\Scheduler::once( self::CONTINUE_HOOK, 120 );
	}

	/**
	 * Partner to continue after in a month ( 0 = from the start).
	 *
	 * @param string $kind  close|recalc.
	 * @param string $month Month.
	 * @return int
	 */
	private static function cursor( $kind, $month ) {
		$c = get_option( self::CURSOR );
		return is_array( $c ) && isset( $c['kind'], $c['month'], $c['after'] ) && $kind === $c['kind'] && $month === $c['month'] ? (int) $c['after'] : 0;
	}

	/** Option: periods in which monthly tiers were switched off in the settings (SET-AC-26). */
	const OFF_PERIODS = 'wpac_tier_off_periods';

	/**
	 * Called by Settings::update() before feature_tiers is stored: switching off starts an
	 * off-period, switching on ends it. Months touched by an off-period get no bonus and no bonus
	 * correction (see skipped()).
	 *
	 * @param int $new New value (1 = on).
	 * @param int $old Stored value.
	 */
	public static function feature_saved( $new, $old ) {
		$new = (int) (bool) $new;
		if ( (int) (bool) $old === $new ) {
			return;
		}
		$periods = self::off_periods();
		$last    = count( $periods ) - 1;
		if ( ! $new && ( $last < 0 || $periods[ $last ]['to'] ) ) {
			$periods[] = array(
				'from' => time(),
				'to'   => 0,
			);
		} elseif ( $new && $last >= 0 && ! $periods[ $last ]['to'] ) {
			$periods[ $last ]['to'] = time();
		} else {
			return;
		}
		update_option( self::OFF_PERIODS, $periods, false );
	}

	/**
	 * Periods in which monthly tiers were switched off (Unix times; to = 0: still off).
	 *
	 * @return array<int,array{from:int,to:int}>
	 */
	public static function off_periods() {
		$out = array();
		foreach ( (array) get_option( self::OFF_PERIODS, array() ) as $p ) {
			if ( is_array( $p ) && ! empty( $p['from'] ) ) {
				$out[] = array(
					'from' => (int) $p['from'],
					'to'   => isset( $p['to'] ) ? (int) $p['to'] : 0,
				);
			}
		}
		return $out;
	}

	/**
	 * Whether a month is skipped: monthly tiers were switched off at some time during it (site's
	 * local calendar month, from the month of switching off up to the month of switching on).
	 * Such a month gets no bonus and no bonus correction; months closed before stay as they are.
	 *
	 * @param string $month YYYY-MM.
	 * @return bool
	 */
	public static function skipped( $month ) {
		$month = (string) $month;
		$skip  = false;
		foreach ( self::off_periods() as $p ) {
			if ( $month >= Db::local_month( 0, $p['from'] ) && $month <= Db::local_month( 0, $p['to'] ? $p['to'] : null ) ) {
				$skip = true;
				break;
			}
		}
		/**
		 * Whether a bonus month is skipped because monthly tiers were switched off during it
		 * (since plugin 0.21.0). The filter wins.
		 *
		 * @param bool   $skip  Decision from the stored off-periods (option wpac_tier_off_periods).
		 * @param string $month YYYY-MM.
		 */
		return (bool) apply_filters( 'wpac_tier_month_skipped', $skip, $month );
	}

	/** Months whose recalculation did not fit into the last run's time budget. */
	const RECALC_PENDING = 'wpac_tier_recalc_pending';

	/**
	 * Close all months that are due (cron).
	 */
	public static function close_due_months() {
		if ( ! \WPAffiliateCaptain\Core\Features::enabled( 'tiers' ) ) {
			return true; // Monthly tiers switched off (SET-AC-26): nothing is booked, months wait.
		}
		if ( ! self::all() ) {
			return true;
		}
		$last = Settings::state( 'tier_month_closed' );
		// Without history: start with the previous (local) month.
		$month    = $last ? gmdate( 'Y-m', strtotime( $last . '-01 +1 month' ) ) : Db::local_month( -1 );
		$budget   = self::budget();
		$deadline = $budget > 0 ? microtime( true ) + $budget : 0.0;
		// At most 24 booked months per run; skipped months (tiers off) are passed over quickly.
		for ( $guard = 0, $total = 0; $guard < 24 && $total < 1200; $total++ ) {
			if ( ! self::is_due( $month ) ) {
				break;
			}
			if ( self::skipped( $month ) ) {
				Settings::set_state( 'tier_month_closed', $month ); // Nothing booked for it (SET-AC-26).
				$month = gmdate( 'Y-m', strtotime( $month . '-01 +1 month' ) );
				continue;
			}
			++$guard;
			self::close_month( $month, $deadline );
			if ( self::$stopped ) {
				return false; // Continues in the next run (CONTINUE_HOOK), the month is not closed yet.
			}
			Settings::set_state( 'tier_month_closed', $month ); // Own option: never writes the settings back.
			$month = gmdate( 'Y-m', strtotime( $month . '-01 +1 month' ) );
		}
		return true;
	}

	/**
	 * Book bonuses for one month (idempotent).
	 *
	 * Partners are processed in ID order; with a time limit the month stops after a partner and
	 * continues there in the next run (idempotent per partner either way).
	 *
	 * @param string $month    YYYY-MM.
	 * @param float  $deadline Stop after this time (microtime, 0 = no limit).
	 * @return int Number of bonus rows created.
	 */
	public static function close_month( $month, $deadline = 0.0 ) {
		global $wpdb;
		self::$stopped = false;
		if ( ! preg_match( '/^\d{4}-\d{2}$/', $month ) || ! \WPAffiliateCaptain\Core\Features::enabled( 'tiers' ) || self::skipped( $month ) ) {
			return 0; // Switched off, now or during the month (SET-AC-26): nothing is booked.
		}
		$first = Settings::state( 'tier_month_first' );
		if ( '' === $first || $month < $first ) {
			Settings::set_state( 'tier_month_first', $month );
		}
		$c        = Db::table( 'commissions' );
		$currency = strtoupper( (string) Settings::get( 'currency' ) );
		list( $start, $end ) = self::range( $month );
		$after    = self::cursor( 'close', $month );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$affiliates = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT affiliate_id FROM {$c} WHERE kind = 'sale' AND created_at BETWEEN %s AND %s AND affiliate_id > %d ORDER BY affiliate_id ASC", $start, $end, $after ) ); // All currencies (orders() converts).
		$created    = 0;
		$deferred   = false;
		AffiliateRepository::find_many( $affiliates );

		foreach ( $affiliates as $i => $affiliate_id ) {
			if ( $deadline > 0 && $i > 0 && microtime( true ) > $deadline ) {
				self::stop_at( 'close', $month, (int) $affiliates[ $i - 1 ] );
				return $created;
			}
			$aff = AffiliateRepository::find( (int) $affiliate_id );
			// No bonus for paused or terminated partners.
			$plan = $aff && 'active' === $aff->status ? self::plan_for( $aff ) : null;
			if ( ! $plan ) {
				continue;
			}
			$key = 'bonus:tier:' . (int) $affiliate_id . ':' . $month;
			if ( Ledger::find_by_key( $key ) ) {
				continue;
			}
			if ( self::waits( $month, (int) $affiliate_id ) ) {
				// Longer hold period of the partner's program: the recalculation books it once due.
				$deferred = true;
				continue;
			}
			$orders = self::orders( (int) $affiliate_id, $month, $currency );
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
			// Row, event and balance together: a half-written bonus would block its own repair (idempotency key).
			$id = Db::transaction(
				static function () use ( $wpdb, $c, $affiliate_id, $month, $currency, $result, $plan, $snapshot, $key ) {
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
							'description'     => sprintf( __( 'Monthly bonus %1$s (tier %2$s %%)', 'affiliate-captain' ), $month, $result['reached']['rate'] ),
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
					if ( ! $ok ) {
						return 0;
					}
					$id = (int) $wpdb->insert_id;
					Balance::post( (int) $affiliate_id, $currency, 'approved', $result['amount'], $id, 0, 'tier ' . $month );
					Ledger::event( $id, 'created', '', 'approved', $result['amount'], 'tier_bonus ' . $month, $key, 0 );
					return $id;
				}
			);
			if ( $id ) {
				Db::after_commit(
					static function () use ( $id ) {
						do_action( 'wpac_commission_created', $id );
					}
				);
				++$created;
			}
		}
		if ( $after ) {
			delete_option( self::CURSOR );
		}
		if ( $deferred ) {
			self::set_deferred( $month, true );
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
	 * Net revenue per order whose sale was booked in the month, incl. later
	 * corrections. Only approved or paid rows count (pending/review/rejected do not).
	 *
	 * @param int    $affiliate_id Partner.
	 * @param string $month        YYYY-MM.
	 * @param string $currency     Currency.
	 * @param bool   $open         Also count orders still in the hold period or under review
	 *                             (progress of the running month in the partner area, R14-U4).
	 * @return array
	 */
	private static function orders( $affiliate_id, $month, $currency, $open = false ) {
		global $wpdb;
		$c        = Db::table( 'commissions' );
		$statuses = $open ? "'pending','review','approved','paid'" : "'approved','paid'"; // Constant fragment, no input.
		list( $start, $end ) = self::range( $month );
		// All currencies: orders in another currency count with their value in the program currency
		// (reference rate); without a rate they cannot be valued and are left out. Only the orders
		// sold in the month are read (index affiliate_created), not the partner's whole history;
		// their later corrections (refunds etc., created later) are included.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT c.source, c.source_order_id, c.currency, SUM(c.base_minor) AS revenue, MIN(CASE WHEN c.kind = 'sale' THEN c.created_at END) AS sold_at FROM {$c} c INNER JOIN ( SELECT DISTINCT source, source_order_id FROM {$c} WHERE affiliate_id = %d AND kind = 'sale' AND created_at BETWEEN %s AND %s ) m ON m.source = c.source AND m.source_order_id = c.source_order_id WHERE c.affiliate_id = %d AND c.status IN ({$statuses}) AND c.kind <> 'bonus' GROUP BY c.source, c.source_order_id, c.currency HAVING sold_at BETWEEN %s AND %s ORDER BY sold_at ASC", $affiliate_id, $start, $end, $affiliate_id, $start, $end ), ARRAY_A );
		$out  = array();
		foreach ( $rows as $row ) {
			if ( strtoupper( (string) $row['currency'] ) !== strtoupper( (string) $currency ) ) {
				$value = \WPAffiliateCaptain\Core\Fx::to_program( (int) $row['revenue'], (string) $row['currency'], (string) $currency, \WPAffiliateCaptain\Core\Fx::rates() );
				if ( null === $value ) {
					continue;
				}
				$row['revenue'] = $value;
			}
			unset( $row['currency'] );
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * Re-check closed months and book the difference when refunds, rejections or
	 * late approvals changed the result (cron, last 3 closed months). The plan
	 * frozen in the original bonus is used; months without bonus use the current plan.
	 *
	 * Runs with a time budget: months that did not fit are remembered and come first in the next run.
	 *
	 * @param float|null $budget Seconds (0 = no limit, null = filter wpac_tier_budget).
	 * @return int Number of corrections.
	 */
	public static function recalculate_closed_months( $budget = null ) {
		$budget = null === $budget ? self::budget() : (float) $budget;
		$last = Settings::state( 'tier_month_closed' );
		if ( '' === $last || ! \WPAffiliateCaptain\Core\Features::enabled( 'tiers' ) || ! self::all() ) {
			return 0;
		}
		$months = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$months[] = gmdate( 'Y-m', strtotime( $last . '-01 -' . $i . ' month' ) );
		}
		$changed = self::changed_months( $last );
		$pending = array_filter(
			array_map( 'strval', (array) get_option( self::RECALC_PENDING, array() ) ),
			static function ( $m ) {
				return (bool) preg_match( '/^\d{4}-\d{2}$/', $m );
			}
		);
		$months   = array_values( array_unique( array_merge( $pending, $months, array_map( 'strval', (array) $changed ), self::deferred_months() ) ) );
		$n        = 0;
		$start    = microtime( true );
		$deadline = $budget > 0 ? $start + $budget : 0.0;
		foreach ( $months as $i => $month ) {
			if ( $budget > 0 && $i > 0 && microtime( true ) - $start > $budget ) {
				update_option( self::RECALC_PENDING, array_slice( $months, $i ), false );
				\WPAffiliateCaptain\Core\Scheduler::once( self::CONTINUE_HOOK, 120 ); // Not only the next day.
				return $n;
			}
			$n += self::recalculate_month( $month, $deadline );
			if ( self::$stopped ) {
				// Stopped within the month: it comes first in the continuation (from its cursor).
				update_option( self::RECALC_PENDING, array_slice( $months, $i ), false );
				return $n;
			}
		}
		if ( $pending ) {
			delete_option( self::RECALC_PENDING );
		}
		return $n;
	}

	/**
	 * Older closed months (up to 24 months before $last) whose commissions changed in the last
	 * three days: late approvals after a long hold, late rejections and refunds. A late refund is
	 * a new row (created now): the month that changes is the month of the order's sale, so the
	 * changed orders are mapped to the month of their sale row.
	 *
	 * @param string $last Last closed month (YYYY-MM).
	 * @return string[] Months (YYYY-MM).
	 */
	public static function changed_months( $last ) {
		global $wpdb;
		$c     = Db::table( 'commissions' );
		$first = gmdate( 'Y-m', strtotime( $last . '-01 -24 month' ) );
		// UTC days of the sales; each is mapped to the bonus month(s) it belongs to (a UTC day can
		// touch two local months), so the month boundaries follow range().
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$days   = array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT LEFT(s.created_at, 10) FROM {$c} s INNER JOIN ( SELECT DISTINCT source, source_order_id FROM {$c} WHERE kind <> 'bonus' AND updated_at >= %s ) r ON r.source = s.source AND r.source_order_id = s.source_order_id WHERE s.kind = 'sale' AND s.created_at >= %s AND s.created_at <= %s", Db::now( time() - 3 * DAY_IN_SECONDS ), self::range( $first )[0], self::range( $last )[1] ) ) );
		$months = array();
		foreach ( $days as $day ) {
			foreach ( array( $day . ' 00:00:00', $day . ' 23:59:59' ) as $t ) {
				foreach ( array( substr( $t, 0, 7 ), gmdate( 'Y-m', strtotime( substr( $t, 0, 7 ) . '-01 -1 month' ) ), gmdate( 'Y-m', strtotime( substr( $t, 0, 7 ) . '-01 +1 month' ) ) ) as $m ) {
					list( $from, $to ) = self::range( $m );
					if ( $t >= $from && $t <= $to && $m >= $first && $m <= $last ) {
						$months[ $m ] = $m;
					}
				}
			}
		}
		sort( $months );
		return array_values( $months );
	}

	/**
	 * Recalculate one closed month and book corrections.
	 *
	 * @param string $month    YYYY-MM.
	 * @param float  $deadline Stop after this time (microtime, 0 = no limit); continues from the partner reached.
	 * @return int Number of corrections booked.
	 */
	public static function recalculate_month( $month, $deadline = 0.0 ) {
		self::$stopped = false;
		if ( ! preg_match( '/^\d{4}-\d{2}$/', (string) $month ) || ! \WPAffiliateCaptain\Core\Features::enabled( 'tiers' ) || self::skipped( $month ) ) {
			return 0; // Switched off, now or during the month (SET-AC-26): booked bonuses stay as they are.
		}
		global $wpdb;
		$c        = Db::table( 'commissions' );
		$currency = strtoupper( (string) Settings::get( 'currency' ) );
		list( $start, $end ) = self::range( $month );
		$after    = self::cursor( 'recalc', $month );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$affiliates = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT affiliate_id FROM {$c} WHERE kind = 'sale' AND created_at BETWEEN %s AND %s AND affiliate_id > %d ORDER BY affiliate_id ASC", $start, $end, $after ) ); // All currencies (orders() converts).
		$booked     = 0;
		$done       = 0;
		$waiting    = false;
		$first      = Settings::state( 'tier_month_first' );
		if ( '' === $first ) {
			$first = Settings::state( 'tier_month_closed' ); // Installs from before this setting.
		}
		foreach ( $affiliates as $affiliate_id ) {
			$affiliate_id = (int) $affiliate_id;
			if ( $deadline > 0 && $done > 0 && microtime( true ) > $deadline ) {
				self::stop_at( 'recalc', $month, $done );
				break;
			}
			$done = $affiliate_id;
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$c} WHERE affiliate_id = %d AND kind = 'bonus' AND source = 'wpac' AND source_order_id = %s AND status <> 'rejected' ORDER BY id ASC", $affiliate_id, 'tier-' . $month ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$all  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$c} WHERE affiliate_id = %d AND kind = 'bonus' AND source = 'wpac' AND source_order_id = %s", $affiliate_id, 'tier-' . $month ) );
			$plan = null;
			if ( ! $rows && $all ) {
				continue; // The bonus of this month was rejected by an admin: that decision is final.
			}
			if ( ! $rows && ( '' === $first || $month < $first ) ) {
				continue; // Never closed (before the program had tiers): no retroactive bonus.
			}
			if ( ! $rows && self::waits( $month, $affiliate_id ) ) {
				$waiting = true; // Hold period of the partner's program not over yet (deferred at closing).
				continue;
			}
			if ( $rows ) {
				$snap = json_decode( (string) $rows[0]->rule_snapshot, true );
				$plan = isset( $snap['plan'] ) ? $snap['plan'] : null;
			} else {
				$aff = AffiliateRepository::find( $affiliate_id );
				$cur = $aff ? self::plan_for( $aff ) : null;
				if ( $cur ) {
					$plan = array(
						'id'      => (int) $cur->id,
						'version' => (int) $cur->version,
						'scope'   => $cur->scope,
						'basis'   => $cur->basis,
						'mode'    => $cur->mode,
						'steps'   => json_decode( (string) $cur->steps, true ),
					);
				}
			}
			if ( ! $plan ) {
				continue;
			}
			$result = Tiers::bonus( self::orders( $affiliate_id, $month, $currency ), (array) $plan['steps'], $plan['basis'], $plan['mode'] );
			$before = 0;
			foreach ( $rows as $r ) {
				$before += (int) $r->amount_minor;
			}
			$delta = (int) $result['amount'] - $before;
			if ( 0 === $delta ) {
				continue;
			}
			if ( $delta > 0 ) {
				$aff = AffiliateRepository::find( $affiliate_id );
				if ( ! $aff || 'active' !== $aff->status ) {
					continue; // No additional bonus for paused or terminated partners (clawbacks still apply).
				}
			}
			// Suffix from ALL rows of the month (incl. rejected ones), so a key never repeats.
			$key = 'bonus:tier:' . $affiliate_id . ':' . $month . ( $all ? ':corr:' . $all : '' );
			if ( Ledger::find_by_key( $key ) ) {
				continue;
			}
			$id = Db::transaction(
				static function () use ( $wpdb, $c, $affiliate_id, $month, $currency, $result, $plan, $rows, $before, $delta, $key ) {
					$ok = $wpdb->insert(
						$c,
						array(
							'affiliate_id'    => $affiliate_id,
							'source'          => 'wpac',
							'source_order_id' => 'tier-' . $month,
							'source_item_id'  => '',
							'kind'            => 'bonus',
							'parent_id'       => $rows ? (int) $rows[0]->id : 0,
							'product_ref'     => '',
							/* translators: %s: month */
							'description'     => $rows ? sprintf( __( 'Monthly bonus correction %s', 'affiliate-captain' ), $month ) : sprintf( __( 'Monthly bonus %1$s (tier %2$s %%)', 'affiliate-captain' ), $month, $result['reached'] ? $result['reached']['rate'] : '0' ),
							'currency'        => $currency,
							'base_minor'      => $result['revenue'],
							'amount_minor'    => $delta,
							'rate_type'       => 'percent',
							'rate'            => (string) ( $result['reached'] ? $result['reached']['rate'] : '0' ),
							'rule_id'         => (int) $plan['id'],
							'rule_snapshot'   => wp_json_encode(
								array(
									'plan'       => $plan,
									'month'      => $month,
									'revenue'    => $result['revenue'],
									'orders'     => $result['orders'],
									'reached'    => $result['reached'],
									'previous'   => $before,
									'correction' => (bool) $rows,
								)
							),
							'status'          => 'approved',
							'hold_until'      => Db::now(),
							'idempotency_key' => $key,
							'created_at'      => Db::now(),
							'updated_at'      => Db::now(),
						)
					);
					if ( ! $ok ) {
						return 0;
					}
					$id = (int) $wpdb->insert_id;
					Ledger::event( $id, 'created', '', 'approved', $delta, 'tier_bonus_correction ' . $month, $key, 0 );
					Balance::post( $affiliate_id, $currency, 'approved', $delta, $id, 0, 'tier correction ' . $month );
					// After COMMIT (dropped on rollback), like the other ledger bookings.
					Db::after_commit(
						static function () use ( $id ) {
							do_action( 'wpac_commission_created', $id );
						}
					);
					return $id;
				}
			);
			if ( $id ) {
				++$booked;
			}
		}
		if ( ! self::$stopped && $after ) {
			delete_option( self::CURSOR );
		}
		if ( ! self::$stopped && ! $after ) {
			// A whole pass over the month: it stays deferred only while partners still wait.
			self::set_deferred( $month, $waiting );
		}
		if ( $booked ) {
			Audit::log(
				'tier.month_recalculated',
				'tier',
				0,
				array(
					'month'       => $month,
					'corrections' => $booked,
				)
			);
		}
		return $booked;
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
			$out[] = ( 'orders' === $plan->basis ? (int) $s['threshold'] : Money::format( (int) $s['threshold'], $currency ) ) . ' → +' . \WPAffiliateCaptain\Core\Locale::percent( $s['rate'] );
		}
		return implode( ', ', $out );
	}

	/**
	 * Steps in the partner's language: what counts (orders or net sales per local month), from
	 * when and on what the extra rate is paid (marginal: only above the step; retroactive: on
	 * the whole month), e.g. "From 5 orders in a month: +2 % of the net value of all orders of
	 * that month" (R14-U4).
	 *
	 * @param object $plan     Plan.
	 * @param string $currency Program currency.
	 * @return string[] One sentence per step.
	 */
	public static function partner_text( $plan, $currency ) {
		$steps = json_decode( (string) $plan->steps, true );
		$out   = array();
		foreach ( (array) $steps as $s ) {
			if ( ! is_array( $s ) || ! isset( $s['threshold'], $s['rate'] ) ) {
				continue;
			}
			$rate = '+' . \WPAffiliateCaptain\Core\Locale::percent( $s['rate'] );
			if ( 'orders' === $plan->basis ) {
				$n     = (int) $s['threshold'];
				$out[] = 'retroactive' === $plan->mode
					/* translators: 1: number of orders, 2: extra rate, e.g. +2 % */
					? sprintf( _n( 'From %1$s order in a month: %2$s of the net value of all orders of that month', 'From %1$s orders in a month: %2$s of the net value of all orders of that month', $n, 'affiliate-captain' ), number_format_i18n( $n ), $rate )
					/* translators: 1: number of the order, 2: extra rate, e.g. +2 % */
					: sprintf( __( 'From order no. %1$s in a month: %2$s of the net value of this and every further order of that month', 'affiliate-captain' ), number_format_i18n( $n ), $rate );
			} else {
				$amount = Money::format( (int) $s['threshold'], $currency );
				$out[]  = 'retroactive' === $plan->mode
					/* translators: 1: amount, 2: extra rate, e.g. +2 % */
					? sprintf( __( 'From %1$s net sales in a month: %2$s of all net sales of that month', 'affiliate-captain' ), $amount, $rate )
					/* translators: 1: amount, 2: extra rate, e.g. +2 % */
					: sprintf( __( 'From %1$s net sales in a month: %2$s of the net sales above this amount', 'affiliate-captain' ), $amount, $rate );
			}
		}
		return $out;
	}

	/**
	 * Progress of a partner in the running local month: orders or net sales so far (including
	 * orders still in the hold period), the step reached and the next step (R14-U4).
	 *
	 * @param object $aff  Partner.
	 * @param object $plan Plan (plan_for()).
	 * @return array|null basis, measure, reached (step|null), next (step|null); null without steps.
	 */
	public static function progress( $aff, $plan ) {
		$steps = json_decode( (string) $plan->steps, true );
		$steps = array_values(
			array_filter(
				(array) $steps,
				static function ( $s ) {
					return is_array( $s ) && isset( $s['threshold'], $s['rate'] );
				}
			)
		);
		if ( ! $steps ) {
			return null;
		}
		usort(
			$steps,
			static function ( $a, $b ) {
				return (int) $a['threshold'] <=> (int) $b['threshold'];
			}
		);
		$orders  = self::orders( (int) $aff->id, Db::local_month( 0 ), strtoupper( (string) Settings::get( 'currency' ) ), true );
		$result  = Tiers::bonus( $orders, $steps, $plan->basis, $plan->mode );
		$measure = 'orders' === $plan->basis ? (int) $result['orders'] : (int) $result['revenue'];
		$next    = null;
		foreach ( $steps as $s ) {
			if ( $measure < (int) $s['threshold'] ) {
				$next = $s;
				break;
			}
		}
		return array(
			'basis'   => 'orders' === $plan->basis ? 'orders' : 'revenue',
			'measure' => $measure,
			'reached' => $result['reached'],
			'next'    => $next,
		);
	}
}
