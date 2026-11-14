<?php
/**
 * Render smoke checks: admin pages and the partner portal produce their markup from the data the
 * earlier integration files created, without PHP warnings, notices or uncaught errors.
 *
 * Required by run.php after the R8 files (uses check(), t() and their data).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable

use WPAffiliateCaptain\Admin\Pages\CommissionsPage;
use WPAffiliateCaptain\Admin\Pages\PayoutsPage;
use WPAffiliateCaptain\Admin\Pages\SettingsPage;
use WPAffiliateCaptain\Portal\Portal;

// Minimal WordPress template functions the pages call (output only; no behaviour is tested here).
$r_stubs = array(
	'esc_attr__'          => static function ( $t ) { return esc_attr( $t ); },
	'esc_html_e'          => static function ( $t ) { echo esc_html( $t ); },
	'esc_attr_e'          => static function ( $t ) { echo esc_attr( $t ); },
	'_e'                  => static function ( $t ) { echo $t; },
	'esc_html_x'          => static function ( $t ) { return esc_html( $t ); },
	'esc_attr_x'          => static function ( $t ) { return esc_attr( $t ); },
	'esc_textarea'        => static function ( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); },
	'esc_js'              => static function ( $t ) { return addslashes( (string) $t ); },
	'selected'            => static function ( $a, $b = true, $echo = true ) { $r = (string) $a === (string) $b ? ' selected="selected"' : ''; if ( $echo ) { echo $r; } return $r; },
	'checked'             => static function ( $a, $b = true, $echo = true ) { $r = (string) $a === (string) $b ? ' checked="checked"' : ''; if ( $echo ) { echo $r; } return $r; },
	'disabled'            => static function ( $a, $b = true, $echo = true ) { $r = (string) $a === (string) $b ? ' disabled="disabled"' : ''; if ( $echo ) { echo $r; } return $r; },
	'wp_nonce_field'      => static function ( $a = -1, $n = '_wpnonce', $ref = true, $echo = true ) { $r = '<input type="hidden" name="' . $n . '" value="nonce">'; if ( $echo ) { echo $r; } return $r; },
	'wp_create_nonce'     => static function ( $a = -1 ) { return 'nonce'; },
	'wp_referer_field'    => static function ( $echo = true ) { $r = '<input type="hidden" name="_wp_http_referer" value="/">'; if ( $echo ) { echo $r; } return $r; },
	'wp_nonce_url'        => static function ( $u, $a = -1, $n = '_wpnonce' ) { return add_query_arg( $n, 'nonce', $u ); },
	'submit_button'       => static function ( $t = null ) { echo '<button type="submit">' . esc_html( (string) $t ) . '</button>'; },
	'get_submit_button'   => static function ( $t = null ) { return '<button type="submit">' . esc_html( (string) $t ) . '</button>'; },
	'wp_enqueue_style'    => static function () {},
	'wp_enqueue_script'   => static function () {},
	'wp_enqueue_media'    => static function () {},
	'wp_get_attachment_image' => static function () { return ''; },
	'wp_trim_words'       => static function ( $t, $n = 55 ) { return implode( ' ', array_slice( preg_split( '/\s+/', trim( (string) $t ) ), 0, (int) $n ) ); },
	'wp_localize_script'  => static function () { return true; },
	'wp_add_inline_script' => static function () { return true; },
	'is_user_logged_in'   => static function () { return get_current_user_id() > 0; },
	'wp_get_current_user' => static function () {
		$u = get_userdata( get_current_user_id() );
		return new class( $u ) {
			public $ID = 0, $user_email = '', $display_name = '', $user_login = '', $first_name = '', $last_name = '', $roles = array();
			public function __construct( $u ) { foreach ( $u ? get_object_vars( $u ) : array() as $k => $v ) { $this->$k = $v; } }
			public function exists() { return $this->ID > 0; }
		};
	},
	'shortcode_atts'      => static function ( $pairs, $atts ) { return array_merge( $pairs, array_intersect_key( (array) $atts, $pairs ) ); },
	'wp_login_form'       => static function ( $a = array() ) { return '<form class="login"></form>'; },
	'wp_lostpassword_url' => static function ( $r = '' ) { return 'http://example.test/wp-login.php?action=lostpassword'; },
	'wp_logout_url'       => static function ( $r = '' ) { return 'http://example.test/wp-login.php?action=logout'; },
	'wp_kses'             => static function ( $t ) { return (string) $t; },
	'wp_dropdown_pages'   => static function ( $a = array() ) { $r = '<select name="' . ( $a['name'] ?? 'page_id' ) . '"></select>'; if ( ! isset( $a['echo'] ) || $a['echo'] ) { echo $r; } return $r; },
	'get_pages'           => static function () { return array(); },
	'get_users'           => static function () { return array(); },
	'get_the_title'       => static function ( $p = 0 ) { return 'Page ' . (int) ( is_object( $p ) ? $p->ID : $p ); },
	'paginate_links'      => static function ( $a = array() ) { return ''; },
	'date_i18n'           => static function ( $f, $ts = false ) { return gmdate( $f, false === $ts ? time() : (int) $ts ); },
	'size_format'         => static function ( $b ) { return (int) $b . ' B'; },
	'get_locale'          => static function () { return 'de_DE'; },
	'get_user_locale'     => static function () { return 'de_DE'; },
	'get_edit_user_link'  => static function ( $id = 0 ) { return admin_url( 'user-edit.php?user_id=' . (int) $id ); },
	'get_edit_post_link'  => static function ( $id = 0 ) { return admin_url( 'post.php?post=' . (int) $id . '&action=edit' ); },
	'settings_errors'     => static function () {},
	'wp_editor'           => static function ( $c, $id ) { echo '<textarea id="' . esc_attr( $id ) . '">' . htmlspecialchars( (string) $c ) . '</textarea>'; },
	'wc_get_order'        => static function () { return false; },
	'wc_price'            => static function ( $p ) { return (string) $p; },
	'get_woocommerce_currency' => static function () { return 'EUR'; },
	'is_admin'            => static function () { return false; },
	'wp_get_referer'      => static function () { return false; },
	'wp_timezone_string'  => static function () { return 'Europe/Berlin'; },
	'get_post_status'     => static function () { return 'publish'; },
	'get_post_type'       => static function () { return 'page'; },
	'wp_strip_all_tags'   => static function ( $t ) { return strip_tags( (string) $t ); },
	'checked_attr'        => static function () { return ''; },
	'wp_parse_args'       => static function ( $a, $d = array() ) { if ( is_string( $a ) ) { parse_str( $a, $a ); } return array_merge( (array) $d, (array) $a ); },
	'remove_query_arg'    => static function ( $k, $u = '' ) { $p = explode( '?', (string) $u, 2 ); if ( ! isset( $p[1] ) ) { return $p[0]; } parse_str( $p[1], $q ); foreach ( (array) $k as $x ) { unset( $q[ $x ] ); } return $p[0] . ( $q ? '?' . http_build_query( $q ) : '' ); },
	'has_action'          => static function ( $h, $f = false ) { return ! empty( $GLOBALS['test_hooks'][ $h ] ); },
	'get_posts'           => static function ( $a = array() ) { return array( (object) array( 'ID' => 5, 'post_title' => 'Segelkurs', 'post_type' => 'page', 'post_status' => 'publish' ) ); },
	'get_post_types'      => static function ( $a = array(), $o = 'names' ) { return array( 'post' => 'post', 'page' => 'page' ); },
	'human_time_diff'     => static function ( $a, $b = 0 ) { return abs( (int) $b - (int) $a ) . ' s'; },
);
// Partner target note of the page get_posts() returns (post meta lives in the bootstrap store).
update_post_meta( 5, WPAffiliateCaptain\Content\Editor::META_NOTE, 'Ab 18 Jahren' );
foreach ( $r_stubs as $r_name => $r_fn ) {
	if ( ! function_exists( $r_name ) ) {
		$GLOBALS['r_stub_fns'][ $r_name ] = $r_fn;
		eval( 'function ' . $r_name . '( ...$a ) { return ( $GLOBALS["r_stub_fns"]["' . $r_name . '"] )( ...$a ); }' );
	}
}

/**
 * Runs $fn with output buffering; returns array( output, PHP problems, exception message ).
 */
// Script/style API used by the portal since the asset registration (G9).
foreach ( array( 'wp_register_style', 'wp_register_script', 'wp_enqueue_style', 'wp_enqueue_script', 'wp_localize_script', 'wp_add_inline_script', 'wp_add_inline_style', 'wp_set_script_translations' ) as $r_fn ) {
	if ( ! function_exists( $r_fn ) ) {
		eval( 'function ' . $r_fn . '() { return true; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test stubs.
	}
}
if ( ! function_exists( 'wp_style_is' ) ) {
	function wp_style_is( $h, $l = 'enqueued' ) { return false; }
}
if ( ! function_exists( 'wp_script_is' ) ) {
	function wp_script_is( $h, $l = 'enqueued' ) { return false; }
}

function r_render( callable $fn ) {
	$problems = array();
	set_error_handler(
		static function ( $no, $msg, $file, $line ) use ( &$problems ) {
			$problems[] = $msg . ' (' . basename( $file ) . ':' . $line . ')';
			return true;
		}
	);
	$error = '';
	ob_start();
	try {
		$ret = $fn();
		if ( is_string( $ret ) ) {
			echo $ret;
		}
	} catch ( \Throwable $e ) {
		$error = get_class( $e ) . ': ' . $e->getMessage() . ' (' . basename( $e->getFile() ) . ':' . $e->getLine() . ')';
	}
	$out = (string) ob_get_clean();
	restore_error_handler();
	return array( $out, $problems, $error );
}

echo "R8 render: admin pages and partner portal\n";
$r_get_backup = $_GET;
$r_user       = $GLOBALS['test_user'];
$r_caps       = $GLOBALS['test_caps'] ?? null;
unset( $GLOBALS['test_caps'] ); // Administrator: every capability.

// Settings page, every tab.
foreach ( array_keys( SettingsPage::tab_keys() ) as $r_tab ) {
	$_GET = array( 'page' => 'wpac-settings', 'tab' => $r_tab );
	$r    = r_render( array( SettingsPage::class, 'render' ) );
	check( "R8-RENDER settings tab {$r_tab}: form without PHP problems", array( true, array(), '' ), array( false !== strpos( $r[0], 'name="tab" value="' . $r_tab . '"' ), $r[1], $r[2] ) );
}

// Commissions page: list, filtered by status.
foreach ( array( '', 'pending', 'approved', 'rejected', 'paid' ) as $r_status ) {
	$_GET = array( 'page' => 'wpac-commissions', 'status' => $r_status );
	$r    = r_render( array( CommissionsPage::class, 'render' ) );
	check( "R8-RENDER commissions list (status '{$r_status}') without PHP problems", array( true, array(), '' ), array( false !== strpos( $r[0], '<div class="wrap' ), $r[1], $r[2] ) );
}

// Audit log: unfiltered, filtered by object type and by an order without entries.
foreach ( array( array(), array( 'object_type' => 'affiliate' ), array( 'order' => '#999999' ) ) as $r_q ) {
	$_GET = array( 'page' => 'wpac-audit' ) + $r_q;
	$r    = r_render( array( WPAffiliateCaptain\Admin\Pages\AuditPage::class, 'render' ) );
	check( 'R8-RENDER audit log ' . wp_json_encode( $r_q ) . ' without PHP problems', array( true, array(), '' ), array( false !== strpos( $r[0], 'name="audit_action"' ), $r[1], $r[2] ) );
}
check( 'R8-RENDER audit log: an order without entries shows the filter notice', true, false !== strpos( $r[0], 'No entries match this filter.' ) );

// Payouts page: overview and the payout detail of every existing run.
$_GET = array( 'page' => 'wpac-payouts' );
$r    = r_render( array( PayoutsPage::class, 'render' ) );
check( 'R8-RENDER payouts overview without PHP problems', array( true, array(), '' ), array( false !== strpos( $r[0], '<div class="wrap' ), $r[1], $r[2] ) );
foreach ( array_slice( array_map( 'intval', (array) $wpdb->get_col( 'SELECT id FROM ' . t( 'payouts' ) . ' ORDER BY id' ) ), 0, 25 ) as $r_pid ) {
	$_GET = array( 'page' => 'wpac-payouts', 'payout' => (string) $r_pid );
	$r    = r_render( array( PayoutsPage::class, 'render' ) );
	if ( array() !== $r[1] || '' !== $r[2] ) {
		check( "R8-RENDER payout {$r_pid} without PHP problems", array( array(), '' ), array( $r[1], $r[2] ) );
	}
}
check( 'R8-RENDER payout details rendered', true, true );

// R5-O47: the remaining admin lists render as list tables with sort links and a search box.
$r_o47 = array(
	'payouts'   => array( array( PayoutsPage::class, 'render' ), array( 'page' => 'wpac-payouts', 'orderby' => 'amount_minor', 'order' => 'asc', 's' => 'x' ), array( 'orderby=number', 'orderby=amount_minor&amp;order=desc', 'name="s" value="x"', 'name="bulk_action"' ) ),
	'audit'     => array( array( WPAffiliateCaptain\Admin\Pages\AuditPage::class, 'render' ), array( 'page' => 'wpac-audit', 'order_ref' => '42', 'orderby' => 'action' ), array( 'orderby=created_at', 'orderby=object_type', 'name="order_ref" value="42"', 'name="s"', 'displaying-num' ) ),
	'creatives' => array( array( WPAffiliateCaptain\Admin\Pages\CreativesPage::class, 'render' ), array( 'page' => 'wpac-creatives' ), array( 'orderby=title', 'orderby=status', 'name="s"' ) ),
	'rules'     => array( array( WPAffiliateCaptain\Admin\Pages\RulesPage::class, 'render' ), array( 'page' => 'wpac-rules', 'tab' => 'rules' ), array( 'orderby=priority', 'orderby=scope', 'name="s"', 'name="tab" value="rules"' ) ),
	'queue'     => array( array( WPAffiliateCaptain\Admin\Pages\IntegrationsPage::class, 'render' ), array( 'page' => 'wpac-integrations', 'tab' => 'queue' ), array( 'orderby=attempts', 'orderby=next_attempt_at', 'name="s"', 'job_status=failed' ) ),
);
foreach ( $r_o47 as $r_name => $r_case ) {
	$_GET     = $r_case[1];
	$_REQUEST = $r_case[1];
	$r        = r_render( $r_case[0] );
	$r_found  = array();
	foreach ( $r_case[2] as $r_needle ) {
		$r_found[ $r_needle ] = false !== strpos( $r[0], $r_needle );
	}
	check( "R5-O47 {$r_name} list: sortable column links and search box, without PHP problems", array( array_fill_keys( $r_case[2], true ), array(), '' ), array( $r_found, $r[1], $r[2] ) );
}
$_REQUEST = array();

// Partner portal: logged out, then as every partner with a user account.
$GLOBALS['test_user'] = 0;
$_GET                 = array();
$r                    = r_render( array( Portal::class, 'render' ) );
check( 'R8-RENDER portal logged out: login form', array( true, array(), '' ), array( false !== strpos( $r[0], 'class="login"' ), $r[1], $r[2] ) );
$r_rows = $wpdb->get_results( 'SELECT id, user_id, email FROM ' . t( 'affiliates' ) . " WHERE user_id > 0 AND status IN ('active','paused') ORDER BY id LIMIT 15" );
$r_done    = 0;
$r_tabs_ok = 0;
foreach ( $r_rows as $r_row ) {
	$GLOBALS['test_user'] = (int) $r_row->user_id;
	if ( ! isset( $GLOBALS['test_users'][ (int) $r_row->user_id ] ) ) {
		$GLOBALS['test_users'][ (int) $r_row->user_id ] = (object) array( 'ID' => (int) $r_row->user_id, 'user_email' => (string) $r_row->email, 'display_name' => 'Partner', 'user_login' => 'p' . (int) $r_row->user_id, 'roles' => array( 'customer' ) );
	}
	foreach ( array( '', 'overview', 'links', 'promotion', 'commissions', 'payouts', 'profile', 'account', 'privacy' ) as $r_tab ) {
		$_GET = '' === $r_tab ? array() : array( 'wpac_tab' => $r_tab );
		$r    = r_render( array( Portal::class, 'render' ) );
		if ( false !== strpos( $r[0], 'class="wpac-tabs"' ) ) {
			++$r_tabs_ok;
		}
		if ( array() !== $r[1] || '' !== $r[2] ) {
			check( "R8-RENDER portal of partner {$r_row->id} (tab '{$r_tab}') without PHP problems", array( array(), '' ), array( $r[1], $r[2] ) );
		}
	}
	++$r_done;
}
check( 'R8-RENDER portal rendered with its tab navigation for partners with an account', array( true, true ), array( $r_done > 0, $r_tabs_ok > 0 ) );

// Registration form (logged out) and its confirmation/success states.
$GLOBALS['test_user'] = 0;
foreach ( array( array(), array( 'wpac_reg' => 'confirm' ), array( 'wpac_reg' => '1' ), array( 'wpac_confirm' => 'no-such-token' ) ) as $r_q ) {
	$_GET = $r_q;
	$r    = r_render( array( WPAffiliateCaptain\Affiliates\Registration::class, 'render' ) );
	check( 'R8-RENDER registration ' . wp_json_encode( $r_q ) . ' without PHP problems', array( true, array(), '' ), array( '' !== trim( $r[0] ), $r[1], $r[2] ) );
}
// With public partner groups the form first asks for the group (?wpac_group=…), then shows the fields.
$r_public = WPAffiliateCaptain\Affiliates\Groups::public_groups();
$_GET     = $r_public ? array( 'wpac_group' => (string) (int) reset( $r_public )->id ) : array();
$r        = r_render( array( WPAffiliateCaptain\Affiliates\Registration::class, 'render' ) );
check( 'R8-RENDER registration form has the e-mail field', array( true, array(), '' ), array( false !== strpos( $r[0], 'type="email"' ), $r[1], $r[2] ) );

$_GET                 = $r_get_backup;
$GLOBALS['test_user'] = $r_user;
if ( null !== $r_caps ) {
	$GLOBALS['test_caps'] = $r_caps;
}
