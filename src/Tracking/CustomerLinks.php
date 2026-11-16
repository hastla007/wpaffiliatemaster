<?php
/**
 * Lifetime customer ↔ partner links.
 *
 * After a customer's first attributed purchase, later purchases by the same
 * customer can be attributed to the same partner (optionally limited in days and
 * with a separate rate). Customers are identified only by keyed hashes (HMAC) of
 * their user ID and normalized e-mail – no plain customer data is stored.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Tracking;

use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class CustomerLinks {

	/**
	 * Keyed hash of an identifier.
	 *
	 * @param string $identifier Identifier.
	 * @return string
	 */
	private static function hash( $identifier ) {
		return hash_hmac( 'sha256', $identifier, self::key() );
	}

	/** Option: fingerprint of WPAC_CUSTOMER_KEY once the hashes are made with it. */
	const CONSTANT_FP = 'wpac_customer_key_constant';

	/**
	 * Key of the request (cached: the decision needs one query).
	 *
	 * @var array|null
	 */
	private static $state = null;

	/**
	 * Dedicated, persistent key (independent of WordPress salt rotation, so
	 * erasure requests still find the hashes).
	 *
	 * @return string
	 */
	private static function key() {
		return self::key_state()['key'];
	}

	/**
	 * A valid WPAC_CUSTOMER_KEY from wp-config.php (at least 32 characters), or ''.
	 *
	 * @return string
	 */
	private static function constant_key() {
		return defined( 'WPAC_CUSTOMER_KEY' ) && is_string( WPAC_CUSTOMER_KEY ) && strlen( WPAC_CUSTOMER_KEY ) >= 32 ? (string) WPAC_CUSTOMER_KEY : '';
	}

	/** Option: persistent "key changed" state (max_id = newest link made with the earlier key, fp = new fingerprint or '' when the constant was removed, since). */
	const KEY_CHANGED = 'wpac_customer_key_changed';

	/**
	 * Fingerprint of a key: HMAC with the site's auth salt, so a database dump alone does not
	 * allow guessing a weak WPAC_CUSTOMER_KEY offline (a plain sha256 would).
	 *
	 * @param string $key Key.
	 * @return string
	 */
	public static function fingerprint( $key ) {
		return hash_hmac( 'sha256', (string) $key, wp_salt( 'auth' ) );
	}

	/**
	 * Whether a stored fingerprint belongs to a key (current HMAC form, or the plain sha256 of
	 * releases up to 0.13).
	 *
	 * @param string $fp  Stored fingerprint.
	 * @param string $key Key.
	 * @return bool
	 */
	private static function fingerprint_matches( $fp, $key ) {
		return '' !== $fp && ( hash_equals( $fp, self::fingerprint( $key ) ) || hash_equals( $fp, hash( 'sha256', (string) $key ) ) );
	}

	/**
	 * Which key the hashes use. WPAC_CUSTOMER_KEY (wp-config.php, so a database dump alone does
	 * not reverse the hashes) is only taken while no customer links exist yet, or when the
	 * existing links were made with it: the e-mail addresses are not stored, so existing hashes
	 * cannot be recalculated with a new key. Otherwise the stored key stays in use and
	 * status() reports the constant as ignored.
	 *
	 * When the constant was changed or removed while links exist, the fingerprint of the earlier
	 * key is never overwritten; a persistent "key changed" state (KEY_CHANGED) keeps the warning
	 * until no link made with the earlier key remains, the earlier key is restored, or an admin
	 * confirms the change (confirm_key_change()).
	 *
	 * @return array key, source (constant|option), ignored (constant set but not used),
	 *               missing (links were made with a key that is no longer defined).
	 */
	private static function key_state() {
		if ( null !== self::$state ) {
			return self::$state;
		}
		$const  = self::constant_key();
		$fp     = (string) get_option( self::CONSTANT_FP, '' );
		$stored = get_option( 'wpac_customer_key' );
		$stored = is_string( $stored ) && strlen( $stored ) >= 32 ? $stored : '';
		$state  = array(
			'key'     => '',
			'source'  => 'option',
			'ignored' => false,
			'missing' => false,
		);
		if ( '' !== $const ) {
			$fp_new = self::fingerprint( $const );
			if ( self::fingerprint_matches( $fp, $const ) ) {
				$state['source'] = 'constant';
				if ( ! hash_equals( $fp, $fp_new ) ) {
					update_option( self::CONSTANT_FP, $fp_new, false ); // Upgrade the plain sha256 of earlier releases.
				}
				delete_option( self::KEY_CHANGED ); // The earlier key is back (or never changed).
			} elseif ( '' === $fp && '' !== $stored && self::has_links() ) {
				// Existing hashes were made with the stored key: keep it, the constant is ignored.
				$state['ignored'] = true;
			} elseif ( '' !== $fp && self::old_links_remain( $fp_new ) ) {
				// Links were made with an earlier constant: keep its fingerprint and warn until
				// they are gone or an admin confirms. New links use the new constant.
				$state['missing'] = true;
				$state['source']  = 'constant';
				delete_option( 'wpac_customer_key' );
			} else {
				// No hashes depend on another key: the constant takes over.
				$state['source'] = 'constant';
				update_option( self::CONSTANT_FP, $fp_new, false );
				delete_option( self::KEY_CHANGED );
				delete_option( 'wpac_customer_key' );
			}
		} elseif ( '' !== $fp ) {
			// The constant was removed: existing hashes no longer match.
			$state['missing'] = self::old_links_remain( '' );
			if ( ! $state['missing'] ) {
				delete_option( self::CONSTANT_FP );
				delete_option( self::KEY_CHANGED );
			}
		}
		if ( 'constant' === $state['source'] ) {
			$state['key'] = $const;
		} else {
			$state['key'] = '' !== $stored ? $stored : self::generate_key();
		}
		self::$state = $state;
		return $state;
	}

	/**
	 * Generate the stored key once. Two first requests at the same time must not end up with
	 * different keys (the loser's hashes would never be found again). add_option() is no help:
	 * WordPress writes it as an UPSERT, so the second request would overwrite the first key. The
	 * key is created with INSERT IGNORE instead - only one insert wins - and both requests use
	 * the value read back from the database.
	 *
	 * @return string
	 */
	private static function generate_key() {
		global $wpdb;
		$key = bin2hex( random_bytes( 32 ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", 'wpac_customer_key', $key ) );
		wp_cache_delete( 'wpac_customer_key', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$winner = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'wpac_customer_key' ) );
		if ( is_string( $winner ) && strlen( $winner ) >= 32 ) {
			return $winner;
		}
		update_option( 'wpac_customer_key', $key, false ); // Insert impossible (e.g. read-only replica lag): as before.
		return $key;
	}

	/**
	 * Whether links made with the earlier key still exist. The first time a change is noticed,
	 * the newest link ID is recorded (links created afterwards use the new key and do not count).
	 *
	 * @param string $fp_new Fingerprint of the key now in use ('' = stored key, constant removed).
	 * @return bool
	 */
	private static function old_links_remain( $fp_new ) {
		global $wpdb;
		$t       = Db::table( 'customer_links' );
		$changed = get_option( self::KEY_CHANGED );
		if ( ! is_array( $changed ) || ! isset( $changed['max_id'], $changed['fp'] ) || ! hash_equals( (string) $changed['fp'], (string) $fp_new ) ) {
			// First notice of this change (or yet another key): all current links are old.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$max = (int) $wpdb->get_var( "SELECT MAX(id) FROM {$t}" );
			if ( ! $max ) {
				return false;
			}
			update_option(
				self::KEY_CHANGED,
				array(
					'max_id' => $max,
					'fp'     => (string) $fp_new,
					'since'  => time(),
				),
				false
			);
			return true;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$t} WHERE id <= %d LIMIT 1", (int) $changed['max_id'] ) );
	}

	/**
	 * The "key changed" state, for the admin: since (timestamp) and the number of links made
	 * with the earlier key; null without a change.
	 *
	 * @return array|null
	 */
	public static function key_change() {
		global $wpdb;
		$changed = get_option( self::KEY_CHANGED );
		if ( ! is_array( $changed ) || empty( $changed['max_id'] ) ) {
			return null;
		}
		$t = Db::table( 'customer_links' );
		return array(
			'since' => (int) ( $changed['since'] ?? 0 ),
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			'links' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE id <= %d", (int) $changed['max_id'] ) ),
		);
	}

	/**
	 * Admin confirms the changed key: the key now in use becomes the reference and the warning
	 * ends. With $delete_old the links made with the earlier key are removed – they can no longer
	 * be matched to a customer (not for lifetime commissions, not for access or erasure
	 * requests). Without it they stay until they expire (e.g. only the WordPress salts changed
	 * and the key itself is the same).
	 *
	 * @param bool $delete_old Delete the links made with the earlier key.
	 * @return int Deleted links.
	 */
	public static function confirm_key_change( $delete_old ) {
		global $wpdb;
		$changed = get_option( self::KEY_CHANGED );
		$deleted = 0;
		if ( $delete_old && is_array( $changed ) && ! empty( $changed['max_id'] ) ) {
			$t = Db::table( 'customer_links' );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$deleted = (int) \WPAffiliateCaptain\Core\Cron::delete_in_batches( $wpdb->prepare( "DELETE FROM {$t} WHERE id <= %d", (int) $changed['max_id'] ), 2000, 100 );
		}
		$const = self::constant_key();
		if ( '' !== $const ) {
			update_option( self::CONSTANT_FP, self::fingerprint( $const ), false );
		} else {
			delete_option( self::CONSTANT_FP );
		}
		delete_option( self::KEY_CHANGED );
		self::reset_key_cache();
		\WPAffiliateCaptain\Core\Audit::log(
			'customer_links.key_confirmed',
			'settings',
			0,
			array(
				'deleted' => $deleted,
				'source'  => '' !== $const ? 'constant' : 'option',
			)
		);
		return $deleted;
	}

	/**
	 * Whether any customer link exists (one index lookup).
	 *
	 * @return bool
	 */
	private static function has_links() {
		global $wpdb;
		$t = Db::table( 'customer_links' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return (bool) $wpdb->get_var( "SELECT 1 FROM {$t} LIMIT 1" );
	}

	/**
	 * Key status for Site Health and the system status: source (constant|option), ignored,
	 * missing (see key_state()).
	 *
	 * @return array
	 */
	public static function key_status() {
		$state = self::key_state();
		unset( $state['key'] );
		return $state;
	}

	/**
	 * Forget the key decision of this request (tests, after the links or options changed).
	 */
	public static function reset_key_cache() {
		self::$state = null;
	}

	/**
	 * Delete expired links (cron) in small batches (index on expires_at), so a large backlog never
	 * becomes one long-locking DELETE. With lifetime commissions switched off, the links have no
	 * purpose any more and are all deleted (the privacy notice no longer mentions them).
	 *
	 * @return int
	 */
	public static function purge_expired() {
		global $wpdb;
		$t = Db::table( 'customer_links' );
		if ( ! Settings::get( 'lifetime_enabled' ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return \WPAffiliateCaptain\Core\Cron::delete_in_batches( "DELETE FROM {$t}", 2000, 100 );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return \WPAffiliateCaptain\Core\Cron::delete_in_batches( $wpdb->prepare( "DELETE FROM {$t} WHERE expires_at IS NOT NULL AND expires_at <= %s", Db::now() ), 2000, 100 );
	}

	/**
	 * Register hooks: the links of a partner end with the partnership.
	 */
	public static function register_hooks() {
		add_action( 'wpac_affiliate_status_changed', array( __CLASS__, 'on_status_changed' ), 10, 2 );
	}

	/**
	 * A terminated or rejected partner keeps no customers ("until the end of the contract").
	 *
	 * @param int    $affiliate_id Partner.
	 * @param string $status       New status.
	 */
	public static function on_status_changed( $affiliate_id, $status ) {
		if ( in_array( $status, array( 'terminated', 'rejected' ), true ) ) {
			self::forget_affiliate( (int) $affiliate_id );
		}
	}

	/**
	 * Remove all customer links of a partner.
	 *
	 * @param int $affiliate_id Partner.
	 * @return int Deleted rows.
	 */
	public static function forget_affiliate( $affiliate_id ) {
		global $wpdb;
		$n = (int) $wpdb->delete( Db::table( 'customer_links' ), array( 'affiliate_id' => (int) $affiliate_id ) );
		if ( $n ) {
			\WPAffiliateCaptain\Core\Audit::log( 'customer_links.removed', 'affiliate', (int) $affiliate_id, array( 'count' => $n ) );
		}
		return $n;
	}

	/**
	 * Hashes for a sale's customer.
	 *
	 * @param int    $user_id User ID.
	 * @param string $email   E-mail.
	 * @return string[]
	 */
	public static function hashes( $user_id, $email ) {
		$out = array();
		if ( (int) $user_id ) {
			$out[] = self::hash( 'u:' . (int) $user_id );
		}
		$email = AffiliateRepository::comparable_email( $email );
		if ( '' !== $email ) {
			$out[] = self::hash( 'e:' . $email );
		}
		return $out;
	}

	/**
	 * Hashes a NEW link may be created for. Only identities the shop has verified count: the
	 * customer account (user ID) and the e-mail address of that account. A guest order's billing
	 * e-mail is not verified – anyone can type a stranger's address into a 0 € guest order – so
	 * guest orders never create a link (they can still be matched by an existing one).
	 *
	 * @param int    $user_id User ID of the order (0 = guest).
	 * @param string $email   Billing e-mail of the order.
	 * @return string[]
	 */
	public static function link_hashes( $user_id, $email ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return array();
		}
		$account = '';
		$user    = function_exists( 'get_userdata' ) ? get_userdata( $user_id ) : false;
		if ( $user && isset( $user->user_email ) && AffiliateRepository::comparable_email( $user->user_email ) === AffiliateRepository::comparable_email( $email ) ) {
			$account = (string) $user->user_email;
		}
		/**
		 * Whether a new lifetime link may also use the account e-mail (so later guest orders with
		 * that address are found). Return false to bind customer accounts only.
		 *
		 * @param bool   $use     Default: true when the billing e-mail is the account e-mail.
		 * @param int    $user_id Customer user ID.
		 * @param string $email   Billing e-mail.
		 */
		if ( '' !== $account && ! apply_filters( 'wpac_lifetime_link_email', true, $user_id, $email ) ) {
			$account = '';
		}
		return self::hashes( $user_id, $account );
	}

	/**
	 * All active (not pending, not expired) links of a customer, oldest first.
	 *
	 * @param string[] $hashes Hashes.
	 * @return object[]
	 */
	public static function find_all( array $hashes ) {
		global $wpdb;
		if ( ! $hashes ) {
			return array();
		}
		$t            = Db::table( 'customer_links' );
		$placeholders = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
		$params       = array_merge( $hashes, array( Db::now() ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE customer_hash IN ({$placeholders}) AND pending = 0 AND (expires_at IS NULL OR expires_at > %s) ORDER BY id ASC", $params ) );
	}

	/**
	 * Active partner link for a customer.
	 *
	 * @param string[] $hashes Hashes.
	 * @return object|null
	 */
	public static function find( array $hashes ) {
		global $wpdb;
		if ( ! $hashes ) {
			return null;
		}
		$t            = Db::table( 'customer_links' );
		$placeholders = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
		$params       = array_merge( $hashes, array( Db::now() ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE customer_hash IN ({$placeholders}) AND pending = 0 AND (expires_at IS NULL OR expires_at > %s) ORDER BY id ASC LIMIT 1", $params ) );
	}

	/**
	 * Link a customer to a partner (first link wins; existing links are kept). A link to a
	 * partner who no longer works with the shop (terminated, rejected, deleted) is replaced, and a
	 * pending link (order still under review) never blocks an active one.
	 *
	 * @param int      $affiliate_id Affiliate.
	 * @param string[] $hashes       Hashes (see link_hashes()).
	 * @param string   $source       Source.
	 * @param string   $order_id     Order.
	 * @param bool     $pending      Order under review: the link only counts once approved (activate_order()).
	 */
	public static function link( $affiliate_id, array $hashes, $source, $order_id, $pending = false ) {
		global $wpdb;
		$days    = (int) Settings::get( 'lifetime_days' );
		$expires = $days > 0 ? Db::now( time() + $days * DAY_IN_SECONDS ) : null;
		$t       = Db::table( 'customer_links' );
		foreach ( $hashes as $hash ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, affiliate_id, pending FROM {$t} WHERE customer_hash = %s AND (expires_at IS NULL OR expires_at > %s)", $hash, Db::now() ) );
			if ( $row && ( ! (int) $row->pending || $pending ) && self::binding( (int) $row->affiliate_id ) ) {
				continue;
			}
			// Replace an expired, pending or orphaned link for the same customer.
			$wpdb->delete( $t, array( 'customer_hash' => $hash ) );
			$wpdb->insert(
				$t,
				array(
					'affiliate_id'    => (int) $affiliate_id,
					'customer_hash'   => $hash,
					'source'          => Db::cut( (string) $source, 40 ),
					'source_order_id' => Db::cut( (string) $order_id, 64 ),
					'pending'         => $pending ? 1 : 0,
					'created_at'      => Db::now(),
					'expires_at'      => $expires,
				)
			);
		}
	}

	/**
	 * Whether a partner still holds customer links (exists and was not terminated/rejected).
	 * Paused or restricted partners keep their customers; they just earn nothing meanwhile.
	 *
	 * @param int $affiliate_id Partner.
	 * @return bool
	 */
	private static function binding( $affiliate_id ) {
		$aff = AffiliateRepository::find( $affiliate_id );
		return $aff && ! in_array( $aff->status, array( 'terminated', 'rejected' ), true );
	}

	/**
	 * Activate the pending links of an order (its commission was approved after a review).
	 *
	 * @param string $source   Source.
	 * @param string $order_id Order.
	 * @return int Activated rows.
	 */
	public static function activate_order( $source, $order_id ) {
		global $wpdb;
		return (int) $wpdb->update(
			Db::table( 'customer_links' ),
			array( 'pending' => 0 ),
			array(
				'source'          => Db::cut( (string) $source, 40 ),
				'source_order_id' => Db::cut( (string) $order_id, 64 ),
				'pending'         => 1,
			)
		);
	}

	/**
	 * Links of one customer for the admin (account and/or e-mail), with partner names.
	 *
	 * @param int    $user_id User ID (0 = none).
	 * @param string $email   E-mail ('' = none).
	 * @return object[]
	 */
	public static function for_customer( $user_id, $email ) {
		global $wpdb;
		$hashes = self::hashes( $user_id, $email );
		if ( ! $hashes ) {
			return array();
		}
		$t = Db::table( 'customer_links' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE customer_hash IN (" . implode( ',', array_fill( 0, count( $hashes ), '%s' ) ) . ') ORDER BY id ASC', $hashes ) );
	}

	/**
	 * Admin removes the lifetime binding of a customer (audited).
	 *
	 * @param int    $user_id User ID.
	 * @param string $email   E-mail.
	 * @param string $reason  Reason.
	 * @return int Deleted rows.
	 */
	public static function unlink_customer( $user_id, $email, $reason = '' ) {
		$rows = self::for_customer( $user_id, $email );
		$n    = self::forget( $user_id, $email );
		if ( $n ) {
			\WPAffiliateCaptain\Core\Audit::log(
				'customer_links.unlinked',
				'affiliate',
				$rows ? (int) $rows[0]->affiliate_id : 0,
				array(
					'count'  => $n,
					'orders' => array_values( array_unique( wp_list_pluck( $rows, 'source_order_id' ) ) ),
					'reason' => Db::cut( (string) $reason, 255 ),
				)
			);
		}
		return $n;
	}

	/**
	 * Remove links of a customer (privacy eraser).
	 *
	 * @param int    $user_id User ID.
	 * @param string $email   E-mail.
	 * @return int Deleted rows.
	 */
	public static function forget( $user_id, $email ) {
		global $wpdb;
		$n = 0;
		foreach ( self::hashes( $user_id, $email ) as $hash ) {
			$n += (int) $wpdb->delete( Db::table( 'customer_links' ), array( 'customer_hash' => $hash ) );
		}
		return $n;
	}

	/**
	 * Remove the links created by an order (the order was fully refunded, cancelled
	 * or rejected – a reversed first order must not bind the customer to the partner).
	 *
	 * @param string $source   Source.
	 * @param string $order_id Order.
	 * @return int Deleted rows.
	 */
	public static function forget_order( $source, $order_id ) {
		global $wpdb;
		return (int) $wpdb->delete(
			Db::table( 'customer_links' ),
			array(
				'source'          => Db::cut( (string) $source, 40 ),
				'source_order_id' => Db::cut( (string) $order_id, 64 ),
			)
		);
	}
}
