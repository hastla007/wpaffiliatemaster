<?php
/**
 * Integration checks of work package G6: central mail function (filters, failure log, sender,
 * Reply-To), mail gaps (applicant confirmation, unsubscribe, payout mail per method, commission
 * notices), job queue (missing handler, retry of running jobs), log details, support data.
 * Included from tests/integration/run.php (uses its check() helper and database).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Affiliates\Notifications;
use WPAffiliateCaptain\Core\Jobs;
use WPAffiliateCaptain\Core\Log;
use WPAffiliateCaptain\Core\Settings;

echo "G6 mails, log, queue, support data\n";

$g6_set = static function ( array $values ) {
	update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), $values ) );
	Settings::flush_cache();
};
// Every mail of the plugin passes wpac_mail_sent: remember them.
$GLOBALS['g6_sent'] = array();
add_action(
	'wpac_mail_sent',
	static function ( $type, $mail, $ok ) {
		$GLOBALS['g6_sent'][] = array( 'type' => $type, 'mail' => $mail, 'ok' => $ok );
	},
	10,
	3
);
$g6_last = static function ( $type ) {
	foreach ( array_reverse( $GLOBALS['g6_sent'] ) as $m ) {
		if ( $m['type'] === $type ) {
			return $m;
		}
	}
	return null;
};
$g6_header = static function ( $m, $name ) {
	foreach ( (array) ( $m ? $m['mail']['headers'] : array() ) as $h ) {
		if ( 0 === stripos( $h, $name . ':' ) ) {
			return trim( substr( $h, strlen( $name ) + 1 ) );
		}
	}
	return null;
};

$g6_set( array( 'admin_email' => 'program@example.de', 'mail_from_name' => '', 'mail_from_email' => '' ) );
$g6_aff = AffiliateRepository::create( array( 'name' => 'Grete Sechs', 'email' => 'grete@example.de', 'status' => 'active' ) );
$g6_a   = AffiliateRepository::find( $g6_aff );

// R5-W48 / R7-AD13: partner mails get Reply-To = program address; operator mails none.
Notifications::send( 'g6_partner', 'grete@example.de', 'S', 'M', array( 'affiliate_id' => $g6_aff ) );
$m = $g6_last( 'g6_partner' );
check( 'R7-AD13 partner mail: Reply-To is the notification e-mail of the settings', 'program@example.de', $g6_header( $m, 'Reply-To' ) );
check( 'R7-AD13 without own sender address no From header (WordPress default)', null, $g6_header( $m, 'From' ) );
Notifications::send( 'g6_admin', Notifications::admin_email(), 'S', 'M', array( 'admin' => true ) );
check( 'R7-AD13 operator mail: no Reply-To to itself', null, $g6_header( $g6_last( 'g6_admin' ), 'Reply-To' ) );
// Configurable sender.
$g6_set( array( 'mail_from_name' => 'Partnerprogramm <Shop>', 'mail_from_email' => 'partner@shop.example' ) );
Notifications::send( 'g6_partner', 'grete@example.de', 'S', 'M' );
check( 'R7-AD13 configured sender name and address (header-safe)', 'Partnerprogramm Shop <partner@shop.example>', $g6_header( $g6_last( 'g6_partner' ), 'From' ) );
$g6_san = Settings::sanitize( array( 'mail_from_email' => 'no address', 'mail_from_name' => "A\nB" ), $g6_err );
check( 'R7-AD13 invalid sender address is rejected with a message', true, isset( $g6_err['mail_from_email'] ) );
$g6_set( array( 'mail_from_name' => '', 'mail_from_email' => '' ) );
// Dunning ("please reply") goes through the central function with Reply-To.
check( 'R7-AD13 dunning uses the central mail function', true, false !== strpos( (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Commissions/Dunning.php' ), "Notifications::send(\n\t\t\t'dunning'" ) );

// R5-W48: filter changes or skips a mail; a failed mail fires wpac_mail_failed and returns false.
add_filter(
	'wpac_mail',
	static function ( $mail, $type ) {
		if ( 'g6_skip' === $type ) {
			return false;
		}
		if ( 'g6_change' === $type ) {
			$mail['subject']   = 'Changed';
			$mail['headers'][] = 'Content-Type: text/html; charset=UTF-8';
		}
		return $mail;
	},
	10,
	2
);
$g6_before = count( $GLOBALS['test_mails'] );
check( 'R5-W48 filter wpac_mail can skip a mail (counts as handled)', true, Notifications::send( 'g6_skip', 'grete@example.de', 'S', 'M' ) );
check( 'R5-W48 skipped mail is not handed to wp_mail()', $g6_before, count( $GLOBALS['test_mails'] ) );
Notifications::send( 'g6_change', 'grete@example.de', 'S', 'M' );
$m = $g6_last( 'g6_change' );
check( 'R5-W48 filter wpac_mail changes subject and headers (HTML possible)', array( 'Changed', 'text/html; charset=UTF-8' ), array( $m['mail']['subject'], $g6_header( $m, 'Content-Type' ) ) );
$GLOBALS['g6_failed'] = array();
add_action( 'wpac_mail_failed', static function ( $type, $mail, $error ) { $GLOBALS['g6_failed'][] = array( $type, $error ); }, 10, 3 );
$GLOBALS['test_mail_fail'] = true;
check( 'R5-W48 failed mail returns false', false, Notifications::send( 'g6_fail', 'grete@example.de', 'S', 'M' ) );
$GLOBALS['test_mail_fail'] = false;
check( 'R5-W48 failed mail fires wpac_mail_failed with a reason', array( array( 'g6_fail', 'wp_mail() returned false' ) ), $GLOBALS['g6_failed'] );
Notifications::capture_mail_error( new WP_Error( 'x', 'not while sending' ) );
check( 'R5-W48 wp_mail_failed of other plugins is not attributed to our mails', array(), array_slice( $GLOBALS['g6_failed'], 1 ) );
// No direct wp_mail() calls left outside the central function.
$g6_direct = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( dirname( __DIR__, 2 ) . '/src', FilesystemIterator::SKIP_DOTS ) ) as $g6_f ) {
	if ( '.php' === substr( (string) $g6_f, -4 ) && false === strpos( (string) $g6_f, 'Notifications.php' ) && preg_match( '/(?<![\w>:])wp_mail\s*\(/', preg_replace( '#//.*$|/\*.*?\*/#ms', '', (string) file_get_contents( (string) $g6_f ) ) ) ) {
		$g6_direct[] = basename( (string) $g6_f );
	}
}
check( 'R5-W48 all mails go through Notifications::send()', array(), $g6_direct );

// R7-AD14: applicant confirmation after the double opt-in; operator mail with Reply-To applicant.
$g6_app = AffiliateRepository::create( array( 'name' => 'Bewerber Sechs', 'email' => 'bewerber6@example.de', 'status' => 'pending' ) );
$GLOBALS['g6_sent'] = array();
Notifications::application_received( $g6_app );
check( 'R7-AD14 application: operator mail and confirmation to the applicant', array( 'application_received', 'application_confirmed' ), array_column( $GLOBALS['g6_sent'], 'type' ) );
check( 'R7-AD14 confirmation goes to the applicant', 'bewerber6@example.de', $GLOBALS['g6_sent'][1]['mail']['to'] );
check( 'R7-AD14 operator can answer the applicant directly (Reply-To)', 'bewerber6@example.de', $g6_header( $GLOBALS['g6_sent'][0], 'Reply-To' ) );

// R7-AD14: monthly mail can be unsubscribed per partner (signed link, List-Unsubscribe).
$GLOBALS['g6_sent'] = array();
Notifications::send_optional( 'monthly_partner', $g6_a, 'Summary', 'Body' );
$m = $g6_last( 'monthly_partner' );
check( 'R7-AD14 monthly mail has an unsubscribe link and List-Unsubscribe header', array( true, true ), array( false !== strpos( $m['mail']['message'], 'wpac_unsubscribe=monthly_partner' ), null !== $g6_header( $m, 'List-Unsubscribe' ) ) );
check( 'R7-AD14 unsubscribe link is signed per partner and type', true, false !== strpos( Notifications::unsubscribe_url( $g6_a, 'monthly_partner' ), 'wpac_sig=' . Notifications::unsubscribe_sig( $g6_aff, 'monthly_partner' ) ) && Notifications::unsubscribe_sig( $g6_aff, 'monthly_partner' ) !== Notifications::unsubscribe_sig( $g6_aff, 'commission' ) );
Notifications::set_opt_out( $g6_aff, 'monthly_partner', true );
check( 'R7-AD14 opt-out is stored per partner and type', array( true, false ), array( Notifications::opted_out( $g6_aff, 'monthly_partner' ), Notifications::opted_out( $g6_aff, 'commission' ) ) );
Notifications::set_opt_out( $g6_aff, 'monthly_partner', false );
check( 'R7-AD14 subscribe again', false, Notifications::opted_out( $g6_aff, 'monthly_partner' ) );
Notifications::set_opt_out( $g6_aff, 'payout_paid', true );
check( 'R7-AD14 mails needed for the partnership cannot be unsubscribed', false, Notifications::opted_out( $g6_aff, 'payout_paid' ) );
check( 'R7-AD14 monthly mail skips unsubscribed partners', true, false !== strpos( (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Reports/MonthlyMail.php' ), "opted_out( (int) \$aff->id, 'monthly_partner' )" ) );

// R5-W48 / R7-AD14: payout mail follows the payment method and can be switched off in the settings.
$g6_pid = (int) $wpdb->get_var( 'SELECT id FROM ' . t( 'payouts' ) . ' ORDER BY id LIMIT 1' );
if ( $g6_pid ) {
	$g6_paff = (int) $wpdb->get_var( 'SELECT affiliate_id FROM ' . t( 'payouts' ) . " WHERE id = {$g6_pid}" );
	q( 'UPDATE ' . t( 'affiliates' ) . " SET email = 'payee6@example.de' WHERE id = {$g6_paff}" );
	AffiliateRepository::clear_cache( $g6_paff );
	$g6_snap = (string) $wpdb->get_var( 'SELECT profile_snapshot FROM ' . t( 'payouts' ) . " WHERE id = {$g6_pid}" );
	$wpdb->query( $wpdb->prepare( 'UPDATE ' . t( 'payouts' ) . ' SET profile_snapshot = %s WHERE id = %d', wp_json_encode( array( 'payout_method' => 'manual' ) ), $g6_pid ) );
	$GLOBALS['g6_sent'] = array();
	Notifications::payout_paid( $g6_pid );
	$m = $g6_last( 'payout_paid' );
	check( 'R5-W48 payout mail for PayPal/manual does not claim a bank transfer', array( false, true ), array( false !== strpos( (string) $m['mail']['message'], 'bank account' ), false !== strpos( (string) $m['mail']['message'], 'payment method agreed' ) ) );
	$wpdb->query( $wpdb->prepare( 'UPDATE ' . t( 'payouts' ) . ' SET profile_snapshot = %s WHERE id = %d', wp_json_encode( array( 'payout_method' => 'sepa' ) ), $g6_pid ) );
	Notifications::payout_paid( $g6_pid );
	check( 'R5-W48 payout mail for SEPA names the bank account', true, false !== strpos( (string) $g6_last( 'payout_paid' )['mail']['message'], 'bank account' ) );
	$g6_set( array( 'notify_payout_paid' => 0 ) );
	$GLOBALS['g6_sent'] = array();
	Notifications::payout_paid( $g6_pid );
	check( 'R7-AD14 payout mail can be switched off in the settings', null, $g6_last( 'payout_paid' ) );
	$g6_set( array( 'notify_payout_paid' => 1 ) );
	$wpdb->query( $wpdb->prepare( 'UPDATE ' . t( 'payouts' ) . ' SET profile_snapshot = %s WHERE id = %d', $g6_snap, $g6_pid ) );
}

// R5-O34: commission notices – off by default, one mail per partner and request when on.
$g6_cids = array_map( 'intval', $wpdb->get_col( 'SELECT id FROM ' . t( 'commissions' ) . " WHERE affiliate_id = (SELECT affiliate_id FROM " . t( 'commissions' ) . " c JOIN " . t( 'affiliates' ) . " a ON a.id = c.affiliate_id WHERE a.status = 'active' GROUP BY c.affiliate_id HAVING COUNT(*) >= 2 ORDER BY c.affiliate_id LIMIT 1) LIMIT 2" ) );
check( 'R5-O34 test data: two commissions of one partner', 2, count( $g6_cids ) );
Notifications::commission_created( $g6_cids[0] );
check( 'R5-O34 off by default: nothing collected', 0, Notifications::flush_commissions() );
$g6_set( array( 'notify_commissions' => 1 ) );
$GLOBALS['g6_sent'] = array();
Notifications::commission_created( $g6_cids[0] );
Notifications::commission_created( $g6_cids[1] );
Notifications::commission_rejected( $g6_cids[1] );
check( 'R5-O34 one mail per partner for all events of the request', 1, Notifications::flush_commissions() );
$m = $g6_last( 'commission' );
check( 'R5-O34 mail lists new and rejected commissions and can be unsubscribed', array( true, true, false, true ), array( false !== strpos( $m['mail']['message'], 'New commissions' ) && false !== strpos( $m['mail']['message'], ': 2 (' ), false !== strpos( $m['mail']['message'], 'Rejected commissions' ), false !== strpos( $m['mail']['message'], 'Approved commissions' ), false !== strpos( $m['mail']['message'], 'wpac_unsubscribe=commission' ) ) );
$g6_caff = (int) $wpdb->get_var( 'SELECT affiliate_id FROM ' . t( 'commissions' ) . ' WHERE id = ' . $g6_cids[0] );
Notifications::set_opt_out( $g6_caff, 'commission', true );
Notifications::commission_approved( $g6_cids[0] );
check( 'R5-O34 unsubscribed partner gets no commission mail', 0, Notifications::flush_commissions() );
Notifications::set_opt_out( $g6_caff, 'commission', false );
$g6_set( array( 'notify_commissions' => 0 ) );

// R5-O23: a privacy request whose mail fails is not reported as sent.
$GLOBALS['test_mail_fail'] = true;
check( 'R5-O23 privacy request with failed mail reports a failure', 'privacy_failed', WPAffiliateCaptain\Privacy\Privacy::partner_request( $g6_a, 'object', 'Please stop.' ) );
$GLOBALS['test_mail_fail'] = false;
check( 'R5-O23 privacy request with mail reports success', 'privacy_sent', WPAffiliateCaptain\Privacy\Privacy::partner_request( $g6_a, 'object', 'Please stop.' ) );

// R5-O20: a missing job handler does not use up the attempts.
$jt = t( 'jobs' );
Jobs::enqueue( 'g6_unknown', 'o20', array(), 'e', 'e' );
$g6_jid = (int) $wpdb->get_var( "SELECT id FROM {$jt} WHERE idempotency_key = 'g6_unknown:o20'" );
q( "UPDATE {$jt} SET attempts = 7 WHERE id = {$g6_jid}" );
Jobs::run( $g6_jid );
$g6_job = $wpdb->get_row( "SELECT status, attempts, last_error_code, TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), next_attempt_at) AS wait FROM {$jt} WHERE id = {$g6_jid}" );
check( 'R5-O20 no handler: postponed by an hour, attempts unchanged, not failed', array( 'queued', 7, 'no_handler', true ), array( $g6_job->status, (int) $g6_job->attempts, $g6_job->last_error_code, (int) $g6_job->wait >= 55 ) );

// R5-O21: "retry now" leaves running and finished jobs alone.
q( "UPDATE {$jt} SET status = 'running' WHERE id = {$g6_jid}" );
check( 'R5-O21 retry of a running job is refused', false, Jobs::retry( $g6_jid ) );
check( 'R5-O21 running job keeps its status', 'running', $wpdb->get_var( "SELECT status FROM {$jt} WHERE id = {$g6_jid}" ) );
q( "UPDATE {$jt} SET status = 'done' WHERE id = {$g6_jid}" );
check( 'R5-O21 retry of a finished job is refused', array( false, 'done' ), array( Jobs::retry( $g6_jid ), $wpdb->get_var( "SELECT status FROM {$jt} WHERE id = {$g6_jid}" ) ) );
q( "DELETE FROM {$jt} WHERE id = {$g6_jid}" );

// R5-W43: log entries carry exception class, code, file and line; escaped texts are plain again.
try {
	throw new WPAffiliateCaptain\Core\IntegrationException( 'g6_code', esc_html( "Duplicate entry '5'" ) );
} catch ( \Throwable $g6_e ) {
	$g6_d = Log::describe( $g6_e );
}
check( 'R5-W43 exception details for the log', array( 'WPAffiliateCaptain\Core\IntegrationException', 'g6_code', "Duplicate entry '5'", 'r8-g6.php', true ), array( $g6_d['exception'], $g6_d['code'], $g6_d['message'], basename( $g6_d['file'] ), $g6_d['line'] > 0 ) );
check( 'R5-W43 not_queued writes to the plugin log, not error_log()', array( true, false ), array( false !== strpos( (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Sales/ContractApi.php' ), "Log::exception( 'Sale or refund could not be processed" ), (bool) preg_match( '/^\s*error_log\(/m', (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Sales/ContractApi.php' ) ) ) );
// The job keeps the plain text of the error (exception messages are HTML-escaped).
add_filter( 'wpac_run_job', static function ( $h, $type ) { if ( 'g6_quote' === $type ) { throw new WPAffiliateCaptain\Core\IntegrationException( 'db', esc_html( "Duplicate entry 'x'" ) ); } return $h; }, 10, 2 );
Jobs::enqueue( 'g6_quote', 'w43', array(), 'e', 'e' );
$g6_jid = (int) $wpdb->get_var( "SELECT id FROM {$jt} WHERE idempotency_key = 'g6_quote:w43'" );
Jobs::run( $g6_jid );
check( 'R5-W43 job error stored as plain text', "Duplicate entry 'x'", $wpdb->get_var( "SELECT last_error FROM {$jt} WHERE id = {$g6_jid}" ) );
q( "DELETE FROM {$jt} WHERE id = {$g6_jid}" );

// R5-W46: anonymized support data.
if ( ! function_exists( 'get_locale' ) ) {
	function get_locale() { return 'de_DE'; }
}
$g6_sd = WPAffiliateCaptain\Core\Monitor::support_data();
check( 'R5-W46 support data sections', array( 'versions', 'environment', 'settings', 'queue', 'checks' ), array_keys( $g6_sd ) );
check( 'R5-W46 support data has version, currency, hold period, cookie settings, VIES, queue', array( WPAC_VERSION, 'EUR', true, true, true, true ), array( $g6_sd['versions']['plugin'], $g6_sd['settings']['currency'], isset( $g6_sd['settings']['hold_days'] ), isset( $g6_sd['settings']['cookie_mode'], $g6_sd['settings']['cookie_days'] ), isset( $g6_sd['settings']['vies_check'] ), isset( $g6_sd['queue']['jobs_failed'], $g6_sd['queue']['last_hourly_run'] ) ) );
$g6_json = wp_json_encode( $g6_sd );
check( 'R5-W46 support data contains no personal data or secrets', array( false, false, false ), array( (bool) preg_match( '/@[a-z0-9.-]+\.[a-z]{2,}/i', $g6_json ), false !== strpos( $g6_json, 'DE89370400440532013000' ), false !== strpos( $g6_json, 'Segelschule Test GmbH' ) ) );
WPAffiliateCaptain\Core\Cron::hourly();
$g6_last_run = get_option( WPAffiliateCaptain\Core\Cron::LAST_RUN_OPTION );
check( 'R5-W46 last hourly run is recorded', true, is_array( $g6_last_run ) && abs( time() - (int) $g6_last_run['hourly'] ) < 60 );
