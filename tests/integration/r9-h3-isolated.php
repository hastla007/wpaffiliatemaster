<?php
/**
 * R9 package H3 checks that need process-wide stand-ins: WPML and Polylang (language functions
 * and filters), get_locale() following the request language as with both plugins, and the
 * uninstall on a network (wizard pages, time budget). Started by r9-h3.php in its own process
 * with its own table prefix; prints the results as JSON on the last line: name => [ expected, actual ].
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
require __DIR__ . '/bootstrap.php';

use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Affiliates\Notifications;
use WPAffiliateCaptain\Core\Locale;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Payouts\PayoutService;

$h3 = array();
function h3( $name, $expected, $actual ) {
	global $h3;
	$h3[ $name ] = array( $expected, $actual );
}

// Locale stand-ins as in WordPress: get_locale() runs the "locale" filter (WPML/Polylang set it to
// the language of the request), switch_to_locale() is observable.
define( 'WP_LANG_DIR', sys_get_temp_dir() . '/wpac-h3-lang' );
$GLOBALS['h3_locale'] = 'fr_FR'; // A request on the French version of the site.
$GLOBALS['h3_stack']  = array();
function get_locale() { return apply_filters( 'locale', 'de_DE' ); }
function determine_locale() { return $GLOBALS['h3_locale']; }
function switch_to_locale( $l ) { $GLOBALS['h3_stack'][] = $GLOBALS['h3_locale']; $GLOBALS['h3_locale'] = $l; return true; }
function restore_previous_locale() { $GLOBALS['h3_locale'] = (string) array_pop( $GLOBALS['h3_stack'] ); return true; }
function unload_textdomain( $d, $reloadable = false ) { return true; }
function load_textdomain( $d, $f, $l = null ) { return true; }
function get_user_locale( $u = 0 ) { $u = is_object( $u ) ? $u : get_userdata( (int) $u ); return $u && ! empty( $u->locale ) ? $u->locale : get_locale(); }
function has_filter( $tag, $cb = false ) { return ! empty( $GLOBALS['test_hooks'][ $tag ] ); }
function get_post_type( $id ) { return 'page'; }
function wp_lostpassword_url( $r = '' ) { return 'http://example.test/wp-login.php?action=lostpassword'; }

// Mails: language at the time of sending and the text.
$GLOBALS['h3_mails'] = array();
add_filter(
	'wpac_mail',
	static function ( $mail, $type ) {
		$GLOBALS['h3_mails'][] = array( 'type' => $type, 'to' => $mail['to'], 'locale' => determine_locale(), 'message' => $mail['message'] );
		return false; // Not sent.
	},
	10,
	2
);
$h3_last = static function () {
	$m = end( $GLOBALS['h3_mails'] );
	return $m ? $m : array( 'type' => '', 'to' => '', 'locale' => '', 'message' => '' );
};

update_option( 'wpac_settings', array_merge( Settings::defaults(), array( 'portal_page_id' => 50, 'admin_email' => 'chef@example.de' ) ) );
Settings::flush_cache();
$GLOBALS['test_users'] = array(
	7 => (object) array( 'ID' => 7, 'user_email' => 'h3-linked@example.de', 'locale' => 'fr_FR' ),
	8 => (object) array( 'ID' => 8, 'user_email' => 'h3-nolocale@example.de', 'locale' => '' ),
	9 => (object) array( 'ID' => 9, 'user_email' => 'chef@example.de', 'locale' => '' ),
);
$h3_linked   = AffiliateRepository::create( array( 'name' => 'Linked FR', 'email' => 'h3-linked@example.de', 'status' => 'active', 'user_id' => 7 ) );
$h3_nolocale = AffiliateRepository::create( array( 'name' => 'Linked no locale', 'email' => 'h3-nolocale@example.de', 'status' => 'active', 'user_id' => 8 ) );
$h3_plain    = AffiliateRepository::create( array( 'name' => 'Not linked', 'email' => 'h3-plain@example.de', 'status' => 'active' ) );

/** The language checks, the same for WPML and Polylang (page 50 = German partner area, 51 = French). */
function h3_language_checks( $label ) {
	global $h3_linked, $h3_nolocale, $h3_plain, $h3_last;
	$GLOBALS['h3_locale'] = 'fr_FR';
	h3( "R5-O55 {$label}: site language is the default language, not the language of the request", array( 'fr_FR', 'de_DE' ), array( get_locale(), Locale::site() ) );
	h3( "R5-O55 {$label}: partner language (own account language, account without one and unlinked partner → default language)", array( 'fr_FR', 'de_DE', 'de_DE' ), array( Locale::for_affiliate( AffiliateRepository::find( $h3_linked ) ), Locale::for_affiliate( AffiliateRepository::find( $h3_nolocale ) ), Locale::for_affiliate( AffiliateRepository::find( $h3_plain ) ) ) );
	h3( "R5-O55 {$label}: partner area link in the partner's language", array( 'http://example.test/?p=51', 'http://example.test/?p=50' ), array( Notifications::portal_url( AffiliateRepository::find( $h3_linked ) ), Notifications::portal_url( AffiliateRepository::find( $h3_plain ) ) ) );
	Notifications::application_approved( $h3_linked );
	$fr = $h3_last();
	Notifications::already_registered( 'h3-plain@example.de' );
	$de = $h3_last();
	h3( "R5-O55 {$label}: mails in the partner's language, translated partner area link, locale restored", array( 'fr_FR', 'de_DE', true, 'fr_FR', array() ), array( $fr['locale'], $de['locale'], false !== strpos( $de['message'], '?p=50' ), $GLOBALS['h3_locale'], $GLOBALS['h3_stack'] ) );
	Notifications::application_received( $h3_plain );
	$admin = $GLOBALS['h3_mails'][ count( $GLOBALS['h3_mails'] ) - 2 ]; // Followed by the confirmation to the applicant.
	h3( "R5-O55 {$label}: mail to the operator (account without own language) in the default language, not the visitor's", array( 'application_received', 'de_DE' ), array( $admin['type'], $admin['locale'] ) );
	h3( "R5-O55 {$label}: credit notes are frozen in the default language, whoever creates them", 'de_DE', PayoutService::operator_snapshot()['locale'] );
}

// ---------------------------------------------------------------- WPML (filters only)
$h3_wpml = array(
	'locale'                => static function ( $l ) { return 'fr_FR'; }, // Current language: French.
	'wpml_current_language' => static function () { return 'fr'; },
	'wpml_default_language' => static function () { return 'de'; },
	'wpml_active_languages' => static function () { return array( 'de' => array( 'default_locale' => 'de_DE' ), 'fr' => array( 'default_locale' => 'fr_FR' ) ); },
	'wpml_object_id'        => static function ( $id, $type = 'page', $original = true, $lang = null ) { $lang = null === $lang ? 'fr' : $lang; return 50 === (int) $id && 'fr' === $lang ? 51 : (int) $id; },
);
foreach ( $h3_wpml as $tag => $cb ) {
	add_filter( $tag, $cb, 10, 4 );
}
h3_language_checks( 'WPML' );
h3( 'R5-O55 WPML: page in the current language when no locale is given', 51, Locale::page_id( 50 ) );
foreach ( array_keys( $h3_wpml ) as $tag ) {
	remove_all_filters( $tag );
}
$GLOBALS['h3_locale'] = 'de_DE';
h3( 'R5-O55 without a multilingual plugin: site language = get_locale(), page unchanged', array( 'de_DE', 50 ), array( Locale::site(), Locale::page_id( 50, 'fr_FR' ) ) );

// ---------------------------------------------------------------- Polylang (functions)
$GLOBALS['h3_pll_current'] = 'fr';
// Defined only now (a function at the top level would exist from the start, also for the WPML checks).
if ( ! function_exists( "pll_get_post" ) ) {
	function pll_current_language( $f = 'slug' ) { return 'locale' === $f ? ( 'fr' === $GLOBALS['h3_pll_current'] ? 'fr_FR' : 'de_DE' ) : $GLOBALS['h3_pll_current']; }
	function pll_default_language( $f = 'slug' ) { return 'locale' === $f ? 'de_DE' : 'de'; }
	function pll_languages_list( $a = array() ) { return 'locale' === ( $a['fields'] ?? 'slug' ) ? array( 'de_DE', 'fr_FR' ) : array( 'de', 'fr' ); }
	function pll_get_post( $id, $lang = '' ) { $lang = '' === $lang ? pll_current_language() : $lang; return 50 === (int) $id ? ( 'fr' === $lang ? 51 : 50 ) : (int) $id; }
}
add_filter( 'locale', static function ( $l ) { return pll_current_language( 'locale' ); } );
h3_language_checks( 'Polylang' );
h3( 'R5-O55 Polylang: page in the current language when no locale is given', 51, Locale::page_id( 50 ) );
remove_all_filters( 'locale' );

// ---------------------------------------------------------------- uninstall on a network
global $wpdb;
$GLOBALS['test_multisite']  = 1;
$GLOBALS['h3_site_options'] = array();
$GLOBALS['h3_trashed']      = array();
$GLOBALS['h3_cleaned']      = array();
function get_site_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['h3_site_options'] ) ? $GLOBALS['h3_site_options'][ $k ] : $d; }
function update_site_option( $k, $v ) { $GLOBALS['h3_site_options'][ $k ] = $v; return true; }
function delete_site_option( $k ) { unset( $GLOBALS['h3_site_options'][ $k ] ); return true; }
function delete_site_transient( $k ) { return delete_site_option( '_t_' . $k ); }
function get_sites( $a = array() ) { return array( 1, 3, 5 ); }
function switch_to_blog( $id ) {
	$GLOBALS['test_blog_id'] = (int) $id;
	$GLOBALS['h3_cleaned'][] = (int) $id;
	if ( 1 === (int) $id ) {
		usleep( 60000 ); // The first site takes longer than the budget below.
	}
	return true;
}
function restore_current_blog() { $GLOBALS['test_blog_id'] = 1; return true; }
function delete_post_meta_by_key( $k ) { return true; }
function wp_roles() { return (object) array( 'role_objects' => array() ); }
function delete_metadata( $t, $id, $k, $v = '', $all = false ) { return true; }
function wp_trash_post( $id ) { $GLOBALS['h3_trashed'][] = (int) $id; return true; }
function wp_unschedule_hook( $hook ) { return 0; }

// R6-LC9: the pages of the setup assistant (configurable get_post() of the bootstrap).
$GLOBALS['test_posts'] = array(
	801 => (object) array( 'ID' => 801, 'post_status' => 'publish', 'post_content' => "<!-- wp:wpac/registration /-->\n<p>Werde Partner</p>" ),
	802 => (object) array( 'ID' => 802, 'post_status' => 'publish', 'post_content' => '<p>Eigener Text ohne Block</p>' ),
	803 => (object) array( 'ID' => 803, 'post_status' => 'trash', 'post_content' => '<!-- wp:wpac/landing /-->' ),
);
update_option( 'wpac_settings', array_merge( (array) get_option( 'wpac_settings' ), array( 'delete_on_uninstall' => 1, 'statement_retention_years' => 0, 'registration_page_id' => 801, 'portal_page_id' => 802, 'landing_page_id' => 803 ) ) );
// R6-MS8: a time budget (normally 80 % of max_execution_time) that the first site exhausts.
add_filter( 'wpac_uninstall_time_budget', static function ( $s ) { $GLOBALS['h3_budget_default'] = $s; return 0.03; } );
$pre = $wpdb->prefix;
define( 'WP_UNINSTALL_PLUGIN', 'affiliate-captain/affiliate-captain.php' );
include WPAC_DIR . 'uninstall.php';
h3( 'R6-LC9 uninstall with "delete all data": wizard page with the plugin block trashed, own page and trashed page untouched', array( 801 ), $GLOBALS['h3_trashed'] );
h3( 'R6-LC9 uninstall with "delete all data": tables and settings of the site removed', array( null, false ), array( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pre . 'wpac_affiliates' ) ), get_option( 'wpac_settings' ) ) );
h3( 'R6-MS8 time budget filterable (default after lifting the time limit: 0 = none); budget used up after site 1, sites 3 and 5 recorded', array( 0.0, array( 1 ), array( 3, 5 ) ), array( isset( $GLOBALS['h3_budget_default'] ) ? (float) $GLOBALS['h3_budget_default'] : null, $GLOBALS['h3_cleaned'], get_site_option( 'wpac_uninstall_incomplete' ) ) );

echo "\n" . json_encode( $h3 ) . "\n";
