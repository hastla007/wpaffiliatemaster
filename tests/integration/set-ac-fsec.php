<?php
/**
 * Fix round after the final review (fsec, plugin 0.22.0): password confirmation when the
 * retention of statements is shortened or the hold lifted (P4-06), when a reset changes protected
 * values (P4-07); SWIFT/BIC and holder of accounts outside SEPA count as a change of the payout
 * destination (P4-08); accounts outside SEPA in the fraud rule "shared_iban" (P4-09); withholding
 * and a waiting bank change in the privacy export (P4-10). Included from run.php before
 * r8-render.php. The program export with bank data (P4-05) is in set-ac-fsec-render.php.
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Admin\Reauth;
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Core\SettingsTransfer;
use WPAffiliateCaptain\Payouts\ProfileRepository;
use WPAffiliateCaptain\Privacy\Privacy;

echo "fsec: retention and reset confirmation, non-SEPA destination, shared accounts, privacy export\n";
global $wpdb;
$fs_keep = get_option( 'wpac_settings' );
$fs_post = $_POST;
$fs_set  = static function ( array $values ) {
	update_option( 'wpac_settings', array_merge( (array) get_option( 'wpac_settings' ), $values ) );
	Settings::flush_cache();
};
$fs_key = static function ( $do, array $post ) {
	$_POST = $post;
	return Reauth::action_key( $do );
};

/* P4-06: retention shortened or hold lifted ------------------------------------------------ */
$fs_set( array( 'reauth_required' => 1, 'operator_country' => 'DE', 'retention_hold' => 1, 'statement_retention_years' => 10, 'statement_retention_minimum' => 0 ) );
$fs_data = array( 'statement_retention_years' => '10', 'statement_retention_minimum' => '0' );
check(
	'P4-06 tab "Data": lifting the hold or lowering period/minimum asks for the password; raising or unchanged does not',
	array( 'settings_retention_lower', 'settings_retention_lower', 'settings_retention_lower', 'settings_save', 'settings_save', 'settings_save', 'settings_retention_lower', true ),
	array(
		$fs_key( 'settings_save', array( 'tab' => 'data', 'wpac' => $fs_data ) ), // Checkbox absent = off.
		$fs_key( 'settings_save', array( 'tab' => 'data', 'wpac' => array( 'retention_hold' => '1', 'statement_retention_years' => '8' ) + $fs_data ) ),
		$fs_key( 'settings_save', array( 'tab' => 'data', 'wpac' => array( 'retention_hold' => '1', 'statement_retention_minimum' => '7' ) + $fs_data ) ), // DE: 8 years.
		$fs_key( 'settings_save', array( 'tab' => 'data', 'wpac' => array( 'retention_hold' => '1', 'statement_retention_years' => '12', 'statement_retention_minimum' => '9' ) + $fs_data ) ),
		$fs_key( 'settings_save', array( 'tab' => 'data', 'wpac' => array( 'retention_hold' => '1', 'statement_retention_years' => '0' ) + $fs_data ) ), // 0 = forever.
		$fs_key( 'settings_save', array( 'tab' => 'emails', 'wpac' => array() ) ), // Other tab: the hold is not on it.
		$fs_key( 'wizard_save', array( 'wpac' => array( 'retention_hold' => '0' ) ) ),
		Reauth::required( 'settings_retention_lower' ),
	)
);
$fs_set( array( 'retention_hold' => 0, 'statement_retention_years' => 0 ) );
check(
	'P4-06 no hold stored: an absent checkbox needs nothing; "forever" → 10 years is a shortening',
	array( 'settings_save', 'settings_retention_lower' ),
	array(
		$fs_key( 'settings_save', array( 'tab' => 'data', 'wpac' => array( 'statement_retention_years' => '0', 'statement_retention_minimum' => '0' ) ) ),
		$fs_key( 'settings_save', array( 'tab' => 'data', 'wpac' => array( 'statement_retention_years' => '10', 'statement_retention_minimum' => '0' ) ) ),
	)
);
$fs_set( array( 'statement_retention_years' => 10 ) );

/* P4-07: reset of the security tab and of other protected values ---------------------------- */
$fs_set( array( 'bank_change_wait_days' => 90, 'reauth_minutes' => 2, 'fraud_max_orders_day' => 5 ) );
$fs_pending = 'wpac_settings_pending_' . get_current_user_id();
set_transient( $fs_pending, array( 'kind' => 'reset', 'tab' => 'security', 'check' => SettingsTransfer::reset_preview( SettingsTransfer::reset_keys( 'security' ) ) ), HOUR_IN_SECONDS );
$fs_r1 = $fs_key( 'settings_reset_apply', array() );
set_transient( $fs_pending, array( 'kind' => 'reset', 'tab' => 'program', 'check' => array( 'changes' => array( 'trusted_proxies' => array( '10.0.0.1', '' ) ), 'values' => array( 'trusted_proxies' => '' ), 'errors' => array() ) ), HOUR_IN_SECONDS );
$fs_r2 = $fs_key( 'settings_reset_apply', array() );
set_transient( $fs_pending, array( 'kind' => 'reset', 'tab' => 'emails', 'check' => array( 'changes' => array( 'mail_from_name' => array( 'Shop', '' ) ), 'values' => array( 'mail_from_name' => '' ), 'errors' => array() ) ), HOUR_IN_SECONDS );
$fs_r3 = $fs_key( 'settings_reset_apply', array() );
delete_transient( $fs_pending );
check( 'P4-07 reset of "Security" (wait 90 → 30, confirmation 2 → 15 minutes, fraud rule) and any reset of a protected value ask for the password; others not', array( 'settings_reset_money', 'settings_reset_money', 'settings_reset_apply', true ), array( $fs_r1, $fs_r2, $fs_r3, Reauth::required( 'settings_reset_money' ) ) );
check( 'P4-05/06 new action keys are listed with a readable name (audit filter)', array( true, true, false, false ), array( in_array( 'program_export_tax', Reauth::ACTIONS, true ), in_array( 'settings_retention_lower', Reauth::ACTIONS, true ), 'program_export_tax' === Reauth::action_label( 'program_export_tax' ), 'settings_retention_lower' === Reauth::action_label( 'settings_retention_lower' ) ) );
$_POST = $fs_post;
update_option( 'wpac_settings', $fs_keep );
Settings::flush_cache();

/* P4-08: SWIFT/BIC and holder of an account outside SEPA ----------------------------------- */
$fs_set( array( 'payout_method_bank' => 1, 'vies_check' => 0 ) );
$fs_partner = static function ( $slug, array $profile ) {
	global $wpdb;
	$id = AffiliateRepository::create( array( 'name' => 'Fsec ' . $slug, 'email' => 'fsec-' . $slug . '@example.com', 'status' => 'active' ) );
	ProfileRepository::save( $id, $profile + array( 'tax_status' => 'private', 'legal_name' => 'Fsec ' . $slug, 'address' => "Rua 1\nRio", 'country' => 'BR', 'account_holder' => 'Ana ' . $slug ) );
	$wpdb->query( 'UPDATE ' . t( 'payout_profiles' ) . " SET updated_at = '2020-01-01 00:00:00' WHERE affiliate_id = " . (int) $id );
	return $id;
};
$fs_br_in = array( 'payout_method' => 'bank', 'bank_country' => 'BR', 'account_number' => '12345678', 'bank_code' => '001', 'bank_name' => 'Banco do Brasil', 'bic' => 'BRASBRRJ' );
$fs_br    = $fs_partner( 'br', $fs_br_in );
$fs_brp   = ProfileRepository::get( $fs_br );
// The portal sends every field; the account number stays empty (kept like the IBAN).
$fs_portal = array( 'tax_status' => 'private', 'legal_name' => 'Fsec br', 'address' => "Rua 1\nRio", 'country' => 'BR', 'account_holder' => 'Ana br', 'payout_method' => 'bank', 'account_number' => '', 'bank_code' => '001', 'bank_name' => 'Banco do Brasil', 'bank_country' => 'BR', 'bic' => 'brasbrrj', 'iban' => '', 'payment_details' => '', 'vat_id' => '', 'tax_number' => '' );
check(
	'P4-08 non-SEPA: a new SWIFT/BIC or holder needs the partner password; the same details (other spelling) not',
	array( false, true, true, true ),
	array(
		ProfileRepository::changes_destination( $fs_brp, $fs_portal ),
		ProfileRepository::changes_destination( $fs_brp, array( 'bic' => 'ITAUBRSP' ) + $fs_portal ),
		ProfileRepository::changes_destination( $fs_brp, array( 'account_holder' => 'Mallory' ) + $fs_portal ),
		ProfileRepository::changes_destination( $fs_brp, array( 'bic' => 'ITAUBRSP', 'account_holder' => 'Mallory' ) + $fs_portal ),
	)
);
AffiliateRepository::mark_access_change( $fs_br );
$GLOBALS['test_mails'] = array();
$fs_hr  = ProfileRepository::save( $fs_br, array( 'bic' => 'ITAUBRSP', 'account_holder' => 'Mallory' ) + $fs_portal, 'partner' );
$fs_hp  = ProfileRepository::pending_bank_change( $fs_br );
$fs_now = ProfileRepository::get( $fs_br );
check(
	'P4-08 after an access change a new SWIFT/BIC and holder wait for approval (stored ones stay, mail about the held change)',
	array( true, true, 'BRASBRRJ', 'Ana br', 'ITAUBRSP', 'Mallory', true ),
	array( $fs_hr, is_array( $fs_hp ), (string) $fs_now->bic, (string) $fs_now->account_holder, (string) ( $fs_hp['data']['bic'] ?? '' ), (string) ( $fs_hp['data']['account_holder'] ?? '' ), (bool) preg_grep( '/wait for our approval/', wp_list_pluck( $GLOBALS['test_mails'], 1 ) ) )
);
ProfileRepository::reject_bank_change( $fs_br );
delete_option( 'wpac_access_changed_' . $fs_br );
$GLOBALS['test_mails'] = array();
ProfileRepository::save( $fs_br, array( 'account_holder' => 'Ana Souza' ) + $fs_portal, 'partner' );
$fs_audit = json_decode( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT details FROM ' . t( 'audit' ) . " WHERE action = 'payout_profile.updated' AND object_id = %d ORDER BY id DESC LIMIT 1", $fs_br ) ), true );
check( 'P4-08 a new holder without access change: saved, "bank details changed" mail, audit account_changed', array( 'Ana Souza', true, true ), array( (string) ProfileRepository::get( $fs_br )->account_holder, (bool) preg_grep( '/Your bank details were changed/', wp_list_pluck( $GLOBALS['test_mails'], 1 ) ), ! empty( $fs_audit['account_changed'] ) ) );

/* P4-09: the same account outside SEPA at two partners ------------------------------------- */
$fs_us_in = array( 'payout_method' => 'bank', 'bank_country' => 'US', 'account_number' => '123456789', 'bank_code' => '021000021', 'bank_name' => 'Chase', 'country' => 'US' );
$fs_a     = $fs_partner( 'us-a', $fs_us_in );
$fs_b     = $fs_partner( 'us-b', array( 'account_number' => '1234-56789', 'bank_name' => 'JPMorgan Chase' ) + $fs_us_in ); // Other spelling, other bank name.
$fs_c     = $fs_partner( 'us-c', array( 'bank_code' => '026009593' ) + $fs_us_in ); // Same number at another bank.
check(
	'P4-09 the same US account (country, number, bank code) at two partners is noted for "shared_iban"; another bank code not',
	array( array( $fs_b ), array( $fs_a ), array(), array( $fs_b ) ),
	array( ProfileRepository::shared_iban( $fs_a ), ProfileRepository::shared_iban( $fs_b ), ProfileRepository::shared_iban( $fs_c ), ProfileRepository::partners_with_account( $fs_a, ProfileRepository::bank_details( ProfileRepository::get( $fs_a ) ) ) )
);
// A held change to the shared account counts after the approval.
AffiliateRepository::mark_access_change( $fs_c );
ProfileRepository::save( $fs_c, array( 'bank_code' => '021000021', 'account_number' => '' ) + $fs_us_in + array( 'tax_status' => 'private', 'legal_name' => 'Fsec us-c', 'address' => "Rua 1\nRio", 'account_holder' => 'Ana us-c' ), 'partner' );
$fs_c_held = ProfileRepository::shared_iban( $fs_c );
ProfileRepository::approve_bank_change( $fs_c );
delete_option( 'wpac_access_changed_' . $fs_c );
check( 'P4-09 a held change to the same account: not before, but after the approval', array( array(), true, true ), array( $fs_c_held, in_array( $fs_a, ProfileRepository::shared_iban( $fs_c ), true ), in_array( $fs_c, ProfileRepository::shared_iban( $fs_a ), true ) ) );
ProfileRepository::save( $fs_b, array( 'payout_method' => 'manual', 'payment_details' => 'PayPal: b@example.com' ) + array( 'tax_status' => 'private', 'legal_name' => 'Fsec us-b', 'address' => "Rua 1\nRio", 'country' => 'US', 'account_holder' => 'Ana us-b' ) );
check( 'P4-09 switching to another method clears the note on both sides', array( array(), false ), array( ProfileRepository::shared_iban( $fs_b ), in_array( $fs_b, ProfileRepository::shared_iban( $fs_a ), true ) ) );

/* P4-10: withholding and a waiting bank change in the privacy export ------------------------ */
\WPAffiliateCaptain\Payouts\Withholding::save( $fs_a, '15', 'Quellensteuer USA' );
AffiliateRepository::mark_access_change( $fs_a );
ProfileRepository::save( $fs_a, array( 'account_number' => '987654321' ) + $fs_us_in + array( 'tax_status' => 'private', 'legal_name' => 'Fsec us-a', 'address' => "Rua 1\nRio", 'account_holder' => 'Ana us-a' ), 'partner' );
delete_option( 'wpac_access_changed_' . $fs_a );
$fs_export = Privacy::export( 'fsec-us-a@example.com' );
$fs_groups = array();
foreach ( $fs_export['data'] as $fs_item ) {
	$fs_groups[ $fs_item['item_id'] ] = array_column( $fs_item['data'], 'value', 'name' );
}
$fs_wh   = $fs_groups[ 'wpac-withholding-' . $fs_a ] ?? array();
$fs_pend = $fs_groups[ 'wpac-profile-pending-' . $fs_a ] ?? array();
check(
	'P4-10 export: withholding (percentage, note) and the waiting bank change masked, never the full account number',
	array( true, 'Quellensteuer USA', 'US •••• 4321', false ),
	array( in_array( '15 %', $fs_wh, true ), $fs_wh['Note'] ?? '', reset( $fs_pend ) ?: '', false !== strpos( (string) wp_json_encode( $fs_export ), '987654321' ) )
);
$fs_payout = (object) array( 'id' => 99901, 'number' => 'G-1', 'created_at' => '2026-11-01 10:00:00', 'net_minor' => 10000, 'vat_minor' => 1900, 'amount_minor' => 10400, 'vat_mode' => 'standard', 'currency' => 'EUR', 'status' => 'paid', 'paid_at' => '', 'storno_number' => '', 'note' => '', 'profile_snapshot' => wp_json_encode( array( 'withholding' => array( 'percent' => '15', 'note' => 'x', 'base_minor' => 10000 ) ) ) );
$fs_pi     = wp_list_pluck( Privacy::payout_item( $fs_payout )['data'], 'value' );
$fs_pi0    = wp_list_pluck( Privacy::payout_item( (object) ( array( 'amount_minor' => 11900 ) + (array) $fs_payout ) )['data'], 'name' );
check( 'P4-10 export of a payout: line with the amount withheld; none without withholding', array( true, false ), array( (bool) preg_grep( '/15[,.]00/', $fs_pi ), (bool) preg_grep( '/Withholding/', $fs_pi0 ) ) );

/* P4-11: saving relaxed fraud rule fields asks for the password; tightening or unchanged does not */
$fs_set( array( 'reauth_required' => 1, 'currency' => 'EUR', 'fraud_max_orders_day' => 50, 'fraud_max_order_minor' => 200000, 'fraud_min_click_seconds' => 5, 'fraud_max_refund_rate' => 30, 'fraud_check_domains' => 1, 'fraud_blocked_domains' => "spam.example\nbad.example", 'fraud_refund_window_days' => 90, 'fraud_refund_min_orders' => 5, 'bank_change_wait_days' => 30, 'reauth_minutes' => 15, 'rate_limit_per_minute' => 30, 'trusted_proxies' => '' ) );
$fs_sec = array( 'reauth_required' => '1', 'fraud_max_orders_day' => '50', 'fraud_max_order' => '2000', 'fraud_min_click_seconds' => '5', 'fraud_max_refund_rate' => '30', 'fraud_check_domains' => '1', 'fraud_blocked_domains' => "spam.example\nbad.example", 'fraud_refund_window_days' => '90', 'fraud_refund_min_orders' => '5', 'bank_change_wait_days' => '30', 'reauth_minutes' => '15', 'rate_limit_per_minute' => '30', 'trusted_proxies' => '' );
$fs_p411 = array();
foreach ( array(
	array(),                                             // Unchanged.
	array( 'fraud_max_orders_day' => '20', 'fraud_max_order' => '1000', 'fraud_min_click_seconds' => '10', 'fraud_max_refund_rate' => '20', 'fraud_refund_min_orders' => '3', 'fraud_blocked_domains' => "spam.example\nbad.example\nmore.example", 'fraud_refund_window_days' => '30' ), // Tighter.
	array( 'fraud_max_orders_day' => '0' ),              // Rule off.
	array( 'fraud_max_order' => '5000' ),                // Higher limit.
	array( 'fraud_min_click_seconds' => '1' ),
	array( 'fraud_max_refund_rate' => '50' ),
	array( 'fraud_refund_min_orders' => '20' ),
	array( 'fraud_blocked_domains' => 'spam.example' ),  // Domain removed.
) as $fs_change ) {
	$fs_p411[] = $fs_key( 'settings_save', array( 'tab' => 'security', 'wpac' => $fs_change + $fs_sec ) );
}
$fs_nodom = $fs_sec;
unset( $fs_nodom['fraud_check_domains'] ); // Checkbox absent = off.
$fs_p411[] = $fs_key( 'settings_save', array( 'tab' => 'security', 'wpac' => $fs_nodom ) );
$fs_p411[] = $fs_key( 'wizard_save', array( 'wpac' => array( 'fraud_max_order' => '0' ) ) );
$fs_p411[] = $fs_key( 'wizard_save', array( 'wpac' => array( 'fraud_max_order' => '1500' ) ) );
$fs_p411[] = $fs_key( 'settings_save', array( 'tab' => 'emails', 'wpac' => array( 'fraud_max_orders_day' => '0' ) ) ); // Not a field of that tab.
$_POST     = $fs_post;
check(
	'P4-11 security tab: a weaker fraud rule (higher/0 limit, shorter click time, more refund orders, domain check off or domain removed) asks for the password (settings_sensitive); tighter or unchanged does not; wizard step too',
	array( 'settings_save', 'settings_save', 'settings_sensitive', 'settings_sensitive', 'settings_sensitive', 'settings_sensitive', 'settings_sensitive', 'settings_sensitive', 'settings_sensitive', 'settings_sensitive', 'wizard_save', 'settings_save' ),
	$fs_p411
);
check(
	'P4-11 relaxed_fraud_rules(): only weaker values are listed',
	array( array( 'fraud_max_orders_day', 'fraud_check_domains' ), array() ),
	array(
		Reauth::relaxed_fraud_rules( array( 'fraud_max_orders_day' => 10, 'fraud_check_domains' => 1 ), array( 'fraud_max_orders_day' => 0, 'fraud_check_domains' => 0 ) ),
		Reauth::relaxed_fraud_rules( array( 'fraud_max_orders_day' => 0, 'fraud_blocked_domains' => 'a.example' ), array( 'fraud_max_orders_day' => 10, 'fraud_blocked_domains' => "A.example\nb.example" ) ),
	)
);

foreach ( array( $fs_br, $fs_a, $fs_b, $fs_c ) as $fs_id ) {
	ProfileRepository::delete( $fs_id );
}
update_option( 'wpac_settings', $fs_keep );
Settings::flush_cache();
