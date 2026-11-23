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
	 * @param bool $with_private Include private values (bank details, addresses, …).
	 * @return array
	 */
	public static function export( $with_private = false ) {
		$all  = Settings::all();
		$data = array();
		foreach ( SettingsSchema::core() as $key => $f ) {
			if ( ! empty( $f['secret'] ) || ! empty( $f['hidden'] ) || ! empty( $f['local'] ) || ( ! empty( $f['private'] ) && ! $with_private ) ) {
				continue;
			}
			$data[ $key ] = array_key_exists( $key, $all ) ? $all[ $key ] : $f['default'];
		}
		/**
		 * Extra sections of an add-on in the settings export (e.g. its own options).
		 *
		 * @param array $sections     section => data.
		 * @param bool  $with_private Whether private values were requested.
		 */
		$addons = function_exists( 'apply_filters' ) ? (array) apply_filters( 'wpac_settings_export', array(), (bool) $with_private ) : array();
		return array(
			'format'     => self::FORMAT,
			'plugin'     => defined( 'WPAC_VERSION' ) ? WPAC_VERSION : '',
			'contract'   => defined( 'WPAC_CONTRACT_VERSION' ) ? WPAC_CONTRACT_VERSION : '',
			'exported'   => gmdate( 'Y-m-d H:i:s' ),
			'private'    => (bool) $with_private,
			'settings'   => $data,
			'addons'     => $addons,
		);
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
		foreach ( $data['settings'] as $key => $value ) {
			$key = (string) $key;
			if ( ! isset( $core[ $key ] ) || ! empty( $core[ $key ]['secret'] ) || ! empty( $core[ $key ]['hidden'] ) || ! empty( $core[ $key ]['local'] ) || ! ( is_scalar( $value ) || null === $value ) ) {
				$ignored[] = $key;
				continue;
			}
			$wanted[ $key ] = null === $value ? '' : $value;
		}
		$check = self::check( $wanted, $current );
		// Sections of add-ons are not imported by the core (contract, wpac_settings_export; P6-01).
		if ( ! empty( $data['addons'] ) && is_array( $data['addons'] ) ) {
			foreach ( array_keys( $data['addons'] ) as $section ) {
				$ignored[] = 'addons:' . sanitize_key( (string) $section );
			}
		}
		// A running retention hold (tax audit) is never lifted by a file (P1-05).
		if ( ! empty( $current['retention_hold'] ) && array_key_exists( 'retention_hold', $wanted ) && empty( $wanted['retention_hold'] ) ) {
			unset( $check['changes']['retention_hold'], $check['values']['retention_hold'] );
			$check['errors']['retention_hold'] = __( 'A running retention hold is only lifted on the settings page.', 'affiliate-captain' );
		}
		$check['ignored'] = $ignored;
		return $check;
	}

	/**
	 * Check new values with the rules of the settings page.
	 *
	 * @param array $wanted  key => new value (stored form, amounts in minor units).
	 * @param array $current Current settings.
	 * @return array { changes, errors, values }
	 */
	public static function check( array $wanted, array $current ) {
		// The settings page sends every field of a tab; here every stored value is sent and the
		// wanted ones replace them, so absent checkboxes are not read as "off".
		$input   = $current;
		$bool_in = array();
		foreach ( $wanted as $key => $value ) {
			$f = SettingsSchema::field( (string) $key );
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
		foreach ( array( 'min_payout_minor' => 'min_payout', 'fraud_max_order_minor' => 'fraud_max_order' ) as $minor_key => $field ) {
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
		}
		if ( 'fixed' === (string) $input['default_rate_type'] && is_numeric( $input['default_rate'] ) ) {
			$old_currency = (string) $current['currency'];
			// Same currency as Settings::sanitize() reads it in: the old one while a stored fixed rate is converted.
			$rate_in                = ( 'fixed' === (string) $current['default_rate_type'] && strtoupper( $currency ) !== strtoupper( $old_currency ) && ! array_key_exists( 'default_rate', $wanted ) ) ? $old_currency : $currency;
			$input['default_rate'] = Money::to_decimal( (int) $input['default_rate'], $rate_in );
		}
		$extra  = $errors;
		$clean  = Settings::sanitize( $input, $errors );
		$errors = array_merge( $errors, $extra );
		$out    = array(
			'changes' => array(),
			'errors'  => array(),
			'values'  => array(),
		);
		$field_of = array(
			'min_payout'      => 'min_payout_minor',
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
			$keys = array_unique( array_merge( $keys, array( 'min_payout_minor', 'fraud_max_order_minor', 'exchange_rates', 'default_rate' ) ) );
		}
		foreach ( $keys as $key ) {
			if ( isset( $out['errors'][ $key ] ) || ! array_key_exists( $key, $clean ) ) {
				continue;
			}
			$new = $clean[ $key ];
			if ( (string) $new !== (string) ( isset( $current[ $key ] ) ? $current[ $key ] : '' ) ) {
				$out['changes'][ $key ] = array( isset( $current[ $key ] ) ? $current[ $key ] : '', $new );
				$out['values'][ $key ]  = $new;
			}
		}
		return $out;
	}

	/**
	 * Apply checked values (the result of preview() or check()) and write the audit log.
	 *
	 * @param array  $checked Result with "values" and "changes".
	 * @param string $action  Audit action (settings.imported, settings.reset, settings.cli).
	 * @param array  $context Extra audit details.
	 * @return bool
	 */
	public static function apply( array $checked, $action, array $context = array() ) {
		$values = isset( $checked['values'] ) ? (array) $checked['values'] : array();
		if ( ! $values ) {
			return true;
		}
		$old = Settings::all();
		if ( ! Settings::update( $values ) ) {
			return false;
		}
		Audit::log( $action, 'settings', 0, $context + array( 'changed' => array_keys( $values ), 'values' => \WPAffiliateCaptain\Admin\Pages\SettingsPage::audit_values( array_keys( $values ), $old, $values ) ) );
		return true;
	}

	/**
	 * Keys a reset of a tab (or of everything, tab '') would set back to the default.
	 *
	 * @param string $tab Tab key or '' for all tabs.
	 * @return string[]
	 */
	public static function reset_keys( $tab = '' ) {
		$keys = array();
		foreach ( SettingsSchema::core() as $key => $f ) {
			if ( ( '' !== $tab && $f['tab'] !== $tab ) || ! empty( $f['hidden'] ) || ! empty( $f['secret'] ) || ! empty( $f['private'] ) || ! empty( $f['local'] ) || ! empty( $f['legal'] ) || in_array( $key, self::RESET_PROTECTED, true ) ) {
				continue;
			}
			$keys[] = $key;
		}
		return $keys;
	}

	/**
	 * Check a reset of keys to their defaults (preview: old → default).
	 *
	 * @param string[] $keys Keys.
	 * @return array { changes, errors, values }
	 */
	public static function reset_preview( array $keys ) {
		$defaults = SettingsSchema::defaults();
		$wanted   = array();
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $defaults ) && 'monitor_token' !== $key ) {
				$wanted[ $key ] = $defaults[ $key ];
			}
		}
		return self::check( $wanted, Settings::all() );
	}
}
