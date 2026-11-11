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
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE code = %s", $code ) );
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
		foreach ( self::for_affiliate( $affiliate_id ) as $c ) {
			if ( '' === $c->batch && '' === $c->coupon_code && self::validate( $c ) ) {
				return $c->code;
			}
		}
		foreach ( self::for_affiliate( $affiliate_id ) as $c ) {
			if ( '' === $c->batch && self::validate( $c ) ) {
				return $c->code;
			}
		}
		return '';
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
			return new \WP_Error( 'wpac_code_short', __( 'Codes need at least 3 characters.', 'wp-affiliate-captain' ) );
		}
		$base = substr( $code, 0, 58 );
		$i    = 2;
		while ( self::find_by_code( $code ) ) {
			if ( ! empty( $opts['strict'] ) ) {
				return new \WP_Error( 'wpac_code_taken', __( 'This code is already in use.', 'wp-affiliate-captain' ) );
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
			return new \WP_Error( 'wpac_db', __( 'The code could not be saved.', 'wp-affiliate-captain' ) );
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
	 * Count one usage (after a persisted attribution).
	 *
	 * @param int $id Code.
	 */
	public static function increment_usage( $id ) {
		global $wpdb;
		$t = Db::table( 'partner_codes' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET usage_count = usage_count + 1 WHERE id = %d", $id ) );
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
		if ( ! $aff || 'active' !== $aff->status ) {
			return null;
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
		// Vanity slug (e.g. ?partner=anna-segelschule) – resolves to the first active code.
		$aff = AffiliateRepository::find_by_slug( $code );
		if ( $aff && 'active' === $aff->status ) {
			foreach ( self::for_affiliate( (int) $aff->id ) as $row ) {
				$valid = self::validate( $row );
				if ( $valid ) {
					return $valid;
				}
			}
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
		if ( ! $row ) {
			// A coupon whose code equals a partner code also counts.
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
		$count_sql = "SELECT COUNT(*) FROM {$t} WHERE {$where}";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$total    = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );
		$params[] = isset( $args['limit'] ) ? (int) $args['limit'] : 50;
		$params[] = isset( $args['offset'] ) ? (int) $args['offset'] : 0;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", $params ) );
		return array( $rows, $total );
	}
}
