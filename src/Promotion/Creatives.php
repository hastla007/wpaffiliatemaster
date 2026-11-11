<?php
/**
 * Versioned creatives (texts and images) with approval.
 *
 * Every change creates a new draft version in the same family; only approved
 * versions are visible to partners, and approving a version archives the
 * previously approved one. Each creative carries a disclosure note
 * (e.g. "Werbung" / "Anzeige") that partners must use.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Promotion;

use WPAffiliateCaptain\Core\Audit;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class Creatives {

	/**
	 * Labels.
	 *
	 * @return array
	 */
	public static function status_labels() {
		return array(
			'draft'    => __( 'Draft (not visible)', 'wp-affiliate-captain' ),
			'approved' => __( 'Approved', 'wp-affiliate-captain' ),
			'archived' => __( 'Archived', 'wp-affiliate-captain' ),
		);
	}

	/**
	 * Find.
	 *
	 * @param int $id ID.
	 * @return object|null
	 */
	public static function find( $id ) {
		global $wpdb;
		$t = Db::table( 'creatives' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) );
	}

	/**
	 * All versions, newest first.
	 *
	 * @return array
	 */
	public static function all() {
		global $wpdb;
		$t = Db::table( 'creatives' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( "SELECT * FROM {$t} ORDER BY family_id DESC, version DESC" );
	}

	/**
	 * Approved creatives visible for a partner group.
	 *
	 * @param int $group_id Group (0 = no group).
	 * @return array
	 */
	public static function approved_for_group( $group_id ) {
		global $wpdb;
		$t = Db::table( 'creatives' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE status = 'approved' AND (group_id = 0 OR group_id = %d) ORDER BY title ASC", (int) $group_id ) );
	}

	/**
	 * Sanitize input.
	 *
	 * @param array $in Raw input.
	 * @return array|\WP_Error
	 */
	public static function sanitize( array $in ) {
		$title = sanitize_text_field( isset( $in['title'] ) ? $in['title'] : '' );
		if ( '' === $title ) {
			return new \WP_Error( 'wpac_title', __( 'Please enter a title.', 'wp-affiliate-captain' ) );
		}
		$type = isset( $in['type'] ) && 'image' === $in['type'] ? 'image' : 'text';
		$data = array(
			'title'         => $title,
			'type'          => $type,
			'content'       => sanitize_textarea_field( isset( $in['content'] ) ? $in['content'] : '' ),
			'attachment_id' => absint( isset( $in['attachment_id'] ) ? $in['attachment_id'] : 0 ),
			'landing_url'   => esc_url_raw( isset( $in['landing_url'] ) ? $in['landing_url'] : '' ),
			'disclosure'    => sanitize_text_field( isset( $in['disclosure'] ) && '' !== trim( $in['disclosure'] ) ? $in['disclosure'] : Settings::get( 'default_disclosure' ) ),
			'group_id'      => absint( isset( $in['group_id'] ) ? $in['group_id'] : 0 ),
		);
		if ( 'image' === $type && ( ! $data['attachment_id'] || ! wp_attachment_is_image( $data['attachment_id'] ) ) ) {
			return new \WP_Error( 'wpac_image', __( 'Please choose an image from the media library.', 'wp-affiliate-captain' ) );
		}
		if ( 'text' === $type && '' === $data['content'] ) {
			return new \WP_Error( 'wpac_content', __( 'Please enter the text.', 'wp-affiliate-captain' ) );
		}
		if ( $data['landing_url'] && wp_parse_url( $data['landing_url'], PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			return new \WP_Error( 'wpac_url', __( 'The target page must be on this website.', 'wp-affiliate-captain' ) );
		}
		return $data;
	}

	/**
	 * Save a new draft version (new family when $family_id is 0).
	 *
	 * @param array  $data      Sanitized data.
	 * @param int    $family_id Family.
	 * @param string $note      Change note.
	 * @return int New version ID.
	 */
	public static function save_draft( array $data, $family_id = 0, $note = '' ) {
		global $wpdb;
		$t       = Db::table( 'creatives' );
		$version = 1;
		if ( $family_id ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$version = 1 + (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(version) FROM {$t} WHERE family_id = %d", $family_id ) );
		}
		$wpdb->insert(
			$t,
			$data + array(
				'family_id'   => (int) $family_id,
				'version'     => $version,
				'status'      => 'draft',
				'change_note' => substr( sanitize_text_field( $note ), 0, 255 ),
				'created_by'  => get_current_user_id(),
				'created_at'  => Db::now(),
			)
		);
		$id = (int) $wpdb->insert_id;
		if ( ! $family_id ) {
			$wpdb->update( $t, array( 'family_id' => $id ), array( 'id' => $id ) );
			$family_id = $id;
		}
		Audit::log(
			'creative.draft',
			'creative',
			$id,
			array(
				'family'  => (int) $family_id,
				'version' => $version,
				'note'    => $note,
			)
		);
		return $id;
	}

	/**
	 * Approve a version; the previously approved version of the family is archived.
	 *
	 * @param int $id Version.
	 * @return true|\WP_Error
	 */
	public static function approve( $id ) {
		global $wpdb;
		$c = self::find( $id );
		if ( ! $c || 'draft' !== $c->status ) {
			return new \WP_Error( 'wpac_state', __( 'Only drafts can be approved.', 'wp-affiliate-captain' ) );
		}
		$t = Db::table( 'creatives' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET status = 'archived' WHERE family_id = %d AND status = 'approved'", $c->family_id ) );
		$wpdb->update(
			$t,
			array(
				'status'      => 'approved',
				'approved_by' => get_current_user_id(),
				'approved_at' => Db::now(),
			),
			array( 'id' => (int) $id )
		);
		Audit::log(
			'creative.approved',
			'creative',
			(int) $id,
			array(
				'family'  => (int) $c->family_id,
				'version' => (int) $c->version,
			)
		);
		return true;
	}

	/**
	 * Withdraw / archive a version.
	 *
	 * @param int $id Version.
	 */
	public static function archive( $id ) {
		global $wpdb;
		$wpdb->update( Db::table( 'creatives' ), array( 'status' => 'archived' ), array( 'id' => (int) $id ) );
		Audit::log( 'creative.archived', 'creative', (int) $id );
	}

	/**
	 * Partner specific text: replaces {link}, {code}, {partner}.
	 *
	 * @param object $creative Creative.
	 * @param string $code     Partner code.
	 * @param string $partner  Partner name.
	 * @return string
	 */
	public static function personalize( $creative, $code, $partner ) {
		return strtr(
			(string) $creative->content,
			array(
				'{link}'    => self::link( $creative, $code ),
				'{code}'    => $code,
				'{partner}' => $partner,
			)
		);
	}

	/**
	 * Partner link of a creative.
	 *
	 * @param object $creative Creative.
	 * @param string $code     Partner code.
	 * @return string
	 */
	public static function link( $creative, $code ) {
		$target = $creative->landing_url ? $creative->landing_url : home_url( '/' );
		return add_query_arg(
			array(
				(string) Settings::get( 'referral_param' ) => $code,
				'utm_source'                              => 'partner',
				'utm_medium'                              => 'affiliate',
				'utm_campaign'                            => 'creative-' . (int) $creative->family_id . '-v' . (int) $creative->version,
			),
			$target
		);
	}
}
