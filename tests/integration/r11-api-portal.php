<?php
/**
 * Contract 1.3.0 white-label hooks of the partner area: wpac_portal_before/after (inside the
 * wrapper, only for a logged-in partner), wpac_portal_classes and wpac_portal_labels. Included
 * from run.php after r10-o47.php (needs the template stubs and r_render() of r8-render.php).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Portal\Portal;

echo "R11 API: white-label hooks of the partner area\n";
global $wpdb;

$r11p_get  = $_GET;
$r11p_user = $GLOBALS['test_user'];
$r11p_row  = $wpdb->get_row( 'SELECT id, user_id, email FROM ' . t( 'affiliates' ) . " WHERE user_id > 0 AND status = 'active' ORDER BY id LIMIT 1" );
$r11p_seen = array();

$GLOBALS['test_hooks']['wpac_portal_before'][10][] = array( static function ( $aff, $tab ) use ( &$r11p_seen ) { $r11p_seen[] = array( 'before', (int) $aff->id, $tab ); echo '<div class="r11-brand">' . esc_html( '<Marke>' ) . '</div>'; }, 2 );
$GLOBALS['test_hooks']['wpac_portal_after'][10][]  = array( static function ( $aff, $tab ) use ( &$r11p_seen ) { $r11p_seen[] = array( 'after', (int) $aff->id, $tab ); echo '<div class="r11-footer"></div>'; }, 2 );
$GLOBALS['test_hooks']['wpac_portal_classes'][10][] = array( static function ( $classes ) { $classes[] = 'brand-dark'; $classes[] = 'x" onclick="alert(1)'; $classes[] = array( 'nested' ); return $classes; }, 1 );
$GLOBALS['test_hooks']['wpac_portal_labels'][10][]  = array( static function () { return array( 'tab_overview' => 'Dashboard <b>neu</b>', 'nav' => 'Partnerprogramm', 'tab_links' => '   ' ); }, 1 );

// Logged out: no hooks.
$GLOBALS['test_user'] = 0;
$_GET                 = array();
$r11p                 = r_render( array( Portal::class, 'render' ) );
check( 'R11P logged out: no portal hooks fire', array( array(), false ), array( $r11p_seen, false !== strpos( $r11p[0], 'r11-brand' ) ) );

$GLOBALS['test_user'] = (int) $r11p_row->user_id;
if ( ! isset( $GLOBALS['test_users'][ (int) $r11p_row->user_id ] ) ) {
	$GLOBALS['test_users'][ (int) $r11p_row->user_id ] = (object) array( 'ID' => (int) $r11p_row->user_id, 'user_email' => (string) $r11p_row->email, 'display_name' => 'Partner', 'user_login' => 'p' . (int) $r11p_row->user_id, 'roles' => array( 'customer' ) );
}
$_GET = array( 'wpac_tab' => 'links' );
$r11p = r_render( array( Portal::class, 'render' ) );
$html = $r11p[0];
check( 'R11P partner: rendered without PHP problems', array( array(), '' ), array( $r11p[1], $r11p[2] ) );
check( 'R11P wpac_portal_before/after fire once with partner and tab', array( array( 'before', (int) $r11p_row->id, 'links' ), array( 'after', (int) $r11p_row->id, 'links' ) ), $r11p_seen );
$pos_wrap   = strpos( $html, '<div class="wpac-portal' );
$pos_brand  = strpos( $html, 'r11-brand' );
$pos_nav    = strpos( $html, 'class="wpac-tabs"' );
$pos_footer = strpos( $html, 'r11-footer' );
check( 'R11P hook output inside the wrapper: before the tabs, after the tab content, escaped by the add-on', array( true, true, true ), array( false !== $pos_wrap && $pos_wrap < $pos_brand && $pos_brand < $pos_nav, $pos_footer > $pos_nav && '</div>' === substr( rtrim( $html ), -6 ), false !== strpos( $html, '&lt;Marke&gt;' ) ) );
check( 'R11P wpac_portal_classes: added class kept, unsafe values sanitized, core class always first', true, false !== strpos( $html, '<div class="wpac-portal brand-dark xonclickalert1">' ) );
check( 'R11P wpac_portal_labels: tab label and navigation name replaced (tags stripped), empty labels ignored', array( true, true, true, false ), array( false !== strpos( $html, '>Dashboard neu</a>' ), false !== strpos( $html, 'aria-label="Partnerprogramm"' ), false !== strpos( $html, '>Links &amp; codes</a>' ) || false !== strpos( $html, '>Links & codes</a>' ), false !== strpos( $html, '<b>neu</b>' ) ) );
check( 'R11P no plugin name in the partner area', false, false !== stripos( $html, 'Affiliate Captain' ) );

remove_all_filters( 'wpac_portal_before' );
remove_all_filters( 'wpac_portal_after' );
remove_all_filters( 'wpac_portal_classes' );
remove_all_filters( 'wpac_portal_labels' );
$r11p = r_render( array( Portal::class, 'render' ) );
check( 'R11P without add-on: plain wrapper class and core labels', array( true, true ), array( false !== strpos( $r11p[0], '<div class="wpac-portal">' ), false !== strpos( $r11p[0], 'aria-label="Partner area"' ) ) );

$_GET                 = $r11p_get;
$GLOBALS['test_user'] = $r11p_user;
