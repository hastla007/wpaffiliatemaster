<?php
/**
 * SET-AC-24 setup wizard: steps from the settings register, saving per step through
 * Settings::sanitize(), audit entries, country profile preview/apply, progress (skip, finish,
 * restart), password confirmation of the profile and sensitive values.
 * Included from run.php right before r8-render.php.
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Admin\Pages\SetupWizard;
use WPAffiliateCaptain\Admin\Reauth;
use WPAffiliateCaptain\Core\CountryProfiles;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Core\SettingsSchema;

echo "SET-AC-24 setup wizard: flow\n";

global $wpdb;
$wiz_saved_settings = get_option( 'wpac_settings' );
$wiz_saved_profile  = get_option( 'wpac_country_profile', null );
$wiz_saved_post     = $_POST;
delete_option( SetupWizard::OPTION );
SettingsSchema::flush();
Settings::update( array( 'operator_country' => 'DE', 'currency' => 'EUR', 'vat_rate' => '19', 'default_rate_type' => 'percent', 'default_rate' => '10', 'min_payout_minor' => 5000, 'max_payout_minor' => 0, 'hold_days' => 30, 'cookie_mode' => 'consent', 'cookie_days' => 30, 'self_billing' => 1, 'operator_address' => '' ) );
Settings::flush_cache();

// Steps in the order of the TODO item; every field is a visible core field of the register.
$wiz_steps  = SetupWizard::steps();
$wiz_fields = array();
foreach ( $wiz_steps as $wiz_def ) {
	$wiz_fields = array_merge( $wiz_fields, $wiz_def['fields'] );
}
$wiz_bad = array();
foreach ( $wiz_fields as $wiz_k ) {
	$wiz_f = SettingsSchema::core()[ $wiz_k ] ?? null;
	if ( ! $wiz_f || ! empty( $wiz_f['hidden'] ) || ! empty( $wiz_f['secret'] ) || '' === (string) $wiz_f['label'] || '' === (string) $wiz_f['help'] ) {
		$wiz_bad[] = $wiz_k;
	}
}
check(
	'SET-AC-24 six steps in order, every field a visible core field of the register with label and help, legal fields included',
	array( array( 'country', 'company', 'commission', 'pages', 'consent', 'test' ), array(), true, true ),
	array( array_keys( $wiz_steps ), $wiz_bad, ! empty( SettingsSchema::field( 'vat_rate' )['legal'] ) && in_array( 'vat_rate', $wiz_fields, true ), in_array( 'cookie_mode', $wiz_fields, true ) )
);
check(
	'SET-AC-24 navigation: next/previous, unknown step refused, amount fields under their form name',
	array( 'company', '', 'country', '', '', 'min_payout', 'hold_days' ),
	array( SetupWizard::next_step( 'country' ), SetupWizard::next_step( 'test' ), SetupWizard::previous_step( 'company' ), SetupWizard::previous_step( 'country' ), SetupWizard::clean_step( 'evil' ), SetupWizard::form_name( 'min_payout_minor' ), SetupWizard::form_name( 'hold_days' ) )
);

// Fresh state: first step current, nothing started.
$wiz_state = SetupWizard::state();
check( 'SET-AC-24 fresh progress: first step current, nothing started or finished', array( 'country', array(), '', '', false ), array( SetupWizard::current_step(), $wiz_state['steps'], $wiz_state['started'], $wiz_state['finished'], $wiz_state['dismissed'] ) );

// Commission step: valid values, amounts as decimals, audit like the settings page.
$wiz_audit_before = (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . t( 'audit' ) );
$wiz_r            = SetupWizard::save_step( 'commission', array( 'default_rate_type' => 'percent', 'default_rate' => '12,5', 'commission_basis' => 'net', 'hold_days' => '14', 'approval_mode' => 'auto', 'min_payout' => '25,00', 'cookie_mode' => 'always', 'vat_rate' => '7' ) );
Settings::flush_cache();
$wiz_audit = $wpdb->get_row( $wpdb->prepare( 'SELECT action, details FROM ' . t( 'audit' ) . " WHERE id > %d AND action = 'settings.updated' ORDER BY id DESC LIMIT 1", $wiz_audit_before ) );
$wiz_det   = $wiz_audit ? json_decode( (string) $wiz_audit->details, true ) : array();
sort( $wiz_r['changed'] );
check(
	'SET-AC-24 commission step saved through sanitize(): 12,5 % / 14 days / 25,00 → 2500; fields of other steps ignored; audit with old → new, source wizard',
	array( true, array(), array( 'default_rate', 'hold_days', 'min_payout_minor' ), '12.5', 14, 2500, 'consent', '19', 'wizard', 'commission', array( 'from' => '30', 'to' => '14' ), 'done' ),
	array( $wiz_r['ok'], $wiz_r['errors'], $wiz_r['changed'], (string) Settings::get( 'default_rate' ), (int) Settings::get( 'hold_days' ), (int) Settings::get( 'min_payout_minor' ), Settings::get( 'cookie_mode' ), (string) Settings::get( 'vat_rate' ), $wiz_det['source'] ?? '', $wiz_det['step'] ?? '', $wiz_det['values']['hold_days'] ?? null, SetupWizard::state()['steps']['commission'] ?? '' )
);

// Invalid values: message per form field, stored values stay, the step is not done.
delete_option( SetupWizard::OPTION );
$wiz_r = SetupWizard::save_step( 'commission', array( 'default_rate_type' => 'percent', 'default_rate' => '150', 'commission_basis' => 'net', 'hold_days' => 'abc', 'approval_mode' => 'auto', 'min_payout' => '1.000 €' ) );
Settings::flush_cache();
$wiz_err = array_keys( $wiz_r['errors'] );
sort( $wiz_err );
check(
	'SET-AC-24 invalid input: errors keyed by form field (default_rate, hold_days, min_payout), stored values unchanged, step not marked',
	array( array( 'default_rate', 'hold_days', 'min_payout' ), '12.5', 14, 2500, false ),
	array( $wiz_err, (string) Settings::get( 'default_rate' ), (int) Settings::get( 'hold_days' ), (int) Settings::get( 'min_payout_minor' ), isset( SetupWizard::state()['steps']['commission'] ) )
);

// Company step: an unchecked checkbox is off; a country outside the list is refused.
$wiz_r = SetupWizard::save_step( 'company', array( 'operator_country' => 'AT', 'operator_address' => "Muster GmbH\nWien", 'operator_vat_id' => 'ATU12345678', 'operator_tax_number' => '', 'vat_rate' => '20' ) );
Settings::flush_cache();
$wiz_r2 = SetupWizard::save_step( 'company', array( 'operator_country' => 'XX', 'operator_address' => "Muster GmbH\nWien", 'operator_vat_id' => 'ATU12345678', 'self_billing' => '1', 'vat_rate' => '20' ) );
Settings::flush_cache();
check(
	'SET-AC-24 company step: address, VAT ID, rate saved, unchecked self-billing off; then an unknown country refused (kept), self-billing on again',
	array( array(), 'AT', "Muster GmbH\nWien", '20', array( 'operator_country' ), 'AT', 1 ),
	array( $wiz_r['errors'], Settings::get( 'operator_country' ), Settings::get( 'operator_address' ), (string) Settings::get( 'vat_rate' ), array_keys( $wiz_r2['errors'] ), Settings::get( 'operator_country' ), (int) Settings::get( 'self_billing' ) )
);

// Steps without fields save nothing.
$wiz_r = SetupWizard::save_step( 'test', array( 'hold_days' => '99' ) );
Settings::flush_cache();
check( 'SET-AC-24 steps without fields (test) change no setting', array( true, array(), 14 ), array( $wiz_r['ok'], $wiz_r['changed'], (int) Settings::get( 'hold_days' ) ) );

// Country step: the preview changes nothing; apply only what was shown, audit with the profile.
Settings::update( array( 'operator_country' => 'DE', 'vat_rate' => '19', 'statement_retention_minimum' => 0, 'statement_retention_years' => 10 ) );
Settings::flush_cache();
$wiz_before = Settings::all();
$wiz_ok     = SetupWizard::profile_preview( 'AT' );
Settings::flush_cache();
$wiz_pend = SetupWizard::profile_pending();
check(
	'SET-AC-24 country step: preview kept per user, nothing changed (no silent overwrite), changes old → new listed',
	array( true, 'AT', $wiz_before, array( '19', '20' ) ),
	array( $wiz_ok, $wiz_pend['profile'] ?? '', Settings::all(), $wiz_pend['check']['changes']['vat_rate'] ?? null )
);
check( 'SET-AC-24 unknown profile: error, nothing kept', array( true, 'AT' ), array( is_wp_error( SetupWizard::profile_preview( 'ZZ' ) ), SetupWizard::profile_pending()['profile'] ?? '' ) );
$wiz_audit_before = (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . t( 'audit' ) );
$wiz_count        = SetupWizard::profile_apply();
Settings::flush_cache();
$wiz_audit = $wpdb->get_row( $wpdb->prepare( 'SELECT details FROM ' . t( 'audit' ) . " WHERE id > %d AND action = 'settings.profile' ORDER BY id DESC LIMIT 1", $wiz_audit_before ) );
$wiz_det   = $wiz_audit ? json_decode( (string) $wiz_audit->details, true ) : array();
check(
	'SET-AC-24 apply: Austrian values, audit "settings.profile" with source wizard, profile remembered, step done, preview gone',
	array( true, 'AT', '20', 'AT', 'wizard', 'AT', 'done', null, true ),
	array( is_int( $wiz_count ) && $wiz_count > 0, Settings::get( 'operator_country' ), (string) Settings::get( 'vat_rate' ), $wiz_det['profile'] ?? '', $wiz_det['source'] ?? '', CountryProfiles::applied()['code'] ?? '', SetupWizard::state()['steps']['country'] ?? '', SetupWizard::profile_pending(), is_wp_error( SetupWizard::profile_apply() ) )
);
SetupWizard::profile_preview( 'DE' );
SetupWizard::profile_discard();
check( 'SET-AC-24 discard: the pending profile is dropped, nothing applied', array( null, 'AT' ), array( SetupWizard::profile_pending(), ( Settings::flush_cache() ?: Settings::get( 'operator_country' ) ) ) );

// Progress: skip, current step, finish, restart.
SetupWizard::mark( 'company', 'skipped' );
SetupWizard::mark( 'pages', 'bogus' );
SetupWizard::mark( 'nope', 'done' );
check( 'SET-AC-24 progress: skipped step stored, invalid status/step ignored, current = first open step', array( array( 'country' => 'done', 'company' => 'skipped' ), 'commission' ), array( SetupWizard::state()['steps'], SetupWizard::current_step() ) );
SetupWizard::mark( 'company', 'done' );
SetupWizard::finish( true );
$wiz_state = SetupWizard::state();
check( 'SET-AC-24 a later save replaces "skipped"; skipping the wizard stores finished + skipped_all', array( 'done', true, true, true ), array( $wiz_state['steps']['company'], '' !== $wiz_state['finished'], $wiz_state['skipped_all'], '' !== $wiz_state['started'] ) );
SetupWizard::restart();
$wiz_state = SetupWizard::state();
check( 'SET-AC-24 restart: progress cleared, settings stay, re-openable at step 1', array( array(), '', false, 'country', 'AT' ), array( $wiz_state['steps'], $wiz_state['finished'], $wiz_state['skipped_all'], SetupWizard::current_step(), Settings::get( 'operator_country' ) ) );

// Password confirmation: applying a profile like on the settings page; sensitive values like settings_save.
$_POST = array( 'wpac' => array( 'cookie_mode' => 'consent' ) );
$wiz_k1 = Reauth::action_key( 'wizard_profile_apply' );
$wiz_k2 = Reauth::action_key( 'wizard_save' );
$_POST  = array( 'wpac' => array( 'admin_email' => 'attacker@example.test' ) );
$wiz_k3 = Reauth::action_key( 'wizard_save' );
check( 'SET-AC-24 password confirmation: profile apply = settings_profile_apply; sensitive value in a wizard save = settings_sensitive', array( 'settings_profile_apply', 'wizard_save', 'settings_sensitive' ), array( $wiz_k1, $wiz_k2, $wiz_k3 ) );

// Routes: capability of the settings page; menu entry; uninstall removes the progress.
$wiz_admin = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Admin/Admin.php' );
$wiz_routes = preg_match_all( "/'wizard_(save|profile|profile_apply|pages|state)'\s*=> array\( Pages\\\\SetupWizard::class, Capabilities::MANAGE_PROGRAM \)/", $wiz_admin );
check(
	'SET-AC-24 five admin-post routes with the settings capability (nonce via dispatch), menu entry, option removed on uninstall',
	array( 5, true, true ),
	array( $wiz_routes, false !== strpos( $wiz_admin, 'Pages\SetupWizard::menu();' ), false !== strpos( (string) file_get_contents( dirname( __DIR__, 2 ) . '/uninstall.php' ), "'wpac_setup_wizard'" ) )
);

$_POST = $wiz_saved_post;
update_option( 'wpac_settings', $wiz_saved_settings );
if ( null === $wiz_saved_profile ) {
	delete_option( 'wpac_country_profile' );
} else {
	update_option( 'wpac_country_profile', $wiz_saved_profile );
}
delete_option( SetupWizard::OPTION );
Settings::flush_cache();
