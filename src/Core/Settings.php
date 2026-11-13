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
			'renewal_commission'      => 1,
			'js_tracking'             => 0,
			'coupon_priority'         => 1,
			'self_referral'           => 'block',    // block|review.
			'rate_limit_per_minute'   => 30,
			'trusted_proxies'         => '',
			'visit_retention_days'    => 90,
			'audit_retention_days'    => 1095,
			'application_retention_days' => 180,
			// Commissions.
			'currency'                => 'EUR',
			'exchange_rates'          => '',
			'default_rate_type'       => 'percent',  // percent|fixed.
			'default_rate'            => '10',
			'hold_days'               => 30,
			'lifetime_enabled'        => 0,
			'lifetime_days'           => 365,
			'lifetime_rate'           => '',
			// Program.
			'auto_approve'            => 0,
			'terms_page_id'           => 0,
			'terms_version'           => '1.0',
			'portal_page_id'          => 0,
			'registration_page_id'    => 0,
			'terminated_open'         => 'pay',      // pay|reject.
			'admin_email'             => '',
			'maintenance_mode'        => 0,
			'monitor_token'           => '',
			// Promotion.
			'landing_prefix'          => 'partner',
			'landing_page_id'         => 0,
			'default_disclosure'      => 'Werbung',
			'promotion_guidelines'    => '',
			// Payouts.
			'min_payout_minor'        => 5000,
			'payout_requests'         => 0,
			'statement_retention_years' => 10,
			'retention_hold'          => 0,
			'payout_period'           => 'monthly',
			'debtor_name'             => '',
			'debtor_iban'             => '',
			'debtor_bic'              => '',
			'remittance_prefix'       => 'Provision',
			// Credit notes (self-billing).
			'self_billing'            => 1,
			'vat_rate'                => '19',
			'operator_country'        => 'DE',
			'operator_address'        => '',
			'operator_vat_id'         => '',
			'operator_tax_number'     => '',
			'credit_prefix'           => 'GS',
			'storno_prefix'           => 'ST',
			'vies_check'              => 0, // Check partner VAT IDs with the EU Commission's VIES service (external request).
			// Fraud rules (0 = off).
			'fraud_max_orders_day'    => 0,
			'fraud_max_order_minor'   => 0,
			'fraud_min_click_seconds' => 0,
			'fraud_max_refund_rate'   => 0,
			'fraud_check_domains'     => 1,
			'fraud_blocked_domains'   => '',
			// Reporting.
			'report_admin'            => 1,
			'report_partners'         => 0,
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
	 * One setting (or runtime state, see RUNTIME).
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public static function get( $key ) {
		if ( isset( self::RUNTIME[ $key ] ) ) {
			return self::state( $key );
		}
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : null;
	}

	/**
	 * Runtime state the scheduled tasks write (own options, never part of the settings array:
	 * writing the whole settings array back from a cron run would undo settings an admin saved
	 * meanwhile). Key => option name.
	 */
	const RUNTIME = array(
		'tier_month_closed' => 'wpac_tier_month_closed',
		'tier_month_first'  => 'wpac_tier_month_first', // First month that was ever closed (earlier months never get bonuses).
	);

	/**
	 * Read runtime state ('' when not set; values of older versions are still read from the settings).
	 *
	 * @param string $key Key of RUNTIME.
	 * @return string
	 */
	public static function state( $key ) {
		if ( ! isset( self::RUNTIME[ $key ] ) ) {
			return '';
		}
		$value = get_option( self::RUNTIME[ $key ], null );
		if ( null === $value ) {
			$saved = get_option( self::OPTION, array() );
			$value = is_array( $saved ) && isset( $saved[ $key ] ) ? $saved[ $key ] : '';
		}
		return (string) $value;
	}

	/**
	 * Write runtime state (only its own option).
	 *
	 * @param string $key   Key of RUNTIME.
	 * @param string $value Value.
	 */
	public static function set_state( $key, $value ) {
		if ( isset( self::RUNTIME[ $key ] ) ) {
			update_option( self::RUNTIME[ $key ], (string) $value, false );
		}
	}

	/**
	 * Persist settings (already sanitized). Runtime state goes to its own options.
	 *
	 * @param array $values Values.
	 */
	public static function update( array $values ) {
		foreach ( self::RUNTIME as $key => $option ) {
			if ( array_key_exists( $key, $values ) ) {
				self::set_state( $key, (string) $values[ $key ] );
				unset( $values[ $key ] );
			} elseif ( null === get_option( $option, null ) && '' !== self::state( $key ) ) {
				self::set_state( $key, self::state( $key ) ); // Value of an older version: keep it.
			}
		}
		$merged = array_diff_key( array_merge( self::all(), $values ), self::RUNTIME );
		update_option( self::OPTION, $merged, true ); // Read on every request → autoload.
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

		$lifetime_rate        = isset( $in['lifetime_rate'] ) ? Money::normalize_decimal( (string) $in['lifetime_rate'] ) : null;
		$out['lifetime_rate'] = ( null !== $lifetime_rate && (float) $lifetime_rate >= 0 && (float) $lifetime_rate <= 100 ) ? $lifetime_rate : '';

		foreach ( array( 'use_wc_session', 'checkout_field', 'coupon_priority', 'auto_approve', 'delete_on_uninstall', 'lifetime_enabled', 'payout_requests', 'report_admin', 'report_partners', 'fraud_check_domains', 'self_billing', 'retention_hold', 'renewal_commission', 'js_tracking', 'maintenance_mode', 'vies_check' ) as $flag ) {
			$out[ $flag ] = empty( $in[ $flag ] ) ? 0 : 1;
		}

		foreach ( array( 'audit_retention_days', 'application_retention_days', 'fraud_max_orders_day', 'fraud_min_click_seconds', 'fraud_max_refund_rate', 'statement_retention_years', 'lifetime_days', 'cookie_days', 'rate_limit_per_minute', 'visit_retention_days', 'hold_days', 'terms_page_id', 'portal_page_id', 'registration_page_id' ) as $int ) {
			$out[ $int ] = isset( $in[ $int ] ) ? max( 0, (int) $in[ $int ] ) : $d[ $int ];
		}
		// Credit notes: at least 10 years (DE §147 AO/§14b UStG, AT §132 BAO, CH Art. 958f OR); 0 = forever.
		if ( $out['statement_retention_years'] > 0 ) {
			// Never below the legal minimum of the operator's country (DE 8, AT 7, CH 10, else 10 years).
			$out['statement_retention_years'] = max( \WPAffiliateCaptain\Privacy\Retention::minimum_years( isset( $in['operator_country'] ) && '' !== (string) $in['operator_country'] ? strtoupper( substr( sanitize_text_field( (string) $in['operator_country'] ), 0, 2 ) ) : 'DE' ), $out['statement_retention_years'] );
		}

		$currency        = isset( $in['currency'] ) ? strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $in['currency'] ) ) : '';
		// Fields of other tabs are not posted: fall back to the stored value (needed for amount conversions).
		$out['currency'] = 3 === strlen( $currency ) ? $currency : ( self::get( 'currency' ) ? self::get( 'currency' ) : $d['currency'] );

		$rates = Fx::parse( isset( $in['exchange_rates'] ) ? (string) $in['exchange_rates'] : '' );
		$lines = array();
		foreach ( $rates as $cur => $rate ) {
			$lines[] = $cur . '=' . $rate;
		}
		$out['exchange_rates'] = implode( "\n", $lines );

		$out['default_rate'] = isset( $in['default_rate'] ) ? Money::normalize_decimal( (string) $in['default_rate'] ) : $d['default_rate'];
		if ( null === $out['default_rate'] ) {
			$out['default_rate'] = $d['default_rate'];
		}
		if ( 'fixed' === $out['default_rate_type'] ) {
			// Fixed default rate is entered as a decimal amount, stored as minor units.
			$out['default_rate'] = (string) Money::to_minor( $out['default_rate'], $out['currency'] );
		}

		$proxies = array();
		foreach ( preg_split( '/[\s,]+/', isset( $in['trusted_proxies'] ) ? (string) $in['trusted_proxies'] : '', -1, PREG_SPLIT_NO_EMPTY ) as $entry ) {
			$ip = explode( '/', $entry, 2 )[0];
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				$proxies[] = $entry;
			}
		}
		$out['trusted_proxies'] = implode( "\n", $proxies );

		$max_order                    = isset( $in['fraud_max_order'] ) ? Money::normalize_decimal( (string) $in['fraud_max_order'] ) : null;
		$out['fraud_max_order_minor'] = null !== $max_order ? max( 0, Money::to_minor( $max_order, $out['currency'] ) ) : 0;
		$out['fraud_blocked_domains'] = isset( $in['fraud_blocked_domains'] ) ? implode( "\n", array_filter( array_map( 'sanitize_text_field', preg_split( '/[\s,]+/', strtolower( (string) $in['fraud_blocked_domains'] ) ) ) ) ) : '';

		$min                     = isset( $in['min_payout'] ) ? Money::normalize_decimal( (string) $in['min_payout'] ) : null;
		$out['min_payout_minor'] = null !== $min ? Money::to_minor( $min, $out['currency'] ) : $d['min_payout_minor'];

		$landing               = isset( $in['landing_prefix'] ) ? sanitize_title( (string) $in['landing_prefix'] ) : '';
		$out['landing_prefix'] = '' !== $landing ? $landing : $d['landing_prefix'];
		$out['landing_page_id']      = isset( $in['landing_page_id'] ) ? absint( $in['landing_page_id'] ) : 0;
		$out['default_disclosure']   = isset( $in['default_disclosure'] ) && '' !== trim( (string) $in['default_disclosure'] ) ? sanitize_text_field( (string) $in['default_disclosure'] ) : $d['default_disclosure'];
		$out['promotion_guidelines'] = isset( $in['promotion_guidelines'] ) ? wp_kses_post( (string) $in['promotion_guidelines'] ) : '';

		$out['terms_version']     = isset( $in['terms_version'] ) ? sanitize_text_field( (string) $in['terms_version'] ) : $d['terms_version'];
		$out['admin_email']       = isset( $in['admin_email'] ) ? sanitize_email( (string) $in['admin_email'] ) : '';
		$token                    = isset( $in['monitor_token'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', (string) $in['monitor_token'] ) : '';
		$out['monitor_token']     = strlen( $token ) >= 20 ? substr( $token, 0, 64 ) : '';
		$out['debtor_name']       = isset( $in['debtor_name'] ) ? sanitize_text_field( (string) $in['debtor_name'] ) : '';
		$vat                         = isset( $in['vat_rate'] ) ? Money::normalize_decimal( (string) $in['vat_rate'] ) : null;
		$out['vat_rate']             = ( null !== $vat && (float) $vat >= 0 && (float) $vat < 100 ) ? $vat : $d['vat_rate'];
		$country                     = isset( $in['operator_country'] ) ? strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $in['operator_country'] ) ) : '';
		$out['operator_country']     = 2 === strlen( $country ) ? $country : $d['operator_country'];
		$out['operator_address']     = isset( $in['operator_address'] ) ? sanitize_textarea_field( (string) $in['operator_address'] ) : '';
		$out['operator_vat_id']      = isset( $in['operator_vat_id'] ) ? strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $in['operator_vat_id'] ) ) : '';
		$out['operator_tax_number']  = isset( $in['operator_tax_number'] ) ? sanitize_text_field( (string) $in['operator_tax_number'] ) : '';
		foreach ( array( 'credit_prefix', 'storno_prefix' ) as $k ) {
			$prefix    = isset( $in[ $k ] ) ? strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $in[ $k ] ) ) : '';
			$out[ $k ] = '' !== $prefix ? substr( $prefix, 0, 8 ) : $d[ $k ];
		}
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
	 * Reset the in-memory cache (tests, site switch on multisite).
	 */
	public static function flush_cache() {
		self::$cache = null;
	}
}
