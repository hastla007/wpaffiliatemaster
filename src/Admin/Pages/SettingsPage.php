<?php
/**
 * Admin: settings.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Admin\Pages;

use WPAffiliateCaptain\Admin\Admin;
use WPAffiliateCaptain\Core\Audit;
use WPAffiliateCaptain\Core\Money;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Core\SettingsSchema;

defined( 'ABSPATH' ) || exit;

final class SettingsPage {

	/**
	 * Save.
	 *
	 * @param string $do Action.
	 */
	public static function handle( $do ) {
		if ( 'ropa_download' === $do ) {
			nocache_headers();
			header( 'Content-Type: text/markdown; charset=utf-8' );
			$lang = \WPAffiliateCaptain\Privacy\Privacy::ropa_language();
			header( 'Content-Disposition: attachment; filename="' . ( 'de' === $lang ? 'verarbeitungsverzeichnis-affiliate.md' : 'record-of-processing-affiliate.md' ) . '"' );
			echo \WPAffiliateCaptain\Privacy\Privacy::ropa( $lang ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markdown download, not HTML.
			exit;
		}
		if ( 'roles_save' === $do ) {
			self::handle_roles();
			return;
		}
		if ( in_array( $do, array( 'settings_export', 'settings_import_preview', 'settings_import_apply', 'settings_reset_preview', 'settings_reset_apply', 'settings_pending_discard' ), true ) ) {
			self::handle_transfer( $do );
			return;
		}
		if ( 'settings_save' !== $do ) {
			return;
		}
		$raw  = (array) Admin::post( 'wpac', array() );
		$tabs = self::tab_keys();
		$tab  = self::tab_alias( sanitize_key( Admin::post( 'tab' ) ) );
		$tab  = isset( $tabs[ $tab ] ) ? $tab : 'program';
		// Only the fields of the submitted tab are changed (unchecked checkboxes are absent from POST).
		// Rejected fields keep their stored value (sanitize() returns it) and are reported.
		$errors  = array();
		$notices = array();
		$clean   = Settings::sanitize( $raw, $errors, $notices );
		$keys    = $tabs[ $tab ];
		if ( isset( $clean['currency'] ) && in_array( 'currency', $keys, true ) && (string) $clean['currency'] !== (string) Settings::get( 'currency' ) ) {
			// A new program currency also converts the amounts of other tabs (R5-O2, R7-AD18).
			$keys[]              = 'min_payout_minor';
			$keys[]              = 'fraud_max_order_minor';
			$notices['currency'] = ( isset( $notices['currency'] ) ? $notices['currency'] . ' ' : '' ) . self::currency_leftovers();
		}
		$new     = array_intersect_key( $clean, array_flip( $keys ) );
		$old     = Settings::all();
		$url     = Admin::url( 'wpac-settings', array( 'tab' => $tab ) );
		if ( ! Settings::update( $new ) ) {
			Audit::log( 'settings.save_failed', 'settings', 0, array( 'tab' => $tab ) );
			Admin::back( __( 'The settings could not be saved (database error). Nothing was changed; please try again.', 'affiliate-captain' ), 'error', $url );
		}
		$changed = array();
		foreach ( $new as $k => $v ) {
			if ( ! isset( $old[ $k ] ) || (string) $old[ $k ] !== (string) $v ) {
				$changed[] = $k;
			}
		}
		if ( $changed ) {
			Audit::log(
				'settings.updated',
				'settings',
				0,
				array(
					'changed' => $changed,
					'values'  => self::audit_values( $changed, $old, $new ),
				)
			);
		}
		/**
		 * After the core fields of a tab were saved (contract 1.8.0): add-on fields of this tab
		 * (rows of wpac_settings_rows) save their own input here. The request is already checked
		 * (nonce and capability of the settings page).
		 *
		 * @param string $tab Tab key.
		 * @param array  $new Saved core values of the tab.
		 * @param array  $old Core settings before saving.
		 */
		do_action( 'wpac_settings_saved', $tab, $new, $old );
		/**
		 * Field errors of add-on fields of this tab (since contract 1.8.1): add "form field name" =>
		 * "message" for values the add-on did not save. They are shown like core errors, and the page
		 * no longer says that everything was saved.
		 *
		 * @param array  $errors Errors so far (form field name => message).
		 * @param string $tab    Tab key.
		 */
		$addon_errors = function_exists( 'apply_filters' ) ? (array) apply_filters( 'wpac_settings_errors', array(), $tab ) : array();
		foreach ( $addon_errors as $field => $message ) {
			if ( is_string( $field ) && '' !== $field && is_scalar( $message ) && ! isset( $errors[ $field ] ) ) {
				$errors[ sanitize_key( $field ) ] = wp_strip_all_tags( (string) $message );
			}
		}
		$labels = self::field_labels();
		if ( $errors ) {
			// Show the rejected input again, with the message next to the field.
			$values = array();
			foreach ( array_keys( $errors ) as $field ) {
				$values[ $field ] = isset( $raw[ $field ] ) && is_scalar( $raw[ $field ] ) ? wp_check_invalid_utf8( (string) $raw[ $field ] ) : '';
			}
			Admin::keep_form(
				'settings',
				array(
					'values' => $values,
					'errors' => $errors,
				)
			);
			$lines = array();
			foreach ( $errors as $field => $message ) {
				$lines[] = ( isset( $labels[ $field ] ) ? $labels[ $field ] : $field ) . ': ' . $message;
			}
			Admin::back(
				sprintf(
					/* translators: %d: number of fields */
					_n( '%d field was not saved because its value is not valid – it keeps its previous value. All other changes were saved.', '%d fields were not saved because their values are not valid – they keep their previous values. All other changes were saved.', count( $errors ), 'affiliate-captain' ),
					count( $errors )
				),
				'error',
				$url,
				$lines
			);
		}
		if ( $notices ) {
			$lines = array();
			foreach ( $notices as $field => $message ) {
				$lines[] = ( isset( $labels[ $field ] ) ? $labels[ $field ] : $field ) . ': ' . $message;
			}
			Admin::back( __( 'Settings saved with an adjustment:', 'affiliate-captain' ), 'warning', $url, $lines );
		}
		Admin::back( __( 'Settings saved.', 'affiliate-captain' ), 'success', $url );
	}

	/**
	 * Key of the pending import or reset of the current user (checked values, shown before applying).
	 *
	 * @return string
	 */
	private static function pending_key() {
		return 'wpac_settings_pending_' . get_current_user_id();
	}

	/**
	 * Export, import and reset (SET-AC-08/09): download; check and keep for confirmation; apply.
	 *
	 * @param string $do Action.
	 */
	private static function handle_transfer( $do ) {
		$url = Admin::url( 'wpac-settings', array( 'tab' => 'data' ) );
		if ( 'settings_export' === $do ) {
			$with_private = ! empty( Admin::post( 'with_private' ) );
			Audit::log( 'settings.exported', 'settings', 0, array( 'private' => $with_private ) );
			nocache_headers();
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="affiliate-captain-settings-' . gmdate( 'Y-m-d' ) . '.json"' );
			echo wp_json_encode( \WPAffiliateCaptain\Core\SettingsTransfer::export( $with_private ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download.
			exit;
		}
		if ( 'settings_pending_discard' === $do ) {
			delete_transient( self::pending_key() );
			Admin::back( __( 'Discarded. Nothing was changed.', 'affiliate-captain' ), 'success', $url );
		}
		if ( 'settings_import_preview' === $do ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in Admin::dispatch(); tmp path and size only.
			$file = isset( $_FILES['settings_file']['tmp_name'], $_FILES['settings_file']['size'] ) ? array( 'tmp_name' => (string) $_FILES['settings_file']['tmp_name'], 'size' => absint( $_FILES['settings_file']['size'] ) ) : null;
			if ( ! $file || ! is_uploaded_file( $file['tmp_name'] ) || $file['size'] > 1048576 ) {
				Admin::back( __( 'Please choose a settings export (JSON, at most 1 MB).', 'affiliate-captain' ), 'error', $url );
			}
			$preview = \WPAffiliateCaptain\Core\SettingsTransfer::preview( json_decode( (string) file_get_contents( (string) $file['tmp_name'] ), true ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- uploaded file.
			if ( is_wp_error( $preview ) ) {
				Admin::back( $preview->get_error_message(), 'error', $url );
			}
			set_transient( self::pending_key(), array( 'kind' => 'import', 'tab' => '', 'check' => $preview ), HOUR_IN_SECONDS );
			wp_safe_redirect( $url . '#wpac-settings-pending' );
			exit;
		}
		if ( 'settings_reset_preview' === $do ) {
			$tabs = self::tab_keys();
			$tab  = sanitize_key( (string) Admin::post( 'reset_tab' ) );
			$tab  = isset( $tabs[ $tab ] ) ? $tab : '';
			$keys = \WPAffiliateCaptain\Core\SettingsTransfer::reset_keys( $tab );
			set_transient( self::pending_key(), array( 'kind' => 'reset', 'tab' => $tab, 'check' => \WPAffiliateCaptain\Core\SettingsTransfer::reset_preview( $keys ) ), HOUR_IN_SECONDS );
			wp_safe_redirect( $url . '#wpac-settings-pending' );
			exit;
		}
		// settings_import_apply / settings_reset_apply: apply what was shown.
		$pending = get_transient( self::pending_key() );
		$kind    = 'settings_import_apply' === $do ? 'import' : 'reset';
		if ( ! is_array( $pending ) || $kind !== $pending['kind'] ) {
			Admin::back( __( 'Nothing to apply (the preview expired). Please start again.', 'affiliate-captain' ), 'error', $url );
		}
		delete_transient( self::pending_key() );
		$check = (array) $pending['check'];
		if ( ! empty( $check['errors'] ) && 'import' === $kind ) {
			// Only the valid values are applied; the rejected ones keep their current value.
			$check['errors'] = array();
		}
		$ok = \WPAffiliateCaptain\Core\SettingsTransfer::apply( $check, 'import' === $kind ? 'settings.imported' : 'settings.reset', array( 'tab' => (string) $pending['tab'] ) );
		if ( ! $ok ) {
			Admin::back( __( 'The settings could not be saved (database error). Nothing was changed; please try again.', 'affiliate-captain' ), 'error', $url );
		}
		/* translators: %d: number of changed settings */
		Admin::back( sprintf( _n( '%d setting changed.', '%d settings changed.', count( (array) $check['values'] ), 'affiliate-captain' ), count( (array) $check['values'] ) ), 'success', $url );
	}

	/**
	 * The permission changes a user may make from the posted role table (P4-03): only roles the user
	 * may edit, the own roles only with manage_options, and no permission the user does not hold.
	 *
	 * @param array $posted role => cap => "1".
	 * @return array role => cap => bool
	 */
	public static function role_request( array $posted ) {
		$wanted = array();
		// Only roles the user may edit, never the own roles without manage_options, and no permission
		// the user does not hold (P4-03: no self-promotion through this table).
		$editable = function_exists( 'get_editable_roles' ) ? array_keys( (array) get_editable_roles() ) : array();
		$me       = wp_get_current_user();
		$own      = isset( $me->roles ) ? (array) $me->roles : array();
		foreach ( \WPAffiliateCaptain\Core\Capabilities::role_matrix() as $role => $row ) {
			if ( ( $editable && ! in_array( $role, $editable, true ) ) || ( in_array( $role, $own, true ) && ! current_user_can( 'manage_options' ) ) ) {
				continue;
			}
			foreach ( \WPAffiliateCaptain\Core\Capabilities::all() as $cap ) {
				$grant = ! empty( $posted[ $role ][ $cap ] );
				if ( $grant && ! current_user_can( $cap ) ) {
					continue;
				}
				$wanted[ $role ][ $cap ] = $grant;
			}
		}
		return $wanted;
	}

	/**
	 * Save the role table (SET-AC-21). Needs the WordPress right to change user roles as well.
	 */
	private static function handle_roles() {
		$url = Admin::url( 'wpac-settings', array( 'tab' => 'data' ) );
		if ( ! current_user_can( 'promote_users' ) ) {
			Admin::back( __( 'Only users who may change user roles can change these permissions.', 'affiliate-captain' ), 'error', $url );
		}
		$wanted  = self::role_request( (array) Admin::post( 'wpac_roles', array() ) );
		$changes = \WPAffiliateCaptain\Core\Capabilities::set_role_caps( $wanted );
		if ( $changes ) {
			Audit::log(
				'settings.roles',
				'settings',
				0,
				array(
					'roles' => array_map(
						static function ( $c ) {
							return $c[0] . ':' . $c[1] . '=' . ( $c[2] ? '1' : '0' );
						},
						$changes
					),
				)
			);
		}
		/* translators: %d: number of changed permissions */
		Admin::back( sprintf( _n( '%d permission changed.', '%d permissions changed.', count( $changes ), 'affiliate-captain' ), count( $changes ) ), 'success', $url );
	}

	/**
	 * Role table on the "Data & maintenance" tab (SET-AC-21).
	 */
	private static function render_roles() {
		$matrix = \WPAffiliateCaptain\Core\Capabilities::role_matrix();
		echo '<h2>' . esc_html__( 'Permissions per role', 'affiliate-captain' ) . '</h2><p>' . esc_html__( 'Administrators always have all permissions. Changes need the WordPress right to change user roles and a password confirmation.', 'affiliate-captain' ) . '</p>';
		Admin::form_open( 'roles_save' );
		echo '<table class="widefat striped wpac-roles"><thead><tr><th scope="col">' . esc_html__( 'Role', 'affiliate-captain' ) . '</th>';
		foreach ( \WPAffiliateCaptain\Core\Capabilities::all() as $cap ) {
			echo '<th scope="col">' . esc_html( \WPAffiliateCaptain\Core\Capabilities::label( $cap ) ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $matrix as $role => $row ) {
			echo '<tr><th scope="row">' . esc_html( $row['name'] ) . '</th>';
			foreach ( $row['caps'] as $cap => $on ) {
				$id = 'wpac-role-' . $role . '-' . $cap;
				echo '<td><input type="checkbox" id="' . esc_attr( $id ) . '" name="wpac_roles[' . esc_attr( $role ) . '][' . esc_attr( $cap ) . ']" value="1"' . checked( $on, true, false ) . '><label for="' . esc_attr( $id ) . '" class="screen-reader-text">' . esc_html( $row['name'] . ': ' . \WPAffiliateCaptain\Core\Capabilities::label( $cap ) ) . '</label></td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table><p><button class="button">' . esc_html__( 'Save permissions', 'affiliate-captain' ) . '</button></p></form>';
	}

	/**
	 * A private value with only its last characters visible ("•••2051").
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function partly_masked( $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return '';
		}
		$len = function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
		return '•••' . ( $len > 6 ? ( function_exists( 'mb_substr' ) ? mb_substr( $value, -4 ) : substr( $value, -4 ) ) : '' );
	}

	/**
	 * Export, import and reset on the "Data & maintenance" tab; reset button on every tab.
	 */
	private static function render_transfer() {
		$labels = self::setting_labels();
		if ( 'data' === self::$tab ) {
			$pending = get_transient( self::pending_key() );
			if ( is_array( $pending ) && isset( $pending['check']['changes'] ) ) {
				echo '<div id="wpac-settings-pending" class="notice notice-info inline"><h2>' . esc_html( 'import' === $pending['kind'] ? __( 'Import: please check', 'affiliate-captain' ) : __( 'Reset: please check', 'affiliate-captain' ) ) . '</h2>';
				if ( $pending['check']['changes'] ) {
					echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Setting', 'affiliate-captain' ) . '</th><th scope="col">' . esc_html__( 'Now', 'affiliate-captain' ) . '</th><th scope="col">' . esc_html__( 'New', 'affiliate-captain' ) . '</th></tr></thead><tbody>';
					foreach ( $pending['check']['changes'] as $key => $pair ) {
						$f = SettingsSchema::field( $key );
						$p = ! empty( $f['private'] );
						// The new value is shown in full, so nobody confirms bank details or addresses blindly (P4-02).
						$now   = $p ? self::partly_masked( (string) $pair[0] ) : (string) $pair[0];
						$legal = ! empty( $f['legal'] ) ? ' <span class="wpac-legal-mark">' . esc_html__( 'Check legally', 'affiliate-captain' ) . '</span>' : '';
						echo '<tr><th scope="row">' . esc_html( isset( $labels[ $key ] ) ? $labels[ $key ] : $key ) . $legal . '</th><td>' . esc_html( $now ) . '</td><td>' . esc_html( (string) $pair[1] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $legal is escaped above.
					}
					echo '</tbody></table>';
				} else {
					echo '<p>' . esc_html__( 'Nothing would change.', 'affiliate-captain' ) . '</p>';
				}
				foreach ( (array) $pending['check']['errors'] as $key => $message ) {
					echo '<p class="wpac-field-error">' . esc_html( ( isset( $labels[ $key ] ) ? $labels[ $key ] : $key ) . ': ' . $message ) . '</p>';
				}
				if ( ! empty( $pending['check']['ignored'] ) ) {
					echo '<p class="description">' . esc_html( __( 'Ignored (unknown or not importable):', 'affiliate-captain' ) . ' ' . implode( ', ', array_map( 'strval', (array) $pending['check']['ignored'] ) ) ) . '</p>';
				}
				echo '<p>';
				if ( $pending['check']['changes'] ) {
					Admin::form_open( 'import' === $pending['kind'] ? 'settings_import_apply' : 'settings_reset_apply', 'wpac-inline-form' );
					echo '<button class="button button-primary">' . esc_html__( 'Apply', 'affiliate-captain' ) . '</button></form> ';
				}
				Admin::form_open( 'settings_pending_discard', 'wpac-inline-form' );
				echo '<button class="button">' . esc_html__( 'Discard', 'affiliate-captain' ) . '</button></form></p></div>';
			}
			echo '<h2>' . esc_html__( 'Export, import and reset', 'affiliate-captain' ) . '</h2>';
			echo '<p>' . esc_html__( 'Export the settings as a JSON file to copy them to another shop or keep a backup. The monitoring token is never exported; bank details, addresses, e-mail addresses and tax numbers only when you tick the box. An import first shows what would change.', 'affiliate-captain' ) . '</p>';
			Admin::form_open( 'settings_export' );
			echo '<p><label><input type="checkbox" name="with_private" value="1"> ' . esc_html__( 'Include bank details, addresses, e-mail addresses and tax numbers', 'affiliate-captain' ) . '</label> <button class="button">' . esc_html__( 'Export settings (JSON)', 'affiliate-captain' ) . '</button></p></form>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" enctype="multipart/form-data">';
			Admin::form_fields( 'settings_import_preview' );
			echo '<p><label for="wpac-settings-file">' . esc_html__( 'Settings file', 'affiliate-captain' ) . '</label> <input type="file" id="wpac-settings-file" name="settings_file" accept=".json,application/json"> <button class="button">' . esc_html__( 'Check import', 'affiliate-captain' ) . '</button></p></form>';
		}
		if ( 'data' === self::$tab ) {
			self::render_roles();
		}
		// Reset of the shown tab (preview first). Currency, number prefixes and private values stay.
		echo '<h2>' . esc_html__( 'Reset', 'affiliate-captain' ) . '</h2>';
		Admin::form_open( 'settings_reset_preview' );
		echo '<input type="hidden" name="reset_tab" value="' . esc_attr( self::$tab ) . '"><p><button class="button">' . esc_html__( 'Reset this tab to the defaults …', 'affiliate-captain' ) . '</button> <span class="description">' . esc_html__( 'You see the changes first. The program currency, document number prefixes, bank details and addresses are kept.', 'affiliate-captain' ) . '</span></p></form>';
	}

	/**
	 * Old and new value of each changed setting for the audit log (SET-AC-11). Secret and private
	 * values (token, bank details, addresses, e-mail addresses, tax numbers) are only marked as
	 * changed; long texts are shortened.
	 *
	 * @param string[] $changed Changed keys.
	 * @param array    $old     Settings before.
	 * @param array    $new     Saved values.
	 * @return array key => [ from, to ]
	 */
	public static function audit_values( array $changed, array $old, array $new ) {
		$out = array();
		foreach ( $changed as $key ) {
			$field = SettingsSchema::field( $key );
			if ( ! $field || ! empty( $field['secret'] ) || ! empty( $field['private'] ) ) {
				$out[ $key ] = array(
					'from' => '[redacted]',
					'to'   => '[redacted]',
				);
				continue;
			}
			$short       = static function ( $v ) {
				$v = is_scalar( $v ) ? (string) $v : wp_json_encode( $v );
				return function_exists( 'mb_strlen' ) && mb_strlen( $v ) > 120 ? mb_substr( $v, 0, 117 ) . '…' : $v;
			};
			$out[ $key ] = array(
				'from' => $short( isset( $old[ $key ] ) ? $old[ $key ] : '' ),
				'to'   => $short( isset( $new[ $key ] ) ? $new[ $key ] : '' ),
			);
		}
		return $out;
	}

	/**
	 * What a currency change does not convert: fixed-amount rules and bonus tiers by revenue.
	 *
	 * @return string
	 */
	private static function currency_leftovers() {
		global $wpdb;
		$rules_t = \WPAffiliateCaptain\Core\Db::table( 'rules' );
		$tiers_t = \WPAffiliateCaptain\Core\Db::table( 'tiers' );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table names.
		$rules = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$rules_t} WHERE rate_type = 'fixed' AND active = 1" );
		$tiers = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tiers_t} WHERE basis = 'revenue' AND active = 1" );
		// phpcs:enable
		if ( ! $rules && ! $tiers ) {
			return '';
		}
		/* translators: 1: number of rules, 2: number of tier plans */
		return sprintf( __( 'Not converted: %1$d active fixed-amount rules and %2$d bonus tier plans with revenue thresholds – please adjust them under Rules & groups.', 'affiliate-captain' ), $rules, $tiers );
	}

	/**
	 * Warning for a cookie lifetime beyond what is usually proportionate (R14-L8).
	 *
	 * @param int $days Cookie lifetime.
	 * @return string
	 */
	public static function cookie_days_warning( $days ) {
		/* translators: 1: configured cookie lifetime in days, 2: advised maximum in days */
		return sprintf( __( 'A cookie lifetime of %1$d days is longer than data protection authorities usually consider proportionate for a consent-based cookie (up to %2$d days, about 13 months). Choose a shorter lifetime unless you have documented reasons; the lifetime must also be stated in your consent banner.', 'affiliate-captain' ), (int) $days, \WPAffiliateCaptain\Core\Settings::COOKIE_DAYS_ADVISED );
	}

	/**
	 * Labels of the validated fields (form field name => label) for error messages.
	 *
	 * @return array
	 */
	private static function field_labels() {
		$labels = array();
		foreach ( \WPAffiliateCaptain\Core\SettingsSchema::core() as $key => $f ) {
			$labels[ $key ] = $f['label'];
		}
		// Form fields that are entered as decimal amounts and stored in minor units.
		$labels['fraud_max_order'] = $labels['fraud_max_order_minor'];
		$labels['min_payout']      = $labels['min_payout_minor'];
		return $labels;
	}

	/**
	 * Names of all setting keys (audit log "settings changed", messages), from the register.
	 *
	 * @return array key => label
	 */
	public static function setting_labels() {
		return self::field_labels();
	}

	/** @var array Rejected input of the last save: values and errors per form field. */
	private static $kept = array(
		'values' => array(),
		'errors' => array(),
	);

	/**
	 * Value to show in a field: the rejected input after a failed save, else the stored value.
	 *
	 * @param string $field Form field name.
	 * @param mixed  $value Stored value.
	 * @return string
	 */
	private static function shown( $field, $value ) {
		return isset( self::$kept['errors'][ $field ], self::$kept['values'][ $field ] ) ? (string) self::$kept['values'][ $field ] : (string) $value;
	}

	/**
	 * Attributes and message for a field with an error.
	 *
	 * @param string $field Form field name.
	 * @param bool   $desc  Whether the field has a description (then it is linked too, P5-02).
	 * @return array [ attributes, message HTML ]
	 */
	private static function error_parts( $field, $desc = false ) {
		$desc_id = $desc ? ' wpac-desc-' . esc_attr( $field ) : '';
		if ( ! isset( self::$kept['errors'][ $field ] ) ) {
			return array( '' !== $desc_id ? ' aria-describedby="' . trim( $desc_id ) . '"' : '', '' );
		}
		// The summary above the form announces the errors once; the field message is only linked (P5-05).
		return array(
			' aria-invalid="true" aria-describedby="wpac-err-' . esc_attr( $field ) . $desc_id . '"',
			'<p class="wpac-field-error" id="wpac-err-' . esc_attr( $field ) . '">' . esc_html( self::$kept['errors'][ $field ] ) . '</p>',
		);
	}

	/**
	 * Setting keys per tab.
	 *
	 * @return array
	 */
	public static function tab_keys() {
		// From the register (SET-AC-01); all retention periods, maintenance and uninstall on "data" (R5-O45).
		return \WPAffiliateCaptain\Core\SettingsSchema::tabs();
	}

	/**
	 * Tab key of older links ("reports" held maintenance and retention before the regrouping).
	 *
	 * @param string $tab Requested tab.
	 * @return string
	 */
	public static function tab_alias( $tab ) {
		return 'reports' === $tab ? 'data' : (string) $tab;
	}

	/**
	 * Program currency: a list of the currencies WooCommerce knows (text field without WooCommerce).
	 *
	 * @param array $s Settings.
	 */
	private static function currency_field( $s ) {
		$desc = __( 'Changing it converts the minimum payout, the review limit and a fixed default rate (with an exchange rate between the two currencies if one is entered, otherwise keeping the value); fixed rules and bonus tiers must be checked by you.', 'affiliate-captain' );
		if ( ! function_exists( 'get_woocommerce_currencies' ) ) {
			self::text( 'currency', null, $s, $desc );
			return;
		}
		$options  = array();
		$accepted = \WPAffiliateCaptain\Sales\ContractApi::currencies();
		foreach ( (array) get_woocommerce_currencies() as $code => $name ) {
			if ( ! in_array( strtoupper( (string) $code ), $accepted, true ) ) {
				continue; // Removed by the filter wpac_contract_currencies: sales would be refused.
			}
			/* translators: 1: currency name, 2: ISO code */
			$options[ (string) $code ] = sprintf( __( '%1$s (%2$s)', 'affiliate-captain' ), html_entity_decode( (string) $name, ENT_QUOTES, 'UTF-8' ), $code );
		}
		$current = strtoupper( (string) $s['currency'] );
		if ( '' !== $current && ! isset( $options[ $current ] ) ) {
			$options[ $current ] = $current;
		}
		self::select( 'currency', null, $s, $options );
		self::desc_row( $desc );
	}

	/**
	 * Description row below a field.
	 *
	 * @param string $desc Text.
	 */
	private static function desc_row( $desc ) {
		echo '<tr><td></td><td><p class="description">' . esc_html( $desc ) . '</p></td></tr>';
	}

	/** @var string Active tab while rendering. */
	private static $tab = 'program';

	/** @var string Tab of the section being rendered. */
	private static $section_tab = '';

	/** @var string Title of the section being rendered. */
	private static $section_title = '';

	/**
	 * Render.
	 */
	public static function render() {
		$labels = array(
			'program'     => __( 'Program', 'affiliate-captain' ),
			'emails'      => __( 'E-mails & reports', 'affiliate-captain' ),
			'tracking'    => __( 'Tracking', 'affiliate-captain' ),
			'security'    => __( 'Fraud & abuse', 'affiliate-captain' ),
			'commissions' => __( 'Commissions', 'affiliate-captain' ),
			'promotion'   => __( 'Promotion', 'affiliate-captain' ),
			'payouts'     => __( 'Payouts & credit notes', 'affiliate-captain' ),
			'data'        => __( 'Data & maintenance', 'affiliate-captain' ),
		);
		$tab       = self::tab_alias( Admin::get( 'tab' ) );
		self::$tab = isset( $labels[ $tab ] ) ? $tab : 'program';
		$s        = Settings::all();
		$currency = $s['currency'];
		$kept     = Admin::kept_form( 'settings' );
		if ( isset( $kept['values'], $kept['errors'] ) ) {
			self::$kept = $kept;
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Affiliate Captain settings', 'affiliate-captain' ) . '</h1>';
		Admin::notice();
		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Settings sections', 'affiliate-captain' ) . '">';
		foreach ( $labels as $key => $label ) {
			printf( '<a class="nav-tab %s" href="%s"%s>%s</a>', $key === self::$tab ? 'nav-tab-active' : '', esc_url( Admin::url( 'wpac-settings', array( 'tab' => $key ) ) ), $key === self::$tab ? ' aria-current="page"' : '', esc_html( $label ) );
		}
		echo '</nav>';
		Admin::form_open( 'settings_save', 'wpac-tabs-content' );
		echo '<input type="hidden" name="tab" value="' . esc_attr( self::$tab ) . '">';

		self::render_program_tab( $s );
		self::render_emails_tab( $s );
		self::render_tracking_tab( $s );
		self::render_security_tab( $s, $currency );
		self::render_commissions_tab( $s, $currency );
		self::render_promotion_tab( $s );
		self::render_payouts_tab( $s, $currency );
		self::render_data_tab( $s );
		\WPAffiliateCaptain\Admin\AddonHints::render( self::$tab );

		submit_button();
		echo '</form>';
		self::render_transfer();
		if ( 'data' === self::$tab ) {
			echo '<h2>' . esc_html__( 'Record of processing activities', 'affiliate-captain' ) . '</h2><p>' . esc_html__( 'Template for your record of processing activities (Art. 30 GDPR), filled with your current settings.', 'affiliate-captain' ) . '</p>';
			Admin::form_open( 'ropa_download' );
			echo '<p><button class="button">' . esc_html__( 'Download template (Markdown)', 'affiliate-captain' ) . '</button></p></form>';
		}
		echo '</div>';
	}

	/**
	 * Fields of the 'Program' tab.
	 *
	 * @param array $s Settings.
	 */
	private static function render_program_tab( array $s ) {
		self::section( __( 'Pages & terms', 'affiliate-captain' ), 'program' );
		self::page_select( 'registration_page_id', null, $s, '[wpac_affiliate_registration]' );
		self::page_select( 'portal_page_id', null, $s, '[wpac_affiliate_portal]' );
		self::page_select( 'terms_page_id', null, $s );
		self::text( 'terms_version', null, $s );
		self::end();

		self::section( __( 'Applications & termination', 'affiliate-captain' ), 'program' );
		self::checkbox( 'auto_approve', null, $s );
		self::select( 'terminated_open', null, $s, array( 'pay' => __( 'Remain payable', 'affiliate-captain' ), 'reject' => __( 'Are rejected', 'affiliate-captain' ) ) );
		self::checkbox( 'code_requests', null, $s );
		self::number( 'code_requests_per_month', null, $s );
		self::number( 'code_request_max_length', null, $s );
		self::number( 'application_confirm_days', null, $s );
		self::number( 'terms_reminder_days', null, $s );
		self::end();
	}

	/**
	 * Fields of the 'E-mails & reports' tab.
	 *
	 * @param array $s Settings.
	 */
	private static function render_emails_tab( array $s ) {
		self::section( __( 'Addresses', 'affiliate-captain' ), 'emails' );
		self::text( 'admin_email', null, $s );
		self::text( 'mail_from_name', null, $s );
		self::text( 'mail_from_email', null, $s );
		self::end();

		self::section( __( 'Notices to partners', 'affiliate-captain' ), 'emails' );
		self::checkbox( 'notify_payout_paid', null, $s );
		self::checkbox( 'notify_commissions', null, $s );
		self::end();

		self::section( __( 'Notices to the operator', 'affiliate-captain' ), 'emails' );
		self::checkbox( 'notify_admin_review', null, $s );
		self::end();

		self::section( __( 'Monthly reports', 'affiliate-captain' ), 'emails' );
		self::checkbox( 'report_admin', null, $s );
		self::checkbox( 'report_partners', null, $s );
		self::end();
	}

	/**
	 * Fields of the 'Tracking' tab.
	 *
	 * @param array $s Settings.
	 */
	private static function render_tracking_tab( array $s ) {
		self::section( __( 'Tracking & attribution', 'affiliate-captain' ), 'tracking' );
		self::text( 'referral_param', null, $s, sprintf( /* translators: %s: example URL with the link parameter */ __( 'Example: %s', 'affiliate-captain' ), home_url( '/?' . $s['referral_param'] . '=CODE' ) ) );
		self::checkbox( 'checkout_field', null, $s );
		if ( class_exists( 'WooCommerce' ) && ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			self::desc_row( __( 'The block checkout shows the partner code field from WooCommerce 8.9 on; with this version only the classic checkout shows it.', 'affiliate-captain' ) );
		}
		self::checkbox( 'use_wc_session', null, $s );
		self::select(
			'cookie_mode',
			null,
			$s,
			array(
				'off'     => __( 'Never set', 'affiliate-captain' ),
				'consent' => __( 'Only with marketing consent (WP Consent API / wpac_has_marketing_consent filter)', 'affiliate-captain' ),
				'always'  => __( 'Always, without consent (not permitted in the EU – §25 TDDDG, ePrivacy)', 'affiliate-captain' ),
			)
		);
		if ( 'always' === $s['cookie_mode'] ) {
			echo '<tr><td></td><td><div class="notice notice-warning inline"><p>' . esc_html__( 'An affiliate cookie is not strictly necessary: in the EU it may only be set with consent. Use "Only with marketing consent" unless your shop is outside the EU and you checked the local law.', 'affiliate-captain' ) . '</p></div></td></tr>';
		}
		self::number( 'cookie_days', null, $s );
		if ( (int) $s['cookie_days'] > \WPAffiliateCaptain\Core\Settings::COOKIE_DAYS_ADVISED ) {
			echo '<tr><td></td><td><div class="notice notice-warning inline"><p>' . esc_html( self::cookie_days_warning( (int) $s['cookie_days'] ) ) . '</p></div></td></tr>';
		}
		self::text( 'cookie_domain', null, $s );
		self::checkbox( 'js_tracking', null, $s );
		$edge = \WPAffiliateCaptain\Tracking\Tracker::edge_cache_hint();
		if ( '' !== $edge ) {
			echo '<tr><td></td><td><div class="notice notice-warning inline"><p>' . esc_html( $edge ) . '</p></div></td></tr>';
		}
		self::number( 'visit_log_percent', null, $s );
		self::select( 'touch_model', null, $s, array( 'last' => __( 'Last touch', 'affiliate-captain' ), 'first' => __( 'First touch', 'affiliate-captain' ) ) );
		self::checkbox( 'coupon_priority', null, $s );
		self::select( 'self_referral', null, $s, array( 'block' => __( 'Block', 'affiliate-captain' ), 'review' => __( 'Flag for review', 'affiliate-captain' ) ) );
		self::end();
	}

	/**
	 * Fields of the 'Fraud & abuse' tab.
	 *
	 * @param array  $s        Settings.
	 * @param string $currency Program currency.
	 */
	private static function render_security_tab( array $s, $currency ) {
		self::section( __( 'Fraud rules', 'affiliate-captain' ), 'security' );
		echo '<tr><td colspan="2" class="description">' . esc_html__( 'Matches do not block a sale: its commissions get the status "needs review" with the reason. 0 = rule off.', 'affiliate-captain' ) . '</td></tr>';
		if ( ! (int) $s['fraud_max_orders_day'] && ! (int) $s['fraud_max_order_minor'] && ! (int) $s['fraud_min_click_seconds'] && ! (int) $s['fraud_max_refund_rate'] ) {
			$rec = Settings::recommended_fraud( $currency );
			/* translators: 1: orders per day, 2: order value with currency, 3: seconds, 4: percent */
			echo '<tr><td colspan="2"><div class="notice notice-warning inline"><p>' . esc_html( sprintf( __( 'All fraud thresholds are off, so no order is ever flagged for review. Recommended starting values: %1$d orders per day, orders above %2$s, %3$d seconds between click and order, %4$d %% refund rate.', 'affiliate-captain' ), $rec['fraud_max_orders_day'], Money::format( $rec['fraud_max_order_minor'], $currency ), $rec['fraud_min_click_seconds'], $rec['fraud_max_refund_rate'] ) ) . '</p></div></td></tr>';
		}
		self::number( 'fraud_max_orders_day', null, $s );
		$err = self::error_parts( 'fraud_max_order', true );
		echo '<tr><th><label for="wpac-fraud_max_order">' . esc_html( SettingsSchema::label( 'fraud_max_order_minor' ) ) . '</label></th><td><input type="text" id="wpac-fraud_max_order" name="wpac[fraud_max_order]" value="' . esc_attr( self::shown( 'fraud_max_order', Money::to_decimal( (int) $s['fraud_max_order_minor'], $currency ) ) ) . '" class="small-text" inputmode="decimal"' . $err[0] . '> ' . esc_html( $currency ) . $err[1] . '<p class="description" id="wpac-desc-fraud_max_order">' . esc_html( SettingsSchema::help( 'fraud_max_order_minor' ) . ' ' . __( 'Orders in other currencies are compared after conversion with the reference exchange rates; without a rate for a currency this rule cannot check them (a warning is logged).', 'affiliate-captain' ) ) . '</p></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- error_parts() is escaped.
		self::number( 'fraud_min_click_seconds', null, $s );
		self::number( 'fraud_max_refund_rate', null, $s );
		self::number( 'fraud_refund_window_days', null, $s );
		self::number( 'fraud_refund_min_orders', null, $s );
		self::checkbox( 'fraud_check_domains', null, $s );
		echo '<tr><th><label for="wpac-fraud_blocked_domains">' . esc_html( SettingsSchema::label( 'fraud_blocked_domains' ) ) . '</label></th><td><textarea id="wpac-fraud_blocked_domains" name="wpac[fraud_blocked_domains]" rows="3" class="regular-text code">' . esc_textarea( (string) $s['fraud_blocked_domains'] ) . '</textarea><p class="description">' . esc_html( SettingsSchema::help( 'fraud_blocked_domains' ) ) . '</p></td></tr>';
		self::end();

		self::section( __( 'Abuse protection', 'affiliate-captain' ), 'security' );
		self::number( 'rate_limit_per_minute', null, $s );
		$err = self::error_parts( 'trusted_proxies', true );
		echo '<tr><th><label for="wpac-trusted_proxies">' . esc_html( SettingsSchema::label( 'trusted_proxies' ) ) . '</label></th><td><textarea id="wpac-trusted_proxies" name="wpac[trusted_proxies]" rows="3" class="regular-text code" placeholder="173.245.48.0/20&#10;10.0.0.1"' . $err[0] . '>' . esc_textarea( self::shown( 'trusted_proxies', $s['trusted_proxies'] ) ) . '</textarea>' . $err[1] . '<p class="description" id="wpac-desc-trusted_proxies">' . esc_html( SettingsSchema::help( 'trusted_proxies' ) ) . '</p></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- error_parts() escapes.
		self::checkbox( 'reauth_required', null, $s );
		self::number( 'reauth_minutes', null, $s );
		self::number( 'bank_change_wait_days', null, $s );
		self::end();
	}

	/**
	 * Fields of the 'Commissions' tab.
	 *
	 * @param array  $s        Settings.
	 * @param string $currency Program currency.
	 */
	private static function render_commissions_tab( array $s, $currency ) {
		self::section( __( 'Program currency', 'affiliate-captain' ), 'commissions' );
		self::currency_field( $s );
		$err = self::error_parts( 'exchange_rates', true );
		echo '<tr><th><label for="wpac-exchange_rates">' . esc_html( SettingsSchema::label( 'exchange_rates' ) ) . '</label></th><td><textarea id="wpac-exchange_rates" name="wpac[exchange_rates]" rows="3" class="regular-text code" placeholder="USD=0.92&#10;CHF=1.05"' . $err[0] . '>' . esc_textarea( self::shown( 'exchange_rates', $s['exchange_rates'] ) ) . '</textarea>' . $err[1] . '<p class="description" id="wpac-desc-exchange_rates">' . esc_html( sprintf( /* translators: %s: currency */ __( 'One per line: 1 unit of the currency = x %s. Commissions and payouts are never converted; rates are only used for reports and the minimum payout of other currencies.', 'affiliate-captain' ), $currency ) ) . '</p></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- error_parts() escapes.
		self::end();

		self::section( __( 'Default commission', 'affiliate-captain' ), 'commissions' );
		self::select( 'default_rate_type', null, $s, array( 'percent' => __( 'Percent of net', 'affiliate-captain' ), 'fixed' => __( 'Fixed amount per unit', 'affiliate-captain' ) ) );
		$display = $s;
		if ( 'fixed' === $s['default_rate_type'] ) {
			$display['default_rate'] = Money::to_decimal( (int) $s['default_rate'], $currency );
		}
		self::text( 'default_rate', null, $display );
		self::select(
			'rule_precedence',
			null,
			$s,
			array(
				'scope'  => __( 'Partner rules first: partner > group > all partners, then product > category > all; exclusions always win', 'affiliate-captain' ),
				'target' => __( 'Most specific product first: product > category > all, then partner > group > all partners; exclusions compete like other rules', 'affiliate-captain' ),
			)
		);
		self::end();

		self::section( __( 'Lifetime commissions', 'affiliate-captain' ), 'commissions' );
		self::checkbox( 'lifetime_enabled', null, $s );
		self::number( 'lifetime_days', null, $s );
		self::text( 'lifetime_rate', null, $s );
		self::end();

		self::section( __( 'Subscription renewals', 'affiliate-captain' ), 'commissions' );
		self::checkbox( 'renewal_commission', null, $s );
		self::number( 'renewal_limit_months', null, $s );
		self::end();

		self::section( __( 'Hold period', 'affiliate-captain' ), 'commissions' );
		self::number( 'hold_days', null, $s );
		self::select(
			'hold_start',
			null,
			$s,
			array(
				'paid'      => __( 'At payment', 'affiliate-captain' ),
				'completed' => __( 'When the WooCommerce order is completed (shipped) – for pre-orders, invoice and cash on delivery', 'affiliate-captain' ),
			)
		);
		self::select( 'approval_mode', null, $s, array( 'auto' => __( 'Automatically after the hold period', 'affiliate-captain' ), 'manual' => __( 'Only by hand', 'affiliate-captain' ) ) );
		self::end();
	}

	/**
	 * Fields of the 'Promotion' tab.
	 *
	 * @param array $s Settings.
	 */
	private static function render_promotion_tab( array $s ) {
		self::section( __( 'Promotion', 'affiliate-captain' ), 'promotion' );
		self::text( 'landing_prefix', null, $s, sprintf( /* translators: %s: example URL of a partner landing page */ __( 'Partner landing URLs look like %s', 'affiliate-captain' ), home_url( '/' . $s['landing_prefix'] . '/partner-slug/' ) ) );
		self::page_select( 'landing_page_id', null, $s );
		self::text( 'default_disclosure', null, $s );
		self::checkbox( 'embed_disclosure_label', null, $s );
		self::checkbox( 'auto_apply_coupon', null, $s );
		echo '<tr><th><label for="wpac-promotion_guidelines">' . esc_html( SettingsSchema::label( 'promotion_guidelines' ) ) . '</label></th><td><textarea id="wpac-promotion_guidelines" name="wpac[promotion_guidelines]" rows="6" class="large-text">' . esc_textarea( (string) $s['promotion_guidelines'] ) . '</textarea><p class="description">' . esc_html( SettingsSchema::help( 'promotion_guidelines' ) ) . '</p></td></tr>';
		self::end();
	}

	/**
	 * Fields of the 'Payouts & credit notes' tab.
	 *
	 * @param array  $s        Settings.
	 * @param string $currency Program currency.
	 */
	private static function render_payouts_tab( array $s, $currency ) {
		self::section( __( 'Payouts', 'affiliate-captain' ), 'payouts' );
		$err = self::error_parts( 'min_payout', true );
		echo '<tr><th><label for="wpac-min_payout">' . esc_html( SettingsSchema::label( 'min_payout_minor' ) ) . '</label></th><td><input type="text" id="wpac-min_payout" name="wpac[min_payout]" value="' . esc_attr( self::shown( 'min_payout', Money::to_decimal( (int) $s['min_payout_minor'], $currency ) ) ) . '" class="small-text" inputmode="decimal"' . $err[0] . '> ' . esc_html( $currency ) . $err[1] . '<p class="description" id="wpac-desc-min_payout">' . esc_html( SettingsSchema::help( 'min_payout_minor' ) ) . '</p></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- error_parts() is escaped.
		// No "payout period" setting: payout runs are started by you (Payouts → New payout run); a
		// stored value of the former setting is kept but has no effect.
		self::checkbox( 'payout_requests', null, $s );
		self::checkbox( 'payout_method_manual', null, $s );
		self::number( 'payout_request_note_days', null, $s );
		self::number( 'claim_cancel_days', null, $s );
		self::end();

		self::section( __( 'Your company details (for credit notes)', 'affiliate-captain' ), 'payouts' );
		$countries = Settings::countries();
		if ( '' !== (string) $s['operator_country'] && ! isset( $countries[ $s['operator_country'] ] ) ) {
			$countries[ $s['operator_country'] ] = (string) $s['operator_country']; // Value from an older version.
		}
		self::select( 'operator_country', null, $s, $countries );
		echo '<tr><th><label for="wpac-operator_address">' . esc_html( SettingsSchema::label( 'operator_address' ) ) . '</label></th><td><textarea id="wpac-operator_address" name="wpac[operator_address]" rows="3" class="regular-text">' . esc_textarea( (string) $s['operator_address'] ) . '</textarea><p class="description">' . esc_html( SettingsSchema::help( 'operator_address' ) ) . '</p></td></tr>';
		self::text( 'operator_vat_id', null, $s );
		self::text( 'operator_tax_number', null, $s );
		self::end();

		self::section( __( 'Credit notes & VAT', 'affiliate-captain' ), 'payouts' );
		self::checkbox( 'self_billing', null, $s );
		self::text( 'vat_rate', null, $s );
		self::text( 'credit_prefix', null, $s );
		self::text( 'storno_prefix', null, $s );
		self::text( 'correction_prefix', null, $s );
		self::end();

		self::section( __( 'Electronic invoice (EN 16931)', 'affiliate-captain' ), 'payouts' );
		self::select(
			'einvoice_mail',
			null,
			$s,
			array(
				'auto' => __( 'Automatic: for partners with German VAT on the credit note (domestic VAT-registered businesses)', 'affiliate-captain' ),
				'on'   => __( 'Always', 'affiliate-captain' ),
				'off'  => __( 'Never (download only)', 'affiliate-captain' ),
			),
			__( 'Every credit note, correction credit note and cancellation can also be downloaded as an electronic invoice (UN/CEFACT CII XML, EN 16931; XRechnung profile when all its data are known) next to the PDF, in the partner area and here. From 2027/2028 domestic VAT-registered partners need it: the XML is then the invoice, the PDF a visual copy.', 'affiliate-captain' )
		);
		self::text( 'einvoice_buyer_reference', null, $s );
		self::end();

		self::section( __( 'VAT ID check (VIES)', 'affiliate-captain' ), 'payouts' );
		self::checkbox( 'vies_check', null, $s );
		self::number( 'vies_recheck_days', null, $s );
		self::end();

		self::section( __( 'SEPA bank transfer', 'affiliate-captain' ), 'payouts' );
		self::text( 'debtor_name', null, $s );
		self::text( 'debtor_iban', null, $s );
		self::text( 'debtor_bic', null, $s );
		self::text( 'remittance_prefix', null, $s );
		self::end();
	}

	/**
	 * Fields of the 'Data & maintenance' tab.
	 *
	 * @param array $s Settings.
	 */
	private static function render_data_tab( array $s ) {
		self::section( __( 'Retention periods', 'affiliate-captain' ), 'data' );
		self::number( 'visit_retention_days', null, $s );
		self::number( 'application_retention_days', null, $s );
		self::number( 'pending_application_days', null, $s );
		self::number( 'audit_retention_days', null, $s );
		self::number( 'statement_retention_years', null, $s );
		self::checkbox( 'retention_hold', null, $s );
		self::end();

		self::section( __( 'Maintenance & monitoring', 'affiliate-captain' ), 'data' );
		self::checkbox( 'maintenance_mode', null, $s );
		self::number( 'privacy_requests_per_day', null, $s );
		self::monitor_token( $s );
		self::end();

		self::section( __( 'Add-ons', 'affiliate-captain' ), 'data' );
		self::checkbox( 'addon_hints', null, $s );
		self::end();

		self::section( __( 'Uninstall', 'affiliate-captain' ), 'data' );
		self::checkbox( 'delete_on_uninstall', null, $s );
		self::uninstall_confirm( $s );
		self::end();
	}

	/**
	 * Section start (output is buffered and only shown on its tab).
	 *
	 * @param string $title Title.
	 * @param string $tab   Tab.
	 */
	private static function section( $title, $tab ) {
		self::$section_tab   = $tab;
		self::$section_title = $title;
		ob_start();
	}

	/** Section end. */
	private static function end() {
		$html = (string) ob_get_clean();
		if ( self::$section_tab === self::$tab ) {
			echo '<h2>' . esc_html( self::$section_title ) . '</h2><table class="form-table" role="presentation">' . $html . '</table>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped fields.
		}
	}

	/**
	 * Label and help text of a field: from the register unless the page passes its own (SET-AC-01).
	 *
	 * @param string      $key   Setting key.
	 * @param string|null $label Label or null.
	 * @param string      $desc  Help text or '' (then the register's help text).
	 * @return array [ label, help ]
	 */
	private static function texts( $key, $label, $desc ) {
		return array(
			null === $label ? SettingsSchema::label( $key ) : (string) $label,
			'' === (string) $desc ? SettingsSchema::help( $key ) : (string) $desc,
		);
	}

	/**
	 * Description.
	 *
	 * @param string $desc Description.
	 * @param string $key  Setting key or form field name (id for aria-describedby, legal mark).
	 */
	private static function desc( $desc, $key = '' ) {
		$f     = '' !== $key ? SettingsSchema::field( $key ) : null;
		$legal = $f && ! empty( $f['legal'] ) ? ' <span class="wpac-legal-mark">' . esc_html__( 'Check legally', 'affiliate-captain' ) . '</span>' : '';
		if ( '' !== trim( (string) $desc ) || '' !== $legal ) {
			echo '<p class="description"' . ( '' !== $key ? ' id="wpac-desc-' . esc_attr( $key ) . '"' : '' ) . '>' . esc_html( trim( (string) $desc ) ) . $legal . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $legal is escaped above.
		}
	}

	/**
	 * Monitoring token field (write-only).
	 *
	 * @param array $s Settings.
	 */
	private static function monitor_token( $s ) {
		$set = '' !== (string) $s['monitor_token'];
		// Only a hash is stored: the field is always empty, an empty field keeps the token.
		$err = self::error_parts( 'monitor_token', true ); // P5-03: a rejected token is marked at the field (the value is never shown again).
		printf( '<tr><th><label for="wpac-monitor_token">%1$s</label></th><td><input type="password" id="wpac-monitor_token" name="wpac[monitor_token]" value="" class="regular-text" autocomplete="new-password" placeholder="%2$s"%3$s>%4$s', esc_html__( 'Monitoring token', 'affiliate-captain' ), esc_attr( $set ? __( 'A token is set – enter a new one to replace it', 'affiliate-captain' ) : '' ), $err[0], $err[1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- error_parts() is escaped.
		if ( $set ) {
			echo ' <label><input type="checkbox" name="wpac[monitor_token_clear]" value="1"> ' . esc_html__( 'Remove token', 'affiliate-captain' ) . '</label>';
		}
		self::desc( sprintf( /* translators: %s: URL */ __( 'At least 20 letters/digits. An external monitor (e.g. an uptime service) can then call %s with the header "X-WPAC-Monitor-Token" and gets ok or the names of failing checks (no details).', 'affiliate-captain' ), rest_url( 'wpac/v1/health' ) ) . ' ' . __( 'Only a hash of the token is stored; note it down before saving.', 'affiliate-captain' ), 'monitor_token' );
		echo '</td></tr>';
	}

	/**
	 * Text field.
	 *
	 * @param string $key   Key.
	 * @param string|null $label Label (null: from the register).
	 * @param array       $s     Settings.
	 * @param string $desc  Description.
	 */
	private static function text( $key, $label, $s, $desc = '' ) {
		list( $label, $desc ) = self::texts( $key, $label, $desc );
		$err = self::error_parts( $key, '' !== trim( (string) $desc ) );
		printf( '<tr><th><label for="wpac-%1$s">%2$s</label></th><td><input type="text" id="wpac-%1$s" name="wpac[%1$s]" value="%3$s" class="regular-text"%4$s>%5$s', esc_attr( $key ), esc_html( $label ), esc_attr( self::shown( $key, $s[ $key ] ) ), $err[0], $err[1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- error_parts() is escaped.
		self::desc( $desc, $key );
		echo '</td></tr>';
	}

	/**
	 * Number field.
	 *
	 * @param string $key   Key.
	 * @param string|null $label Label (null: from the register).
	 * @param array       $s     Settings.
	 * @param string $desc  Description.
	 */
	private static function number( $key, $label, $s, $desc = '' ) {
		list( $label, $desc ) = self::texts( $key, $label, $desc );
		$err = self::error_parts( $key, '' !== trim( (string) $desc ) );
		$min = \WPAffiliateCaptain\Core\SettingsSchema::int_min();
		printf( '<tr><th><label for="wpac-%1$s">%2$s</label></th><td><input type="number" min="%6$d" step="1" id="wpac-%1$s" name="wpac[%1$s]" value="%3$s" class="small-text"%4$s>%5$s', esc_attr( $key ), esc_html( $label ), esc_attr( self::shown( $key, $s[ $key ] ) ), $err[0], $err[1], isset( $min[ $key ] ) ? (int) $min[ $key ] : 0 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- error_parts() is escaped.
		self::desc( $desc, $key );
		echo '</td></tr>';
	}

	/**
	 * Checkbox.
	 *
	 * @param string $key   Key.
	 * @param string|null $label Label (null: from the register).
	 * @param array       $s     Settings.
	 * @param string $desc  Description.
	 */
	private static function checkbox( $key, $label, $s, $desc = '' ) {
		list( $label, $desc ) = self::texts( $key, $label, $desc );
		// The checkbox is named by its full label (screen readers announce what is switched on).
		$described = '' !== trim( (string) $desc ) ? ' aria-describedby="wpac-desc-' . esc_attr( $key ) . '"' : '';
		printf( '<tr><th scope="row">%2$s</th><td><label for="wpac-%1$s"><input type="checkbox" id="wpac-%1$s" name="wpac[%1$s]" value="1"%5$s %3$s> %4$s</label>', esc_attr( $key ), esc_html( self::short_label( $label ) === $label ? '' : self::short_label( $label ) ), checked( (int) $s[ $key ], 1, false ), esc_html( $label ), $described ); // P5-06: the row title only when it is shorter than the label. // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $described is escaped.
		self::desc( $desc, $key );
		echo '</td></tr>';
	}

	/**
	 * Confirmation field for "delete all data" (only needed while it is still off).
	 *
	 * @param array $s Settings.
	 */
	private static function uninstall_confirm( $s ) {
		if ( ! empty( $s['delete_on_uninstall'] ) ) {
			return;
		}
		$error = isset( self::$kept['errors']['delete_on_uninstall'] ) ? (string) self::$kept['errors']['delete_on_uninstall'] : '';
		printf(
			'<tr><th><label for="wpac-delete_on_uninstall_confirm">%1$s</label></th><td><input type="text" id="wpac-delete_on_uninstall_confirm" name="wpac[delete_on_uninstall_confirm]" value="" class="regular-text" autocomplete="off" aria-describedby="%2$swpac-delete_on_uninstall_confirm-desc"%3$s>%4$s<p class="description" id="wpac-delete_on_uninstall_confirm-desc">%5$s</p></td></tr>',
			esc_html__( 'Confirmation', 'affiliate-captain' ),
			'' !== $error ? 'wpac-err-delete_on_uninstall ' : '',
			'' !== $error ? ' aria-invalid="true"' : '',
			'' !== $error ? '<p class="wpac-field-error" id="wpac-err-delete_on_uninstall" role="alert">' . esc_html( $error ) . '</p>' : '', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped here.
			esc_html__( 'Only to switch it on: type DELETE (or LÖSCHEN). Switching off and saving other fields need no word.', 'affiliate-captain' )
		);
	}

	/**
	 * Short row title for long checkbox labels (text before a colon or the first 6 words).
	 *
	 * @param string $label Label.
	 * @return string
	 */
	private static function short_label( $label ) {
		if ( false !== strpos( $label, ':' ) ) {
			return trim( strstr( $label, ':', true ) );
		}
		$words = preg_split( '/\s+/', $label );
		return count( $words ) > 6 ? implode( ' ', array_slice( $words, 0, 6 ) ) . ' …' : $label;
	}

	/**
	 * Select.
	 *
	 * @param string $key     Key.
	 * @param string $label   Label.
	 * @param array  $s       Settings.
	 * @param array  $options Options.
	 * @param string $desc    Description.
	 */
	private static function select( $key, $label, $s, array $options, $desc = '' ) {
		list( $label, $desc ) = self::texts( $key, $label, $desc );
		$err = self::error_parts( $key, '' !== trim( (string) $desc ) );
		printf( '<tr><th><label for="wpac-%1$s">%2$s</label></th><td><select id="wpac-%1$s" name="wpac[%1$s]"%3$s>', esc_attr( $key ), esc_html( $label ), $err[0] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- error_parts() is escaped.
		foreach ( $options as $v => $l ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $v ), selected( self::shown( $key, $s[ $key ] ), (string) $v, false ), esc_html( $l ) );
		}
		echo '</select>' . $err[1]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- error_parts() is escaped.
		self::desc( $desc, $key );
		echo '</td></tr>';
	}

	/**
	 * Page dropdown.
	 *
	 * @param string $key   Key.
	 * @param string|null $label Label (null: from the register).
	 * @param array       $s     Settings.
	 * @param string $desc  Description.
	 */
	private static function page_select( $key, $label, $s, $desc = '' ) {
		list( $label, $desc ) = self::texts( $key, $label, $desc );
		echo '<tr><th><label for="wpac-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
		wp_dropdown_pages(
			array(
				'name'              => 'wpac[' . $key . ']', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				'id'                => 'wpac-' . $key, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				'selected'          => (int) $s[ $key ],
				'show_option_none'  => '—', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				'option_none_value' => '0',
			)
		);
		self::desc( $desc, $key );
		echo '</td></tr>';
	}
}
