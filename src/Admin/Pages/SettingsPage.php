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
		$tab  = sanitize_key( Admin::post( 'tab' ) );
		$tab  = isset( $tabs[ $tab ] ) ? $tab : 'program';
		// Only the fields of the submitted tab are changed (unchecked checkboxes are absent from POST).
		// Rejected fields keep their stored value (sanitize() returns it) and are reported.
		$errors  = array();
		$notices = array();
		$new     = array_intersect_key( Settings::sanitize( $raw, $errors, $notices ), array_flip( $tabs[ $tab ] ) );
		$old     = Settings::all();
		Settings::update( $new );
		$changed = array();
		foreach ( $new as $k => $v ) {
			if ( ! isset( $old[ $k ] ) || (string) $old[ $k ] !== (string) $v ) {
				$changed[] = $k;
			}
		}
		if ( $changed ) {
			Audit::log( 'settings.updated', 'settings', 0, array( 'changed' => $changed ) );
		}
		$url    = Admin::url( 'wpac-settings', array( 'tab' => $tab ) );
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
					_n( '%d field was not saved because its value is not valid – it keeps its previous value. All other changes were saved.', '%d fields were not saved because their values are not valid – they keep their previous values. All other changes were saved.', count( $errors ), 'wp-affiliate-captain' ),
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
			Admin::back( __( 'Settings saved with an adjustment:', 'wp-affiliate-captain' ), 'warning', $url, $lines );
		}
		Admin::back( __( 'Settings saved.', 'wp-affiliate-captain' ), 'success', $url );
	}

	/**
	 * Labels of the validated fields (form field name => label) for error messages.
	 *
	 * @return array
	 */
	private static function field_labels() {
		return array(
			'referral_param'             => __( 'Link parameter', 'wp-affiliate-captain' ),
			'lifetime_rate'              => __( 'Lifetime rate in % (empty = normal rules)', 'wp-affiliate-captain' ),
			'audit_retention_days'       => __( 'Keep audit log (days, 0 = forever)', 'wp-affiliate-captain' ),
			'application_retention_days' => __( 'Keep rejected applications (days, 0 = forever)', 'wp-affiliate-captain' ),
			'fraud_max_orders_day'       => __( 'Max. orders per partner in 24 hours', 'wp-affiliate-captain' ),
			'fraud_min_click_seconds'    => __( 'Min. seconds between click and order', 'wp-affiliate-captain' ),
			'fraud_max_refund_rate'      => __( 'Max. refund rate of a partner in % (90 days, min. 5 orders)', 'wp-affiliate-captain' ),
			'statement_retention_years'  => __( 'Keep statements (years, 0 = forever)', 'wp-affiliate-captain' ),
			'lifetime_days'              => __( 'Lifetime link duration (days, 0 = unlimited)', 'wp-affiliate-captain' ),
			'cookie_days'                => __( 'Cookie lifetime (days)', 'wp-affiliate-captain' ),
			'rate_limit_per_minute'      => __( 'Max. counted link visits per client and minute', 'wp-affiliate-captain' ),
			'visit_retention_days'       => __( 'Keep visit data (days)', 'wp-affiliate-captain' ),
			'hold_days'                  => __( 'Hold period (days after payment)', 'wp-affiliate-captain' ),
			'operator_country'           => __( 'Your country', 'wp-affiliate-captain' ),
			'currency'                   => __( 'Program currency', 'wp-affiliate-captain' ),
			'exchange_rates'             => __( 'Reference exchange rates', 'wp-affiliate-captain' ),
			'default_rate'               => __( 'Default rate', 'wp-affiliate-captain' ),
			'trusted_proxies'            => __( 'Trusted proxies / CDN', 'wp-affiliate-captain' ),
			'fraud_max_order'            => __( 'Review orders above (net)', 'wp-affiliate-captain' ),
			'min_payout'                 => __( 'Minimum payout', 'wp-affiliate-captain' ),
			'landing_prefix'             => __( 'Landing page path', 'wp-affiliate-captain' ),
			'default_disclosure'         => __( 'Default disclosure note', 'wp-affiliate-captain' ),
			'terms_version'              => __( 'Terms version', 'wp-affiliate-captain' ),
			'admin_email'                => __( 'Notification e-mail', 'wp-affiliate-captain' ),
			'monitor_token'              => __( 'Monitoring token', 'wp-affiliate-captain' ),
			'vat_rate'                   => __( 'VAT rate in %', 'wp-affiliate-captain' ),
			'credit_prefix'              => __( 'Prefix credit note numbers', 'wp-affiliate-captain' ),
			'storno_prefix'              => __( 'Prefix cancellation numbers', 'wp-affiliate-captain' ),
			'debtor_bic'                 => __( 'Company BIC (optional)', 'wp-affiliate-captain' ),
			'debtor_iban'                => __( 'Company IBAN (SEPA)', 'wp-affiliate-captain' ),
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
			'program'     => array( 'registration_page_id', 'portal_page_id', 'terms_page_id', 'terms_version', 'auto_approve', 'terminated_open', 'admin_email' ),
			'tracking'    => array( 'referral_param', 'checkout_field', 'use_wc_session', 'cookie_mode', 'cookie_days', 'touch_model', 'coupon_priority', 'self_referral', 'js_tracking' ),
			'security'    => array( 'fraud_max_orders_day', 'fraud_max_order_minor', 'fraud_min_click_seconds', 'fraud_max_refund_rate', 'fraud_check_domains', 'fraud_blocked_domains', 'rate_limit_per_minute', 'trusted_proxies', 'visit_retention_days', 'application_retention_days' ),
			'commissions' => array( 'currency', 'exchange_rates', 'default_rate_type', 'default_rate', 'lifetime_enabled', 'lifetime_days', 'lifetime_rate', 'hold_days', 'renewal_commission' ),
			'promotion'   => array( 'landing_prefix', 'landing_page_id', 'default_disclosure', 'promotion_guidelines' ),
			'payouts'     => array( 'min_payout_minor', 'payout_period', 'payout_requests', 'statement_retention_years', 'retention_hold', 'self_billing', 'vat_rate', 'operator_country', 'operator_address', 'operator_vat_id', 'operator_tax_number', 'credit_prefix', 'storno_prefix', 'debtor_name', 'debtor_iban', 'debtor_bic', 'remittance_prefix' ),
			'reports'     => array( 'report_admin', 'report_partners', 'audit_retention_days', 'delete_on_uninstall', 'maintenance_mode', 'monitor_token' ),
		);
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
			'program'     => __( 'Program', 'wp-affiliate-captain' ),
			'tracking'    => __( 'Tracking', 'wp-affiliate-captain' ),
			'security'    => __( 'Fraud & abuse', 'wp-affiliate-captain' ),
			'commissions' => __( 'Commissions', 'wp-affiliate-captain' ),
			'promotion'   => __( 'Promotion', 'wp-affiliate-captain' ),
			'payouts'     => __( 'Payouts & credit notes', 'wp-affiliate-captain' ),
			'reports'     => __( 'Reports & data', 'wp-affiliate-captain' ),
		);
		$tab       = Admin::get( 'tab' );
		self::$tab = isset( $labels[ $tab ] ) ? $tab : 'program';
		$s        = Settings::all();
		$currency = $s['currency'];
		$kept     = Admin::kept_form( 'settings' );
		if ( isset( $kept['values'], $kept['errors'] ) ) {
			self::$kept = $kept;
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Affiliate Captain settings', 'wp-affiliate-captain' ) . '</h1>';
		Admin::notice();
		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Settings sections', 'wp-affiliate-captain' ) . '">';
		foreach ( $labels as $key => $label ) {
			printf( '<a class="nav-tab %s" href="%s"%s>%s</a>', $key === self::$tab ? 'nav-tab-active' : '', esc_url( Admin::url( 'wpac-settings', array( 'tab' => $key ) ) ), $key === self::$tab ? ' aria-current="page"' : '', esc_html( $label ) );
		}
		echo '</nav>';
		Admin::form_open( 'settings_save', 'wpac-tabs-content' );
		echo '<input type="hidden" name="tab" value="' . esc_attr( self::$tab ) . '">';

		self::section( __( 'Program & pages', 'wp-affiliate-captain' ), 'program' );
		self::page_select( 'registration_page_id', __( 'Application page', 'wp-affiliate-captain' ), $s, '[wpac_affiliate_registration]' );
		self::page_select( 'portal_page_id', __( 'Partner area page', 'wp-affiliate-captain' ), $s, '[wpac_affiliate_portal]' );
		self::page_select( 'terms_page_id', __( 'Partner program terms', 'wp-affiliate-captain' ), $s, __( 'Version, text and SHA-256 hash of this page are stored with every acceptance as evidence.', 'wp-affiliate-captain' ) );
		self::text( 'terms_version', __( 'Terms version', 'wp-affiliate-captain' ), $s, __( 'Increase the version only for material changes: partners are then asked to accept again on their next login (credit note payouts wait until they did). Corrections of the page without a new version do not require a new acceptance. Partners can be reminded by e-mail from the partner list.', 'wp-affiliate-captain' ) );
		self::checkbox( 'auto_approve', __( 'Approve applications automatically', 'wp-affiliate-captain' ), $s );
		self::select( 'terminated_open', __( 'Open commissions on termination', 'wp-affiliate-captain' ), $s, array( 'pay' => __( 'Remain payable', 'wp-affiliate-captain' ), 'reject' => __( 'Are rejected', 'wp-affiliate-captain' ) ) );
		self::text( 'admin_email', __( 'Notification e-mail', 'wp-affiliate-captain' ), $s, __( 'Defaults to the site admin e-mail.', 'wp-affiliate-captain' ) );
		self::end();

		self::section( __( 'Tracking & attribution', 'wp-affiliate-captain' ), 'tracking' );
		/* translators: %s: example URL */
		self::text( 'referral_param', __( 'Link parameter', 'wp-affiliate-captain' ), $s, sprintf( __( 'Example: %s', 'wp-affiliate-captain' ), home_url( '/?' . $s['referral_param'] . '=CODE' ) ) );
		self::checkbox( 'checkout_field', __( 'Show partner code field at checkout (classic + blocks)', 'wp-affiliate-captain' ), $s );
		self::checkbox( 'use_wc_session', __( 'Keep the code from a partner link in the WooCommerce shop session', 'wp-affiliate-captain' ), $s, __( 'Without marketing consent only if a shop session already exists (e.g. a cart) – a click alone never sets a cookie.', 'wp-affiliate-captain' ) );
		self::select(
			'cookie_mode',
			__( 'Affiliate cookie', 'wp-affiliate-captain' ),
			$s,
			array(
				'off'     => __( 'Never set', 'wp-affiliate-captain' ),
				'consent' => __( 'Only with marketing consent (WP Consent API / wpac_has_marketing_consent filter)', 'wp-affiliate-captain' ),
				'always'  => __( 'Always, without consent (not permitted in the EU – §25 TDDDG, ePrivacy)', 'wp-affiliate-captain' ),
			)
		);
		if ( 'always' === $s['cookie_mode'] ) {
			echo '<tr><td></td><td><div class="notice notice-warning inline"><p>' . esc_html__( 'An affiliate cookie is not strictly necessary: in the EU it may only be set with consent. Use "Only with marketing consent" unless your shop is outside the EU and you checked the local law.', 'wp-affiliate-captain' ) . '</p></div></td></tr>';
		}
		self::number( 'cookie_days', __( 'Cookie lifetime (days)', 'wp-affiliate-captain' ), $s );
		self::checkbox( 'js_tracking', __( 'Also record partner links via JavaScript (for full-page caches/CDNs)', 'wp-affiliate-captain' ), $s, __( 'Only needed if your cache or CDN serves pages with ?partner=… from the cache (e.g. Cloudflare "Cache Everything"). A tiny script reports the parameter; a visit is never counted twice.', 'wp-affiliate-captain' ) );
		self::select( 'touch_model', __( 'Link attribution', 'wp-affiliate-captain' ), $s, array( 'last' => __( 'Last touch', 'wp-affiliate-captain' ), 'first' => __( 'First touch', 'wp-affiliate-captain' ) ) );
		self::checkbox( 'coupon_priority', __( 'Affiliate coupon has priority over partner links', 'wp-affiliate-captain' ), $s );
		self::select( 'self_referral', __( 'Self-referrals', 'wp-affiliate-captain' ), $s, array( 'block' => __( 'Block', 'wp-affiliate-captain' ), 'review' => __( 'Flag for review', 'wp-affiliate-captain' ) ) );
		self::end();

		self::section( __( 'Fraud rules', 'wp-affiliate-captain' ), 'security' );
		echo '<tr><td colspan="2" class="description">' . esc_html__( 'Matches do not block a sale: its commissions get the status "needs review" with the reason. 0 = rule off.', 'wp-affiliate-captain' ) . '</td></tr>';
		self::number( 'fraud_max_orders_day', __( 'Max. orders per partner in 24 hours', 'wp-affiliate-captain' ), $s );
		$err = self::error_parts( 'fraud_max_order' );
		echo '<tr><th><label for="wpac-fraud_max_order">' . esc_html__( 'Review orders above (net)', 'wp-affiliate-captain' ) . '</label></th><td><input type="text" id="wpac-fraud_max_order" name="wpac[fraud_max_order]" value="' . esc_attr( self::shown( 'fraud_max_order', Money::to_decimal( (int) $s['fraud_max_order_minor'], $currency ) ) ) . '" class="small-text" inputmode="decimal"' . $err[0] . '> ' . esc_html( $currency ) . $err[1] . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- error_parts() is escaped.
		self::number( 'fraud_min_click_seconds', __( 'Min. seconds between click and order', 'wp-affiliate-captain' ), $s );
		self::number( 'fraud_max_refund_rate', __( 'Max. refund rate of a partner in % (90 days, min. 5 orders)', 'wp-affiliate-captain' ), $s );
		self::checkbox( 'fraud_check_domains', __( 'Review orders with disposable or blocked e-mail domains', 'wp-affiliate-captain' ), $s );
		echo '<tr><th><label for="wpac-fraud_blocked_domains">' . esc_html__( 'Additional blocked domains', 'wp-affiliate-captain' ) . '</label></th><td><textarea id="wpac-fraud_blocked_domains" name="wpac[fraud_blocked_domains]" rows="3" class="regular-text code">' . esc_textarea( (string) $s['fraud_blocked_domains'] ) . '</textarea></td></tr>';
		self::end();

		self::section( __( 'Abuse protection', 'wp-affiliate-captain' ), 'security' );
		self::number( 'rate_limit_per_minute', __( 'Max. counted link visits per client and minute', 'wp-affiliate-captain' ), $s );
		$err = self::error_parts( 'trusted_proxies' );
		echo '<tr><th><label for="wpac-trusted_proxies">' . esc_html__( 'Trusted proxies / CDN', 'wp-affiliate-captain' ) . '</label></th><td><textarea id="wpac-trusted_proxies" name="wpac[trusted_proxies]" rows="3" class="regular-text code" placeholder="173.245.48.0/20&#10;10.0.0.1"' . $err[0] . '>' . esc_textarea( self::shown( 'trusted_proxies', $s['trusted_proxies'] ) ) . '</textarea>' . $err[1] . '<p class="description">' . esc_html__( 'IP addresses or ranges (CIDR) of your reverse proxy or CDN (e.g. Cloudflare). Only for these, the visitor IP is read from X-Forwarded-For / CF-Connecting-IP. Leave empty without a proxy.', 'wp-affiliate-captain' ) . '</p></td></tr>';
		self::number( 'visit_retention_days', __( 'Keep visit data (days)', 'wp-affiliate-captain' ), $s );
		self::number( 'application_retention_days', __( 'Keep rejected applications (days, 0 = forever)', 'wp-affiliate-captain' ), $s, __( 'Afterwards name, e-mail and application data of rejected applicants without commissions are anonymised.', 'wp-affiliate-captain' ) );
		self::end();

		self::section( __( 'Commissions', 'wp-affiliate-captain' ), 'commissions' );
		self::text( 'currency', __( 'Program currency', 'wp-affiliate-captain' ), $s );
		$err = self::error_parts( 'exchange_rates' );
		echo '<tr><th><label for="wpac-exchange_rates">' . esc_html__( 'Reference exchange rates', 'wp-affiliate-captain' ) . '</label></th><td><textarea id="wpac-exchange_rates" name="wpac[exchange_rates]" rows="3" class="regular-text code" placeholder="USD=0.92&#10;CHF=1.05"' . $err[0] . '>' . esc_textarea( self::shown( 'exchange_rates', $s['exchange_rates'] ) ) . '</textarea>' . $err[1] . '<p class="description">' . esc_html( sprintf( /* translators: %s: currency */ __( 'One per line: 1 unit of the currency = x %s. Commissions and payouts are never converted; rates are only used for reports and the minimum payout of other currencies.', 'wp-affiliate-captain' ), $currency ) ) . '</p></td></tr>';
		self::select( 'default_rate_type', __( 'Default commission type', 'wp-affiliate-captain' ), $s, array( 'percent' => __( 'Percent of net', 'wp-affiliate-captain' ), 'fixed' => __( 'Fixed amount per unit', 'wp-affiliate-captain' ) ) );
		$display = $s;
		if ( 'fixed' === $s['default_rate_type'] ) {
			$display['default_rate'] = Money::to_decimal( (int) $s['default_rate'], $currency );
		}
		self::text( 'default_rate', __( 'Default rate', 'wp-affiliate-captain' ), $display, __( 'Percent (e.g. 10 or 12,5 %), or amount in program currency for fixed commissions (e.g. 25 €). Use rules for partners, groups, products and course codes.', 'wp-affiliate-captain' ) );
		self::checkbox( 'lifetime_enabled', __( 'Lifetime commissions: repeat purchases of referred customers go to the same partner', 'wp-affiliate-captain' ), $s, __( 'Only applies when no new partner link, code or coupon is used. Customers are stored as keyed hashes only.', 'wp-affiliate-captain' ) );
		self::checkbox( 'renewal_commission', __( 'Commission on subscription renewals (WooCommerce Subscriptions)', 'wp-affiliate-captain' ), $s, __( 'Off: only the first order of a subscription earns commission; renewals are not attributed.', 'wp-affiliate-captain' ) );
		self::number( 'lifetime_days', __( 'Lifetime link duration (days, 0 = unlimited)', 'wp-affiliate-captain' ), $s );
		self::text( 'lifetime_rate', __( 'Lifetime rate in % (empty = normal rules)', 'wp-affiliate-captain' ), $s );
		self::number( 'hold_days', __( 'Hold period (days after payment)', 'wp-affiliate-captain' ), $s, __( 'Commissions are released after this period. Integrations can extend it per order (e.g. guarantee end) via hold_until / wpac_hold_until.', 'wp-affiliate-captain' ) );
		self::end();

		self::section( __( 'Promotion', 'wp-affiliate-captain' ), 'promotion' );
		/* translators: %s: example URL */
		self::text( 'landing_prefix', __( 'Landing page path', 'wp-affiliate-captain' ), $s, sprintf( __( 'Partner landing URLs look like %s', 'wp-affiliate-captain' ), home_url( '/' . $s['landing_prefix'] . '/partner-slug/' ) ) );
		self::page_select( 'landing_page_id', __( 'Default landing page', 'wp-affiliate-captain' ), $s, __( 'Add [wpac_partner_welcome] to show "Recommended by …".', 'wp-affiliate-captain' ) );
		self::text( 'default_disclosure', __( 'Default disclosure note', 'wp-affiliate-captain' ), $s );
		echo '<tr><th><label for="wpac-promotion_guidelines">' . esc_html__( 'Promotion guidelines for partners', 'wp-affiliate-captain' ) . '</label></th><td><textarea id="wpac-promotion_guidelines" name="wpac[promotion_guidelines]" rows="6" class="large-text">' . esc_textarea( (string) $s['promotion_guidelines'] ) . '</textarea><p class="description">' . esc_html__( 'Allowed claims, labelling duty, prohibited channels, how to report problems. Shown in the partner area.', 'wp-affiliate-captain' ) . '</p></td></tr>';
		self::end();

		self::section( __( 'Payouts', 'wp-affiliate-captain' ), 'payouts' );
		$err = self::error_parts( 'min_payout' );
		echo '<tr><th><label for="wpac-min_payout">' . esc_html__( 'Minimum payout', 'wp-affiliate-captain' ) . '</label></th><td><input type="text" id="wpac-min_payout" name="wpac[min_payout]" value="' . esc_attr( self::shown( 'min_payout', Money::to_decimal( (int) $s['min_payout_minor'], $currency ) ) ) . '" class="small-text" inputmode="decimal"' . $err[0] . '> ' . esc_html( $currency ) . $err[1] . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- error_parts() is escaped.
		self::select( 'payout_period', __( 'Payout period', 'wp-affiliate-captain' ), $s, array( 'monthly' => __( 'Monthly', 'wp-affiliate-captain' ), 'quarterly' => __( 'Quarterly', 'wp-affiliate-captain' ), 'manual' => __( 'Manual', 'wp-affiliate-captain' ) ) );
		self::checkbox( 'payout_requests', __( 'Partners can request payouts in the partner area', 'wp-affiliate-captain' ), $s );
		self::number( 'statement_retention_years', __( 'Keep statements (years, 0 = forever)', 'wp-affiliate-captain' ), $s, __( 'At least the legal minimum of your country (Germany 8, Austria 7, Switzerland 10 years; otherwise 10). Counted from the end of the calendar year (cancellations from their own date). After that, the PDF and the partner data snapshot are deleted; amounts remain.', 'wp-affiliate-captain' ) );
		self::checkbox( 'retention_hold', __( 'Tax audit or legal hold: do not delete any statements', 'wp-affiliate-captain' ), $s, __( 'The retention period is suspended while a tax audit is running (§147 Abs. 3 AO).', 'wp-affiliate-captain' ) );
		self::checkbox( 'self_billing', __( 'Issue statements as credit notes (self-billing, Gutschrift §14 Abs. 2 UStG)', 'wp-affiliate-captain' ), $s, __( 'Commission rates are net; for domestic VAT-registered partners VAT is added and shown. Foreign business partners: reverse charge. Agree self-billing with your partners in the terms and confirm the treatment with your tax advisor.', 'wp-affiliate-captain' ) );
		self::text( 'vat_rate', __( 'VAT rate in %', 'wp-affiliate-captain' ), $s, __( 'E.g. 19 or 7,7 – "%" may be typed.', 'wp-affiliate-captain' ) );
		$countries = Settings::countries();
		if ( '' !== (string) $s['operator_country'] && ! isset( $countries[ $s['operator_country'] ] ) ) {
			$countries[ $s['operator_country'] ] = (string) $s['operator_country']; // Value from an older version.
		}
		self::select( 'operator_country', __( 'Your country', 'wp-affiliate-captain' ), $s, $countries );
		echo '<tr><th><label for="wpac-operator_address">' . esc_html__( 'Your address (for credit notes)', 'wp-affiliate-captain' ) . '</label></th><td><textarea id="wpac-operator_address" name="wpac[operator_address]" rows="3" class="regular-text">' . esc_textarea( (string) $s['operator_address'] ) . '</textarea></td></tr>';
		self::text( 'operator_vat_id', __( 'Your VAT ID', 'wp-affiliate-captain' ), $s );
		self::text( 'operator_tax_number', __( 'Your tax number (if no VAT ID)', 'wp-affiliate-captain' ), $s );
		self::text( 'credit_prefix', __( 'Prefix credit note numbers', 'wp-affiliate-captain' ), $s, __( 'Numbers are gapless per year, e.g. GS-2026-000001.', 'wp-affiliate-captain' ) );
		self::text( 'storno_prefix', __( 'Prefix cancellation numbers', 'wp-affiliate-captain' ), $s );
		self::text( 'debtor_name', __( 'Company account holder (SEPA)', 'wp-affiliate-captain' ), $s );
		self::text( 'debtor_iban', __( 'Company IBAN (SEPA)', 'wp-affiliate-captain' ), $s );
		self::text( 'debtor_bic', __( 'Company BIC (optional)', 'wp-affiliate-captain' ), $s );
		self::text( 'remittance_prefix', __( 'Remittance text prefix', 'wp-affiliate-captain' ), $s );
		self::end();

		self::section( __( 'Reporting', 'wp-affiliate-captain' ), 'reports' );
		self::checkbox( 'report_admin', __( 'Monthly summary e-mail to the operator', 'wp-affiliate-captain' ), $s );
		self::checkbox( 'report_partners', __( 'Monthly summary e-mail to active partners', 'wp-affiliate-captain' ), $s, __( 'Sent on the first days of the month for the previous month.', 'wp-affiliate-captain' ) );
		self::end();

		self::section( __( 'Housekeeping', 'wp-affiliate-captain' ), 'reports' );
		self::number( 'audit_retention_days', __( 'Keep audit log (days, 0 = forever)', 'wp-affiliate-captain' ), $s, __( 'Terms acceptance records and booking-relevant entries are kept as long as the credit notes.', 'wp-affiliate-captain' ) );
		self::checkbox( 'maintenance_mode', __( 'Maintenance mode', 'wp-affiliate-captain' ), $s, __( 'For migrations or the accounting close: sales are still recorded, but approvals, payouts, report mails and changes in the partner area wait until you switch it off.', 'wp-affiliate-captain' ) );
		self::text( 'monitor_token', __( 'Monitoring token', 'wp-affiliate-captain' ), $s, sprintf( /* translators: %s: URL */ __( 'At least 20 letters/digits. An external monitor (e.g. an uptime service) can then call %s with the header "X-WPAC-Monitor-Token" and gets ok or the names of failing checks (no details).', 'wp-affiliate-captain' ), rest_url( 'wpac/v1/health' ) ) );
		self::checkbox( 'delete_on_uninstall', __( 'Delete all data when the plugin is deleted', 'wp-affiliate-captain' ), $s, __( 'Commission and payout records, credit notes and the audit log are kept as long as a credit note is within the retention period; everything else is deleted.', 'wp-affiliate-captain' ) );
		self::end();

		submit_button();
		echo '</form>';
		if ( 'reports' === self::$tab ) {
			echo '<h2>' . esc_html__( 'Record of processing activities', 'wp-affiliate-captain' ) . '</h2><p>' . esc_html__( 'Template for your record of processing activities (Art. 30 GDPR), filled with your current settings.', 'wp-affiliate-captain' ) . '</p>';
			Admin::form_open( 'ropa_download' );
			echo '<p><button class="button">' . esc_html__( 'Download template (Markdown)', 'wp-affiliate-captain' ) . '</button></p></form>';
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
			printf( '<option value="%s" %s>%s</option>', esc_attr( $v ), selected( (string) $s[ $key ], (string) $v, false ), esc_html( $l ) );
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
