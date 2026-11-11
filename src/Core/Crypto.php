<?php
/**
 * Symmetric encryption for sensitive payout data (IBAN) using libsodium.
 *
 * Define WPAC_ENCRYPTION_KEY (64 hex chars) in wp-config.php for a key that is
 * independent of the WordPress salts. Otherwise a key is derived from the salts;
 * rotating the salts then makes stored bank data unreadable.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Core;

defined( 'ABSPATH' ) || exit;

final class Crypto {

	const PREFIX = 'wpac1:';

	/**
	 * Whether a dedicated key constant is configured.
	 *
	 * @return bool
	 */
	public static function has_dedicated_key() {
		return defined( 'WPAC_ENCRYPTION_KEY' ) && 64 === strlen( (string) WPAC_ENCRYPTION_KEY ) && ctype_xdigit( (string) WPAC_ENCRYPTION_KEY );
	}

	/**
	 * 32 byte key.
	 *
	 * @return string
	 */
	private static function key() {
		if ( self::has_dedicated_key() ) {
			return sodium_hex2bin( (string) WPAC_ENCRYPTION_KEY );
		}
		return sodium_crypto_generichash( 'wpac-payout-data|' . wp_salt( 'secure_auth' ), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	/**
	 * Encrypt.
	 *
	 * @param string $plain Plain text.
	 * @return string
	 */
	public static function encrypt( $plain ) {
		if ( '' === (string) $plain ) {
			return '';
		}
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$box   = sodium_crypto_secretbox( (string) $plain, $nonce, self::key() );
		return self::PREFIX . base64_encode( $nonce . $box ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt. Returns null when the value cannot be decrypted.
	 *
	 * @param string $cipher Cipher text.
	 * @return string|null
	 */
	public static function decrypt( $cipher ) {
		if ( '' === (string) $cipher || 0 !== strpos( $cipher, self::PREFIX ) ) {
			return null;
		}
		$raw = base64_decode( substr( $cipher, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$box   = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain = sodium_crypto_secretbox_open( $box, $nonce, self::key() );
		return false === $plain ? null : $plain;
	}
}
