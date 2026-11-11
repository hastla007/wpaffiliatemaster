<?php
/**
 * Minimal WordPress runtime for integration tests against a real MySQL/MariaDB.
 * Only what the plugin's ledger/payout code paths need – not a WordPress replacement.
 *
 * Env: WPAC_TEST_DB_HOST, WPAC_TEST_DB_NAME, WPAC_TEST_DB_USER, WPAC_TEST_DB_PASS.
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
define( 'OBJECT', 'OBJECT' );
define( 'OBJECT_K', 'OBJECT_K' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WPAC_VERSION', 'test' );
define( 'WPAC_DB_VERSION', 6 );
define( 'WPAC_CONTRACT_VERSION', '1.1.0' );
define( 'WPAC_FILE', dirname( __DIR__, 2 ) . '/wp-affiliate-captain.php' );
define( 'WPAC_DIR', dirname( __DIR__, 2 ) . '/' );
define( 'WPAC_URL', 'http://example.test/wp-content/plugins/wp-affiliate-captain/' );

class Test_WPDB {
	public $prefix = 'wp_';
	public $insert_id = 0;
	public $last_error = '';
	public $suppress   = false;
	public function suppress_errors( $s = true ) { $old = $this->suppress; $this->suppress = (bool) $s; return $old; }
	/** @var mysqli */
	public $db;

	public function __construct() {
		mysqli_report( MYSQLI_REPORT_OFF );
		$this->db = new mysqli(
			getenv( 'WPAC_TEST_DB_HOST' ) ?: 'localhost',
			getenv( 'WPAC_TEST_DB_USER' ) ?: 'wpac',
			getenv( 'WPAC_TEST_DB_PASS' ) ?: 'wpac',
			getenv( 'WPAC_TEST_DB_NAME' ) ?: 'wpac_test'
		);
		if ( $this->db->connect_error ) {
			fwrite( STDERR, 'DB connection failed: ' . $this->db->connect_error . "\n" );
			exit( 2 );
		}
		$this->db->set_charset( 'utf8mb4' );
	}
	public function get_charset_collate() { return 'DEFAULT CHARSET=utf8mb4'; }
	public function esc_like( $t ) { return addcslashes( $t, '_%\\' ); }
	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$i = 0;
		return preg_replace_callback(
			'/%[dsf]/',
			function ( $m ) use ( &$i, $args ) {
				$v = $args[ $i++ ];
				if ( '%d' === $m[0] ) { return (string) (int) $v; }
				if ( '%f' === $m[0] ) { return (string) (float) $v; }
				return "'" . $this->db->real_escape_string( (string) $v ) . "'";
			},
			$query
		);
	}
	public function query( $sql ) {
		$r = $this->db->query( $sql );
		$this->last_error = $this->db->error;
		if ( false === $r ) {
			return false;
		}
		if ( $r instanceof mysqli_result ) {
			$n = $r->num_rows;
			$r->free();
			return $n;
		}
		$this->insert_id = (int) $this->db->insert_id;
		return $this->db->affected_rows;
	}
	public function get_results( $sql, $output = OBJECT ) {
		$r = $this->db->query( $sql );
		$this->last_error = $this->db->error;
		if ( ! $r instanceof mysqli_result ) {
			if ( $this->db->error ) { throw new RuntimeException( $this->db->error . ' in ' . $sql ); }
			return array();
		}
		$out = array();
		while ( $row = $r->fetch_assoc() ) {
			if ( ARRAY_A === $output ) {
				$out[] = $row;
			} elseif ( OBJECT_K === $output ) {
				$out[ reset( $row ) ] = (object) $row;
			} else {
				$out[] = (object) $row;
			}
		}
		return $out;
	}
	public function get_row( $sql, $output = OBJECT ) {
		$rows = $this->get_results( $sql, $output );
		return $rows ? reset( $rows ) : null;
	}
	public function get_var( $sql ) {
		$row = $this->get_row( $sql, ARRAY_A );
		return $row ? reset( $row ) : null;
	}
	public function get_col( $sql ) {
		return array_map( function ( $r ) { return reset( $r ); }, $this->get_results( $sql, ARRAY_A ) );
	}
	private function where( array $where ) {
		$parts = array();
		foreach ( $where as $k => $v ) {
			$parts[] = "`$k` = " . ( null === $v ? 'NULL' : "'" . $this->db->real_escape_string( (string) $v ) . "'" );
		}
		return implode( ' AND ', $parts );
	}
	private function values( array $data ) {
		$parts = array();
		foreach ( $data as $k => $v ) {
			$parts[] = "`$k` = " . ( null === $v ? 'NULL' : "'" . $this->db->real_escape_string( (string) $v ) . "'" );
		}
		return implode( ', ', $parts );
	}
	public function insert( $table, $data, $format = null ) {
		$r = $this->query( "INSERT INTO `$table` SET " . $this->values( $data ) );
		return false === $r ? false : $r;
	}
	public function update( $table, $data, $where, $format = null, $wf = null ) {
		return $this->query( "UPDATE `$table` SET " . $this->values( $data ) . ' WHERE ' . $this->where( $where ) );
	}
	public function delete( $table, $where, $wf = null ) {
		return $this->query( "DELETE FROM `$table` WHERE " . $this->where( $where ) );
	}
}

$GLOBALS['wpdb']        = new Test_WPDB();
$GLOBALS['test_hooks']  = array();
$GLOBALS['test_fired']  = array();
$GLOBALS['test_options'] = array();
$GLOBALS['test_user']   = 1;
$GLOBALS['test_mails']  = array();

function add_filter( $tag, $cb, $prio = 10, $args = 1 ) { $GLOBALS['test_hooks'][ $tag ][ $prio ][] = array( $cb, $args ); return true; }
function add_action( $tag, $cb, $prio = 10, $args = 1 ) { return add_filter( $tag, $cb, $prio, $args ); }
function apply_filters( $tag, $value, ...$rest ) {
	if ( empty( $GLOBALS['test_hooks'][ $tag ] ) ) { return $value; }
	ksort( $GLOBALS['test_hooks'][ $tag ] );
	foreach ( $GLOBALS['test_hooks'][ $tag ] as $cbs ) {
		foreach ( $cbs as $cb ) {
			$value = call_user_func_array( $cb[0], array_slice( array_merge( array( $value ), $rest ), 0, max( 1, $cb[1] ) ) );
		}
	}
	return $value;
}
function do_action( $tag, ...$args ) {
	$GLOBALS['test_fired'][] = array( $tag, $args, $GLOBALS['wpdb']->db->query( 'SELECT @@in_transaction' ) ? (int) $GLOBALS['wpdb']->db->query( 'SELECT @@in_transaction AS t' )->fetch_assoc()['t'] : 0 );
	if ( empty( $GLOBALS['test_hooks'][ $tag ] ) ) { return; }
	ksort( $GLOBALS['test_hooks'][ $tag ] );
	foreach ( $GLOBALS['test_hooks'][ $tag ] as $cbs ) {
		foreach ( $cbs as $cb ) {
			call_user_func_array( $cb[0], array_slice( $args, 0, $cb[1] ) );
		}
	}
}
function remove_all_filters( $tag ) { unset( $GLOBALS['test_hooks'][ $tag ] ); }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['test_options'] ) ? $GLOBALS['test_options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['test_options'][ $k ] = $v; return true; }
function add_option( $k, $v, $x = '', $a = null ) { $GLOBALS['test_options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['test_options'][ $k ] ); return true; }
function get_transient( $k ) { return get_option( '_t_' . $k ); }
function set_transient( $k, $v, $e = 0 ) { return update_option( '_t_' . $k, $v ); }
function __( $t, $d = null ) { return $t; }
function esc_html__( $t, $d = null ) { return $t; }
function _n( $s, $p, $n, $d = null ) { return 1 === $n ? $s : $p; }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function esc_attr( $t ) { return esc_html( $t ); }
function wp_json_encode( $d, $o = 0 ) { return json_encode( $d, $o ); }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function sanitize_text_field( $t ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $t ) ) ); }
function sanitize_textarea_field( $t ) { return trim( strip_tags( (string) $t ) ); }
function sanitize_email( $e ) { return trim( (string) $e ); }
function sanitize_title( $t ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $t ) ), '-' ); }
function sanitize_user( $u, $s = false ) { return preg_replace( '/[^a-z0-9_.\-@]/i', '', (string) $u ); }
function sanitize_file_name( $f ) { return preg_replace( '/[^A-Za-z0-9_.\-]/', '', (string) $f ); }
function is_email( $e ) { return false !== filter_var( $e, FILTER_VALIDATE_EMAIL ) ? $e : false; }
function esc_url_raw( $u ) { return (string) $u; }
function absint( $v ) { return abs( (int) $v ); }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function get_current_user_id() { return $GLOBALS['test_user']; }
function current_user_can( $c ) { return true; }
function wp_generate_password( $l = 12, $s = true ) { return substr( bin2hex( random_bytes( $l ) ), 0, $l ); }
function wp_rand( $a = 0, $b = 0 ) { return random_int( $a, $b ); }
function wp_salt( $s = 'auth' ) { return 'test-salt-' . $s; }
function wp_hash( $d ) { return hash_hmac( 'md5', $d, 'test' ); }
function home_url( $p = '' ) { return 'http://example.test' . $p; }
function admin_url( $p = '' ) { return 'http://example.test/wp-admin/' . $p; }
function get_bloginfo( $s ) { return 'Test Shop'; }
function wp_specialchars_decode( $t, $q = 0 ) { return $t; }
function mysql2date( $f, $d ) { return gmdate( 'd.m.Y', strtotime( $d ) ); }
function get_date_from_gmt( $d, $f = 'Y-m-d H:i:s' ) { return $d; }
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, $d, ',', '.' ); }
function wp_upload_dir( $t = null, $c = true ) { return array( 'basedir' => sys_get_temp_dir() . '/wpac-uploads' ); }
function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0777, true ); }
function trailingslashit( $p ) { return rtrim( $p, '/' ) . '/'; }
function wp_delete_file( $f ) { @unlink( $f ); }
function wp_mail( $to, $s, $m ) { $GLOBALS['test_mails'][] = array( $to, $s ); return true; }
function get_user_by( $f, $v ) { return false; }
function get_userdata( $id ) { return false; }
function username_exists( $u ) { return false; }
function wp_insert_user( $u ) { static $id = 100; return ++$id; }
function wp_new_user_notification( $id, $x = null, $n = '' ) {}
// WP Consent API stand-in with the real semantics: "true" when no consent type is registered.
$GLOBALS['test_consent_type']   = '';
$GLOBALS['test_consent_cookie'] = '';
function wp_get_consent_type() { return $GLOBALS['test_consent_type']; }
function wp_has_consent( $c, $requested_by = null ) {
	if ( '' === $GLOBALS['test_consent_type'] ) { return true; }
	if ( false !== strpos( $GLOBALS['test_consent_type'], 'optout' ) && '' === $GLOBALS['test_consent_cookie'] ) { return true; }
	return 'allow' === $GLOBALS['test_consent_cookie'];
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
class WP_Error {
	private $code; private $message; private $data = array();
	public function __construct( $c = '', $m = '', $d = null ) { $this->code = $c; $this->message = $m; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function add_data( $d ) { $this->data = $d; }
}

spl_autoload_register(
	static function ( $class ) {
		$prefix = 'WPAffiliateCaptain\\';
		if ( 0 === strpos( $class, $prefix ) ) {
			$file = WPAC_DIR . 'src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
			if ( is_readable( $file ) ) {
				require $file;
			}
		}
	}
);
require WPAC_DIR . 'src/functions.php';

if ( ! defined( 'WPAC_SKIP_RESET' ) ) :
// Fresh statement directory and schema.
foreach ( (array) glob( sys_get_temp_dir() . '/wpac-uploads/wpac-statements/*' ) as $f ) {
	@unlink( $f );
}
// Fresh schema.
global $wpdb;
foreach ( WPAffiliateCaptain\Core\Db::TABLES as $t ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . WPAffiliateCaptain\Core\Db::table( $t ) );
}
WPAffiliateCaptain\Core\Installer::install_schema();
endif;
WPAffiliateCaptain\Codes\CodeRepository::register_hooks();
WPAffiliateCaptain\Sales\ContractApi::register_hooks();
function user_can( $u, $c ) { return false; }
function untrailingslashit( $p ) { return rtrim( $p, '/\\' ); }
function wp_list_pluck( $l, $f ) { return array_map( function ( $r ) use ( $f ) { return is_object( $r ) ? $r->$f : $r[ $f ]; }, $l ); }
$GLOBALS['test_scheduled'] = array();
function wp_schedule_single_event( $ts, $hook, $args = array() ) { $GLOBALS['test_scheduled'][] = $hook; return true; }
function get_permalink( $id ) { return 'http://example.test/?p=' . (int) $id; }
function wp_unslash( $v ) { return is_array( $v ) ? array_map( "wp_unslash", $v ) : stripslashes( (string) $v ); }
