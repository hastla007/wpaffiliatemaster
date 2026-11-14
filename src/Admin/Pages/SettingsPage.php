<?php
/**
 * Admin: settings.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Admin\Pages;

use WPAffiliateCaptain\Admin\Admin;
use WPAffiliateCaptain\Core\Audit;
use WPAffiliateCaptain\Core\Money;
use WPAffiliateCaptain\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class SettingsPage {

	/**
	 * Save.
	 *
	 * @param string $do Action.
	 */
	public static function handle( $do ) {
		if ( 'ropa_download' === $do ) {
			nocache_headers();
			header( 'Content-Type: text/markdown; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="verarbeitungsverzeichnis-affiliate.md"' );
			echo \WPAffiliateCaptain\Privacy\Privacy::ropa(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markdown download, not HTML.
			exit;
		}
		if ( 'settings_save' !== $do ) {
			return;
		}
		$raw  = (array) Admin::post( 'wpac', array() );
		$tabs = self::tab_keys();
		$tab  = self::tab_alias( sanitize_key( Admin::post( 'tab' ) ) );
		$tab  = isset( $tabs[ $tab ] ) ? $tab : 'program';
		// Only the fields of the submitted tab are changed (unchecked checkboxes are absent from POST).
		// Rejected fields keep their stored value (sanitize() returns it) and are reported.
		$errors  = array();
		$notices = array();
		$clean   = Settings::sanitize( $raw, $errors, $notices );
		$keys    = $tabs[ $tab ];
		if ( isset( $clean['currency'] ) && in_array( 'currency', $keys, true ) && (string) $clean['currency'] !== (string) Settings::get( 'currency' ) ) {
			// A new program currency also converts the amounts of other tabs (R5-O2, R7-AD18).
			$keys[]              = 'min_payout_minor';
			$keys[]              = 'fraud_max_order_minor';
			$notices['currency'] = ( isset( $notices['currency'] ) ? $notices['currency'] . ' ' : '' ) . self::currency_leftovers();
		}
		$new     = array_intersect_key( $clean, array_flip( $keys ) );
		$old     = Settings::all();
		$url     = Admin::url( 'wpac-settings', array( 'tab' => $tab ) );
		if ( ! Settings::update( $new ) ) {
			Audit::log( 'settings.save_failed', 'settings', 0, array( 'tab' => $tab ) );
			Admin::back( __( 'The settings could not be saved (database error). Nothing was changed; please try again.', 'affiliate-captain' ), 'error', $url );
		}
		$changed = array();
		foreach ( $new as $k => $v ) {
			if ( ! isset( $old[ $k ] ) || (string) $old[ $k ] !== (string) $v ) {
				$changed[] = $k;
			}
		}
		if ( $changed ) {
			Audit::log( 'settings.updated', 'settings', 0, array( 'changed' => $changed ) );
		}
		$labels = self::field_labels();
		if ( $errors ) {
			// Show the rejected input again, with the message next to the field.
			$values = array();
			foreach ( array_keys( $errors ) as $field ) {
				$values[ $field ] = isset( $raw[ $field ] ) && is_scalar( $raw[ $field ] ) ? wp_check_invalid_utf8( (string) $raw[ $field ] ) : '';
			}
			Admin::keep_form(
				'settings',
				array(
					'values' => $values,
					'errors' => $errors,
				)
			);
			$lines = array();
			foreach ( $errors as $field => $message ) {
				$lines[] = ( isset( $labels[ $field ] ) ? $labels[ $field ] : $field ) . ': ' . $message;
			}
			Admin::back(
				sprintf(
					/* translators: %d: number of fields */
					_n( '%d field was not saved because its value is not valid – it keeps its previous value. All other changes were saved.', '%d fields were not saved because their values are not valid – they keep their previous values. All other changes were saved.', count( $errors ), 'affiliate-captain' ),
					count( $errors )
				),
				'error',
				$url,
				$lines
			);
		}
		if ( $notices ) {
			$lines = array();
			foreach ( $notices as $field => $message ) {
				$lines[] = ( isset( $labels[ $field ] ) ? $labels[ $field ] : $field ) . ': ' . $message;
			}
			Admin::back( __( 'Settings saved with an adjustment:', 'affiliate-captain' ), 'warning', $url, $lines );
		}
		Admin::back( __( 'Settings saved.', 'affiliate-captain' ), 'success', $url );
	}

	/**
	 * What a currency change does not convert: fixed-amount rules and bonus tiers by revenue.
	 *
	 * @return string
	 */
	private static function currency_leftovers() {
		global $wpdb;
		$rules_t = \WPAffiliateCaptain\Core\Db::table( 'rules' );
		$tiers_t = \WPAffiliateCaptain\Core\Db::table( 'tiers' );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table names.
		$rules = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$rules_t} WHERE rate_type = 'fixed' AND active = 1" );
		$tiers = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tiers_t} WHERE basis = 'revenue' AND active = 1" );
		// phpcs:enable
		if ( ! $rules && ! $tiers ) {
			return '';
		}
		/* translators: 1: number of rules, 2: number of tier plans */
		return sprintf( __( 'Not converted: %1$d active fixed-amount rules and %2$d bonus tier plans with revenue thresholds – please adjust them under Rules & groups.', 'affiliate-captain' ), $rules, $tiers );
	}

	/**
	 * Labels of the validated fields (form field name => label) for error messages.
	 *
	 * @return array
	 */
	private static function field_labels() {
		return array(
			'referral_param'             => __( 'Link parameter', 'affiliate-captain' ),
			'lifetime_rate'              => __( 'Lifetime rate in % (empty = normal rules)', 'affiliate-captain' ),
			'audit_retention_days'       => __( 'Keep audit log (days, 0 = forever)', 'affiliate-captain' ),
			'application_retention_days' => __( 'Keep rejected applications (days, 0 = forever)', 'affiliate-captain' ),
			'fraud_max_orders_day'       => __( 'Max. orders per partner in 24 hours', 'affiliate-captain' ),
			'fraud_min_click_seconds'    => __( 'Min. seconds between click and order', 'affiliate-captain' ),
			'fraud_max_refund_rate'      => __( 'Max. refund rate of a partner in % (90 days, min. 5 orders)', 'affiliate-captain' ),
			'statement_retention_years'  => __( 'Keep statements (years, 0 = forever)', 'affiliate-captain' ),
			'lifetime_days'              => __( 'Lifetime link duration (days, 0 = unlimited)', 'affiliate-captain' ),
			'cookie_days'                => __( 'Cookie lifetime (days)', 'affiliate-captain' ),
			'cookie_domain'              => __( 'Cookie domain', 'affiliate-captain' ),
			'visit_log_percent'          => __( 'Record link visits (%)', 'affiliate-captain' ),
			'rate_limit_per_minute'      => __( 'Max. counted link visits per client and minute', 'affiliate-captain' ),
			'visit_retention_days'       => __( 'Keep visit data (days)', 'affiliate-captain' ),
			'hold_days'                  => __( 'Hold period (days after payment)', 'affiliate-captain' ),
			'renewal_limit_months'       => __( 'Renewal commission for (months, 0 = unlimited)', 'affiliate-captain' ),
			'operator_country'           => __( 'Your country', 'affiliate-captain' ),
			'currency'                   => __( 'Program currency', 'affiliate-captain' ),
			'exchange_rates'             => __( 'Reference exchange rates', 'affiliate-captain' ),
			'default_rate'               => __( 'Default rate', 'affiliate-captain' ),
			'trusted_proxies'            => __( 'Trusted proxies / CDN', 'affiliate-captain' ),
			'fraud_max_order'            => __( 'Review orders above (net)', 'affiliate-captain' ),
			'min_payout'                 => __( 'Minimum payout', 'affiliate-captain' ),
			'landing_prefix'             => __( 'Landing page path', 'affiliate-captain' ),
			'default_disclosure'         => __( 'Default disclosure note', 'affiliate-captain' ),
			'terms_version'              => __( 'Terms version', 'affiliate-captain' ),
			'admin_email'                => __( 'Notification e-mail', 'affiliate-captain' ),
			'mail_from_email'            => __( 'Sender address', 'affiliate-captain' ),
			'monitor_token'              => __( 'Monitoring token', 'affiliate-captain' ),
			'vat_rate'                   => __( 'VAT rate in %', 'affiliate-captain' ),
			'credit_prefix'              => __( 'Prefix credit note numbers', 'affiliate-captain' ),
			'storno_prefix'              => __( 'Prefix cancellation numbers', 'affiliate-captain' ),
			'debtor_bic'                 => __( 'Company BIC (optional)', 'affiliate-captain' ),
			'debtor_iban'                => __( 'Company IBAN (SEPA)', 'affiliate-captain' ),
		);
	}

	/**
	 * Names of all setting keys (audit log "settings changed", messages).
	 *
	 * @return array key => label
	 */
	public static function setting_labels() {
		return array_merge(
			self::field_labels(),
			array(
				'registration_page_id'  => __( 'Application page', 'affiliate-captain' ),
				'portal_page_id'        => __( 'Partner area page', 'affiliate-captain' ),
				'terms_page_id'         => __( 'Partner program terms', 'affiliate-captain' ),
				'auto_approve'          => __( 'Approve applications automatically', 'affiliate-captain' ),
				'terminated_open'       => __( 'Open commissions on termination', 'affiliate-captain' ),
				'mail_from_name'        => __( 'Sender name', 'affiliate-captain' ),
				'notify_payout_paid'    => __( 'E-mail to the partner when a payout was paid', 'affiliate-captain' ),
				'notify_commissions'    => __( 'E-mail to partners about new, approved and rejected commissions', 'affiliate-captain' ),
				'notify_admin_review'   => __( 'E-mail to the operator when commissions need review', 'affiliate-captain' ),
				'checkout_field'        => __( 'Show partner code field at checkout (classic + blocks)', 'affiliate-captain' ),
				'use_wc_session'        => __( 'Keep the code from a partner link in the WooCommerce shop session', 'affiliate-captain' ),
				'cookie_mode'           => __( 'Affiliate cookie', 'affiliate-captain' ),
				'touch_model'           => __( 'Link attribution', 'affiliate-captain' ),
				'coupon_priority'       => __( 'Affiliate coupon has priority over partner links', 'affiliate-captain' ),
				'self_referral'         => __( 'Self-referrals', 'affiliate-captain' ),
				'js_tracking'           => __( 'Also record partner links via JavaScript (for full-page caches/CDNs)', 'affiliate-captain' ),
				'carry_referral_links'  => __( 'Keep the referral of a partner link on the next pages until consent (link parameter, no storage)', 'affiliate-captain' ),
				'fraud_max_order_minor' => __( 'Review orders above (net)', 'affiliate-captain' ),
				'fraud_check_domains'   => __( 'Review orders with disposable or blocked e-mail domains', 'affiliate-captain' ),
				'fraud_blocked_domains' => __( 'Additional blocked domains', 'affiliate-captain' ),
				'default_rate_type'     => __( 'Default commission type', 'affiliate-captain' ),
				'lifetime_enabled'      => __( 'Lifetime commissions: repeat purchases of referred customers go to the same partner', 'affiliate-captain' ),
				'hold_start'            => __( 'Hold period starts', 'affiliate-captain' ),
				'renewal_commission'    => __( 'Commission on subscription renewals (WooCommerce Subscriptions)', 'affiliate-captain' ),
				'rule_precedence'       => __( 'Rule precedence', 'affiliate-captain' ),
				'landing_page_id'       => __( 'Default landing page', 'affiliate-captain' ),
				'promotion_guidelines'  => __( 'Promotion guidelines for partners', 'affiliate-captain' ),
				'min_payout_minor'      => __( 'Minimum payout', 'affiliate-captain' ),
				'payout_requests'       => __( 'Partners can request payouts in the partner area', 'affiliate-captain' ),
				'payout_method_manual'  => __( 'Partners may choose another payment method (e.g. PayPal, Wise), paid manually', 'affiliate-captain' ),
				'retention_hold'        => __( 'Tax audit or legal hold: do not delete any statements', 'affiliate-captain' ),
				'self_billing'          => __( 'Issue statements as credit notes (self-billing, Gutschrift §14 Abs. 2 UStG)', 'affiliate-captain' ),
				'operator_address'      => __( 'Your address (for credit notes)', 'affiliate-captain' ),
				'operator_vat_id'       => __( 'Your VAT ID', 'affiliate-captain' ),
				'vies_check'            => __( 'VIES check: verify partner VAT IDs with the EU Commission', 'affiliate-captain' ),
				'operator_tax_number'   => __( 'Your tax number (if no VAT ID)', 'affiliate-captain' ),
				'debtor_name'           => __( 'Company account holder (SEPA)', 'affiliate-captain' ),
				'remittance_prefix'     => __( 'Remittance text prefix', 'affiliate-captain' ),
				'report_admin'          => __( 'Monthly summary e-mail to the operator', 'affiliate-captain' ),
				'report_partners'       => __( 'Monthly summary e-mail to active partners', 'affiliate-captain' ),
				'maintenance_mode'      => __( 'Maintenance mode', 'affiliate-captain' ),
				'delete_on_uninstall'   => __( 'Delete all data when the plugin is deleted', 'affiliate-captain' ),
			)
		);
	}

	/** @var array Rejected input of the last save: values and errors per form field. */
	private static $kept = array(
		'values' => array(),
		'errors' => array(),
	);

	/**
	 * Value to show in a field: the rejected input after a failed save, else the stored value.
	 *
	 * @param string $field Form field name.
	 * @param mixed  $value Stored value.
	 * @return string
	 */
	private static function shown( $field, $value ) {
		return isset( self::$kept['errors'][ $field ], self::$kept['values'][ $field ] ) ? (string) self::$kept['values'][ $field ] : (string) $value;
	}

	/**
	 * Attributes and message for a field with an error.
	 *
	 * @param string $field Form field name.
	 * @return array [ attributes, message HTML ]
	 */
	private static function error_parts( $field ) {
		if ( ! isset( self::$kept['errors'][ $field ] ) ) {
			return array( '', '' );
		}
		return array(
			' aria-invalid="true" aria-describedby="wpac-err-' . esc_attr( $field ) . '"',
			'<p class="wpac-field-error" id="wpac-err-' . esc_attr( $field ) . '" role="alert">' . esc_html( self::$kept['errors'][ $field ] ) . '</p>',
		);
	}

	/**
	 * Setting keys per tab.
	 *
	 * @return array
	 */
	public static function tab_keys() {
		return array(
			'program'     => array( 'registration_page_id', 'portal_page_id', 'terms_page_id', 'terms_version', 'auto_approve', 'terminated_open' ),
			'emails'      => array( 'admin_email', 'mail_from_name', 'mail_from_email', 'notify_payout_paid', 'notify_commissions', 'notify_admin_review', 'report_admin', 'report_partners' ),
			'tracking'    => array( 'referral_param', 'checkout_field', 'use_wc_session', 'cookie_mode', 'carry_referral_links', 'cookie_days', 'cookie_domain', 'touch_model', 'coupon_priority', 'self_referral', 'js_tracking', 'visit_log_percent' ),
			'security'    => array( 'fraud_max_orders_day', 'fraud_max_order_minor', 'fraud_min_click_seconds', 'fraud_max_refund_rate', 'fraud_check_domains', 'fraud_blocked_domains', 'rate_limit_per_minute', 'trusted_proxies' ),
			'commissions' => array( 'currency', 'exchange_rates', 'default_rate_type', 'default_rate', 'lifetime_enabled', 'lifetime_days', 'lifetime_rate', 'hold_days', 'hold_start', 'renewal_commission', 'renewal_limit_months', 'rule_precedence' ),
			'promotion'   => array( 'landing_prefix', 'landing_page_id', 'default_disclosure', 'promotion_guidelines' ),
			'payouts'     => array( 'min_payout_minor', 'payout_requests', 'payout_method_manual', 'self_billing', 'vat_rate', 'operator_country', 'operator_address', 'operator_vat_id', 'vies_check', 'operator_tax_number', 'credit_prefix', 'storno_prefix', 'debtor_name', 'debtor_iban', 'debtor_bic', 'remittance_prefix' ),
			// All retention periods, maintenance and uninstall in one place (R5-O45).
			'data'        => array( 'visit_retention_days', 'application_retention_days', 'audit_retention_days', 'statement_retention_years', 'retention_hold', 'maintenance_mode', 'monitor_token', 'delete_on_uninstall' ),
		);
	}

	/**
	 * Tab key of older links ("reports" held maintenance and retention before the regrouping).
	 *
	 * @param string $tab Requested tab.
	 * @return string
	 */
	public static function tab_alias( $tab ) {
		return 'reports' === $tab ? 'data' : (string) $tab;
	}

	/**
	 * Program currency: a list of the currencies WooCommerce knows (text field without WooCommerce).
	 *
	 * @param array $s Settings.
	 */
	private static function currency_field( $s ) {
		$desc = __( 'Changing it converts the minimum payout, the review limit and a fixed default rate (with an exchange rate between the two currencies if one is entered, otherwise keeping the value); fixed rules and bonus tiers must be checked by you.', 'affiliate-captain' );
		if ( ! function_exists( 'get_woocommerce_currencies' ) ) {
			self::text( 'currency', __( 'Program currency', 'affiliate-captain' ), $s, $desc );
			return;
		}
		$options = array();
		foreach ( (array) get_woocommerce_currencies() as $code => $name ) {
			/* translators: 1: currency name, 2: ISO code */
			$options[ (string) $code ] = sprintf( __( '%1$s (%2$s)', 'affiliate-captain' ), html_entity_decode( (string) $name, ENT_QUOTES, 'UTF-8' ), $code );
		}
		$current = strtoupper( (string) $s['currency'] );
		if ( '' !== $current && ! isset( $options[ $current ] ) ) {
			$options[ $current ] = $current;
		}
		self::select( 'currency', __( 'Program currency', 'affiliate-captain' ), $s, $options );
		self::desc_row( $desc );
	}

	/**
	 * Description row below a field.
	 *
	 * @param string $desc Text.
	 */
	private static function desc_row( $desc ) {
		echo '<tr><td></td><td><p class="description">' . esc_html( $desc ) . '</p></td></tr>';
	}

	/** @var string Active tab while rendering. */
	private static $tab = 'program';

	/** @var string Tab of the section being rendered. */
	private static $section_tab = '';

	/** @var string Title of the section being rendered. */
	private static $section_title = '';

	/**
	 * Render.
	 */
	public static function render() {
		$labels = array(
			'program'     => __( 'Program', 'affiliate-captain' ),
			'emails'      => __( 'E-mails & reports', 'affiliate-captain' ),
			'tracking'    => __( 'Tracking', 'affiliate-captain' ),
			'security'    => __( 'Fraud & abuse', 'affiliate-captain' ),
			'commissions' => __( 'Commissions', 'affiliate-captain' ),
			'promotion'   => __( 'Promotion', 'affiliate-captain' ),
			'payouts'     => __( 'Payouts & credit notes', 'affiliate-captain' ),
			'data'        => __( 'Data & maintenance', 'affiliate-captain' ),
		);
		$tab       = self::tab_alias( Admin::get( 'tab' ) );
		self::$tab = isset( $labels[ $tab ] ) ? $tab : 'program';
		$s        = Settings::all();
		$currency = $s['currency'];
		$kept     = Admin::kept_form( 'settings' );
		if ( isset( $kept['values'], $kept['errors'] ) ) {
			self::$kept = $kept;
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Affiliate Captain settings', 'affiliate-captain' ) . '</h1>';
		Admin::notice();
		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Settings sections', 'affiliate-captain' ) . '">';
		foreach ( $labels as $key => $label ) {
			printf( '<a class="nav-tab %s" href="%s"%s>%s</a>', $key === self::$tab ? 'nav-tab-active' : '', esc_url( Admin::url( 'wpac-settings', array( 'tab' => $key ) ) ), $key === self::$tab ? ' aria-current="page"' : '', esc_html( $label ) );
		}
		echo '</nav>';
		Admin::form_open( 'settings_save', 'wpac-tabs-content' );
		echo '<input type="hidden" name="tab" value="' . esc_attr( self::$tab ) . '">';

		self::section( __( 'Pages & terms', 'affiliate-captain' ), 'program' );
		self::page_select( 'registration_page_id', __( 'Application page', 'affiliate-captain' ), $s, '[wpac_affiliate_registration]' );
		self::page_select( 'portal_page_id', __( 'Partner area page', 'affiliate-captain' ), $s, '[wpac_affiliate_portal]' );
		self::page_select( 'terms_page_id', __( 'Partner program terms', 'affiliate-captain' ), $s, __( 'Version, text and SHA-256 hash of this page are stored with every acceptance as evidence.', 'affiliate-captain' ) );
		self::text( 'terms_version', __( 'Terms version', 'affiliate-captain' ), $s, __( 'Increase the version only for material changes: partners are then asked to accept again on their next login (credit note payouts wait until they did). Corrections of the page without a new version do not require a new acceptance. Partners can be reminded by e-mail from the partner list.', 'affiliate-captain' ) );
		self::end();

		self::section( __( 'Applications & termination', 'affiliate-captain' ), 'program' );
		self::checkbox( 'auto_approve', __( 'Approve applications automatically', 'affiliate-captain' ), $s );
		self::select( 'terminated_open', __( 'Open commissions on termination', 'affiliate-captain' ), $s, array( 'pay' => __( 'Remain payable', 'affiliate-captain' ), 'reject' => __( 'Are rejected', 'affiliate-captain' ) ) );
		self::end();

		self::section( __( 'Addresses', 'affiliate-captain' ), 'emails' );
		self::text( 'admin_email', __( 'Notification e-mail', 'affiliate-captain' ), $s, __( 'Defaults to the site admin e-mail.', 'affiliate-captain' ) );
		self::text( 'mail_from_name', __( 'Sender name', 'affiliate-captain' ), $s, __( 'Empty: WordPress default (or the setting of your SMTP plugin).', 'affiliate-captain' ) );
		self::text( 'mail_from_email', __( 'Sender address', 'affiliate-captain' ), $s, __( 'Empty: WordPress default. Use an address of your own domain, otherwise mails may land in spam. Replies from partners always go to the notification e-mail (Reply-To).', 'affiliate-captain' ) );
		self::end();

		self::section( __( 'Notices to partners', 'affiliate-captain' ), 'emails' );
		self::checkbox( 'notify_payout_paid', __( 'E-mail to the partner when a payout was paid', 'affiliate-captain' ), $s );
		self::checkbox( 'notify_commissions', __( 'E-mail to partners about new, approved and rejected commissions', 'affiliate-captain' ), $s, __( 'At most one e-mail per partner and run (e.g. all approvals of the hourly run together). Partners can unsubscribe with a link in the e-mail, like from the monthly summary.', 'affiliate-captain' ) );
		self::end();

		self::section( __( 'Notices to the operator', 'affiliate-captain' ), 'emails' );
		self::checkbox( 'notify_admin_review', __( 'E-mail to the operator when commissions need review', 'affiliate-captain' ), $s, __( 'Sent to the notification e-mail at most once per hour with the number of commissions waiting (e.g. self-referrals or orders flagged by the fraud check). Flagged commissions are never approved automatically.', 'affiliate-captain' ) );
		self::end();

		self::section( __( 'Monthly reports', 'affiliate-captain' ), 'emails' );
		self::checkbox( 'report_admin', __( 'Monthly summary e-mail to the operator', 'affiliate-captain' ), $s );
		self::checkbox( 'report_partners', __( 'Monthly summary e-mail to active partners', 'affiliate-captain' ), $s, __( 'Sent on the first days of the month for the previous month.', 'affiliate-captain' ) );
		self::end();

		self::section( __( 'Tracking & attribution', 'affiliate-captain' ), 'tracking' );
		self::text( 'referral_param', __( 'Link parameter', 'affiliate-captain' ), $s, sprintf( /* translators: %s: example URL with the link parameter */ __( 'Example: %s', 'affiliate-captain' ), home_url( '/?' . $s['referral_param'] . '=CODE' ) ) );
		self::checkbox( 'checkout_field', __( 'Show partner code field at checkout (classic + blocks)', 'affiliate-captain' ), $s );
		self::checkbox( 'use_wc_session', __( 'Keep the code from a partner link in the WooCommerce shop session', 'affiliate-captain' ), $s, __( 'Only when a shop session exists (a cart) or a product is put into the cart – a click alone never creates a session or sets a cookie.', 'affiliate-captain' ) );
		self::select(
			'cookie_mode',
			__( 'Affiliate cookie', 'affiliate-captain' ),
			$s,
			array(
				'off'     => __( 'Never set', 'affiliate-captain' ),
				'consent' => __( 'Only with marketing consent (WP Consent API / wpac_has_marketing_consent filter)', 'affiliate-captain' ),
				'always'  => __( 'Always, without consent (not permitted in the EU – §25 TDDDG, ePrivacy)', 'affiliate-captain' ),
			)
		);
		if ( 'always' === $s['cookie_mode'] ) {
			echo '<tr><td></td><td><div class="notice notice-warning inline"><p>' . esc_html__( 'An affiliate cookie is not strictly necessary: in the EU it may only be set with consent. Use "Only with marketing consent" unless your shop is outside the EU and you checked the local law.', 'affiliate-captain' ) . '</p></div></td></tr>';
		}
		self::checkbox( 'carry_referral_links', __( 'Keep the referral of a partner link on the next pages until consent (link parameter, no storage)', 'affiliate-captain' ), $s, __( 'Only with "Only with marketing consent": if the visitor answers the consent banner only on a later page, links and search forms of this shop carry the referral for 30 minutes (signed URL parameter, nothing is stored in the browser before consent). With consent the cookie is set; without consent nothing is kept.', 'affiliate-captain' ) );
		self::number( 'cookie_days', __( 'Cookie lifetime (days)', 'affiliate-captain' ), $s );
		self::text( 'cookie_domain', __( 'Cookie domain', 'affiliate-captain' ), $s, __( 'Leave empty normally. Only if landing pages and shop run on different subdomains (e.g. www.example.com and shop.example.com): enter the common domain .example.com, so the referral cookie and the cart fallback work across both.', 'affiliate-captain' ) );
		self::checkbox( 'js_tracking', __( 'Also record partner links via JavaScript (for full-page caches/CDNs)', 'affiliate-captain' ), $s, __( 'Only needed if your cache or CDN serves pages with ?partner=… from the cache (e.g. Cloudflare "Cache Everything"). Pages with partner links then stay cacheable: a tiny script reports the parameter for guests; a visit is never counted twice.', 'affiliate-captain' ) );
		self::number( 'visit_log_percent', __( 'Record link visits (%)', 'affiliate-captain' ), $s, __( '100 = every visit, e.g. 10 = a sample of every tenth visit (high-traffic sites), 0 = no visit log. Attribution of orders is not affected.', 'affiliate-captain' ) );
		self::select( 'touch_model', __( 'Link attribution', 'affiliate-captain' ), $s, array( 'last' => __( 'Last touch', 'affiliate-captain' ), 'first' => __( 'First touch', 'affiliate-captain' ) ) );
		self::checkbox( 'coupon_priority', __( 'Affiliate coupon has priority over partner links', 'affiliate-captain' ), $s );
		self::select( 'self_referral', __( 'Self-referrals', 'affiliate-captain' ), $s, array( 'block' => __( 'Block', 'affiliate-captain' ), 'review' => __( 'Flag for review', 'affiliate-captain' ) ) );
		self::end();

		self::section( __( 'Fraud rules', 'affiliate-captain' ), 'security' );
		echo '<tr><td colspan="2" class="description">' . esc_html__( 'Matches do not block a sale: its commissions get the status "needs review" with the reason. 0 = rule off.', 'affiliate-captain' ) . '</td></tr>';
		if ( ! (int) $s['fraud_max_orders_day'] && ! (int) $s['fraud_max_order_minor'] && ! (int) $s['fraud_min_click_seconds'] && ! (int) $s['fraud_max_refund_rate'] ) {
			$rec = Settings::recommended_fraud( $currency );
			/* translators: 1: orders per day, 2: order value with currency, 3: seconds, 4: percent */
			echo '<tr><td colspan="2"><div class="notice notice-warning inline"><p>' . esc_html( sprintf( __( 'All fraud thresholds are off, so no order is ever flagged for review. Recommended starting values: %1$d orders per day, orders above %2$s, %3$d seconds between click and order, %4$d %% refund rate.', 'affiliate-captain' ), $rec['fraud_max_orders_day'], Money::format( $rec['fraud_max_order_minor'], $currency ), $rec['fraud_min_click_seconds'], $rec['fraud_max_refund_rate'] ) ) . '</p></div></td></tr>';
		}
		self::number( 'fraud_max_orders_day', __( 'Max. orders per partner in 24 hours', 'affiliate-captain' ), $s );
		$err = self::error_parts( 'fraud_max_order' );
		echo '<tr><th><label for="wpac-fraud_max_order">' . esc_html__( 'Review orders above (net)', 'affiliate-captain' ) . '</label></th><td><input type="text" id="wpac-fraud_max_order" name="wpac[fraud_max_order]" value="' . esc_attr( self::shown( 'fraud_max_order', Money::to_decimal( (int) $s['fraud_max_order_minor'], $currency ) ) ) . '" class="small-text" inputmode="decimal"' . $err[0] . '> ' . esc_html( $currency ) . $err[1] . '<p class="description">' . esc_html__( 'Orders in other currencies are compared after conversion with the reference exchange rates; without a rate for a currency this rule cannot check them (a warning is logged).', 'affiliate-captain' ) . '</p></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- error_parts() is escaped.
		self::number( 'fraud_min_click_seconds', __( 'Min. seconds between click and order', 'affiliate-captain' ), $s );
		self::number( 'fraud_max_refund_rate', __( 'Max. refund rate of a partner in % (90 days, min. 5 orders)', 'affiliate-captain' ), $s );
		self::checkbox( 'fraud_check_domains', __( 'Review orders with disposable or blocked e-mail domains', 'affiliate-captain' ), $s );
		echo '<tr><th><label for="wpac-fraud_blocked_domains">' . esc_html__( 'Additional blocked domains', 'affiliate-captain' ) . '</label></th><td><textarea id="wpac-fraud_blocked_domains" name="wpac[fraud_blocked_domains]" rows="3" class="regular-text code">' . esc_textarea( (string) $s['fraud_blocked_domains'] ) . '</textarea></td></tr>';
		self::end();

		self::section( __( 'Abuse protection', 'affiliate-captain' ), 'security' );
		self::number( 'rate_limit_per_minute', __( 'Max. counted link visits per client and minute', 'affiliate-captain' ), $s );
		$err = self::error_parts( 'trusted_proxies' );
		echo '<tr><th><label for="wpac-trusted_proxies">' . esc_html__( 'Trusted proxies / CDN', 'affiliate-captain' ) . '</label></th><td><textarea id="wpac-trusted_proxies" name="wpac[trusted_proxies]" rows="3" class="regular-text code" placeholder="173.245.48.0/20&#10;10.0.0.1"' . $err[0] . '>' . esc_textarea( self::shown( 'trusted_proxies', $s['trusted_proxies'] ) ) . '</textarea>' . $err[1] . '<p class="description">' . esc_html__( 'IP addresses or ranges (CIDR) of your reverse proxy or CDN (e.g. Cloudflare). Only for these, the visitor IP is read from X-Forwarded-For (CF-Connecting-IP only with the filter wpac_client_ip_headers). Leave empty without a proxy.', 'affiliate-captain' ) . '</p></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- error_parts() escapes.
		self::end();

		self::section( __( 'Program currency', 'affiliate-captain' ), 'commissions' );
		self::currency_field( $s );
		$err = self::error_parts( 'exchange_rates' );
		echo '<tr><th><label for="wpac-exchange_rates">' . esc_html__( 'Reference exchange rates', 'affiliate-captain' ) . '</label></th><td><textarea id="wpac-exchange_rates" name="wpac[exchange_rates]" rows="3" class="regular-text code" placeholder="USD=0.92&#10;CHF=1.05"' . $err[0] . '>' . esc_textarea( self::shown( 'exchange_rates', $s['exchange_rates'] ) ) . '</textarea>' . $err[1] . '<p class="description">' . esc_html( sprintf( /* translators: %s: currency */ __( 'One per line: 1 unit of the currency = x %s. Commissions and payouts are never converted; rates are only used for reports and the minimum payout of other currencies.', 'affiliate-captain' ), $currency ) ) . '</p></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- error_parts() escapes.
		self::end();

		self::section( __( 'Default commission', 'affiliate-captain' ), 'commissions' );
		self::select( 'default_rate_type', __( 'Default commission type', 'affiliate-captain' ), $s, array( 'percent' => __( 'Percent of net', 'affiliate-captain' ), 'fixed' => __( 'Fixed amount per unit', 'affiliate-captain' ) ) );
		$display = $s;
		if ( 'fixed' === $s['default_rate_type'] ) {
			$display['default_rate'] = Money::to_decimal( (int) $s['default_rate'], $currency );
		}
		self::text( 'default_rate', __( 'Default rate', 'affiliate-captain' ), $display, __( 'Percent (e.g. 10 or 12,5 %), or amount in program currency for fixed commissions (e.g. 25 €). Use rules for partners, groups, products and course codes.', 'affiliate-captain' ) );
		self::select(
			'rule_precedence',
			__( 'Rule precedence', 'affiliate-captain' ),
			$s,
			array(
				'scope'  => __( 'Partner rules first: partner > group > all partners, then product > category > all; exclusions always win', 'affiliate-captain' ),
				'target' => __( 'Most specific product first: product > category > all, then partner > group > all partners; exclusions compete like other rules', 'affiliate-captain' ),
			)
		);
		self::end();

		self::section( __( 'Lifetime commissions', 'affiliate-captain' ), 'commissions' );
		self::checkbox( 'lifetime_enabled', __( 'Lifetime commissions: repeat purchases of referred customers go to the same partner', 'affiliate-captain' ), $s, __( 'Only applies when no new partner link, code or coupon is used. Customers are stored as keyed hashes only.', 'affiliate-captain' ) );
		self::number( 'lifetime_days', __( 'Lifetime link duration (days, 0 = unlimited)', 'affiliate-captain' ), $s );
		self::text( 'lifetime_rate', __( 'Lifetime rate in % (empty = normal rules)', 'affiliate-captain' ), $s );
		self::end();

		self::section( __( 'Subscription renewals', 'affiliate-captain' ), 'commissions' );
		self::checkbox( 'renewal_commission', __( 'Commission on subscription renewals (WooCommerce Subscriptions)', 'affiliate-captain' ), $s, __( 'Off: only the first order of a subscription earns commission; renewals are not attributed. Renewals never earn commission when the commission of the first order was rejected, refunded or cancelled.', 'affiliate-captain' ) );
		self::number( 'renewal_limit_months', __( 'Renewal commission for (months, 0 = unlimited)', 'affiliate-captain' ), $s, __( 'Renewals earn commission only within this many months after the first order of the subscription.', 'affiliate-captain' ) );
		self::end();

		self::section( __( 'Hold period', 'affiliate-captain' ), 'commissions' );
		self::number( 'hold_days', __( 'Hold period (days after payment)', 'affiliate-captain' ), $s, __( 'Commissions are released after this period. Integrations can extend it per order (e.g. guarantee end) via hold_until / wpac_hold_until. WooCommerce orders that are on hold are not released until they are processing or completed again.', 'affiliate-captain' ) );
		self::select(
			'hold_start',
			__( 'Hold period starts', 'affiliate-captain' ),
			$s,
			array(
				'paid'      => __( 'At payment', 'affiliate-captain' ),
				'completed' => __( 'When the WooCommerce order is completed (shipped) – for pre-orders, invoice and cash on delivery', 'affiliate-captain' ),
			)
		);
		self::end();

		self::section( __( 'Promotion', 'affiliate-captain' ), 'promotion' );
		self::text( 'landing_prefix', __( 'Landing page path', 'affiliate-captain' ), $s, sprintf( /* translators: %s: example URL of a partner landing page */ __( 'Partner landing URLs look like %s', 'affiliate-captain' ), home_url( '/' . $s['landing_prefix'] . '/partner-slug/' ) ) );
		self::page_select( 'landing_page_id', __( 'Default landing page', 'affiliate-captain' ), $s, __( 'Add [wpac_partner_welcome] to show "Recommended by …".', 'affiliate-captain' ) );
		self::text( 'default_disclosure', __( 'Default disclosure note', 'affiliate-captain' ), $s );
		echo '<tr><th><label for="wpac-promotion_guidelines">' . esc_html__( 'Promotion guidelines for partners', 'affiliate-captain' ) . '</label></th><td><textarea id="wpac-promotion_guidelines" name="wpac[promotion_guidelines]" rows="6" class="large-text">' . esc_textarea( (string) $s['promotion_guidelines'] ) . '</textarea><p class="description">' . esc_html__( 'Allowed claims, labelling duty, prohibited channels, how to report problems. Shown in the partner area.', 'affiliate-captain' ) . '</p></td></tr>';
		self::end();

		self::section( __( 'Payouts', 'affiliate-captain' ), 'payouts' );
		$err = self::error_parts( 'min_payout' );
		echo '<tr><th><label for="wpac-min_payout">' . esc_html__( 'Minimum payout', 'affiliate-captain' ) . '</label></th><td><input type="text" id="wpac-min_payout" name="wpac[min_payout]" value="' . esc_attr( self::shown( 'min_payout', Money::to_decimal( (int) $s['min_payout_minor'], $currency ) ) ) . '" class="small-text" inputmode="decimal"' . $err[0] . '> ' . esc_html( $currency ) . $err[1] . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- error_parts() is escaped.
		// No "payout period" setting: payout runs are started by you (Payouts → New payout run); a
		// stored value of the former setting is kept but has no effect.
		self::checkbox( 'payout_requests', __( 'Partners can request payouts in the partner area', 'affiliate-captain' ), $s );
		self::checkbox( 'payout_method_manual', __( 'Partners may choose another payment method (e.g. PayPal, Wise), paid manually', 'affiliate-captain' ), $s, __( 'Payment details are stored encrypted like the IBAN. You pay these payouts yourself (transfer list or PayPal payouts file) and then mark them as paid; the payment provider is a further recipient of the partner\'s data (privacy policy). Off: new partners can only enter an IBAN; partners who already use another method keep it until they change it.', 'affiliate-captain' ) );
		self::end();

		self::section( __( 'Your company details (for credit notes)', 'affiliate-captain' ), 'payouts' );
		$countries = Settings::countries();
		if ( '' !== (string) $s['operator_country'] && ! isset( $countries[ $s['operator_country'] ] ) ) {
			$countries[ $s['operator_country'] ] = (string) $s['operator_country']; // Value from an older version.
		}
		self::select( 'operator_country', __( 'Your country', 'affiliate-captain' ), $s, $countries );
		echo '<tr><th><label for="wpac-operator_address">' . esc_html__( 'Your address (for credit notes)', 'affiliate-captain' ) . '</label></th><td><textarea id="wpac-operator_address" name="wpac[operator_address]" rows="3" class="regular-text">' . esc_textarea( (string) $s['operator_address'] ) . '</textarea></td></tr>';
		self::text( 'operator_vat_id', __( 'Your VAT ID', 'affiliate-captain' ), $s );
		self::text( 'operator_tax_number', __( 'Your tax number (if no VAT ID)', 'affiliate-captain' ), $s );
		self::end();

		self::section( __( 'Credit notes & VAT', 'affiliate-captain' ), 'payouts' );
		self::checkbox( 'self_billing', __( 'Issue statements as credit notes (self-billing, Gutschrift §14 Abs. 2 UStG)', 'affiliate-captain' ), $s, __( 'Commission rates are net; for domestic VAT-registered partners VAT is added and shown. Foreign business partners: reverse charge. Agree self-billing with your partners in the terms and confirm the treatment with your tax advisor.', 'affiliate-captain' ) );
		self::text( 'vat_rate', __( 'VAT rate in %', 'affiliate-captain' ), $s, __( 'E.g. 19 or 7,7 – "%" may be typed.', 'affiliate-captain' ) );
		self::text( 'credit_prefix', __( 'Prefix credit note numbers', 'affiliate-captain' ), $s, __( 'Numbers are gapless per year, e.g. GS-2026-000001.', 'affiliate-captain' ) );
		self::text( 'storno_prefix', __( 'Prefix cancellation numbers', 'affiliate-captain' ), $s );
		self::end();

		self::section( __( 'VAT ID check (VIES)', 'affiliate-captain' ), 'payouts' );
		self::checkbox( 'vies_check', __( 'VIES check: verify partner VAT IDs with the EU Commission', 'affiliate-captain' ), $s, __( 'Partner VAT IDs (and your own VAT ID) are sent to the VIES service of the European Commission (ec.europa.eu) when payout details are saved and again every 30 days; the confirmation number is kept as proof for reverse-charge credit notes. A VAT ID reported as invalid blocks reverse-charge payouts. Off: nothing is sent and earlier results no longer block payouts.', 'affiliate-captain' ) );
		self::end();

		self::section( __( 'SEPA bank transfer', 'affiliate-captain' ), 'payouts' );
		self::text( 'debtor_name', __( 'Company account holder (SEPA)', 'affiliate-captain' ), $s );
		self::text( 'debtor_iban', __( 'Company IBAN (SEPA)', 'affiliate-captain' ), $s );
		self::text( 'debtor_bic', __( 'Company BIC (optional)', 'affiliate-captain' ), $s );
		self::text( 'remittance_prefix', __( 'Remittance text prefix', 'affiliate-captain' ), $s );
		self::end();

		self::section( __( 'Retention periods', 'affiliate-captain' ), 'data' );
		self::number( 'visit_retention_days', __( 'Keep visit data (days)', 'affiliate-captain' ), $s );
		self::number( 'application_retention_days', __( 'Keep rejected applications (days, 0 = forever)', 'affiliate-captain' ), $s, __( 'Afterwards name, e-mail and application data of rejected applicants without commissions are anonymised.', 'affiliate-captain' ) );
		self::number( 'audit_retention_days', __( 'Keep audit log (days, 0 = forever)', 'affiliate-captain' ), $s, __( 'Terms acceptance records and booking-relevant entries are kept as long as the credit notes.', 'affiliate-captain' ) );
		self::number( 'statement_retention_years', __( 'Keep statements (years, 0 = forever)', 'affiliate-captain' ), $s, __( 'At least the legal minimum of your country (Germany 8, Austria 7, Switzerland 10 years; otherwise 10). Counted from the end of the calendar year (cancellations from their own date). After that, the PDF and the partner data snapshot are deleted; amounts remain.', 'affiliate-captain' ) );
		self::checkbox( 'retention_hold', __( 'Tax audit or legal hold: do not delete any statements', 'affiliate-captain' ), $s, __( 'The retention period is suspended while a tax audit is running (§147 Abs. 3 AO).', 'affiliate-captain' ) );
		self::end();

		self::section( __( 'Maintenance & monitoring', 'affiliate-captain' ), 'data' );
		self::checkbox( 'maintenance_mode', __( 'Maintenance mode', 'affiliate-captain' ), $s, __( 'For migrations or the accounting close: sales are still recorded, but approvals, payouts, report mails and changes in the partner area wait until you switch it off.', 'affiliate-captain' ) );
		self::monitor_token( $s );
		self::end();

		self::section( __( 'Uninstall', 'affiliate-captain' ), 'data' );
		self::checkbox( 'delete_on_uninstall', __( 'Delete all data when the plugin is deleted', 'affiliate-captain' ), $s, __( 'Commission and payout records, credit notes and the audit log are kept as long as a credit note is within the retention period; everything else is deleted.', 'affiliate-captain' ) );
		self::end();

		submit_button();
		echo '</form>';
		if ( 'data' === self::$tab ) {
			echo '<h2>' . esc_html__( 'Record of processing activities', 'affiliate-captain' ) . '</h2><p>' . esc_html__( 'Template for your record of processing activities (Art. 30 GDPR), filled with your current settings.', 'affiliate-captain' ) . '</p>';
			Admin::form_open( 'ropa_download' );
			echo '<p><button class="button">' . esc_html__( 'Download template (Markdown)', 'affiliate-captain' ) . '</button></p></form>';
		}
		echo '</div>';
	}

	/**
	 * Section start (output is buffered and only shown on its tab).
	 *
	 * @param string $title Title.
	 * @param string $tab   Tab.
	 */
	private static function section( $title, $tab ) {
		self::$section_tab   = $tab;
		self::$section_title = $title;
		ob_start();
	}

	/** Section end. */
	private static function end() {
		$html = (string) ob_get_clean();
		if ( self::$section_tab === self::$tab ) {
			echo '<h2>' . esc_html( self::$section_title ) . '</h2><table class="form-table" role="presentation">' . $html . '</table>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped fields.
		}
	}

	/**
	 * Description.
	 *
	 * @param string $desc Description.
	 */
	private static function desc( $desc ) {
		if ( $desc ) {
			echo '<p class="description">' . esc_html( $desc ) . '</p>';
		}
	}

	/**
	 * Monitoring token field (write-only).
	 *
	 * @param array $s Settings.
	 */
	private static function monitor_token( $s ) {
		$set = '' !== (string) $s['monitor_token'];
		// Only a hash is stored: the field is always empty, an empty field keeps the token.
		printf( '<tr><th><label for="wpac-monitor_token">%1$s</label></th><td><input type="password" id="wpac-monitor_token" name="wpac[monitor_token]" value="" class="regular-text" autocomplete="new-password" placeholder="%2$s">', esc_html__( 'Monitoring token', 'affiliate-captain' ), esc_attr( $set ? __( 'A token is set – enter a new one to replace it', 'affiliate-captain' ) : '' ) );
		if ( $set ) {
			echo ' <label><input type="checkbox" name="wpac[monitor_token_clear]" value="1"> ' . esc_html__( 'Remove token', 'affiliate-captain' ) . '</label>';
		}
		self::desc( sprintf( /* translators: %s: URL */ __( 'At least 20 letters/digits. An external monitor (e.g. an uptime service) can then call %s with the header "X-WPAC-Monitor-Token" and gets ok or the names of failing checks (no details).', 'affiliate-captain' ), rest_url( 'wpac/v1/health' ) ) . ' ' . __( 'Only a hash of the token is stored; note it down before saving.', 'affiliate-captain' ) );
		echo '</td></tr>';
	}

	/**
	 * Text field.
	 *
	 * @param string $key   Key.
	 * @param string $label Label.
	 * @param array  $s     Settings.
	 * @param string $desc  Description.
	 */
	private static function text( $key, $label, $s, $desc = '' ) {
		$err = self::error_parts( $key );
		printf( '<tr><th><label for="wpac-%1$s">%2$s</label></th><td><input type="text" id="wpac-%1$s" name="wpac[%1$s]" value="%3$s" class="regular-text"%4$s>%5$s', esc_attr( $key ), esc_html( $label ), esc_attr( self::shown( $key, $s[ $key ] ) ), $err[0], $err[1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- error_parts() is escaped.
		self::desc( $desc );
		echo '</td></tr>';
	}

	/**
	 * Number field.
	 *
	 * @param string $key   Key.
	 * @param string $label Label.
	 * @param array  $s     Settings.
	 * @param string $desc  Description.
	 */
	private static function number( $key, $label, $s, $desc = '' ) {
		$err = self::error_parts( $key );
		printf( '<tr><th><label for="wpac-%1$s">%2$s</label></th><td><input type="number" min="0" step="1" id="wpac-%1$s" name="wpac[%1$s]" value="%3$s" class="small-text"%4$s>%5$s', esc_attr( $key ), esc_html( $label ), esc_attr( self::shown( $key, $s[ $key ] ) ), $err[0], $err[1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- error_parts() is escaped.
		self::desc( $desc );
		echo '</td></tr>';
	}

	/**
	 * Checkbox.
	 *
	 * @param string $key   Key.
	 * @param string $label Label.
	 * @param array  $s     Settings.
	 * @param string $desc  Description.
	 */
	private static function checkbox( $key, $label, $s, $desc = '' ) {
		// The checkbox is named by its full label (screen readers announce what is switched on).
		printf( '<tr><th scope="row">%2$s</th><td><label for="wpac-%1$s"><input type="checkbox" id="wpac-%1$s" name="wpac[%1$s]" value="1" %3$s> %4$s</label>', esc_attr( $key ), esc_html( self::short_label( $label ) ), checked( (int) $s[ $key ], 1, false ), esc_html( $label ) );
		self::desc( $desc );
		echo '</td></tr>';
	}

	/**
	 * Short row title for long checkbox labels (text before a colon or the first 6 words).
	 *
	 * @param string $label Label.
	 * @return string
	 */
	private static function short_label( $label ) {
		if ( false !== strpos( $label, ':' ) ) {
			return trim( strstr( $label, ':', true ) );
		}
		$words = preg_split( '/\s+/', $label );
		return count( $words ) > 6 ? implode( ' ', array_slice( $words, 0, 6 ) ) . ' …' : $label;
	}

	/**
	 * Select.
	 *
	 * @param string $key     Key.
	 * @param string $label   Label.
	 * @param array  $s       Settings.
	 * @param array  $options Options.
	 */
	private static function select( $key, $label, $s, array $options ) {
		$err = self::error_parts( $key );
		printf( '<tr><th><label for="wpac-%1$s">%2$s</label></th><td><select id="wpac-%1$s" name="wpac[%1$s]"%3$s>', esc_attr( $key ), esc_html( $label ), $err[0] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- error_parts() is escaped.
		foreach ( $options as $v => $l ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $v ), selected( self::shown( $key, $s[ $key ] ), (string) $v, false ), esc_html( $l ) );
		}
		echo '</select>' . $err[1] . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- error_parts() is escaped.
	}

	/**
	 * Page dropdown.
	 *
	 * @param string $key   Key.
	 * @param string $label Label.
	 * @param array  $s     Settings.
	 * @param string $desc  Description.
	 */
	private static function page_select( $key, $label, $s, $desc = '' ) {
		echo '<tr><th><label for="wpac-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
		wp_dropdown_pages(
			array(
				'name'              => 'wpac[' . $key . ']', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				'id'                => 'wpac-' . $key, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				'selected'          => (int) $s[ $key ],
				'show_option_none'  => '—', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				'option_none_value' => '0',
			)
		);
		self::desc( $desc );
		echo '</td></tr>';
	}
}
