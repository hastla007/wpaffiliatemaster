<?php
/**
 * Fix round after the final review (24.11.2026), agent fset, render checks (after the render files:
 * template stubs): notices and formatted amounts in the preview of a country profile on the
 * settings page and in the wizard (P3-12, P1-14), texts of a language that is not installed
 * (P1-16), the log level shown after "verbose" ended (P1-17), the currency of the small business
 * limits (P1-15).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Admin\Pages\SettingsPage;
use WPAffiliateCaptain\Admin\Pages\SetupWizard;
use WPAffiliateCaptain\Core\CountryProfiles;
use WPAffiliateCaptain\Core\Log;
use WPAffiliateCaptain\Core\Money;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Core\SettingsSchema;

echo "R24 fset render: preview notices, texts per language, log level\n";

$fsr_saved = get_option( 'wpac_settings' );
$fsr_get   = $_GET;
$fsr_caps  = $GLOBALS['test_caps'] ?? null;
unset( $GLOBALS['test_caps'] );
$fsr_set = static function ( array $values ) {
	update_option( 'wpac_settings', array_merge( Settings::all(), $values ) );
	Settings::flush_cache();
};
SettingsSchema::flush();

/* P3-12 / P1-14: CH profile on a EUR shop without rates. ----------------------------------- */
$fsr_set( array( 'operator_country' => 'DE', 'currency' => 'EUR', 'exchange_rates' => '', 'min_payout_minor' => 5000, 'max_payout_minor' => 0, 'fraud_max_order_minor' => 0 ) );
$fsr_check = CountryProfiles::preview( 'CH' );
set_transient( 'wpac_settings_pending_' . get_current_user_id(), array( 'kind' => 'profile', 'profile' => 'CH', 'check' => $fsr_check ), 3600 );
$_GET  = array( 'page' => 'wpac-settings', 'tab' => 'payouts' );
$fsr_p = r_render( array( SettingsPage::class, 'render' ) );
delete_transient( 'wpac_settings_pending_' . get_current_user_id() );
SetupWizard::profile_preview( 'CH' );
$_GET  = array( 'page' => SetupWizard::SLUG, 'step' => 'country' );
$fsr_w = r_render( array( SetupWizard::class, 'render' ) );
delete_transient( 'wpac_setup_profile_' . get_current_user_id() );
// An import that changes the minimum payout: the preview shows amounts, not minor units.
set_transient( 'wpac_settings_pending_' . get_current_user_id(), array( 'kind' => 'import', 'tab' => '', 'check' => \WPAffiliateCaptain\Core\SettingsTransfer::preview( array( 'format' => 1, 'settings' => array( 'min_payout_minor' => 7000 ) ) ) ), 3600 );
$_GET  = array( 'page' => 'wpac-settings', 'tab' => 'data' );
$fsr_i = r_render( array( SettingsPage::class, 'render' ) );
delete_transient( 'wpac_settings_pending_' . get_current_user_id() );
check(
	'P3-12 the currency notice of the CH profile is shown on the settings page and in the wizard; P1-14 import preview with amounts (50,00 EUR → 70,00 EUR)',
	array( true, true, true, array(), '' ),
	array(
		(bool) preg_match( '/class="wpac-field-notice">[^<]*amounts keep their value/', $fsr_p[0] ),
		(bool) preg_match( '/class="wpac-field-notice">[^<]*amounts keep their value/', $fsr_w[0] ),
		false !== strpos( $fsr_i[0], '<td>' . esc_html( Money::format( 5000, 'EUR' ) ) . '</td><td>' . esc_html( Money::format( 7000, 'EUR' ) ) . '</td>' ),
		$fsr_p[1],
		$fsr_p[2],
	)
);

/* P1-15: limits taken over in euro are labelled with their currency. ---------------------- */
update_option( Settings::SMALL_BUSINESS_CURRENCY, 'EUR' );
$fsr_set( array( 'currency' => 'USD' ) );
$_GET  = array( 'page' => 'wpac-settings', 'tab' => 'payouts' );
$fsr_l = r_render( array( SettingsPage::class, 'render' ) );
delete_option( Settings::SMALL_BUSINESS_CURRENCY );
$fsr_set( array( 'currency' => 'EUR' ) );
check( 'P1-15 settings page: small business limits bound to EUR say "Currency: EUR." in a USD program', true, substr_count( $fsr_l[0], 'Currency: EUR.' ) >= 2 );

/* P1-16: a stored text of a language that is not installed gets its own field. ------------- */
$fsr_set( array( 'default_disclosure_i18n' => '{"fr_FR":"Publicité"}' ) );
$_GET  = array( 'page' => 'wpac-settings', 'tab' => 'promotion' );
$fsr_t = r_render( array( SettingsPage::class, 'render' ) );
$fsr_set( array( 'default_disclosure_i18n' => '' ) );
check(
	'P1-16 settings page: text of fr_FR (not installed) shown in its own field marked "not installed"',
	array( true, true ),
	array( false !== strpos( $fsr_t[0], 'fr_FR (not installed)' ), false !== strpos( $fsr_t[0], 'name="wpac[default_disclosure_i18n][fr_FR]" value="Publicité"' ) )
);

/* P1-17: after "verbose" ended the default level is selected. ------------------------------ */
$fsr_set( array( 'log_level' => 'verbose' ) );
update_option( Log::VERBOSE_UNTIL, time() - 1, false );
$_GET   = array( 'page' => 'wpac-settings', 'tab' => 'data' );
$fsr_d1 = r_render( array( SettingsPage::class, 'render' ) );
update_option( Log::VERBOSE_UNTIL, time() + DAY_IN_SECONDS, false );
$fsr_d2 = r_render( array( SettingsPage::class, 'render' ) );
delete_option( Log::VERBOSE_UNTIL );
check(
	'P1-17 log level: ended verbose → "notices" selected (saving does not restart 7 days); running verbose stays selected',
	array( true, false, true ),
	array( (bool) preg_match( '/<option value="notices"\s+selected=.selected.>/', $fsr_d1[0] ), (bool) preg_match( '/<option value="verbose"\s+selected=.selected.>/', $fsr_d1[0] ), (bool) preg_match( '/<option value="verbose"\s+selected=.selected.>/', $fsr_d2[0] ) )
);

update_option( 'wpac_settings', $fsr_saved );
Settings::flush_cache();
$_GET = $fsr_get;
if ( null !== $fsr_caps ) {
	$GLOBALS['test_caps'] = $fsr_caps;
}
