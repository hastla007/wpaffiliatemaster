<?php
/**
 * R8 package G5: lifecycle, install/uninstall, data integrity, settings, multisite.
 * Included by run.php (shares its helpers); the uninstall/multisite parts run in their own
 * process (r8-g5-isolated.php, own table prefix).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Installer;
use WPAffiliateCaptain\Core\Settings;

echo "R8-G5 lifecycle, data integrity, settings\n";
global $wpdb;
$g5_audit = static function ( $action, $id ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Db::table( 'audit' ) . ' WHERE action = %s AND object_id = %d', $action, $id ) );
};

// R6-QA1: a reason cut in the middle of a multi-byte character used to make WordPress drop the
// whole UPDATE while mails, audit and the rejection of open commissions still ran.
$g5_aff = AffiliateRepository::create( array( 'name' => 'G5 Partner', 'email' => 'g5-partner@example.de', 'status' => 'active' ) );
$g5_reason = str_repeat( 'x', 254 ) . str_repeat( 'ä', 10 );
$g5_res    = AffiliateRepository::set_status( $g5_aff, 'terminated', $g5_reason );
AffiliateRepository::clear_cache( $g5_aff );
$g5_row = AffiliateRepository::find( $g5_aff );
check( 'R6-QA1 termination with an umlaut at the cut is saved (valid UTF-8, 255 characters)', array( true, 'terminated', true, 255 ), array( $g5_res, $g5_row->status, mb_check_encoding( $g5_row->status_reason, 'UTF-8' ), mb_strlen( $g5_row->status_reason ) ) );
$g5_aff2 = AffiliateRepository::create( array( 'name' => 'G5 Partner 2', 'email' => 'g5-partner2@example.de', 'status' => 'active' ) );
AffiliateRepository::find( $g5_aff2 ); // Cached as active …
q( 'UPDATE ' . t( 'affiliates' ) . " SET status = 'paused' WHERE id = {$g5_aff2}" ); // … changed meanwhile.
$GLOBALS['test_mails'] = array();
$g5_res = AffiliateRepository::set_status( $g5_aff2, 'terminated', 'Grund' );
check( 'R6-QA1 status not saved → error, no audit entry, no mail', array( true, 0, 0 ), array( is_wp_error( $g5_res ), $g5_audit( 'affiliate.status', $g5_aff2 ), count( $GLOBALS['test_mails'] ) ) );
$g5_src = '';
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( WPAC_DIR . 'src' ) ) as $g5_file ) {
	if ( '.php' === substr( (string) $g5_file, -4 ) ) {
		$g5_src .= file_get_contents( (string) $g5_file );
	}
}
check( 'R6-QA1 no byte truncation of free text left (reason/note/description/campaign/message)', 0, preg_match_all( '/(?<![_a-z])substr\(\s*[^;]*(reason|note|description|campaign|getMessage|message|path|version|details)[^;]*,\s*0,\s*\d+\s*\)/i', $g5_src ) );

// R6-QA2: Art. 18 restriction with a long note is stored, not silently lost.
$g5_note = str_repeat( 'ü', 300 );
check( 'R6-QA2 restriction with a long umlaut note is stored', array( true, true, true ), array( WPAffiliateCaptain\Privacy\Restriction::add( $g5_aff, 'request', $g5_note ), WPAffiliateCaptain\Privacy\Restriction::is_restricted( $g5_aff ), mb_check_encoding( WPAffiliateCaptain\Privacy\Restriction::get( $g5_aff )['note'], 'UTF-8' ) ) );
WPAffiliateCaptain\Privacy\Restriction::remove( $g5_aff, '', false );

// R6-QA6: settings save is verified; the terms version is cut by characters.
$g5_san = Settings::sanitize( array( 'terms_version' => 'Versiön 2026 – Überarbeitung' ), $g5_err );
check( 'R6-QA6 terms version cut by characters (valid UTF-8)', array( true, 20 ), array( mb_check_encoding( $g5_san['terms_version'], 'UTF-8' ), mb_strlen( $g5_san['terms_version'] ) ) );
check( 'R6-QA6 Settings::update() reports a verified save', array( true, $g5_san['terms_version'] ), array( Settings::update( array( 'terms_version' => $g5_san['terms_version'] ) ), Settings::get( 'terms_version' ) ) );

// R6-QA15: whole-number settings have upper limits.
$g5_san = Settings::sanitize( array( 'hold_days' => '4000000', 'cookie_days' => '99999999999999999999', 'lifetime_days' => '365', 'audit_retention_days' => '36501' ), $g5_err );
check( 'R6-QA15 huge hold period / cookie lifetime rejected, keep the stored value', array( true, true, true, 365, (int) Settings::get( 'hold_days' ), (int) Settings::get( 'cookie_days' ) ), array( isset( $g5_err['hold_days'] ), isset( $g5_err['cookie_days'] ), isset( $g5_err['audit_retention_days'] ), $g5_san['lifetime_days'], $g5_san['hold_days'], $g5_san['cookie_days'] ) );

// R6-QA7: rejecting a payout request with a long umlaut reason works (was "already handled").
$wpdb->insert( t( 'payout_requests' ), array( 'affiliate_id' => $g5_aff, 'currency' => 'EUR', 'amount_minor' => 1000, 'status' => 'requested', 'created_at' => Db::now() ) );
$g5_req = (int) $wpdb->insert_id;
$g5_res = WPAffiliateCaptain\Payouts\PayoutRequests::reject( $g5_req, str_repeat( 'ö', 300 ) );
check( 'R6-QA7 rejection with a long umlaut reason is saved', array( true, 'rejected' ), array( $g5_res, (string) $wpdb->get_var( 'SELECT status FROM ' . t( 'payout_requests' ) . " WHERE id = {$g5_req}" ) ) );

// R6-LC2: the schema version is part of the code; an old main file cannot lower it.
check( 'R6-LC2 schema version from the class constant', array( 11, 11 ), array( Installer::DB_VERSION, Installer::db_version() ) );
$g5_dir = sys_get_temp_dir() . '/g5-plugins/wp-affiliate-captain';
@mkdir( $g5_dir, 0777, true );
file_put_contents( $g5_dir . '/wp-affiliate-captain.php', '<?php' );
file_put_contents( $g5_dir . '/affiliate-captain.php', '<?php' );
$g5_active = get_option( 'active_plugins', 'unset' );
update_option( 'active_plugins', array( 'other/other.php', 'wp-affiliate-captain/wp-affiliate-captain.php' ) );
$g5_switched = WPAffiliateCaptain\Core\Lifecycle::switch_main_file( $g5_dir . '/wp-affiliate-captain.php' );
check( 'R6-LC2 old main file left in the folder → activation switched to the new main file', array( true, array( 'other/other.php', 'wp-affiliate-captain/affiliate-captain.php' ) ), array( $g5_switched, get_option( 'active_plugins' ) ) );
check( 'R6-LC2 the new main file is not switched again', false, WPAffiliateCaptain\Core\Lifecycle::switch_main_file( $g5_dir . '/affiliate-captain.php' ) );
'unset' === $g5_active ? delete_option( 'active_plugins' ) : update_option( 'active_plugins', $g5_active );
delete_option( WPAffiliateCaptain\Core\Lifecycle::TAKEOVER );

// R5-W1/R5-W2 (single site): an old copy in any folder is taken over, data deletion switched off.
update_option( 'active_plugins', array( 'my-old-folder/wp-affiliate-captain.php', 'affiliate-captain/affiliate-captain.php' ) );
$g5_settings = get_option( 'wpac_settings' );
update_option( 'wpac_settings', array_merge( $g5_settings, array( 'delete_on_uninstall' => 1 ) ) );
$g5_taken = WPAffiliateCaptain\Core\Lifecycle::take_over( 'affiliate-captain/affiliate-captain.php' );
check( 'R5-W1 old copy in another folder is detected and deactivated', array( array( 'my-old-folder/wp-affiliate-captain.php' ), array( 'affiliate-captain/affiliate-captain.php' ) ), array( $g5_taken, get_option( 'active_plugins' ) ) );
check( 'R5-W2 takeover switches "delete all data" off for good', 0, (int) get_option( 'wpac_settings' )['delete_on_uninstall'] );
update_option( 'wpac_settings', $g5_settings );
Settings::flush_cache();
delete_option( 'active_plugins' );
delete_option( WPAffiliateCaptain\Core\Lifecycle::TAKEOVER );

// R6-LC3: the schema of a newer version is never touched (downgrade marker stays).
update_option( 'wpac_db_version', 99 );
Installer::install_schema();
check( 'R6-LC3 install_schema() keeps the newer schema version', array( 99, 99 ), array( (int) get_option( 'wpac_db_version' ), Installer::downgraded_from() ) );
update_option( 'wpac_db_version', Installer::db_version() );

// R6-LC4: monitoring token keyed with an own key; old salt-keyed tokens are converted on use.
$g5_settings = get_option( 'wpac_settings' );
update_option( 'wpac_settings', array_merge( $g5_settings, array( 'monitor_token' => 'h1:' . hash_hmac( 'sha256', 'G5Token123456789012345', wp_salt( 'auth' ) . 'wpac_monitor' ) ) ) );
Settings::flush_cache();
check( 'R6-LC4 salt-keyed token still accepted and converted to the own key', array( true, true, true ), array( Settings::monitor_token_matches( 'G5Token123456789012345' ), 0 === strpos( (string) Settings::get( 'monitor_token' ), Settings::MONITOR_HASH_KEYED ), Settings::monitor_token_matches( 'G5Token123456789012345' ) ) );
check( 'R6-LC4 hash depends on the own key, not on wp_salt()', Settings::MONITOR_HASH_KEYED . hash_hmac( 'sha256', 'x', get_option( 'wpac_monitor_key' ) . 'wpac_monitor' ), Settings::monitor_token_hash( 'x' ) );
check( 'R6-LC4 keyed hash still looks hashed to older versions (no double hashing after a downgrade)', 0, strpos( Settings::monitor_token_hash( 'x' ), Settings::MONITOR_HASH ) );
update_option( 'wpac_settings', $g5_settings );
Settings::flush_cache();

// R6-LC7: activation migrates under the lock (no parallel migration, no fatal).
update_option( 'wpac_db_version', 8 );
$g5_lock = Db::lock( Installer::LOCK, 900 );
Installer::activate_site();
check( 'R6-LC7 activation does not migrate while another request holds the lock', 8, (int) get_option( 'wpac_db_version' ) );
Db::unlock( Installer::LOCK, $g5_lock );
Installer::activate_site();
check( 'R6-LC7 activation migrates under the lock', Installer::db_version(), (int) get_option( 'wpac_db_version' ) );

// R6-LC8: a stale "migration aborted" entry is cleared once the schema is current and verified.
update_option( 'wpac_schema_problems', array( 'migration aborted: lock taken over' ) );
update_option( 'wpac_schema_problems_since', time() - 7200 );
Installer::maybe_upgrade();
check( 'R6-LC8 stale migration problem cleared', array( false, false ), array( get_option( 'wpac_schema_problems' ), get_option( 'wpac_schema_problems_since' ) ) );

// R5-W17: manual account link – never one's own account; other e-mail only for program managers.
$GLOBALS['test_users'] = array(
	1   => (object) array( 'ID' => 1, 'user_email' => 'admin@example.de', 'user_login' => 'admin' ),
	501 => (object) array( 'ID' => 501, 'user_email' => 'someone-else@example.de', 'user_login' => 'other' ),
	502 => (object) array( 'ID' => 502, 'user_email' => 'g5-link@example.de', 'user_login' => 'g5link' ),
);
$g5_link = AffiliateRepository::create( array( 'name' => 'G5 Link', 'email' => 'g5-link@example.de', 'status' => 'pending' ) );
$GLOBALS['test_caps'] = array( 'wpac_manage_affiliates' );
$g5_self  = AffiliateRepository::link_user( $g5_link, '1' );
$g5_other = AffiliateRepository::link_user( $g5_link, '501' );
check( 'R5-W17 own account and foreign e-mail refused for partner managers', array( 'wpac_link_self', 'wpac_link_email' ), array( is_wp_error( $g5_self ) ? $g5_self->get_error_code() : 'linked', is_wp_error( $g5_other ) ? $g5_other->get_error_code() : 'linked' ) );
$GLOBALS['test_mails'] = array();
$g5_ok = AffiliateRepository::link_user( $g5_link, '502' );
unset( $GLOBALS['test_caps'] );
check( 'R5-W17 matching account linked, partner and admin informed', array( true, 502, 2, 'g5-link@example.de' ), array( $g5_ok, (int) AffiliateRepository::find( $g5_link )->user_id, count( $GLOBALS['test_mails'] ), $GLOBALS['test_mails'][0][0] ?? '' ) );

// R6-MS5: a deleted WordPress user is disconnected from the partner.
AffiliateRepository::on_user_deleted( 502 );
AffiliateRepository::clear_cache( $g5_link );
check( 'R6-MS5 deleted user unlinked (partner can be linked again)', array( 0, 1 ), array( (int) AffiliateRepository::find( $g5_link )->user_id, $g5_audit( 'affiliate.user_unlinked', $g5_link ) ) );
$GLOBALS['test_users'] = array();

// R6-CC6: pending records survive a cache flush (options with their own expiry, not transients).
WPAffiliateCaptain\Core\Pending::set( 'app_' . hash( 'sha256', 'g5-token' ), array( 'email' => 'g5@example.de', 'user_id' => 0 ), 3600 );
foreach ( array_keys( $GLOBALS['test_options'] ) as $g5_k ) {
	if ( 0 === strpos( $g5_k, '_t_' ) ) {
		unset( $GLOBALS['test_options'][ $g5_k ] ); // "Redis flush": all transients gone.
	}
}
check( 'R6-CC6 pending application survives a transient flush', 'pending', WPAffiliateCaptain\Affiliates\Registration::token_state( 'g5-token' ) );
WPAffiliateCaptain\Core\Pending::set( 'g5_old', 1, -10 );
check( 'R6-CC6 expired records are not returned and purged', array( null, true ), array( WPAffiliateCaptain\Core\Pending::get( 'g5_old' ), WPAffiliateCaptain\Core\Pending::purge() >= 0 && false === get_option( 'wpac_pending_g5_old' ) ) );
WPAffiliateCaptain\Core\Pending::delete( 'app_' . hash( 'sha256', 'g5-token' ) );

// R5-W36: a copy of the site (other URL) switches maintenance mode on and sends no alarms.
update_option( WPAffiliateCaptain\Core\Lifecycle::FINGERPRINT, 'live-shop.example' );
$g5_maint = Settings::get( 'maintenance_mode' );
check( 'R5-W36 changed site URL detected, maintenance on, alarms paused', array( true, 1, true, 'example.test' ), array( WPAffiliateCaptain\Core\Lifecycle::check_clone(), (int) Settings::get( 'maintenance_mode' ), WPAffiliateCaptain\Core\Lifecycle::copy_paused(), get_option( WPAffiliateCaptain\Core\Lifecycle::FINGERPRINT ) ) );
check( 'R5-W36 same URL again: nothing happens', false, WPAffiliateCaptain\Core\Lifecycle::check_clone() );
Settings::update( array( 'maintenance_mode' => (int) $g5_maint ) );
check( 'R5-W36 switching maintenance off ends the pause', false, WPAffiliateCaptain\Core\Lifecycle::copy_paused() );

// R5-W37/R5-W56: unreadable bank details are counted without a dedicated key, named, and block payouts.
$wpdb->insert( t( 'payout_profiles' ), array( 'affiliate_id' => $g5_aff, 'tax_status' => 'private', 'legal_name' => 'G5', 'address' => 'Weg 1', 'country' => 'DE', 'account_holder' => 'G5', 'iban_encrypted' => 'wpac1:' . base64_encode( str_repeat( 'x', 60 ) ), 'updated_at' => Db::now() ) );
$g5_status = WPAffiliateCaptain\Core\Crypto::status();
check( 'R5-W37 unreadable bank details found without WPAC_ENCRYPTION_KEY', array( 1, array( $g5_aff ) ), array( $g5_status['broken'], $g5_status['broken_ids'] ) );
check( 'R5-W37 unreadable bank details count as missing (no payout with them)', 'iban', WPAffiliateCaptain\Payouts\ProfileRepository::missing( WPAffiliateCaptain\Payouts\ProfileRepository::get( $g5_aff ) ) );
WPAffiliateCaptain\Core\Monitor::check_migrations();
$g5_checks = WPAffiliateCaptain\Core\Monitor::checks();
check( 'R5-W56 Site Health names the partners with unreadable bank details', array( 'critical', true ), array( $g5_checks['bank_details'][0] ?? '', false !== strpos( $g5_checks['bank_details'][2] ?? '', (string) $g5_aff ) ) );
$wpdb->delete( t( 'payout_profiles' ), array( 'affiliate_id' => $g5_aff ) );
WPAffiliateCaptain\Core\Monitor::check_migrations();

// R5-W42: verify checks every account (not only 500).
$g5_values = array();
for ( $g5_i = 0; $g5_i < 620; $g5_i++ ) {
	$g5_values[] = '(' . ( 900000 + $g5_i ) . ", 'EUR', 0, 0, '', NOW())";
}
q( 'INSERT INTO ' . t( 'balances' ) . ' (affiliate_id, currency, balance_minor, entries, last_hash, updated_at) VALUES ' . implode( ',', $g5_values ) );
$g5_checked = 0;
$g5_problems = WPAffiliateCaptain\Commissions\Balance::verify_all( 0, $g5_checked );
$g5_total    = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'balances' ) );
check( 'R5-W42 verify checks all accounts', array( $g5_total, true ), array( $g5_checked, $g5_total > 620 ) );
q( 'DELETE FROM ' . t( 'balances' ) . ' WHERE affiliate_id >= 900000' );
check( 'R5-W42 one definition of "scheduled tasks run"', WPAffiliateCaptain\Core\Monitor::cron_running(), 'good' === WPAffiliateCaptain\Core\Monitor::checks()['cron'][0] );

// R5-W44: a failing step is logged with its key and shown by the health check until it succeeds.
$g5_run = new ReflectionMethod( WPAffiliateCaptain\Core\Cron::class, 'run_step' );
$g5_run->setAccessible( true );
for ( $g5_i = 0; $g5_i < 3; $g5_i++ ) {
	$g5_run->invoke( null, 'daily.balances', static function () { throw new RuntimeException( 'Saldenabgleich kaputt' ); } );
}
$g5_checks = WPAffiliateCaptain\Core\Monitor::checks();
check( 'R5-W44 failing step recorded with its key, critical after 3 runs', array( 3, 'critical', 'daily.balances' ), array( (int) WPAffiliateCaptain\Core\Cron::failures()['daily.balances']['count'], $g5_checks['cron_steps'][0] ?? '', (string) json_decode( (string) $wpdb->get_var( 'SELECT details FROM ' . t( 'audit' ) . " WHERE action = 'cron.step_failed' ORDER BY id DESC LIMIT 1" ), true )['step'] ) );
$g5_run->invoke( null, 'daily.balances', static function () {} );
check( 'R5-W44 success clears the failure', array( array(), false ), array( WPAffiliateCaptain\Core\Cron::failures(), isset( WPAffiliateCaptain\Core\Monitor::checks()['cron_steps'] ) ) );

// R5-O5: deactivation removes the re-encryption and terms reminder events too.
$GLOBALS['test_scheduled'][] = WPAffiliateCaptain\Core\Crypto::REENCRYPT_HOOK;
$GLOBALS['test_scheduled'][] = WPAffiliateCaptain\Affiliates\Agreements::REMIND_HOOK;
WPAffiliateCaptain\Core\Cron::unschedule();
check( 'R5-O5 unschedule removes wpac_reencrypt and wpac_terms_remind', array( false, false ), array( in_array( 'wpac_reencrypt', $GLOBALS['test_scheduled'], true ), in_array( 'wpac_terms_remind', $GLOBALS['test_scheduled'], true ) ) );
WPAffiliateCaptain\Core\Cron::schedule();

// Uninstall, reinstall and multisite in their own process.
$g5_prefix = getenv( 'WPAC_TEST_DB_PREFIX' );
putenv( 'WPAC_TEST_DB_PREFIX=g5u_' );
$g5_out = array();
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/r8-g5-isolated.php' ) . ' 2>&1', $g5_out );
putenv( false === $g5_prefix ? 'WPAC_TEST_DB_PREFIX' : 'WPAC_TEST_DB_PREFIX=' . $g5_prefix );
$g5_res = json_decode( (string) end( $g5_out ), true );
if ( ! is_array( $g5_res ) ) {
	check( 'R8-G5 isolated checks ran', 'json', implode( "\n", $g5_out ) );
} else {
	foreach ( $g5_res as $g5_name => $g5_pair ) {
		check( $g5_name, $g5_pair[0], $g5_pair[1] );
	}
}
