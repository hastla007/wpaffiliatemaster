<?php
/**
 * Integration checks of work package G11: editor blocks, shortcodes and the application form
 * (private groups, inactive/deleted groups, wrapper attributes, partner targets, flash data,
 * several forms on a page, page cache).
 * Included from tests/integration/run.php (uses its check() helper and database).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\Groups;
use WPAffiliateCaptain\Affiliates\Registration;
use WPAffiliateCaptain\Content\Editor;
use WPAffiliateCaptain\Portal\Portal;

echo "G11 blocks, shortcodes, application form\n";

// Stand-ins (only where no other test defined them).
$GLOBALS['g11_meta'] = array();
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $id, $key = '', $single = false ) { return $GLOBALS['g11_meta'][ (int) $id ][ $key ] ?? ''; }
}
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $id, $key, $value ) { $GLOBALS['g11_meta'][ (int) $id ][ $key ] = $value; return true; }
}
if ( ! function_exists( 'delete_post_meta' ) ) {
	function delete_post_meta( $id, $key ) { unset( $GLOBALS['g11_meta'][ (int) $id ][ $key ] ); return true; }
}
if ( ! function_exists( 'shortcode_atts' ) ) {
	function shortcode_atts( $pairs, $atts, $sc = '' ) { $out = array(); foreach ( $pairs as $k => $v ) { $out[ $k ] = array_key_exists( $k, (array) $atts ) ? $atts[ $k ] : $v; } return $out; }
}
if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style() {}
}
if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script() {}
}
if ( ! function_exists( 'nocache_headers' ) ) {
	function nocache_headers() {}
}
if ( ! function_exists( 'get_the_ID' ) ) {
	function get_the_ID() { return $GLOBALS['g11_the_id'] ?? 0; }
}
if ( ! function_exists( 'wp_get_current_user' ) ) {
	function wp_get_current_user() { return new class() { public $ID = 0; public $display_name = ''; public $user_email = ''; public function exists() { return false; } }; }
}
if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in() { return ! empty( $GLOBALS['g11_logged_in'] ); }
}
if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( $a = -1 ) { return 'nonce'; }
}
if ( ! function_exists( 'wp_referer_field' ) ) {
	function wp_referer_field( $echo = true ) { return '<input type="hidden" name="_wp_http_referer" value="/">'; }
}
if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( $t, $d = '' ) { echo esc_html( $t ); }
}
if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( $t, $d = '' ) { return esc_attr( $t ); }
}
if ( ! function_exists( 'esc_textarea' ) ) {
	function esc_textarea( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
}
if ( ! function_exists( 'checked' ) ) {
	function checked( $a, $b = true, $echo = true ) { $r = (string) $a === (string) $b ? " checked='checked'" : ''; if ( $echo ) { echo $r; } return $r; }
}
if ( ! function_exists( 'get_block_wrapper_attributes' ) ) {
	function get_block_wrapper_attributes( $extra = array() ) { return 'class="wp-block-wpac-test my-class" id="anker"'; }
}
if ( ! function_exists( 'register_post_meta' ) ) {
	function register_post_meta( $type, $key, $args ) { $GLOBALS['g11_registered_meta'][ $key ] = $args; return true; }
}
$g11_caps = $GLOBALS['test_caps'] ?? null;
$g11_set_caps = static function ( $caps ) {
	if ( null === $caps ) {
		unset( $GLOBALS['test_caps'] );
	} else {
		$GLOBALS['test_caps'] = $caps;
	}
};
$g11_manager = array( 'wpac_manage_affiliates', 'edit_post' );
$g11_editor  = array( 'edit_post', 'edit_posts', 'edit_others_pages' );

/* R6-GB1: private groups only from the page's own content, approved by a partner manager ------- */
check(
	'R6-GB1 groups of shortcodes and (nested) blocks found in the content',
	array( 3, 7, 12 ),
	Registration::content_groups( 'Text [wpac_affiliate_registration group="7"] [wpac_affiliate_registration] <!-- wp:group --><!-- wp:wpac/registration {"group":12,"className":"x"} /--><!-- /wp:group --> [wpac_affiliate_registration group=3]' )
);
check( 'R6-GB1 content without form: no groups', array(), Registration::content_groups( '<!-- wp:wpac/portal /--> group="5"' ) );
check(
	'R6-GB1 manager approves all groups, an editor keeps only groups approved before',
	array( array( 5, 9 ), array( 5 ), array() ),
	array( Registration::approved_groups( array( 9, 5 ), array(), true ), Registration::approved_groups( array( 5, 9 ), array( 5 ), false ), Registration::approved_groups( array( 9 ), array( 5 ), false ) )
);

$g11_vip = Groups::create( 'G11 VIP 30 %' ); // Private by default.
$g11_pub = Groups::create( 'G11 Öffentlich' );
Groups::update( $g11_pub, array( 'name' => 'G11 Öffentlich', 'public' => 1 ) );
$g11_post = (object) array(
	'ID'                => 1101,
	'post_status'       => 'publish',
	'post_password'     => '',
	'post_author'       => 1,
	'post_modified_gmt' => '2026-11-01 10:00:00',
	'post_content'      => '<!-- wp:wpac/registration {"group":' . $g11_vip . '} /-->',
);

// An editor without the partner right sets the private group: not approved.
$g11_set_caps( $g11_editor );
Registration::record_group_approval( 1101, $g11_post );
check( 'R6-GB1 editor without partner right cannot open a private group', false, Registration::post_opens_group( $g11_post, $g11_vip ) );
// A partner manager saves the page: approved.
$g11_set_caps( $g11_manager );
Registration::record_group_approval( 1101, $g11_post );
check( 'R6-GB1 group approved when a partner manager saves the page', true, Registration::post_opens_group( $g11_post, $g11_vip ) );
$g11_sig_ref = new ReflectionMethod( Registration::class, 'group_sig' );
$g11_sig_ref->setAccessible( true );
$g11_sig1 = $g11_sig_ref->invoke( null, $g11_vip, $g11_post );
// The editor edits the text: the approval (and the signature of open forms) stays.
$g11_set_caps( $g11_editor );
$g11_post->post_content .= "\n<!-- wp:paragraph --><p>Neu</p><!-- /wp:paragraph -->";
Registration::record_group_approval( 1101, $g11_post );
check( 'R6-GB1 text edit by an editor keeps the approval and the signature', array( true, $g11_sig1 ), array( Registration::post_opens_group( $g11_post, $g11_vip ), $g11_sig_ref->invoke( null, $g11_vip, $g11_post ) ) );
// The group is removed from the page: old copies of the form stop working.
$g11_old_content          = $g11_post->post_content;
$g11_post->post_content   = '<!-- wp:paragraph --><p>Aktion beendet</p><!-- /wp:paragraph -->';
Registration::record_group_approval( 1101, $g11_post );
check( 'R6-GB1 invitation ended: group no longer in the content, approval removed', array( false, '' ), array( Registration::post_opens_group( $g11_post, $g11_vip ), get_post_meta( 1101, Registration::META_GROUPS, true ) ) );
// Put back by the editor: not approved again.
$g11_post->post_content = $g11_old_content;
Registration::record_group_approval( 1101, $g11_post );
check( 'R6-GB1 group put back by an editor stays closed', false, Registration::post_opens_group( $g11_post, $g11_vip ) );
$g11_set_caps( $g11_manager );
Registration::record_group_approval( 1101, $g11_post );
check( 'R6-GB1 re-approval changes the signature (old HTML copies invalid)', array( true, false ), array( Registration::post_opens_group( $g11_post, $g11_vip ), hash_equals( $g11_sig1, $g11_sig_ref->invoke( null, $g11_vip, $g11_post ) ) ) );
$g11_draft = clone $g11_post;
$g11_draft->post_status = 'draft';
$g11_other = clone $g11_post;
$g11_other->ID = 1102; // Same content, no approval recorded, last editor unknown (user_can() false here).
check( 'R6-GB1 draft or unapproved legacy page cannot open a private group', array( false, false ), array( Registration::post_opens_group( $g11_draft, $g11_vip ), Registration::post_opens_group( $g11_other, $g11_vip ) ) );
// The configured application page no longer opens any group by itself (post 900 has no form).
update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), array( 'registration_page_id' => 900 ) ) );
WPAffiliateCaptain\Core\Settings::flush_cache();
check( 'R6-GB1 application page without the group in its content does not open it', false, Registration::fixed_group_valid( $g11_vip, 900, Registration::signature_for( $g11_vip, 900 ) ) );

/* R6-GB2: inactive and deleted groups ---------------------------------------------------------- */
$g11_gone = Groups::create( 'G11 Weg' );
Groups::delete( $g11_gone );
$g11_off = Groups::create( 'G11 Inaktiv' );
$GLOBALS['wpdb']->update( WPAffiliateCaptain\Core\Db::table( 'partner_groups' ), array( 'status' => 'inactive', 'public' => 1 ), array( 'id' => $g11_off ) );
$g11_set_caps( array() );
check(
	'R6-GB2 deleted, inactive and unapproved private groups are reported; public active group is fine',
	array( true, true, true, '' ),
	array(
		'' !== Registration::fixed_group_problem( Groups::find( $g11_gone ), $g11_gone, 1101 ),
		'' !== Registration::fixed_group_problem( Groups::find( $g11_off ), $g11_off, 1101 ),
		'' !== Registration::fixed_group_problem( Groups::find( $g11_vip ), $g11_vip, 0 ),
		Registration::fixed_group_problem( Groups::find( $g11_pub ), $g11_pub, 0 ),
	)
);
$g11_html = Registration::render( array( 'group' => $g11_gone ) );
check( 'R6-GB2 form with a deleted group shows a notice instead of the group choice', array( false, false, true ), array( false !== strpos( $g11_html, '<form' ), false !== strpos( $g11_html, 'wpac-group-choice' ), false !== strpos( $g11_html, 'wpac-notice' ) ) );
$g11_html = Registration::render( array( 'group' => $g11_off ) );
check( 'R6-GB2 form with an inactive group is not shown', false, false !== strpos( $g11_html, '<form' ) );
$g11_choices = array();
foreach ( Editor::group_choices() as $c ) {
	$g11_choices[ $c['value'] ] = $c;
}
check(
	'R6-GB2 block dropdown marks private and inactive groups, inactive not selectable',
	array( true, true, true, false ),
	array( false !== strpos( $g11_choices[ $g11_vip ]['label'], '(private)' ), false !== strpos( $g11_choices[ $g11_off ]['label'], 'inactive' ), $g11_choices[ $g11_off ]['disabled'], $g11_choices[ $g11_pub ]['disabled'] )
);

/* R6-GB3: wrapper attributes ------------------------------------------------------------------- */
check( 'R6-GB3 block output carries the wrapper attributes, empty output stays empty', array( '<div class="wp-block-wpac-test my-class" id="anker"><p>x</p></div>', '' ), array( Editor::wrap( '<p>x</p>' ), Editor::wrap( '' ) ) );
$g11_json = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/blocks/registration/block.json' ), true );
check( 'R6-GB3 blocks support anchor and spacing; R6-GB1 form uses the postId context', array( true, true, array( 'postId' ) ), array( $g11_json['supports']['anchor'], $g11_json['supports']['spacing']['margin'], $g11_json['usesContext'] ) );

/* R6-GB4 / R6-GB8: partner targets only by partner managers, registered for REST --------------- */
$g11_set_caps( array( 'edit_post', 'edit_posts' ) );
$g11_author = Editor::can_promote( 1101 );
$g11_set_caps( $g11_manager );
check( 'R6-GB4 authors cannot recommend partner targets, partner managers can', array( false, true ), array( $g11_author, Editor::can_promote( 1101 ) ) );
$GLOBALS['g11_registered_meta'] = array();
Editor::register_meta();
$g11_rm = $GLOBALS['g11_registered_meta'];
$g11_set_caps( array( 'edit_post' ) );
$g11_auth_editor = isset( $g11_rm[ Editor::META_PROMOTE ] ) ? ( $g11_rm[ Editor::META_PROMOTE ]['auth_callback'] )( true, Editor::META_PROMOTE, 1101 ) : null;
$g11_set_caps( $g11_manager );
check(
	'R6-GB8 promote meta registered for REST with auth callback',
	array( true, true, false, true, 1, 'Hinweis' ),
	array(
		! empty( $g11_rm[ Editor::META_PROMOTE ]['show_in_rest'] ),
		! empty( $g11_rm[ Editor::META_NOTE ]['show_in_rest'] ),
		$g11_auth_editor,
		( $g11_rm[ Editor::META_NOTE ]['auth_callback'] )( false, Editor::META_NOTE, 1101 ),
		( $g11_rm[ Editor::META_PROMOTE ]['sanitize_callback'] )( 'yes' ),
		( $g11_rm[ Editor::META_NOTE ]['sanitize_callback'] )( '<b>Hinweis</b>' ),
	)
);

/* R6-GB5: flash data survives a second rendering ---------------------------------------------- */
set_transient( 'wpac_portal_error_g11', 'Mindestbetrag nicht erreicht.', 300 );
check( 'R6-GB5 flash data read twice in one request, removed from storage', array( 'Mindestbetrag nicht erreicht.', 'Mindestbetrag nicht erreicht.', false ), array( Portal::flash( 'wpac_portal_error_g11' ), Portal::flash( 'wpac_portal_error_g11' ), get_transient( 'wpac_portal_error_g11' ) ) );

/* R6-GB6: several forms on one page ------------------------------------------------------------ */
$g11_set_caps( array() );
$GLOBALS['g11_the_id'] = 1101;
$_GET['wpac_err']      = 'required';
$_GET['wpac_fi']       = Registration::form_key( $g11_pub, 1101 );
$g11_a = Registration::render( array( 'group' => $g11_pub ) );
$g11_b = Registration::render( array( 'group' => $g11_pub ), null, '', 1102 );
unset( $_GET['wpac_err'], $_GET['wpac_fi'] );
preg_match_all( '/\bid="([^"]+)"/', $g11_a . $g11_b, $g11_ids );
check( 'R6-GB6 two forms on a page: unique element IDs', array(), array_values( array_diff_assoc( $g11_ids[1], array_unique( $g11_ids[1] ) ) ) );
check( 'R6-GB6 error shown only on the form that was sent', array( true, true, true, false ), array( false !== strpos( $g11_a, '<form' ), false !== strpos( $g11_b, 'id="wpac_name-2"' ), false !== strpos( $g11_a, 'role="alert"' ), false !== strpos( $g11_b, 'role="alert"' ) ) );
check( 'R6-GB6 portal block allowed once per page', false, json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/blocks/portal/block.json' ), true )['supports']['multiple'] );

/* R5-W51: the form itself marks the page as not cacheable ------------------------------------- */
check( 'R5-W51 rendering the form sets DONOTCACHEPAGE', true, defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE );

/* R6-GB7: editor preview uses the block's text ------------------------------------------------- */
$g11_prev = Editor::welcome_preview( 'Tipp von %s – 10% Rabatt' );
check( 'R6-GB7 editor preview shows the own text with the partner placeholder', true, false !== strpos( $g11_prev, 'Tipp von [partner name] – 10% Rabatt' ) );

/* R6-GB9: partner targets only for partners ---------------------------------------------------- */
$GLOBALS['g11_logged_in'] = false;
check( 'R6-GB9 partner targets and notes not shown to visitors', '', Editor::render_targets() );

$g11_set_caps( $g11_caps );
unset( $GLOBALS['g11_the_id'] );
