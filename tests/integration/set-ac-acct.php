<?php
/**
 * SET-AC-30 (payout methods for countries without IBAN): structured bank details outside SEPA
 * (account number, bank code, bank name, bank country, BIC/SWIFT, holder), encrypted like the
 * IBAN; switches payout_method_sepa / _bank / _manual; lenient checks per country; transfer list
 * columns; SEPA file only for SEPA; change rules (password, waiting period, audit); privacy export
 * and erasure. Included from run.php before r8-render.php.
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Core\Crypto;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Core\SettingsSchema;
use WPAffiliateCaptain\Payouts\PayoutService;
use WPAffiliateCaptain\Payouts\ProfileRepository;

echo "SET-AC-30 payout methods outside SEPA\n";
global $wpdb;
$acct_keep = get_option( 'wpac_settings' );
SettingsSchema::flush();
update_option( 'wpac_settings', array_merge( (array) $acct_keep, array( 'min_payout_minor' => 5000, 'vies_check' => 0, 'maintenance_mode' => 0, 'terminated_open' => 'pay', 'debtor_name' => 'Shop GmbH', 'debtor_iban' => 'DE89370400440532013000' ) ) );
Settings::flush_cache();

function acct_set( array $values ) {
	update_option( 'wpac_settings', array_merge( (array) get_option( 'wpac_settings' ), $values ) );
	Settings::flush_cache();
}
function acct_code( $r ) {
	return is_wp_error( $r ) ? $r->get_error_code() : $r;
}
function acct_base( $slug ) {
	return array( 'tax_status' => 'private', 'legal_name' => 'Acct ' . $slug, 'address' => "1 Main St\nSpringfield", 'country' => 'US', 'account_holder' => 'Acct ' . $slug );
}
function acct_us( array $extra = array() ) {
	return $extra + array( 'payout_method' => 'bank', 'bank_country' => 'US', 'account_number' => '0001234 56789', 'bank_code' => '021000021', 'bank_name' => 'Chase Bank', 'bic' => 'CHASUS33' );
}
function acct_partner( $slug, array $profile ) {
	global $wpdb;
	$id = AffiliateRepository::create( array( 'name' => 'Acct ' . $slug, 'email' => 'acct-' . $slug . '@example.com', 'status' => 'active' ) );
	$r  = ProfileRepository::save( $id, $profile + acct_base( $slug ) );
	$wpdb->query( 'UPDATE ' . t( 'payout_profiles' ) . " SET updated_at = '2020-01-01 00:00:00' WHERE affiliate_id = " . (int) $id );
	return array( $id, $r );
}
function acct_earn( $aff, $order, $net ) {
	$r = wpac_record_sale( array( 'source' => 'test', 'order_id' => (string) $order, 'currency' => 'EUR', 'partner_code' => WPAffiliateCaptain\Codes\CodeRepository::main_code( $aff ), 'items' => array( array( 'item_id' => '1', 'product_id' => 10, 'name' => 'Kurs', 'net_minor' => $net ) ), 'customer' => array( 'user_id' => 0, 'email' => 'kunde-' . $order . '@example.de' ) ) );
	foreach ( (array) $r['commission_ids'] as $cid ) {
		Ledger::transition( (int) $cid, 'approved' );
	}
}

/* Defaults: SEPA and "other" on, bank outside SEPA off – existing behaviour unchanged ---------- */
$acct_d = SettingsSchema::defaults();
check( 'SET-AC-30 defaults: SEPA on, non-SEPA bank off, other method on; register on the payouts tab', array( 1, 0, 1, true, true ), array( $acct_d['payout_method_sepa'], $acct_d['payout_method_bank'], $acct_d['payout_method_manual'], in_array( 'payout_method_sepa', WPAffiliateCaptain\Admin\Pages\SettingsPage::tab_keys()['payouts'], true ), in_array( 'payout_method_bank', WPAffiliateCaptain\Admin\Pages\SettingsPage::tab_keys()['payouts'], true ) ) );
check( 'SET-AC-30 by default a new partner chooses SEPA or other (as before)', array( 'sepa', 'manual' ), array_keys( ProfileRepository::method_options( null ) ) );
list( $acct_off, $acct_off_r ) = acct_partner( 'off', acct_us() );
check( 'SET-AC-30 non-SEPA bank transfer switched off: not saved', array( 'wpac_method_off', null ), array( acct_code( $acct_off_r ), ProfileRepository::get( $acct_off ) ) );

/* Validation per country (lenient) ---------------------------------------------------------- */
acct_set( array( 'payout_method_bank' => 1 ) );
check( 'SET-AC-30 switched on: offered between SEPA and other', array( 'sepa', 'bank', 'manual' ), array_keys( ProfileRepository::method_options( null ) ) );
$acct_v = static function ( array $bank ) {
	$r = ProfileRepository::bank_input( null, $bank );
	return is_wp_error( $r ) ? $r->get_error_code() : $r;
};
check(
	'SET-AC-30 US: routing number with checksum, account 4–17 digits (spaces and dashes removed)',
	array(
		array( 'account_number' => '000123456789', 'bank_code' => '021000021', 'bank_name' => 'Chase Bank', 'bank_country' => 'US' ),
		'wpac_bank_code',
		'wpac_bank_code',
		'wpac_account_number',
		'wpac_bank_code',
	),
	array(
		$acct_v( acct_us() ),
		$acct_v( acct_us( array( 'bank_code' => '021000022' ) ) ), // Checksum wrong.
		$acct_v( acct_us( array( 'bank_code' => '02100002' ) ) ),
		$acct_v( acct_us( array( 'account_number' => '123' ) ) ),
		$acct_v( acct_us( array( 'bank_code' => '' ) ) ),
	)
);
check(
	'SET-AC-30 GB sort code 6 digits (12-34-56), CA 8 digits, AU BSB, IN IFSC, NZ without bank code',
	array( '123456', '00011234', true, 'HDFC0001234', true, 'wpac_bank_code', 'wpac_bank_code', 'wpac_account_number' ),
	array(
		$acct_v( array( 'bank_country' => 'GB', 'account_number' => '31926819', 'bank_code' => '12-34-56' ) )['bank_code'] ?? '',
		$acct_v( array( 'bank_country' => 'CA', 'account_number' => '1234567', 'bank_code' => '00011234' ) )['bank_code'] ?? '',
		is_array( $acct_v( array( 'bank_country' => 'AU', 'account_number' => '12345678', 'bank_code' => '062-000' ) ) ),
		$acct_v( array( 'bank_country' => 'IN', 'account_number' => '50100012345678', 'bank_code' => 'hdfc0001234' ) )['bank_code'] ?? '',
		is_array( $acct_v( array( 'bank_country' => 'NZ', 'account_number' => '12-3456-0123456-00' ) ) ),
		$acct_v( array( 'bank_country' => 'GB', 'account_number' => '31926819', 'bank_code' => '12345' ) ),
		$acct_v( array( 'bank_country' => 'IN', 'account_number' => '50100012345678', 'bank_code' => 'HDFC1001234' ) ),
		$acct_v( array( 'bank_country' => 'GB', 'account_number' => '123', 'bank_code' => '123456' ) ),
	)
);
check(
	'SET-AC-30 other countries: account 4–34 letters/digits, bank code optional; country required',
	array( true, true, 'wpac_account_number', 'wpac_bank_country', 'wpac_account_number' ),
	array(
		is_array( $acct_v( array( 'bank_country' => 'BR', 'account_number' => '0001234-5', 'bank_code' => '' ) ) ),
		is_array( $acct_v( array( 'bank_country' => 'JP', 'account_number' => '1234567', 'bank_code' => '0005 123', 'bank_name' => 'MUFG' ) ) ),
		$acct_v( array( 'bank_country' => 'BR', 'account_number' => '12' ) ),
		$acct_v( array( 'account_number' => '12345678' ) ),
		$acct_v( array( 'bank_country' => 'BR', 'bank_name' => 'Itaú' ) ),
	)
);
add_filter( 'wpac_bank_account_rules', static function ( $rules ) { $rules['MX'] = array( 'account' => array( 18, 18 ), 'code' => null, 'code_required' => false ); return $rules; } );
check( 'SET-AC-30 filter wpac_bank_account_rules adds a country (MX CLABE 18 digits)', array( true, 'wpac_account_number' ), array( is_array( $acct_v( array( 'bank_country' => 'MX', 'account_number' => '002010077777777771' ) ) ), $acct_v( array( 'bank_country' => 'MX', 'account_number' => '1234567' ) ) ) );
remove_all_filters( 'wpac_bank_account_rules' );
list( $acct_bic, $acct_bic_r ) = acct_partner( 'bic', acct_us( array( 'bic' => 'CHAS' ) ) );
list( $acct_long, $acct_long_r ) = acct_partner( 'long', acct_us( array( 'bank_name' => str_repeat( 'B', 101 ) ) ) );
list( $acct_nohold, $acct_nohold_r ) = array( 0, ProfileRepository::save( AffiliateRepository::create( array( 'name' => 'Acct nh', 'email' => 'acct-nh@example.com', 'status' => 'active' ) ), array( 'account_holder' => '' ) + acct_us() + acct_base( 'nh' ), 'partner' ) );
check( 'SET-AC-30 BIC/SWIFT format, bank name length, account holder required in the partner area', array( 'wpac_bic', 'wpac_too_long_bank_name', 'wpac_account_holder' ), array( acct_code( $acct_bic_r ), acct_code( $acct_long_r ), acct_code( $acct_nohold_r ) ) );

/* Encryption: one encrypted value, masked display, nothing in clear text ---------------------- */
list( $acct_us, $acct_us_r ) = acct_partner( 'us', acct_us() );
$acct_row = $wpdb->get_row( 'SELECT * FROM ' . t( 'payout_profiles' ) . ' WHERE affiliate_id = ' . (int) $acct_us );
$acct_p   = ProfileRepository::get( $acct_us );
check(
	'SET-AC-30 stored encrypted like the IBAN (prefix bank:, JSON), no clear text in the row; method bank; complete',
	array( true, false, false, '', '6789', 'CHASUS33', 'bank', null, true, 0 ),
	array( $acct_us_r, false !== strpos( serialize( (array) $acct_row ), '000123456789' ), false !== strpos( serialize( (array) $acct_row ), '021000021' ), (string) $acct_row->iban_country, (string) $acct_row->iban_last4, (string) $acct_row->bic, ProfileRepository::method( $acct_p ), ProfileRepository::iban( $acct_p ), ProfileRepository::is_complete( $acct_p ), strpos( (string) Crypto::decrypt( (string) $acct_row->iban_encrypted ), 'bank:{' ) )
);
check( 'SET-AC-30 decrypted account details', array( 'account_number' => '000123456789', 'bank_code' => '021000021', 'bank_name' => 'Chase Bank', 'bank_country' => 'US' ), ProfileRepository::bank_details( $acct_p ) );
$acct_snap = ProfileRepository::snapshot( $acct_p );
check( 'SET-AC-30 payout snapshot: masked account, bank country and name; never the full number or bank code', array( 'bank', 'US •••• 6789', 'US', 'Chase Bank', '', false, false ), array( $acct_snap['payout_method'], $acct_snap['account_masked'], $acct_snap['bank_country'], $acct_snap['bank_name'], $acct_snap['iban_masked'], false !== strpos( (string) wp_json_encode( $acct_snap ), '000123456789' ), false !== strpos( (string) wp_json_encode( $acct_snap ), '021000021' ) ) );
check( 'SET-AC-30 re-encryption keeps the account (Crypto::reencrypt_batch works on the same column)', 'bank:', substr( (string) Crypto::decrypt( Crypto::encrypt( (string) Crypto::decrypt( (string) $acct_row->iban_encrypted ) ) ), 0, 5 ) );

/* Change rules: password (changes_destination), waiting period after an access change, audit ---- */
$acct_same = acct_us( array( 'account_number' => '' ) ) + acct_base( 'us' );
check(
	'SET-AC-30 password needed when account number, bank code, bank name, country or method change; not for the same details',
	array( false, true, true, true, true, true ),
	array(
		ProfileRepository::changes_destination( $acct_p, $acct_same ),
		ProfileRepository::changes_destination( $acct_p, array( 'account_number' => '999988887777' ) + $acct_same ),
		ProfileRepository::changes_destination( $acct_p, array( 'bank_code' => '026009593' ) + $acct_same ),
		ProfileRepository::changes_destination( $acct_p, array( 'bank_name' => 'Other Bank' ) + $acct_same ),
		ProfileRepository::changes_destination( $acct_p, array( 'payout_method' => 'sepa', 'iban' => 'DE89370400440532013000' ) + $acct_same ),
		ProfileRepository::changes_destination( $acct_p, array( 'bank_code' => '1' ) + $acct_same ), // Invalid: never without password.
	)
);
$acct_kept = ProfileRepository::form_keep( acct_us() + acct_base( 'us' ) );
check( 'SET-AC-30 rejected admin form keeps bank code/name/country, never the account number', array( false, '021000021', 'US' ), array( isset( $acct_kept['account_number'] ), $acct_kept['bank_code'], $acct_kept['bank_country'] ) );
$GLOBALS['test_mails'] = array();
check( 'SET-AC-30 saving the same details (account number left empty) changes nothing and sends no mail', array( true, (string) $acct_row->iban_encrypted, array() ), array( ProfileRepository::save( $acct_us, $acct_same ), (string) ProfileRepository::get( $acct_us )->iban_encrypted, $GLOBALS['test_mails'] ) );
$wpdb->query( 'UPDATE ' . t( 'payout_profiles' ) . " SET updated_at = '2020-01-01 00:00:00' WHERE affiliate_id = " . (int) $acct_us );

list( $acct_h ) = acct_partner( 'held', acct_us() );
AffiliateRepository::mark_access_change( $acct_h );
$GLOBALS['test_mails'] = array();
$acct_hr = ProfileRepository::save( $acct_h, acct_us( array( 'account_number' => '55554321' ) ) + acct_base( 'held' ), 'partner' );
$acct_hp = ProfileRepository::pending_bank_change( $acct_h );
check(
	'SET-AC-30 new account after an access change waits for approval; payouts keep the stored account',
	array( true, '000123456789', true, 'US •••• 4321', true ),
	array( $acct_hr, ProfileRepository::bank_details( ProfileRepository::get( $acct_h ) )['account_number'], is_array( $acct_hp ), is_array( $acct_hp ) ? ProfileRepository::pending_bank_label( $acct_hp ) : '', (bool) preg_grep( '/wait for our approval/', wp_list_pluck( $GLOBALS['test_mails'], 1 ) ) )
);
check( 'SET-AC-30 approval applies the held account', array( true, '55554321' ), array( ProfileRepository::approve_bank_change( $acct_h ), ProfileRepository::bank_details( ProfileRepository::get( $acct_h ) )['account_number'] ) );
delete_option( 'wpac_access_changed_' . $acct_h );
$GLOBALS['test_mails'] = array();
ProfileRepository::save( $acct_h, acct_us( array( 'bank_code' => '026009593' ) ) + acct_base( 'held' ), 'partner' );
$acct_audit = json_decode( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT details FROM ' . t( 'audit' ) . " WHERE action = 'payout_profile.updated' AND object_id = %d ORDER BY id DESC LIMIT 1", $acct_h ) ), true );
check( 'SET-AC-30 changed bank code: "bank details changed" mail and audit entry (no account data in it)', array( true, true, 'bank', false ), array( (bool) preg_grep( '/Your bank details were changed/', wp_list_pluck( $GLOBALS['test_mails'], 1 ) ), ! empty( $acct_audit['account_changed'] ), $acct_audit['payout_method'] ?? '', false !== strpos( (string) wp_json_encode( $acct_audit ), '026009593' ) ) );

/* Transfer list (CSV) and SEPA file ---------------------------------------------------------- */
list( $acct_sepa ) = acct_partner( 'sepa', array( 'payout_method' => 'sepa', 'iban' => 'DE89370400440532013000', 'country' => 'DE' ) );
$acct_pids = array();
foreach ( array( $acct_us, $acct_sepa ) as $acct_i => $acct_a ) {
	acct_earn( $acct_a, 'acct-exp-' . $acct_i, 150000 );
	$acct_pids[] = PayoutService::create_for_affiliate( $acct_a, gmdate( 'Y-m-d' ), 'EUR' );
}
check( 'SET-AC-30 payout method of the snapshot: bank / sepa', array( 'bank', 'sepa' ), array( PayoutService::snapshot_method( PayoutService::find( $acct_pids[0] ) ), PayoutService::snapshot_method( PayoutService::find( $acct_pids[1] ) ) ) );
$acct_sx = PayoutService::sepa_export( $acct_pids );
check( 'SET-AC-30 SEPA file only for the SEPA payout; the non-SEPA payout is left out and stays pending', array( 1, array( $acct_pids[1] ), false, 'pending' ), is_array( $acct_sx ) ? array( $acct_sx[2], $acct_sx[3], false !== strpos( (string) $acct_sx[1], '000123456789' ), (string) PayoutService::find( $acct_pids[0] )->status ) : array( acct_code( $acct_sx ) ) );
$acct_only = PayoutService::sepa_export( array( $acct_pids[0] ) );
check( 'SET-AC-30 SEPA export of only non-SEPA payouts is refused with the hint to the transfer list', array( 'wpac_empty', true ), array( acct_code( $acct_only ), is_wp_error( $acct_only ) && false !== strpos( $acct_only->get_error_message(), 'outside SEPA' ) ) );
$acct_tl = PayoutService::transfer_csv( $acct_pids, true );
$acct_rows = is_array( $acct_tl ) ? $acct_tl[1] : array();
check(
	'SET-AC-30 transfer list: non-SEPA row with holder, BIC/SWIFT, account number, bank code, bank name, country; SEPA row with IBAN and empty new columns',
	array(
		array( 'Acct us', '', 'CHASUS33', '150.00', 'bank', '', '000123456789', '021000021', 'Chase Bank', 'US' ),
		array( 'DE89370400440532013000', 'sepa', '', '', '', '' ),
		13,
	),
	array(
		isset( $acct_rows[0] ) ? array( $acct_rows[0][1], $acct_rows[0][2], $acct_rows[0][3], $acct_rows[0][4], $acct_rows[0][7], $acct_rows[0][8], $acct_rows[0][9], $acct_rows[0][10], $acct_rows[0][11], $acct_rows[0][12] ) : array(),
		isset( $acct_rows[1] ) ? array( $acct_rows[1][2], $acct_rows[1][7], $acct_rows[1][9], $acct_rows[1][10], $acct_rows[1][11], $acct_rows[1][12] ) : array(),
		count( WPAffiliateCaptain\Admin\Pages\PayoutsPage::transfer_header() ),
	)
);
$acct_xl = WPAffiliateCaptain\Admin\Pages\PayoutsPage::transfer_rows_for_excel( $acct_rows );
check( 'SET-AC-30 transfer list for Excel: readable method, header names the new columns', array( 'Bank transfer (non-SEPA)', 'Account number', 'Bank country' ), array( $acct_xl[0][7] ?? '', WPAffiliateCaptain\Admin\Pages\PayoutsPage::transfer_header()[9], WPAffiliateCaptain\Admin\Pages\PayoutsPage::transfer_header()[12] ) );
$acct_cl_bank = wp_list_pluck( PayoutService::contract_list( array( 'method' => 'bank', 'affiliate_id' => $acct_us ) ), 'method' );
$acct_cl_sepa = PayoutService::contract_list( array( 'method' => 'sepa', 'affiliate_id' => $acct_us ) );
check( 'SET-AC-30 wpac_get_payouts( method bank ) lists the payout, method sepa does not; no bank data in the view', array( array( 'bank' ), array(), false ), array( $acct_cl_bank, $acct_cl_sepa, false !== strpos( (string) wp_json_encode( wpac_get_payout( $acct_pids[0] ) ), '000123456789' ) ) );
$acct_pdf_facts = json_decode( (string) PayoutService::find( $acct_pids[0] )->profile_snapshot, true );
check( 'SET-AC-30 credit note snapshot carries the masked account', array( 'bank', 'US •••• 6789' ), array( $acct_pdf_facts['payout_method'] ?? '', $acct_pdf_facts['account_masked'] ?? '' ) );

/* Switching SEPA off ------------------------------------------------------------------------ */
acct_set( array( 'payout_method_sepa' => 0 ) );
$acct_new = AffiliateRepository::create( array( 'name' => 'Acct new', 'email' => 'acct-new@example.com', 'status' => 'active' ) );
check( 'SET-AC-30 SEPA off: a new partner chooses bank (non-SEPA) or other; IBAN refused; tax data alone can be saved', array( array( 'bank', 'manual' ), 'wpac_method_off', true ), array( array_keys( ProfileRepository::method_options( null ) ), acct_code( ProfileRepository::save( $acct_new, array( 'payout_method' => 'sepa', 'iban' => 'DE89370400440532013000' ) + acct_base( 'new' ) ) ), ProfileRepository::save( $acct_new, acct_base( 'new' ) ) ) );
check( 'SET-AC-30 SEPA off: an existing SEPA partner keeps SEPA (and may change the IBAN)', array( array( 'sepa', 'bank', 'manual' ), true, 'NL91ABNA0417164300' ), array( array_keys( ProfileRepository::method_options( ProfileRepository::get( $acct_sepa ) ) ), ProfileRepository::save( $acct_sepa, array( 'iban' => 'NL91ABNA0417164300', 'country' => 'DE' ) + acct_base( 'sepa' ) ), ProfileRepository::iban( ProfileRepository::get( $acct_sepa ) ) ) );
$acct_all_off = Settings::sanitize( array_merge( Settings::all(), array( 'payout_method_sepa' => 0, 'payout_method_bank' => 0, 'payout_method_manual' => 0 ) ) );
check( 'SET-AC-30 at least one method stays on: switching off all three keeps SEPA on', array( 1, 0, 0 ), array( $acct_all_off['payout_method_sepa'], $acct_all_off['payout_method_bank'], $acct_all_off['payout_method_manual'] ) );
acct_set( array( 'payout_method_sepa' => 1, 'payout_method_bank' => 0 ) );
check( 'SET-AC-30 bank switched off again: a partner who uses it keeps it', array( 'sepa', 'bank', 'manual' ), array_keys( ProfileRepository::method_options( ProfileRepository::get( $acct_us ) ) ) );
check( 'SET-AC-30 settings export/import carries the switches (register)', array( true, true ), array( array_key_exists( 'payout_method_sepa', WPAffiliateCaptain\Core\SettingsTransfer::export( false )['settings'] ), array_key_exists( 'payout_method_bank', WPAffiliateCaptain\Core\SettingsTransfer::export( false )['settings'] ) ) );

/* Privacy: export (masked) and erasure; program export ------------------------------------ */
$acct_exp  = WPAffiliateCaptain\Privacy\Privacy::export( 'acct-us@example.com' );
$acct_vals = array();
foreach ( $acct_exp['data'] as $acct_item ) {
	if ( 'wpac-payout-profile' === $acct_item['group_id'] ) {
		foreach ( (array) $acct_item['data'] as $acct_dd ) {
			$acct_vals[ (string) $acct_dd['name'] ] = (string) $acct_dd['value'];
		}
	}
}
check(
	'SET-AC-30 Art. 15 export: account number masked, routing number, bank name and country; never the full number',
	array( '•••• 6789', '021000021', 'Chase Bank', 'US', 'US •••• 6789', false ),
	array( $acct_vals['Account number'] ?? '', $acct_vals['Routing number (ABA)'] ?? '', $acct_vals['bank_name'] ?? '', $acct_vals['bank_country'] ?? '', $acct_vals['account_masked'] ?? '', false !== strpos( implode( '|', $acct_vals ), '000123456789' ) )
);
$acct_rowm = new ReflectionMethod( WPAffiliateCaptain\Migration\ProgramExport::class, 'row' );
$acct_rowm->setAccessible( true );
$acct_prow = $acct_rowm->invoke( null, 'payout_profiles', (array) ProfileRepository::get( $acct_us ), true );
check( 'SET-AC-30 program export (with tax data) carries the decrypted account', array( 'bank', '', '000123456789', '021000021', 'Chase Bank', 'US' ), array( $acct_prow['payout_method'], $acct_prow['iban'], $acct_prow['account_number'], $acct_prow['bank_code'], $acct_prow['bank_name'], $acct_prow['bank_country'] ) );
AffiliateRepository::mark_access_change( $acct_h );
ProfileRepository::save( $acct_h, acct_us( array( 'account_number' => '77771111' ) ) + acct_base( 'held' ), 'partner' );
$acct_had = is_array( ProfileRepository::pending_bank_change( $acct_h ) );
ProfileRepository::delete( $acct_h );
check( 'SET-AC-30 erasure deletes the account and a held change', array( true, null, null ), array( $acct_had, ProfileRepository::get( $acct_h ), ProfileRepository::pending_bank_change( $acct_h ) ) );
delete_option( 'wpac_access_changed_' . $acct_h );

/* Switching between methods ---------------------------------------------------------------- */
acct_set( array( 'payout_method_bank' => 1 ) );
$acct_sw = ProfileRepository::save( $acct_us, array( 'payout_method' => 'manual', 'payment_details' => 'PayPal: acct@example.com' ) + acct_base( 'us' ) );
$acct_swp = ProfileRepository::get( $acct_us );
check( 'SET-AC-30 bank → other: method manual, no account left, BIC cleared', array( true, 'manual', null, '', '' ), array( $acct_sw, ProfileRepository::method( $acct_swp ), ProfileRepository::bank_details( $acct_swp ), (string) $acct_swp->iban_last4, (string) $acct_swp->bic ) );
check( 'SET-AC-30 other → bank needs the account number; → SEPA needs an IBAN', array( 'wpac_account_number', 'wpac_iban_required' ), array( acct_code( ProfileRepository::save( $acct_us, array( 'payout_method' => 'bank', 'bank_country' => 'US' ) + acct_base( 'us' ) ) ), acct_code( ProfileRepository::save( $acct_us, array( 'payout_method' => 'sepa' ) + acct_base( 'us' ) ) ) ) );

update_option( 'wpac_settings', $acct_keep );
Settings::flush_cache();
SettingsSchema::flush();
