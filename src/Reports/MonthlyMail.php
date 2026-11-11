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
		if ( ! Settings::get( 'report_admin' ) && ! Settings::get( 'report_partners' ) ) {
			return;
		}
		$month = gmdate( 'Y-m', strtotime( 'first day of previous month' ) );
		if ( get_option( self::OPTION ) === $month ) {
			return;
		}
		update_option( self::OPTION, $month, false ); // Mark first: never send twice even if mails fail.
		self::send( $month );
	}

	/**
	 * Send the summaries for a month.
	 *
	 * @param string $month YYYY-MM.
	 * @return int Number of e-mails.
	 */
	public static function send( $month ) {
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

		if ( Settings::get( 'report_admin' ) ) {
			$tot = array(
				'visits'      => 0,
				'orders'      => 0,
				'revenue'     => 0,
				'commission'  => 0,
				'corrections' => 0,
				'bonus'       => 0,
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
			wp_mail( is_email( $to_mail ) ? $to_mail : (string) get_option( 'admin_email' ), sprintf( '[%s] %s', $site, sprintf( /* translators: %s: month */ __( 'Partner program %s', 'wp-affiliate-captain' ), $month ) ), implode( "\n", $lines ) );
			++$sent;
		}

		if ( Settings::get( 'report_partners' ) ) {
			$portal = (int) Settings::get( 'portal_page_id' );
			foreach ( $rows as $r ) {
				$aff = AffiliateRepository::find( $r['affiliate_id'] );
				if ( ! $aff || 'active' !== $aff->status || ( ! $r['orders'] && ! $r['visits'] && ! $r['commission'] ) ) {
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
				if ( $r['bonus'] ) {
					$lines[] = __( 'Monthly bonus', 'wp-affiliate-captain' ) . ': ' . $fmt( $r['bonus'] );
				}
				$lines[] = __( 'Available for payout', 'wp-affiliate-captain' ) . ': ' . $fmt( PayoutRequests::available( (int) $aff->id ) );
				if ( $portal ) {
					$lines[] = '';
					$lines[] = get_permalink( $portal );
				}
				wp_mail( $aff->email, sprintf( '[%s] %s', $site, sprintf( /* translators: %s: month */ __( 'Your partner summary %s', 'wp-affiliate-captain' ), $month ) ), implode( "\n", $lines ) );
				++$sent;
			}
		}
		Audit::log(
			'report.monthly_sent',
			'report',
			0,
			array(
				'month' => $month,
				'mails' => $sent,
			)
		);
		return $sent;
	}
}
