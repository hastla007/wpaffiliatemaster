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
use WPAffiliateCaptain\Core\Jobs;
use WPAffiliateCaptain\Core\Money;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Reports\Reports;

defined( 'ABSPATH' ) || exit;

final class DashboardPage {

	/**
	 * Handle actions.
	 *
	 * @param string $do Action.
	 */
	public static function handle( $do ) {
		if ( 'setup_pages' === $do ) {
			list( $created, $failed ) = self::create_pages();
			if ( $failed ) {
				Admin::back( __( 'Some pages could not be created. Please create them yourself with the blocks "Partner application" and "Partner area" and select them in the settings.', 'affiliate-captain' ), 'error', Admin::url( 'wpac-settings', array( 'tab' => 'program' ) ), $failed );
			}
			if ( ! $created ) {
				Admin::back( __( 'All pages already exist – nothing was created.', 'affiliate-captain' ), 'info' );
			}
			Admin::back( __( 'Pages created and selected in the settings.', 'affiliate-captain' ), 'success', '', $created );
		}
		if ( 'report_export' !== $do ) {
			return;
		}
		$from     = self::date_or( Admin::post( 'from' ), wp_date( 'Y-m-01' ) );
		$to       = self::date_or( Admin::post( 'to' ), wp_date( 'Y-m-d' ) );
		$view     = sanitize_key( Admin::post( 'view' ) );
		$currency = (string) Settings::get( 'currency' );
		$out      = array();
		if ( in_array( $view, array( 'content', 'campaign' ), true ) ) {
			foreach ( Reports::by_dimension( 'content' === $view ? 'content_id' : 'campaign', $from, $to ) as $r ) {
				$out[] = array( $r['key'], $r['label'], $r['visits'], $r['partners'], $r['orders'], $r['conversion'], Money::to_decimal( $r['revenue'], $currency ), Money::to_decimal( $r['commission'], $currency ) );
			}
			Csv::download( "report-{$view}-{$from}-{$to}.csv", array( 'content' === $view ? 'content_id' : 'campaign', 'label', 'visits', 'partners', 'orders', 'conversion_percent', 'net_revenue_' . $currency, 'commission_' . $currency ), $out );
		}
		foreach ( Reports::partners( $from, $to ) as $r ) {
			$out[] = array( $r['affiliate_id'], $r['name'], $r['visits'], $r['orders'], Money::to_decimal( $r['revenue'], $currency ), Money::to_decimal( $r['commission'], $currency ), Money::to_decimal( $r['corrections'], $currency ), Money::to_decimal( $r['bonus'], $currency ), Money::to_decimal( $r['writeoffs'], $currency ), $r['refund_rate'] );
		}
		Csv::download( "partner-report-{$from}-{$to}.csv", array( 'affiliate_id', 'partner', 'visits', 'orders', 'net_revenue_' . $currency, 'commission_' . $currency, 'corrections_' . $currency, 'bonus_' . $currency, 'writeoffs_' . $currency, 'refund_rate_percent' ), $out );
	}

	/**
	 * Create application, partner area and landing pages (with blocks) if missing.
	 *
	 * @return array [ titles of created pages, error lines ]
	 */
	private static function create_pages() {
		$pages = array(
			'registration_page_id' => array( __( 'Become a partner', 'affiliate-captain' ), '<!-- wp:wpac/registration /-->' ),
			'portal_page_id'       => array( __( 'Partner area', 'affiliate-captain' ), '<!-- wp:wpac/portal /-->' ),
			'landing_page_id'      => array( __( 'Recommended by our partner', 'affiliate-captain' ), "<!-- wp:wpac/partner-welcome /-->\n<!-- wp:paragraph --><p>" . esc_html__( 'Welcome! Here you can introduce your offer to visitors referred by a partner.', 'affiliate-captain' ) . '</p><!-- /wp:paragraph -->' ),
		);
		$update  = array();
		$created = array();
		$failed  = array();
		foreach ( $pages as $key => $page ) {
			$current = (int) Settings::get( $key );
			if ( $current && get_post( $current ) && 'trash' !== get_post_status( $current ) ) {
				continue;
			}
			$id = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => $page[0],
					'post_content' => $page[1],
				),
				true
			);
			if ( $id && ! is_wp_error( $id ) ) {
				$update[ $key ] = (int) $id;
				$created[]      = $page[0];
			} else {
				$failed[] = $page[0] . ': ' . ( is_wp_error( $id ) ? $id->get_error_message() : __( 'unknown error', 'affiliate-captain' ) );
			}
		}
		if ( $update ) {
			Settings::update( $update );
			\WPAffiliateCaptain\Core\Audit::log( 'setup.pages_created', 'settings', 0, $update );
		}
		return array( $created, $failed );
	}

	/**
	 * Whether partners exist whose credit notes need reverse charge (foreign businesses).
	 *
	 * @return bool
	 */
	private static function has_reverse_charge_partners() {
		global $wpdb;
		$t = \WPAffiliateCaptain\Core\Db::table( 'payout_profiles' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT affiliate_id FROM {$t} WHERE tax_status IN ('business','small_business') AND country <> '' AND country <> %s LIMIT 1", strtoupper( (string) Settings::get( 'operator_country' ) ) ) );
	}

	/**
	 * Setup checklist (shown until everything is done).
	 */
	private static function checklist() {
		$items = array(
			array( (bool) Settings::get( 'registration_page_id' ) && (bool) Settings::get( 'portal_page_id' ), __( 'Application page and partner area page', 'affiliate-captain' ), Admin::url( 'wpac-settings', array( 'tab' => 'program' ) ) ),
			array( (bool) Settings::get( 'terms_page_id' ), __( 'Partner program terms (page and version)', 'affiliate-captain' ), Admin::url( 'wpac-settings', array( 'tab' => 'program' ) ) ),
			array( '' === \WPAffiliateCaptain\Payouts\PayoutService::operator_missing(), __( 'Your address and VAT ID or tax number for credit notes', 'affiliate-captain' ), Admin::url( 'wpac-settings', array( 'tab' => 'payouts' ) ) ),
			array( '' !== trim( (string) Settings::get( 'operator_vat_id' ) ) || ! self::has_reverse_charge_partners(), __( 'Your VAT ID – needed for credit notes to business partners abroad (reverse charge); a tax number is not enough', 'affiliate-captain' ), Admin::url( 'wpac-settings', array( 'tab' => 'payouts' ) ) ),
			array( '' !== (string) Settings::get( 'debtor_iban' ) && '' !== trim( (string) Settings::get( 'debtor_name' ) ), __( 'Company bank account for SEPA payouts (account holder and IBAN)', 'affiliate-captain' ), Admin::url( 'wpac-settings', array( 'tab' => 'payouts' ) ) ),
			array( \WPAffiliateCaptain\Core\Crypto::has_dedicated_key(), __( 'Own encryption key WPAC_ENCRYPTION_KEY in wp-config.php', 'affiliate-captain' ), Admin::url( 'wpac-integrations' ) ),
			array( AffiliateRepository::count_by_status()['active'] > 0, __( 'First partner approved', 'affiliate-captain' ), Admin::url( 'wpac-affiliates' ) ),
			array( 'always' !== Settings::get( 'cookie_mode' ), __( 'Affiliate cookie only with consent (not "always")', 'affiliate-captain' ), Admin::url( 'wpac-settings', array( 'tab' => 'tracking' ) ) ),
		);
		$open = array_filter(
			$items,
			static function ( $i ) {
				return ! $i[0];
			}
		);
		if ( ! $open ) {
			return;
		}
		echo '<div class="wpac-checklist"><h2>' . esc_html__( 'Setup checklist', 'affiliate-captain' ) . '</h2><ul>';
		foreach ( $items as $i ) {
			echo '<li class="' . ( $i[0] ? 'done' : 'todo' ) . '">' . ( $i[0] ? esc_html( $i[1] ) : '<a href="' . esc_url( $i[2] ) . '">' . esc_html( $i[1] ) . '</a>' ) . '<span class="screen-reader-text"> – ' . esc_html( $i[0] ? __( 'done', 'affiliate-captain' ) : __( 'open', 'affiliate-captain' ) ) . '</span></li>';
		}
		echo '</ul>';
		if ( ! Settings::get( 'registration_page_id' ) || ! Settings::get( 'portal_page_id' ) ) {
			Admin::form_open( 'setup_pages' );
			echo '<p><button class="button button-primary">' . esc_html__( 'Create pages automatically', 'affiliate-captain' ) . '</button> <span class="description">' . esc_html__( 'Creates "Become a partner", "Partner area" and a landing page with the matching blocks.', 'affiliate-captain' ) . '</span></p></form>';
		}
		echo '</div>';
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
	 * Render.
	 */
	public static function render() {
		$currency = (string) Settings::get( 'currency' );
		// Local days (site time zone), like the report itself.
		$from     = self::date_or( Admin::get( 'from' ), wp_date( 'Y-m-d', time() - 29 * DAY_IN_SECONDS ) );
		$to       = self::date_or( Admin::get( 'to' ), wp_date( 'Y-m-d' ) );
		$counts   = AffiliateRepository::count_by_status();
		$rows     = Reports::cached( 'partners', $from, $to ); // Same report for KPIs and table, cached until the data changes.
		$view     = in_array( Admin::get( 'view' ), array( 'content', 'campaign' ), true ) ? Admin::get( 'view' ) : 'partners';
		$failed   = count( Jobs::query( 'failed' ) );

		$sum = array(
			'visits'      => 0,
			'orders'      => 0,
			'revenue'     => 0,
			'commission'  => 0,
			'corrections' => 0,
			'bonus'       => 0,
			'writeoffs'   => 0,
		);
		$unconverted = array_keys( Reports::$unconverted );
		foreach ( $rows as $r ) {
			foreach ( $sum as $k => $v ) {
				$sum[ $k ] += $r[ $k ];
			}
		}

		echo '<div class="wrap wpac-wrap"><h1>' . esc_html__( 'Affiliate Captain', 'affiliate-captain' ) . '</h1>';
		Admin::notice();
		if ( current_user_can( \WPAffiliateCaptain\Core\Capabilities::MANAGE_PROGRAM ) ) {
			self::checklist();
		}
		if ( $counts['pending'] ) {
			/* translators: %d: count */
			echo '<div class="notice notice-warning inline"><p><a href="' . esc_url( Admin::url( 'wpac-affiliates', array( 'status' => 'pending' ) ) ) . '">' . esc_html( sprintf( _n( '%d application waiting for review', '%d applications waiting for review', $counts['pending'], 'affiliate-captain' ), $counts['pending'] ) ) . '</a></p></div>';
		}
		if ( $failed ) {
			/* translators: %d: count */
			echo '<div class="notice notice-error inline"><p><a href="' . esc_url( Admin::url( 'wpac-integrations' ) ) . '">' . esc_html( sprintf( _n( '%d integration job failed', '%d integration jobs failed', $failed, 'affiliate-captain' ), $failed ) ) . '</a></p></div>';
		}

		if ( $unconverted ) {
			/* translators: %s: currencies */
			echo '<div class="notice notice-warning inline"><p>' . esc_html( sprintf( __( 'Amounts in %s are not included in the money columns (no reference exchange rate in the settings).', 'affiliate-captain' ), implode( ', ', $unconverted ) ) ) . '</p></div>';
		}
		echo '<form method="get" class="wpac-filters"><input type="hidden" name="page" value="wpac"><input type="hidden" name="view" value="' . esc_attr( $view ) . '"><label>' . esc_html__( 'from', 'affiliate-captain' ) . ' <input type="date" name="from" value="' . esc_attr( $from ) . '"></label> <label>' . esc_html__( 'to', 'affiliate-captain' ) . ' <input type="date" name="to" value="' . esc_attr( $to ) . '"></label> <button class="button">' . esc_html__( 'Apply', 'affiliate-captain' ) . '</button></form>';

		echo '<div class="wpac-kpis">';
		foreach (
			array(
				__( 'Active partners', 'affiliate-captain' )  => number_format_i18n( $counts['active'] ),
				__( 'Link visits', 'affiliate-captain' )      => number_format_i18n( $sum['visits'] ),
				__( 'Attributed orders', 'affiliate-captain' ) => number_format_i18n( $sum['orders'] ),
				__( 'Net revenue', 'affiliate-captain' )      => Money::format( $sum['revenue'], $currency ),
				__( 'Commission', 'affiliate-captain' )       => Money::format( $sum['commission'], $currency ),
				__( 'Corrections', 'affiliate-captain' )      => Money::format( $sum['corrections'], $currency ),
				__( 'Write-offs', 'affiliate-captain' )       => Money::format( $sum['writeoffs'], $currency ),
			) as $label => $value
		) {
			echo '<div class="wpac-kpi"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( $value ) . '</strong></div>';
		}
		echo '</div>';

		$tabs = array(
			'partners' => __( 'By partner', 'affiliate-captain' ),
			'content'  => __( 'By content', 'affiliate-captain' ),
			'campaign' => __( 'By campaign', 'affiliate-captain' ),
		);
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $k => $l ) {
			echo '<a class="nav-tab ' . ( $k === $view ? 'nav-tab-active' : '' ) . '" href="' . esc_url( Admin::url( 'wpac', array( 'view' => $k, 'from' => $from, 'to' => $to ) ) ) . '">' . esc_html( $l ) . '</a>';
		}
		echo '</nav><p>';
		Admin::form_open( 'report_export', 'wpac-inline' );
		echo '<input type="hidden" name="from" value="' . esc_attr( $from ) . '"><input type="hidden" name="to" value="' . esc_attr( $to ) . '"><input type="hidden" name="view" value="' . esc_attr( $view ) . '"><button class="button">' . esc_html__( 'Export CSV', 'affiliate-captain' ) . '</button></form></p>';

		if ( 'partners' !== $view ) {
			$dim = Reports::cached( 'content' === $view ? 'content_id' : 'campaign', $from, $to );
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html( 'content' === $view ? __( 'Content', 'affiliate-captain' ) : __( 'Campaign', 'affiliate-captain' ) ) . '</th><th>' . esc_html__( 'Visits', 'affiliate-captain' ) . '</th><th>' . esc_html__( 'Partners', 'affiliate-captain' ) . '</th><th>' . esc_html__( 'Orders', 'affiliate-captain' ) . '</th><th>' . esc_html__( 'Conversion', 'affiliate-captain' ) . '</th><th>' . esc_html__( 'Net revenue', 'affiliate-captain' ) . '</th><th>' . esc_html__( 'Commission', 'affiliate-captain' ) . '</th></tr></thead><tbody>';
			if ( ! $dim ) {
				echo '<tr><td colspan="7">' . esc_html__( 'No activity in this period.', 'affiliate-captain' ) . '</td></tr>';
			}
			foreach ( $dim as $r ) {
				$label = esc_html( $r['label'] );
				if ( 'content' === $view && (int) $r['key'] && get_edit_post_link( (int) $r['key'] ) ) {
					$label = '<a href="' . esc_url( get_edit_post_link( (int) $r['key'] ) ) . '">' . $label . '</a> <small>(' . esc_html( get_post_type( (int) $r['key'] ) ) . ')</small>';
				}
				printf(
					'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s %%</td><td>%s</td><td>%s</td></tr>',
					$label, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					esc_html( number_format_i18n( $r['visits'] ) ),
					esc_html( number_format_i18n( $r['partners'] ) ),
					esc_html( number_format_i18n( $r['orders'] ) ),
					esc_html( number_format_i18n( $r['conversion'], 1 ) ),
					esc_html( Money::format( $r['revenue'], $currency ) ),
					esc_html( Money::format( $r['commission'], $currency ) )
				);
			}
			echo '</tbody></table>';
			echo '<p class="description">' . esc_html__( 'Content = the post/page where the partner link landed. Orders are counted by the content/campaign of their attribution.', 'affiliate-captain' ) . '</p></div>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Partner', 'affiliate-captain' ) . '</th><th>' . esc_html__( 'Visits', 'affiliate-captain' ) . '</th><th>' . esc_html__( 'Orders', 'affiliate-captain' ) . '</th><th>' . esc_html__( 'Net revenue', 'affiliate-captain' ) . '</th><th>' . esc_html__( 'Commission', 'affiliate-captain' ) . '</th><th>' . esc_html__( 'Corrections', 'affiliate-captain' ) . '</th><th>' . esc_html__( 'Monthly bonuses', 'affiliate-captain' ) . '</th><th>' . esc_html__( 'Write-offs', 'affiliate-captain' ) . '</th><th>' . esc_html__( 'Refund rate', 'affiliate-captain' ) . '</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="9">' . esc_html__( 'No activity in this period.', 'affiliate-captain' ) . '</td></tr>';
		}
		foreach ( $rows as $r ) {
			printf(
				'<tr><td><a href="%s">%s</a></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s %%</td></tr>',
				esc_url( Admin::url( 'wpac-affiliates', array( 'action' => 'edit', 'id' => $r['affiliate_id'] ) ) ),
				esc_html( $r['name'] ),
				esc_html( number_format_i18n( $r['visits'] ) ),
				esc_html( number_format_i18n( $r['orders'] ) ),
				esc_html( Money::format( $r['revenue'], $currency ) ),
				esc_html( Money::format( $r['commission'], $currency ) ),
				esc_html( Money::format( $r['corrections'], $currency ) ),
				esc_html( Money::format( $r['bonus'], $currency ) ),
				esc_html( Money::format( $r['writeoffs'], $currency ) ),
				esc_html( number_format_i18n( $r['refund_rate'], 1 ) )
			);
		}
		echo '</tbody></table></div>';
	}
}
