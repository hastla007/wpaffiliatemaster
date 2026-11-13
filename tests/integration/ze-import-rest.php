<?php
/**
 * Integration checks "ZE": CSV partner import, program export and the REST permission callbacks.
 * Included from tests/integration/run.php (uses its check() helper and database).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Core\Capabilities;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Migration\PartnerImport;
use WPAffiliateCaptain\Migration\ProgramExport;
use WPAffiliateCaptain\Rest\RestApi;

echo "ZE import, export, REST permissions\n";

// Minimal stand-ins (only what these checks need).
if ( ! function_exists( 'remove_filter' ) ) {
	function remove_filter( $tag, $cb, $prio = 10 ) {
		foreach ( (array) ( $GLOBALS['test_hooks'][ $tag ][ $prio ] ?? array() ) as $i => $h ) {
			if ( $h[0] === $cb ) {
				unset( $GLOBALS['test_hooks'][ $tag ][ $prio ][ $i ] );
				return true;
			}
		}
		return false;
	}
}
if ( ! function_exists( 'wp_tempnam' ) ) {
	function wp_tempnam( $name = '' ) { return tempnam( sys_get_temp_dir(), 'wpac' ); }
}
if ( ! function_exists( 'wp_lostpassword_url' ) ) {
	function wp_lostpassword_url( $redirect = '' ) { return 'http://example.test/wp-login.php?action=lostpassword'; }
}
if ( ! function_exists( 'wp_login_url' ) ) {
	function wp_login_url( $redirect = '' ) { return 'http://example.test/wp-login.php'; }
}
if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in() { return get_current_user_id() > 0; }
}
if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( $ns, $route, $args ) { $GLOBALS['test_routes'][ $ns . $route ] = $args; return true; }
}
if ( ! function_exists( 'rest_ensure_response' ) ) {
	function rest_ensure_response( $data ) { return $data instanceof WP_REST_Response ? $data : new WP_REST_Response( $data ); }
}
if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public $data; public $status; public $headers = array();
		public function __construct( $data = null, $status = 200 ) { $this->data = $data; $this->status = $status; }
		public function header( $k, $v ) { $this->headers[ $k ] = $v; }
		public function set_status( $s ) { $this->status = $s; }
	}
}
if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request implements ArrayAccess {
		private $params; private $headers;
		public function __construct( array $params = array(), array $headers = array() ) { $this->params = $params; $this->headers = array_change_key_case( $headers ); }
		public function get_header( $k ) { $k = strtolower( $k ); return $this->headers[ $k ] ?? null; }
		public function get_json_params() { return $this->params; }
		#[\ReturnTypeWillChange] public function offsetExists( $k ) { return isset( $this->params[ $k ] ); }
		#[\ReturnTypeWillChange] public function offsetGet( $k ) { return $this->params[ $k ] ?? null; }
		#[\ReturnTypeWillChange] public function offsetSet( $k, $v ) { $this->params[ $k ] = $v; }
		#[\ReturnTypeWillChange] public function offsetUnset( $k ) { unset( $this->params[ $k ] ); }
	}
}

global $wpdb;
$ze_aff_count = static function () use ( $wpdb ) { return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::table( 'affiliates' ) ); };

/* CSV import -------------------------------------------------------------------------------- */
$ze_existing = AffiliateRepository::create( array( 'name' => 'ZE Bestand', 'email' => 'ze-bestand@example.de', 'status' => 'active' ) );
$ze_taken    = CodeRepository::main_code( $ze_existing );
$ze_csv      = "name;email;website;status;codes;rate;rate_type;group\n"
	. "ZE Neu;ze-neu@example.de;ze-neu.example;active;ZEIMP1 ZEIMP2;12,5;;ZE Gruppe\n"
	. "Doppelt;ze-bestand@example.de;;active;;;;\n"
	. "Code vergeben;ze-code@example.de;;active;{$ze_taken};;;\n"
	. "Status falsch;ze-status@example.de;;sleeping;;;;\n"
	. "ZE Wartend;ze-wartend@example.de;;pending;;5,00 €;;ZE Gruppe\n"
	. "Doppelt in Datei;ZE-NEU@example.de;;active;;;;\n"
	. "ZE Pausiert;ze-pause@example.de;;inactive;ZEIMP3;;;\n";
$ze_before = $ze_aff_count();
$ze_dry    = PartnerImport::run( $ze_csv, array( 'dry_run' => true ) );
check( 'ZE01 dry run writes nothing', $ze_before, $ze_aff_count() );
check( 'ZE01 dry run: rows with errors are listed per line', array( 2 => 'ok', 3 => 'error', 4 => 'error', 5 => 'error', 6 => 'ok', 7 => 'error', 8 => 'ok' ), array_map( static function ( $r ) { return $r['result']; }, $ze_dry['rows'] ) );
check( 'ZE01 dry run: reasons (existing e-mail, code in use, status, duplicate in file)', array( true, true, true, true ), array(
	false !== strpos( implode( ' ', $ze_dry['rows'][3]['errors'] ), 'already exists' ),
	false !== strpos( implode( ' ', $ze_dry['rows'][4]['errors'] ), 'already in use' ),
	false !== strpos( implode( ' ', $ze_dry['rows'][5]['errors'] ), 'unknown status' ),
	false !== strpos( implode( ' ', $ze_dry['rows'][7]['errors'] ), 'line 2' ),
) );
check( 'ZE01 dry run: new group announced', true, (bool) preg_grep( '/new partner group/', $ze_dry['rows'][2]['notes'] ) );
$ze_hooks = count( $GLOBALS['test_hooks']['pre_wp_mail'][99] ?? array() );
$ze_run   = PartnerImport::run( $ze_csv, array( 'dry_run' => false ) );
check( 'ZE02 import: 3 created, 4 skipped', array( 3, 4, $ze_before + 3 ), array( $ze_run['created'], $ze_run['failed'], $ze_aff_count() ) );
check( 'ZE02 e-mail muting filter removed after the import', $ze_hooks, count( $GLOBALS['test_hooks']['pre_wp_mail'][99] ?? array() ) );
$ze_new  = AffiliateRepository::find_by_email( 'ze-neu@example.de' );
$ze_wait = AffiliateRepository::find_by_email( 'ze-wartend@example.de' );
$ze_pause = AffiliateRepository::find_by_email( 'ze-pause@example.de' );
check( 'ZE02 statuses (inactive → paused), website normalised', array( 'active', 'pending', 'paused', 'https://ze-neu.example' ), array( $ze_new->status, $ze_wait->status, $ze_pause->status, $ze_new->website ) );
check( 'ZE02 imported codes, first one is the main code (no random code added)', array( 'ZEIMP1', 2 ), array( CodeRepository::main_code( (int) $ze_new->id ), count( CodeRepository::for_affiliate( (int) $ze_new->id ) ) ) );
check( 'ZE02 pending partner without codes gets none until approval', 0, count( CodeRepository::for_affiliate( (int) $ze_wait->id ) ) );
check( 'ZE02 one group created and shared by both rows', array( 1, (int) $ze_new->group_id ), array( (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::table( 'partner_groups' ) . " WHERE name = 'ZE Gruppe'" ), (int) $ze_wait->group_id ) );
$ze_rules = $wpdb->get_results( $wpdb->prepare( 'SELECT scope_id, target, rate_type, rate FROM ' . Db::table( 'rules' ) . " WHERE scope = 'affiliate' AND scope_id IN (%d, %d) ORDER BY scope_id", $ze_new->id, $ze_wait->id ), ARRAY_A );
check( 'ZE02 partner rules: 12.5 % and 5,00 € fixed (minor units)', array( array( 'percent', '12.5' ), array( 'fixed', '500' ) ), array_map( static function ( $r ) { return array( $r['rate_type'], (string) (float) $r['rate'] === $r['rate'] ? $r['rate'] : rtrim( rtrim( $r['rate'], '0' ), '.' ) ); }, $ze_rules ) );
$ze_again = PartnerImport::run( $ze_csv, array( 'dry_run' => true ) );
check( 'ZE02 importing the same file again creates nothing new', 0, count( array_filter( $ze_again['rows'], static function ( $r ) { return ! $r['errors']; } ) ) );
$ze_limited = PartnerImport::run( "email;rate;group\nze-limit@example.de;10;Unbekannt\n", array( 'dry_run' => true, 'allow_program' => false ) );
check( 'ZE03 without wpac_manage_program: no rules, no new groups', 2, count( $ze_limited['rows'][2]['errors'] ) );
check( 'ZE03 admin page row limit', true, is_wp_error( PartnerImport::run( "email\na@example.de\nb@example.de\n", array( 'max_rows' => 1 ) ) ) );
check( 'ZE03 header without e-mail column refused', 'wpac_import_header', PartnerImport::run( "name\nA\n" )->get_error_code() );

/* Program export ------------------------------------------------------------------------------ */
$ze_zip    = tempnam( sys_get_temp_dir(), 'wpaczip' );
$ze_counts = ProgramExport::write( $ze_zip, false );
$ze_z      = new ZipArchive();
$ze_z->open( $ze_zip );
check( 'ZE04 export: one CSV per list, no bank data without request', array( false, true, true ), array( isset( $ze_counts['payout_details.csv'] ), false !== $ze_z->locateName( 'partners.csv' ), false !== $ze_z->locateName( 'README.txt' ) ) );
check( 'ZE04 export: partners.csv contains the imported partner', true, false !== strpos( (string) $ze_z->getFromName( 'partners.csv' ), 'ze-neu@example.de' ) );
check( 'ZE04 export: row count = table size', (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::table( 'commissions' ) ), $ze_counts['commissions.csv'] );
$ze_payouts = (string) $ze_z->getFromName( 'payouts.csv' );
check( 'ZE04 export: tax snapshot of payouts removed, no IBAN anywhere', array( false, false ), array( false !== strpos( $ze_payouts, 'legal_name' ), false !== strpos( $ze_payouts . $ze_z->getFromName( 'partners.csv' ), 'DE89370400440532013000' ) ) );
$ze_z->close();
$ze_counts = ProgramExport::write( $ze_zip, true );
$ze_z->open( $ze_zip );
$ze_details = (string) $ze_z->getFromName( 'payout_details.csv' );
check( 'ZE04 export with tax data: decrypted IBAN, no ciphertext column', array( true, false ), array( false !== strpos( $ze_details, 'DE89370400440532013000' ), false !== strpos( $ze_details, 'iban_encrypted' ) ) );
$ze_z->close();
@unlink( $ze_zip );

/* REST permission callbacks ---------------------------------------------------------------- */
$GLOBALS['test_routes'] = array();
RestApi::routes();
$ze_routes = $GLOBALS['test_routes'];
$ze_missing = array();
$ze_public  = array();
foreach ( $ze_routes as $route => $args ) {
	foreach ( isset( $args['methods'] ) ? array( $args ) : $args as $endpoint ) {
		if ( empty( $endpoint['permission_callback'] ) || ( '__return_true' !== $endpoint['permission_callback'] && ! is_callable( $endpoint['permission_callback'] ) ) ) {
			$ze_missing[] = $route;
		} elseif ( '__return_true' === $endpoint['permission_callback'] ) {
			$ze_public[] = $route;
		}
	}
}
check( 'ZE05 every REST route has a permission_callback', array(), $ze_missing );
// A new public route must be added here on purpose (and checked for enumeration/rate limits).
check( 'ZE05 public routes are exactly health (token check in the callback), contract, touch, code check', array( 'wpac/v1/health', 'wpac/v1/contract', 'wpac/v1/touch', 'wpac/v1/codes/(?P<code>[A-Za-z0-9_\-]{3,64})' ), $ze_public );
$ze_allowed = static function ( $route, $user, $caps ) use ( $ze_routes ) {
	$GLOBALS['test_user'] = $user;
	$GLOBALS['test_caps'] = $caps;
	$r = (bool) call_user_func( $ze_routes[ 'wpac/v1' . $route ]['permission_callback'] );
	unset( $GLOBALS['test_caps'] );
	$GLOBALS['test_user'] = 1;
	return $r;
};
$ze_admin_routes   = array( '/sales', '/refunds', '/affiliates', '/affiliates/(?P<id>\d+)', '/commissions', '/reports/(?P<view>partners|content|campaign)' );
$ze_partner_routes = array( '/me', '/me/commissions', '/me/payouts' );
check( 'ZE05 all protected routes are covered by these checks', count( $ze_routes ) - count( $ze_public ), count( $ze_admin_routes ) + count( $ze_partner_routes ) );
$ze_partner_user = 9001;
AffiliateRepository::create( array( 'name' => 'ZE REST Partner', 'email' => 'ze-rest@example.de', 'status' => 'active', 'user_id' => $ze_partner_user ) );
$ze_matrix = array();
foreach ( array_merge( $ze_admin_routes, $ze_partner_routes ) as $route ) {
	$ze_matrix[ $route ] = array(
		'anonymous' => $ze_allowed( $route, 0, array() ),
		'customer'  => $ze_allowed( $route, 9000, array() ),
		'partner'   => $ze_allowed( $route, $ze_partner_user, array() ),
		'admin'     => $ze_allowed( $route, 1, Capabilities::all() ),
	);
}
$ze_expect = array();
foreach ( $ze_admin_routes as $route ) {
	$ze_expect[ $route ] = array( 'anonymous' => false, 'customer' => false, 'partner' => false, 'admin' => true );
}
foreach ( $ze_partner_routes as $route ) {
	$ze_expect[ $route ] = array( 'anonymous' => false, 'customer' => false, 'partner' => true, 'admin' => false );
}
check( 'ZE06 admin routes: only with capability; partner routes: only the logged-in partner', $ze_expect, $ze_matrix );
check( 'ZE06 sales/refunds need wpac_manage_program, not only wpac_manage_affiliates', array( false, false, true ), array( $ze_allowed( '/sales', 1, array( Capabilities::MANAGE_AFFILIATES ) ), $ze_allowed( '/refunds', 1, array( Capabilities::MANAGE_AFFILIATES, Capabilities::MANAGE_PAYOUTS ) ), $ze_allowed( '/affiliates', 1, array( Capabilities::MANAGE_AFFILIATES ) ) ) );
$ze_rest_aff = AffiliateRepository::find_by_user( $ze_partner_user );
$ze_states   = array();
foreach ( array( 'paused' => 'Pause', 'terminated' => 'Ende' ) as $ze_status => $ze_reason ) {
	AffiliateRepository::set_status( (int) $ze_rest_aff->id, $ze_status, $ze_reason );
	$ze_states[ $ze_status ] = $ze_allowed( '/me', $ze_partner_user, array() );
}
check( 'ZE06 paused partner keeps read access, terminated partner loses it', array( 'paused' => true, 'terminated' => false ), $ze_states );
// Health: token or capability, checked in the callback.
update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), array( 'monitor_token' => 'ze-monitor-token-0123456789abcdef' ) ) );
Settings::flush_cache();
$ze_health = static function ( $token, $caps ) {
	$GLOBALS['test_caps'] = $caps;
	$GLOBALS['test_user'] = $caps ? 1 : 0;
	$r = RestApi::health( new WP_REST_Request( array(), null === $token ? array() : array( 'X-WPAC-Monitor-Token' => $token ) ) );
	unset( $GLOBALS['test_caps'] );
	$GLOBALS['test_user'] = 1;
	return is_wp_error( $r ) ? $r->get_error_code() : 'ok';
};
check( 'ZE07 health: anonymous and wrong token refused, right token or capability allowed', array( 'wpac_forbidden', 'wpac_forbidden', 'ok', 'ok' ), array( $ze_health( null, array() ), $ze_health( 'wrong-token-0123456789abcdef', array() ), $ze_health( 'ze-monitor-token-0123456789abcdef', array() ), $ze_health( null, array( Capabilities::MANAGE_PROGRAM ) ) ) );
update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), array( 'monitor_token' => '' ) ) );
Settings::flush_cache();
