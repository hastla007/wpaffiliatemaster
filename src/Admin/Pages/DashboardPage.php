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
	 *
	 * @return array [ titles of created pages, error lines ]
	 */
	private static function create_pages() {
		$pages = array(
			'registration_page_id' => array( __( 'Become a partner', 'affiliate-captain' ), '<!-- wp:wpac/registration /-->' ),
			'portal_page_id'       => array( __( 'Partner area', 'affiliate-captain' ), '<!-- wp:wpac/portal /-->' ),
			'landing_page_id'      => array( __( 'Recommended by our partner', 'affiliate-captain' ), "<!-- wp:wpac/partner-welcome /-->\n<!-- wp:paragraph --><p>" . esc_html__( 'Welcome! Here you can introduce your offer to visitors referred by a partner.', 'affiliate-captain' ) . '</p><!-- /wp:paragraph -->' ),
			// Draft only: the operator reviews and publishes it (R5-O44).
			'terms_page_id'        => array( __( 'Partner program terms', 'affiliate-captain' ), self::terms_template(), 'draft' ),
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
					'post_status'  => isset( $page[2] ) ? $page[2] : 'publish',
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
	 * Template of the partner program terms (blocks), with the self-billing agreement credit
	 * notes need. A starting point to adapt, not legal advice.
	 *
	 * @return string
	 */
	public static function terms_template() {
		$site  = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$parts = array(
			__( 'Template – please adapt it to your program and have it checked before publishing.', 'affiliate-captain' ),
			/* translators: %s: site name */
			sprintf( __( '1. Parties and subject: these terms govern the partner program of %s. Partners promote our offer with their personal links, codes and coupons and receive a commission for attributed sales.', 'affiliate-captain' ), $site ),
			__( '2. Commission: the rates shown in the partner area apply. Commissions are released after the hold period; refunded or cancelled orders reduce them.', 'affiliate-captain' ),
			__( '3. Payout: payouts are made once the minimum payout is reached, by bank transfer or another agreed payment method.', 'affiliate-captain' ),
			__( '4. Credit notes (self-billing): we settle commissions by credit note in the name and for the account of the partner (§14 (2) sentence 2 German VAT Act). The partner agrees to this procedure, keeps the tax details in the partner area up to date and may object to a credit note; it then loses its effect as an invoice.', 'affiliate-captain' ),
			__( '5. Advertising rules: partners label their posts as advertising, follow the promotion guidelines and do not use misleading claims, spam, brand bidding or cookie stuffing.', 'affiliate-captain' ),
			__( '6. Data protection: we process the partner data needed for the program and payouts as described in our privacy policy.', 'affiliate-captain' ),
			__( '7. Term and termination: either party can end the partnership at any time; commissions earned until then are settled as described in the partner area.', 'affiliate-captain' ),
		);
		$out = '';
		foreach ( $parts as $part ) {
			$out .= "<!-- wp:paragraph -->\n<p>" . esc_html( $part ) . "</p>\n<!-- /wp:paragraph -->\n";
		}
		return $out;
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
	 * Setup checklist items: [ done, label, URL, required ]. Optional items (recommended, not needed
	 * by every shop) never keep the checklist or the setup notice open (R5-O44).
	 *
	 * @return array
	 */
	public static function checklist_items() {
		$terms_page = (int) Settings::get( 'terms_page_id' );
		$self_bill  = (bool) Settings::get( 'self_billing' );
		$source     = class_exists( 'WooCommerce' );
		if ( ! $source && class_exists( '\WPAffiliateCaptain\Sales\ContractApi' ) ) {
			foreach ( (array) \WPAffiliateCaptain\Sales\ContractApi::integrations() as $info ) {
				if ( ! empty( $info['active'] ) ) {
					$source = true;
					break;
				}
			}
		}
		return array(
			array( $source, __( 'A sales source: WooCommerce or another shop integration is active', 'affiliate-captain' ), Admin::url( 'wpac-integrations' ), true ),
			array( (bool) Settings::get( 'registration_page_id' ) && (bool) Settings::get( 'portal_page_id' ), __( 'Application page and partner area page', 'affiliate-captain' ), Admin::url( 'wpac-settings', array( 'tab' => 'program' ) ), true ),
			array( $terms_page && 'publish' === get_post_status( $terms_page ), __( 'Partner program terms: published page and version (a draft from the template can be created below)', 'affiliate-captain' ), $terms_page && get_edit_post_link( $terms_page ) ? (string) get_edit_post_link( $terms_page, 'url' ) : Admin::url( 'wpac-settings', array( 'tab' => 'program' ) ), true ),
			array( ! $self_bill || '' === \WPAffiliateCaptain\Payouts\PayoutService::operator_missing(), __( 'Your address and VAT ID or tax number for credit notes', 'affiliate-captain' ), Admin::url( 'wpac-settings', array( 'tab' => 'payouts' ) ), $self_bill ),
			array( '' !== trim( (string) Settings::get( 'operator_vat_id' ) ) || ! self::has_reverse_charge_partners(), __( 'Your VAT ID – needed for credit notes to business partners abroad (reverse charge); a tax number is not enough', 'affiliate-captain' ), Admin::url( 'wpac-settings', array( 'tab' => 'payouts' ) ), true ),
			array( 'always' !== Settings::get( 'cookie_mode' ), __( 'Affiliate cookie only with consent (not "always")', 'affiliate-captain' ), Admin::url( 'wpac-settings', array( 'tab' => 'tracking' ) ), true ),
			array( AffiliateRepository::count_by_status()['active'] > 0, __( 'First partner approved', 'affiliate-captain' ), Admin::url( 'wpac-affiliates' ), true ),
			array( '' !== (string) Settings::get( 'debtor_iban' ) && '' !== trim( (string) Settings::get( 'debtor_name' ) ), __( 'Optional: company bank account for SEPA files (account holder and IBAN) – not needed if you pay by hand or by PayPal', 'affiliate-captain' ), Admin::url( 'wpac-settings', array( 'tab' => 'payouts' ) ), false ),
			array( \WPAffiliateCaptain\Core\Crypto::has_dedicated_key(), __( 'Recommended: own encryption key WPAC_ENCRYPTION_KEY in wp-config.php (bank details then survive a change of the WordPress salts)', 'affiliate-captain' ), Admin::url( 'wpac-integrations', array( 'tab' => 'status' ) ), false ),
		);
	}

	/**
	 * Whether a required checklist item is still open.
	 *
	 * @return bool
	 */
	public static function setup_open() {
		foreach ( self::checklist_items() as $i ) {
			if ( ! $i[0] && $i[3] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Setup checklist (shown until every required item is done).
	 */
	private static function checklist() {
		$items = self::checklist_items();
		if ( ! self::setup_open() ) {
			return;
		}
		echo '<div class="wpac-checklist"><h2>' . esc_html__( 'Setup checklist', 'affiliate-captain' ) . '</h2><ul>';
		foreach ( $items as $i ) {
			$class = $i[0] ? 'done' : ( $i[3] ? 'todo' : 'optional' );
			$state = $i[0] ? __( 'done', 'affiliate-captain' ) : ( $i[3] ? __( 'open', 'affiliate-captain' ) : __( 'optional', 'affiliate-captain' ) );
			echo '<li class="' . esc_attr( $class ) . '">' . ( $i[0] ? esc_html( $i[1] ) : '<a href="' . esc_url( $i[2] ) . '">' . esc_html( $i[1] ) . '</a>' ) . '<span class="screen-reader-text"> – ' . esc_html( $state ) . '</span></li>';
		}
		echo '</ul>';
		$terms_page = (int) Settings::get( 'terms_page_id' );
		if ( ! Settings::get( 'registration_page_id' ) || ! Settings::get( 'portal_page_id' ) || ! $terms_page || ! get_post( $terms_page ) ) {
			Admin::form_open( 'setup_pages' );
			echo '<p><button class="button button-primary">' . esc_html__( 'Create pages automatically', 'affiliate-captain' ) . '</button> <span class="description">' . esc_html__( 'Creates "Become a partner", "Partner area" and a landing page with the matching blocks, and a draft of the partner terms from a template (review it with your legal advisor, then publish it).', 'affiliate-captain' ) . '</span></p></form>';
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
		echo '<form method="get" class="wpac-filters"><input type="hidden" name="page" value="wpac"><input type="hidden" name="view" value="' . esc_attr( $view ) . '"><label>' . esc_html_x( 'From', 'date range start', 'affiliate-captain' ) . ' <input type="date" name="from" value="' . esc_attr( $from ) . '"></label> <label>' . esc_html_x( 'To', 'date range end', 'affiliate-captain' ) . ' <input type="date" name="to" value="' . esc_attr( $to ) . '"></label> <button class="button">' . esc_html__( 'Apply', 'affiliate-captain' ) . '</button></form>';

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
		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Report views', 'affiliate-captain' ) . '">';
		foreach ( $tabs as $k => $l ) {
			echo '<a class="nav-tab ' . ( $k === $view ? 'nav-tab-active' : '' ) . '" href="' . esc_url( Admin::url( 'wpac', array( 'view' => $k, 'from' => $from, 'to' => $to ) ) ) . '"' . ( $k === $view ? ' aria-current="page"' : '' ) . '>' . esc_html( $l ) . '</a>';
		}
		echo '</nav><p>';
		Admin::form_open( 'report_export', 'wpac-inline' );
		echo '<input type="hidden" name="from" value="' . esc_attr( $from ) . '"><input type="hidden" name="to" value="' . esc_attr( $to ) . '"><input type="hidden" name="view" value="' . esc_attr( $view ) . '"><button class="button">' . esc_html__( 'Export CSV', 'affiliate-captain' ) . '</button></form></p>';

		if ( 'partners' !== $view ) {
			$dim = Reports::by_dimension( 'content' === $view ? 'content_id' : 'campaign', $from, $to );
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
