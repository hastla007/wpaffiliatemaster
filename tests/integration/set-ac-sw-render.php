<?php
/**
 * SET-AC-26/28 render checks: admin menu, settings page, rules page, partner list and partner area
 * with features switched on and off; the log level field with its end. Included from run.php after
 * the render files (uses the template stubs of r8-render.php).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Admin\Admin;
use WPAffiliateCaptain\Admin\Pages\AffiliatesPage;
use WPAffiliateCaptain\Admin\Pages\RulesPage;
use WPAffiliateCaptain\Admin\Pages\SettingsPage;
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Core\Log;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Core\SettingsSchema;
use WPAffiliateCaptain\Portal\Portal;
use WPAffiliateCaptain\Promotion\Landing;
use WPAffiliateCaptain\Rest\RestApi;

echo "SET-AC-26/28 render: menu, settings, partner area\n";

global $wpdb;
$swr_saved = get_option( 'wpac_settings' );
$swr_get   = $_GET;
$swr_user  = isset( $GLOBALS['test_user'] ) ? $GLOBALS['test_user'] : 0;
$swr_caps  = $GLOBALS['test_caps'] ?? null;
unset( $GLOBALS['test_caps'] );
SettingsSchema::flush();
$swr_set = static function ( array $values ) {
	Settings::update( $values );
	Settings::flush_cache();
};
if ( ! function_exists( 'add_menu_page' ) ) {
	function add_menu_page( $title, $menu, $cap, $slug ) { $GLOBALS['swr_menu_slugs'][] = $slug; return 'toplevel_page_' . $slug; }
}
if ( ! function_exists( 'add_submenu_page' ) ) {
	function add_submenu_page( $parent, $title, $menu, $cap, $slug ) { $GLOBALS['swr_menu_slugs'][] = $slug; return 'wpac_page_' . $slug; }
}
$swr_menu = static function () {
	$GLOBALS['swr_menu_slugs'] = array();
	Admin::menu();
	return $GLOBALS['swr_menu_slugs'];
};

/* Admin: menu, settings page, rules page, partner list ----------------------------------------- */
$swr_m_on = $swr_menu();
$swr_set( array( 'feature_creatives' => 0 ) );
$swr_m_off = $swr_menu();
$swr_set( array( 'feature_creatives' => 1 ) );
check( 'SET-AC-26 creatives: menu item only while on (other items unchanged)', array( true, false, true, true ), array( in_array( 'wpac-creatives', $swr_m_on, true ), in_array( 'wpac-creatives', $swr_m_off, true ), in_array( 'wpac-rules', $swr_m_off, true ), count( $swr_m_on ) === count( $swr_m_off ) + 1 ) );

$_GET   = array( 'page' => 'wpac-settings', 'tab' => 'program' );
$swr_sp = r_render( array( SettingsPage::class, 'render' ) );
check( 'SET-AC-26 settings page: section Features with the five switches (on by default)', array( true, 5, 5, array(), '' ), array( false !== strpos( $swr_sp[0], '<h2>Features</h2>' ), preg_match_all( '/name="wpac\[feature_(creatives|landing|tiers|qr|broadcast)\]" value="1"/', $swr_sp[0] ), preg_match_all( '/name="wpac\[feature_[a-z]+\]" value="1"[^>]*checked=.checked./', $swr_sp[0] ), $swr_sp[1], $swr_sp[2] ) );

$_GET   = array( 'page' => 'wpac-settings', 'tab' => 'data' );
$swr_d1 = r_render( array( SettingsPage::class, 'render' ) );
$swr_set( array( 'log_level' => 'verbose' ) );
update_option( Log::VERBOSE_UNTIL, time() + 7 * DAY_IN_SECONDS, false );
$swr_d2 = r_render( array( SettingsPage::class, 'render' ) );
update_option( Log::VERBOSE_UNTIL, time() - 1, false );
$swr_d3 = r_render( array( SettingsPage::class, 'render' ) );
$swr_set( array( 'log_level' => 'notices' ) );
check( 'SET-AC-28 settings page: log level field (default selected), end of verbose shown at the field, ended verbose explained', array( true, true, false, true, true ), array( false !== strpos( $swr_d1[0], '<select id="wpac-log_level" name="wpac[log_level]"' ), (bool) preg_match( '/<option value="notices"\s+selected=.selected.>/', $swr_d1[0] ), false !== strpos( $swr_d1[0], 'Verbose is on until' ), false !== strpos( $swr_d2[0], 'Verbose is on until ' . wp_date( (string) get_option( 'date_format' ), time() + 7 * DAY_IN_SECONDS ) ), false !== strpos( $swr_d3[0], 'The 7 days of verbose logging have ended' ) ) );

$_GET   = array( 'page' => 'wpac-rules' );
$swr_r1 = r_render( array( RulesPage::class, 'render' ) );
$swr_set( array( 'feature_tiers' => 0 ) );
$swr_r2 = r_render( array( RulesPage::class, 'render' ) );
$_GET   = array( 'page' => 'wpac-rules', 'tab' => 'tiers' );
$swr_r3 = r_render( array( RulesPage::class, 'render' ) );
$swr_set( array( 'feature_tiers' => 1 ) );
delete_option( WPAffiliateCaptain\Commissions\TierService::OFF_PERIODS );
check( 'SET-AC-26 tiers: tab Monthly tiers only while on (a direct link shows the rules)', array( true, false, false, array(), '' ), array( false !== strpos( $swr_r1[0], 'Monthly tiers' ), false !== strpos( $swr_r2[0], 'Monthly tiers' ), false !== strpos( $swr_r3[0], 'Add tier plan' ), $swr_r3[1], $swr_r3[2] ) );

$_GET   = array( 'page' => 'wpac-affiliates' );
$swr_a1 = r_render( array( AffiliatesPage::class, 'render' ) );
$swr_set( array( 'feature_broadcast' => 0 ) );
$swr_a2 = r_render( array( AffiliatesPage::class, 'render' ) );
$swr_set( array( 'feature_broadcast' => 1 ) );
check( 'SET-AC-26 broadcast: box "Write to all active partners" only while on', array( true, false, '' ), array( false !== strpos( $swr_a1[0], 'Write to all active partners' ), false !== strpos( $swr_a2[0], 'Write to all active partners' ), $swr_a2[2] ) );

/* Partner area -------------------------------------------------------------------------------- */
$swr_uid = 926001;
$swr_aff = AffiliateRepository::create( array( 'name' => 'SW Render', 'email' => 'sw-render@example.de', 'status' => 'active', 'user_id' => $swr_uid ) );
$wpdb->update( t( 'affiliates' ), array( 'user_id' => $swr_uid ), array( 'id' => (int) $swr_aff ) );
$GLOBALS['test_users'][ $swr_uid ] = (object) array( 'ID' => $swr_uid, 'user_email' => 'sw-render@example.de', 'display_name' => 'SW', 'user_login' => 'sw-render', 'roles' => array( 'customer' ) );
$GLOBALS['test_user']              = $swr_uid;
$swr_portal = static function ( $tab ) {
	$_GET = array( 'wpac_tab' => $tab );
	return r_render( array( Portal::class, 'render' ) );
};
$swr_p_on  = $swr_portal( 'links' );
$swr_acc1  = $swr_portal( 'account' );
$swr_prom1 = $swr_portal( 'promotion' );
$swr_set( array( 'feature_creatives' => 0 ) );
$swr_prom2 = $swr_portal( 'promotion' );
$swr_set( array( 'feature_creatives' => 1 ) );
check( 'SET-AC-26 creatives: partner area tab Creatives only while on (a direct link opens the first tab)', array( true, true, false, false, array(), '' ), array( false !== strpos( $swr_p_on[0], 'wpac_tab=promotion' ), false !== strpos( $swr_prom1[0], 'Approved creatives' ), false !== strpos( $swr_prom2[0], 'wpac_tab=promotion' ), false !== strpos( $swr_prom2[0], 'Approved creatives' ), $swr_prom2[1], $swr_prom2[2] ) );

$swr_set( array( 'feature_qr' => 0 ) );
$swr_p_noqr = $swr_portal( 'links' );
$swr_set( array( 'feature_qr' => 1, 'feature_landing' => 0 ) );
$swr_p_nolanding = $swr_portal( 'links' );
$swr_set( array( 'feature_qr' => 0 ) );
$swr_p_none = $swr_portal( 'links' );
$swr_set( array( 'feature_qr' => 1, 'feature_landing' => 1 ) );
$swr_short = Landing::url( AffiliateRepository::find( $swr_aff ) );
check(
	'SET-AC-26 QR / landing: QR code and short link in "Links & codes" only while on (QR of the partner link without landing pages)',
	array( true, true, false, true, true, false, true, false, false, array(), '' ),
	array(
		false !== strpos( $swr_p_on[0], '<div class="wpac-qr">' ),
		false !== strpos( $swr_p_on[0], $swr_short ),
		false !== strpos( $swr_p_noqr[0], '<div class="wpac-qr">' ),
		false !== strpos( $swr_p_noqr[0], $swr_short ),
		false !== strpos( $swr_p_nolanding[0], '<div class="wpac-qr">' ),
		false !== strpos( $swr_p_nolanding[0], $swr_short ),
		false !== strpos( $swr_p_nolanding[0], '<h3>QR code</h3>' ),
		false !== strpos( $swr_p_none[0], '<div class="wpac-qr">' ),
		false !== strpos( $swr_p_none[0], $swr_short ),
		$swr_p_none[1],
		$swr_p_none[2],
	)
);

$swr_set( array( 'feature_broadcast' => 0 ) );
$swr_acc2 = $swr_portal( 'account' );
$swr_set( array( 'feature_broadcast' => 1 ) );
check( 'SET-AC-26 broadcast: the partner\'s choice "News and announcements" only while on', array( true, false, true ), array( false !== strpos( $swr_acc1[0], 'value="broadcast"' ), false !== strpos( $swr_acc2[0], 'value="broadcast"' ), false !== strpos( $swr_acc2[0], 'value="commission"' ) ) );

// Landing off: the welcome shortcode and the REST field are empty.
$swr_set( array( 'feature_landing' => 0 ) );
$_GET           = array( (string) Settings::get( 'referral_param' ) => (string) WPAffiliateCaptain\Codes\CodeRepository::main_code( $swr_aff ) );
$swr_welcome    = Landing::welcome( array() );
$swr_me_off     = RestApi::is_partner() ? RestApi::me()['landing_url'] : null;
$swr_set( array( 'feature_landing' => 1 ) );
$swr_me_on      = RestApi::is_partner() ? RestApi::me()['landing_url'] : null;
check( 'SET-AC-26 landing off: [wpac_partner_welcome] shows nothing, REST /me landing_url empty (filled while on)', array( '', '', $swr_short ), array( $swr_welcome, $swr_me_off, $swr_me_on ) );

$_GET                 = $swr_get;
$GLOBALS['test_user'] = $swr_user;
if ( null !== $swr_caps ) {
	$GLOBALS['test_caps'] = $swr_caps;
}
update_option( 'wpac_settings', $swr_saved );
delete_option( Log::VERBOSE_UNTIL );
Settings::flush_cache();
SettingsSchema::flush();
