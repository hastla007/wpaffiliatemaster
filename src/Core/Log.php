<?php
/**
 * Technical error log: WooCommerce's logger when available (WooCommerce → Status → Logs,
 * source "wp-affiliate-captain"), the PHP error log otherwise. The audit log stays the record of
 * business events; this is for operators debugging failures.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Core;

defined( 'ABSPATH' ) || exit;

final class Log {

	const SOURCE = 'wp-affiliate-captain';

	/**
	 * Log an error.
	 *
	 * @param string $message Message (no personal data).
	 * @param array  $context Context (codes, IDs).
	 */
	public static function error( $message, array $context = array() ) {
		self::write( 'error', $message, $context );
	}

	/**
	 * Log a warning.
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public static function warning( $message, array $context = array() ) {
		self::write( 'warning', $message, $context );
	}

	/**
	 * Write.
	 *
	 * @param string $level   Level.
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	private static function write( $level, $message, array $context ) {
		$line = (string) $message . ( $context ? ' ' . wp_json_encode( $context ) : '' );
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $line, array( 'source' => self::SOURCE ) );
			return;
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( 'WP Affiliate Captain ' . $level . ': ' . $line );
	}
}
