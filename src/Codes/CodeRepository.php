<?php
/**
 * Partner codes: cookie-free primary attribution path. A code may be linked to
 * a WooCommerce/Voucher Captain coupon code; the coupon system stays in charge
 * of any discount.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Codes;

use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Core\Audit;
use WPAffiliateCaptain\Core\Db;

defined( 'ABSPATH' ) || exit;

final class CodeRepository {

	public static function register_hooks() {
		add_filter( 'wpac_resolve_partner_code', array( __CLASS__, 'filter_resolve' ), 10, 3 );
		add_filter( 'wpac_coupon_affiliate', array( __CLASS__, 'filter_coupon' ), 10, 2 );
	}

	/**
	 * Normalize a code: upper-case, A-Z 0-9 _ -.
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function normalize( $code ) {
		return substr( strtoupper( preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $code ) ), 0, 64 );
	}

	/**
	 * Partner code for a coupon link. Coupon codes may contain any characters ("größe10",
	 * "лето20", "€10"); partner codes are A–Z/0–9. Umlauts and accents are transliterated
	 * (GRÖSSE10 → GROESSE10); if too little is left, a random code is used. The coupon itself
	 * is matched by its own column, so the partner code only has to be unique.
	 *
	 * @param string $coupon Coupon code.
	 * @return string
	 */
	public static function code_for_coupon( $coupon ) {
		$coupon = (string) $coupon;
		$coupon = strtr(
			$coupon,
			array(
				'Ä' => 'AE',
				'Ö' => 'OE',
				'Ü' => 'UE',
				'ä' => 'ae',
				'ö' => 'oe',
				'ü' => 'ue',
				'ß' => 'ss',
			)
		);
		if ( function_exists( 'remove_accents' ) ) {
			$coupon = remove_accents( $coupon );
		}
		$code = self::normalize( $coupon );
		return strlen( $code ) >= 3 ? $code : self::random_code();
	}

	/**
	 * Normalize a coupon code the way WooCommerce does (trimmed, lower-case).
	 *
	 * @param string $coupon Coupon.
	 * @return string
	 */
	public static function normalize_coupon( $coupon ) {
		$coupon = trim( (string) $coupon );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $coupon, 'UTF-8' ) : strtolower( $coupon );
	}

	/**
	 * Find by code.
	 *
	 * @param string $code Code.
	 * @return object|null
	 */
	public static function find_by_code( $code ) {
		global $wpdb;
		$code = self::normalize( $code );
		if ( '' === $code ) {
			return null;
		}
		$t = Db::table( 'partner_codes' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE code = %s", $code ) );
		if ( ! $row && false === strpos( $code, '-' ) && 8 === strlen( $code ) ) {
			// Generated code typed without hyphen (K7Q3MX2P → K7Q3-MX2P): one indexed lookup
			// instead of a full scan (unknown codes are cheap, also for bots).
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE code = %s", substr( $code, 0, 4 ) . '-' . substr( $code, 4 ) ) );
		}
		return $row;
	}

	/**
	 * Find the code row linked to a coupon.
	 *
	 * @param string $coupon       Coupon code.
	 * @param bool   $active_only  Only active rows (current link).
	 * @param int    $affiliate_id Restrict to an affiliate; also finds earlier, unlinked rows whose code equals the coupon.
	 * @return object|null
	 */
	public static function find_by_coupon( $coupon, $active_only = true, $affiliate_id = 0 ) {
		global $wpdb;
		$coupon = self::normalize_coupon( $coupon );
		if ( '' === $coupon ) {
			return null;
		}
		$t = Db::table( 'partner_codes' );
		if ( $affiliate_id ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE affiliate_id = %d AND (coupon_code = %s OR (coupon_code = '' AND code = %s)) ORDER BY id DESC LIMIT 1", $affiliate_id, $coupon, self::normalize( $coupon ) ) );
		}
		$status = $active_only ? " AND status = 'active'" : '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE coupon_code = %s{$status} ORDER BY id DESC LIMIT 1", $coupon ) );
	}

	/**
	 * Find by ID.
	 *
	 * @param int $id ID.
	 * @return object|null
	 */
	public static function find( $id ) {
		global $wpdb;
		$t = Db::table( 'partner_codes' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) );
	}

	/**
	 * Codes of an affiliate.
	 *
	 * @param int $affiliate_id Affiliate.
	 * @return array
	 */
	public static function for_affiliate( $affiliate_id ) {
		global $wpdb;
		$t = Db::table( 'partner_codes' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE affiliate_id = %d ORDER BY id ASC", $affiliate_id ) );
	}

	/**
	 * Main (non-batch, valid) code of an affiliate.
	 *
	 * @param int $affiliate_id Affiliate.
	 * @return string
	 */
	public static function main_code( $affiliate_id ) {
		// Non-batch codes without coupon first, then with coupon (partners with thousands of batch
		// codes: only the candidates are read, never all rows).
		$row = self::first_valid( $affiliate_id, true );
		return $row ? $row->code : '';
	}

	/**
	 * First usable code of a partner (active, not expired, not used up), by ID.
	 *
	 * @param int  $affiliate_id Affiliate.
	 * @param bool $main_only    Only non-batch codes, those without coupon first.
	 * @return object|null
	 */
	private static function first_valid( $affiliate_id, $main_only = false ) {
		global $wpdb;
		$t     = Db::table( 'partner_codes' );
		$where = "affiliate_id = %d AND status = 'active' AND ( expires_at IS NULL OR expires_at > %s ) AND ( usage_limit = 0 OR usage_count < usage_limit )";
		$order = 'id ASC';
		if ( $main_only ) {
			$where .= " AND batch = ''";
			$order  = "( coupon_code <> '' ) ASC, id ASC";
		}
		// A few rows only: validate() checks each again (and the partner's status).
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE {$where} ORDER BY {$order} LIMIT 5", $affiliate_id, Db::now() ) );
		foreach ( (array) $rows as $row ) {
			if ( self::validate( $row ) ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Create a code.
	 *
	 * @param int    $affiliate_id Affiliate.
	 * @param string $code         Code (made unique if taken).
	 * @param array  $opts         coupon_code, batch, usage_limit, expires_at.
	 * @return int|\WP_Error
	 */
	public static function create( $affiliate_id, $code, array $opts = array() ) {
		global $wpdb;
		$code = self::normalize( $code );
		if ( strlen( $code ) < 3 ) {
			return new \WP_Error( 'wpac_code_short', __( 'Codes need at least 3 characters.', 'affiliate-captain' ) );
		}
		$base = substr( $code, 0, 58 );
		$i    = 2;
		while ( self::find_by_code( $code ) ) {
			if ( ! empty( $opts['strict'] ) ) {
				return new \WP_Error( 'wpac_code_taken', __( 'This code is already in use.', 'affiliate-captain' ) );
			}
			$code = $base . $i++;
		}
		$coupon = isset( $opts['coupon_code'] ) ? self::normalize_coupon( sanitize_text_field( $opts['coupon_code'] ) ) : '';
		$ok     = $wpdb->insert(
			Db::table( 'partner_codes' ),
			array(
				'affiliate_id' => (int) $affiliate_id,
				'code'         => $code,
				'coupon_code'  => $coupon,
				'batch'        => isset( $opts['batch'] ) ? sanitize_key( $opts['batch'] ) : '',
				'usage_limit'  => isset( $opts['usage_limit'] ) ? max( 0, (int) $opts['usage_limit'] ) : 0,
				'expires_at'   => ! empty( $opts['expires_at'] ) ? gmdate( 'Y-m-d H:i:s', strtotime( $opts['expires_at'] . ' 23:59:59' ) ) : null,
				'status'       => 'active',
				'created_at'   => Db::now(),
			)
		);
		if ( ! $ok ) {
			return new \WP_Error( 'wpac_db', __( 'The code could not be saved.', 'affiliate-captain' ) );
		}
		$id = (int) $wpdb->insert_id;
		Audit::log(
			'code.created',
			'code',
			$id,
			array(
				'affiliate_id' => (int) $affiliate_id,
				'code'         => $code,
				'coupon'       => $coupon,
			)
		);
		return $id;
	}

	/**
	 * Random, hard to guess code like "K7Q3-MX2P" (32 symbols ^ 8 ≈ 10^12, no 0/O/1/I).
	 *
	 * @param string $prefix Optional prefix.
	 * @return string
	 */
	public static function random_code( $prefix = '' ) {
		$alpha = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$rand  = '';
		for ( $i = 0; $i < 8; $i++ ) {
			$rand .= $alpha[ random_int( 0, strlen( $alpha ) - 1 ) ] . ( 3 === $i ? '-' : '' );
		}
		$prefix = self::normalize( $prefix );
		return ( '' !== $prefix ? $prefix . '-' : '' ) . $rand;
	}

	/**
	 * Generate a batch of random codes for one affiliate.
	 *
	 * @param int    $affiliate_id Affiliate.
	 * @param int    $count        Number of codes (max 1000).
	 * @param string $prefix       Prefix.
	 * @param array  $opts         usage_limit, expires_at.
	 * @return array [ batch, created ids ]
	 */
	public static function create_batch( $affiliate_id, $count, $prefix = '', array $opts = array() ) {
		$count  = min( 1000, max( 1, (int) $count ) );
		$prefix = self::normalize( $prefix );
		$batch  = 'b' . gmdate( 'YmdHis' ) . wp_rand( 10, 99 );
		$ids    = array();
		$alpha  = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // No 0/O/1/I.
		for ( $n = 0; $n < $count; $n++ ) {
			$rand = '';
			for ( $i = 0; $i < 8; $i++ ) {
				$rand .= $alpha[ random_int( 0, strlen( $alpha ) - 1 ) ];
			}
			$id = self::create( $affiliate_id, ( $prefix ? $prefix . '-' : '' ) . $rand, array_merge( $opts, array( 'batch' => $batch ) ) );
			if ( ! is_wp_error( $id ) ) {
				$ids[] = $id;
			}
		}
		Audit::log(
			'code.batch_created',
			'affiliate',
			(int) $affiliate_id,
			array(
				'batch' => $batch,
				'count' => count( $ids ),
			)
		);
		return array( $batch, $ids );
	}

	/**
	 * Block/unblock.
	 *
	 * @param int    $id     Code.
	 * @param string $status active|blocked.
	 */
	public static function set_status( $id, $status ) {
		global $wpdb;
		$status = 'blocked' === $status ? 'blocked' : 'active';
		$wpdb->update( Db::table( 'partner_codes' ), array( 'status' => $status ), array( 'id' => (int) $id ) );
		Audit::log( 'code.' . $status, 'code', (int) $id );
	}

	/**
	 * Update the linked coupon.
	 *
	 * @param int    $id     Code.
	 * @param string $coupon Coupon code.
	 */
	public static function set_coupon( $id, $coupon ) {
		global $wpdb;
		$coupon = self::normalize_coupon( sanitize_text_field( $coupon ) );
		$wpdb->update( Db::table( 'partner_codes' ), array( 'coupon_code' => $coupon ), array( 'id' => (int) $id ) );
		Audit::log( 'code.coupon_linked', 'code', (int) $id, array( 'coupon' => $coupon ) );
	}

	/**
	 * Check a coupon before it is linked to a partner code: it must exist in WooCommerce (when
	 * WooCommerce is active) and must not be linked to another active code already.
	 *
	 * @param string $coupon  Coupon code ('' = no link, always fine).
	 * @param int    $code_id Code row being changed (0 = new row).
	 * @return true|\WP_Error
	 */
	public static function check_coupon( $coupon, $code_id = 0 ) {
		$coupon = self::normalize_coupon( sanitize_text_field( (string) $coupon ) );
		if ( '' === $coupon ) {
			return true;
		}
		if ( function_exists( 'wc_get_coupon_id_by_code' ) && ! wc_get_coupon_id_by_code( $coupon ) ) {
			/* translators: %s: coupon code */
			return new \WP_Error( 'wpac_coupon_missing', sprintf( __( 'There is no WooCommerce coupon "%s". Please create the coupon first (Marketing → Coupons) or check the spelling.', 'affiliate-captain' ), $coupon ) );
		}
		$linked = self::find_by_coupon( $coupon, true );
		if ( $linked && (int) $linked->id !== (int) $code_id ) {
			$aff = AffiliateRepository::find( (int) $linked->affiliate_id );
			/* translators: 1: coupon code, 2: partner name */
			return new \WP_Error( 'wpac_coupon_taken', sprintf( __( 'The coupon "%1$s" is already linked to partner "%2$s". Remove that link first.', 'affiliate-captain' ), $coupon, $aff ? $aff->name : '#' . (int) $linked->affiliate_id ) );
		}
		return true;
	}

	/**
	 * Count one usage, only while the usage limit is not reached (atomic, conditional UPDATE;
	 * called inside the booking transaction).
	 *
	 * @param int $id Code.
	 * @return bool False when the limit is reached (nothing counted).
	 */
	public static function increment_usage( $id ) {
		global $wpdb;
		$t = Db::table( 'partner_codes' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET usage_count = usage_count + 1 WHERE id = %d AND (usage_limit = 0 OR usage_count < usage_limit)", $id ) ) > 0;
	}

	/**
	 * Undo a usage counted for a booking that was not made.
	 *
	 * @param int $id Code.
	 */
	public static function decrement_usage( $id ) {
		global $wpdb;
		$t = Db::table( 'partner_codes' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET usage_count = usage_count - 1 WHERE id = %d AND usage_count > 0", $id ) );
	}

	/**
	 * Validate a code row: active, not expired, not over limit, affiliate active.
	 *
	 * @param object|null $row Code row.
	 * @return array|null { affiliate_id, code_id, code }
	 */
	public static function validate( $row ) {
		if ( ! $row || 'active' !== $row->status ) {
			return null;
		}
		if ( $row->expires_at && strtotime( $row->expires_at . ' UTC' ) < time() ) {
			return null;
		}
		if ( (int) $row->usage_limit > 0 && (int) $row->usage_count >= (int) $row->usage_limit ) {
			return null;
		}
		$aff = AffiliateRepository::find( (int) $row->affiliate_id );
		if ( ! $aff || 'active' !== $aff->status || \WPAffiliateCaptain\Privacy\Restriction::is_restricted( (int) $aff->id ) ) {
			return null; // Restricted processing (Art. 18 GDPR): no new attributions.
		}
		return array(
			'affiliate_id' => (int) $row->affiliate_id,
			'code_id'      => (int) $row->id,
			'code'         => $row->code,
		);
	}

	/**
	 * Default resolver for the public `wpac_resolve_partner_code` filter.
	 * Accepts partner codes and vanity slugs.
	 *
	 * @param array|null $resolved Previous value.
	 * @param string     $code     Code or slug.
	 * @param array      $context  Context.
	 * @return array|null
	 */
	public static function filter_resolve( $resolved, $code, $context = array() ) {
		if ( null !== $resolved ) {
			return $resolved;
		}
		$found = self::validate( self::find_by_code( $code ) );
		if ( $found ) {
			return $found;
		}
		// Vanity slug (e.g. ?partner=anna-segelschule) – only for links (not as a typed code
		// at checkout or via REST, so partner names cannot be used to find out codes).
		$context = isset( $context['context'] ) ? (string) $context['context'] : '';
		if ( ! in_array( $context, array( 'link', 'cart', 'welcome' ), true ) ) {
			return null;
		}
		$aff = AffiliateRepository::find_by_slug( $code );
		if ( $aff && 'active' === $aff->status ) {
			$row = self::first_valid( (int) $aff->id );
			return $row ? self::validate( $row ) : null;
		}
		return null;
	}

	/**
	 * Resolve a coupon code (WooCommerce / Voucher Captain) to an affiliate.
	 *
	 * @param array|null $resolved Previous value.
	 * @param string     $coupon   Coupon code.
	 * @return array|null
	 */
	public static function filter_coupon( $resolved, $coupon ) {
		global $wpdb;
		if ( null !== $resolved ) {
			return $resolved;
		}
		$coupon = self::normalize_coupon( (string) $coupon );
		if ( '' === $coupon ) {
			return null;
		}
		$t = Db::table( 'partner_codes' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE coupon_code = %s AND status = 'active' ORDER BY id DESC LIMIT 1", $coupon ) );
		if ( ! $row && strtoupper( $coupon ) === self::normalize( $coupon ) ) {
			// A coupon whose code equals a partner code also counts – only if the coupon has no
			// characters normalize() would drop ("müller" must not match the code "MLLER").
			$row = self::find_by_code( $coupon );
		}
		return self::validate( $row );
	}

	/**
	 * List codes for admin.
	 *
	 * @param array $args affiliate_id, search, limit, offset.
	 * @return array [ rows, total ]
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$t      = Db::table( 'partner_codes' );
		$where  = '1=1';
		$params = array();
		if ( ! empty( $args['affiliate_id'] ) ) {
			$where   .= ' AND affiliate_id = %d';
			$params[] = (int) $args['affiliate_id'];
		}
		if ( ! empty( $args['batch'] ) ) {
			$where   .= ' AND batch = %s';
			$params[] = $args['batch'];
		}
		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( strtoupper( $args['search'] ) ) . '%';
			$where   .= ' AND (code LIKE %s OR coupon_code LIKE %s)';
			$params[] = $like;
			$params[] = '%' . $wpdb->esc_like( strtolower( $args['search'] ) ) . '%';
		}
		return \WPAffiliateCaptain\Core\Paged::fetch( $t, $where, $params, $args, array( 'id', 'code', 'usage_count', 'status' ) );
	}
}
