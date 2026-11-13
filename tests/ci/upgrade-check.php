<?php
/**
 * Upgrade test: the site was installed with the previous release (and test data); the plugin
 * folder now points to this checkout. A cron request runs the migration with the real dbDelta;
 * afterwards the schema must be current and complete and the data unchanged.
 *
 *   php tests/ci/upgrade-check.php <wp-dir> <host> <before.json>   (writes before.json when missing)
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
$_SERVER['HTTP_HOST']   = $argv[2];
$_SERVER['REQUEST_URI'] = '/';
$snapshot_file          = $argv[3];
$before                 = is_file( $snapshot_file ) ? json_decode( (string) file_get_contents( $snapshot_file ), true ) : null;
if ( $before ) {
	define( 'DOING_CRON', true ); // Migrations run in cron, admin or WP-CLI requests.
}
require rtrim( $argv[1], '/' ) . '/wp-load.php';
global $wpdb;
$p     = $wpdb->prefix . 'wpac_';
$state = array(
	'affiliates'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}affiliates" ),
	'commissions' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}commissions" ),
	'sum'         => (int) $wpdb->get_var( "SELECT COALESCE(SUM(amount_minor),0) FROM {$p}commissions" ),
	'codes'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}partner_codes" ),
	'version'     => (int) get_option( 'wpac_db_version' ),
);
if ( ! $before ) {
	file_put_contents( $snapshot_file, json_encode( $state ) );
	echo 'before upgrade: ', json_encode( $state ), "\n";
	exit( 0 );
}
$fail = 0;
$chk  = static function ( $name, $cond, $info = '' ) use ( &$fail ) {
	echo ( $cond ? '  ok   ' : '  FAIL ' ), $name, $info ? "  [$info]" : '', "\n";
	$fail += $cond ? 0 : 1;
};
echo 'after upgrade: ', json_encode( $state ), "\n";
$chk( 'schema version raised to ' . WPAC_DB_VERSION, WPAC_DB_VERSION === $state['version'], 'was ' . $before['version'] );
$problems = WPAffiliateCaptain\Core\Installer::verify_schema();
$chk( 'schema complete (real dbDelta)', array() === $problems, implode( '; ', $problems ) );
$chk( 'no migration problems stored', ! get_option( 'wpac_schema_problems' ) );
foreach ( array( 'affiliates', 'commissions', 'sum', 'codes' ) as $k ) {
	$chk( "data unchanged: $k", $before[ $k ] === $state[ $k ], $before[ $k ] . ' → ' . $state[ $k ] );
}
$chk( 'accounts consistent', array() === WPAffiliateCaptain\Commissions\Balance::verify_all() );
exit( $fail ? 1 : 0 );
