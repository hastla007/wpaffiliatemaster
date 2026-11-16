<?php
/**
 * Minimum versions of the database server (decision E10, 16.11.2026): MySQL 9.7 LTS and
 * MariaDB 12.3 LTS. An older server is not blocked (activation and bookings keep working); the
 * monitor shows a warning in Site Health. The PHP minimum is checked in affiliate-captain.php.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Core;

defined( 'ABSPATH' ) || exit;

final class Requirements {

	/** Oldest supported MySQL version (LTS). */
	const MYSQL = '9.7';

	/** Oldest supported MariaDB version (LTS). */
	const MARIADB = '12.3';

	/**
	 * Version string of the database server (cached per request).
	 *
	 * @var string|null
	 */
	private static $server_info = null;

	/**
	 * Minimum versions, changeable with the filter wpac_minimum_db_versions (since 0.15.0), e.g.
	 * on a host whose older server the operator has tested.
	 *
	 * @return array{mysql: string, mariadb: string}
	 */
	public static function minimum_db_versions() {
		$defaults = array(
			'mysql'   => self::MYSQL,
			'mariadb' => self::MARIADB,
		);
		$filtered = apply_filters( 'wpac_minimum_db_versions', $defaults );
		$out      = $defaults;
		foreach ( array_keys( $defaults ) as $key ) {
			if ( is_array( $filtered ) && isset( $filtered[ $key ] ) && is_scalar( $filtered[ $key ] ) && preg_match( '/^\d+(\.\d+){0,2}$/', (string) $filtered[ $key ] ) ) {
				$out[ $key ] = (string) $filtered[ $key ];
			}
		}
		return $out;
	}

	/**
	 * Version string as reported by the server ("9.7.0", "12.3.1-MariaDB-ubu2404",
	 * "5.5.5-10.11.6-MariaDB-…").
	 *
	 * @return string Empty when unknown.
	 */
	public static function db_server_info() {
		if ( null === self::$server_info ) {
			global $wpdb;
			$info = '';
			if ( is_object( $wpdb ) && method_exists( $wpdb, 'db_server_info' ) ) {
				$info = (string) $wpdb->db_server_info();
			}
			if ( '' === $info && is_object( $wpdb ) ) {
				$info = (string) $wpdb->get_var( 'SELECT VERSION()' );
			}
			self::$server_info = $info;
		}
		return self::$server_info;
	}

	/**
	 * Server type and version from a version string. MariaDB is detected by the word "MariaDB";
	 * the replication prefix "5.5.5-" of older MariaDB servers is skipped.
	 *
	 * @param string $info Version string.
	 * @return array{type: string, version: string}|null Null when no version can be read.
	 */
	public static function parse_db_server( $info ) {
		$info    = trim( (string) $info );
		$mariadb = false !== stripos( $info, 'mariadb' );
		if ( $mariadb ) {
			$info = (string) preg_replace( '/^5\.5\.5-/', '', $info );
		}
		if ( ! preg_match( '/(\d+)\.(\d+)(?:\.(\d+))?/', $info, $m ) ) {
			return null;
		}
		return array(
			'type'    => $mariadb ? 'mariadb' : 'mysql',
			'version' => $m[1] . '.' . $m[2] . '.' . ( isset( $m[3] ) && '' !== $m[3] ? $m[3] : '0' ),
		);
	}

	/**
	 * Monitor check: null when the server is new enough (or its version is unknown), otherwise
	 * a warning [ 'recommended', label, description ] (never critical: no daily alarm mail).
	 *
	 * @param string|null $info Version string; null = the connected server.
	 * @return array|null
	 */
	public static function database_check( $info = null ) {
		$server = self::parse_db_server( null === $info ? self::db_server_info() : $info );
		if ( ! $server ) {
			return null;
		}
		$minimum = self::minimum_db_versions();
		if ( ! version_compare( $server['version'], $minimum[ $server['type'] ], '<' ) ) {
			return null;
		}
		$name = 'mariadb' === $server['type'] ? 'MariaDB' : 'MySQL';
		return array(
			'recommended',
			__( 'The database server is older than the supported minimum', 'affiliate-captain' ),
			/* translators: 1: minimum MySQL version, 2: minimum MariaDB version, 3: MySQL or MariaDB, 4: version of this server */
			sprintf( __( 'Affiliate Captain supports MySQL %1$s or newer and MariaDB %2$s or newer; this site uses %3$s %4$s. The plugin keeps working and nothing is blocked, but older database servers are no longer tested. Ask your host for an update.', 'affiliate-captain' ), $minimum['mysql'], $minimum['mariadb'], $name, $server['version'] ),
		);
	}
}
