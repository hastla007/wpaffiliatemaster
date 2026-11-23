<?php
/**
 * SET-AC-01 / SET-AC-05 (settings register and add-on rows): the register is the one source of keys,
 * defaults, tabs, labels and help texts; stored values keep their meaning; add-on rows and notes on
 * the settings page (notes are text only). Included from run.php after r8-render.php (r_render()).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Admin\AddonHints;
use WPAffiliateCaptain\Admin\Pages\SettingsPage;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Core\SettingsSchema;

echo "SET-AC-01/05 settings register and add-on rows\n";

$set_get = $_GET;
$set_caps = $GLOBALS['test_caps'] ?? null;
unset( $GLOBALS['test_caps'] );
SettingsSchema::flush();

// Defaults of 0.19.0 (before the register): unchanged, plus the new key addon_hints.
$set_before = array( 'referral_param' => 'partner', 'touch_model' => 'last', 'cookie_mode' => 'consent', 'cookie_days' => 30, 'use_wc_session' => 1, 'checkout_field' => 1, 'renewal_commission' => 1, 'js_tracking' => 0, 'cookie_domain' => '', 'visit_log_percent' => 100, 'coupon_priority' => 1, 'self_referral' => 'block', 'rate_limit_per_minute' => 30, 'trusted_proxies' => '', 'visit_retention_days' => 90, 'audit_retention_days' => 1095, 'application_retention_days' => 180, 'pending_application_days' => 365, 'currency' => 'EUR', 'exchange_rates' => '', 'default_rate_type' => 'percent', 'default_rate' => '10', 'hold_days' => 30, 'lifetime_enabled' => 0, 'lifetime_days' => 365, 'lifetime_rate' => '', 'hold_start' => 'paid', 'renewal_limit_months' => 0, 'rule_precedence' => 'scope', 'auto_approve' => 0, 'terms_page_id' => 0, 'terms_version' => '1.0', 'portal_page_id' => 0, 'registration_page_id' => 0, 'terminated_open' => 'pay', 'code_requests' => 1, 'admin_email' => '', 'mail_from_name' => '', 'mail_from_email' => '', 'notify_payout_paid' => 1, 'notify_commissions' => 0, 'notify_admin_review' => 0, 'maintenance_mode' => 0, 'monitor_token' => '', 'landing_prefix' => 'partner', 'landing_page_id' => 0, 'default_disclosure' => 'Advertisement', 'promotion_guidelines' => '', 'embed_disclosure_label' => 1, 'auto_apply_coupon' => 0, 'min_payout_minor' => 5000, 'payout_requests' => 0, 'statement_retention_years' => 10, 'retention_hold' => 0, 'payout_period' => 'monthly', 'payout_method_manual' => 1, 'debtor_name' => '', 'debtor_iban' => '', 'debtor_bic' => '', 'remittance_prefix' => 'Provision', 'self_billing' => 1, 'vat_rate' => '19', 'operator_country' => 'DE', 'operator_address' => '', 'operator_vat_id' => '', 'operator_tax_number' => '', 'credit_prefix' => 'GS', 'storno_prefix' => 'ST', 'correction_prefix' => 'KG', 'einvoice_mail' => 'auto', 'einvoice_buyer_reference' => '', 'vies_check' => 0, 'fraud_max_orders_day' => 0, 'fraud_max_order_minor' => 0, 'fraud_min_click_seconds' => 0, 'fraud_max_refund_rate' => 0, 'fraud_check_domains' => 1, 'fraud_blocked_domains' => '', 'reauth_required' => 1, 'report_admin' => 1, 'report_partners' => 0, 'delete_on_uninstall' => 0 );
$set_now    = SettingsSchema::defaults();
check( 'SET-AC-01 defaults: the 82 keys of 0.19.0 keep their order and values; new: addon_hints = 1', array( $set_before, 1 ), array( array_diff_key( $set_now, array( 'addon_hints' => 1 ) ), $set_now['addon_hints'] ) );
check( 'SET-AC-01 Settings::defaults() is the register', SettingsSchema::defaults(), Settings::defaults() );

// Every key: label and help text; every key on exactly one tab (hidden keys on none).
$set_missing = array();
$set_tabbed  = array();
foreach ( SettingsSchema::core() as $set_k => $set_f ) {
	if ( '' === trim( $set_f['label'] ) || '' === trim( $set_f['help'] ) ) {
		$set_missing[] = $set_k;
	}
}
foreach ( SettingsPage::tab_keys() as $set_tab => $set_keys ) {
	foreach ( $set_keys as $set_k ) {
		$set_tabbed[] = $set_k;
	}
}
$set_hidden = array_keys( array_filter( SettingsSchema::core(), static function ( $f ) { return ! empty( $f['hidden'] ); } ) );
check( 'SET-AC-01 every key has a label and a help text', array(), $set_missing );
check( 'SET-AC-01 every key on exactly one tab (only payout_period hidden)', array( array(), array(), array( 'payout_period' ) ), array( array_values( array_diff( array_keys( SettingsSchema::core() ), $set_tabbed, $set_hidden ) ), array_values( array_diff_assoc( $set_tabbed, array_unique( $set_tabbed ) ) ), $set_hidden ) );
$set_im = array_intersect_key( SettingsSchema::int_max(), Settings::INT_MAX );
$set_ic = Settings::INT_MAX;
ksort( $set_im );
ksort( $set_ic );
check( 'SET-AC-01 INT_MAX of Settings equals the register limits', $set_ic, $set_im );

// Every key is shown on its tab with the register label and help text (form field name).
$set_not_shown = array();
foreach ( SettingsPage::tab_keys() as $set_tab => $set_keys ) {
	$_GET  = array( 'page' => 'wpac-settings', 'tab' => $set_tab );
	$set_r = r_render( array( SettingsPage::class, 'render' ) );
	foreach ( $set_keys as $set_k ) {
		$set_name = array( 'fraud_max_order_minor' => 'fraud_max_order', 'min_payout_minor' => 'min_payout' )[ $set_k ] ?? $set_k;
		if ( false === strpos( $set_r[0], 'name="wpac[' . $set_name . ']"' ) || false === strpos( $set_r[0], esc_html( SettingsSchema::label( $set_k ) ) ) ) {
			$set_not_shown[] = $set_tab . ':' . $set_k;
		}
	}
}
check( 'SET-AC-01 every key appears on its tab with the register label', array(), $set_not_shown );
$_GET   = array( 'page' => 'wpac-settings', 'tab' => 'tracking' );
$set_tr = r_render( array( SettingsPage::class, 'render' ) );
check( 'SET-AC-25 a field without its own text on the page shows the register help (touch_model, coupon_priority)', array( true, true ), array( false !== strpos( $set_tr[0], esc_html( SettingsSchema::help( 'touch_model' ) ) ), false !== strpos( $set_tr[0], esc_html( SettingsSchema::help( 'coupon_priority' ) ) ) ) );

// Stored values keep their meaning: saving the stored settings again changes nothing.
$set_all   = Settings::all();
$set_clean = Settings::sanitize( $set_all, $set_err );
$set_skip  = array( 'monitor_token' => 1, 'min_payout_minor' => 1, 'fraud_max_order_minor' => 1 ); // Entered as token / decimal amount.
check( 'SET-AC-01 stored settings sanitize to themselves (no error, no change)', array( array(), array() ), array( $set_err, array_keys( array_diff_assoc( array_map( 'strval', array_diff_key( $set_clean, $set_skip ) ), array_map( 'strval', array_diff_key( $set_all, $set_skip ) ) ) ) ) );

// Filter wpac_settings_schema: add-on fields need their own option; core keys stay untouched.
$set_filter = static function ( $fields ) {
	$fields['hold_days']      = array( 'tab' => 'data', 'label' => 'X', 'option' => 'evil', 'default' => 1 );
	$fields['pro_webhook_x']  = array( 'tab' => 'data', 'label' => 'Webhook retries', 'option' => 'wpac_pro_webhooks', 'default' => 5, 'type' => 'int' );
	$fields['pro_no_option']  = array( 'tab' => 'data', 'label' => 'No option' );
	return $fields;
};
add_filter( 'wpac_settings_schema', $set_filter );
SettingsSchema::flush();
check( 'SET-AC-01 wpac_settings_schema: add-on field with own option accepted, core key and field without option ignored', array( array( 'pro_webhook_x' ), 'wpac_pro_webhooks', 30 ), array( array_keys( SettingsSchema::addon_fields() ), SettingsSchema::addon_fields()['pro_webhook_x']['option'], SettingsSchema::field( 'hold_days' )['default'] ) );
remove_filter( 'wpac_settings_schema', $set_filter );
SettingsSchema::flush();

// SET-AC-05: notes at the end of the tab, text only; an add-on row with the same id replaces the note.
$_GET  = array( 'page' => 'wpac-settings', 'tab' => 'data' );
$set_d = r_render( array( SettingsPage::class, 'render' ) );
$set_notes_html = (string) strstr( $set_d[0], '<table class="form-table wpac-addon-notes"' );
$set_notes_html = (string) substr( $set_notes_html, 0, (int) strpos( $set_notes_html, '</table>' ) );
check( 'SET-AC-05 data tab: notes "Webhooks" and "Extended REST API" with Pro mark, no form field in the notes, no PHP problems', array( true, true, true, false, false, array(), '' ), array( false !== strpos( $set_notes_html, 'Webhooks' ), false !== strpos( $set_notes_html, 'Extended REST API' ), false !== strpos( $set_notes_html, 'wpac-pro-mark' ), false !== strpos( $set_notes_html, 'name=' ), false !== strpos( $set_notes_html, '<input' ), $set_d[1], $set_d[2] ) );
$set_row = static function ( $rows, $tab ) {
	$rows[] = array( 'id' => 'webhooks', 'tab' => 'data', 'title' => 'Webhooks (add-on)', 'render' => static function () { echo '<input type="number" name="wpac_pro[webhook_retries]" value="5">'; } );
	return $rows;
};
add_filter( 'wpac_settings_rows', $set_row, 10, 2 );
$set_d2 = r_render( array( SettingsPage::class, 'render' ) );
$set_n2 = (string) strstr( $set_d2[0], '<table class="form-table wpac-addon-notes"' );
check( 'SET-AC-05 an add-on row with the id "webhooks" replaces the note and shows its field; the other note stays', array( true, false, true ), array( false !== strpos( $set_d2[0], 'name="wpac_pro[webhook_retries]"' ), false !== strpos( (string) substr( $set_n2, 0, (int) strpos( $set_n2, '</table>' ) ), '>Webhooks<' ) || false !== strpos( (string) substr( $set_n2, 0, (int) strpos( $set_n2, '</table>' ) ), '</span> Webhooks</th>' ), false !== strpos( $set_n2, 'Extended REST API' ) ) );
remove_filter( 'wpac_settings_rows', $set_row, 10 );
Settings::update( array( 'addon_hints' => 0 ) );
$set_d3 = r_render( array( SettingsPage::class, 'render' ) );
$set_true  = static function () { return true; };
$set_false = static function () { return false; };
add_filter( 'wpac_show_addon_hints', $set_true );
$set_d4 = r_render( array( SettingsPage::class, 'render' ) );
remove_filter( 'wpac_show_addon_hints', $set_true );
Settings::update( array( 'addon_hints' => 1 ) );
add_filter( 'wpac_show_addon_hints', $set_false );
$set_d5 = r_render( array( SettingsPage::class, 'render' ) );
remove_filter( 'wpac_show_addon_hints', $set_false );
check( 'SET-AC-05 notes off by the setting, the filter wins in both directions', array( false, true, false ), array( false !== strpos( $set_d3[0], 'wpac-addon-notes' ), false !== strpos( $set_d4[0], 'wpac-addon-notes' ), false !== strpos( $set_d5[0], 'wpac-addon-notes' ) ) );
$set_tabs_with_notes = array();
foreach ( AddonHints::notes() as $set_n ) {
	$set_tabs_with_notes[ $set_n['tab'] ] = true;
}
check( 'SET-AC-05 built-in notes only on existing tabs', array(), array_values( array_diff( array_keys( $set_tabs_with_notes ), array_keys( SettingsPage::tab_keys() ) ) ) );

$_GET = $set_get;
if ( null !== $set_caps ) {
	$GLOBALS['test_caps'] = $set_caps;
}
