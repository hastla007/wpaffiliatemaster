<?php
/**
 * Admin: e-mail templates (subject and text per mail type with placeholders, reset to the
 * built-in text, test mail to the current user, plain-text or HTML layout).
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Admin\Pages;

use WPAffiliateCaptain\Admin\Admin;
use WPAffiliateCaptain\Affiliates\Notifications;
use WPAffiliateCaptain\Core\Capabilities;
use WPAffiliateCaptain\Core\Locale;

defined( 'ABSPATH' ) || exit;

final class MailTemplatesPage {

	/** Page slug. */
	const SLUG = 'wpac-mail-templates';

	/**
	 * Menu entry (added by Admin::menu()).
	 */
	public static function menu() {
		add_submenu_page( 'wpac', __( 'E-mail templates', 'affiliate-captain' ), __( 'E-mail templates', 'affiliate-captain' ), Capabilities::MANAGE_PROGRAM, self::SLUG, array( __CLASS__, 'render' ) );
	}

	/**
	 * Actions (routed by Admin::dispatch() with nonce and the capability MANAGE_PROGRAM).
	 *
	 * @param string $do Action.
	 */
	public static function handle( $do ) {
		$key  = sanitize_key( (string) Admin::post( 'template' ) );
		$defs = Notifications::template_defs();
		if ( 'mail_layout_save' === $do ) {
			Notifications::set_layout( 'html' === Admin::post( 'layout' ) ? 'html' : 'text' );
			Admin::back( __( 'Layout saved.', 'affiliate-captain' ), 'success', Admin::url( self::SLUG ) );
		}
		if ( ! isset( $defs[ $key ] ) ) {
			Admin::back( __( 'Unknown e-mail template.', 'affiliate-captain' ), 'error', Admin::url( self::SLUG ) );
		}
		$lang = self::lang( (string) Admin::post( 'lang' ) );
		$page = Admin::url( self::SLUG, array_filter( array( 'template' => $key, 'lang' => $lang ) ) );
		switch ( $do ) {
			case 'mail_template_save':
				$result = Notifications::save_template( $key, (string) Admin::post( 'subject' ), (string) Admin::post( 'body' ), $lang );
				if ( is_wp_error( $result ) ) {
					Admin::keep_form(
						'mail_template',
						array(
							'template' => $key,
							'lang'     => $lang,
							'subject'  => sanitize_text_field( (string) Admin::post( 'subject' ) ),
							'body'     => sanitize_textarea_field( (string) Admin::post( 'body' ) ),
						)
					);
					Admin::back( $result->get_error_message(), 'error', $page );
				}
				Admin::back( __( 'Template saved.', 'affiliate-captain' ), 'success', $page );
				break;
			case 'mail_template_reset':
				Notifications::reset_template( $key, $lang );
				Admin::back( '' !== $lang ? __( 'The text of the site language is used again for this language.', 'affiliate-captain' ) : __( 'The built-in text is used again.', 'affiliate-captain' ), 'success', $page );
				break;
			case 'mail_template_test':
				$user   = get_userdata( get_current_user_id() );
				$email  = $user ? (string) $user->user_email : '';
				$result = Notifications::send_test( $key, $email, '' !== $lang ? $lang : Locale::site() );
				if ( is_wp_error( $result ) ) {
					Admin::back( $result->get_error_message(), 'error', $page );
				}
				Admin::back(
					$result
						/* translators: %s: e-mail address */
						? sprintf( __( 'Test e-mail sent to %s.', 'affiliate-captain' ), $email )
						: __( 'The test e-mail could not be sent. Please check the mail log or your SMTP settings.', 'affiliate-captain' ),
					$result ? 'success' : 'error',
					$page
				);
				break;
		}
	}

	/**
	 * Language of a template being edited: '' for the site language, else an installed locale (SET-AC-17).
	 *
	 * @param string $lang Requested locale.
	 * @return string
	 */
	public static function lang( $lang ) {
		$lang = (string) $lang;
		return '' !== $lang && Locale::site() !== $lang && isset( Locale::installed()[ $lang ] ) ? $lang : '';
	}

	/**
	 * Render the list or the editor of one template.
	 */
	public static function render() {
		if ( ! current_user_can( Capabilities::MANAGE_PROGRAM ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'affiliate-captain' ), 403 );
		}
		$defs = Notifications::template_defs();
		$key  = sanitize_key( Admin::get( 'template' ) );
		echo '<div class="wrap"><h1>' . esc_html__( 'E-mail templates', 'affiliate-captain' ) . '</h1>';
		Admin::notice();
		if ( isset( $defs[ $key ] ) ) {
			self::render_editor( $key, $defs[ $key ] );
		} else {
			self::render_list( $defs );
		}
		echo '</div>';
	}

	/**
	 * Overview: layout and all templates.
	 *
	 * @param array $defs Template definitions.
	 */
	private static function render_list( array $defs ) {
		echo '<p>' . esc_html__( 'Adjust the e-mails your partners receive, e.g. to address them informally. Placeholders in curly brackets are replaced with the values of the partner. Templates you have not changed follow the language of the partner; changed templates are sent as you wrote them.', 'affiliate-captain' ) . '</p>';
		if ( count( Locale::installed() ) > 1 ) {
			echo '<p>' . esc_html__( 'With several installed languages you can store a text per language: partners get the text of their language (partner profile, otherwise their user account), otherwise the text of the site language.', 'affiliate-captain' ) . '</p>';
		}
		$settings = Notifications::template_settings();
		Admin::form_open( 'mail_layout_save' );
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Layout', 'affiliate-captain' ) . '</legend><p><strong>' . esc_html__( 'Layout', 'affiliate-captain' ) . '</strong></p>';
		echo '<p><label><input type="radio" name="layout" value="text"' . checked( 'text', $settings['layout'], false ) . '> ' . esc_html__( 'Plain text', 'affiliate-captain' ) . '</label><br>';
		echo '<label><input type="radio" name="layout" value="html"' . checked( 'html', $settings['layout'], false ) . '> ' . esc_html__( 'Simple HTML layout (with a plain-text version for e-mail programs without HTML)', 'affiliate-captain' ) . '</label></p></fieldset>';
		submit_button( __( 'Save layout', 'affiliate-captain' ), 'secondary', 'submit', false );
		echo '</form>';

		echo '<table class="widefat striped" style="margin-top:1.5em"><thead><tr><th scope="col">' . esc_html__( 'E-mail', 'affiliate-captain' ) . '</th><th scope="col">' . esc_html__( 'Text', 'affiliate-captain' ) . '</th><th scope="col"><span class="screen-reader-text">' . esc_html__( 'Actions', 'affiliate-captain' ) . '</span></th></tr></thead><tbody>';
		foreach ( $defs as $key => $def ) {
			$custom = null !== Notifications::stored_template( $key, '' );
			echo '<tr><td>' . esc_html( $def['label'] ) . '</td>';
			echo '<td>' . ( $custom ? Admin::badge( 'active', __( 'Changed', 'affiliate-captain' ) ) : esc_html__( 'Built-in text', 'affiliate-captain' ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
			/* translators: %s: name of the e-mail */
			echo '<td><a href="' . esc_url( Admin::url( self::SLUG, array( 'template' => $key ) ) ) . '" aria-label="' . esc_attr( sprintf( __( 'Edit: %s', 'affiliate-captain' ), $def['label'] ) ) . '">' . esc_html__( 'Edit', 'affiliate-captain' ) . '</a></td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Developers can still change every e-mail with the filter wpac_mail; it runs after the template.', 'affiliate-captain' ) . '</p>';
	}

	/**
	 * Editor of one template.
	 *
	 * @param string $key Template key.
	 * @param array  $def Definition.
	 */
	private static function render_editor( $key, array $def ) {
		$lang = self::lang( Admin::get( 'lang' ) );
		// The text shown: stored for this language, else the one in effect for it (written in that language).
		$switched = Locale::switch_to( '' !== $lang ? $lang : Locale::site() );
		try {
			list( $subject, $body ) = Notifications::template( $key, '' !== $lang ? $lang : '' );
		} finally {
			Locale::restore( $switched );
		}
		$kept = Admin::kept_form( 'mail_template' );
		if ( isset( $kept['template'] ) && $kept['template'] === $key && ( isset( $kept['lang'] ) ? (string) $kept['lang'] : '' ) === $lang ) {
			$subject = (string) $kept['subject'];
			$body    = (string) $kept['body'];
		}
		$custom = null !== Notifications::stored_template( $key, $lang );
		echo '<p><a href="' . esc_url( Admin::url( self::SLUG ) ) . '">&larr; ' . esc_html__( 'All e-mail templates', 'affiliate-captain' ) . '</a></p>';
		echo '<h2>' . esc_html( $def['label'] ) . '</h2>';
		$langs = Locale::installed();
		if ( count( $langs ) > 1 ) {
			$links = array();
			foreach ( $langs as $locale => $name ) {
				$value   = Locale::site() === $locale ? '' : $locale;
				$current = $value === $lang;
				$stored  = null !== Notifications::stored_template( $key, $value );
				$links[] = '<a href="' . esc_url( Admin::url( self::SLUG, array_filter( array( 'template' => $key, 'lang' => $value ) ) ) ) . '"' . ( $current ? ' aria-current="page" style="font-weight:600"' : '' ) . '>' . esc_html( $name ) . '</a>' . ( $stored ? ' ✓' : '' );
			}
			echo '<nav aria-label="' . esc_attr__( 'Language of the template', 'affiliate-captain' ) . '"><p>' . esc_html__( 'Language:', 'affiliate-captain' ) . ' ' . implode( ' | ', $links ) . '</p></nav>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- links escaped above.
		}
		if ( '' !== $lang ) {
			echo '<p>' . esc_html( $custom ? __( 'A text is stored for this language.', 'affiliate-captain' ) : __( 'No text is stored for this language: partners of this language get the text of the site language (a changed one) or the built-in text in their language.', 'affiliate-captain' ) ) . '</p>';
		} else {
			echo '<p>' . esc_html( $custom ? __( 'This template was changed.', 'affiliate-captain' ) : __( 'The built-in text is used.', 'affiliate-captain' ) ) . '</p>';
		}

		Admin::form_open( 'mail_template_save' );
		echo '<input type="hidden" name="template" value="' . esc_attr( $key ) . '"><input type="hidden" name="lang" value="' . esc_attr( $lang ) . '">';
		echo '<p><label for="wpac_tpl_subject"><strong>' . esc_html__( 'Subject', 'affiliate-captain' ) . '</strong></label><br><input type="text" class="large-text" id="wpac_tpl_subject" name="subject" required maxlength="' . (int) Notifications::TEMPLATE_SUBJECT_MAX . '" value="' . esc_attr( $subject ) . '"></p>';
		echo '<p><label for="wpac_tpl_body"><strong>' . esc_html__( 'Text', 'affiliate-captain' ) . '</strong></label><br><textarea class="large-text code" rows="14" id="wpac_tpl_body" name="body" required maxlength="' . (int) Notifications::TEMPLATE_BODY_MAX . '" aria-describedby="wpac_tpl_vars">' . esc_textarea( $body ) . '</textarea></p>';
		echo '<div id="wpac_tpl_vars"><p><strong>' . esc_html__( 'Placeholders', 'affiliate-captain' ) . '</strong></p><ul>';
		$labels = Notifications::placeholder_labels( $key );
		foreach ( $def['vars'] as $var ) {
			echo '<li><code>{' . esc_html( $var ) . '}</code> – ' . esc_html( isset( $labels[ $var ] ) ? $labels[ $var ] : $var ) . '</li>';
		}
		echo '</ul><p class="description">' . esc_html__( 'Plain text: line breaks are kept, web addresses become links in the HTML layout. HTML tags are removed.', 'affiliate-captain' ) . '</p></div>';
		submit_button( __( 'Save template', 'affiliate-captain' ), 'primary', 'submit', false );
		echo '</form>';

		echo '<div style="margin-top:1.5em;display:flex;gap:1em;flex-wrap:wrap">';
		Admin::form_open( 'mail_template_test' );
		echo '<input type="hidden" name="template" value="' . esc_attr( $key ) . '"><input type="hidden" name="lang" value="' . esc_attr( $lang ) . '">';
		submit_button( __( 'Send test e-mail to me', 'affiliate-captain' ), 'secondary', 'submit', false );
		echo '<p class="description">' . esc_html__( 'Sends the saved template with example values to your own e-mail address.', 'affiliate-captain' ) . '</p></form>';
		if ( $custom ) {
			Admin::form_open( 'mail_template_reset' );
			echo '<input type="hidden" name="template" value="' . esc_attr( $key ) . '"><input type="hidden" name="lang" value="' . esc_attr( $lang ) . '">';
			submit_button( __( 'Reset to the built-in text', 'affiliate-captain' ), 'delete', 'submit', false, array( 'data-wpac-confirm' => __( 'Discard your changes to this template?', 'affiliate-captain' ) ) );
			echo '</form>';
		}
		echo '</div>';
	}
}
