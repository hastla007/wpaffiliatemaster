<?php
/**
 * PHP-1 / decision E10 (0.15.0): minimum database server MySQL 9.7 LTS / MariaDB 12.3 LTS.
 * An older server is reported in the monitor (Site Health) as a warning, never blocked and never
 * a daily alarm; MariaDB is recognised by its version string (also with the "5.5.5-" prefix);
 * the minimums come from Requirements::MYSQL/MARIADB and the filter wpac_minimum_db_versions.
 * Included from run.php before r8-render.php (helpers check()).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Core\Monitor;
use WPAffiliateCaptain\Core\Requirements;

echo "R14 PHP-1 database server minimum (decision E10)\n";

check( 'PHP-1 minimums are MySQL 9.7 and MariaDB 12.3', array( '9.7', '12.3', array( 'mysql' => '9.7', 'mariadb' => '12.3' ) ), array( Requirements::MYSQL, Requirements::MARIADB, Requirements::minimum_db_versions() ) );

// Version strings as servers report them (fake versions, no server needed).
$php1_parse = array();
foreach ( array( '9.7.4', '8.4.3-0ubuntu0.24.04.1', '12.3.1-MariaDB-ubu2404', '5.5.5-10.11.6-MariaDB-0+deb12u1', '11.8.2-MariaDB-log', '8.0.mysql_aurora.3.04.0', '', 'unknown' ) as $php1_v ) {
	$php1_parse[] = Requirements::parse_db_server( $php1_v );
}
check(
	'PHP-1 server type and version read from the version string (MariaDB by name, "5.5.5-" prefix skipped)',
	array(
		array( 'type' => 'mysql', 'version' => '9.7.4' ),
		array( 'type' => 'mysql', 'version' => '8.4.3' ),
		array( 'type' => 'mariadb', 'version' => '12.3.1' ),
		array( 'type' => 'mariadb', 'version' => '10.11.6' ),
		array( 'type' => 'mariadb', 'version' => '11.8.2' ),
		array( 'type' => 'mysql', 'version' => '8.0.0' ),
		null,
		null,
	),
	$php1_parse
);

$php1_state = static function ( $info ) {
	$c = Requirements::database_check( $info );
	return null === $c ? 'ok' : $c[0];
};
check(
	'PHP-1 fake versions: older than MySQL 9.7 / MariaDB 12.3 warn ("recommended"), the minimums and newer do not, unknown does not',
	array( 'recommended', 'ok', 'ok', 'recommended', 'recommended', 'ok', 'ok', 'recommended', 'ok', 'ok' ),
	array( $php1_state( '8.4.3' ), $php1_state( '9.7.0' ), $php1_state( '9.8.1' ), $php1_state( '5.5.5-10.11.6-MariaDB' ), $php1_state( '12.2.9-MariaDB' ), $php1_state( '12.3.0-MariaDB' ), $php1_state( '13.0.1-MariaDB-ubu2404' ), $php1_state( '9.7.0-MariaDB' ), $php1_state( '' ), $php1_state( 'unknown' ) )
);
$php1_warn = Requirements::database_check( '5.5.5-10.11.6-MariaDB-0+deb12u1' );
check(
	'PHP-1 warning names both minimums and the detected server',
	array( true, true, true ),
	array( false !== strpos( $php1_warn[2], 'MySQL 9.7 or newer' ), false !== strpos( $php1_warn[2], 'MariaDB 12.3 or newer' ), false !== strpos( $php1_warn[2], 'uses MariaDB 10.11.6' ) )
);

// Filter: the operator lowers the minimums (tested older server); invalid values are ignored.
add_filter(
	'wpac_minimum_db_versions',
	static function ( $v ) {
		return array( 'mysql' => '8.4', 'mariadb' => 'eleven' );
	}
);
check( 'PHP-1 filter wpac_minimum_db_versions changes the minimum, an invalid value keeps the default', array( array( 'mysql' => '8.4', 'mariadb' => '12.3' ), 'ok', 'recommended' ), array( Requirements::minimum_db_versions(), $php1_state( '8.4.3' ), $php1_state( '11.8.2-MariaDB' ) ) );
remove_all_filters( 'wpac_minimum_db_versions' );

// The connected server (real) in the monitor: with minimums above it a warning, never critical.
$php1_real = Requirements::parse_db_server( Requirements::db_server_info() );
check( 'PHP-1 version of the connected test server can be read', true, is_array( $php1_real ) );
add_filter(
	'wpac_minimum_db_versions',
	static function () {
		return array( 'mysql' => '99.0', 'mariadb' => '99.0' );
	}
);
$php1_checks = Monitor::checks();
$php1_health = Monitor::site_health_result();
check(
	'PHP-1 too old server: monitor warning "db_server" (recommended), in Site Health, not in the critical list (no alarm mail, health route stays ok)',
	array( 'recommended', true, false, true ),
	array( $php1_checks['db_server'][0] ?? '', isset( Monitor::problems()['db_server'] ), isset( Monitor::problems( true )['db_server'] ), false !== strpos( $php1_health['description'], 'The database server is older than the supported minimum' ) )
);
remove_all_filters( 'wpac_minimum_db_versions' );
add_filter(
	'wpac_minimum_db_versions',
	static function () {
		return array( 'mysql' => '1.0', 'mariadb' => '1.0' );
	}
);
check( 'PHP-1 server new enough: no "db_server" check', false, isset( Monitor::checks()['db_server'] ) );
remove_all_filters( 'wpac_minimum_db_versions' );

// Activation is not blocked: the installer does not consult the database minimum.
check( 'PHP-1 activation and schema do not depend on the database minimum (warning only)', false, false !== strpos( (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Core/Installer.php' ), 'Requirements::' ) );
