<?php
/**
 * Admin: dashboard and partner report (with CSV).
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Admin\Pages;

use WPAffiliateCaptain\Admin\Admin;
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Core\Csv;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Jobs;
use WPAffiliateCaptain\Core\Money;
use WPAffiliateCaptain\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class DashboardPage {

	/**
	 * Handle actions.
	 *
	 * @param string $do Action.
	 */
	public static function handle( $do ) {
		if ( 'report_export' !== $do ) {
			return;
		}
		$from     = self::date_or( Admin::post( 'from' ), gmdate( 'Y-m-01' ) );
		$to       = self::date_or( Admin::post( 'to' ), gmdate( 'Y-m-d' ) );
		$currency = (string) Settings::get( 'currency' );
		$out      = array();
		foreach ( self::report( $from, $to, $currency ) as $r ) {
			$out[] = array( $r['affiliate_id'], $r['name'], $r['visits'], $r['orders'], Money::to_decimal( $r['revenue'], $currency ), Money::to_decimal( $r['commission'], $currency ), Money::to_decimal( $r['corrections'], $currency ), $r['refund_rate'] );
		}
		Csv::download( "partner-report-{$from}-{$to}.csv", array( 'affiliate_id', 'partner', 'visits', 'orders', 'net_revenue', 'commission', 'corrections', 'refund_rate_percent' ), $out );
	}

	/**
	 * Valid Y-m-d or fallback.
	 *
	 * @param mixed  $value    Value.
	 * @param string $fallback Fallback.
	 * @return string
	 */
	private static function date_or( $value, $fallback ) {
		$value = sanitize_text_field( (string) $value );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : $fallback;
	}

	/**
	 * Per-partner report rows.
	 *
	 * @param string $from     Y-m-d.
	 * @param string $to       Y-m-d.
	 * @param string $currency Currency.
	 * @return array
	 */
	public static function report( $from, $to, $currency ) {
		global $wpdb;
		$c     = Db::table( 'commissions' );
		$v     = Db::table( 'visits' );
		$at    = Db::table( 'attributions' );
		$start = $from . ' 00:00:00';
		$end   = $to . ' 23:59:59';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$money = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT affiliate_id,
					SUM(CASE WHEN kind = 'sale' AND status <> 'rejected' THEN base_minor ELSE 0 END) revenue,
					SUM(CASE WHEN kind = 'sale' AND status <> 'rejected' THEN amount_minor ELSE 0 END) commission,
					SUM(CASE WHEN kind <> 'sale' AND status <> 'rejected' THEN amount_minor ELSE 0 END) corrections
				FROM {$c} WHERE currency = %s AND created_at BETWEEN %s AND %s GROUP BY affiliate_id",
				$currency,
				$start,
				$end
			),
			OBJECT_K
		);
		$visits = $wpdb->get_results( $wpdb->prepare( "SELECT affiliate_id, COUNT(*) n FROM {$v} WHERE created_at BETWEEN %s AND %s GROUP BY affiliate_id", $start, $end ), OBJECT_K );
		$orders = $wpdb->get_results( $wpdb->prepare( "SELECT affiliate_id, COUNT(*) n FROM {$at} WHERE created_at BETWEEN %s AND %s GROUP BY affiliate_id", $start, $end ), OBJECT_K );
		// phpcs:enable
		$ids = array_unique( array_merge( array_keys( $money ), array_keys( $visits ), array_keys( $orders ) ) );
		$out = array();
		foreach ( $ids as $id ) {
			$aff        = AffiliateRepository::find( (int) $id );
			$commission = isset( $money[ $id ] ) ? (int) $money[ $id ]->commission : 0;
			$corr       = isset( $money[ $id ] ) ? (int) $money[ $id ]->corrections : 0;
			$out[]      = array(
				'affiliate_id' => (int) $id,
				'name'         => $aff ? $aff->name : '#' . $id,
				'visits'       => isset( $visits[ $id ] ) ? (int) $visits[ $id ]->n : 0,
				'orders'       => isset( $orders[ $id ] ) ? (int) $orders[ $id ]->n : 0,
				'revenue'      => isset( $money[ $id ] ) ? (int) $money[ $id ]->revenue : 0,
				'commission'   => $commission,
				'corrections'  => $corr,
				'refund_rate'  => $commission > 0 ? round( abs( $corr ) * 100 / $commission, 1 ) : 0,
			);
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return $b['commission'] <=> $a['commission'];
			}
		);
		return $out;
	}

	/**
	 * Render.
	 */
	public static function render() {
		$currency = (string) Settings::get( 'currency' );
		$from     = self::date_or( Admin::get( 'from' ), gmdate( 'Y-m-d', time() - 29 * DAY_IN_SECONDS ) );
		$to       = self::date_or( Admin::get( 'to' ), gmdate( 'Y-m-d' ) );
		$counts   = AffiliateRepository::count_by_status();
		$rows     = self::report( $from, $to, $currency );
		$failed   = count( Jobs::query( 'failed' ) );

		$sum = array(
			'visits'      => 0,
			'orders'      => 0,
			'revenue'     => 0,
			'commission'  => 0,
			'corrections' => 0,
		);
		foreach ( $rows as $r ) {
			foreach ( $sum as $k => $v ) {
				$sum[ $k ] += $r[ $k ];
			}
		}

		echo '<div class="wrap wpac-wrap"><h1>' . esc_html__( 'Affiliate Captain', 'wp-affiliate-captain' ) . '</h1>';
		Admin::notice();
		if ( $counts['pending'] ) {
			/* translators: %d: count */
			echo '<div class="notice notice-warning inline"><p><a href="' . esc_url( Admin::url( 'wpac-affiliates', array( 'status' => 'pending' ) ) ) . '">' . esc_html( sprintf( _n( '%d application waiting for review', '%d applications waiting for review', $counts['pending'], 'wp-affiliate-captain' ), $counts['pending'] ) ) . '</a></p></div>';
		}
		if ( $failed ) {
			/* translators: %d: count */
			echo '<div class="notice notice-error inline"><p><a href="' . esc_url( Admin::url( 'wpac-integrations' ) ) . '">' . esc_html( sprintf( _n( '%d integration job failed', '%d integration jobs failed', $failed, 'wp-affiliate-captain' ), $failed ) ) . '</a></p></div>';
		}

		echo '<form method="get" class="wpac-filters"><input type="hidden" name="page" value="wpac"><label>' . esc_html__( 'from', 'wp-affiliate-captain' ) . ' <input type="date" name="from" value="' . esc_attr( $from ) . '"></label> <label>' . esc_html__( 'to', 'wp-affiliate-captain' ) . ' <input type="date" name="to" value="' . esc_attr( $to ) . '"></label> <button class="button">' . esc_html__( 'Apply', 'wp-affiliate-captain' ) . '</button></form>';

		echo '<div class="wpac-kpis">';
		foreach (
			array(
				__( 'Active partners', 'wp-affiliate-captain' )  => number_format_i18n( $counts['active'] ),
				__( 'Link visits', 'wp-affiliate-captain' )      => number_format_i18n( $sum['visits'] ),
				__( 'Attributed orders', 'wp-affiliate-captain' ) => number_format_i18n( $sum['orders'] ),
				__( 'Net revenue', 'wp-affiliate-captain' )      => Money::format( $sum['revenue'], $currency ),
				__( 'Commission', 'wp-affiliate-captain' )       => Money::format( $sum['commission'], $currency ),
				__( 'Corrections', 'wp-affiliate-captain' )      => Money::format( $sum['corrections'], $currency ),
			) as $label => $value
		) {
			echo '<div class="wpac-kpi"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( $value ) . '</strong></div>';
		}
		echo '</div>';

		echo '<h2>' . esc_html__( 'Partners in this period', 'wp-affiliate-captain' ) . '</h2>';
		Admin::form_open( 'report_export', 'wpac-inline' );
		echo '<input type="hidden" name="from" value="' . esc_attr( $from ) . '"><input type="hidden" name="to" value="' . esc_attr( $to ) . '"><button class="button">' . esc_html__( 'Export CSV', 'wp-affiliate-captain' ) . '</button></form>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Partner', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Visits', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Orders', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Net revenue', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Commission', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Corrections', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Refund rate', 'wp-affiliate-captain' ) . '</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="7">' . esc_html__( 'No activity in this period.', 'wp-affiliate-captain' ) . '</td></tr>';
		}
		foreach ( $rows as $r ) {
			printf(
				'<tr><td><a href="%s">%s</a></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s %%</td></tr>',
				esc_url( Admin::url( 'wpac-affiliates', array( 'action' => 'edit', 'id' => $r['affiliate_id'] ) ) ),
				esc_html( $r['name'] ),
				esc_html( number_format_i18n( $r['visits'] ) ),
				esc_html( number_format_i18n( $r['orders'] ) ),
				esc_html( Money::format( $r['revenue'], $currency ) ),
				esc_html( Money::format( $r['commission'], $currency ) ),
				esc_html( Money::format( $r['corrections'], $currency ) ),
				esc_html( number_format_i18n( $r['refund_rate'], 1 ) )
			);
		}
		echo '</tbody></table></div>';
	}
}
