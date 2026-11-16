<?php
/**
 * Render checks of audit round 14, fix agent g5: the e-mail templates page (list, editor with
 * placeholders, reset and test buttons) and its admin-post actions through Admin::dispatch().
 * Included from tests/integration/run.php after the render files (uses the template stubs of
 * r8-render.php and the wp_die()/wp_safe_redirect() stubs of r12-c.php).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Admin\Admin;
use WPAffiliateCaptain\Admin\Pages\MailTemplatesPage;
use WPAffiliateCaptain\Affiliates\Notifications;
use WPAffiliateCaptain\Core\Capabilities;

echo "R14 g5 render: e-mail templates page\n";

$g5r_get  = $_GET;
$g5r_post = $_POST;
$g5r_caps = $GLOBALS['test_caps'] ?? null;
$g5r_user = $GLOBALS['test_user'];
delete_option( Notifications::TEMPLATES_OPTION );

$GLOBALS['test_caps'] = array( Capabilities::MANAGE_PROGRAM );
$_GET                 = array( 'page' => MailTemplatesPage::SLUG );
$g5r                  = r_render( array( MailTemplatesPage::class, 'render' ) );
check(
	'R14-P1 template list renders without PHP problems: layout choice, every template with an edit link',
	array( array(), '', true, count( Notifications::template_defs() ) ),
	array( $g5r[1], $g5r[2], false !== strpos( $g5r[0], 'name="layout" value="html"' ), substr_count( $g5r[0], 'template=' ) )
);

$_GET = array( 'page' => MailTemplatesPage::SLUG, 'template' => 'application_approved' );
$g5r  = r_render( array( MailTemplatesPage::class, 'render' ) );
check(
	'R14-P1 editor shows the built-in text with placeholders, their descriptions and the test button (no reset for an unchanged template)',
	array( array(), '', true, true, true, false ),
	array(
		$g5r[1],
		$g5r[2],
		false !== strpos( $g5r[0], 'id="wpac_tpl_subject" name="subject" required maxlength="190" value="[{site}] Your affiliate application was approved"' ),
		false !== strpos( $g5r[0], '<code>{rate}</code> – Commission rate' ),
		false !== strpos( $g5r[0], 'name="do" value="mail_template_test"' ),
		false !== strpos( $g5r[0], 'name="do" value="mail_template_reset"' ),
	)
);

$g5r_dispatch = static function ( $do, array $post, $caps ) {
	$GLOBALS['test_caps'] = $caps;
	$_POST                = array( 'do' => $do, '_wpac_nonce' => 'nonce' ) + $post;
	$_REQUEST             = $_POST;
	try {
		Admin::dispatch();
		return array( 'no halt', 0 );
	} catch ( R12C_Halt $e ) {
		return array( $e->getMessage(), $e->getCode() );
	}
};
$g5r_denied = $g5r_dispatch( 'mail_template_save', array( 'template' => 'credit_note', 'subject' => 'S', 'body' => 'B' ), array( Capabilities::MANAGE_AFFILIATES ) );
check( 'R14-P1 saving a template needs the program capability', array( 403, null ), array( $g5r_denied[1], Notifications::custom_template( 'credit_note' ) ) );
$g5r_saved = $g5r_dispatch( 'mail_template_save', array( 'template' => 'credit_note', 'subject' => 'Gutschrift {number}', 'body' => 'Hallo {name}, hier ist deine Gutschrift.' ), array( Capabilities::MANAGE_PROGRAM ) );
delete_transient( 'wpac_notice_' . get_current_user_id() );
check( 'R14-P1 template saved through the admin action, back to the editor', array( true, array( 'Gutschrift {number}', 'Hallo {name}, hier ist deine Gutschrift.' ) ), array( 0 === strpos( $g5r_saved[0], 'redirect:' ) && false !== strpos( $g5r_saved[0], 'template=credit_note' ), Notifications::custom_template( 'credit_note' ) ) );
$_GET = array( 'page' => MailTemplatesPage::SLUG, 'template' => 'credit_note' );
$g5r  = r_render( array( MailTemplatesPage::class, 'render' ) );
check( 'R14-P1 changed template: editor offers the reset', true, false !== strpos( $g5r[0], 'name="do" value="mail_template_reset"' ) );

$GLOBALS['test_users'][ 914005 ] = (object) array( 'ID' => 914005, 'user_email' => 'admin-g5@example.de', 'display_name' => 'Admin', 'user_login' => 'admin-g5', 'roles' => array( 'administrator' ) );
$GLOBALS['test_user']             = 914005;
$g5r_before                       = count( $GLOBALS['test_mails'] );
$g5r_dispatch( 'mail_template_test', array( 'template' => 'credit_note' ), array( Capabilities::MANAGE_PROGRAM ) );
$g5r_notice = get_transient( 'wpac_notice_914005' );
delete_transient( 'wpac_notice_914005' );
check( 'R14-P1 test mail goes to the current admin', array( $g5r_before + 1, 'admin-g5@example.de', '[Test] Gutschrift GS-2026-0001', 'success' ), array( count( $GLOBALS['test_mails'] ), end( $GLOBALS['test_mails'] )[0], end( $GLOBALS['test_mails'] )[1], $g5r_notice['type'] ?? '' ) );

$g5r_dispatch( 'mail_template_reset', array( 'template' => 'credit_note' ), array( Capabilities::MANAGE_PROGRAM ) );
$g5r_dispatch( 'mail_layout_save', array( 'layout' => 'html' ), array( Capabilities::MANAGE_PROGRAM ) );
delete_transient( 'wpac_notice_914005' );
check( 'R14-P1 reset and layout through the admin actions', array( null, true ), array( Notifications::custom_template( 'credit_note' ), Notifications::html_layout() ) );

delete_option( Notifications::TEMPLATES_OPTION );
$_GET                 = $g5r_get;
$_POST                = $g5r_post;
$_REQUEST             = array();
$GLOBALS['test_user'] = $g5r_user;
if ( null === $g5r_caps ) {
	unset( $GLOBALS['test_caps'] );
} else {
	$GLOBALS['test_caps'] = $g5r_caps;
}
