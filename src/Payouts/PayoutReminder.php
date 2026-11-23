<?php
/**
 * Payout reminder (SET-AC-19, owner decision E5 a): on the chosen day of each month or quarter
 * the operator gets an e-mail with the open payable amounts per currency (the same figures as the
 * preview of a payout run). Nothing is booked – payout runs are still started by hand; automatic
 * payout runs are an add-on feature.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Payouts;

use WPAffiliateCaptain\Affiliates\Notifications;
use WPAffiliateCaptain\Core\Audit;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Locale;
use WPAffiliateCaptain\Core\Money;
use WPAffiliateCaptain\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class PayoutReminder {

	/** Option: key of the last period a reminder was handled for (e.g. "2026-11" or "2026-Q4"). */
	const SENT = 'wpac_payout_reminder_sent';

	/** Lock against two parallel daily runs. */
	const LOCK = 'wpac_payout_reminder_lock';

	/**
	 * The current reminder period, or null while the reminder is off.
	 *
	 * @param int|null $now Timestamp (default: now).
	 * @return array|null { key, due (bool), mode, day }
	 */
	public static function period( $now = null ) {
		$mode = (string) Settings::get( 'payout_reminder' );
		if ( ! in_array( $mode, array( 'monthly', 'quarterly' ), true ) ) {
			return null;
		}
		$now   = null === $now ? time() : (int) $now;
		$day   = min( 28, max( 1, (int) Settings::get( 'payout_reminder_day' ) ) );
		$year  = (int) wp_date( 'Y', $now );
		$month = (int) wp_date( 'n', $now );
		$today = (int) wp_date( 'j', $now );
		if ( 'monthly' === $mode ) {
			$key   = sprintf( '%04d-%02d', $year, $month );
			$first = $month;
		} else {
			$quarter = (int) floor( ( $month - 1 ) / 3 ) + 1;
			$key     = sprintf( '%04d-Q%d', $year, $quarter );
			$first   = 3 * $quarter - 2;
		}
		return array(
			'key'  => $key,
			// Due from the chosen day on (a missed day, e.g. in maintenance mode, is caught up later in the period).
			'due'  => $month > $first || $today >= $day,
			'mode' => $mode,
			'day'  => $day,
		);
	}

	/**
	 * Daily step: send the reminder once per period when it is due.
	 *
	 * @param int|null $now Timestamp (default: now).
	 * @return bool Whether a reminder was sent.
	 */
	public static function send_due( $now = null ) {
		$period = self::period( $now );
		if ( ! $period || ! $period['due'] || (string) get_option( self::SENT, '' ) === $period['key'] || \WPAffiliateCaptain\Core\Lifecycle::copy_paused() ) {
			return false; // A copy of the site (staging, clone) sends no reminders.
		}
		$owner = Db::lock( self::LOCK, 10 * MINUTE_IN_SECONDS );
		if ( '' === $owner ) {
			return false; // Another run is sending it.
		}
		try {
			if ( (string) get_option( self::SENT, '' ) === $period['key'] ) {
				return false;
			}
			$summary = self::summary();
			if ( ! self::worth_sending( $summary ) ) {
				// Nothing payable and nothing blocked: no mail for this period.
				update_option( self::SENT, $period['key'], false );
				return false;
			}
			$switched = Notifications::switch_for_admin();
			try {
				list( $subject, $body ) = self::message( $summary, $period );
				$sent                   = Notifications::send( 'payout_reminder', Notifications::admin_email(), $subject, $body, array( 'admin' => true ) );
			} finally {
				Locale::restore( $switched );
			}
			if ( ! $sent ) {
				return false; // Tried again with the next daily run.
			}
			update_option( self::SENT, $period['key'], false );
			Audit::log(
				'payout.reminder_sent',
				'payout',
				0,
				array(
					'period'     => $period['key'],
					'currencies' => array_keys( $summary ),
				)
			);
			return true;
		} finally {
			Db::unlock( self::LOCK, $owner );
		}
	}

	/**
	 * Open payable amounts per currency as a payout run would see them today (no booking).
	 *
	 * @return array currency => { eligible, pay_minor, capped, carried, blocked }
	 */
	public static function summary() {
		global $wpdb;
		$t          = Db::table( 'commissions' );
		$program    = strtoupper( (string) Settings::get( 'currency' ) );
		$currencies = array_map( 'strtoupper', (array) $wpdb->get_col( "SELECT DISTINCT currency FROM {$t} WHERE status = 'approved' AND payout_id = 0" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		usort(
			$currencies,
			static function ( $a, $b ) use ( $program ) {
				if ( $program === $a || $program === $b ) {
					return $program === $a ? -1 : ( $program === $b ? 1 : 0 );
				}
				return strcmp( $a, $b );
			}
		);
		$out = array();
		foreach ( array_unique( $currencies ) as $currency ) {
			$sum = array(
				'eligible'  => 0,
				'pay_minor' => 0,
				'capped'    => 0,
				'carried'   => 0,
				'blocked'   => 0,
			);
			foreach ( PayoutService::preview( wp_date( 'Y-m-d' ), $currency ) as $row ) {
				if ( $row['eligible'] ) {
					++$sum['eligible'];
					$sum['pay_minor'] += (int) $row['pay_minor'];
					$sum['capped']    += empty( $row['capped'] ) ? 0 : 1;
				} elseif ( in_array( (string) $row['code'], array( 'negative', 'below_minimum' ), true ) ) {
					++$sum['carried'];
				} else {
					++$sum['blocked'];
				}
			}
			$out[ $currency ] = $sum;
		}
		return $out;
	}

	/**
	 * Whether a summary is worth a mail: someone can be paid, or someone is blocked.
	 *
	 * @param array $summary summary().
	 * @return bool
	 */
	public static function worth_sending( array $summary ) {
		foreach ( $summary as $sum ) {
			if ( $sum['eligible'] > 0 || $sum['blocked'] > 0 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Subject and text of the reminder.
	 *
	 * @param array $summary summary().
	 * @param array $period  period().
	 * @return array [ subject, body ]
	 */
	public static function message( array $summary, array $period ) {
		$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$lines = array();
		foreach ( $summary as $currency => $sum ) {
			/* translators: 1: currency, 2: number of partners, 3: amount */
			$line = sprintf( _n( '%1$s: %2$d partner can be paid now, %3$s in total (VAT included).', '%1$s: %2$d partners can be paid now, %3$s in total (VAT included).', $sum['eligible'], 'affiliate-captain' ), $currency, $sum['eligible'], Money::format( (int) $sum['pay_minor'], (string) $currency ) );
			if ( $sum['capped'] > 0 ) {
				/* translators: %d: number of partners */
				$line .= ' ' . sprintf( _n( '%d of them reaches the maximum payout; the rest stays payable.', '%d of them reach the maximum payout; the rest stays payable.', $sum['capped'], 'affiliate-captain' ), $sum['capped'] );
			}
			if ( $sum['carried'] > 0 ) {
				/* translators: %d: number of partners */
				$line .= ' ' . sprintf( _n( '%d partner is below the minimum (carried forward).', '%d partners are below the minimum (carried forward).', $sum['carried'], 'affiliate-captain' ), $sum['carried'] );
			}
			if ( $sum['blocked'] > 0 ) {
				/* translators: %d: number of partners */
				$line .= ' ' . sprintf( _n( '%d partner cannot be paid yet (see the reason in the payout preview).', '%d partners cannot be paid yet (see the reasons in the payout preview).', $sum['blocked'], 'affiliate-captain' ), $sum['blocked'] );
			}
			$lines[] = '- ' . $line;
		}
		$intro = 'quarterly' === $period['mode']
			? __( 'This is your quarterly payout reminder. Open payable amounts as of today:', 'affiliate-captain' )
			: __( 'This is your monthly payout reminder. Open payable amounts as of today:', 'affiliate-captain' );
		$body  = $intro . "\n\n" . implode( "\n", $lines ) . "\n\n"
			. __( 'Nothing was booked. Start the payout run yourself (preview first):', 'affiliate-captain' ) . "\n"
			. admin_url( 'admin.php?page=wpac-payouts' ) . "\n\n"
			. __( 'Change or switch off this reminder under Settings > Payouts & credit notes.', 'affiliate-captain' );
		/* translators: %s: site name */
		return array( sprintf( __( '[%s] Payout reminder: open payouts', 'affiliate-captain' ), $site ), $body );
	}

	/**
	 * Text for the partner area ('' while the reminder is off): the day payouts are made.
	 *
	 * @return string
	 */
	public static function partner_text() {
		$mode = (string) Settings::get( 'payout_reminder' );
		$day  = min( 28, max( 1, (int) Settings::get( 'payout_reminder_day' ) ) );
		if ( 'monthly' === $mode ) {
			/* translators: %d: day of the month */
			return sprintf( __( 'Payout on day %d of each month', 'affiliate-captain' ), $day );
		}
		if ( 'quarterly' === $mode ) {
			/* translators: %d: day of the month */
			return sprintf( __( 'Payout on day %d of each quarter (January, April, July, October)', 'affiliate-captain' ), $day );
		}
		return '';
	}
}
