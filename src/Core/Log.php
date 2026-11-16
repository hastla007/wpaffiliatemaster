<?php
/**
 * Technical error log: WooCommerce's logger when available (WooCommerce → Status → Logs,
 * source "affiliate-captain"; up to 0.10 "wp-affiliate-captain"), the PHP error log otherwise. The audit log stays the record of
 * business events; this is for operators debugging failures.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Core;

defined( 'ABSPATH' ) || exit;

final class Log {

	const SOURCE = 'affiliate-captain';

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
	 * Log a debug message (only with WP_DEBUG or the filter wpac_log_debug).
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public static function debug( $message, array $context = array() ) {
		/**
		 * Whether debug messages of Affiliate Captain are written to the log.
		 *
		 * @param bool $enabled Default: WP_DEBUG.
		 */
		if ( apply_filters( 'wpac_log_debug', defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			self::write( 'debug', $message, $context );
		}
	}

	/**
	 * Log a caught exception with its class, code, file and line (the message alone often does
	 * not say where it came from).
	 *
	 * @param string     $message Message (no personal data).
	 * @param \Throwable $e       Exception.
	 * @param array      $context Context (codes, IDs).
	 * @param string     $level   error|warning|debug.
	 */
	public static function exception( $message, $e, array $context = array(), $level = 'error' ) {
		$context = array_merge( $context, self::describe( $e ) );
		if ( 'debug' === $level ) {
			self::debug( $message, $context );
			return;
		}
		self::write( 'warning' === $level ? 'warning' : 'error', $message, $context );
	}

	/**
	 * Exception details for a log entry or a support report: class, error code, message as plain
	 * text (exception texts are HTML-escaped where they are thrown), file relative to the plugin
	 * and line.
	 *
	 * @param \Throwable $e Exception.
	 * @return array
	 */
	public static function describe( $e ) {
		if ( ! $e instanceof \Throwable ) {
			return array();
		}
		$root = defined( 'WPAC_FILE' ) ? dirname( (string) WPAC_FILE ) . '/' : '';
		$file = (string) $e->getFile();
		if ( '' !== $root && 0 === strpos( $file, $root ) ) {
			$file = substr( $file, strlen( $root ) );
		} else {
			$file = basename( $file );
		}
		return array(
			'exception' => get_class( $e ),
			'code'      => $e instanceof IntegrationException ? $e->error_code : (string) $e->getCode(),
			'message'   => Db::cut( self::plain( $e->getMessage() ), 500 ),
			'file'      => $file,
			'line'      => (int) $e->getLine(),
		);
	}

	/**
	 * Plain text of an escaped exception message (for logs, CLI output and the retry queue).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function plain( $text ) {
		return html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Mask e-mail addresses in a log text: "anna.berg@example.de" becomes "a***@***.de" (enough to
	 * tell addresses apart while debugging, not enough to identify the person). Unicode-aware
	 * (R13-N1): umlauts and other scripts in the local part or the domain ("jörg@bücher.de",
	 * "анна@пример.рф") and address literals ("anna@[192.168.0.1]") are masked as well.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function mask( $text ) {
		$text = (string) $text;
		$char = '[^\s@"\'<>()\[\],;:\\\\\/]';
		$out  = preg_replace_callback(
			'/(' . $char . ')' . $char . '*@(?:\[[^\]\s]+\]|(?:[^\s@"\'<>()\[\],;:\\\\\/.]+\.)+([^\s@"\'<>()\[\],;:\\\\\/.\d]{2,24}))/u',
			static function ( $m ) {
				return $m[1] . '***@***' . ( isset( $m[2] ) && '' !== $m[2] ? '.' . $m[2] : '' );
			},
			$text
		);
		if ( null === $out ) {
			// Invalid UTF-8: byte-wise, ASCII addresses only.
			$out = preg_replace_callback(
				'/([A-Za-z0-9._%+\-])[A-Za-z0-9._%+\-]*@(?:[A-Za-z0-9\-]+\.)+([A-Za-z]{2,24})\b/',
				static function ( $m ) {
					return $m[1] . '***@***.' . $m[2];
				},
				$text
			);
		}
		return (string) $out;
	}

	/**
	 * Mask a log context before it is encoded (R13-N1): every string value through mask(), so
	 * JSON escapes ("\u00fc") never hide an address from the pattern.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	public static function mask_value( $value ) {
		if ( is_string( $value ) ) {
			return self::mask( $value );
		}
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $k => $v ) {
				$out[ is_string( $k ) ? self::mask( $k ) : $k ] = self::mask_value( $v );
			}
			return $out;
		}
		return $value;
	}

	/**
	 * A database or exception error text for the log or the job queue (R13-N1): e-mail addresses
	 * masked and the value of a duplicate key removed ("Duplicate entry 'jane-doe' for key 'slug'"
	 * names the partner).
	 *
	 * @param string $text Error text.
	 * @return string
	 */
	public static function mask_error( $text ) {
		$text = (string) preg_replace( "/(Duplicate entry ')(.*?)(' for key)/su", '$1***$3', (string) $text );
		return self::mask( $text );
	}

	/**
	 * Write.
	 *
	 * @param string $level   Level.
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	private static function write( $level, $message, array $context ) {
		// Exception texts and mailer errors may contain e-mail addresses (e.g. "Duplicate entry
		// 'x@y.de'", "Invalid address: …"): they are masked before anything is written (R12-N19).
		// Masked before encoding (R13-N1), and the encoded line once more for keys and message.
		$line = self::mask( self::mask( (string) $message ) . ( $context ? ' ' . wp_json_encode( self::mask_value( $context ), JSON_UNESCAPED_UNICODE ) : '' ) );
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $line, array( 'source' => self::SOURCE ) );
			return;
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( 'Affiliate Captain ' . $level . ': ' . $line );
	}
}
