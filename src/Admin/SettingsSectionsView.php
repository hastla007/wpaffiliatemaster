<?php
/**
 * Settings page, tab "Data & maintenance": the optional sections of the settings export and the
 * preview of their import (SET-AC-08a). The forms and actions stay those of the settings export
 * (settings_export, settings_import_apply: nonce, capability and password confirmation).
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Admin;

use WPAffiliateCaptain\Core\SettingsSections;

defined( 'ABSPATH' ) || exit;

final class SettingsSectionsView {

	/** ID of the apply form (the section checkboxes belong to it). */
	const FORM_ID = 'wpac-settings-apply';

	/**
	 * Checkboxes of the export form.
	 */
	public static function export_choices() {
		echo '<fieldset class="wpac-sections-choice"><legend>' . esc_html__( 'Also export (optional):', 'affiliate-captain' ) . '</legend>';
		foreach ( SettingsSections::labels() as $section => $label ) {
			echo '<label><input type="checkbox" name="sections[]" value="' . esc_attr( $section ) . '"> ' . esc_html( $label ) . '</label> ';
		}
		echo '<p class="description">' . esc_html__( 'Rules and tier plans for single partners, partners and commissions are never exported. On import, items are matched by name (groups), by scope, group, products and note (rules), by group (tier plans) and by template and language (e-mail templates); nothing is deleted.', 'affiliate-captain' ) . '</p></fieldset>';
	}

	/**
	 * Preview of the sections of a checked import, with one checkbox per section (part of the
	 * apply form through the form attribute).
	 *
	 * @param array $check Result of SettingsTransfer::preview().
	 */
	public static function preview( array $check ) {
		if ( empty( $check['sections'] ) || ! is_array( $check['sections'] ) ) {
			return;
		}
		$labels = SettingsSections::labels();
		$status = array(
			'new'       => __( 'New', 'affiliate-captain' ),
			'changed'   => __( 'Changed', 'affiliate-captain' ),
			'unchanged' => __( 'Unchanged', 'affiliate-captain' ),
			'error'     => __( 'Not imported', 'affiliate-captain' ),
		);
		$fields = array(
			'description'       => __( 'Description', 'affiliate-captain' ),
			'agreement_version' => __( 'Terms version', 'affiliate-captain' ),
			'terms_page_id'     => __( 'Terms page', 'affiliate-captain' ),
			'public'            => __( 'Selectable in the application form', 'affiliate-captain' ),
			'fields'            => __( 'Application fields', 'affiliate-captain' ),
			'rate'              => __( 'Rate', 'affiliate-captain' ),
			'max_amount_minor'  => __( 'Maximum per line', 'affiliate-captain' ),
			'min_base_minor'    => __( 'Minimum line amount', 'affiliate-captain' ),
			'valid_from'        => __( 'Valid from', 'affiliate-captain' ),
			'valid_until'       => __( 'Valid until', 'affiliate-captain' ),
			'priority'          => __( 'Priority', 'affiliate-captain' ),
			'active'            => __( 'Status', 'affiliate-captain' ),
			'basis'             => __( 'Basis', 'affiliate-captain' ),
			'mode'              => __( 'Mode', 'affiliate-captain' ),
			'steps'             => __( 'Steps', 'affiliate-captain' ),
			'note'              => __( 'Note', 'affiliate-captain' ),
			'subject'           => __( 'Subject', 'affiliate-captain' ),
			'body'              => __( 'Text', 'affiliate-captain' ),
			'layout'            => __( 'Layout', 'affiliate-captain' ),
		);
		echo '<input type="hidden" name="import_sections_shown" value="1" form="' . esc_attr( self::FORM_ID ) . '">';
		foreach ( $check['sections'] as $section => $data ) {
			$c     = $data['counts'];
			$label = isset( $labels[ $section ] ) ? $labels[ $section ] : $section;
			$id    = 'wpac-section-' . sanitize_html_class( (string) $section );
			echo '<h3 id="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</h3>';
			/* translators: 1: new items, 2: changed items, 3: unchanged items, 4: rejected items */
			echo '<p>' . esc_html( sprintf( __( '%1$d new, %2$d changed, %3$d unchanged, %4$d not imported.', 'affiliate-captain' ), $c['new'], $c['changed'], $c['unchanged'], $c['error'] ) ) . '</p>';
			if ( $c['new'] || $c['changed'] ) {
				echo '<p><label><input type="checkbox" name="import_sections[]" value="' . esc_attr( $section ) . '" form="' . esc_attr( self::FORM_ID ) . '" checked aria-describedby="' . esc_attr( $id ) . '"> ' . esc_html__( 'Import this section', 'affiliate-captain' ) . '</label></p>';
			}
			$rows = array_filter(
				$data['items'],
				static function ( $item ) {
					return 'unchanged' !== $item['status'];
				}
			);
			if ( $rows ) {
				echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Status', 'affiliate-captain' ) . '</th><th scope="col">' . esc_html__( 'Entry', 'affiliate-captain' ) . '</th><th scope="col">' . esc_html__( 'Changes', 'affiliate-captain' ) . '</th></tr></thead><tbody>';
				foreach ( $rows as $item ) {
					$lines = array();
					foreach ( $item['changes'] as $field => $pair ) {
						$lines[] = ( isset( $fields[ $field ] ) ? $fields[ $field ] : $field ) . ': ' . $pair[0] . ' → ' . $pair[1];
					}
					if ( '' !== $item['message'] ) {
						$lines[] = $item['message'];
					}
					echo '<tr><td>' . esc_html( $status[ $item['status'] ] ) . '</td><td>' . esc_html( $item['label'] ) . '</td><td>' . nl2br( esc_html( implode( "\n", $lines ) ) ) . '</td></tr>';
				}
				echo '</tbody></table>';
			}
		}
	}
}
