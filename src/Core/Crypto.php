<?php
/**
 * Symmetric encryption for sensitive payout data (IBAN) using libsodium.
 *
 * Define WPAC_ENCRYPTION_KEY (64 hex chars) in wp-config.php for a key that is
 * independent of the WordPress salts. Otherwise a key is derived from the salts;
 * rotating the salts then makes stored bank data unreadable.
 *
 * When the dedicated key is added later, data encrypted before stays readable: decryption
 * falls back to the salt-derived (legacy) key, and reencrypt_batch() re-encrypts such values
 * with the dedicated key (admin action on the integrations page, continued in the background).
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Core;

defined( 'ABSPATH' ) || exit;

final class Crypto {

	const PREFIX = 'wpac1:';

	/** Background continuation of the re-encryption. */
	const REENCRYPT_HOOK = 'wpac_reencrypt';

	/**
	 * Whether a dedicated key constant is configured.
	 *
	 * @return bool
	 */
	public static function has_dedicated_key() {
		return defined( 'WPAC_ENCRYPTION_KEY' ) && 64 === strlen( (string) WPAC_ENCRYPTION_KEY ) && ctype_xdigit( (string) WPAC_ENCRYPTION_KEY );
	}

	/**
	 * 32 byte key used for new encryptions.
	 *
	 * @return string
	 */
	private static function key() {
		if ( self::has_dedicated_key() ) {
			return sodium_hex2bin( (string) WPAC_ENCRYPTION_KEY );
		}
		return self::legacy_key();
	}

	/**
	 * Key derived from the WordPress salts (used before a dedicated key was configured).
	 *
	 * @return string
	 */
	private static function legacy_key() {
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
	 * Decrypt. Returns null when the value cannot be decrypted. Values encrypted with the
	 * salt-derived key before a dedicated key was set are still readable.
	 *
	 * @param string $cipher Cipher text.
	 * @return string|null
	 */
	public static function decrypt( $cipher ) {
		$plain = self::open( $cipher, self::key() );
		if ( null === $plain && self::has_dedicated_key() ) {
			$plain = self::open( $cipher, self::legacy_key() );
		}
		return $plain;
	}

	/**
	 * Whether a value can only be read with the legacy key although a dedicated key is set
	 * (it should be encrypted again).
	 *
	 * @param string $cipher Cipher text.
	 * @return bool
	 */
	public static function needs_reencrypt( $cipher ) {
		return self::has_dedicated_key() && '' !== (string) $cipher
			&& null === self::open( $cipher, self::key() ) && null !== self::open( $cipher, self::legacy_key() );
	}

	/**
	 * Decrypt with one key.
	 *
	 * @param string $cipher Cipher text.
	 * @param string $key    Key.
	 * @return string|null
	 */
	private static function open( $cipher, $key ) {
		if ( '' === (string) $cipher || 0 !== strpos( (string) $cipher, self::PREFIX ) ) {
			return null;
		}
		$raw = base64_decode( substr( (string) $cipher, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$box   = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain = sodium_crypto_secretbox_open( $box, $nonce, $key );
		return false === $plain ? null : $plain;
	}

	/**
	 * Stored bank details that are still encrypted with the legacy key, and those that cannot
	 * be decrypted at all (salts rotated before a dedicated key was set).
	 *
	 * @return array [ legacy count, unreadable count ]
	 */
	public static function legacy_status() {
		global $wpdb;
		if ( ! self::has_dedicated_key() ) {
			return array( 0, 0 );
		}
		$t       = Db::table( 'payout_profiles' );
		$legacy  = 0;
		$broken  = 0;
		$last_id = 0;
		do {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT affiliate_id, iban_encrypted FROM {$t} WHERE affiliate_id > %d AND iban_encrypted IS NOT NULL AND iban_encrypted <> '' ORDER BY affiliate_id ASC LIMIT 500", $last_id ) );
			foreach ( (array) $rows as $r ) {
				$last_id = (int) $r->affiliate_id;
				if ( null !== self::open( (string) $r->iban_encrypted, self::key() ) ) {
					continue;
				}
				if ( null !== self::open( (string) $r->iban_encrypted, self::legacy_key() ) ) {
					++$legacy;
				} else {
					++$broken;
				}
			}
		} while ( $rows && 500 === count( $rows ) );
		return array( $legacy, $broken );
	}

	/**
	 * Re-encrypt a batch of bank details from the legacy key to the dedicated key. Continues in
	 * the background while more are left.
	 *
	 * @param int $limit Rows per batch.
	 * @return int Number of re-encrypted values.
	 */
	public static function reencrypt_batch( $limit = 200 ) {
		global $wpdb;
		if ( ! self::has_dedicated_key() ) {
			return 0;
		}
		$t      = Db::table( 'payout_profiles' );
		$done   = 0;
		$cursor = (int) get_option( 'wpac_reencrypt_cursor', 0 );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT affiliate_id, iban_encrypted FROM {$t} WHERE affiliate_id > %d AND iban_encrypted IS NOT NULL AND iban_encrypted <> '' ORDER BY affiliate_id ASC LIMIT %d", $cursor, (int) $limit ) );
		foreach ( (array) $rows as $r ) {
			$cursor = (int) $r->affiliate_id;
			if ( ! self::needs_reencrypt( (string) $r->iban_encrypted ) ) {
				continue;
			}
			$plain = self::open( (string) $r->iban_encrypted, self::legacy_key() );
			// Only replace the exact value that was read (a parallel profile change wins).
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ok = $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET iban_encrypted = %s WHERE affiliate_id = %d AND iban_encrypted = %s", self::encrypt( (string) $plain ), (int) $r->affiliate_id, (string) $r->iban_encrypted ) );
			if ( $ok ) {
				++$done;
			}
		}
		if ( $rows && count( $rows ) === (int) $limit ) {
			update_option( 'wpac_reencrypt_cursor', $cursor, false );
			Scheduler::once( self::REENCRYPT_HOOK, 30 );
		} else {
			delete_option( 'wpac_reencrypt_cursor' );
		}
		if ( $done ) {
			Audit::log( 'payout_profile.reencrypted', 'settings', 0, array( 'count' => $done ) );
		}
		return $done;
	}
}
