<?php
/**
 * Admin: versioned creatives with approval.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Admin\Pages;

use WPAffiliateCaptain\Admin\Admin;
use WPAffiliateCaptain\Affiliates\Groups;
use WPAffiliateCaptain\Promotion\Creatives;

defined( 'ABSPATH' ) || exit;

final class CreativesPage {

	/**
	 * Handle actions.
	 *
	 * @param string $do Action.
	 */
	public static function handle( $do ) {
		$id = absint( Admin::post( 'id', 0 ) );
		switch ( $do ) {
			case 'creative_save':
				$data = Creatives::sanitize(
					array(
						'title'         => Admin::post( 'title' ),
						'type'          => Admin::post( 'type' ),
						'content'       => Admin::post( 'content' ),
						'attachment_id' => Admin::post( 'attachment_id' ),
						'landing_url'   => Admin::post( 'landing_url' ),
						'disclosure'    => Admin::post( 'disclosure' ),
						'group_id'      => Admin::post( 'group_id' ),
					)
				);
				if ( is_wp_error( $data ) ) {
					Admin::back( $data->get_error_message(), 'error' );
				}
				$family = absint( Admin::post( 'family_id', 0 ) );
				$saved  = Creatives::save_draft( $data, $family, sanitize_text_field( Admin::post( 'change_note' ) ) );
				if ( is_wp_error( $saved ) ) {
					Admin::back( $saved->get_error_message(), 'error' );
				}
				Admin::back( __( 'Draft saved. Approve it to make it visible to partners.', 'affiliate-captain' ), 'success', Admin::url( 'wpac-creatives' ) );
				break;
			case 'creative_approve':
				$r = Creatives::approve( $id );
				if ( is_wp_error( $r ) ) {
					Admin::back( $r->get_error_message(), 'error' );
				}
				Admin::back( __( 'Creative approved and visible to partners.', 'affiliate-captain' ) );
				break;
			case 'creative_archive':
				Creatives::archive( $id );
				Admin::back( __( 'Creative archived.', 'affiliate-captain' ) );
				break;
		}
	}

	/**
	 * Render.
	 */
	public static function render() {
		$groups = Groups::options();
		$labels = Creatives::status_labels();
		$edit   = absint( Admin::get( 'edit' ) ) ? Creatives::find( absint( Admin::get( 'edit' ) ) ) : null;
		wp_enqueue_media();

		echo '<div class="wrap"><h1>' . esc_html__( 'Creatives', 'affiliate-captain' ) . '</h1>';
		Admin::notice();
		echo '<p class="description">' . esc_html__( 'Only approved versions are shown to partners. Every change creates a new version that needs approval. Placeholders in texts: {link}, {code}, {partner}.', 'affiliate-captain' ) . '</p>';

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Creative', 'affiliate-captain' ) . '</th><th>' . esc_html__( 'Version', 'affiliate-captain' ) . '</th><th>' . esc_html__( 'Group', 'affiliate-captain' ) . '</th><th>' . esc_html__( 'Disclosure', 'affiliate-captain' ) . '</th><th>' . esc_html__( 'Status', 'affiliate-captain' ) . '</th><th></th></tr></thead><tbody>';
		$rows = Creatives::all();
		if ( ! $rows ) {
			echo '<tr><td colspan="6">' . esc_html__( 'No creatives yet.', 'affiliate-captain' ) . '</td></tr>';
		}
		foreach ( $rows as $c ) {
			$preview = 'image' === $c->type ? wp_get_attachment_image( (int) $c->attachment_id, array( 80, 80 ) ) : '<small>' . esc_html( wp_trim_words( $c->content, 20 ) ) . '</small>';
			$user    = $c->approved_by ? get_userdata( (int) $c->approved_by ) : null;
			printf(
				'<tr class="%s"><td><strong>%s</strong><br>%s</td><td>v%d%s</td><td>%s</td><td>%s</td><td>%s%s</td><td>',
				'archived' === $c->status ? 'wpac-inactive' : '',
				esc_html( $c->title ),
				$preview, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				(int) $c->version,
				$c->change_note ? '<br><small>' . esc_html( $c->change_note ) . '</small>' : '',
				esc_html( $c->group_id && isset( $groups[ (int) $c->group_id ] ) ? $groups[ (int) $c->group_id ] : __( 'All', 'affiliate-captain' ) ),
				esc_html( $c->disclosure ),
				Admin::badge( 'draft' === $c->status ? 'pending' : ( 'approved' === $c->status ? 'active' : 'paused' ), $labels[ $c->status ] ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				$user ? '<br><small>' . esc_html( $user->display_name . ', ' . Admin::date( $c->approved_at ) ) . '</small>' : ''
			);
			echo '<a href="' . esc_url( Admin::url( 'wpac-creatives', array( 'edit' => $c->id ) ) ) . '">' . esc_html__( 'New version', 'affiliate-captain' ) . '</a> ';
			if ( 'draft' === $c->status ) {
				Admin::form_open( 'creative_approve', 'wpac-inline' );
				echo '<input type="hidden" name="id" value="' . (int) $c->id . '"><button class="button button-small button-primary">' . esc_html__( 'Approve', 'affiliate-captain' ) . '</button></form> ';
			}
			if ( 'archived' !== $c->status ) {
				Admin::form_open( 'creative_archive', 'wpac-inline' );
				echo '<input type="hidden" name="id" value="' . (int) $c->id . '"><button class="button-link wpac-danger" data-wpac-confirm="' . esc_attr__( 'Archive this creative? Partners will no longer see it.', 'affiliate-captain' ) . '">' . esc_html__( 'Archive', 'affiliate-captain' ) . '</button></form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		$v = static function ( $k, $d = '' ) use ( $edit ) {
			return $edit ? (string) $edit->$k : $d;
		};
		echo '<h2>' . esc_html( $edit ? sprintf( /* translators: 1: title, 2: version */ __( 'New version of "%1$s" (based on v%2$d)', 'affiliate-captain' ), $edit->title, $edit->version ) : __( 'Add creative', 'affiliate-captain' ) ) . '</h2>';
		Admin::form_open( 'creative_save' );
		echo '<input type="hidden" name="family_id" value="' . (int) ( $edit ? $edit->family_id : 0 ) . '"><table class="form-table" role="presentation">';
		echo '<tr><th><label for="wpac-cr-title">' . esc_html__( 'Title', 'affiliate-captain' ) . '</label></th><td><input type="text" id="wpac-cr-title" name="title" value="' . esc_attr( $v( 'title' ) ) . '" class="regular-text" required maxlength="190"></td></tr>';
		echo '<tr><th><label for="wpac-cr-type">' . esc_html__( 'Type', 'affiliate-captain' ) . '</label></th><td><select id="wpac-cr-type" name="type"><option value="text">' . esc_html__( 'Text', 'affiliate-captain' ) . '</option><option value="image" ' . selected( $v( 'type' ), 'image', false ) . '>' . esc_html__( 'Image / banner', 'affiliate-captain' ) . '</option></select></td></tr>';
		echo '<tr><th><label for="wpac-cr-content">' . esc_html__( 'Text', 'affiliate-captain' ) . '</label></th><td><textarea id="wpac-cr-content" name="content" rows="5" class="large-text" maxlength="' . (int) Creatives::CONTENT_MAX . '">' . esc_textarea( $v( 'content' ) ) . '</textarea><p class="description">' . esc_html__( 'For images: optional suggested caption.', 'affiliate-captain' ) . '</p></td></tr>';
		// The image is chosen in the media library and shown as a preview, not typed as an ID (R5-O48).
		$image    = (int) $v( 'attachment_id', '0' );
		$thumb    = $image && function_exists( 'wp_get_attachment_image' ) ? wp_get_attachment_image( $image, 'thumbnail', false, array( 'id' => 'wpac-attachment-preview' ) ) : '';
		$no_image = '<span id="wpac-attachment-preview" class="description">' . esc_html__( 'No image chosen.', 'affiliate-captain' ) . '</span>';
		echo '<tr data-wpac-depends="type=image"><th scope="row">' . esc_html__( 'Image', 'affiliate-captain' ) . '</th><td><input type="hidden" name="attachment_id" id="wpac-attachment" value="' . esc_attr( $image ? (string) $image : '' ) . '"><div class="wpac-attachment">' . ( $thumb ? $thumb : $no_image ) . '</div><p><button type="button" class="button" id="wpac-pick" data-wpac-none="' . esc_attr__( 'No image chosen.', 'affiliate-captain' ) . '">' . esc_html__( 'Choose from media library', 'affiliate-captain' ) . '</button> <button type="button" class="button-link" id="wpac-unpick"' . ( $image ? '' : ' hidden' ) . '>' . esc_html__( 'Remove image', 'affiliate-captain' ) . '</button></p></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_attachment_image() escapes.
		echo '<tr><th><label for="wpac-cr-landing">' . esc_html__( 'Target page', 'affiliate-captain' ) . '</label></th><td><input type="url" id="wpac-cr-landing" name="landing_url" dir="ltr" value="' . esc_attr( $v( 'landing_url' ) ) . '" class="regular-text" placeholder="' . esc_attr( home_url( '/' ) ) . '" maxlength="255"></td></tr>';
		echo '<tr><th><label for="wpac-cr-disclosure">' . esc_html__( 'Disclosure note', 'affiliate-captain' ) . '</label></th><td><input type="text" id="wpac-cr-disclosure" name="disclosure" value="' . esc_attr( $v( 'disclosure', (string) \WPAffiliateCaptain\Core\Settings::get( 'default_disclosure' ) ) ) . '" class="regular-text" maxlength="255"><p class="description">' . esc_html__( 'How partners must label posts, e.g. "Advertisement" or "Sponsored".', 'affiliate-captain' ) . '</p></td></tr>';
		echo '<tr><th><label for="wpac-cr-group">' . esc_html__( 'Visible for', 'affiliate-captain' ) . '</label></th><td><select id="wpac-cr-group" name="group_id"><option value="0">' . esc_html__( 'All partners', 'affiliate-captain' ) . '</option>';
		foreach ( $groups as $gid => $name ) {
			printf( '<option value="%d" %s>%s</option>', (int) $gid, selected( (int) $v( 'group_id', '0' ), $gid, false ), esc_html( $name ) );
		}
		echo '</select></td></tr>';
		echo '<tr><th><label for="wpac-cr-note">' . esc_html__( 'Change note', 'affiliate-captain' ) . '</label></th><td><input type="text" id="wpac-cr-note" name="change_note" class="regular-text"></td></tr></table>';
		submit_button( __( 'Save draft', 'affiliate-captain' ) );
		echo '</form>';
		// The media picker is in assets/js/admin.js (no inline script: CSP, script delay plugins).
		echo '</div>';
	}
}
