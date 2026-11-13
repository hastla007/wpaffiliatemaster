<?php
/**
 * Monthly summary e-mails (operator and partners), sent once per month by cron.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Reports;

use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Core\Audit;
use WPAffiliateCaptain\Core\Money;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Payouts\PayoutRequests;

defined( 'ABSPATH' ) || exit;

final class MonthlyMail {

	const OPTION = 'wpac_report_month_sent';

	/**
	 * Send the summary of the previous month if not done yet (cron, daily).
	 */
	public static function send_due() {
		self::watchdog();
		if ( ! Settings::get( 'report_admin' ) && ! Settings::get( 'report_partners' ) ) {
			return;
		}
		$month = gmdate( 'Y-m', strtotime( 'first day of previous month' ) );
		if ( get_option( self::OPTION ) === $month || ! self::claim( $month ) ) {
			return;
		}
		update_option( self::OPTION, $month, false ); // Mark first: never send twice even if mails fail.
		self::send( $month, true );
		if ( Settings::get( 'report_partners' ) ) {
			update_option(
				self::PROGRESS,
				array(
					'month'   => $month,
					'offset'  => 0,
					'touched' => time(),
				),
				false
			);
			wp_schedule_single_event( time() + 30, self::BATCH_HOOK );
		}
	}

	/**
	 * Atomically claim the report run of a month. WP-Cron and a system cron can run at the same time;
	 * both would read "not sent" before either marks the month, so the claim is an INSERT that only
	 * one process can win.
	 *
	 * @param string $month Month (Y-m).
	 * @return bool Whether this process won the claim.
	 */
	private static function claim( $month ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", self::CLAIM_PREFIX . $month, (string) time() ) );
		return 1 === (int) $wpdb->rows_affected;
	}

	const CLAIM_PREFIX = 'wpac_report_claim_';
	const PROGRESS     = 'wpac_report_progress';
	const BATCH_HOOK = 'wpac_report_batch';
	const BATCH_SIZE = 50;

	/**
	 * Send the next batch of partner summaries (single cron events until done).
	 */
	public static function batch() {
		$progress = get_option( self::PROGRESS );
		if ( ! is_array( $progress ) || empty( $progress['month'] ) ) {
			return;
		}
		$sent = self::send( $progress['month'], false, (int) $progress['offset'], self::BATCH_SIZE );
		if ( $sent['remaining'] > 0 ) {
			$progress['offset']  += self::BATCH_SIZE;
			$progress['touched']  = time();
			update_option( self::PROGRESS, $progress, false );
			wp_schedule_single_event( time() + 60, self::BATCH_HOOK );
		} else {
			delete_option( self::PROGRESS );
		}
	}

	/**
	 * Send the summaries for a month.
	 *
	 * @param string $month       YYYY-MM.
	 * @param bool   $admin_only  Only the operator summary (partners are sent in batches).
	 * @param int    $offset      Partner offset.
	 * @param int    $limit       Partner batch size (0 = all).
	 * @param int[]  $only        Only these partners (retry of failed mails).
	 * @return array|int Number of e-mails (admin) or [ sent, remaining ] (partners).
	 */
	public static function send( $month, $admin_only = false, $offset = 0, $limit = 0, array $only = array() ) {
		$from     = $month . '-01';
		$to       = gmdate( 'Y-m-t', strtotime( $from ) );
		$currency = (string) Settings::get( 'currency' );
		$site     = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$rows     = Reports::partners( $from, $to );
		$unconv   = array_keys( Reports::$unconverted );
		$sent     = 0;
		$fmt      = static function ( $minor ) use ( $currency ) {
			return html_entity_decode( Money::format( (int) $minor, $currency ), ENT_QUOTES, 'UTF-8' );
		};

		if ( $admin_only ) {
			if ( Settings::get( 'report_admin' ) ) {
				if ( ! self::send_admin( $month, $rows, $unconv, $fmt, $site, $from, $to ) ) {
					self::record_failure( $month, 0 );
					return 0;
				}
				Audit::log( 'report.monthly_admin_sent', 'report', 0, array( 'month' => $month ) );
				return 1;
			}
			return 0;
		}
		$remaining = 0;
		if ( Settings::get( 'report_partners' ) ) {
			$portal = (int) Settings::get( 'portal_page_id' );
			// Batches are cut from a stable order (by partner, not by commission): a change
			// between two batches must not shift partners so that some are skipped or mailed twice.
			usort(
				$rows,
				static function ( $a, $b ) {
					return (int) $a['affiliate_id'] - (int) $b['affiliate_id'];
				}
			);
			if ( $only ) {
				// Retry of failed mails: only these partners.
				$rows = array_values(
					array_filter(
						$rows,
						static function ( $r ) use ( $only ) {
							return in_array( (int) $r['affiliate_id'], $only, true );
						}
					)
				);
			}
			$remaining = max( 0, count( $rows ) - $offset - ( $limit ? $limit : count( $rows ) ) );
			foreach ( $limit ? array_slice( $rows, $offset, $limit ) : $rows as $r ) {
				$aff = AffiliateRepository::find( $r['affiliate_id'] );
				if ( ! $aff || 'active' !== $aff->status || \WPAffiliateCaptain\Privacy\Restriction::is_restricted( (int) $aff->id ) || ( ! $r['orders'] && ! $r['visits'] && ! $r['commission'] ) ) {
					continue;
				}
				$lines   = array();
				$lines[] = sprintf( /* translators: %s: name */ __( 'Hello %s,', 'wp-affiliate-captain' ), $aff->name );
				$lines[] = '';
				$lines[] = sprintf( /* translators: %s: month */ __( 'here is your summary for %s:', 'wp-affiliate-captain' ), $month );
				$lines[] = __( 'Link visits', 'wp-affiliate-captain' ) . ': ' . $r['visits'];
				$lines[] = __( 'Sales', 'wp-affiliate-captain' ) . ': ' . $r['orders'];
				$lines[] = __( 'Commission', 'wp-affiliate-captain' ) . ': ' . $fmt( $r['commission'] );
				if ( $r['corrections'] ) {
					$lines[] = __( 'Corrections', 'wp-affiliate-captain' ) . ': ' . $fmt( $r['corrections'] );
				}
				if ( $r['writeoffs'] ) {
					$lines[] = __( 'Written-off negative balance', 'wp-affiliate-captain' ) . ': ' . $fmt( $r['writeoffs'] );
				}
				if ( $r['bonus'] ) {
					$lines[] = __( 'Monthly bonus', 'wp-affiliate-captain' ) . ': ' . $fmt( $r['bonus'] );
				}
				$lines[] = __( 'Available for payout', 'wp-affiliate-captain' ) . ': ' . $fmt( PayoutRequests::available( (int) $aff->id ) );
				if ( $portal ) {
					$lines[] = '';
					$lines[] = get_permalink( $portal );
				}
				if ( ! wp_mail( $aff->email, sprintf( '[%s] %s', $site, sprintf( /* translators: %s: month */ __( 'Your partner summary %s', 'wp-affiliate-captain' ), $month ) ), implode( "\n", $lines ) ) ) {
					self::record_failure( $month, (int) $aff->id ); // Retried later (retry_failed()).
					continue;
				}
				++$sent;
			}
		}
		Audit::log(
			'report.monthly_partner_batch',
			'report',
			0,
			array(
				'month'  => $month,
				'mails'  => $sent,
				'offset' => $offset,
			)
		);
		return array(
			'sent'      => $sent,
			'remaining' => $remaining,
		);
	}

	/**
	 * Operator summary.
	 *
	 * @param string   $month  Month.
	 * @param array    $rows   Partner rows.
	 * @param array    $unconv Unconverted currencies.
	 * @param callable $fmt    Money formatter.
	 * @param string   $site   Site name.
	 * @param string   $from   From date.
	 * @param string   $to     To date.
	 * @return bool Whether the mail was handed over.
	 */
	private static function send_admin( $month, array $rows, array $unconv, $fmt, $site, $from, $to ) {
		$tot = array(
			'visits'      => 0,
			'orders'      => 0,
			'revenue'     => 0,
			'commission'  => 0,
			'corrections' => 0,
			'bonus'       => 0,
			'writeoffs'   => 0,
		);
		foreach ( $rows as $r ) {
			foreach ( $tot as $k => $v ) {
				$tot[ $k ] += $r[ $k ];
			}
		}
		$lines   = array();
		$lines[] = sprintf( /* translators: %s: month */ __( 'Partner program summary %s', 'wp-affiliate-captain' ), $month );
		$lines[] = '';
		$lines[] = __( 'Link visits', 'wp-affiliate-captain' ) . ': ' . $tot['visits'];
		$lines[] = __( 'Attributed orders', 'wp-affiliate-captain' ) . ': ' . $tot['orders'];
		$lines[] = __( 'Net revenue', 'wp-affiliate-captain' ) . ': ' . $fmt( $tot['revenue'] );
		$lines[] = __( 'Commission', 'wp-affiliate-captain' ) . ': ' . $fmt( $tot['commission'] );
		$lines[] = __( 'Corrections', 'wp-affiliate-captain' ) . ': ' . $fmt( $tot['corrections'] );
		$lines[] = __( 'Monthly bonuses', 'wp-affiliate-captain' ) . ': ' . $fmt( $tot['bonus'] );
		if ( $tot['writeoffs'] ) {
			$lines[] = __( 'Written-off negative balances', 'wp-affiliate-captain' ) . ': ' . $fmt( $tot['writeoffs'] );
		}
		$counts  = AffiliateRepository::count_by_status();
		$lines[] = __( 'Applications waiting', 'wp-affiliate-captain' ) . ': ' . (int) $counts['pending'];
		$lines[] = __( 'Open payout requests', 'wp-affiliate-captain' ) . ': ' . count( PayoutRequests::query( 'requested' ) );
		if ( $unconv ) {
			$lines[] = __( 'Not converted (no reference rate)', 'wp-affiliate-captain' ) . ': ' . implode( ', ', $unconv );
		}
		$lines[] = '';
		$lines[] = __( 'Top partners', 'wp-affiliate-captain' ) . ':';
		foreach ( array_slice( $rows, 0, 10 ) as $r ) {
			$lines[] = sprintf( '- %s: %d / %s', $r['name'], $r['orders'], $fmt( $r['commission'] ) );
		}
		$lines[] = '';
		$lines[] = admin_url( 'admin.php?page=wpac&from=' . $from . '&to=' . $to );
		$to_mail = (string) Settings::get( 'admin_email' );
		return (bool) wp_mail( is_email( $to_mail ) ? $to_mail : (string) get_option( 'admin_email' ), sprintf( '[%s] %s', $site, sprintf( /* translators: %s: month */ __( 'Partner program %s', 'wp-affiliate-captain' ), $month ) ), implode( "\n", $lines ) );
	}

	const FAILED     = 'wpac_report_failed';
	const RETRY_HOOK = 'wpac_report_retry';

	/**
	 * Remember a report mail that could not be sent (partner ID, 0 = operator summary).
	 *
	 * @param string $month        Month.
	 * @param int    $affiliate_id Partner (0 = operator).
	 */
	private static function record_failure( $month, $affiliate_id ) {
		// Failures are kept per month: a failure of a later month must not drop an older one.
		$failed = self::failures();
		if ( ! isset( $failed[ $month ] ) ) {
			$failed[ $month ] = array(
				'ids'   => array(),
				'admin' => false,
				'tries' => 0,
			);
		}
		if ( $affiliate_id ) {
			$failed[ $month ]['ids'][] = (int) $affiliate_id;
			$failed[ $month ]['ids']   = array_values( array_unique( $failed[ $month ]['ids'] ) );
		} else {
			$failed[ $month ]['admin'] = true;
		}
		update_option( self::FAILED, $failed, false );
		if ( ! wp_next_scheduled( self::RETRY_HOOK ) ) {
			wp_schedule_single_event( time() + HOUR_IN_SECONDS, self::RETRY_HOOK );
		}
	}

	/**
	 * Remembered failures: month => [ ids, admin, tries ] (older single-month format is converted).
	 *
	 * @return array
	 */
	public static function failures() {
		wp_cache_delete( self::FAILED, 'options' );
		$failed = get_option( self::FAILED );
		if ( ! is_array( $failed ) ) {
			return array();
		}
		if ( isset( $failed['month'] ) && is_string( $failed['month'] ) ) {
			return array(
				$failed['month'] => array(
					'ids'   => isset( $failed['ids'] ) ? (array) $failed['ids'] : array(),
					'admin' => ! empty( $failed['admin'] ),
					'tries' => isset( $failed['tries'] ) ? (int) $failed['tries'] : 0,
				),
			);
		}
		return $failed;
	}

	/**
	 * Send failed report mails again (hourly, at most 3 attempts per month); what still fails is
	 * logged in the audit log (report.mail_failed) instead of being lost silently. Only one
	 * process retries at a time (WP-Cron and a system cron could overlap and mail twice).
	 */
	public static function retry_failed() {
		$owner = \WPAffiliateCaptain\Core\Db::lock( 'wpac_report_retry_lock', 30 * MINUTE_IN_SECONDS );
		if ( '' === $owner ) {
			return;
		}
		try {
			$failed = self::failures();
			if ( ! $failed ) {
				return;
			}
			delete_option( self::FAILED ); // send() records what fails again in this run.
			foreach ( $failed as $month => $f ) {
				if ( ! empty( $f['admin'] ) ) {
					self::send( (string) $month, true );
				}
				if ( ! empty( $f['ids'] ) ) {
					self::send( (string) $month, false, 0, 0, array_map( 'intval', $f['ids'] ) );
				}
			}
			$left = array();
			foreach ( self::failures() as $month => $f ) {
				$attempt = ( isset( $failed[ $month ] ) ? (int) $failed[ $month ]['tries'] : 0 ) + 1;
				if ( $attempt >= 3 ) {
					Audit::log(
						'report.mail_failed',
						'report',
						0,
						array(
							'month'    => (string) $month,
							'partners' => $f['ids'],
							'operator' => ! empty( $f['admin'] ),
						)
					);
					continue;
				}
				$f['tries']     = $attempt;
				$left[ $month ] = $f;
			}
			if ( $left ) {
				update_option( self::FAILED, $left, false );
			} else {
				delete_option( self::FAILED );
				wp_clear_scheduled_hook( self::RETRY_HOOK );
			}
		} finally {
			\WPAffiliateCaptain\Core\Db::unlock( 'wpac_report_retry_lock', $owner );
		}
	}

	/**
	 * Watchdog (daily): a partner batch chain whose next event got lost (cron cleared, site
	 * migrated, fatal error) is started again; pending retries get their event back.
	 */
	public static function watchdog() {
		$progress = get_option( self::PROGRESS );
		if ( is_array( $progress ) && ! empty( $progress['month'] ) && ! wp_next_scheduled( self::BATCH_HOOK ) ) {
			$touched = isset( $progress['touched'] ) ? (int) $progress['touched'] : 0;
			if ( $touched < time() - 10 * MINUTE_IN_SECONDS ) {
				wp_schedule_single_event( time() + 30, self::BATCH_HOOK );
				Audit::log( 'report.batch_restarted', 'report', 0, array( 'month' => $progress['month'], 'offset' => (int) $progress['offset'] ) );
			}
		}
		if ( self::failures() && ! wp_next_scheduled( self::RETRY_HOOK ) ) {
			wp_schedule_single_event( time() + 60, self::RETRY_HOOK );
		}
	}
}
