<?php
/**
 * Findings of the settings review phase (SET-AC-P1 … P6): each block reproduces one finding.
 * Included from run.php after set-ac35.php.
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Core\SettingsSchema;
use WPAffiliateCaptain\Core\SettingsTransfer;

echo "SET-AC review findings\n";

$rv_saved = get_option( 'wpac_settings' );
$rv_set   = static function ( array $values ) use ( $rv_saved ) {
	update_option( 'wpac_settings', array_merge( Settings::all(), $values ) );
	Settings::flush_cache();
};
SettingsSchema::flush();

// P1-01: a fixed default rate (stored in minor units) survives export → import, CLI set and type changes.
$rv_set( array( 'currency' => 'EUR', 'default_rate_type' => 'fixed', 'default_rate' => '2500' ) );
$rv_round = SettingsTransfer::preview( SettingsTransfer::export() );
$rv_cli   = SettingsTransfer::check( array( 'default_rate' => '3000' ), Settings::all() );
$rv_set( array( 'default_rate_type' => 'percent', 'default_rate' => '10' ) );
$rv_type  = SettingsTransfer::check( array( 'default_rate_type' => 'fixed' ), Settings::all() );
$rv_both  = SettingsTransfer::check( array( 'default_rate_type' => 'fixed', 'default_rate' => '1500' ), Settings::all() );
check( 'P1-01 fixed default rate: round trip unchanged, CLI value in minor units, type only with the rate', array( array(), array(), array( 'default_rate' => '3000' ), true, array( 'default_rate_type' => 'fixed', 'default_rate' => '1500' ) ), array( $rv_round['changes'], $rv_round['errors'], $rv_cli['values'], isset( $rv_type['errors']['default_rate_type'] ), $rv_both['values'] ) );

// P1-02: words for switches; P1-03: an unknown choice is an error, the stored value stays.
$rv_set( array( 'maintenance_mode' => 1, 'touch_model' => 'first' ) );
$rv_b1 = SettingsTransfer::check( array( 'maintenance_mode' => 'false' ), Settings::all() );
$rv_b2 = SettingsTransfer::check( array( 'maintenance_mode' => 'foo' ), Settings::all() );
$rv_c1 = SettingsTransfer::check( array( 'touch_model' => 'firts' ), Settings::all() );
$rv_c2 = SettingsTransfer::check( array( 'cookie_mode' => 'Always' ), Settings::all() );
check( 'P1-02/P1-03 "false" switches off, "foo" and unknown choices are errors and change nothing', array( array( 'maintenance_mode' => 0 ), true, true, array(), true ), array( $rv_b1['values'], isset( $rv_b2['errors']['maintenance_mode'] ), isset( $rv_c1['errors']['touch_model'] ), $rv_c1['values'], isset( $rv_c2['errors']['cookie_mode'] ) ) );

// P1-05 / P3-02 / P1-09: a reset of a tab keeps legal values, an import never lifts a retention hold.
$rv_set( array( 'retention_hold' => 1, 'operator_country' => 'AT', 'vat_rate' => '20', 'statement_retention_years' => 0 ) );
$rv_rk_data = SettingsTransfer::reset_keys( 'data' );
$rv_rk_pay  = SettingsTransfer::reset_keys( 'payouts' );
$rv_imp     = SettingsTransfer::preview( array( 'format' => 1, 'settings' => array( 'retention_hold' => 0, 'hold_days' => 14 ) ) );
check( 'P1-05 reset keeps retention hold, retention years, country and VAT rate; import cannot lift the hold', array( false, false, false, false, true, array( 'hold_days' => 14 ) ), array( in_array( 'retention_hold', $rv_rk_data, true ), in_array( 'statement_retention_years', $rv_rk_data, true ), in_array( 'operator_country', $rv_rk_pay, true ), in_array( 'vat_rate', $rv_rk_pay, true ), isset( $rv_imp['errors']['retention_hold'] ), $rv_imp['values'] ) );

// P1-06 / P2-02 / P3-06: confirmation mail and privacy text name the configured validity.
$rv_set( array( 'application_confirm_days' => 7 ) );
list( , $rv_mail ) = \WPAffiliateCaptain\Affiliates\Notifications::render_template( 'confirm_application', array( 'link' => 'https://example.test/x', 'valid' => \WPAffiliateCaptain\Affiliates\Registration::confirm_ttl_text() ) );
\WPAffiliateCaptain\Privacy\Privacy::policy_text();
$rv_policy = (string) ( $GLOBALS['g4_policy'] ?? '' );
check( 'P1-06 confirmation validity from the setting in mail and privacy text', array( true, false, true, false ), array( false !== strpos( $rv_mail, '7 days' ), false !== strpos( $rv_mail, '48 hours' ), false !== strpos( $rv_policy, 'not confirmed: 7 days' ), defined( '\WPAffiliateCaptain\Integrations\FormCaptain\Handover::CONFIRM_TTL' ) ) );

// P2-01: taking back a deleted refund fires wpac_commission_rejected( $id, 'pending' ) after the commit.
update_option( 'wpac_settings', array_merge( $rv_saved, array( 'fraud_check_domains' => 0 ) ) );
Settings::flush_cache();
$rv_aff  = \WPAffiliateCaptain\Affiliates\AffiliateRepository::create( array( 'name' => 'Rv Anna', 'email' => 'rv-anna@example.de', 'status' => 'active' ) );
$rv_code = \WPAffiliateCaptain\Codes\CodeRepository::main_code( $rv_aff );
wpac_record_sale( array( 'source' => 'test', 'order_id' => 'rv-5001', 'currency' => 'EUR', 'partner_code' => $rv_code, 'items' => array( array( 'item_id' => '1', 'product_id' => 1, 'name' => 'K', 'net_minor' => 10000 ) ), 'customer' => array( 'user_id' => 0, 'email' => 'rv-k@example.de' ) ) );
wpac_record_refund( array( 'source' => 'test', 'order_id' => 'rv-5001', 'refund_id' => 'rv-rf1', 'type' => 'refund', 'reason' => 't', 'items' => array( array( 'item_id' => '1', 'net_minor' => 5000 ) ) ) );
$GLOBALS['rv_hooks'] = array();
$GLOBALS['rv_on']    = true;
add_action(
	'wpac_commission_rejected',
	static function ( $id, $old = '' ) {
		if ( ! empty( $GLOBALS['rv_on'] ) ) {
			$GLOBALS['rv_hooks'][] = 'rejected:' . $old;
		}
	},
	10,
	2
);
$rv_rr = \WPAffiliateCaptain\Commissions\Ledger::reverse_refund( 'test', 'rv-5001', 'rv-rf1', 'refund deleted' );
$GLOBALS['rv_on'] = false;
check( 'P2-01 reversed refund row fires wpac_commission_rejected with the old status', array( 1, array( 'rejected:pending' ) ), array( count( (array) ( $rv_rr['commission_ids'] ?? array() ) ), $GLOBALS['rv_hooks'] ) );

// P2-03: the waiting period for new bank details follows the setting also after an e-mail change.
$rv_set( array( 'bank_change_wait_days' => 0 ) );
\WPAffiliateCaptain\Affiliates\AffiliateRepository::set_email( $rv_aff, 'rv-anna2@example.de' );
$rv_w0 = \WPAffiliateCaptain\Affiliates\AffiliateRepository::access_changed_recently( $rv_aff );
$rv_set( array( 'bank_change_wait_days' => 5 ) );
\WPAffiliateCaptain\Affiliates\AffiliateRepository::set_email( $rv_aff, 'rv-anna3@example.de' );
$rv_w5 = \WPAffiliateCaptain\Affiliates\AffiliateRepository::access_changed_recently( $rv_aff );
update_option( 'wpac_access_changed_' . $rv_aff, time() - 6 * DAY_IN_SECONDS, false );
update_option( 'wpac_prev_email_' . $rv_aff, array( 'email' => 'rv-anna2@example.de', 'until' => time() + 24 * DAY_IN_SECONDS ), false );
$rv_w5_later = \WPAffiliateCaptain\Affiliates\AffiliateRepository::access_changed_recently( $rv_aff );
check( 'P2-03 bank details wait 0 days / 5 days after an e-mail change, as set', array( false, true, false, 'rv-anna2@example.de' ), array( $rv_w0, $rv_w5, $rv_w5_later, \WPAffiliateCaptain\Affiliates\AffiliateRepository::previous_email( $rv_aff ) ) );

// P2-05: the request history is long enough for the highest monthly limit.
check( 'P2-05 code request history covers the highest allowed monthly limit', true, \WPAffiliateCaptain\Codes\CodeRequests::HISTORY_MAX >= SettingsSchema::int_max()['code_requests_per_month'] );

// P2-04: with release by hand the partner area promises no automatic approval.
$rv_set( array( 'approval_mode' => 'manual', 'hold_days' => 0 ) );
$rv_cond_m = implode( ' ', \WPAffiliateCaptain\Portal\Portal::conditions( \WPAffiliateCaptain\Affiliates\AffiliateRepository::find( $rv_aff ) ) );
$rv_set( array( 'approval_mode' => 'auto', 'hold_days' => 0 ) );
$rv_cond_a = implode( ' ', \WPAffiliateCaptain\Portal\Portal::conditions( \WPAffiliateCaptain\Affiliates\AffiliateRepository::find( $rv_aff ) ) );
check( 'P2-04 conditions: manual release says "we review", automatic says "right away"', array( true, false, true ), array( false !== strpos( $rv_cond_m, 'we review and approve' ), false !== strpos( $rv_cond_m, 'approved right away' ), false !== strpos( $rv_cond_a, 'approved right away' ) ) );

// P6-01 / P6-02: add-on sections are reported as skipped; add-on fields cannot use core options.
$rv_prev6 = SettingsTransfer::preview( array( 'format' => 1, 'settings' => array(), 'addons' => array( 'pro' => array( 'x' => 1 ) ) ) );
add_filter( 'wpac_settings_schema', static function ( $f ) { $f['pro_hook_url'] = array( 'tab' => 'data', 'label' => 'Hook', 'option' => 'wpac_settings' ); $f['pro_ok'] = array( 'tab' => 'data', 'label' => 'Ok', 'option' => 'captain_pro_settings' ); return $f; } );
SettingsSchema::flush();
check( 'P6-01/P6-02 add-on export section reported as skipped; add-on field on a wpac_ option rejected, own option accepted', array( true, null, 'captain_pro_settings' ), array( in_array( 'addons:pro', $rv_prev6['ignored'], true ), SettingsSchema::field( 'pro_hook_url' ), SettingsSchema::field( 'pro_ok' )['option'] ?? '' ) );
$GLOBALS['test_hooks']['wpac_settings_schema'] = array();

// P1-04: a currency change by WP-CLI converts amounts and exchange rates like the settings page.
$rv_set( array( 'currency' => 'EUR', 'exchange_rates' => 'USD=0.92', 'min_payout_minor' => 5000, 'fraud_max_order_minor' => 200000, 'default_rate_type' => 'percent', 'default_rate' => '10' ) );
$rv_fx = SettingsTransfer::check( array( 'currency' => 'USD' ), Settings::all() );
check( 'P1-04 currency change converts minimum payout, review limit and rates', array( 'USD', 5435, 217391, true ), array( $rv_fx['values']['currency'] ?? '', (int) ( $rv_fx['values']['min_payout_minor'] ?? 0 ), (int) ( $rv_fx['values']['fraud_max_order_minor'] ?? 0 ), 0 === strpos( (string) ( $rv_fx['values']['exchange_rates'] ?? '' ), 'EUR=' ) ) );

// P1-07 / P2-08: lower limits are errors, not silent clamps.
$rv_set( array( 'application_confirm_days' => 2, 'code_request_max_length' => 20 ) );
$rv_min = SettingsTransfer::check( array( 'application_confirm_days' => '0', 'code_request_max_length' => '1' ), Settings::all() );
$rv_ok  = SettingsTransfer::check( array( 'application_confirm_days' => '7' ), Settings::all() );
check( 'P1-07 values under the lower limit are rejected, valid values pass', array( true, true, array(), array( 'application_confirm_days' => 7 ) ), array( isset( $rv_min['errors']['application_confirm_days'] ), isset( $rv_min['errors']['code_request_max_length'] ), $rv_min['values'], $rv_ok['values'] ) );

// P4-04 / P6-05: WP-CLI shows private values only on request.
if ( ! class_exists( '\\WPAffiliateCaptain\\Cli\\SettingsCommand', false ) ) {
	require_once WPAC_DIR . 'src/Cli/SettingsCommand.php';
}
check( 'P4-04 CLI output: IBAN shown as "(set)", secret too, public values in clear', array( '(set)', '(set)', '30' ), array( \WPAffiliateCaptain\Cli\SettingsCommand::shown( 'debtor_iban', 'DE89370400440532013000' ), \WPAffiliateCaptain\Cli\SettingsCommand::shown( 'monitor_token', 'h1:x' ), \WPAffiliateCaptain\Cli\SettingsCommand::shown( 'hold_days', 30 ) ) );

// P5-02 / P3-01 / P5-04: every described field points to its description; legal values are marked.
$rv_get = $_GET;
$rv_missing = array();
$rv_legal_seen = 0;
foreach ( array_keys( \WPAffiliateCaptain\Admin\Pages\SettingsPage::tab_keys() ) as $rv_tab ) {
	$_GET  = array( 'page' => 'wpac-settings', 'tab' => $rv_tab );
	$rv_html = r_render( array( \WPAffiliateCaptain\Admin\Pages\SettingsPage::class, 'render' ) )[0];
	preg_match_all( '/aria-describedby="([^"]+)"/', $rv_html, $rv_m );
	foreach ( $rv_m[1] as $rv_ids ) {
		foreach ( preg_split( '/\s+/', trim( $rv_ids ) ) as $rv_id ) {
			if ( false === strpos( $rv_html, 'id="' . $rv_id . '"' ) ) {
				$rv_missing[] = $rv_tab . ':' . $rv_id;
			}
		}
	}
	$rv_legal_seen += substr_count( $rv_html, 'wpac-legal-mark' );
}
$_GET = array( 'page' => 'wpac-settings', 'tab' => 'payouts' );
$rv_pay = r_render( array( \WPAffiliateCaptain\Admin\Pages\SettingsPage::class, 'render' ) )[0];
$_GET = $rv_get;
check( 'P5-02/P3-01/P5-04 descriptions linked (all ids exist), legal marks shown, minimum payout with help text', array( array(), true, true ), array( $rv_missing, $rv_legal_seen >= 10, false !== strpos( $rv_pay, 'id="wpac-desc-min_payout"' ) ) );

// P4-02: private values only from an export made with them; the preview shows new values in full.
$rv_pv = SettingsTransfer::preview( array( 'format' => 1, 'private' => false, 'settings' => array( 'admin_email' => 'attacker@evil.example' ) ) );
$rv_pp = SettingsTransfer::preview( array( 'format' => 1, 'private' => true, 'settings' => array( 'admin_email' => 'new-admin@example.de' ) ) );
check( 'P4-02 private keys ignored without "private": true, taken with it', array( true, array(), 'new-admin@example.de' ), array( in_array( 'admin_email', $rv_pv['ignored'], true ), $rv_pv['values'], $rv_pp['values']['admin_email'] ?? '' ) );

// P4-01: saving a tab that changes bank details, the alert address or a protection needs a confirmation.
$rv_set( array( 'reauth_required' => 1, 'bank_change_wait_days' => 30, 'debtor_iban' => 'DE89370400440532013000' ) );
$rv_post = $_POST;
$_POST = array( 'tab' => 'security', 'wpac' => array( 'reauth_required' => '1', 'bank_change_wait_days' => '0' ) );
$rv_k1 = \WPAffiliateCaptain\Admin\Reauth::action_key( 'settings_save' );
$_POST = array( 'tab' => 'payouts', 'wpac' => array( 'debtor_iban' => 'de89 3704 0044 0532 0130 00', 'hold_days' => '14' ) );
$rv_k2 = \WPAffiliateCaptain\Admin\Reauth::action_key( 'settings_save' );
$_POST = array( 'tab' => 'payouts', 'wpac' => array( 'debtor_iban' => 'DE02120300000000202051' ) );
$rv_k3 = \WPAffiliateCaptain\Admin\Reauth::action_key( 'settings_save' );
$_POST = $rv_post;
check( 'P4-01 sensitive change → settings_sensitive (required); unchanged IBAN in another spelling → settings_save', array( 'settings_sensitive', 'settings_save', 'settings_sensitive', true ), array( $rv_k1, $rv_k2, $rv_k3, \WPAffiliateCaptain\Admin\Reauth::required( 'settings_sensitive' ) ) );

// P4-03: the role table never grants a permission the user does not hold.
$rv_caps = $GLOBALS['test_caps'] ?? null;
$GLOBALS['test_caps'] = array( 'promote_users', 'wpac_manage_program', 'wpac_manage_affiliates' );
$rv_rr = \WPAffiliateCaptain\Admin\Pages\SettingsPage::role_request( array( 'editor' => array( 'wpac_manage_affiliates' => '1', 'wpac_manage_payouts' => '1' ) ) );
if ( null === $rv_caps ) {
	unset( $GLOBALS['test_caps'] );
} else {
	$GLOBALS['test_caps'] = $rv_caps;
}
check( 'P4-03 role table: own permission may be granted, a missing one (payouts) not', array( true, false ), array( ! empty( $rv_rr['editor']['wpac_manage_affiliates'] ), isset( $rv_rr['editor']['wpac_manage_payouts'] ) ) );

// P2-06: the rejection note counts from the rejection, not from the request.
global $wpdb;
$wpdb->insert( \WPAffiliateCaptain\Core\Db::table( 'payout_requests' ), array( 'affiliate_id' => $rv_aff, 'currency' => 'EUR', 'amount_minor' => 1000, 'status' => 'rejected', 'note' => 'Zu früh', 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 61 * DAY_IN_SECONDS ), 'processed_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$rv_lr = \WPAffiliateCaptain\Payouts\PayoutRequests::last_rejected( $rv_aff );
check( 'P2-06 a request from 61 days ago rejected today still shows its reason', 'Zu früh', $rv_lr ? (string) $rv_lr->note : '' );
// P1-08: the support report no longer lists the former setting payout_period.
check( 'P1-08 support report without payout_period', false, false !== strpos( (string) file_get_contents( WPAC_DIR . 'src/Core/Monitor.php' ), "'payout_period'" ) );

// Contract 1.8.1: "replaces" removes further built-in notes; rows on unknown tabs are ignored.
$rv_url = static function () { return 'https://example.test/pro'; };
add_filter( 'wpac_addon_hint_url', $rv_url );
add_filter( 'wpac_settings_rows', static function ( $rows ) { $rows[] = array( 'id' => 'paypal_auto_limit', 'tab' => 'payouts', 'title' => 'PayPal limit', 'render' => static function () { echo '<input name="pro[x]">'; }, 'replaces' => array( 'payout_method_paypal' ) ); $rows[] = array( 'id' => 'webhooks', 'tab' => 'nowhere', 'title' => 'Lost' ); return $rows; } );
$rv_pay_rows  = \WPAffiliateCaptain\Admin\AddonHints::rows( 'payouts' );
$rv_data_rows = \WPAffiliateCaptain\Admin\AddonHints::rows( 'data' );
check( '1.8.1 replaces removes the PayPal note; a row on an unknown tab is ignored and replaces nothing', array( array(), array( 'paypal_auto_limit' ), true ), array( array_values( array_filter( wp_list_pluck( $rv_pay_rows['notes'], 'id' ), static function ( $i ) { return 'payout_method_paypal' === $i; } ) ), wp_list_pluck( $rv_pay_rows['fields'], 'id' ), in_array( 'webhooks', wp_list_pluck( $rv_data_rows['notes'], 'id' ), true ) ) );
$GLOBALS['test_hooks']['wpac_settings_rows'] = array();
$GLOBALS['test_hooks']['wpac_addon_hint_url'] = array();

update_option( 'wpac_settings', $rv_saved );
Settings::flush_cache();
SettingsSchema::flush();
