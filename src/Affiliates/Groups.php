<?php
/**
 * Partner groups (e.g. "training partner", "content partner").
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Affiliates;

use WPAffiliateCaptain\Core\Audit;
use WPAffiliateCaptain\Core\Db;

defined( 'ABSPATH' ) || exit;

final class Groups {

	/**
	 * All groups.
	 *
	 * @return array
	 */
	public static function all() {
		global $wpdb;
		$t = Db::table( 'partner_groups' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( "SELECT * FROM {$t} ORDER BY name ASC" );
	}

	/**
	 * id => name.
	 *
	 * @return array
	 */
	public static function options() {
		$out = array();
		foreach ( self::all() as $g ) {
			$out[ (int) $g->id ] = $g->name;
		}
		return $out;
	}

	/**
	 * Create a group.
	 *
	 * @param string $name              Name.
	 * @param string $description       Description.
	 * @param string $agreement_version Optional group specific terms version.
	 * @return int
	 */
	public static function create( $name, $description = '', $agreement_version = '' ) {
		global $wpdb;
		$wpdb->insert(
			Db::table( 'partner_groups' ),
			array(
				'name'              => sanitize_text_field( $name ),
				'description'       => sanitize_textarea_field( $description ),
				'agreement_version' => sanitize_text_field( $agreement_version ),
				'status'            => 'active',
				'created_at'        => Db::now(),
			)
		);
		$id = (int) $wpdb->insert_id;
		Audit::log( 'group.created', 'group', $id );
		return $id;
	}

	/**
	 * Find a group.
	 *
	 * @param int $id ID.
	 * @return object|null
	 */
	public static function find( $id ) {
		global $wpdb;
		if ( ! $id ) {
			return null;
		}
		$t = Db::table( 'partner_groups' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) );
	}

	/**
	 * Groups that applicants can choose.
	 *
	 * @return array
	 */
	public static function public_groups() {
		return array_values(
			array_filter(
				self::all(),
				static function ( $g ) {
					return (int) $g->public && 'active' === $g->status;
				}
			)
		);
	}

	/**
	 * Update a group's terms, visibility and application fields.
	 *
	 * @param int   $id   ID.
	 * @param array $data name, description, terms_page_id, agreement_version, public, fields (text).
	 * @return true|\WP_Error
	 */
	public static function update( $id, array $data ) {
		global $wpdb;
		$fields = self::parse_fields( isset( $data['fields'] ) ? (string) $data['fields'] : '' );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		$row = array(
			'name'              => sanitize_text_field( $data['name'] ),
			'description'       => sanitize_textarea_field( isset( $data['description'] ) ? $data['description'] : '' ),
			'terms_page_id'     => isset( $data['terms_page_id'] ) ? absint( $data['terms_page_id'] ) : 0,
			'agreement_version' => sanitize_text_field( isset( $data['agreement_version'] ) ? $data['agreement_version'] : '' ),
			'public'            => empty( $data['public'] ) ? 0 : 1,
			'fields'            => wp_json_encode( $fields ),
		);
		$wpdb->update( Db::table( 'partner_groups' ), $row, array( 'id' => (int) $id ) );
		Audit::log( 'group.updated', 'group', (int) $id, $row );
		return true;
	}

	/**
	 * Parse application field definitions, one per line: key|Label|type|required
	 * (type: text, textarea, url, number, checkbox).
	 *
	 * @param string $text Definition.
	 * @return array|\WP_Error
	 */
	public static function parse_fields( $text ) {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$parts = array_map( 'trim', explode( '|', $line ) );
			$key   = sanitize_key( $parts[0] );
			if ( '' === $key || empty( $parts[1] ) ) {
				/* translators: %s: line */
				return new \WP_Error( 'wpac_fields', sprintf( __( 'Invalid field definition: %s', 'wp-affiliate-captain' ), $line ) );
			}
			$type  = isset( $parts[2] ) && in_array( $parts[2], array( 'text', 'textarea', 'url', 'number', 'checkbox' ), true ) ? $parts[2] : 'text';
			$out[] = array(
				'key'      => $key,
				'label'    => sanitize_text_field( $parts[1] ),
				'type'     => $type,
				'required' => isset( $parts[3] ) && in_array( strtolower( $parts[3] ), array( 'required', 'pflicht', '1', 'yes', 'ja' ), true ),
			);
		}
		return $out;
	}

	/**
	 * Field definitions of a group.
	 *
	 * @param object|null $group Group.
	 * @return array
	 */
	public static function fields( $group ) {
		$fields = $group ? json_decode( (string) $group->fields, true ) : array();
		return is_array( $fields ) ? $fields : array();
	}

	/**
	 * Field definitions as editable text.
	 *
	 * @param object $group Group.
	 * @return string
	 */
	public static function fields_text( $group ) {
		$lines = array();
		foreach ( self::fields( $group ) as $f ) {
			$lines[] = $f['key'] . '|' . $f['label'] . '|' . $f['type'] . ( $f['required'] ? '|required' : '' );
		}
		return implode( "\n", $lines );
	}

	/**
	 * Delete a group (affiliates fall back to no group).
	 *
	 * @param int $id ID.
	 */
	public static function delete( $id ) {
		global $wpdb;
		$wpdb->delete( Db::table( 'partner_groups' ), array( 'id' => (int) $id ) );
		$wpdb->update( Db::table( 'affiliates' ), array( 'group_id' => 0 ), array( 'group_id' => (int) $id ) );
		AffiliateRepository::clear_cache();
		Audit::log( 'group.deleted', 'group', (int) $id );
	}
}
