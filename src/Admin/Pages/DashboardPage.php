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
			self::create_pages();
			Admin::back( __( 'Pages created and selected in the settings.', 'wp-affiliate-captain' ) );
		}
		if ( 'report_export' !== $do ) {
			return;
		}
		$from     = self::date_or( Admin::post( 'from' ), gmdate( 'Y-m-01' ) );
		$to       = self::date_or( Admin::post( 'to' ), gmdate( 'Y-m-d' ) );
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
	 */
	private static function create_pages() {
		$pages = array(
			'registration_page_id' => array( __( 'Become a partner', 'wp-affiliate-captain' ), '<!-- wp:wpac/registration /-->' ),
			'portal_page_id'       => array( __( 'Partner area', 'wp-affiliate-captain' ), '<!-- wp:wpac/portal /-->' ),
			'landing_page_id'      => array( __( 'Recommended by our partner', 'wp-affiliate-captain' ), "<!-- wp:wpac/partner-welcome /-->\n<!-- wp:paragraph --><p>" . esc_html__( 'Welcome! Here you can introduce your offer to visitors referred by a partner.', 'wp-affiliate-captain' ) . '</p><!-- /wp:paragraph -->' ),
		);
		$update = array();
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
				)
			);
			if ( $id && ! is_wp_error( $id ) ) {
				$update[ $key ] = (int) $id;
			}
		}
		if ( $update ) {
			Settings::update( $update );
			\WPAffiliateCaptain\Core\Audit::log( 'setup.pages_created', 'settings', 0, $update );
		}
	}

	/**
	 * Setup checklist (shown until everything is done).
	 */
	private static function checklist() {
		$items = array(
			array( (bool) Settings::get( 'registration_page_id' ) && (bool) Settings::get( 'portal_page_id' ), __( 'Application page and partner area page', 'wp-affiliate-captain' ), Admin::url( 'wpac-settings', array( 'tab' => 'program' ) ) ),
			array( (bool) Settings::get( 'terms_page_id' ), __( 'Partner program terms (page and version)', 'wp-affiliate-captain' ), Admin::url( 'wpac-settings', array( 'tab' => 'program' ) ) ),
			array( '' !== (string) Settings::get( 'operator_address' ) && ( '' !== (string) Settings::get( 'operator_vat_id' ) || '' !== (string) Settings::get( 'operator_tax_number' ) ), __( 'Your address and VAT ID for credit notes', 'wp-affiliate-captain' ), Admin::url( 'wpac-settings', array( 'tab' => 'payouts' ) ) ),
			array( '' !== (string) Settings::get( 'debtor_iban' ), __( 'Company bank account for SEPA payouts', 'wp-affiliate-captain' ), Admin::url( 'wpac-settings', array( 'tab' => 'payouts' ) ) ),
			array( \WPAffiliateCaptain\Core\Crypto::has_dedicated_key(), __( 'Own encryption key WPAC_ENCRYPTION_KEY in wp-config.php', 'wp-affiliate-captain' ), Admin::url( 'wpac-integrations' ) ),
			array( AffiliateRepository::count_by_status()['active'] > 0, __( 'First partner approved', 'wp-affiliate-captain' ), Admin::url( 'wpac-affiliates' ) ),
			array( 'always' !== Settings::get( 'cookie_mode' ), __( 'Affiliate cookie only with consent (not "always")', 'wp-affiliate-captain' ), Admin::url( 'wpac-settings', array( 'tab' => 'tracking' ) ) ),
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
		echo '<div class="wpac-checklist"><h2>' . esc_html__( 'Setup checklist', 'wp-affiliate-captain' ) . '</h2><ul>';
		foreach ( $items as $i ) {
			echo '<li class="' . ( $i[0] ? 'done' : 'todo' ) . '">' . ( $i[0] ? esc_html( $i[1] ) : '<a href="' . esc_url( $i[2] ) . '">' . esc_html( $i[1] ) . '</a>' ) . '<span class="screen-reader-text"> – ' . esc_html( $i[0] ? __( 'done', 'wp-affiliate-captain' ) : __( 'open', 'wp-affiliate-captain' ) ) . '</span></li>';
		}
		echo '</ul>';
		if ( ! Settings::get( 'registration_page_id' ) || ! Settings::get( 'portal_page_id' ) ) {
			Admin::form_open( 'setup_pages' );
			echo '<p><button class="button button-primary">' . esc_html__( 'Create pages automatically', 'wp-affiliate-captain' ) . '</button> <span class="description">' . esc_html__( 'Creates "Become a partner", "Partner area" and a landing page with the matching blocks.', 'wp-affiliate-captain' ) . '</span></p></form>';
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
		$from     = self::date_or( Admin::get( 'from' ), gmdate( 'Y-m-d', time() - 29 * DAY_IN_SECONDS ) );
		$to       = self::date_or( Admin::get( 'to' ), gmdate( 'Y-m-d' ) );
		$counts   = AffiliateRepository::count_by_status();
		$rows     = Reports::partners( $from, $to );
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

		echo '<div class="wrap wpac-wrap"><h1>' . esc_html__( 'Affiliate Captain', 'wp-affiliate-captain' ) . '</h1>';
		Admin::notice();
		if ( current_user_can( \WPAffiliateCaptain\Core\Capabilities::MANAGE_PROGRAM ) ) {
			self::checklist();
		}
		if ( $counts['pending'] ) {
			/* translators: %d: count */
			echo '<div class="notice notice-warning inline"><p><a href="' . esc_url( Admin::url( 'wpac-affiliates', array( 'status' => 'pending' ) ) ) . '">' . esc_html( sprintf( _n( '%d application waiting for review', '%d applications waiting for review', $counts['pending'], 'wp-affiliate-captain' ), $counts['pending'] ) ) . '</a></p></div>';
		}
		if ( $failed ) {
			/* translators: %d: count */
			echo '<div class="notice notice-error inline"><p><a href="' . esc_url( Admin::url( 'wpac-integrations' ) ) . '">' . esc_html( sprintf( _n( '%d integration job failed', '%d integration jobs failed', $failed, 'wp-affiliate-captain' ), $failed ) ) . '</a></p></div>';
		}

		if ( $unconverted ) {
			/* translators: %s: currencies */
			echo '<div class="notice notice-warning inline"><p>' . esc_html( sprintf( __( 'Amounts in %s are not included in the money columns (no reference exchange rate in the settings).', 'wp-affiliate-captain' ), implode( ', ', $unconverted ) ) ) . '</p></div>';
		}
		echo '<form method="get" class="wpac-filters"><input type="hidden" name="page" value="wpac"><input type="hidden" name="view" value="' . esc_attr( $view ) . '"><label>' . esc_html__( 'from', 'wp-affiliate-captain' ) . ' <input type="date" name="from" value="' . esc_attr( $from ) . '"></label> <label>' . esc_html__( 'to', 'wp-affiliate-captain' ) . ' <input type="date" name="to" value="' . esc_attr( $to ) . '"></label> <button class="button">' . esc_html__( 'Apply', 'wp-affiliate-captain' ) . '</button></form>';

		echo '<div class="wpac-kpis">';
		foreach (
			array(
				__( 'Active partners', 'wp-affiliate-captain' )  => number_format_i18n( $counts['active'] ),
				__( 'Link visits', 'wp-affiliate-captain' )      => number_format_i18n( $sum['visits'] ),
				__( 'Attributed orders', 'wp-affiliate-captain' ) => number_format_i18n( $sum['orders'] ),
				__( 'Net revenue', 'wp-affiliate-captain' )      => Money::format( $sum['revenue'], $currency ),
				__( 'Commission', 'wp-affiliate-captain' )       => Money::format( $sum['commission'], $currency ),
				__( 'Corrections', 'wp-affiliate-captain' )      => Money::format( $sum['corrections'], $currency ),
				__( 'Write-offs', 'wp-affiliate-captain' )       => Money::format( $sum['writeoffs'], $currency ),
			) as $label => $value
		) {
			echo '<div class="wpac-kpi"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( $value ) . '</strong></div>';
		}
		echo '</div>';

		$tabs = array(
			'partners' => __( 'By partner', 'wp-affiliate-captain' ),
			'content'  => __( 'By content', 'wp-affiliate-captain' ),
			'campaign' => __( 'By campaign', 'wp-affiliate-captain' ),
		);
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $k => $l ) {
			echo '<a class="nav-tab ' . ( $k === $view ? 'nav-tab-active' : '' ) . '" href="' . esc_url( Admin::url( 'wpac', array( 'view' => $k, 'from' => $from, 'to' => $to ) ) ) . '">' . esc_html( $l ) . '</a>';
		}
		echo '</nav><p>';
		Admin::form_open( 'report_export', 'wpac-inline' );
		echo '<input type="hidden" name="from" value="' . esc_attr( $from ) . '"><input type="hidden" name="to" value="' . esc_attr( $to ) . '"><input type="hidden" name="view" value="' . esc_attr( $view ) . '"><button class="button">' . esc_html__( 'Export CSV', 'wp-affiliate-captain' ) . '</button></form></p>';

		if ( 'partners' !== $view ) {
			$dim = Reports::by_dimension( 'content' === $view ? 'content_id' : 'campaign', $from, $to );
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html( 'content' === $view ? __( 'Content', 'wp-affiliate-captain' ) : __( 'Campaign', 'wp-affiliate-captain' ) ) . '</th><th>' . esc_html__( 'Visits', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Partners', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Orders', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Conversion', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Net revenue', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Commission', 'wp-affiliate-captain' ) . '</th></tr></thead><tbody>';
			if ( ! $dim ) {
				echo '<tr><td colspan="7">' . esc_html__( 'No activity in this period.', 'wp-affiliate-captain' ) . '</td></tr>';
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
			echo '<p class="description">' . esc_html__( 'Content = the post/page where the partner link landed. Orders are counted by the content/campaign of their attribution.', 'wp-affiliate-captain' ) . '</p></div>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Partner', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Visits', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Orders', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Net revenue', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Commission', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Corrections', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Monthly bonuses', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Write-offs', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Refund rate', 'wp-affiliate-captain' ) . '</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="9">' . esc_html__( 'No activity in this period.', 'wp-affiliate-captain' ) . '</td></tr>';
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
