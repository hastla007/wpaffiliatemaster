<?php
/**
 * Versioned terms acceptance protocol (version + document hash + time).
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Affiliates;

use WPAffiliateCaptain\Core\Audit;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class Agreements {

	/**
	 * Current terms: page, version and SHA-256 of the page content.
	 *
	 * @return array { page_id, version, hash, url }
	 */
	public static function current() {
		$page_id = (int) Settings::get( 'terms_page_id' );
		$post    = $page_id ? get_post( $page_id ) : null;
		return array(
			'page_id' => $page_id,
			'version' => (string) Settings::get( 'terms_version' ),
			'hash'    => $post ? hash( 'sha256', $post->post_title . "\n" . $post->post_content ) : '',
			'url'     => $post ? get_permalink( $post ) : '',
		);
	}

	/**
	 * Record an acceptance (or decline).
	 *
	 * @param int    $affiliate_id Affiliate.
	 * @param string $result       accepted|declined.
	 * @return int
	 */
	public static function record( $affiliate_id, $result = 'accepted' ) {
		global $wpdb;
		$terms = self::current();
		$wpdb->insert(
			Db::table( 'agreements' ),
			array(
				'affiliate_id' => (int) $affiliate_id,
				'version'      => $terms['version'],
				'doc_hash'     => $terms['hash'],
				'result'       => 'declined' === $result ? 'declined' : 'accepted',
				'user_id'      => get_current_user_id(),
				'created_at'   => Db::now(),
			)
		);
		$id = (int) $wpdb->insert_id;
		Audit::log(
			'agreement.' . ( 'declined' === $result ? 'declined' : 'accepted' ),
			'affiliate',
			(int) $affiliate_id,
			array(
				'version' => $terms['version'],
				'hash'    => $terms['hash'],
			)
		);
		return $id;
	}

	/**
	 * Latest accepted agreement of an affiliate.
	 *
	 * @param int $affiliate_id Affiliate.
	 * @return object|null
	 */
	public static function latest( $affiliate_id ) {
		global $wpdb;
		$t = Db::table( 'agreements' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE affiliate_id = %d AND result = 'accepted' ORDER BY id DESC LIMIT 1", $affiliate_id ) );
	}

	/**
	 * Whether the affiliate accepted the current version.
	 *
	 * @param int $affiliate_id Affiliate.
	 * @return bool
	 */
	public static function has_current( $affiliate_id ) {
		$latest = self::latest( $affiliate_id );
		$terms  = self::current();
		return $latest && $latest->version === $terms['version'] && ( '' === $terms['hash'] || $latest->doc_hash === $terms['hash'] );
	}

	/**
	 * All records of an affiliate.
	 *
	 * @param int $affiliate_id Affiliate.
	 * @return array
	 */
	public static function for_affiliate( $affiliate_id ) {
		global $wpdb;
		$t = Db::table( 'agreements' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE affiliate_id = %d ORDER BY id DESC", $affiliate_id ) );
	}
}
