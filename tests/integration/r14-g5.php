<?php
/**
 * Audit round 14, fix agent g5: e-mail templates editable in the admin (R14-P1: placeholders,
 * built-in defaults, reset, test mail, HTML layout with plain-text part, welcome mail with partner
 * link, code and rate, wpac_mail after the template) and the unsubscribe link without a change on
 * GET plus RFC 8058 one-click (R14-S3).
 * Included from run.php (helpers check(), t()).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Affiliates\Notifications;
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Portal\Portal;

echo "R14 g5 e-mail templates, one-click unsubscribe\n";

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $t ) { return strip_tags( (string) $t ); } // Same as the r8-render stub.
}

$g5_root = dirname( __DIR__, 2 );
$g5_src  = static function ( $file ) use ( $g5_root ) {
	return (string) file_get_contents( $g5_root . '/' . $file );
};
$GLOBALS['g5_sent'] = array();
add_action(
	'wpac_mail_sent',
	static function ( $type, $mail, $ok, $context ) {
		$GLOBALS['g5_sent'][] = array( 'type' => $type, 'mail' => $mail, 'context' => $context );
	},
	10,
	4
);
$g5_last = static function ( $type ) {
	foreach ( array_reverse( $GLOBALS['g5_sent'] ) as $m ) {
		if ( $m['type'] === $type ) {
			return $m;
		}
	}
	return null;
};
$g5_header = static function ( $m, $name ) {
	foreach ( (array) ( $m ? $m['mail']['headers'] : array() ) as $h ) {
		if ( 0 === stripos( $h, $name . ':' ) ) {
			return trim( substr( $h, strlen( $name ) + 1 ) );
		}
	}
	return null;
};
$g5_alt = static function () {
	$p = new ReflectionProperty( Notifications::class, 'alt_body' );
	$p->setAccessible( true );
	return (string) $p->getValue();
};
delete_option( Notifications::TEMPLATES_OPTION );

// ---------------------------------------------------------------------------------------------
// R14-P1: every template has a built-in default whose placeholders are offered by the template.
$g5_bad = array();
foreach ( Notifications::template_defs() as $g5_key => $g5_def ) {
	$g5_d = Notifications::default_template( $g5_key );
	if ( ! $g5_d || '' === trim( $g5_d[0] ) || '' === trim( $g5_d[1] ) ) {
		$g5_bad[] = $g5_key . ': no default';
		continue;
	}
	preg_match_all( '/\{([a-z_]+)\}/', $g5_d[0] . $g5_d[1], $g5_m );
	foreach ( array_diff( array_unique( $g5_m[1] ), $g5_def['vars'] ) as $g5_v ) {
		$g5_bad[] = $g5_key . ': {' . $g5_v . '} not offered';
	}
	$g5_labels = Notifications::placeholder_labels( $g5_key );
	foreach ( $g5_def['vars'] as $g5_v ) {
		if ( ! isset( $g5_labels[ $g5_v ] ) ) {
			$g5_bad[] = $g5_key . ': {' . $g5_v . '} without description';
		}
	}
}
check( 'R14-P1 every template has a default text with only offered (and described) placeholders', array(), $g5_bad );
check( 'R14-P1 mails with text chosen by strangers offer no free-text placeholder', array( array( 'site', 'link' ), array( 'site', 'portal_url' ) ), array( Notifications::template_defs()['confirm_application']['vars'], Notifications::template_defs()['already_registered']['vars'] ) );

// Defaults = the previous texts (the msgids stay, translations keep working).
$g5_aff = AffiliateRepository::create( array( 'name' => 'Gerda Fünf', 'email' => 'gerda5@example.de', 'status' => 'pending' ) );
$GLOBALS['g5_sent'] = array();
Notifications::application_rejected( $g5_aff, 'Keine passende Website' );
$m = $g5_last( 'application_rejected' );
check(
	'R14-P1 default template renders the previous text (rejection)',
	array( '[Test Shop] Your affiliate application', "Hello Gerda Fünf,\n\nunfortunately we cannot accept your application at this time.\n\nKeine passende Website", 'application_rejected' ),
	array( $m['mail']['subject'], $m['mail']['message'], $m['context']['template'] )
);
Notifications::confirm_application( 'neu5@example.de', 'http://example.test/?wpac_confirm=abc' );
$m = $g5_last( 'confirm_application' );
check( 'R14-P1 default double opt-in text unchanged (site and link filled in)', true, false !== strpos( $m['mail']['message'], 'partner program of Test Shop with this e-mail address' ) && false !== strpos( $m['mail']['message'], "application:\nhttp://example.test/?wpac_confirm=abc\n" ) );

// Approval mail: partner link, code and commission rate.
$GLOBALS['g5_sent'] = array();
AffiliateRepository::set_status( $g5_aff, 'active' );
$m       = $g5_last( 'application_approved' );
$g5_code = CodeRepository::main_code( $g5_aff );
$g5_cond = Portal::conditions( AffiliateRepository::find( $g5_aff ) );
$g5_link = add_query_arg( (string) Settings::get( 'referral_param' ), rawurlencode( $g5_code ), home_url( '/' ) );
check(
	'R14-P1 approval mail contains partner link, code and commission rate',
	array( true, true, true, true ),
	array(
		'' !== $g5_code && null !== $m,
		false !== strpos( (string) $m['mail']['message'], 'Your partner link: ' . $g5_link ),
		false !== strpos( (string) $m['mail']['message'], 'Your partner code: ' . $g5_code ),
		false !== strpos( (string) $m['mail']['message'], 'Your commission: ' . reset( $g5_cond ) ),
	)
);
check( 'R14-P1 approval mail still contains the access to the partner area', true, false !== strpos( (string) $m['mail']['message'], 'Your partner area with links, codes and statistics:' ) );

// Custom template: placeholders filled in once, unknown ones stay, subject on one line.
$g5_res = Notifications::save_template( 'application_approved', "Willkommen, {name}!\n", "Hallo {name},\n\ndein Link: {link}\ndein Code: {code}\nProvision: {rate}\n{unbekannt}\n<script>x</script>" );
check( 'R14-P1 template saved', true, $g5_res );
$g5_stored = Notifications::custom_template( 'application_approved' );
check( 'R14-P1 stored template: trimmed, HTML tags removed', array( 'Willkommen, {name}!', "Hallo {name},\n\ndein Link: {link}\ndein Code: {code}\nProvision: {rate}\n{unbekannt}\nx" ), $g5_stored );
$wpdb->update( t( 'affiliates' ), array( 'name' => "Kim {link}\nBcc: x@example.de" ), array( 'id' => $g5_aff ) );
AffiliateRepository::clear_cache( $g5_aff );
$GLOBALS['g5_sent'] = array();
Notifications::application_approved( $g5_aff );
$m = $g5_last( 'application_approved' );
check(
	'R14-P1 custom template used: values inserted once, unknown placeholder kept, no line break in the subject',
	array( 'Willkommen, Kim {link} Bcc: x@example.de!', true, true, true ),
	array(
		$m['mail']['subject'],
		0 === strpos( $m['mail']['message'], "Hallo Kim {link}\nBcc: x@example.de,\n\ndein Link: " . $g5_link . "\ndein Code: " . $g5_code ),
		false !== strpos( $m['mail']['message'], "\n{unbekannt}\n" ),
		false !== strpos( $m['mail']['message'], 'Provision: ' . reset( $g5_cond ) ),
	)
);
$wpdb->update( t( 'affiliates' ), array( 'name' => 'Gerda Fünf' ), array( 'id' => $g5_aff ) );
AffiliateRepository::clear_cache( $g5_aff );

// Saving the built-in text stores nothing; empty input is refused; reset.
list( $g5_ds, $g5_db ) = Notifications::default_template( 'credit_note' );
Notifications::save_template( 'credit_note', $g5_ds, str_replace( "\n", "\r\n", $g5_db ) );
check( 'R14-P1 template equal to the built-in text is not stored (keeps following translations)', null, Notifications::custom_template( 'credit_note' ) );
check( 'R14-P1 empty subject or text is refused', array( 'wpac_template_empty', 'wpac_template_unknown' ), array( Notifications::save_template( 'credit_note', ' ', 'x' )->get_error_code(), Notifications::save_template( 'nope', 'S', 'B' )->get_error_code() ) );
Notifications::reset_template( 'application_approved' );
check( 'R14-P1 reset returns to the built-in text', array( null, Notifications::default_template( 'application_approved' ) ), array( Notifications::custom_template( 'application_approved' ), Notifications::template( 'application_approved' ) ) );
check( 'R14-P1 templates are stored in one option that is not autoloaded', true, false !== strpos( $g5_src( 'src/Affiliates/Notifications.php' ), 'update_option( self::TEMPLATES_OPTION, $settings, false )' ) );
check( 'R14-P1 templates are removed on uninstall together with the settings', true, (bool) preg_match( "/'wpac_monitor_key', 'wpac_mail_templates'/", $g5_src( 'uninstall.php' ) ) );

// Status mail variants have their own templates (mail type stays status_changed).
Notifications::save_template( 'status_paused', 'Pause', "Hey {name}, Pause. {reason}" );
$GLOBALS['g5_sent'] = array();
Notifications::status_changed( $g5_aff, 'paused', 'active', 'Prüfung' );
Notifications::status_changed( $g5_aff, 'active', 'paused', '' );
check(
	'R14-P1 status mail: template per status, type unchanged for wpac_mail',
	array( array( 'status_changed', 'status_changed' ), 'Hey Gerda Fünf, Pause. Reason: Prüfung', '[Test Shop] Your partner account is active again' ),
	array( array_column( $GLOBALS['g5_sent'], 'type' ), $GLOBALS['g5_sent'][0]['mail']['message'], $GLOBALS['g5_sent'][1]['mail']['subject'] )
);
Notifications::reset_template( 'status_paused' );

// wpac_mail runs after the template and can still change everything.
$GLOBALS['g5_filter_saw'] = '';
add_filter(
	'wpac_mail',
	static function ( $mail, $type, $context ) {
		if ( 'bank_details_changed' === $type ) {
			$GLOBALS['g5_filter_saw'] = $mail['message'] . '|' . ( $context['template'] ?? '' );
			$mail['subject']          = 'Vom Filter';
		}
		return $mail;
	},
	10,
	3
);
Notifications::save_template( 'bank_details_changed', 'Bankdaten', 'Hallo {name}, deine Bankdaten wurden geändert.' );
$GLOBALS['g5_sent'] = array();
Notifications::bank_details_changed( $g5_aff );
check(
	'R14-P1 wpac_mail sees the filled-in template (and the template key) and its changes win',
	array( 'Hallo Gerda Fünf, deine Bankdaten wurden geändert.|bank_details_changed', 'Vom Filter' ),
	array( $GLOBALS['g5_filter_saw'], $g5_last( 'bank_details_changed' )['mail']['subject'] )
);
Notifications::reset_template( 'bank_details_changed' );

// HTML layout: escaped values, links, Content-Type, plain-text alternative.
Notifications::set_layout( 'html' );
Notifications::save_template( 'application_rejected', 'Absage', "Hallo {name},\n\nleider nein. {reason}\nMehr: https://example.test/faq?a=1&b=2." );
$GLOBALS['g5_sent'] = array();
Notifications::application_rejected( $g5_aff, '<b>Evil</b> & "Co" <a href="javascript:alert(1)">x</a>' );
$m     = $g5_last( 'application_rejected' );
$g5_h  = (string) $m['mail']['message'];
$g5_pt = $g5_alt();
check(
	'R14-P1 HTML layout: values escaped, no markup from the reason, links for web addresses, line breaks kept',
	array( true, false, false, true, true, true ),
	array(
		false !== strpos( $g5_h, '&lt;b&gt;Evil&lt;/b&gt; &amp; &quot;Co&quot;' ),
		false !== strpos( $g5_h, '<b>Evil' ),
		false !== strpos( $g5_h, 'href="javascript' ),
		false !== strpos( $g5_h, '<a href="https://example.test/faq?a=1&amp;b=2" style="color:#2563eb;">https://example.test/faq?a=1&amp;b=2</a>.' ),
		false !== strpos( $g5_h, "<br>\n" ) && false !== strpos( $g5_h, '<!DOCTYPE html>' ),
		$m['context']['html'],
	)
);
check( 'R14-P1 HTML layout: Content-Type header and plain-text alternative with the raw text', array( 'text/html; charset=UTF-8', true ), array( $g5_header( $m, 'Content-Type' ), 0 === strpos( $g5_pt, "Hallo Gerda Fünf,\n\nleider nein. <b>Evil</b> & \"Co\"" ) ) );
$g5_pm = new stdClass();
$g5_sending = new ReflectionProperty( Notifications::class, 'sending' );
$g5_sending->setAccessible( true );
$g5_sending->setValue( null, true );
Notifications::set_alt_body( $g5_pm );
$g5_sending->setValue( null, false );
$g5_pm2 = new stdClass();
Notifications::set_alt_body( $g5_pm2 );
check( 'R14-P1 plain-text part is handed to PHPMailer (AltBody) only while our mail is sent', array( $g5_pt, false ), array( $g5_pm->AltBody ?? null, isset( $g5_pm2->AltBody ) ) );
// Mails without a template (operator mails) stay plain text.
$GLOBALS['g5_sent'] = array();
Notifications::send( 'g5_admin', 'program@example.de', 'S', 'M <b>', array( 'admin' => true ) );
check( 'R14-P1 mails without a template stay plain text in the HTML layout', array( 'M <b>', null ), array( $g5_last( 'g5_admin' )['mail']['message'], $g5_header( $g5_last( 'g5_admin' ), 'Content-Type' ) ) );
// A filter that rewrites the HTML gets a plain-text part derived from it.
add_filter(
	'wpac_mail',
	static function ( $mail, $type ) {
		if ( 'public_change_resolved' === $type ) {
			$mail['message'] = '<p>Eigenes &amp; HTML</p><p>Zeile 2</p>';
		}
		return $mail;
	},
	10,
	2
);
Notifications::public_change_resolved( $g5_aff, true );
check( 'R14-P1 HTML rewritten by wpac_mail: plain-text part follows the filter', "Eigenes & HTML\nZeile 2", $g5_alt() );
Notifications::reset_template( 'application_rejected' );
Notifications::set_layout( 'text' );
$GLOBALS['g5_sent'] = array();
Notifications::application_rejected( $g5_aff, 'x' );
check( 'R14-P1 plain-text layout: no HTML, no alternative part', array( null, '', false ), array( $g5_header( $g5_last( 'application_rejected' ), 'Content-Type' ), $g5_alt(), $g5_last( 'application_rejected' )['context']['html'] ) );

// Test mail to the current admin with example values.
$GLOBALS['g5_sent'] = array();
$g5_t = Notifications::send_test( 'payout_paid_manual', 'chef@example.de' );
$m    = $g5_last( 'payout_paid' );
check(
	'R14-P1 test mail: to the given admin address, example values, marked as test, no Reply-To',
	array( true, 'chef@example.de', true, true, null, true ),
	array( $g5_t, $m['mail']['to'], 0 === strpos( $m['mail']['subject'], '[Test] [Test Shop] Payout GS-2026-0001 paid' ), false !== strpos( $m['mail']['message'], 'Max Mustermann' ), $g5_header( $m, 'Reply-To' ), $m['context']['test'] )
);
check( 'R14-P1 test mail refused without a valid address or for an unknown template', array( 'wpac_template_email', 'wpac_template_unknown' ), array( Notifications::send_test( 'credit_note', '' )->get_error_code(), Notifications::send_test( 'nope', 'chef@example.de' )->get_error_code() ) );
$g5_test_vars = array();
foreach ( Notifications::template_defs() as $g5_key => $g5_def ) {
	list( $g5_s, $g5_b ) = Notifications::render_template( $g5_key, Notifications::sample_vars( $g5_key ) );
	if ( preg_match( '/\{[a-z_]+\}/', $g5_s . $g5_b ) ) {
		$g5_test_vars[] = $g5_key;
	}
}
check( 'R14-P1 example values fill every placeholder of every default template', array(), $g5_test_vars );

// Admin wiring: routes with MANAGE_PROGRAM, own menu entry.
$g5_admin = $g5_src( 'src/Admin/Admin.php' );
check(
	'R14-P1 template actions are routed with the program capability and the page has a menu entry',
	array( 4, true ),
	array(
		preg_match_all( "/'mail_(template_save|template_reset|template_test|layout_save)'\s*=> array\( Pages\\\\MailTemplatesPage::class, Capabilities::MANAGE_PROGRAM \)/", $g5_admin ),
		false !== strpos( $g5_admin, 'Pages\MailTemplatesPage::menu();' ),
	)
);

// ---------------------------------------------------------------------------------------------
// R14-S3: unsubscribe only on POST, one-click header.
$g5_a = AffiliateRepository::find( $g5_aff );
$GLOBALS['g5_sent'] = array();
Notifications::send_optional( 'monthly_partner', $g5_a, 'Summary', 'Body' );
$m = $g5_last( 'monthly_partner' );
check( 'R14-S3 List-Unsubscribe-Post header for one-click unsubscribe (RFC 8058)', array( 'List-Unsubscribe=One-Click', true ), array( $g5_header( $m, 'List-Unsubscribe-Post' ), 0 === strpos( (string) $g5_header( $m, 'List-Unsubscribe' ), '<http://example.test/?' ) ) );
$g5_q = array(
	'wpac_unsubscribe' => 'monthly_partner',
	'wpac_aff'         => $g5_aff,
	'wpac_sig'         => Notifications::unsubscribe_sig( $g5_aff, 'monthly_partner' ),
	'wpac_on'          => 0,
);
Notifications::set_opt_out( $g5_aff, 'monthly_partner', false );
$g5_r = Notifications::unsubscribe_request( $g5_q, false );
check(
	'R14-S3 GET (e.g. a mail scanner) only shows a confirmation with a POST button, nothing changes',
	array( 200, true, true, false ),
	array( $g5_r['status'], false !== strpos( $g5_r['html'], '<form method="post" action="' . esc_url( Notifications::unsubscribe_url( $g5_a, 'monthly_partner' ) ) . '">' ), false !== strpos( $g5_r['html'], 'Monthly summary' ), Notifications::opted_out( $g5_aff, 'monthly_partner' ) )
);
$g5_r = Notifications::unsubscribe_request( $g5_q, true );
check(
	'R14-S3 POST (button or one-click of the mail client, no nonce) unsubscribes; undo is a POST button too',
	array( 200, true, true, false ),
	array( $g5_r['status'], Notifications::opted_out( $g5_aff, 'monthly_partner' ), false !== strpos( $g5_r['html'], '<form method="post" action="' . esc_url( Notifications::unsubscribe_url( $g5_a, 'monthly_partner', false ) ) . '">' ), false !== strpos( $g5_r['html'], '<a href' ) )
);
$g5_r = Notifications::unsubscribe_request( array( 'wpac_on' => 1 ) + $g5_q, false );
check( 'R14-S3 GET of the undo link does not subscribe again', array( 200, true ), array( $g5_r['status'], Notifications::opted_out( $g5_aff, 'monthly_partner' ) ) );
Notifications::unsubscribe_request( array( 'wpac_on' => 1 ) + $g5_q, true );
check( 'R14-S3 POST of the undo link subscribes again', false, Notifications::opted_out( $g5_aff, 'monthly_partner' ) );
$g5_r = Notifications::unsubscribe_request( array( 'wpac_sig' => 'falsch' ) + $g5_q, true );
check( 'R14-S3 wrong signature: 400, nothing changes', array( 400, false ), array( $g5_r['status'], Notifications::opted_out( $g5_aff, 'monthly_partner' ) ) );
$g5_r = Notifications::unsubscribe_request( array( 'wpac_unsubscribe' => 'payout_paid', 'wpac_sig' => Notifications::unsubscribe_sig( $g5_aff, 'payout_paid' ) ) + $g5_q, true );
check( 'R14-S3 mails needed for the partnership cannot be unsubscribed by POST either', array( 400, false ), array( $g5_r['status'], Notifications::opted_out( $g5_aff, 'payout_paid' ) ) );
$g5_n = $g5_src( 'src/Affiliates/Notifications.php' );
check( 'R14-S3 the init handler only stores the choice for POST requests', true, false !== strpos( $g5_n, "self::unsubscribe_request( \$query, 'POST' === \$method )" ) );

delete_option( Notifications::TEMPLATES_OPTION );
