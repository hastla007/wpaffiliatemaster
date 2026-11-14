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

	/** @var int Max characters of a text creative (TEXT column, up to 4 bytes per character). */
	const CONTENT_MAX = 16000;

	/**
	 * Labels.
	 *
	 * @return array
	 */
	public static function status_labels() {
		return array(
			'draft'    => __( 'Draft (not visible)', 'affiliate-captain' ),
			'approved' => __( 'Approved', 'affiliate-captain' ),
			'archived' => __( 'Archived', 'affiliate-captain' ),
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
	 * Version of a creative family.
	 *
	 * @param int $family_id Family.
	 * @param int $version   Version.
	 * @return object|null
	 */
	public static function find_version( $family_id, $version ) {
		global $wpdb;
		$t = Db::table( 'creatives' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE family_id = %d AND version = %d", $family_id, $version ) );
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
			return new \WP_Error( 'wpac_title', __( 'Please enter a title.', 'affiliate-captain' ) );
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
		// Column limits: wpdb refuses the whole INSERT for a longer value (no draft would be saved).
		foreach ( array(
			'title'       => array( 190, __( 'Title', 'affiliate-captain' ) ),
			'content'     => array( self::CONTENT_MAX, __( 'Text', 'affiliate-captain' ) ),
			'landing_url' => array( 255, __( 'Target page', 'affiliate-captain' ) ),
			'disclosure'  => array( 255, __( 'Disclosure note', 'affiliate-captain' ) ),
		) as $field => $limit ) {
			if ( Db::too_long( $data[ $field ], $limit[0] ) ) {
				return new \WP_Error(
					'wpac_too_long_' . $field,
					/* translators: 1: field, 2: number of characters */
					sprintf( __( '%1$s is too long (at most %2$d characters). Nothing was changed.', 'affiliate-captain' ), $limit[1], $limit[0] )
				);
			}
		}
		if ( 'image' === $type && ( ! $data['attachment_id'] || ! wp_attachment_is_image( $data['attachment_id'] ) ) ) {
			return new \WP_Error( 'wpac_image', __( 'Please choose an image from the media library.', 'affiliate-captain' ) );
		}
		if ( 'text' === $type && '' === $data['content'] ) {
			return new \WP_Error( 'wpac_content', __( 'Please enter the text.', 'affiliate-captain' ) );
		}
		if ( $data['landing_url'] && wp_parse_url( $data['landing_url'], PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			return new \WP_Error( 'wpac_url', __( 'The target page must be on this website.', 'affiliate-captain' ) );
		}
		return $data;
	}

	/**
	 * Save a new draft version (new family when $family_id is 0).
	 *
	 * @param array  $data      Sanitized data.
	 * @param int    $family_id Family.
	 * @param string $note      Change note.
	 * @return int|\WP_Error New version ID, or an error when the database refused it (nothing saved,
	 *                      nothing logged).
	 */
	public static function save_draft( array $data, $family_id = 0, $note = '' ) {
		global $wpdb;
		$t       = Db::table( 'creatives' );
		$version = 1;
		if ( $family_id ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$version = 1 + (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(version) FROM {$t} WHERE family_id = %d", $family_id ) );
		}
		$failed = new \WP_Error( 'wpac_creative_db', __( 'The draft could not be saved. Please try again; nothing was changed.', 'affiliate-captain' ) );
		$ok     = $wpdb->insert(
			$t,
			$data + array(
				'family_id'   => (int) $family_id,
				'version'     => $version,
				'status'      => 'draft',
				'change_note' => Db::cut( sanitize_text_field( $note ), 255 ),
				'created_by'  => get_current_user_id(),
				'created_at'  => Db::now(),
			)
		);
		if ( ! $ok ) {
			return $failed;
		}
		$id = (int) $wpdb->insert_id;
		if ( ! $family_id ) {
			if ( false === $wpdb->update( $t, array( 'family_id' => $id ), array( 'id' => $id ) ) ) {
				$wpdb->delete( $t, array( 'id' => $id ) ); // Without a family the version would be orphaned.
				return $failed;
			}
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
			return new \WP_Error( 'wpac_state', __( 'Only drafts can be approved.', 'affiliate-captain' ) );
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
	 * @param string $sub      Sub-ID of the partner (optional).
	 * @return string
	 */
	public static function personalize( $creative, $code, $partner, $sub = '' ) {
		return strtr(
			(string) $creative->content,
			array(
				'{link}'    => self::link( $creative, $code, $sub ),
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
	 * @param string $sub      Sub-ID of the partner (optional; e.g. the placement).
	 * @return string
	 */
	public static function link( $creative, $code, $sub = '' ) {
		$target = $creative->landing_url ? $creative->landing_url : home_url( '/' );
		$args   = array(
			(string) Settings::get( 'referral_param' ) => $code,
			'utm_source'                              => 'partner',
			'utm_medium'                              => 'affiliate',
			'utm_campaign'                            => 'creative-' . (int) $creative->family_id . '-v' . (int) $creative->version,
		);
		$sub = \WPAffiliateCaptain\Tracking\Tracker::clean_sub( $sub );
		if ( '' !== $sub ) {
			$args[ \WPAffiliateCaptain\Tracking\Tracker::sub_param() ] = $sub;
		}
		return add_query_arg( $args, $target );
	}
}
