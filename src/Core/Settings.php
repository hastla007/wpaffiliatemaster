<?php
/**
 * Plugin settings.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Core;

defined( 'ABSPATH' ) || exit;

final class Settings {

	const OPTION = 'wpac_settings';

	/** @var array|null */
	private static $cache = null;

	/**
	 * Defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// Tracking & attribution.
			'referral_param'          => 'partner',
			'touch_model'             => 'last',     // last|first.
			'cookie_mode'             => 'consent',  // off|consent|always.
			'cookie_days'             => 30,
			'use_wc_session'          => 1,
			'checkout_field'          => 1,
			'coupon_priority'         => 1,
			'self_referral'           => 'block',    // block|review.
			'rate_limit_per_minute'   => 30,
			'visit_retention_days'    => 90,
			// Commissions.
			'currency'                => 'EUR',
			'default_rate_type'       => 'percent',  // percent|fixed.
			'default_rate'            => '10',
			'hold_days'               => 30,
			// Program.
			'auto_approve'            => 0,
			'terms_page_id'           => 0,
			'terms_version'           => '1.0',
			'portal_page_id'          => 0,
			'registration_page_id'    => 0,
			'terminated_open'         => 'pay',      // pay|reject.
			'admin_email'             => '',
			// Payouts.
			'min_payout_minor'        => 5000,
			'payout_period'           => 'monthly',
			'debtor_name'             => '',
			'debtor_iban'             => '',
			'debtor_bic'              => '',
			'remittance_prefix'       => 'Provision',
			// Housekeeping.
			'delete_on_uninstall'     => 0,
		);
	}

	/**
	 * All settings.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		}
		return self::$cache;
	}

	/**
	 * One setting.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : null;
	}

	/**
	 * Persist settings (already sanitized).
	 *
	 * @param array $values Values.
	 */
	public static function update( array $values ) {
		$merged = array_merge( self::all(), $values );
		update_option( self::OPTION, $merged, false );
		self::$cache = null;
	}

	/**
	 * Sanitize raw input from the settings form.
	 *
	 * @param array $in Raw input (unslashed).
	 * @return array
	 */
	public static function sanitize( array $in ) {
		$d   = self::defaults();
		$out = array();

		$param                 = isset( $in['referral_param'] ) ? preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $in['referral_param'] ) ) : '';
		$out['referral_param'] = '' !== $param ? $param : $d['referral_param'];

		$out['touch_model']       = self::choice( $in, 'touch_model', array( 'last', 'first' ), $d );
		$out['cookie_mode']       = self::choice( $in, 'cookie_mode', array( 'off', 'consent', 'always' ), $d );
		$out['self_referral']     = self::choice( $in, 'self_referral', array( 'block', 'review' ), $d );
		$out['default_rate_type'] = self::choice( $in, 'default_rate_type', array( 'percent', 'fixed' ), $d );
		$out['terminated_open']   = self::choice( $in, 'terminated_open', array( 'pay', 'reject' ), $d );
		$out['payout_period']     = self::choice( $in, 'payout_period', array( 'monthly', 'quarterly', 'manual' ), $d );

		foreach ( array( 'use_wc_session', 'checkout_field', 'coupon_priority', 'auto_approve', 'delete_on_uninstall' ) as $flag ) {
			$out[ $flag ] = empty( $in[ $flag ] ) ? 0 : 1;
		}

		foreach ( array( 'cookie_days', 'rate_limit_per_minute', 'visit_retention_days', 'hold_days', 'terms_page_id', 'portal_page_id', 'registration_page_id' ) as $int ) {
			$out[ $int ] = isset( $in[ $int ] ) ? max( 0, (int) $in[ $int ] ) : $d[ $int ];
		}

		$currency        = isset( $in['currency'] ) ? strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $in['currency'] ) ) : '';
		$out['currency'] = 3 === strlen( $currency ) ? $currency : $d['currency'];

		$out['default_rate'] = isset( $in['default_rate'] ) ? Money::normalize_decimal( (string) $in['default_rate'] ) : $d['default_rate'];
		if ( null === $out['default_rate'] ) {
			$out['default_rate'] = $d['default_rate'];
		}
		if ( 'fixed' === $out['default_rate_type'] ) {
			// Fixed default rate is entered as a decimal amount, stored as minor units.
			$out['default_rate'] = (string) Money::to_minor( $out['default_rate'], $out['currency'] );
		}

		$min                     = isset( $in['min_payout'] ) ? Money::normalize_decimal( (string) $in['min_payout'] ) : null;
		$out['min_payout_minor'] = null !== $min ? Money::to_minor( $min, $out['currency'] ) : $d['min_payout_minor'];

		$out['terms_version']     = isset( $in['terms_version'] ) ? sanitize_text_field( (string) $in['terms_version'] ) : $d['terms_version'];
		$out['admin_email']       = isset( $in['admin_email'] ) ? sanitize_email( (string) $in['admin_email'] ) : '';
		$out['debtor_name']       = isset( $in['debtor_name'] ) ? sanitize_text_field( (string) $in['debtor_name'] ) : '';
		$out['remittance_prefix'] = isset( $in['remittance_prefix'] ) ? sanitize_text_field( (string) $in['remittance_prefix'] ) : $d['remittance_prefix'];
		$out['debtor_bic']        = isset( $in['debtor_bic'] ) ? strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $in['debtor_bic'] ) ) : '';
		$iban                     = isset( $in['debtor_iban'] ) ? Iban::normalize( (string) $in['debtor_iban'] ) : '';
		$out['debtor_iban']       = ( '' === $iban || Iban::is_valid( $iban ) ) ? $iban : self::get( 'debtor_iban' );

		return $out;
	}

	/**
	 * Pick an allowed choice.
	 *
	 * @param array  $in      Input.
	 * @param string $key     Key.
	 * @param array  $allowed Allowed.
	 * @param array  $d       Defaults.
	 * @return string
	 */
	private static function choice( array $in, $key, array $allowed, array $d ) {
		return ( isset( $in[ $key ] ) && in_array( $in[ $key ], $allowed, true ) ) ? $in[ $key ] : $d[ $key ];
	}

	/**
	 * Reset the in-memory cache (tests).
	 */
	public static function flush_cache() {
		self::$cache = null;
	}
}
