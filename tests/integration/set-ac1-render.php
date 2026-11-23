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

// Defaults of 0.19.0 (before the register): unchanged, plus the new key addon_hints (payout_period removed by SET-AC-31).
$set_before = array( 'referral_param' => 'partner', 'touch_model' => 'last', 'cookie_mode' => 'consent', 'cookie_days' => 30, 'use_wc_session' => 1, 'checkout_field' => 1, 'renewal_commission' => 1, 'js_tracking' => 0, 'cookie_domain' => '', 'visit_log_percent' => 100, 'coupon_priority' => 1, 'self_referral' => 'block', 'rate_limit_per_minute' => 30, 'trusted_proxies' => '', 'visit_retention_days' => 90, 'audit_retention_days' => 1095, 'application_retention_days' => 180, 'pending_application_days' => 365, 'currency' => 'EUR', 'exchange_rates' => '', 'default_rate_type' => 'percent', 'default_rate' => '10', 'hold_days' => 30, 'lifetime_enabled' => 0, 'lifetime_days' => 365, 'lifetime_rate' => '', 'hold_start' => 'paid', 'renewal_limit_months' => 0, 'rule_precedence' => 'scope', 'auto_approve' => 0, 'terms_page_id' => 0, 'terms_version' => '1.0', 'portal_page_id' => 0, 'registration_page_id' => 0, 'terminated_open' => 'pay', 'code_requests' => 1, 'admin_email' => '', 'mail_from_name' => '', 'mail_from_email' => '', 'notify_payout_paid' => 1, 'notify_commissions' => 0, 'notify_admin_review' => 0, 'maintenance_mode' => 0, 'monitor_token' => '', 'landing_prefix' => 'partner', 'landing_page_id' => 0, 'default_disclosure' => 'Advertisement', 'promotion_guidelines' => '', 'embed_disclosure_label' => 1, 'auto_apply_coupon' => 0, 'min_payout_minor' => 5000, 'payout_requests' => 0, 'statement_retention_years' => 10, 'retention_hold' => 0, 'payout_method_manual' => 1, 'debtor_name' => '', 'debtor_iban' => '', 'debtor_bic' => '', 'remittance_prefix' => 'Provision', 'self_billing' => 1, 'vat_rate' => '19', 'operator_country' => 'DE', 'operator_address' => '', 'operator_vat_id' => '', 'operator_tax_number' => '', 'credit_prefix' => 'GS', 'storno_prefix' => 'ST', 'correction_prefix' => 'KG', 'einvoice_mail' => 'auto', 'einvoice_buyer_reference' => '', 'vies_check' => 0, 'fraud_max_orders_day' => 0, 'fraud_max_order_minor' => 0, 'fraud_min_click_seconds' => 0, 'fraud_max_refund_rate' => 0, 'fraud_check_domains' => 1, 'fraud_blocked_domains' => '', 'reauth_required' => 1, 'report_admin' => 1, 'report_partners' => 0, 'delete_on_uninstall' => 0 );
$set_now    = SettingsSchema::defaults();
$set_new_keys = array( 'addon_hints' => 1, 'approval_mode' => 'auto', 'code_requests_per_month' => 3, 'code_request_max_length' => 20, 'application_confirm_days' => 2, 'bank_change_wait_days' => 30, 'reauth_minutes' => 15, 'fraud_refund_window_days' => 90, 'fraud_refund_min_orders' => 5, 'privacy_requests_per_day' => 5, 'terms_reminder_days' => 7, 'claim_cancel_days' => 45, 'payout_request_note_days' => 60, 'vies_recheck_days' => 30, 'feature_creatives' => 1, 'feature_landing' => 1, 'feature_tiers' => 1, 'feature_qr' => 1, 'feature_broadcast' => 1, 'log_level' => 'notices', 'exchange_rates_date' => '', 'min_payout_currency' => '', 'dunning_levels' => 3, 'dunning_days' => 14, 'dunning_final_days' => 7, 'payout_reminder' => 'off', 'payout_reminder_day' => 1, 'max_payout_minor' => 0, 'credit_start' => 1, 'credit_digits' => 6, 'storno_start' => 1, 'storno_digits' => 6, 'correction_start' => 1, 'correction_digits' => 6 );
check( 'SET-AC-01 defaults: the 82 keys of 0.19.0 keep their order and values; new keys default to the former fixed values (S2)', array( $set_before, $set_new_keys ), array( array_diff_key( $set_now, $set_new_keys ), array_merge( $set_new_keys, array_intersect_key( $set_now, $set_new_keys ) ) ) );
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
check( 'SET-AC-01 every key on exactly one tab (no hidden key since SET-AC-31)', array( array(), array(), array() ), array( array_values( array_diff( array_keys( SettingsSchema::core() ), $set_tabbed, $set_hidden ) ), array_values( array_diff_assoc( $set_tabbed, array_unique( $set_tabbed ) ) ), $set_hidden ) );
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
		$set_name = array( 'fraud_max_order_minor' => 'fraud_max_order', 'min_payout_minor' => 'min_payout', 'max_payout_minor' => 'max_payout', 'exchange_rates' => 'fx_rows][0][rate', 'min_payout_currency' => 'fx_rows][0][min' )[ $set_k ] ?? $set_k; // Exchange rate table: SET-AC-29.
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
$set_skip  = array( 'monitor_token' => 1, 'min_payout_minor' => 1, 'max_payout_minor' => 1, 'fraud_max_order_minor' => 1 ); // Entered as token / decimal amount.
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
// P6-03: the built-in notes need a product address (only while the add-on is on sale).
$_GET  = array( 'page' => 'wpac-settings', 'tab' => 'data' );
$set_d0 = r_render( array( SettingsPage::class, 'render' ) );
check( 'P6-03 without a product address no built-in note is shown', false, false !== strpos( $set_d0[0], 'wpac-addon-notes' ) );
$set_url = static function () { return 'https://example.test/pro'; };
add_filter( 'wpac_addon_hint_url', $set_url );
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
// P6-04: switching the built-in notes off keeps the note of an installed add-on, without the Pro mark.
$set_row4 = static function ( $rows ) { $rows[] = array( 'id' => 'acme_sync', 'tab' => 'data', 'title' => 'ACME Sync', 'text' => 'Licence missing.' ); return $rows; };
add_filter( 'wpac_settings_rows', $set_row4 );
Settings::update( array( 'addon_hints' => 0 ) );
$set_d6 = r_render( array( SettingsPage::class, 'render' ) );
Settings::update( array( 'addon_hints' => 1 ) );
remove_filter( 'wpac_settings_rows', $set_row4, 10 );
check( 'P6-04 add-on note stays when the built-in notes are off, without Pro mark', array( true, false ), array( false !== strpos( $set_d6[0], 'ACME Sync' ), false !== strpos( $set_d6[0], 'wpac-pro-mark' ) ) );
remove_filter( 'wpac_addon_hint_url', $set_url );
$set_tabs_with_notes = array();
foreach ( AddonHints::notes() as $set_n ) {
	$set_tabs_with_notes[ $set_n['tab'] ] = true;
}
check( 'SET-AC-05 built-in notes only on existing tabs', array(), array_values( array_diff( array_keys( $set_tabs_with_notes ), array_keys( SettingsPage::tab_keys() ) ) ) );

// SET-AC-11: the audit log keeps old and new value per setting; secret and private values are redacted.
$set_av = SettingsPage::audit_values( array( 'hold_days', 'debtor_iban', 'monitor_token', 'admin_email', 'promotion_guidelines' ), array( 'hold_days' => 30, 'debtor_iban' => 'DE02120300000000202051', 'monitor_token' => 'h1:abc', 'admin_email' => 'old@example.de', 'promotion_guidelines' => str_repeat( 'x', 200 ) ), array( 'hold_days' => 14, 'debtor_iban' => 'DE89370400440532013000', 'monitor_token' => 'h1:def', 'admin_email' => 'new@example.de', 'promotion_guidelines' => 'kurz' ) );
check(
	'SET-AC-11 old → new per setting; IBAN, token and e-mail address redacted; long text shortened',
	array( array( 'from' => '30', 'to' => '14' ), array( 'from' => '[redacted]', 'to' => '[redacted]' ), array( 'from' => '[redacted]', 'to' => '[redacted]' ), array( 'from' => '[redacted]', 'to' => '[redacted]' ), 118, false ),
	array( $set_av['hold_days'], $set_av['debtor_iban'], $set_av['monitor_token'], $set_av['admin_email'], mb_strlen( $set_av['promotion_guidelines']['from'] ), false !== strpos( wp_json_encode( $set_av ), 'DE89' ) || false !== strpos( wp_json_encode( $set_av ), 'example.de' ) )
);
$set_text = \WPAffiliateCaptain\Admin\Pages\AuditPage::details_text( wp_json_encode( array( 'changed' => array( 'hold_days' ), 'values' => array( 'hold_days' => $set_av['hold_days'] ) ) ) );
check( 'SET-AC-11 audit log shows "Hold period …: 30 → 14" (field label, not the key)', true, false !== strpos( $set_text, SettingsSchema::label( 'hold_days' ) . ': 30 → 14' ) );

// SET-AC-12/13/14 (S2): business rules are settings; the old filters still win.
$set_rules = array( WPAffiliateCaptain\Codes\CodeRequests::per_month( 1 ), WPAffiliateCaptain\Codes\CodeRequests::max_length(), WPAffiliateCaptain\Admin\Reauth::ttl(), WPAffiliateCaptain\Affiliates\AffiliateRepository::access_change_days(), WPAffiliateCaptain\Affiliates\Registration::confirm_ttl(), WPAffiliateCaptain\Payouts\PayoutService::claim_cancel_after() );
Settings::update( array( 'code_requests_per_month' => 1, 'code_request_max_length' => 12, 'reauth_minutes' => 5, 'bank_change_wait_days' => 10, 'application_confirm_days' => 3, 'claim_cancel_days' => 60 ) );
$set_rules2 = array( WPAffiliateCaptain\Codes\CodeRequests::per_month( 1 ), WPAffiliateCaptain\Codes\CodeRequests::max_length(), WPAffiliateCaptain\Admin\Reauth::ttl(), WPAffiliateCaptain\Affiliates\AffiliateRepository::access_change_days(), WPAffiliateCaptain\Affiliates\Registration::confirm_ttl(), WPAffiliateCaptain\Payouts\PayoutService::claim_cancel_after() );
$set_f = static function () { return 7; };
add_filter( 'wpac_code_requests_per_month', $set_f );
add_filter( 'wpac_access_change_days', $set_f );
$set_rules3 = array( WPAffiliateCaptain\Codes\CodeRequests::per_month( 1 ), WPAffiliateCaptain\Affiliates\AffiliateRepository::access_change_days() );
remove_filter( 'wpac_code_requests_per_month', $set_f );
remove_filter( 'wpac_access_change_days', $set_f );
Settings::update( array_intersect_key( SettingsSchema::defaults(), array_flip( array( 'code_requests_per_month', 'code_request_max_length', 'reauth_minutes', 'bank_change_wait_days', 'application_confirm_days', 'claim_cancel_days' ) ) ) );
check(
	'SET-AC-12/13 defaults = former fixed values; the settings change them; the old filters still win',
	array( array( 3, 20, 15 * MINUTE_IN_SECONDS, 30, 2 * DAY_IN_SECONDS, 45 * DAY_IN_SECONDS ), array( 1, 12, 5 * MINUTE_IN_SECONDS, 10, 3 * DAY_IN_SECONDS, 60 * DAY_IN_SECONDS ), array( 7, 7 ) ),
	array( $set_rules, $set_rules2, $set_rules3 )
);
$set_dom = static function ( $d ) { $d[] = 'wegwerf.example'; return $d; };
add_filter( 'wpac_disposable_domains', $set_dom );
$set_blocked = WPAffiliateCaptain\Tracking\FraudCheck::domain_blocked( 'x@wegwerf.example' );
remove_filter( 'wpac_disposable_domains', $set_dom );
check( 'SET-AC-14 filter wpac_disposable_domains extends the built-in list', array( true, false ), array( $set_blocked, WPAffiliateCaptain\Tracking\FraudCheck::domain_blocked( 'x@wegwerf.example' ) ) );

// SET-AC-23: approval mode "manual" releases nothing automatically; "auto" releases due commissions as before.
$set_aid = (int) WPAffiliateCaptain\Affiliates\AffiliateRepository::create( array( 'name' => 'SET23 Partner', 'email' => 'set23@example.de', 'status' => 'active' ) );
WPAffiliateCaptain\Codes\CodeRepository::create( $set_aid, 'SET23CODE' );
$set_sale = wpac_record_sale( array( 'source' => 'set23', 'order_id' => 'set23-1', 'currency' => 'EUR', 'partner_code' => 'SET23CODE', 'items' => array( array( 'item_id' => '1', 'product_id' => 10, 'name' => 'Kurs', 'net_minor' => 10000 ) ), 'customer' => array( 'user_id' => 0, 'email' => 'k23@example.de', 'name' => 'K' ) ) );
$set_cid  = is_array( $set_sale ) && ! empty( $set_sale['commission_ids'] ) ? (int) $set_sale['commission_ids'][0] : 0;
$GLOBALS['wpdb']->query( 'UPDATE ' . t( 'commissions' ) . " SET status = 'pending', hold_until = '2000-01-01 00:00:00' WHERE id = " . $set_cid );
Settings::update( array( 'approval_mode' => 'manual' ) );
WPAffiliateCaptain\Commissions\Ledger::approve_due();
$set_manual = (string) WPAffiliateCaptain\Commissions\Ledger::find( $set_cid )->status;
Settings::update( array( 'approval_mode' => 'auto' ) );
WPAffiliateCaptain\Commissions\Ledger::approve_due();
check( 'SET-AC-23 manual mode: a due commission stays pending; auto mode: it is approved', array( true, 'pending', 'approved' ), array( $set_cid > 0, $set_manual, (string) WPAffiliateCaptain\Commissions\Ledger::find( $set_cid )->status ) );

// SET-AC-08/09/10: export, import (with preview) and reset share one logic (SettingsTransfer).
use WPAffiliateCaptain\Core\SettingsTransfer;
Settings::update( array( 'hold_days' => 21, 'debtor_iban' => 'DE89370400440532013000', 'cookie_days' => 60 ) );
$set_ex  = SettingsTransfer::export();
$set_exp = SettingsTransfer::export( true );
check(
	'SET-AC-08 export: format 1, plugin version, no secret token, private values only on request',
	array( 1, WPAC_VERSION, false, false, true, 21 ),
	array( $set_ex['format'], $set_ex['plugin'], array_key_exists( 'monitor_token', $set_ex['settings'] ), array_key_exists( 'debtor_iban', $set_ex['settings'] ), array_key_exists( 'debtor_iban', $set_exp['settings'] ), $set_ex['settings']['hold_days'] )
);
Settings::update( array( 'hold_days' => 30, 'cookie_days' => 30 ) );
$set_pv = SettingsTransfer::preview( $set_ex );
check( 'SET-AC-08 import preview: old → new for the changed keys, nothing stored yet', array( array( 'cookie_days' => array( 30, 60 ), 'hold_days' => array( 30, 21 ) ), 30 ), array( array_intersect_key( $set_pv['changes'], array( 'hold_days' => 1, 'cookie_days' => 1 ) ), (int) Settings::get( 'hold_days' ) ) );
SettingsTransfer::apply( $set_pv, 'settings.imported' );
check( 'SET-AC-08 export → import gives the same values', array( 21, 60 ), array( (int) Settings::get( 'hold_days' ), (int) Settings::get( 'cookie_days' ) ) );
$set_bad = SettingsTransfer::preview( array( 'format' => 1, 'settings' => array( 'hold_days' => 'viele', 'cookie_mode' => 'sometimes', 'unknown_key' => 1, 'monitor_token' => 'x' ) ) );
check( 'SET-AC-08 invalid values are reported and change nothing; unknown and secret keys are ignored', array( true, false, false, array( 'monitor_token', 'unknown_key' ) ), array( isset( $set_bad['errors']['hold_days'] ), isset( $set_bad['values']['hold_days'] ), isset( $set_bad['values']['cookie_mode'] ) && 'sometimes' === $set_bad['values']['cookie_mode'], array_values( array_intersect( array( 'monitor_token', 'unknown_key' ), $set_bad['ignored'] ) ) ) );
check( 'SET-AC-08 not an export / newer format: error, nothing changed', array( 'wpac_import_format', 'wpac_import_format' ), array( SettingsTransfer::preview( array( 'foo' => 1 ) )->get_error_code(), SettingsTransfer::preview( array( 'format' => 99, 'settings' => array() ) )->get_error_code() ) );
$set_rk = SettingsTransfer::reset_keys( 'payouts' );
check( 'SET-AC-09 reset of a tab never touches currency, number prefixes, private, secret or legal values (vat_rate kept since P1-05); other values are reset', array( false, false, false, false, true ), array( in_array( 'credit_prefix', $set_rk, true ), in_array( 'debtor_iban', $set_rk, true ), in_array( 'currency', SettingsTransfer::reset_keys(), true ), in_array( 'vat_rate', $set_rk, true ), in_array( 'min_payout_minor', $set_rk, true ) ) );
$set_rp = SettingsTransfer::reset_preview( SettingsTransfer::reset_keys( 'commissions' ) );
SettingsTransfer::apply( $set_rp, 'settings.reset' );
check( 'SET-AC-09 reset of the commissions tab: hold period back to 30, cookie lifetime (other tab) unchanged', array( 30, 60 ), array( (int) Settings::get( 'hold_days' ), (int) Settings::get( 'cookie_days' ) ) );
Settings::update( array( 'cookie_days' => 30, 'debtor_iban' => '' ) );

// SET-AC-08/09 on the page: export/import on "Data & maintenance", reset with preview on every tab.
$_GET  = array( 'page' => 'wpac-settings', 'tab' => 'data' );
$set_t = r_render( array( SettingsPage::class, 'render' ) );
$_GET  = array( 'page' => 'wpac-settings', 'tab' => 'tracking' );
$set_u = r_render( array( SettingsPage::class, 'render' ) );
set_transient( 'wpac_settings_pending_' . get_current_user_id(), array( 'kind' => 'reset', 'tab' => 'tracking', 'check' => SettingsTransfer::reset_preview( array( 'cookie_days' ) ) + array( 'ignored' => array() ) ), 3600 );
Settings::update( array( 'cookie_days' => 45 ) );
set_transient( 'wpac_settings_pending_' . get_current_user_id(), array( 'kind' => 'reset', 'tab' => 'tracking', 'check' => SettingsTransfer::reset_preview( array( 'cookie_days' ) ) + array( 'ignored' => array() ) ), 3600 );
$_GET  = array( 'page' => 'wpac-settings', 'tab' => 'data' );
$set_v = r_render( array( SettingsPage::class, 'render' ) );
delete_transient( 'wpac_settings_pending_' . get_current_user_id() );
Settings::update( array( 'cookie_days' => 30 ) );
check(
	'SET-AC-08/09 page: export and import forms on Data & maintenance, reset button on other tabs, pending reset shown as now → new with Apply',
	array( true, true, true, false, true, true, true, array(), '' ),
	array( false !== strpos( $set_t[0], 'value="settings_export"' ), false !== strpos( $set_t[0], 'name="settings_file"' ), false !== strpos( $set_u[0], 'value="settings_reset_preview"' ), false !== strpos( $set_u[0], 'value="settings_export"' ), false !== strpos( $set_v[0], 'id="wpac-settings-pending"' ), false !== strpos( $set_v[0], '<td>45</td><td>30</td>' ), false !== strpos( $set_v[0], 'value="settings_reset_apply"' ), array_merge( $set_t[1], $set_u[1], $set_v[1] ), $set_t[2] . $set_u[2] . $set_v[2] )
);

// SET-AC-21: permissions per role; administrators always keep everything.
if ( ! function_exists( 'wp_roles' ) ) {
	final class Set_Test_Role {
		public $name;
		public function __construct( $name ) { $this->name = $name; }
		public function add_cap( $cap ) { $GLOBALS['set_roles'][ $this->name ]['capabilities'][ $cap ] = true; }
		public function remove_cap( $cap ) { unset( $GLOBALS['set_roles'][ $this->name ]['capabilities'][ $cap ] ); }
	}
	final class Set_Test_Roles {
		public function __get( $k ) { return 'roles' === $k ? $GLOBALS['set_roles'] : null; }
		public function get_role( $r ) { return isset( $GLOBALS['set_roles'][ $r ] ) ? new Set_Test_Role( $r ) : null; }
	}
	function wp_roles() { return new Set_Test_Roles(); }
}
$GLOBALS['set_roles'] = array(
	'administrator' => array( 'name' => 'Administrator', 'capabilities' => array( 'wpac_manage_program' => true ) ),
	'editor'        => array( 'name' => 'Editor', 'capabilities' => array() ),
	'shop_manager'  => array( 'name' => 'Shop manager', 'capabilities' => array( 'wpac_manage_payouts' => true ) ),
);
$set_ch = WPAffiliateCaptain\Core\Capabilities::set_role_caps(
	array(
		'administrator' => array( 'wpac_manage_program' => false ),
		'editor'        => array( 'wpac_manage_affiliates' => true ),
		'shop_manager'  => array( 'wpac_manage_payouts' => false ),
	)
);
$set_mx = WPAffiliateCaptain\Core\Capabilities::role_matrix();
check(
	'SET-AC-21 role table: editor gets partners, shop manager loses payouts, administrator cannot be changed and is not listed',
	array( array( array( 'editor', 'wpac_manage_affiliates', true ), array( 'shop_manager', 'wpac_manage_payouts', false ) ), true, false, false, true ),
	array( $set_ch, $set_mx['editor']['caps']['wpac_manage_affiliates'], $set_mx['shop_manager']['caps']['wpac_manage_payouts'], isset( $set_mx['administrator'] ), ! empty( $GLOBALS['set_roles']['administrator']['capabilities']['wpac_manage_program'] ) )
);
$_GET  = array( 'page' => 'wpac-settings', 'tab' => 'data' );
$set_r = r_render( array( SettingsPage::class, 'render' ) );
check( 'SET-AC-21 the data tab shows the role table with one checkbox per role and permission', array( true, true, false ), array( false !== strpos( $set_r[0], 'name="wpac_roles[editor][wpac_manage_affiliates]" value="1" checked' ), false !== strpos( $set_r[0], 'value="roles_save"' ), false !== strpos( $set_r[0], 'wpac_roles[administrator]' ) ) );

$_GET = $set_get;
if ( null !== $set_caps ) {
	$GLOBALS['test_caps'] = $set_caps;
}
