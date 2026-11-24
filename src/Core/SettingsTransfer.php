<?php
/**
 * Export, import and reset of the settings (SET-AC-08, -09, -10): one logic for the settings page,
 * the import/export page and WP-CLI. Everything goes through the register (SettingsSchema) and the
 * same checks as the settings page (Settings::sanitize()).
 *
 * - Secret values (monitoring token) are never exported or imported.
 * - Private values (bank details, addresses, e-mail addresses, tax numbers) are only exported on
 *   request and are then imported like any other value.
 * - A reset never touches the program currency, the document number prefixes, secret or private
 *   values unless they are named one by one (WP-CLI "reset <key>").
 * - Optional sections (SET-AC-08a): e-mail templates, partner groups, commission rules and tier
 *   plans, see SettingsSections. Files without them import as before.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Core;

defined( 'ABSPATH' ) || exit;

final class SettingsTransfer {

	/** Version of the export format. */
	const FORMAT = 1;

	/** Keys that a reset of a tab or of everything never changes (SET-AC-09); values marked "legal" are kept too (P1-05). */
	const RESET_PROTECTED = array( 'currency', 'credit_prefix', 'storno_prefix', 'correction_prefix' );

	/**
	 * Export of the core settings as an array (JSON-ready).
	 *
	 * @param bool     $with_private Include private values (bank details, addresses, …).
	 * @param string[] $sections     Optional sections (SET-AC-08a): groups, rules, tiers, mail_templates.
	 * @return array
	 */
	public static function export( $with_private = false, array $sections = array() ) {
		$all  = Settings::all();
		$data = array();
		foreach ( SettingsSchema::core() as $key => $f ) {
			if ( ! empty( $f['secret'] ) || ! empty( $f['hidden'] ) || ! empty( $f['local'] ) || ( ! empty( $f['private'] ) && ! $with_private ) ) {
				continue;
			}
			$data[ $key ] = array_key_exists( $key, $all ) ? $all[ $key ] : $f['default'];
			if ( 'page' === $f['type'] ) {
				// Page IDs differ between sites: the page travels by its path (P1-12). Older versions
				// skip the array (not a plain value) instead of taking a foreign ID.
				$path = self::page_path( (int) $data[ $key ] );
				if ( '' === $path && (int) $data[ $key ] > 0 ) {
					unset( $data[ $key ] ); // A page without a path (e.g. a draft never saved with a slug) cannot be found elsewhere.
					continue;
				}
				$data[ $key ] = array( 'page' => $path );
			}
		}
		/**
		 * Extra sections of an add-on in the settings export (e.g. its own options).
		 *
		 * @param array $sections     section => data.
		 * @param bool  $with_private Whether private values were requested.
		 */
		$addons = function_exists( 'apply_filters' ) ? (array) apply_filters( 'wpac_settings_export', array(), (bool) $with_private ) : array();
		$out    = array(
			'format'     => self::FORMAT,
			'plugin'     => defined( 'WPAC_VERSION' ) ? WPAC_VERSION : '',
			'contract'   => defined( 'WPAC_CONTRACT_VERSION' ) ? WPAC_CONTRACT_VERSION : '',
			'exported'   => gmdate( 'Y-m-d H:i:s' ),
			'private'    => (bool) $with_private,
			'settings'   => $data,
			'addons'     => $addons,
		);
		$sections = SettingsSections::clean( $sections );
		if ( $sections ) {
			// The format number stays 1: older versions import the settings and skip the sections.
			$out['sections_format'] = SettingsSections::FORMAT;
			$out['sections']        = SettingsSections::export( $sections );
		}
		return $out;
	}

	/**
	 * Check an import without changing anything: which keys would change (old → new), which values
	 * are rejected and which keys are unknown or not importable.
	 *
	 * @param mixed $data Decoded export.
	 * @return array|\WP_Error { changes: key => [from, to], errors: key => message, ignored: keys, values: key => value }
	 */
	public static function preview( $data ) {
		if ( ! is_array( $data ) || ! isset( $data['format'], $data['settings'] ) || ! is_array( $data['settings'] ) ) {
			return new \WP_Error( 'wpac_import_format', __( 'This is not a settings export of Affiliate Captain.', 'affiliate-captain' ) );
		}
		if ( (int) $data['format'] > self::FORMAT ) {
			return new \WP_Error( 'wpac_import_format', __( 'The file comes from a newer version of Affiliate Captain. Update the plugin first.', 'affiliate-captain' ) );
		}
		$core    = SettingsSchema::core();
		$current = Settings::all();
		$wanted  = array();
		$ignored = array();
		$refused = array();
		// Files of older versions may carry the former key payout_period (SET-AC-31): mapped, not ignored.
		foreach ( Settings::map_legacy_import( $data['settings'] ) as $key => $value ) {
			$key = (string) $key;
			// Private values (bank details, addresses) only from an export made with them (P4-02).
			if ( isset( $core[ $key ] ) && ! empty( $core[ $key ]['private'] ) && empty( $data['private'] ) ) {
				$ignored[] = $key;
				continue;
			}
			if ( isset( $core[ $key ] ) && 'page' === $core[ $key ]['type'] && is_array( $value ) && empty( $core[ $key ]['local'] ) ) {
				// Page by its path (P1-12): resolved on this site, otherwise rejected at the key.
				$value = self::page_from_ref( $value, $refused, $key );
				if ( null === $value ) {
					continue;
				}
			}
			if ( ! isset( $core[ $key ] ) || ! empty( $core[ $key ]['secret'] ) || ! empty( $core[ $key ]['hidden'] ) || ! empty( $core[ $key ]['local'] ) || ! ( is_scalar( $value ) || null === $value ) ) {
				$ignored[] = $key;
				continue;
			}
			$wanted[ $key ] = null === $value ? '' : $value;
		}
		// A running retention hold (tax audit) is never lifted by a file (P1-05): that key is skipped
		// with a notice, the rest of the file is imported (P1-19).
		$hold_kept = ! empty( $current['retention_hold'] ) && array_key_exists( 'retention_hold', $wanted ) && in_array( strtolower( trim( (string) $wanted['retention_hold'] ) ), array( '0', 'false', 'no', 'off', '' ), true );
		if ( $hold_kept ) {
			unset( $wanted['retention_hold'] );
		}
		$check = self::check( $wanted, $current );
		$check['errors'] = array_merge( $check['errors'], $refused );
		if ( $hold_kept ) {
			$check['notices']['retention_hold'] = __( 'Not imported: a running retention hold is only lifted on the settings page. The other values are imported.', 'affiliate-captain' );
		}
		// Sections of add-ons are not imported by the core (contract, wpac_settings_export; P6-01).
		if ( ! empty( $data['addons'] ) && is_array( $data['addons'] ) ) {
			foreach ( array_keys( $data['addons'] ) as $section ) {
				$ignored[] = 'addons:' . sanitize_key( (string) $section );
			}
		}
		// Optional sections (SET-AC-08a), checked against the program currency after the import.
		$check['sections']      = array();
		$check['sections_data'] = array();
		if ( isset( $data['sections'] ) && is_array( $data['sections'] ) ) {
			if ( isset( $data['sections_format'] ) && (int) $data['sections_format'] > SettingsSections::FORMAT ) {
				$ignored[] = 'sections';
			} else {
				$currency               = isset( $check['values']['currency'] ) ? (string) $check['values']['currency'] : (string) $current['currency'];
				$sections               = SettingsSections::preview( $data['sections'], $currency );
				$ignored                = array_merge( $ignored, $sections['ignored'] );
				$check['sections']      = $sections['sections'];
				$check['sections_data'] = array_intersect_key( $data['sections'], $sections['sections'] );
			}
		}
		$check['ignored'] = $ignored;
		return $check;
	}

	/**
	 * Check new values with the rules of the settings page.
	 *
	 * @param array $wanted  key => new value (stored form, amounts in minor units).
	 * @param array $current Current settings.
	 * @return array { changes, errors, notices, values }; notices = accepted but adjusted values
	 *               (e.g. amounts kept after a currency change without exchange rate, P3-12).
	 */
	public static function check( array $wanted, array $current ) {
		// The settings page sends every field of a tab; here every stored value is sent and the
		// wanted ones replace them, so absent checkboxes are not read as "off".
		$input   = $current;
		$bool_in = array();
		$fixed   = 'fixed' === (string) ( isset( $wanted['default_rate_type'] ) ? $wanted['default_rate_type'] : $current['default_rate_type'] );
		foreach ( $wanted as $key => $value ) {
			$f = SettingsSchema::field( (string) $key );
			if ( ( ( $f && 'money' === $f['type'] ) || ( 'default_rate' === $key && $fixed ) ) && ! self::is_minor( $value ) ) {
				// Stored form = whole minor units (P1-11): "abc", "2000,00", "12.5" or "50 EUR" are
				// rejected at the key instead of being cut to a number.
				$bool_in[ $key ] = __( 'Please give a whole number of minor units (e.g. 5000 for 50.00), 0 or more.', 'affiliate-captain' );
				continue;
			}
			if ( $f && 'bool' === $f['type'] && ! is_bool( $value ) ) {
				// Words from WP-CLI or a file (P1-02): "false", "no", "off" mean off; anything unknown is an error.
				$word = strtolower( trim( (string) $value ) );
				if ( in_array( $word, array( '1', 'true', 'yes', 'on' ), true ) ) {
					$value = 1;
				} elseif ( in_array( $word, array( '0', 'false', 'no', 'off', '' ), true ) ) {
					$value = 0;
				} else {
					$bool_in[ $key ] = __( 'Please use 1 or 0 (on or off).', 'affiliate-captain' );
					continue;
				}
				$wanted[ $key ] = $value;
			}
			$input[ $key ] = $value;
		}
		$currency = isset( $input['currency'] ) ? (string) $input['currency'] : (string) $current['currency'];
		unset( $input['monitor_token'] );
		// A currency change converts the stored amounts like the settings page does, unless they
		// are given together with the new currency (P1-04).
		$fx_change = strtoupper( $currency ) !== strtoupper( (string) $current['currency'] );
		foreach ( array( 'min_payout_minor' => 'min_payout', 'max_payout_minor' => 'max_payout', 'fraud_max_order_minor' => 'fraud_max_order' ) as $minor_key => $field ) {
			if ( ! $fx_change || array_key_exists( $minor_key, $wanted ) ) {
				$input[ $field ] = Money::to_decimal( (int) $input[ $minor_key ], $currency );
			}
		}
		$errors = $bool_in;
		// A fixed default rate is stored in minor units, the form takes it as an amount (P1-01).
		$type_changed = isset( $wanted['default_rate_type'] ) && (string) $wanted['default_rate_type'] !== (string) $current['default_rate_type'];
		if ( $type_changed && ! array_key_exists( 'default_rate', $wanted ) ) {
			$errors['default_rate_type'] = __( 'Change the type of the default rate only together with the rate itself.', 'affiliate-captain' );
			unset( $input['default_rate_type'] );
			$input['default_rate_type'] = $current['default_rate_type'];
		} elseif ( $type_changed && isset( $bool_in['default_rate'] ) ) {
			// Rate and type belong together: a rejected rate keeps the type as well.
			$errors['default_rate_type'] = __( 'Not changed, because the default rate entered with it is not valid.', 'affiliate-captain' );
			$input['default_rate_type']  = $current['default_rate_type'];
		}
		if ( 'fixed' === (string) $input['default_rate_type'] && is_numeric( $input['default_rate'] ) ) {
			$old_currency = (string) $current['currency'];
			// Same currency as Settings::sanitize() reads it in: the old one while a stored fixed rate is converted.
			$rate_in                = ( 'fixed' === (string) $current['default_rate_type'] && strtoupper( $currency ) !== strtoupper( $old_currency ) && ! array_key_exists( 'default_rate', $wanted ) ) ? $old_currency : $currency;
			$input['default_rate'] = Money::to_decimal( (int) $input['default_rate'], $rate_in );
		}
		$extra   = $errors;
		$notices = array();
		$clean   = Settings::sanitize( $input, $errors, $notices );
		$errors  = array_merge( $errors, $extra );
		$out     = array(
			'changes' => array(),
			'errors'  => array(),
			'notices' => array(),
			'values'  => array(),
		);
		$field_of = array(
			'min_payout'      => 'min_payout_minor',
			'max_payout'      => 'max_payout_minor',
			'fraud_max_order' => 'fraud_max_order_minor',
		);
		foreach ( $errors as $field => $message ) {
			$key = isset( $field_of[ $field ] ) ? $field_of[ $field ] : $field;
			if ( array_key_exists( $key, $wanted ) ) {
				$out['errors'][ $key ] = $message;
			}
		}
		$keys = array_keys( $wanted );
		if ( $fx_change && ! isset( $out['errors']['currency'] ) ) {
			// Values derived from the currency change go along with it.
			$keys = array_unique( array_merge( $keys, array( 'min_payout_minor', 'max_payout_minor', 'fraud_max_order_minor', 'exchange_rates', 'min_payout_currency', 'default_rate' ) ) );
		}
		if ( array_intersect( array( 'exchange_rates', 'currency' ), $keys ) ) {
			$keys[] = 'exchange_rates_date'; // Follows the rates (SET-AC-29).
		}
		foreach ( array_unique( $keys ) as $key ) {
			if ( isset( $out['errors'][ $key ] ) || ! array_key_exists( $key, $clean ) ) {
				continue;
			}
			$new = $clean[ $key ];
			if ( (string) $new !== (string) ( isset( $current[ $key ] ) ? $current[ $key ] : '' ) ) {
				$out['changes'][ $key ] = array( isset( $current[ $key ] ) ? $current[ $key ] : '', $new );
				$out['values'][ $key ]  = $new;
			}
		}
		foreach ( $notices as $field => $message ) {
			// Notices only for values that change (P3-12), e.g. amounts kept after a currency change.
			$key = isset( $field_of[ $field ] ) ? $field_of[ $field ] : $field;
			if ( array_key_exists( $key, $out['values'] ) ) {
				$out['notices'][ $key ] = $message;
			}
		}
		return $out;
	}

	/**
	 * Apply checked values (the result of preview() or check()) and write the audit log. Sections
	 * of an import (SET-AC-08a) follow the settings: only those in "sections_selected" when given.
	 *
	 * @param array  $checked Result with "values" and "changes" (and "sections_data").
	 * @param string $action  Audit action (settings.imported, settings.reset, settings.cli).
	 * @param array  $context Extra audit details.
	 * @return bool
	 */
	public static function apply( array $checked, $action, array $context = array() ) {
		$values = isset( $checked['values'] ) ? (array) $checked['values'] : array();
		if ( $values ) {
			$old = Settings::all();
			if ( ! Settings::update( $values ) ) {
				return false;
			}
			Audit::log( $action, 'settings', 0, $context + array( 'changed' => array_keys( $values ), 'values' => \WPAffiliateCaptain\Admin\Pages\SettingsPage::audit_values( array_keys( $values ), $old, $values ) ) );
		}
		SettingsSections::apply( ! empty( $checked['sections_data'] ) ? $checked['sections_data'] : array(), (string) Settings::get( 'currency' ), isset( $checked['sections_selected'] ) ? (array) $checked['sections_selected'] : null, $context );
		return true;
	}

	/**
	 * Keys a reset of a tab (or of everything, tab '') would set back to the default. Chosen pages
	 * (terms, partner area, application, landing and imprint page) are kept like the protected
	 * keys (P1-12); a page is only reset when named as a key.
	 *
	 * @param string $tab Tab key or '' for all tabs.
	 * @return string[]
	 */
	public static function reset_keys( $tab = '' ) {
		$keys = array();
		foreach ( SettingsSchema::core() as $key => $f ) {
			if ( ( '' !== $tab && $f['tab'] !== $tab ) || ! empty( $f['hidden'] ) || ! empty( $f['secret'] ) || ! empty( $f['private'] ) || ! empty( $f['local'] ) || ! empty( $f['legal'] ) || 'page' === $f['type'] || in_array( $key, self::RESET_PROTECTED, true ) ) {
				continue;
			}
			$keys[] = $key;
		}
		return $keys;
	}

	/**
	 * Check a reset of keys to their defaults (preview: old → default). The default rate is only
	 * reset together with its type (10 means 10 %, never 0.10 of a fixed amount), and amounts are
	 * defaults as amounts (50.00 = 50 in the program currency), converted to the minor units of
	 * the program currency (P1-14).
	 *
	 * @param string[] $keys Keys.
	 * @return array { changes, errors, notices, values }
	 */
	public static function reset_preview( array $keys ) {
		$defaults = SettingsSchema::defaults();
		$current  = Settings::all();
		$wanted   = array();
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $defaults ) && 'monitor_token' !== $key ) {
				$wanted[ $key ] = $defaults[ $key ];
			}
		}
		if ( array_key_exists( 'default_rate', $wanted ) || array_key_exists( 'default_rate_type', $wanted ) ) {
			$wanted['default_rate']      = $defaults['default_rate'];
			$wanted['default_rate_type'] = $defaults['default_rate_type'];
		}
		$currency = isset( $wanted['currency'] ) ? (string) $wanted['currency'] : (string) $current['currency'];
		foreach ( $wanted as $key => $value ) {
			$f = SettingsSchema::field( (string) $key );
			if ( $f && 'money' === $f['type'] ) {
				$wanted[ $key ] = Money::to_minor( self::default_amount( (string) $key ), $currency );
			}
		}
		return self::check( $wanted, $current );
	}

	/**
	 * Default of an amount setting as a decimal amount in the program currency. The register keeps
	 * money defaults in cents of a currency with two decimals (5000 = 50.00).
	 *
	 * @param string $key Key of a money setting.
	 * @return string
	 */
	public static function default_amount( $key ) {
		$f = SettingsSchema::field( $key );
		return Money::to_decimal( (int) ( $f ? $f['default'] : 0 ), 'EUR' );
	}

	/**
	 * Value for a preview table (settings page, wizard, WP-CLI): amounts formatted in the program
	 * currency (P1-14), a fixed default rate as an amount, a percentage with "%".
	 *
	 * @param string $key      Key.
	 * @param mixed  $value    Stored value.
	 * @param array  $settings Settings the value belongs to (currency, default_rate_type).
	 * @return string
	 */
	public static function shown( $key, $value, array $settings ) {
		$f        = SettingsSchema::field( (string) $key );
		$currency = isset( $settings['currency'] ) ? (string) $settings['currency'] : (string) Settings::get( 'currency' );
		if ( ( $f && 'money' === $f['type'] ) || ( 'default_rate' === $key && 'fixed' === (string) ( isset( $settings['default_rate_type'] ) ? $settings['default_rate_type'] : '' ) ) ) {
			return is_numeric( $value ) ? Money::format( (int) $value, $currency ) : (string) $value;
		}
		if ( 'default_rate' === $key && is_numeric( $value ) ) {
			return $value . ' %';
		}
		return is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );
	}

	/**
	 * Old and new settings of a checked change, for shown(): the new ones are the current ones
	 * with the checked values.
	 *
	 * @param array $checked Result of check() or preview().
	 * @return array[] [ old settings, new settings ]
	 */
	public static function sides( array $checked ) {
		$old = Settings::all();
		return array( $old, array_merge( $old, isset( $checked['values'] ) ? (array) $checked['values'] : array() ) );
	}

	/**
	 * Whether a value is a whole number of minor units (0 or more).
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function is_minor( $value ) {
		if ( is_int( $value ) ) {
			return $value >= 0;
		}
		return is_string( $value ) && (bool) preg_match( '/^\d{1,18}$/', trim( $value ) );
	}

	/**
	 * Path of a page for the export ('' = none or not found).
	 *
	 * @param int $id Page ID.
	 * @return string
	 */
	private static function page_path( $id ) {
		if ( $id <= 0 ) {
			return '';
		}
		if ( function_exists( 'get_page_uri' ) ) {
			$uri = get_page_uri( $id );
			if ( is_string( $uri ) && '' !== $uri ) {
				return $uri;
			}
		}
		$post = function_exists( 'get_post' ) ? get_post( $id ) : null;
		return is_object( $post ) && ! empty( $post->post_name ) ? (string) $post->post_name : '';
	}

	/**
	 * Page ID of an exported page reference { page: path } on this site.
	 *
	 * @param array  $ref     Reference from the file.
	 * @param array  $refused Out: key => message for a page that does not exist here.
	 * @param string $key     Setting key.
	 * @return int|null ID (0 = none), null when not found.
	 */
	private static function page_from_ref( array $ref, array &$refused, $key ) {
		$path = isset( $ref['page'] ) && is_scalar( $ref['page'] ) ? trim( (string) $ref['page'], " \t\n\r\0\x0B/" ) : '';
		if ( '' === $path ) {
			return 0;
		}
		$post = function_exists( 'get_page_by_path' ) ? get_page_by_path( $path, OBJECT, 'page' ) : null;
		if ( is_object( $post ) && ! empty( $post->ID ) ) {
			return (int) $post->ID;
		}
		/* translators: %s: path (slug) of a page */
		$refused[ $key ] = sprintf( __( 'There is no page "%s" on this site. Create it or choose the page after the import.', 'affiliate-captain' ), sanitize_text_field( $path ) );
		return null;
	}
}
