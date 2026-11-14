<?php
/**
 * Integration checks of work package G10: admin UX, internationalisation and translations
 * (locale switches, currency change, settings groups, partner list, audit texts, checklist,
 * screen options, RTL, localized numbers/countries/dates, slugs).
 * Included from tests/integration/run.php (uses its check() helper and database).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Admin\Admin;
use WPAffiliateCaptain\Admin\Pages\AuditPage;
use WPAffiliateCaptain\Admin\Pages\CommissionsPage;
use WPAffiliateCaptain\Admin\Pages\DashboardPage;
use WPAffiliateCaptain\Admin\Pages\SettingsPage;
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Affiliates\Agreements;
use WPAffiliateCaptain\Commissions\RuleRepository;
use WPAffiliateCaptain\Core\Locale;
use WPAffiliateCaptain\Core\Settings;

echo "G10 admin UX, i18n\n";

$g10_root = dirname( __DIR__, 2 );
$g10_src  = static function ( $file ) use ( $g10_root ) {
	return (string) file_get_contents( $g10_root . '/' . $file );
};
$g10_set  = static function ( array $values ) {
	update_option( 'wpac_settings', array_merge( (array) get_option( 'wpac_settings' ), $values ) );
	Settings::flush_cache();
};
$g10_settings_before = get_option( 'wpac_settings' );

// Locale stubs: a switch is observable (stack) and the text domain reload records its flag.
if ( ! defined( 'WP_LANG_DIR' ) ) {
	define( 'WP_LANG_DIR', sys_get_temp_dir() . '/wpac-g10-lang' );
}
$GLOBALS['g10_locale'] = 'en_US';
$GLOBALS['g10_stack']  = array();
$GLOBALS['g10_unload'] = array();
if ( ! function_exists( 'switch_to_locale' ) ) {
	function determine_locale() { return $GLOBALS['g10_locale']; }
	function switch_to_locale( $l ) { $GLOBALS['g10_stack'][] = $GLOBALS['g10_locale']; $GLOBALS['g10_locale'] = $l; return true; }
	function restore_previous_locale() { $GLOBALS['g10_locale'] = (string) array_pop( $GLOBALS['g10_stack'] ); return true; }
	function unload_textdomain( $d, $reloadable = false ) { $GLOBALS['g10_unload'][] = $reloadable; return true; }
	function load_textdomain( $d, $f, $l = null ) { return true; }
}
if ( ! function_exists( 'get_locale' ) ) {
	function get_locale() { return 'de_DE'; }
}

// R5-O1: a mail that throws still restores the locale (try/finally), the domain stays reloadable.
$g10_aff = AffiliateRepository::create( array( 'name' => 'Gerda Zehn', 'email' => 'gerda10@example.de', 'status' => 'active' ) );
$g10_throw = static function ( $mail, $type ) {
	if ( 'terms_reminder' === $type ) {
		throw new RuntimeException( 'g10 mail' );
	}
	return $mail;
};
add_filter( 'wpac_mail', $g10_throw, 10, 2 );
$g10_thrown = false;
try {
	Agreements::send_reminder( $g10_aff );
} catch ( RuntimeException $e ) {
	$g10_thrown = true;
}
remove_all_filters( 'wpac_mail' );
check( 'R5-O1 locale restored after an exception in the mail, text domain reloadable', array( true, 'en_US', array(), true ), array( $g10_thrown, $GLOBALS['g10_locale'], $GLOBALS['g10_stack'], ! in_array( false, $GLOBALS['g10_unload'], true ) && count( $GLOBALS['g10_unload'] ) >= 2 ) );
check( 'R5-O1 all listed locale pairs use try/finally', array( 0, 0, 0, 0, 0, 0 ), array_map(
	static function ( $f ) use ( $g10_src ) {
		$text = $g10_src( $f );
		return max( 0, substr_count( $text, 'Locale::restore(' ) - substr_count( $text, "} finally {\n" ) - substr_count( $text, "} finally {\r\n" ) );
	},
	array( 'src/Reports/MonthlyMail.php', 'src/Commissions/Dunning.php', 'src/Payouts/PayoutRequests.php', 'src/Payouts/Objections.php', 'src/Affiliates/Agreements.php', 'src/Privacy/Privacy.php' )
) );

// R5-O2 / R7-AD18: currency change converts stored minor amounts.
$g10_rates_old = array( 'JPY' => '0.0062', 'USD' => '0.92' );
check( 'R5-O2 conversion with the new currency in the old rates', array( 8065, 'rate' ), WPAffiliateCaptain\Core\Settings::convert_minor( 5000, 'EUR', 'JPY', $g10_rates_old, array() ) );
check( 'R5-O2 conversion with the old currency in the new rates', array( 5435, 'rate' ), Settings::convert_minor( 5000, 'EUR', 'USD', array(), array( 'EUR' => '1.087' ) ) );
check( 'R5-O2 without a rate the shown value is kept (50.00 EUR → 50 JPY)', array( array( 50, 'scaled' ), array( 5000, 'same' ) ), array( Settings::convert_minor( 5000, 'EUR', 'JPY', array(), array() ), Settings::convert_minor( 5000, 'EUR', 'eur', array(), array() ) ) );
check( 'R7-AD18 exchange rates are rebased to the new program currency', array( 'EUR' => '161.290323', 'USD' => '148.387097' ), Settings::rebase_rates( $g10_rates_old, 'EUR', 'JPY' ) );
check( 'R7-AD18 no rebase without a rate of the new currency', null, Settings::rebase_rates( array( 'USD' => '0.92' ), 'EUR', 'JPY' ) );
$g10_set( array( 'currency' => 'EUR', 'exchange_rates' => "JPY=0.0062\nUSD=0.92", 'min_payout_minor' => 5000, 'fraud_max_order_minor' => 200000, 'default_rate_type' => 'fixed', 'default_rate' => '2500' ) );
$g10_out = Settings::sanitize( array( 'currency' => 'JPY', 'exchange_rates' => "JPY=0.0062\nUSD=0.92", 'default_rate_type' => 'fixed', 'default_rate' => '25,00' ), $g10_err, $g10_notes );
check( 'R5-O2 / R7-AD18 EUR → JPY: minimum payout, review limit, fixed default rate and rates converted', array( 'JPY', 8065, 322581, '4032', "EUR=161.290323\nUSD=148.387097", true, true, array() ), array( $g10_out['currency'], $g10_out['min_payout_minor'], $g10_out['fraud_max_order_minor'], $g10_out['default_rate'], $g10_out['exchange_rates'], isset( $g10_notes['currency'] ), isset( $g10_notes['exchange_rates'] ), $g10_err ) );
$g10_out = Settings::sanitize( array( 'currency' => 'CHF', 'exchange_rates' => '' ), $g10_err, $g10_notes );
check( 'R5-O2 EUR → CHF without a rate: value kept and a warning', array( 5000, 200000, true ), array( $g10_out['min_payout_minor'], $g10_out['fraud_max_order_minor'], false !== strpos( (string) $g10_notes['currency'], 'CHF' ) ) );
$g10_out = Settings::sanitize( array( 'default_rate_type' => 'fixed' ), $g10_err, $g10_notes );
check( 'R5-O2 no conversion and no notice without a currency change', array( 5000, false ), array( $g10_out['min_payout_minor'], isset( $g10_notes['currency'] ) ) );
check( 'R7-AD18 the commissions tab also saves the converted amounts of other tabs', true, false !== strpos( $g10_src( 'src/Admin/Pages/SettingsPage.php' ), "\$keys[]              = 'min_payout_minor';" ) );

// R5-O12: an invalid rate keeps the type – and says so.
$g10_set( array( 'default_rate_type' => 'percent', 'default_rate' => '10' ) );
$g10_out = Settings::sanitize( array( 'default_rate_type' => 'fixed', 'default_rate' => 'abc' ), $g10_err, $g10_notes );
check( 'R5-O12 type kept with a message when the rate is invalid', array( 'percent', '10', true, true ), array( $g10_out['default_rate_type'], $g10_out['default_rate'], isset( $g10_err['default_rate'] ), isset( $g10_err['default_rate_type'] ) ) );
$g10_out = Settings::sanitize( array( 'default_rate_type' => 'percent', 'default_rate' => 'abc' ), $g10_err, $g10_notes );
check( 'R5-O12 no type message when the type was not changed', false, isset( $g10_err['default_rate_type'] ) );

// R5-O13 / R6-MS13: country names without WooCommerce/intl, sorted by language; localized percent.
$g10_wc = $GLOBALS['g1_wc'] ?? null;
$GLOBALS['g1_wc'] = null;
$g10_countries = Settings::countries();
check( 'R5-O13 countries have names, not ISO codes', array( 36, true, true ), array( count( $g10_countries ), 'DE' !== $g10_countries['DE'] && '' !== $g10_countries['DE'], 'AT' !== $g10_countries['AT'] ) );
check( 'R6-MS13 names sorted by language ("Österreich" before "Polen", not after "Zypern")', array( 'AT', 'PL', 'CY' ), array_keys( Settings::sort_names( array( 'CY' => 'Zypern', 'AT' => 'Österreich', 'PL' => 'Polen' ), 'de_DE' ) ) );
check( 'R6-MS13 commission and VAT rates localized', array( "12,5\u{00A0}%", "19\u{00A0}%", '7,7', '19' ), array( RuleRepository::format_rate( 'percent', '12.50' ), RuleRepository::format_rate( 'percent', '19' ), Locale::number( '7.70' ), Locale::number( '19.00' ) ) );

// R5-O54: a new installation takes currency and country from WooCommerce, the disclosure in the site language.
if ( ! function_exists( 'get_woocommerce_currency' ) ) {
	function get_woocommerce_currency() { return 'CHF'; }
}
class G10_Countries { public function get_countries() { return array(); } public function get_base_country() { return 'AT'; } }
$GLOBALS['g1_wc'] = (object) array( 'countries' => new G10_Countries() );
$g10_init = Settings::initial();
$GLOBALS['g1_wc'] = $g10_wc;
check( 'R5-O54 initial settings follow WooCommerce, disclosure translatable, fraud thresholds on', array( 'CHF', 'AT', 'Advertisement', 50 ), array( $g10_init['currency'], $g10_init['operator_country'], $g10_init['default_disclosure'], $g10_init['fraud_max_orders_day'] ) );
check( 'R5-O54 activation uses the initial settings', true, false !== strpos( $g10_src( 'src/Core/Installer.php' ), 'Settings::initial()' ) );

// R6-MS4: a URL-encoded slug is never cut inside an escape or a character.
$g10_slug = str_repeat( '%d0%b0', 12 ); // "аааааааааааа", 72 bytes.
$g10_cut  = AffiliateRepository::cut_slug( $g10_slug, 50 );
check( 'R6-MS4 cut slug is valid UTF-8 and ends on a whole character', array( true, true, 48 ), array( strlen( $g10_cut ) <= 50 && mb_check_encoding( rawurldecode( $g10_cut ), 'UTF-8' ), (bool) preg_match( '/^(%d0%b0)+$/', $g10_cut ), strlen( $g10_cut ) ) );
check( 'R6-MS4 a cut in the middle of a 3-byte character drops the whole character', '%e2%82%ac', AffiliateRepository::cut_slug( '%e2%82%ac%e2%82%ac', 12 ) );
check( 'R6-MS4 Latin slugs are cut as before', 'abcde', AffiliateRepository::cut_slug( 'abcdefgh', 5 ) );

// R6-MS7: admin dates via wp_date() (translated month names, site time zone).
update_option( 'date_format', 'j. F Y' );
update_option( 'time_format', 'H:i' );
check( 'R6-MS7 Admin::date() uses wp_date()', array( wp_date( 'j. F Y', strtotime( '2026-03-03 10:00:00 UTC' ) ), '—', true ), array( Admin::date( '2026-03-03 10:00:00' ), Admin::date( '' ), false !== strpos( $g10_src( 'src/Admin/Admin.php' ), 'return (string) wp_date( $format, $ts );' ) ) );

// R6-MS12: no string pieces glued together, translator comments in place.
$g10_frag = 0;
foreach ( array( 'src/Admin/Tables/CommissionsTable.php', 'src/Admin/Pages/AffiliatesPage.php', 'src/Admin/Pages/DashboardPage.php', 'src/Admin/Pages/CommissionsPage.php', 'src/Affiliates/Notifications.php', 'src/Portal/Portal.php' ) as $f ) {
	$g10_frag += preg_match_all( "/esc_html__\\( '(until|from|to|Reason:)', 'affiliate-captain' \\)|__\\( 'Reason', 'affiliate-captain' \\) \\. ': '/", $g10_src( $f ) );
}
check( 'R6-MS12 no glued pieces ("until" + date, "from"/"to", "Reason:" + text)', 0, $g10_frag );
check( 'R6-MS12 translator comments directly at "Example: %s" and "Partner landing URLs look like %s"', array( true, true ), array( false !== strpos( $g10_src( 'src/Admin/Pages/SettingsPage.php' ), "/* translators: %s: example URL with the link parameter */ __( 'Example: %s'" ), false !== strpos( $g10_src( 'src/Admin/Pages/SettingsPage.php' ), "/* translators: %s: example URL of a partner landing page */ __( 'Partner landing URLs look like %s'" ) ) );

// R5-O8: audit codes and setting keys shown by name.
check( 'R5-O8 payout method, details change and mail result readable', 'Payout method: Other payment method (e.g. PayPal), paid manually; Payment details changed: yes; E-mail: failed', AuditPage::details_text( '{"payout_method":"manual","details_changed":true,"mail":"failed"}' ) );
check( 'R5-O8 changed setting keys named', 'Changed: Hold period (days after payment), Maintenance mode', AuditPage::details_text( '{"changed":["hold_days","maintenance_mode"]}' ) );
check( 'R5-O8 every setting has a name', array(), array_values( array_diff( array_keys( Settings::defaults() ), array_keys( SettingsPage::setting_labels() ), array( 'payout_period', 'exchange_rates', 'currency', 'monitor_token', 'default_rate' ) ) ) );

// R5-O45: settings regrouped (all retention periods in one tab), no key in two tabs, old links work.
$g10_tabs = SettingsPage::tab_keys();
$g10_all  = array();
foreach ( $g10_tabs as $keys ) {
	$g10_all = array_merge( $g10_all, $keys );
}
check( 'R5-O45 every key in exactly one tab, retention periods and maintenance together, e-mails in their own tab', array( count( $g10_all ), true, true, 'data' ), array( count( array_unique( $g10_all ) ), ! array_diff( array( 'visit_retention_days', 'application_retention_days', 'audit_retention_days', 'statement_retention_years', 'retention_hold', 'maintenance_mode' ), $g10_tabs['data'] ), ! array_diff( array( 'admin_email', 'mail_from_email', 'report_admin', 'report_partners' ), $g10_tabs['emails'] ), SettingsPage::tab_alias( 'reports' ) ) );
check( 'R5-O45 all saved settings reachable in a tab (except hidden ones)', array(), array_values( array_diff( array_keys( Settings::defaults() ), $g10_all, array( 'payout_period', 'fraud_max_order_minor' ) ) ) );
$g10_set( array( 'operator_country' => 'AT' ) );
$g10_out = Settings::sanitize( array( 'statement_retention_years' => '7' ), $g10_err, $g10_notes );
check( 'R5-O45 retention minimum uses the stored country when the country is on another tab', array( 'AT', 7, false ), array( $g10_out['operator_country'], $g10_out['statement_retention_years'], isset( $g10_notes['statement_retention_years'] ) ) );

// R5-O9: reminders not started in maintenance mode; only real recipients counted.
$g10_set( array( 'maintenance_mode' => 1 ) );
delete_option( Agreements::REMIND_OPTION );
$g10_r = Agreements::start_reminders();
$g10_set( array( 'maintenance_mode' => 0 ) );
check( 'R5-O9 no silent waiting in maintenance mode', 'wpac_maintenance', is_wp_error( $g10_r ) ? $g10_r->get_error_code() : $g10_r );
$g10_b = AffiliateRepository::create( array( 'name' => 'Bea Zehn', 'email' => 'bea10@example.de', 'status' => 'active' ) );
set_transient( 'wpac_terms_reminded_' . $g10_b, 1 );
check( 'R5-O9 recently reminded partners are not counted', array( $g10_aff ), Agreements::reminder_recipients( array( $g10_aff, $g10_b ) ) );
check( 'R5-O9 "1 e-mail" in singular, recipient count in the dialog', array( true, true ), array( false !== strpos( $g10_src( 'src/Admin/Pages/AffiliatesPage.php' ), "_n( 'Last reminder on %1\$s: %2\$d e-mail sent.'" ), false !== strpos( $g10_src( 'src/Admin/Pages/AffiliatesPage.php' ), "_n( 'Send an e-mail to %d active partner without the current acceptance?'" ) ) );

// R5-O16: the same text for every refused link, whatever the reason (without the user list right).
$GLOBALS['test_caps'] = array( 'wpac_manage_affiliates' );
$g10_l1 = AffiliateRepository::link_user( $g10_aff, 'nobody10@example.de' );
$GLOBALS['test_users'] = ( $GLOBALS['test_users'] ?? array() ) + array( 610 => (object) array( 'ID' => 610, 'user_email' => 'other10@example.de', 'user_login' => 'other10', 'roles' => array( 'customer' ) ) );
$g10_l2 = AffiliateRepository::link_user( $g10_aff, '610' );
unset( $GLOBALS['test_caps'] );
check( 'R5-O16 unknown account and foreign e-mail give the same message', array( true, true ), array( is_wp_error( $g10_l1 ) && is_wp_error( $g10_l2 ), is_wp_error( $g10_l1 ) && is_wp_error( $g10_l2 ) && $g10_l1->get_error_message() === $g10_l2->get_error_message() ) );
$g10_l3 = AffiliateRepository::link_user( $g10_aff, 'nobody10@example.de' );
check( 'R5-O16 administrators still see the exact reason', 'No WordPress user with this ID or e-mail address was found.', is_wp_error( $g10_l3 ) ? $g10_l3->get_error_message() : '' );

// R5-O51: partner filter by name, e-mail or number; audit object types as a list; history link.
check( 'R5-O51 partner filter by number, name and e-mail; no or several matches → empty list', array( $g10_aff, $g10_aff, $g10_aff, -1, 0 ), array( CommissionsPage::partner_filter( (string) $g10_aff ), CommissionsPage::partner_filter( 'Gerda Zehn' ), CommissionsPage::partner_filter( 'gerda10@example.de' ), CommissionsPage::partner_filter( 'Zehn' ), CommissionsPage::partner_filter( '' ) ) );
check( 'R5-O51 audit object types as a list with names', array( 'Partner', 'Payout' ), array( AuditPage::object_types()['affiliate'], AuditPage::object_types()['payout'] ) );
check( 'R5-O51 product search and category list in the rule form, history link to the audit log', array( true, true ), array( false !== strpos( $g10_src( 'src/Admin/Pages/RulesPage.php' ), 'woocommerce_json_search_products_and_variations' ), false !== strpos( $g10_src( 'src/Admin/Pages/AffiliatesPage.php' ), "'wpac-audit', array( 'object_type' => 'affiliate', 'object_id' => \$id )" ) ) );

// R5-O52: more columns in the partner list (loaded in one go), bulk approve/reject only for applications.
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php'; // Minimal test version (also used by r8-render.php).
}
$wpdb->insert( t( 'balances' ), array( 'affiliate_id' => $g10_aff, 'currency' => 'EUR', 'balance_minor' => 1234, 'updated_at' => WPAffiliateCaptain\Core\Db::now() ) );
$g10_x = WPAffiliateCaptain\Admin\Tables\AffiliatesTable::extra_data( array( $g10_aff, $g10_b ) );
$g10_main = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(code) FROM ' . t( 'partner_codes' ) . " WHERE affiliate_id = %d AND status = 'active'", $g10_aff ) );
check( 'R5-O52 code, balance, payout details, terms and login per partner', array( $g10_main, array( 'EUR' => 1234 ), false, Agreements::has_current( $g10_b ), (bool) AffiliateRepository::find( $g10_b )->user_id ), array( $g10_x[ $g10_aff ]['code'], $g10_x[ $g10_aff ]['balances'], $g10_x[ $g10_aff ]['payout'], $g10_x[ $g10_b ]['terms'], $g10_x[ $g10_b ]['login'] ) );
$wpdb->delete( t( 'balances' ), array( 'affiliate_id' => $g10_aff ) );
check( 'R5-O52 bulk reject of applications', true, false !== strpos( $g10_src( 'src/Admin/Tables/AffiliatesTable.php' ), "'reject'  => __( 'Reject applications'" ) && false !== strpos( $g10_src( 'src/Admin/Pages/AffiliatesPage.php' ), "'approve' === \$action ? 'active' : 'rejected'" ) );

if ( ! function_exists( 'get_post_status' ) ) {
	function get_post_status( $id ) { return $GLOBALS['g10_post_status'][ (int) $id ] ?? false; }
}
if ( ! function_exists( 'get_edit_post_link' ) ) {
	function get_edit_post_link( $id, $context = 'display' ) { return ''; }
}
// R5-O44 / R5-W61: checklist with optional items; setup notice until the required items are done.
$g10_items = DashboardPage::checklist_items();
check( 'R5-O44 sales source checked; SEPA account and own key optional', array( 9, true, false, false ), array( count( $g10_items ), $g10_items[0][3], $g10_items[7][3], $g10_items[8][3] ) );
check( 'R5-O44 terms template with the self-billing agreement', true, false !== strpos( DashboardPage::terms_template(), 'self-billing' ) );
check( 'R5-W61 setup notice follows the checklist, not only the partner area page', true, DashboardPage::setup_open() && false !== strpos( $g10_src( 'src/Admin/Admin.php' ), 'DashboardPage::setup_open()' ) );
update_option( 'wpac_activation_redirect', 4242 );
Admin::activation_redirect();
check( 'R5-W61 the redirect after activation waits for the user who activated the plugin', 4242, get_option( 'wpac_activation_redirect' ) );
if ( ! function_exists( 'wp_doing_ajax' ) ) {
	function wp_doing_ajax() { return false; }
}
update_option( 'wpac_activation_redirect', get_current_user_id() );
$_GET['activate-multi'] = 'true';
Admin::activation_redirect();
unset( $_GET['activate-multi'] );
check( 'R5-W61 bulk activation: no redirect, flag removed', false, get_option( 'wpac_activation_redirect', false ) );

// R5-O47: screen options per page (bounded), help tabs on the main pages.
check( 'R5-O47 rows per page stored within 1–500, other options untouched', array( 500, 1, 'x' ), array( Admin::save_screen_option( false, 'wpac_partners_per_page', '9999' ), Admin::save_screen_option( false, 'wpac_commissions_per_page', '0' ), Admin::save_screen_option( 'x', 'other_option', '5' ) ) );
check( 'R5-O47 help tabs for dashboard, partners, commissions, payouts and settings', array( 'wpac', 'wpac-affiliates', 'wpac-commissions', 'wpac-payouts', 'wpac-settings' ), array_keys( Admin::help_texts() ) );

// R5-O43 / R5-O46 / R5-O48 / R5-O49 / R5-O50 / R5-O36 / R6-MS11: markup checks.
$g10_pay = $g10_src( 'src/Admin/Pages/PayoutsPage.php' );
check( 'R5-O43 tabs with aria-current, wide tables in a scroll box', array( true, true ), array( false !== strpos( $g10_src( 'src/Admin/Pages/DashboardPage.php' ), "' aria-current=\"page\"' : ''" ), false !== strpos( $g10_src( 'assets/js/admin.js' ), 'wpac-table-scroll' ) && false !== strpos( $g10_src( 'assets/css/admin.css' ), '.wpac-table-scroll' ) ) );
check( 'R5-O46 integrations page in tabs without fixed add-on rows; import before settings in the menu', array( true, false, true ), array( isset( WPAffiliateCaptain\Admin\Pages\IntegrationsPage::tabs()['queue'] ), false !== strpos( $g10_src( 'src/Admin/Pages/IntegrationsPage.php' ), "'pass-captain'" ), strpos( $g10_src( 'src/Admin/Admin.php' ), 'ImportExportPage::menu();' ) < strpos( $g10_src( 'src/Admin/Admin.php' ), "'wpac-settings', array( SettingsPage::class" ) ) );
check( 'R5-O48 payout details depend on the method, creative image with preview and labels', array( true, true, false ), array( false !== strpos( $g10_src( 'src/Admin/Pages/AffiliatesPage.php' ), 'data-wpac-depends="payout_method=' ), false !== strpos( $g10_src( 'src/Admin/Pages/CreativesPage.php' ), 'wpac-attachment-preview' ), false !== strpos( $g10_src( 'src/Admin/Pages/CreativesPage.php' ), 'type="number" name="attachment_id"' ) ) );
check( 'R5-O49 bulk fields depend on the action, one confirmation, fix link in the preview, no inline script', array( true, false, true, false ), array( false !== strpos( $g10_pay, 'data-wpac-depends="bulk_action=cancel"' ), false !== strpos( $g10_pay, "data-wpac-confirm=\"' . esc_attr__( 'Run this action for the selected payouts?'" ), false !== strpos( $g10_pay, 'self::fix_link( $row )' ), false !== strpos( $g10_pay, '<script>' ) ) );
check( 'R5-O50 empty lists name the next step', array( true, true, true, true ), array( false !== strpos( $g10_src( 'src/Admin/Tables/AffiliatesTable.php' ), 'No partners yet.' ), false !== strpos( $g10_src( 'src/Admin/Tables/CommissionsTable.php' ), 'No commissions yet.' ), false !== strpos( $g10_pay, 'No payouts yet. Approved commissions' ), false !== strpos( $g10_src( 'src/Admin/Pages/AuditPage.php' ), 'No entries yet.' ) ) );
check( 'R5-O36 target page fields with label and button, no inline onclick', array( false, true ), array( false !== strpos( $g10_src( 'src/Content/Editor.php' ), 'onclick=' ), false !== strpos( $g10_src( 'src/Content/Editor.php' ), 'data-wpac-copy=' ) ) );
check( 'R6-MS11 RTL stylesheet for the partner area, logical properties in the admin, IBAN/BIC/URL fields left-to-right', array( true, false, true, true ), array( is_readable( $g10_root . '/assets/css/portal-rtl.css' ) && false !== strpos( $g10_src( 'src/Portal/Portal.php' ), "Locale::enqueue_rtl( 'wpac-portal', 'portal' )" ), (bool) preg_match( '/(margin|padding|border)-(left|right)/', $g10_src( 'assets/css/admin.css' ) ), false !== strpos( $g10_src( 'src/Admin/Pages/AffiliatesPage.php' ), "'autocomplete=\"off\" dir=\"ltr\"" ), false !== strpos( $g10_src( 'src/Admin/Pages/CreativesPage.php' ), 'name="landing_url" dir="ltr"' ) ) );

// R5-O15: consistent German terms.
$g10_de = $g10_src( 'languages/de_DE.json' );
check( 'R5-O15 German: pseudonymised hash, one word for the terms and the tiers, correct settings path', array( 0, 0, 0, 0, true ), array( substr_count( $g10_de, 'verschlüsselten Hash' ) + substr_count( $g10_de, 'verschlüsselte Hashes' ), substr_count( $g10_de, 'AGB' ), substr_count( $g10_de, 'Staffeln' ), substr_count( $g10_src( 'src/Payouts/PayoutService.php' ), 'Settings → Program → Terms page' ), false !== strpos( $g10_de, 'pseudonymisierten Hashwert' ) ) );

update_option( 'wpac_settings', $g10_settings_before );
Settings::flush_cache();
