<?php
/**
 * Fix round after the final review (24.11.2026), agent fset: settings transfer, wizard, reset,
 * page references, copy pause, start numbers, texts per language, small business limits and the
 * notices of a check (P1-10 … P1-19, P3-12). Included from run.php right before r8-render.php.
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Admin\Pages\SetupWizard;
use WPAffiliateCaptain\Core\CountryProfiles;
use WPAffiliateCaptain\Core\Lifecycle;
use WPAffiliateCaptain\Core\Money;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Core\SettingsSchema;
use WPAffiliateCaptain\Core\SettingsTransfer;

echo "R24 fset: settings transfer, wizard, reset, pages, copy pause\n";

$fs_saved_settings = get_option( 'wpac_settings' );
$fs_saved_wizard   = get_option( SetupWizard::OPTION, null );
$fs_saved_posts    = $GLOBALS['test_posts'] ?? array();
$fs_saved_status   = $GLOBALS['test_post_status'] ?? null;
$fs_set            = static function ( array $values ) {
	update_option( 'wpac_settings', array_merge( Settings::all(), $values ) );
	Settings::flush_cache();
};
if ( ! function_exists( 'get_page_by_path' ) ) {
	function get_page_by_path( $path, $output = OBJECT, $type = 'page' ) {
		foreach ( (array) $GLOBALS['test_posts'] as $p ) {
			if ( isset( $p->post_name, $p->post_type ) && $p->post_name === $path && in_array( $p->post_type, (array) $type, true ) ) {
				return $p;
			}
		}
		return null;
	}
}
SettingsSchema::flush();
$fs_set( array( 'currency' => 'EUR', 'exchange_rates' => '', 'default_rate_type' => 'percent', 'default_rate' => '10', 'min_payout_minor' => 5000, 'max_payout_minor' => 10000, 'fraud_max_order_minor' => 0, 'retention_hold' => 0, 'maintenance_mode' => 0 ) );

/* P1-10: the wizard checks the minimum payout against the stored maximum. ------------------- */
delete_option( SetupWizard::OPTION );
$fs_r = SetupWizard::save_step( 'commission', array( 'default_rate_type' => 'percent', 'default_rate' => '10', 'commission_basis' => 'net', 'hold_days' => '30', 'approval_mode' => 'auto', 'min_payout' => '200' ) );
Settings::flush_cache();
check(
	'P1-10 wizard: minimum payout 200 above the stored maximum 100 is refused at min_payout, stored min/max unchanged, step not done',
	array( true, 5000, 10000, false ),
	array( isset( $fs_r['errors']['min_payout'] ), (int) Settings::get( 'min_payout_minor' ), (int) Settings::get( 'max_payout_minor' ), isset( SetupWizard::state()['steps']['commission'] ) )
);
$fs_r = SetupWizard::save_step( 'commission', array( 'default_rate_type' => 'percent', 'default_rate' => '10', 'commission_basis' => 'net', 'hold_days' => '30', 'approval_mode' => 'auto', 'min_payout' => '80' ) );
Settings::flush_cache();
check( 'P1-10 wizard: minimum 80 below the maximum is saved; maximum and review limit untouched', array( array(), 8000, 10000, 0 ), array( $fs_r['errors'], (int) Settings::get( 'min_payout_minor' ), (int) Settings::get( 'max_payout_minor' ), (int) Settings::get( 'fraud_max_order_minor' ) ) );
$fs_set( array( 'min_payout_minor' => 5000 ) );

/* P1-11: minor units only as whole numbers. ------------------------------------------------- */
$fs_c1 = SettingsTransfer::check( array( 'min_payout_minor' => 'abc' ), Settings::all() );
$fs_c2 = SettingsTransfer::check( array( 'fraud_max_order_minor' => '2000,00' ), Settings::all() );
$fs_c3 = SettingsTransfer::check( array( 'default_rate_type' => 'fixed', 'default_rate' => '12.5' ), Settings::all() );
$fs_c4 = SettingsTransfer::preview( array( 'format' => 1, 'settings' => array( 'max_payout_minor' => '50 EUR' ) ) );
$fs_c5 = SettingsTransfer::check( array( 'min_payout_minor' => ' 6000 ', 'max_payout_minor' => 20000, 'fraud_max_order_minor' => '-5' ), Settings::all() );
$fs_c6 = SettingsTransfer::check( array( 'default_rate' => '12,5' ), Settings::all() ); // Percent: a percentage, not minor units.
check(
	'P1-11 minor units: "abc", "2000,00", fixed "12.5", "50 EUR" and "-5" rejected at the key; " 6000 " and 20000 taken; a percentage stays a percentage',
	array( true, array(), true, true, true, true, array( 'min_payout_minor' => 6000, 'max_payout_minor' => 20000 ), true, array( 'default_rate' => '12.5' ) ),
	array( isset( $fs_c1['errors']['min_payout_minor'] ), $fs_c1['values'], isset( $fs_c2['errors']['fraud_max_order_minor'] ), isset( $fs_c3['errors']['default_rate'], $fs_c3['errors']['default_rate_type'] ) && array() === $fs_c3['values'], isset( $fs_c4['errors']['max_payout_minor'] ), isset( $fs_c5['errors']['fraud_max_order_minor'] ), $fs_c5['values'], ! isset( $fs_c5['errors']['min_payout_minor'] ), $fs_c6['values'] )
);

/* P1-12: pages travel by path, are checked, and a tab reset keeps them. -------------------- */
$GLOBALS['test_posts'][90701] = (object) array( 'ID' => 90701, 'post_type' => 'page', 'post_name' => 'partner-agb', 'post_title' => 'Partner-AGB', 'post_status' => 'publish', 'post_content' => 'Gutschriftverfahren vereinbart.', 'post_password' => '', 'post_author' => 1 );
$GLOBALS['test_posts'][90702] = (object) array( 'ID' => 90702, 'post_type' => 'page', 'post_name' => 'partnerbereich', 'post_title' => 'Partnerbereich', 'post_status' => 'publish' );
$GLOBALS['test_posts'][90703] = (object) array( 'ID' => 90703, 'post_type' => 'post', 'post_name' => 'ein-beitrag', 'post_title' => 'Beitrag', 'post_status' => 'publish' );
$GLOBALS['test_posts'][90704] = (object) array( 'ID' => 90704, 'post_type' => 'page', 'post_name' => 'alt', 'post_title' => 'Alt', 'post_status' => 'trash' );
$GLOBALS['test_post_status'] = array( 90704 => 'trash' ) + (array) ( $fs_saved_status ?? array() );
$fs_set( array( 'terms_page_id' => 90701, 'portal_page_id' => 90702, 'registration_page_id' => 0 ) );
$fs_export = SettingsTransfer::export();
check(
	'P1-12 export: pages as path, no page ID; no page = empty path',
	array( array( 'page' => 'partner-agb' ), array( 'page' => 'partnerbereich' ), array( 'page' => '' ) ),
	array( $fs_export['settings']['terms_page_id'], $fs_export['settings']['portal_page_id'], $fs_export['settings']['registration_page_id'] )
);
// "Another site": the same pages exist with other IDs, the portal page is missing.
$fs_set( array( 'terms_page_id' => 0, 'portal_page_id' => 0 ) );
unset( $GLOBALS['test_posts'][90701], $GLOBALS['test_posts'][90702] );
$GLOBALS['test_posts'][90711] = (object) array( 'ID' => 90711, 'post_type' => 'page', 'post_name' => 'partner-agb', 'post_title' => 'Partner-AGB', 'post_status' => 'publish' );
$fs_imp = SettingsTransfer::preview( $fs_export );
check(
	'P1-12 import on another site: the page is found by its path (new ID), a missing page is an error at its key',
	array( 90711, true, false ),
	array( $fs_imp['values']['terms_page_id'] ?? null, isset( $fs_imp['errors']['portal_page_id'] ), isset( $fs_imp['values']['portal_page_id'] ) )
);
$fs_p1 = SettingsTransfer::check( array( 'terms_page_id' => '4711' ), Settings::all() );
$fs_p2 = SettingsTransfer::check( array( 'portal_page_id' => '90703' ), Settings::all() );
$fs_p3 = SettingsTransfer::check( array( 'registration_page_id' => '90704' ), Settings::all() );
Settings::sanitize( array( 'landing_page_id' => '4711' ), $fs_err );
$fs_p4 = SettingsTransfer::check( array( 'terms_page_id' => '90711' ), Settings::all() );
check(
	'P1-12 checked: unknown ID, a post and a trashed page refused (also the landing page); an existing page taken',
	array( true, true, true, true, array( 'terms_page_id' => 90711 ) ),
	array( isset( $fs_p1['errors']['terms_page_id'] ), isset( $fs_p2['errors']['portal_page_id'] ), isset( $fs_p3['errors']['registration_page_id'] ), isset( $fs_err['landing_page_id'] ), $fs_p4['values'] )
);
$fs_rk = SettingsTransfer::reset_keys( 'program' );
$fs_set( array( 'terms_page_id' => 90711 ) );
$fs_rp = SettingsTransfer::reset_preview( SettingsTransfer::reset_keys( '' ) );
check(
	'P1-12 a reset of the tab or of everything keeps the chosen pages (named as a key they can be reset)',
	array( false, false, false, false, false, true ),
	array( in_array( 'terms_page_id', $fs_rk, true ), in_array( 'portal_page_id', $fs_rk, true ), in_array( 'registration_page_id', $fs_rk, true ), in_array( 'imprint_page_id', SettingsTransfer::reset_keys( '' ), true ), isset( $fs_rp['values']['terms_page_id'] ), array_key_exists( 'terms_page_id', SettingsTransfer::reset_preview( array( 'terms_page_id' ) )['values'] ) )
);
$fs_set( array( 'terms_page_id' => 900, 'portal_page_id' => 0 ) );

/* P1-13: maintenance mode is local; an import does not end the copy pause. ------------------ */
$fs_set( array( 'maintenance_mode' => 1 ) );
update_option( Lifecycle::CLONE_OPTION, array( 'from' => 'https://old.example', 'at' => time() ) );
$fs_other = array( 'format' => 1, 'settings' => array( 'maintenance_mode' => 0, 'hold_days' => 21 ) );
$fs_mi    = SettingsTransfer::preview( $fs_other );
SettingsTransfer::apply( $fs_mi, 'settings.imported' );
Settings::flush_cache();
check(
	'P1-13 maintenance mode: not exported, ignored on import, not in the reset of "Data"; the copy pause stays',
	array( false, true, false, false, true, 1, 21 ),
	array( array_key_exists( 'maintenance_mode', SettingsTransfer::export()['settings'] ), in_array( 'maintenance_mode', $fs_mi['ignored'], true ), isset( $fs_mi['values']['maintenance_mode'] ), in_array( 'maintenance_mode', SettingsTransfer::reset_keys( 'data' ), true ), Lifecycle::copy_paused(), (int) Settings::get( 'maintenance_mode' ), (int) Settings::get( 'hold_days' ) )
);
delete_option( Lifecycle::CLONE_OPTION );
$fs_set( array( 'maintenance_mode' => 0, 'hold_days' => 30 ) );

/* P1-14: reset of currency- and type-dependent values. --------------------------------------- */
$fs_set( array( 'default_rate_type' => 'fixed', 'default_rate' => '2500' ) );
$fs_r1 = SettingsTransfer::reset_preview( array( 'default_rate' ) );
$fs_r2 = SettingsTransfer::reset_preview( array( 'default_rate_type' ) );
$fs_set( array( 'default_rate_type' => 'percent', 'default_rate' => '10', 'currency' => 'KWD', 'min_payout_minor' => 12345, 'max_payout_minor' => 0 ) );
$fs_r3 = SettingsTransfer::reset_preview( array( 'min_payout_minor' ) );
$fs_ksort = static function ( array $a ) { ksort( $a ); return $a; };
check(
	'P1-14 reset: the default rate goes back together with its type (10 %, not 0.10 €); KWD minimum payout = 50.000 KWD (50000), not 5000',
	array( array( 'default_rate' => '10', 'default_rate_type' => 'percent' ), array( 'default_rate' => '10', 'default_rate_type' => 'percent' ), array( 'min_payout_minor' => 50000 ), '50.00' ),
	array( $fs_ksort( $fs_r1['values'] ), $fs_ksort( $fs_r2['values'] ), $fs_r3['values'], SettingsTransfer::default_amount( 'min_payout_minor' ) )
);
check(
	'P1-14 preview values formatted: amounts in their currency, fixed rate as amount, percentage with %',
	array( Money::format( 50000, 'KWD' ), Money::format( 2500, 'EUR' ), '10 %', 'consent' ),
	array( SettingsTransfer::shown( 'min_payout_minor', 50000, array( 'currency' => 'KWD' ) ), SettingsTransfer::shown( 'default_rate', '2500', array( 'currency' => 'EUR', 'default_rate_type' => 'fixed' ) ), SettingsTransfer::shown( 'default_rate', '10', array( 'currency' => 'EUR', 'default_rate_type' => 'percent' ) ), SettingsTransfer::shown( 'cookie_mode', 'consent', array() ) )
);
$fs_set( array( 'currency' => 'EUR', 'min_payout_minor' => 5000, 'max_payout_minor' => 10000 ) );

/* P1-15: German operators of 0.21.0 with another currency keep the euro limits. -------------- */
delete_option( Settings::SMALL_BUSINESS_CURRENCY );
$fs_legacy = Settings::all();
unset( $fs_legacy['small_business_limit_previous'], $fs_legacy['small_business_limit_current'] );
update_option( 'wpac_settings', array_merge( $fs_legacy, array( 'operator_country' => 'DE', 'currency' => 'USD' ) ) );
Settings::flush_cache();
$fs_sb = array( (string) Settings::get( 'small_business_limit_previous' ), (string) Settings::get( 'small_business_limit_current' ), Settings::small_business_currency() );
$fs_sb_out = Settings::sanitize( array( 'currency' => 'CHF', 'small_business_limit_previous' => '25000' ), $fs_sb_err, $fs_sb_notes );
check(
	'P1-15 legacy DE + USD: limits 25,000 / 100,000 stay euro amounts (bound to EUR); a currency change names the limits',
	array( '25000', '100000', 'EUR', true ),
	array( $fs_sb[0], $fs_sb[1], $fs_sb[2], false !== strpos( (string) ( $fs_sb_notes['currency'] ?? '' ), 'small business limits (25000 / 100000) stay amounts in EUR' ) )
);
Settings::update( array( 'small_business_limit_previous' => '', 'small_business_limit_current' => '' ) );
Settings::flush_cache();
$fs_set( array( 'currency' => 'EUR', 'small_business_limit_previous' => '22000', 'small_business_limit_current' => '' ) );
Settings::sanitize( array( 'currency' => 'CHF' ), $fs_sb_err, $fs_sb_notes );
check(
	'P1-15 clearing both limits ends the binding; limits in the program currency: the currency change says they are not converted',
	array( 'EUR', false, true ),
	array( Settings::small_business_currency(), (bool) get_option( Settings::SMALL_BUSINESS_CURRENCY, '' ), false !== strpos( (string) ( $fs_sb_notes['currency'] ?? '' ), 'small business limits (22000) are not converted' ) )
);
$fs_set( array( 'operator_country' => 'DE', 'currency' => 'EUR', 'small_business_limit_previous' => '25000', 'small_business_limit_current' => '100000' ) );

/* P1-16: texts per language are not lost. -------------------------------------------------- */
$fs_set( array( 'default_disclosure_i18n' => '{"en_US":"Ad","fr_FR":"Publicité"}' ) );
$fs_t1 = Settings::sanitize( array( 'hold_days' => '30' ), $fs_err );
$fs_t2 = Settings::sanitize( array( 'default_disclosure_i18n' => array( 'en_US' => 'Advert' ) ), $fs_err );
$fs_t3 = Settings::sanitize( array( 'default_disclosure_i18n' => array( 'en_US' => 'Advert', 'fr_FR' => '' ) ), $fs_err );
$fs_t4 = Settings::sanitize( array( 'default_disclosure_i18n' => '{"es_ES":"Publicidad"}' ), $fs_err4 );
check(
	'P1-16 not sent = kept; fr_FR (not installed) kept on the form unless sent empty; an unknown language from a file still an error',
	array( '{"en_US":"Ad","fr_FR":"Publicité"}', '{"en_US":"Advert","fr_FR":"Publicité"}', '{"en_US":"Advert"}', true ),
	array( $fs_t1['default_disclosure_i18n'], $fs_t2['default_disclosure_i18n'], $fs_t3['default_disclosure_i18n'], isset( $fs_err4['default_disclosure_i18n'] ) )
);
$fs_set( array( 'default_disclosure_i18n' => '' ) );

/* P1-18: start numbers stay on the site. ---------------------------------------------------- */
$fs_sn = SettingsTransfer::preview( array( 'format' => 1, 'settings' => array( 'credit_start' => 500, 'storno_start' => 20, 'correction_start' => 3 ) ) );
check(
	'P1-18 start numbers: not exported, ignored on import, nothing changes',
	array( false, false, false, array( 'credit_start', 'storno_start', 'correction_start' ), array() ),
	array( array_key_exists( 'credit_start', SettingsTransfer::export()['settings'] ), array_key_exists( 'storno_start', SettingsTransfer::export()['settings'] ), array_key_exists( 'correction_start', SettingsTransfer::export()['settings'] ), $fs_sn['ignored'], $fs_sn['values'] )
);

/* P1-19: a retention hold is skipped with a notice, the rest is imported. ------------------- */
$fs_set( array( 'retention_hold' => 1 ) );
$fs_rh = SettingsTransfer::preview( array( 'format' => 1, 'settings' => array( 'retention_hold' => 'false', 'hold_days' => 14 ) ) );
check( 'P1-19 retention hold in a file: no error, a notice, the other values are imported ("false" does not lift it either)', array( array(), true, array( 'hold_days' => 14 ) ), array( $fs_rh['errors'], isset( $fs_rh['notices']['retention_hold'] ), $fs_rh['values'] ) );
if ( function_exists( 'h3_cli' ) ) {
	if ( ! class_exists( '\\WPAffiliateCaptain\\Cli\\SettingsCommand', false ) ) {
		require_once WPAC_DIR . 'src/Cli/SettingsCommand.php';
	}
	$fs_file = tempnam( sys_get_temp_dir(), 'wpacfs' );
	file_put_contents( $fs_file, wp_json_encode( array( 'format' => 1, 'settings' => array( 'retention_hold' => 0, 'hold_days' => 14 ) ) ) );
	$fs_cli = h3_cli( static function () use ( $fs_file ) { ( new \WPAffiliateCaptain\Cli\SettingsCommand() )->import( array( $fs_file ), array() ); } );
	unlink( $fs_file );
	Settings::flush_cache();
	$fs_warn = '';
	foreach ( $fs_cli['out'] as $fs_o ) {
		if ( 'warning' === $fs_o[0] ) {
			$fs_warn .= $fs_o[1];
		}
	}
	check( 'P1-19 WP-CLI import during a retention hold: completes, warns at retention_hold, hold stays on, hold_days 14', array( false, true, 1, 14 ), array( $fs_cli['halted'], false !== strpos( $fs_warn, 'retention_hold' ), (int) Settings::get( 'retention_hold' ), (int) Settings::get( 'hold_days' ) ) );
}
$fs_set( array( 'retention_hold' => 0, 'hold_days' => 30 ) );

/* P3-12: the currency notice of a country profile reaches the preview. --------------------- */
$fs_set( array( 'operator_country' => 'DE', 'currency' => 'EUR', 'exchange_rates' => '', 'min_payout_minor' => 5000, 'max_payout_minor' => 0 ) );
$fs_ch = CountryProfiles::preview( 'CH' );
check(
	'P3-12 CH profile on a EUR shop without rates: currency changes, the notice "amounts keep their value" is in the preview',
	array( 'CHF', true ),
	array( $fs_ch['values']['currency'] ?? '', false !== strpos( (string) ( $fs_ch['notices']['currency'] ?? '' ), 'amounts keep their value' ) )
);
$fs_cur = SettingsTransfer::check( array( 'cookie_days' => '400' ), Settings::all() );
check( 'P3-12 check() returns notices of accepted values (cookie lifetime above the advised maximum)', true, isset( $fs_cur['notices']['cookie_days'] ) );

// Restore.
update_option( 'wpac_settings', $fs_saved_settings );
if ( null === $fs_saved_wizard ) {
	delete_option( SetupWizard::OPTION );
} else {
	update_option( SetupWizard::OPTION, $fs_saved_wizard );
}
delete_option( Settings::SMALL_BUSINESS_CURRENCY );
$GLOBALS['test_posts'] = $fs_saved_posts;
if ( null === $fs_saved_status ) {
	unset( $GLOBALS['test_post_status'] );
} else {
	$GLOBALS['test_post_status'] = $fs_saved_status;
}
Settings::flush_cache();
SettingsSchema::flush();
