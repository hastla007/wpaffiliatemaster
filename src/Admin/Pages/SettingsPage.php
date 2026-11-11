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
		if ( 'settings_save' !== $do ) {
			return;
		}
		$raw = (array) Admin::post( 'wpac', array() );
		$new = Settings::sanitize( $raw );
		$old = Settings::all();
		Settings::update( $new );
		$changed = array();
		foreach ( $new as $k => $v ) {
			if ( ! isset( $old[ $k ] ) || (string) $old[ $k ] !== (string) $v ) {
				$changed[] = $k;
			}
		}
		Audit::log( 'settings.updated', 'settings', 0, array( 'changed' => $changed ) );
		Admin::back( __( 'Settings saved.', 'wp-affiliate-captain' ) );
	}

	/**
	 * Render.
	 */
	public static function render() {
		$s        = Settings::all();
		$currency = $s['currency'];
		echo '<div class="wrap"><h1>' . esc_html__( 'Affiliate Captain settings', 'wp-affiliate-captain' ) . '</h1>';
		Admin::notice();
		Admin::form_open( 'settings_save' );

		self::section( __( 'Program & pages', 'wp-affiliate-captain' ) );
		self::page_select( 'registration_page_id', __( 'Application page', 'wp-affiliate-captain' ), $s, '[wpac_affiliate_registration]' );
		self::page_select( 'portal_page_id', __( 'Partner area page', 'wp-affiliate-captain' ), $s, '[wpac_affiliate_portal]' );
		self::page_select( 'terms_page_id', __( 'Partner program terms', 'wp-affiliate-captain' ), $s, __( 'Version and SHA-256 hash of this page are stored with every acceptance.', 'wp-affiliate-captain' ) );
		self::text( 'terms_version', __( 'Terms version', 'wp-affiliate-captain' ), $s, __( 'Increase when the terms change – partners must accept again.', 'wp-affiliate-captain' ) );
		self::checkbox( 'auto_approve', __( 'Approve applications automatically', 'wp-affiliate-captain' ), $s );
		self::select( 'terminated_open', __( 'Open commissions on termination', 'wp-affiliate-captain' ), $s, array( 'pay' => __( 'Remain payable', 'wp-affiliate-captain' ), 'reject' => __( 'Are rejected', 'wp-affiliate-captain' ) ) );
		self::text( 'admin_email', __( 'Notification e-mail', 'wp-affiliate-captain' ), $s, __( 'Defaults to the site admin e-mail.', 'wp-affiliate-captain' ) );
		self::end();

		self::section( __( 'Tracking & attribution', 'wp-affiliate-captain' ) );
		/* translators: %s: example URL */
		self::text( 'referral_param', __( 'Link parameter', 'wp-affiliate-captain' ), $s, sprintf( __( 'Example: %s', 'wp-affiliate-captain' ), home_url( '/?' . $s['referral_param'] . '=CODE' ) ) );
		self::checkbox( 'checkout_field', __( 'Show partner code field at checkout (classic + blocks)', 'wp-affiliate-captain' ), $s );
		self::checkbox( 'use_wc_session', __( 'Keep the code from a partner link in the WooCommerce shop session', 'wp-affiliate-captain' ), $s );
		self::select(
			'cookie_mode',
			__( 'Affiliate cookie', 'wp-affiliate-captain' ),
			$s,
			array(
				'off'     => __( 'Never set', 'wp-affiliate-captain' ),
				'consent' => __( 'Only with marketing consent (WP Consent API / wpac_has_marketing_consent filter)', 'wp-affiliate-captain' ),
				'always'  => __( 'Always (only if legally permitted)', 'wp-affiliate-captain' ),
			)
		);
		self::number( 'cookie_days', __( 'Cookie lifetime (days)', 'wp-affiliate-captain' ), $s );
		self::select( 'touch_model', __( 'Link attribution', 'wp-affiliate-captain' ), $s, array( 'last' => __( 'Last touch', 'wp-affiliate-captain' ), 'first' => __( 'First touch', 'wp-affiliate-captain' ) ) );
		self::checkbox( 'coupon_priority', __( 'Affiliate coupon has priority over partner links', 'wp-affiliate-captain' ), $s );
		self::select( 'self_referral', __( 'Self-referrals', 'wp-affiliate-captain' ), $s, array( 'block' => __( 'Block', 'wp-affiliate-captain' ), 'review' => __( 'Flag for review', 'wp-affiliate-captain' ) ) );
		self::end();

		self::section( __( 'Fraud rules', 'wp-affiliate-captain' ) );
		echo '<tr><td colspan="2" class="description">' . esc_html__( 'Matches do not block a sale: its commissions get the status "needs review" with the reason. 0 = rule off.', 'wp-affiliate-captain' ) . '</td></tr>';
		self::number( 'fraud_max_orders_day', __( 'Max. orders per partner in 24 hours', 'wp-affiliate-captain' ), $s );
		echo '<tr><th><label for="wpac-fraud_max_order">' . esc_html__( 'Review orders above (net)', 'wp-affiliate-captain' ) . '</label></th><td><input type="text" id="wpac-fraud_max_order" name="wpac[fraud_max_order]" value="' . esc_attr( Money::to_decimal( (int) $s['fraud_max_order_minor'], $currency ) ) . '" class="small-text"> ' . esc_html( $currency ) . '</td></tr>';
		self::number( 'fraud_min_click_seconds', __( 'Min. seconds between click and order', 'wp-affiliate-captain' ), $s );
		self::number( 'fraud_max_refund_rate', __( 'Max. refund rate of a partner in % (90 days, min. 5 orders)', 'wp-affiliate-captain' ), $s );
		self::checkbox( 'fraud_check_domains', __( 'Review orders with disposable or blocked e-mail domains', 'wp-affiliate-captain' ), $s );
		echo '<tr><th><label for="wpac-fraud_blocked_domains">' . esc_html__( 'Additional blocked domains', 'wp-affiliate-captain' ) . '</label></th><td><textarea id="wpac-fraud_blocked_domains" name="wpac[fraud_blocked_domains]" rows="3" class="regular-text code">' . esc_textarea( (string) $s['fraud_blocked_domains'] ) . '</textarea></td></tr>';
		self::end();

		self::section( __( 'Abuse protection', 'wp-affiliate-captain' ) );
		self::number( 'rate_limit_per_minute', __( 'Max. link visits per client and minute', 'wp-affiliate-captain' ), $s );
		self::number( 'visit_retention_days', __( 'Keep visit data (days)', 'wp-affiliate-captain' ), $s );
		self::end();

		self::section( __( 'Commissions', 'wp-affiliate-captain' ) );
		self::text( 'currency', __( 'Program currency', 'wp-affiliate-captain' ), $s );
		echo '<tr><th><label for="wpac-exchange_rates">' . esc_html__( 'Reference exchange rates', 'wp-affiliate-captain' ) . '</label></th><td><textarea id="wpac-exchange_rates" name="wpac[exchange_rates]" rows="3" class="regular-text code" placeholder="USD=0.92&#10;CHF=1.05">' . esc_textarea( (string) $s['exchange_rates'] ) . '</textarea><p class="description">' . esc_html( sprintf( /* translators: %s: currency */ __( 'One per line: 1 unit of the currency = x %s. Commissions and payouts are never converted; rates are only used for reports and the minimum payout of other currencies.', 'wp-affiliate-captain' ), $currency ) ) . '</p></td></tr>';
		self::select( 'default_rate_type', __( 'Default commission type', 'wp-affiliate-captain' ), $s, array( 'percent' => __( 'Percent of net', 'wp-affiliate-captain' ), 'fixed' => __( 'Fixed amount per unit', 'wp-affiliate-captain' ) ) );
		$display = $s;
		if ( 'fixed' === $s['default_rate_type'] ) {
			$display['default_rate'] = Money::to_decimal( (int) $s['default_rate'], $currency );
		}
		self::text( 'default_rate', __( 'Default rate', 'wp-affiliate-captain' ), $display, __( 'Percent, or amount in program currency for fixed commissions. Use rules for partners, groups, products and course codes.', 'wp-affiliate-captain' ) );
		self::checkbox( 'lifetime_enabled', __( 'Lifetime commissions: repeat purchases of referred customers go to the same partner', 'wp-affiliate-captain' ), $s, __( 'Only applies when no new partner link, code or coupon is used. Customers are stored as keyed hashes only.', 'wp-affiliate-captain' ) );
		self::number( 'lifetime_days', __( 'Lifetime link duration (days, 0 = unlimited)', 'wp-affiliate-captain' ), $s );
		self::text( 'lifetime_rate', __( 'Lifetime rate in % (empty = normal rules)', 'wp-affiliate-captain' ), $s );
		self::number( 'hold_days', __( 'Hold period (days after payment)', 'wp-affiliate-captain' ), $s, __( 'Commissions are released after this period. Integrations can extend it per order (e.g. guarantee end) via hold_until / wpac_hold_until.', 'wp-affiliate-captain' ) );
		self::end();

		self::section( __( 'Promotion', 'wp-affiliate-captain' ) );
		/* translators: %s: example URL */
		self::text( 'landing_prefix', __( 'Landing page path', 'wp-affiliate-captain' ), $s, sprintf( __( 'Partner landing URLs look like %s', 'wp-affiliate-captain' ), home_url( '/' . $s['landing_prefix'] . '/partner-slug/' ) ) );
		self::page_select( 'landing_page_id', __( 'Default landing page', 'wp-affiliate-captain' ), $s, __( 'Add [wpac_partner_welcome] to show "Recommended by …".', 'wp-affiliate-captain' ) );
		self::text( 'default_disclosure', __( 'Default disclosure note', 'wp-affiliate-captain' ), $s );
		echo '<tr><th><label for="wpac-promotion_guidelines">' . esc_html__( 'Promotion guidelines for partners', 'wp-affiliate-captain' ) . '</label></th><td><textarea id="wpac-promotion_guidelines" name="wpac[promotion_guidelines]" rows="6" class="large-text">' . esc_textarea( (string) $s['promotion_guidelines'] ) . '</textarea><p class="description">' . esc_html__( 'Allowed claims, labelling duty, prohibited channels, how to report problems. Shown in the partner area.', 'wp-affiliate-captain' ) . '</p></td></tr>';
		self::end();

		self::section( __( 'Payouts', 'wp-affiliate-captain' ) );
		echo '<tr><th><label for="wpac-min_payout">' . esc_html__( 'Minimum payout', 'wp-affiliate-captain' ) . '</label></th><td><input type="text" id="wpac-min_payout" name="wpac[min_payout]" value="' . esc_attr( Money::to_decimal( (int) $s['min_payout_minor'], $currency ) ) . '" class="small-text"> ' . esc_html( $currency ) . '</td></tr>';
		self::select( 'payout_period', __( 'Payout period', 'wp-affiliate-captain' ), $s, array( 'monthly' => __( 'Monthly', 'wp-affiliate-captain' ), 'quarterly' => __( 'Quarterly', 'wp-affiliate-captain' ), 'manual' => __( 'Manual', 'wp-affiliate-captain' ) ) );
		self::checkbox( 'payout_requests', __( 'Partners can request payouts in the partner area', 'wp-affiliate-captain' ), $s );
		self::number( 'statement_retention_years', __( 'Keep statements (years, 0 = forever)', 'wp-affiliate-captain' ), $s, __( 'Counted from the end of the calendar year. After that, the PDF and the partner data snapshot are deleted; amounts remain. Check your statutory retention period (e.g. §147 AO).', 'wp-affiliate-captain' ) );
		self::text( 'debtor_name', __( 'Company account holder (SEPA)', 'wp-affiliate-captain' ), $s );
		self::text( 'debtor_iban', __( 'Company IBAN (SEPA)', 'wp-affiliate-captain' ), $s );
		self::text( 'debtor_bic', __( 'Company BIC (optional)', 'wp-affiliate-captain' ), $s );
		self::text( 'remittance_prefix', __( 'Remittance text prefix', 'wp-affiliate-captain' ), $s );
		self::end();

		self::section( __( 'Reporting', 'wp-affiliate-captain' ) );
		self::checkbox( 'report_admin', __( 'Monthly summary e-mail to the operator', 'wp-affiliate-captain' ), $s );
		self::checkbox( 'report_partners', __( 'Monthly summary e-mail to active partners', 'wp-affiliate-captain' ), $s, __( 'Sent on the first days of the month for the previous month.', 'wp-affiliate-captain' ) );
		self::end();

		self::section( __( 'Housekeeping', 'wp-affiliate-captain' ) );
		self::checkbox( 'delete_on_uninstall', __( 'Delete all data when the plugin is deleted', 'wp-affiliate-captain' ), $s, __( 'Includes commission and payout records – make sure bookkeeping retention obligations are met.', 'wp-affiliate-captain' ) );
		self::end();

		submit_button();
		echo '</form></div>';
	}

	/**
	 * Section start.
	 *
	 * @param string $title Title.
	 */
	private static function section( $title ) {
		echo '<h2>' . esc_html( $title ) . '</h2><table class="form-table">';
	}

	/** Section end. */
	private static function end() {
		echo '</table>';
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
		printf( '<tr><th><label for="wpac-%1$s">%2$s</label></th><td><input type="text" id="wpac-%1$s" name="wpac[%1$s]" value="%3$s" class="regular-text">', esc_attr( $key ), esc_html( $label ), esc_attr( (string) $s[ $key ] ) );
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
		printf( '<tr><th><label for="wpac-%1$s">%2$s</label></th><td><input type="number" min="0" id="wpac-%1$s" name="wpac[%1$s]" value="%3$s" class="small-text">', esc_attr( $key ), esc_html( $label ), esc_attr( (string) $s[ $key ] ) );
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
		printf( '<tr><th>%2$s</th><td><label><input type="checkbox" name="wpac[%1$s]" value="1" %3$s> %2$s</label>', esc_attr( $key ), esc_html( $label ), checked( (int) $s[ $key ], 1, false ) );
		self::desc( $desc );
		echo '</td></tr>';
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
		printf( '<tr><th><label for="wpac-%1$s">%2$s</label></th><td><select id="wpac-%1$s" name="wpac[%1$s]">', esc_attr( $key ), esc_html( $label ) );
		foreach ( $options as $v => $l ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $v ), selected( (string) $s[ $key ], (string) $v, false ), esc_html( $l ) );
		}
		echo '</select></td></tr>';
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
