<?php
/**
 * Notes about add-on features at the end of a settings tab (owner decision S1 of 23.11.2026,
 * SET-AC-05): a grey text line with a "Pro" mark and one sentence – no form field, no stored value,
 * no feature code, no check whether anything was bought. An add-on that provides the feature
 * registers a row with the same id through the filter wpac_settings_rows and replaces the note.
 * The operator can switch all notes off (setting "addon_hints", filter wpac_show_addon_hints).
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Admin;

use WPAffiliateCaptain\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class AddonHints {

	/**
	 * Built-in notes (only for add-on features that are released; see TODO.md SET-AC-05).
	 *
	 * @return array[] { id, tab, title, text, link }
	 */
	public static function notes() {
		$link  = (string) apply_filters( 'wpac_addon_hint_url', '' );
		$notes = array(
			array(
				'id'    => 'payout_method_paypal',
				'tab'   => 'payouts',
				'title' => __( 'PayPal payouts', 'affiliate-captain' ),
				'text'  => __( 'Pay partners automatically with PayPal Payouts. Available with Affiliate Captain Pro.', 'affiliate-captain' ),
			),
			array(
				'id'    => 'portal_design',
				'tab'   => 'promotion',
				'title' => __( 'Partner area design', 'affiliate-captain' ),
				'text'  => __( 'Show the partner area in your own design. Available with Affiliate Captain Pro.', 'affiliate-captain' ),
			),
			array(
				'id'    => 'mail_design',
				'tab'   => 'emails',
				'title' => __( 'E-mail header and footer', 'affiliate-captain' ),
				'text'  => __( 'Add your own header and footer to partner e-mails. Available with Affiliate Captain Pro.', 'affiliate-captain' ),
			),
			array(
				'id'    => 'webhooks',
				'tab'   => 'data',
				'title' => __( 'Webhooks', 'affiliate-captain' ),
				'text'  => __( 'Notify other systems about commissions and payouts. Available with Affiliate Captain Pro.', 'affiliate-captain' ),
			),
			array(
				'id'    => 'extended_api',
				'tab'   => 'data',
				'title' => __( 'Extended REST API', 'affiliate-captain' ),
				'text'  => __( 'Read and manage partners, commissions and payouts through the REST API. Available with Affiliate Captain Pro.', 'affiliate-captain' ),
			),
		);
		foreach ( $notes as $i => $n ) {
			$notes[ $i ]['link'] = $link;
		}
		return $notes;
	}

	/**
	 * Rows of one tab: add-on rows from the filter wpac_settings_rows, and the built-in notes that no
	 * add-on row replaces (unless notes are switched off).
	 *
	 * Row: { id, tab, title, text, link, render }. A row with a callable "render" is an add-on field
	 * (the callback prints the field; the add-on saves it on wpac_settings_saved). A row without
	 * "render" is a note (text only).
	 *
	 * @param string $tab Tab key.
	 * @return array[] [ 'fields' => rows, 'notes' => rows ]
	 */
	public static function rows( $tab ) {
		$given  = (array) apply_filters( 'wpac_settings_rows', array(), $tab );
		$tabs   = \WPAffiliateCaptain\Admin\Pages\SettingsPage::tab_keys();
		$fields = array();
		$notes  = array();
		$ids    = array();
		foreach ( $given as $row ) {
			$row = self::normalize( $row );
			if ( ! $row ) {
				continue;
			}
			// A row on an unknown tab would hide a note and show up nowhere: it is ignored (contract 1.8.1).
			if ( ! isset( $tabs[ $row['tab'] ] ) ) {
				continue;
			}
			$ids[ $row['id'] ] = true; // Replaces a built-in note with this id (on any tab).
			foreach ( $row['replaces'] as $replaced ) {
				$ids[ $replaced ] = true; // …and the notes it names in "replaces" (contract 1.8.1).
			}
			if ( $row['tab'] !== $tab ) {
				continue;
			}
			if ( $row['render'] ) {
				$fields[] = $row;
			} else {
				$notes[] = $row;
			}
		}
		// The switch "addon_hints" only hides the built-in notes; notes of installed add-ons stay (P6-04).
		if ( self::shown() ) {
			foreach ( self::notes() as $n ) {
				if ( $n['tab'] === $tab && ! isset( $ids[ $n['id'] ] ) ) {
					$notes[] = array( 'builtin' => true ) + self::normalize( $n );
				}
			}
		}
		return array(
			'fields' => $fields,
			'notes'  => $notes,
		);
	}

	/**
	 * Whether notes are shown (setting "addon_hints", filter wpac_show_addon_hints).
	 *
	 * @return bool
	 */
	public static function shown() {
		// Only while the add-on is on sale, i.e. a product address is set (decision S5; P6-03).
		$on_sale = '' !== (string) apply_filters( 'wpac_addon_hint_url', '' );
		return (bool) apply_filters( 'wpac_show_addon_hints', (bool) Settings::get( 'addon_hints' ) && $on_sale );
	}

	/**
	 * Clean one row, or null.
	 *
	 * @param mixed $row Row.
	 * @return array|null
	 */
	private static function normalize( $row ) {
		if ( ! is_array( $row ) || empty( $row['id'] ) || empty( $row['title'] ) || ! isset( $row['tab'] ) ) {
			return null;
		}
		return array(
			'id'     => sanitize_key( (string) $row['id'] ),
			'tab'    => sanitize_key( (string) $row['tab'] ),
			'title'  => wp_strip_all_tags( (string) $row['title'] ),
			'text'   => isset( $row['text'] ) ? wp_strip_all_tags( (string) $row['text'] ) : '',
			'link'   => isset( $row['link'] ) ? esc_url_raw( (string) $row['link'] ) : '',
			'render'   => isset( $row['render'] ) && is_callable( $row['render'] ) ? $row['render'] : null,
			'replaces' => isset( $row['replaces'] ) ? array_values( array_filter( array_map( 'sanitize_key', array_map( 'strval', (array) $row['replaces'] ) ) ) ) : array(),
		);
	}

	/**
	 * Print the rows of a tab (inside the settings form, after the core fields).
	 *
	 * @param string $tab Tab key.
	 */
	public static function render( $tab ) {
		$rows = self::rows( $tab );
		if ( $rows['fields'] ) {
			echo '<h2>' . esc_html__( 'Add-ons', 'affiliate-captain' ) . '</h2><table class="form-table" role="presentation">';
			foreach ( $rows['fields'] as $row ) {
				echo '<tr><th scope="row">' . esc_html( $row['title'] ) . '</th><td>';
				call_user_func( $row['render'], $tab );
				if ( '' !== $row['text'] ) {
					echo '<p class="description">' . esc_html( $row['text'] ) . '</p>';
				}
				echo '</td></tr>';
			}
			echo '</table>';
		}
		if ( $rows['notes'] ) {
			$builtin = (bool) array_filter( array_column( $rows['notes'], 'builtin' ) );
			echo '<h2 class="wpac-addon-notes-title">' . ( $builtin ? esc_html__( 'More with Affiliate Captain Pro', 'affiliate-captain' ) : esc_html__( 'Add-ons', 'affiliate-captain' ) ) . '</h2><table class="form-table wpac-addon-notes" role="presentation">';
			foreach ( $rows['notes'] as $row ) {
				// Text only: no form field, no name attribute, no stored value. The Pro mark only on built-in notes.
				$mark = ! empty( $row['builtin'] ) ? '<span class="wpac-pro-mark">' . esc_html__( 'Pro', 'affiliate-captain' ) . '</span> ' : '';
				echo '<tr class="wpac-addon-note"><th scope="row">' . $mark . esc_html( $row['title'] ) . '</th><td><p class="description">' . esc_html( $row['text'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $mark is escaped above.
				if ( '' !== $row['link'] ) {
					echo ' <a href="' . esc_url( $row['link'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Learn more', 'affiliate-captain' ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'affiliate-captain' ) . '</span></a>';
				}
				echo '</p></td></tr>';
			}
			echo '</table>';
		}
	}
}
