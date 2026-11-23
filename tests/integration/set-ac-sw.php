<?php
/**
 * SET-AC-26 (switch per feature) and SET-AC-28 (log level in the settings). Logic checks; the
 * rendered pages are checked in set-ac-sw-render.php. Included from run.php before r8-render.php.
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Affiliates\Notifications;
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Commissions\Tiers;
use WPAffiliateCaptain\Commissions\TierService;
use WPAffiliateCaptain\Core\Features;
use WPAffiliateCaptain\Core\Log;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Core\SettingsSchema;
use WPAffiliateCaptain\Core\SettingsTransfer;
use WPAffiliateCaptain\Portal\Portal;
use WPAffiliateCaptain\Promotion\Landing;

echo "SET-AC-26/28 switches per feature, log level\n";

global $wpdb;
$sw_saved = get_option( 'wpac_settings' );
SettingsSchema::flush();
Settings::flush_cache();
$sw_set = static function ( array $values ) {
	Settings::update( $values );
	Settings::flush_cache();
};

/* SET-AC-26: register ------------------------------------------------------------------------ */
$sw_defaults = SettingsSchema::defaults();
$sw_keys     = array_values( Features::SETTINGS );
check( 'SET-AC-26 five switches, bool on tab "program", default on (existing sites unchanged)', array( 1, 1, 1, 1, 1 ), array_map( static function ( $k ) use ( $sw_defaults ) { return $sw_defaults[ $k ]; }, $sw_keys ) );
check( 'SET-AC-26 register: type bool, tab program, label and help', array( true, true, true, true, true ), array_map( static function ( $k ) { $f = SettingsSchema::field( $k ); return 'bool' === $f['type'] && 'program' === $f['tab'] && '' !== $f['label'] && '' !== $f['help']; }, $sw_keys ) );
check( 'SET-AC-26 all features on by default; unknown features are always on', array( true, true, true, true, true, true ), array( Features::enabled( 'creatives' ), Features::enabled( 'landing' ), Features::enabled( 'tiers' ), Features::enabled( 'qr' ), Features::enabled( 'broadcast' ), Features::enabled( 'unknown' ) ) );
$sw_export = SettingsTransfer::export();
check( 'SET-AC-26 switches are exported and part of the reset of the tab "program"', array( true, true ), array( ! array_diff( $sw_keys, array_keys( $sw_export['settings'] ) ), ! array_diff( $sw_keys, SettingsTransfer::reset_keys( 'program' ) ) ) );
$sw_check = SettingsTransfer::check( array( 'feature_qr' => 'off', 'feature_tiers' => 'maybe' ), Settings::all() );
check( 'SET-AC-26 import/CLI words: "off" switches off, unknown words are rejected', array( array( 'feature_qr' => 0 ), true ), array( $sw_check['values'], isset( $sw_check['errors']['feature_tiers'] ) ) );
// Saving another tab keeps a switched-off feature off (fields of other tabs are not posted).
$sw_set( array( 'feature_qr' => 0 ) );
check( 'SET-AC-26 a switched-off feature stays off when other fields are saved', array( array(), 0 ), array( SettingsTransfer::check( array( 'hold_days' => 21 ), Settings::all() )['errors'], (int) Settings::get( 'feature_qr' ) ) );
check( 'SET-AC-26 filter wpac_feature_enabled wins (both directions)', array( false, true ), array( Features::enabled( 'qr' ), ( function () { add_filter( 'wpac_feature_enabled', static function ( $on, $f ) { return 'qr' === $f ? true : $on; }, 10, 2 ); $r = Features::enabled( 'qr' ); remove_all_filters( 'wpac_feature_enabled' ); return $r; } )() ) );
add_filter( 'wpac_feature_enabled', static function () { return false; } );
$sw_filtered_off = Features::enabled( 'creatives' );
remove_all_filters( 'wpac_feature_enabled' );
check( 'SET-AC-26 filter can switch a feature off although the setting is on', false, $sw_filtered_off );
$sw_set( array( 'feature_qr' => 1 ) );

/* Creatives ----------------------------------------------------------------------------------- */
$sw_cr_before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'creatives' ) );
$sw_set( array( 'feature_creatives' => 0 ) );
$sw_cr_actions = array( Features::action_allowed( 'creative_save' ), Features::action_allowed( 'creative_approve' ), Features::action_allowed( 'creative_archive' ), Features::action_allowed( 'rule_save' ) );
$sw_cr_off     = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'creatives' ) );
$sw_set( array( 'feature_creatives' => 1 ) );
check( 'SET-AC-26 creatives off: admin actions refused (other actions not), creatives kept', array( false, false, false, true, $sw_cr_before, true ), array_merge( $sw_cr_actions, array( $sw_cr_off, Features::action_allowed( 'creative_save' ) ) ) );

/* Landing pages ------------------------------------------------------------------------------- */
$sw_aff  = AffiliateRepository::create( array( 'name' => 'SW Landing', 'email' => 'sw-landing@example.de', 'status' => 'active' ) );
$sw_row  = AffiliateRepository::find( $sw_aff );
$sw_slug = (string) $sw_row->slug;
$sw_on   = Landing::redirect_url( $sw_slug, array(), false );
$sw_set( array( 'feature_landing' => 0 ) );
$sw_off      = Landing::redirect_url( $sw_slug, array(), true );
$sw_fallback = Landing::fallback_to_page( array( Landing::QUERY_VAR => $sw_slug ) );
$sw_set( array( 'feature_landing' => 1 ) );
check( 'SET-AC-26 landing off: short link no longer redirects (resolved as an ordinary page address), on again: redirects', array( true, null, array( 'pagename' => Settings::get( 'landing_prefix' ) . '/' . $sw_slug ), true ), array( is_string( $sw_on ) && '' !== $sw_on, $sw_off, $sw_fallback, is_string( Landing::redirect_url( $sw_slug, array(), false ) ) ) );
check( 'SET-AC-26 landing on: an active partner\'s short link is kept for the request', array( Landing::QUERY_VAR => $sw_slug ), Landing::fallback_to_page( array( Landing::QUERY_VAR => $sw_slug ) ) );

/* Monthly tiers ------------------------------------------------------------------------------- */
$sw_tm   = '2023-08';
$sw_taff = AffiliateRepository::create( array( 'name' => 'SW Tier', 'email' => 'sw-tier@example.de', 'status' => 'active' ) );
$sw_r    = sale( 'sw-tier-1', CodeRepository::main_code( $sw_taff ), array( 200000 ) );
q( 'UPDATE ' . t( 'commissions' ) . " SET created_at = '{$sw_tm}-10 10:00:00' WHERE id = " . (int) $sw_r['commission_ids'][0] );
Ledger::transition( (int) $sw_r['commission_ids'][0], 'approved' );
$sw_plan   = TierService::create( array( 'scope' => 'affiliate', 'scope_id' => $sw_taff, 'basis' => 'revenue', 'mode' => 'retroactive', 'steps' => Tiers::parse_steps( '1000:2', 'revenue', 'EUR' ), 'note' => 'SW' ), 'test' );
$sw_bonus  = static function () use ( $wpdb, $sw_taff ) {
	return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'commissions' ) . " WHERE affiliate_id = {$sw_taff} AND kind = 'bonus'" );
};
$sw_closed = Settings::state( 'tier_month_closed' );
$sw_set( array( 'feature_tiers' => 0 ) );
$sw_t_off = array( TierService::close_month( $sw_tm ), TierService::close_due_months(), TierService::recalculate_closed_months( 0 ), TierService::recalculate_month( $sw_tm ), $sw_bonus(), Settings::state( 'tier_month_closed' ), count( TierService::all() ) > 0, Features::action_allowed( 'tier_create' ), Features::action_allowed( 'tier_close_month' ), isset( Portal::conditions( AffiliateRepository::find( $sw_taff ) )['Monthly bonus'] ), Portal::tier_progress( AffiliateRepository::find( $sw_taff ) ) );
check( 'SET-AC-26 tiers off: no bonus booked or recalculated, closed month unchanged, plans kept, actions refused, no bonus in the partner area', array( 0, true, 0, 0, 0, $sw_closed, true, false, false, false, '' ), $sw_t_off );
$sw_set( array( 'feature_tiers' => 1 ) );
check( 'SET-AC-26 tiers on again: the month is booked, the partner sees the bonus plan', array( 1, 1, true ), array( TierService::close_month( $sw_tm ), $sw_bonus(), isset( Portal::conditions( AffiliateRepository::find( $sw_taff ) )['Monthly bonus'] ) ) );
$sw_set( array( 'feature_tiers' => 0 ) );
$sw_bonus_row = (array) $wpdb->get_row( 'SELECT amount_minor, status FROM ' . t( 'commissions' ) . " WHERE affiliate_id = {$sw_taff} AND kind = 'bonus'" );
TierService::recalculate_month( $sw_tm );
check( 'SET-AC-26 tiers off: a booked bonus stays unchanged', array( $sw_bonus_row, 1 ), array( (array) $wpdb->get_row( 'SELECT amount_minor, status FROM ' . t( 'commissions' ) . " WHERE affiliate_id = {$sw_taff} AND kind = 'bonus'" ), $sw_bonus() ) );
$sw_set( array( 'feature_tiers' => 1 ) );
TierService::deactivate( $sw_plan, 'test done' );
if ( '' !== $sw_closed ) {
	Settings::set_state( 'tier_month_closed', $sw_closed );
}

/* QR codes ------------------------------------------------------------------------------------ */
$sw_set( array( 'feature_qr' => 0 ) );
$sw_qr_off = Features::enabled( 'qr' );
$sw_set( array( 'feature_qr' => 1 ) );
check( 'SET-AC-26 QR off/on (partner area checked in set-ac-sw-render.php)', array( false, true ), array( $sw_qr_off, Features::enabled( 'qr' ) ) );

/* Broadcast mail ------------------------------------------------------------------------------ */
$sw_b1 = AffiliateRepository::create( array( 'name' => 'SW Broadcast 1', 'email' => 'sw-b1@example.de', 'status' => 'active' ) );
$sw_b2 = AffiliateRepository::create( array( 'name' => 'SW Broadcast 2', 'email' => 'sw-b2@example.de', 'status' => 'active' ) );
Notifications::set_opt_out( $sw_b1, 'broadcast', true );
$sw_state_before = get_option( Notifications::BROADCAST_OPTION );
$sw_set( array( 'feature_broadcast' => 0 ) );
$sw_start = Notifications::start_broadcast( 'News', 'Text', 0 );
check( 'SET-AC-26 broadcast off: start refused quietly, state unchanged, admin action refused', array( 'wpac_feature_off', $sw_state_before, false ), array( is_wp_error( $sw_start ) ? $sw_start->get_error_code() : $sw_start, get_option( Notifications::BROADCAST_OPTION ), Features::action_allowed( 'broadcast_send' ) ) );
// Saving the mail preferences while the choice is hidden keeps both stored choices.
Portal::save_mail_preferences( $sw_b1, array( 'commission', 'monthly_partner' ) );
Portal::save_mail_preferences( $sw_b2, array() );
check( 'SET-AC-26 broadcast off: saved mail preferences keep the stored broadcast choice (opted out / subscribed)', array( true, false, true ), array( Notifications::opted_out( $sw_b1, 'broadcast' ), Notifications::opted_out( $sw_b2, 'broadcast' ), Notifications::opted_out( $sw_b2, 'commission' ) ) );
// A running broadcast pauses while off and continues after switching on.
$sw_running = array( 'subject' => 'S', 'message' => 'M', 'group' => 0, 'cursor' => 0, 'sent' => 0, 'skipped' => 0, 'total' => 2, 'started' => time(), 'done' => 0 );
update_option( Notifications::BROADCAST_OPTION, $sw_running, false );
$GLOBALS['test_mails']     = array();
$GLOBALS['test_scheduled'] = array();
Notifications::broadcast_batch( 1 );
Notifications::resume_broadcast();
check( 'SET-AC-26 broadcast off: a running broadcast pauses (no mail, state kept, not rescheduled)', array( 0, $sw_running, false ), array( count( $GLOBALS['test_mails'] ), get_option( Notifications::BROADCAST_OPTION ), in_array( Notifications::BROADCAST_HOOK, $GLOBALS['test_scheduled'], true ) ) );
$sw_set( array( 'feature_broadcast' => 1 ) );
Notifications::resume_broadcast();
check( 'SET-AC-26 broadcast on again: the hourly run continues the paused broadcast', true, in_array( Notifications::BROADCAST_HOOK, $GLOBALS['test_scheduled'], true ) );
Notifications::broadcast_batch( 1 );
check( 'SET-AC-26 broadcast on again: the next batch is sent', true, (int) get_option( Notifications::BROADCAST_OPTION )['cursor'] > 0 );
Portal::save_mail_preferences( $sw_b1, array( 'commission', 'monthly_partner' ) );
check( 'SET-AC-26 broadcast on: the choice is saved again as usual', true, Notifications::opted_out( $sw_b1, 'broadcast' ) );
if ( false === $sw_state_before ) {
	delete_option( Notifications::BROADCAST_OPTION );
} else {
	update_option( Notifications::BROADCAST_OPTION, $sw_state_before, false );
}
$GLOBALS['test_mails']     = array();
$GLOBALS['test_scheduled'] = array();

/* SET-AC-28: log level ------------------------------------------------------------------------ */
$sw_f = SettingsSchema::field( 'log_level' );
check( 'SET-AC-28 register: choice errors/notices/verbose on tab "data", default notices (as before)', array( 'choice', 'data', array( 'errors', 'notices', 'verbose' ), 'notices', 'notices' ), array( $sw_f['type'], $sw_f['tab'], $sw_f['options'], $sw_f['default'], Log::level() ) );
$sw_log = static function ( callable $fn ) {
	$file = tempnam( sys_get_temp_dir(), 'swlog' );
	$prev = ini_set( 'error_log', $file );
	$fn();
	ini_set( 'error_log', (string) $prev );
	$out = (string) file_get_contents( $file );
	@unlink( $file );
	return array( false !== strpos( $out, 'error: sw-e' ), false !== strpos( $out, 'warning: sw-w' ), false !== strpos( $out, 'debug: sw-d' ) );
};
$sw_write = static function () {
	Log::error( 'sw-e' );
	Log::warning( 'sw-w' );
	Log::debug( 'sw-d' );
};
$sw_notices = $sw_log( $sw_write );
$sw_set( array( 'log_level' => 'errors' ) );
$sw_errors = $sw_log( $sw_write );
check( 'SET-AC-28 notices: errors and warnings; errors: only errors (no expiry stored)', array( array( true, true, false ), array( true, false, false ), 0 ), array( $sw_notices, $sw_errors, Log::verbose_until() ) );
$sw_t0 = time();
$sw_set( array( 'log_level' => 'verbose' ) );
$sw_until   = Log::verbose_until();
$sw_verbose = $sw_log( $sw_write );
check( 'SET-AC-28 verbose: debug entries too, ends after 7 days (expiry stored)', array( array( true, true, true ), 'verbose', true ), array( $sw_verbose, Log::level(), $sw_until >= $sw_t0 + 7 * DAY_IN_SECONDS && $sw_until <= time() + 7 * DAY_IN_SECONDS ) );
update_option( Log::VERBOSE_UNTIL, $sw_until - DAY_IN_SECONDS, false );
$sw_set( array( 'log_level' => 'verbose' ) );
check( 'SET-AC-28 saving verbose again keeps the running end (no silent extension)', $sw_until - DAY_IN_SECONDS, Log::verbose_until() );
check( 'SET-AC-28 daily run while verbose is still running changes nothing', array( false, 'verbose' ), array( Log::expire_verbose(), (string) Settings::get( 'log_level' ) ) );
update_option( Log::VERBOSE_UNTIL, time() - 1, false );
$sw_expired_level = Log::level();
$sw_expired_log   = $sw_log( $sw_write );
check( 'SET-AC-28 after the end: the default applies at once (before the daily run)', array( 'notices', array( true, true, false ) ), array( $sw_expired_level, $sw_expired_log ) );
$sw_audit_before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'audit' ) . " WHERE action = 'settings.log_level_expired'" );
$sw_expire       = Log::expire_verbose();
Settings::flush_cache();
check( 'SET-AC-28 daily run sets an ended verbose back to the default (audit entry, expiry removed)', array( true, 'notices', 0, $sw_audit_before + 1, true ), array( $sw_expire, (string) Settings::get( 'log_level' ), Log::verbose_until(), (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'audit' ) . " WHERE action = 'settings.log_level_expired'" ), isset( WPAffiliateCaptain\Core\Cron::daily_steps()['log_level'] ) ) );
// Import / WP-CLI path: the same check and the same expiry.
$sw_chk = SettingsTransfer::check( array( 'log_level' => 'verbose' ), Settings::all() );
SettingsTransfer::apply( $sw_chk, 'settings.cli' );
Settings::flush_cache();
check( 'SET-AC-28 import/CLI: verbose gets its 7-day end too; unknown level rejected', array( 'verbose', true, true ), array( (string) Settings::get( 'log_level' ), Log::verbose_until() > time() + 6 * DAY_IN_SECONDS, isset( SettingsTransfer::check( array( 'log_level' => 'loud' ), Settings::all() )['errors']['log_level'] ) ) );
check( 'SET-AC-28 log_level is exported, the expiry is not a setting', array( true, false ), array( array_key_exists( 'log_level', SettingsTransfer::export()['settings'] ), array_key_exists( 'log_verbose_until', SettingsTransfer::export()['settings'] ) ) );
// The filters win.
$sw_set( array( 'log_level' => 'errors' ) );
add_filter( 'wpac_log_level', static function () { return 'verbose'; } );
$sw_flt1 = $sw_log( $sw_write );
remove_all_filters( 'wpac_log_level' );
add_filter( 'wpac_log_level', static function () { return 'nonsense'; } );
$sw_flt2 = Log::level();
remove_all_filters( 'wpac_log_level' );
add_filter( 'wpac_log_debug', static function () { return true; } );
$sw_flt3 = $sw_log( $sw_write );
remove_all_filters( 'wpac_log_debug' );
$sw_set( array( 'log_level' => 'verbose' ) );
add_filter( 'wpac_log_debug', static function () { return false; } );
$sw_flt4 = $sw_log( $sw_write );
remove_all_filters( 'wpac_log_debug' );
check( 'SET-AC-28 filter wpac_log_level wins (unknown values ignored); wpac_log_debug wins in both directions', array( array( true, true, true ), 'errors', array( true, false, true ), array( true, true, false ) ), array( $sw_flt1, $sw_flt2, $sw_flt3, $sw_flt4 ) );
check( 'SET-AC-28 switching away from verbose removes the expiry; uninstall removes the option', array( 0, true ), array( ( function () use ( $sw_set ) { $sw_set( array( 'log_level' => 'notices' ) ); return Log::verbose_until(); } )(), false !== strpos( (string) file_get_contents( dirname( __DIR__, 2 ) . '/uninstall.php' ), "'wpac_log_verbose_until'" ) ) );

// WP_DEBUG wins over the setting: checked in its own process (a constant cannot be undefined).
$sw_script = tempnam( sys_get_temp_dir(), 'swdbg' ) . '.php';
file_put_contents(
	$sw_script,
	'<?php
namespace WPAffiliateCaptain\Core { final class Settings { public static function get( $k ) { return "errors"; } } }
namespace {
define( "ABSPATH", "/" ); define( "WP_DEBUG", true ); define( "DAY_IN_SECONDS", 86400 );
function get_option( $k, $d = false ) { return $d; }
function apply_filters( $t, $v ) { return $v; }
require ' . var_export( dirname( __DIR__, 2 ) . '/src/Core/Log.php', true ) . ';
echo \WPAffiliateCaptain\Core\Log::level();
}'
);
$sw_dbg = (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $sw_script ) . ' 2>&1' );
@unlink( $sw_script );
check( 'SET-AC-28 WP_DEBUG wins over the setting "errors" (everything is written)', 'verbose', trim( $sw_dbg ) );

update_option( 'wpac_settings', $sw_saved );
delete_option( Log::VERBOSE_UNTIL );
Settings::flush_cache();
SettingsSchema::flush();
