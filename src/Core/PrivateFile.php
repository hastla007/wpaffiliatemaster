<?php
/**
 * Temporary files with personal data (program export with IBANs, uploaded import files):
 * created readable only by the web server user (0600), outside the web root where possible,
 * and deleted at the end of the request even when it is aborted (fatal error, closed
 * connection, time limit).
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Core;

defined( 'ABSPATH' ) || exit;

final class PrivateFile {

	/** @var string[] Files deleted at shutdown. */
	private static $files = array();

	/** @var bool Shutdown handler registered. */
	private static $hooked = false;

	/**
	 * Folder for private temporary files: WPAC_TEMP_DIR, WP_TEMP_DIR or the system temp folder
	 * when they are outside the WordPress folder; otherwise the protected folder of the credit
	 * notes (never the public wp-content folder, unlike wp_tempnam()).
	 *
	 * @param bool $shared Needed by a later request (another app server must see it): the
	 *                     protected credit note folder is used (shared storage on clusters).
	 * @return string
	 */
	public static function dir( $shared = false ) {
		if ( ! $shared ) {
			$candidates = array(
				defined( 'WPAC_TEMP_DIR' ) ? (string) WPAC_TEMP_DIR : '',
				defined( 'WP_TEMP_DIR' ) ? (string) WP_TEMP_DIR : '',
				(string) sys_get_temp_dir(),
			);
			$public = array_filter( array( defined( 'ABSPATH' ) ? (string) ABSPATH : '', defined( 'WP_CONTENT_DIR' ) ? (string) WP_CONTENT_DIR : '' ) );
			foreach ( $candidates as $dir ) {
				$dir = rtrim( $dir, '/\\' );
				if ( '' === $dir || ! @is_dir( $dir ) || ! @is_writable( $dir ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- open_basedir.
					continue;
				}
				$real = (string) realpath( $dir );
				$web  = false;
				foreach ( $public as $root ) {
					$root = (string) realpath( $root );
					if ( '' !== $root && 0 === strpos( $real . '/', rtrim( $root, '/' ) . '/' ) ) {
						$web = true;
					}
				}
				if ( ! $web ) {
					return $dir;
				}
			}
		}
		return \WPAffiliateCaptain\Payouts\Statement::dir();
	}

	/**
	 * Create an empty private file (0600).
	 *
	 * @param string $prefix  Name prefix.
	 * @param bool   $cleanup Delete at the end of this request.
	 * @param bool   $shared  See dir().
	 * @return string Path, '' on failure.
	 */
	public static function create( $prefix, $cleanup = true, $shared = false ) {
		$path = @tempnam( self::dir( $shared ), sanitize_file_name( (string) $prefix ) . '-' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $path ) {
			return '';
		}
		@chmod( $path, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod
		if ( $cleanup ) {
			self::track( $path );
		}
		return $path;
	}

	/**
	 * Delete this file at the end of the request.
	 *
	 * @param string $path Path.
	 */
	public static function track( $path ) {
		self::$files[ (string) $path ] = true;
		if ( ! self::$hooked ) {
			self::$hooked = true;
			register_shutdown_function( array( __CLASS__, 'cleanup' ) );
		}
	}

	/**
	 * Delete a file now.
	 *
	 * @param string $path Path.
	 */
	public static function release( $path ) {
		$path = (string) $path;
		if ( '' !== $path && file_exists( $path ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		unset( self::$files[ $path ] );
	}

	/**
	 * Shutdown: delete every tracked file that is still there.
	 */
	public static function cleanup() {
		foreach ( array_keys( self::$files ) as $path ) {
			self::release( $path );
		}
	}

	/**
	 * Tracked files (tests).
	 *
	 * @return string[]
	 */
	public static function tracked() {
		return array_keys( self::$files );
	}
}
