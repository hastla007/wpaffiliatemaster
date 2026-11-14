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

	/**
	 * Fraud thresholds a new installation starts with (the defaults keep the rules off so existing
	 * shops are not changed by an update). Order value: 2,000 in the program currency.
	 *
	 * @param string $currency Program currency.
	 * @return array
	 */
	public static function recommended_fraud( $currency = 'EUR' ) {
		return array(
			'fraud_max_orders_day'    => 50,
			'fraud_max_order_minor'   => Money::to_minor( '2000', (string) $currency ),
			'fraud_min_click_seconds' => 5,
			'fraud_max_refund_rate'   => 30,
		);
	}

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
			'cookie_domain'           => '',         // '' = WordPress COOKIE_DOMAIN (current host).
			'visit_log_percent'       => 100,        // Share of link visits written to the visit log (0 = off).
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
			'hold_start'              => 'paid',     // paid|completed (WooCommerce: hold period starts when the order is completed).
			'renewal_limit_months'    => 0,          // Renewals earn commission for this many months after the first order (0 = no limit).
			'rule_precedence'         => 'scope',    // scope|target, see Calculator.
			// Program.
			'auto_approve'            => 0,
			'terms_page_id'           => 0,
			'terms_version'           => '1.0',
			'portal_page_id'          => 0,
			'registration_page_id'    => 0,
			'terminated_open'         => 'pay',      // pay|reject.
			'admin_email'             => '',
			// E-mails: sender ('' = WordPress default) and optional partner notices.
			'mail_from_name'          => '',
			'mail_from_email'         => '',
			'notify_payout_paid'      => 1,
			'notify_commissions'      => 0,
			'notify_admin_review'     => 0,          // Hourly digest to the operator about commissions needing review (R5-W58).
			'maintenance_mode'        => 0,
			'monitor_token'           => '',
			// Promotion.
			'landing_prefix'          => 'partner',
			'landing_page_id'         => 0,
			// Translated once translations can load (before "init" WordPress warns about early loading).
			'default_disclosure'      => function_exists( 'did_action' ) && did_action( 'init' ) ? __( 'Advertisement', 'affiliate-captain' ) : 'Advertisement',
			'promotion_guidelines'    => '',
			// Payouts.
			'min_payout_minor'        => 5000,
			'payout_requests'         => 0,
			'statement_retention_years' => 10,
			'retention_hold'          => 0,
			'payout_period'           => 'monthly', // Unused (no effect, no longer offered in the settings); kept for stored settings.
			'payout_method_manual'    => 1,         // Partners may choose "other payment method" (PayPal etc., paid manually).
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
			// Monitoring tokens of older versions were stored in plain text: hash on first read.
			// (wp_salt() is pluggable: not yet defined while plugins load – then on a later request.)
			if ( '' !== (string) self::$cache['monitor_token'] && 0 !== strpos( (string) self::$cache['monitor_token'], self::MONITOR_HASH ) && function_exists( 'wp_salt' ) ) {
				self::$cache['monitor_token'] = self::monitor_token_hash( (string) self::$cache['monitor_token'] );
				if ( is_array( $saved ) ) {
					$saved['monitor_token'] = self::$cache['monitor_token'];
					update_option( self::OPTION, $saved, true );
				}
			}
		}
		return self::$cache;
	}

	/** Prefix of a hashed monitoring token (older versions keyed it with the WordPress salt). */
	const MONITOR_HASH = 'h1:';

	/**
	 * Prefix of a token hashed with the plugin's own key. It still starts with MONITOR_HASH, so
	 * older versions (after a downgrade) treat it as hashed and never hash it a second time.
	 */
	const MONITOR_HASH_KEYED = 'h1:k';

	/**
	 * Own, permanent key for the monitoring token: a rotation of the WordPress salts must not
	 * make /health silently unusable.
	 *
	 * @return string
	 */
	private static function monitor_key() {
		$key = get_option( 'wpac_monitor_key' );
		if ( ! is_string( $key ) || strlen( $key ) < 32 ) {
			$key = bin2hex( random_bytes( 32 ) );
			add_option( 'wpac_monitor_key', $key, '', false );
			$stored = get_option( 'wpac_monitor_key' );
			$key    = is_string( $stored ) && strlen( $stored ) >= 32 ? $stored : $key;
		}
		return $key;
	}

	/**
	 * Stored form of a monitoring token: only a keyed hash, never the token itself.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	public static function monitor_token_hash( $token ) {
		return self::MONITOR_HASH_KEYED . hash_hmac( 'sha256', (string) $token, self::monitor_key() . 'wpac_monitor' );
	}

	/**
	 * Whether a token sent by a monitor matches the stored one. A token hashed by an older version
	 * (with the WordPress salt) is converted to the own key on its first successful use.
	 *
	 * @param string $sent Token from the request.
	 * @return bool
	 */
	public static function monitor_token_matches( $sent ) {
		$stored = (string) self::get( 'monitor_token' );
		if ( '' === $stored || '' === (string) $sent ) {
			return false;
		}
		if ( 0 !== strpos( $stored, self::MONITOR_HASH ) ) {
			return hash_equals( $stored, (string) $sent ); // Not hashed yet (see all()).
		}
		if ( 0 === strpos( $stored, self::MONITOR_HASH_KEYED ) ) {
			return hash_equals( $stored, self::monitor_token_hash( (string) $sent ) );
		}
		$salted = self::MONITOR_HASH . hash_hmac( 'sha256', (string) $sent, wp_salt( 'auth' ) . 'wpac_monitor' );
		if ( ! hash_equals( $stored, $salted ) ) {
			return false;
		}
		self::update( array( 'monitor_token' => self::monitor_token_hash( (string) $sent ) ) );
		return true;
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
	 * Upper limits of whole-number settings (days, years, counts). Beyond them date arithmetic
	 * overflows (e.g. a hold period of millions of days becomes "0000-00-00" = no hold at all).
	 */
	const INT_MAX = array(
		'audit_retention_days'       => 36500,
		'application_retention_days' => 36500,
		'visit_retention_days'       => 36500,
		'fraud_max_orders_day'       => 1000000,
		'fraud_min_click_seconds'    => 86400,
		'statement_retention_years'  => 100,
		'lifetime_days'              => 36500,
		'cookie_days'                => 3650,
		'rate_limit_per_minute'      => 1000000,
		'hold_days'                  => 3650,
	);

	/**
	 * Persist settings (already sanitized). Runtime state goes to its own options.
	 *
	 * @param array $values Values.
	 * @return bool Whether the settings are stored as given (read back: update_option() also
	 *              returns false for an unchanged value, and silently fails on a database error).
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
		if ( array_key_exists( 'vat_rate', $values ) ) {
			// The VAT of a credit note follows the rate valid at the time of the service: keep the old rate.
			\WPAffiliateCaptain\Payouts\TaxHistory::record_rate( (string) self::get( 'vat_rate' ), (string) $values['vat_rate'] );
		}
		update_option( self::OPTION, $merged, true ); // Read on every request → autoload.
		self::$cache = null;
		// A failed write leaves the cached (old) value in place, so reading back detects it.
		$stored = get_option( self::OPTION );
		if ( ! is_array( $stored ) ) {
			return false;
		}
		foreach ( $values as $key => $value ) {
			if ( ! array_key_exists( $key, $stored ) || (string) ( is_scalar( $stored[ $key ] ) ? $stored[ $key ] : wp_json_encode( $stored[ $key ] ) ) !== (string) ( is_scalar( $value ) ? $value : wp_json_encode( $value ) ) ) {
				Log::error( 'Settings could not be saved', array( 'key' => (string) $key ) );
				return false;
			}
		}
		return true;
	}

	/**
	 * Sanitize and validate raw input from the settings form.
	 *
	 * Invalid input is never replaced silently: the field keeps its stored value and an error
	 * message is returned in $errors (form field name => message), so the settings page can
	 * show it next to the field together with what was entered.
	 *
	 * @param array      $in      Raw input (unslashed).
	 * @param array|null $errors  Out: rejected fields (form field name => message).
	 * @param array|null $notices Out: accepted but adjusted fields (form field name => message).
	 * @return array
	 */
	public static function sanitize( array $in, &$errors = null, &$notices = null ) {
		$d       = self::defaults();
		$out     = array();
		$errors  = array();
		$notices = array();
		$old     = static function ( $key ) use ( $d ) {
			$value = self::get( $key );
			return null !== $value ? $value : $d[ $key ];
		};

		$out['referral_param'] = $d['referral_param'];
		if ( isset( $in['referral_param'] ) ) {
			$param = strtolower( trim( (string) $in['referral_param'] ) );
			if ( '' === $param ) {
				$errors['referral_param'] = __( 'Please enter a parameter name.', 'affiliate-captain' );
				$out['referral_param']    = $old( 'referral_param' );
			} elseif ( ! preg_match( '/^[a-z0-9_\-]+$/', $param ) ) {
				$errors['referral_param'] = __( 'Only lowercase letters, digits, "-" and "_" are allowed.', 'affiliate-captain' );
				$out['referral_param']    = $old( 'referral_param' );
			} else {
				$out['referral_param'] = $param;
			}
		}

		$out['touch_model']       = self::choice( $in, 'touch_model', array( 'last', 'first' ), $d );
		$out['cookie_mode']       = self::choice( $in, 'cookie_mode', array( 'off', 'consent', 'always' ), $d );
		$out['self_referral']     = self::choice( $in, 'self_referral', array( 'block', 'review' ), $d );
		$out['default_rate_type'] = self::choice( $in, 'default_rate_type', array( 'percent', 'fixed' ), $d );
		$out['terminated_open']   = self::choice( $in, 'terminated_open', array( 'pay', 'reject' ), $d );
		$out['payout_period']     = self::choice( $in, 'payout_period', array( 'monthly', 'quarterly', 'manual' ), $d );
		$out['hold_start']        = self::choice( $in, 'hold_start', array( 'paid', 'completed' ), $d );
		$out['rule_precedence']   = self::choice( $in, 'rule_precedence', array( 'scope', 'target' ), $d );

		$out['lifetime_rate'] = '';
		if ( isset( $in['lifetime_rate'] ) && '' !== trim( (string) $in['lifetime_rate'] ) ) {
			$lifetime_rate = self::parse_percent( $in['lifetime_rate'] );
			if ( null === $lifetime_rate || (float) $lifetime_rate < 0 || (float) $lifetime_rate > 100 ) {
				$errors['lifetime_rate'] = __( 'Please enter a percentage between 0 and 100 (e.g. 10 or 12,5), or leave the field empty.', 'affiliate-captain' );
				$out['lifetime_rate']    = (string) $old( 'lifetime_rate' );
			} else {
				$out['lifetime_rate'] = $lifetime_rate;
			}
		}

		foreach ( array( 'use_wc_session', 'checkout_field', 'coupon_priority', 'auto_approve', 'delete_on_uninstall', 'lifetime_enabled', 'payout_requests', 'report_admin', 'report_partners', 'fraud_check_domains', 'self_billing', 'retention_hold', 'renewal_commission', 'js_tracking', 'maintenance_mode', 'vies_check', 'notify_payout_paid', 'notify_commissions', 'notify_admin_review', 'payout_method_manual' ) as $flag ) {
			$out[ $flag ] = empty( $in[ $flag ] ) ? 0 : 1;
		}

		foreach ( array( 'audit_retention_days', 'application_retention_days', 'fraud_max_orders_day', 'fraud_min_click_seconds', 'fraud_max_refund_rate', 'statement_retention_years', 'lifetime_days', 'cookie_days', 'rate_limit_per_minute', 'visit_retention_days', 'hold_days', 'renewal_limit_months', 'terms_page_id', 'portal_page_id', 'registration_page_id' ) as $int ) {
			if ( ! isset( $in[ $int ] ) ) {
				$out[ $int ] = $d[ $int ];
				continue;
			}
			$raw = trim( (string) $in[ $int ] );
			$max = isset( self::INT_MAX[ $int ] ) ? self::INT_MAX[ $int ] : 2147483647;
			if ( '' === $raw ) {
				$out[ $int ] = 0;
			} elseif ( preg_match( '/^\d+$/', $raw ) && ( strlen( ltrim( $raw, '0' ) ) < 11 && (int) $raw <= $max ) ) {
				$out[ $int ] = (int) $raw;
			} elseif ( preg_match( '/^\d+$/', $raw ) ) {
				// Huge values would overflow dates (hold period "0000-00-00" = released at once).
				/* translators: %d: largest allowed number */
				$errors[ $int ] = sprintf( __( 'Please enter a whole number between 0 and %d.', 'affiliate-captain' ), $max );
				$out[ $int ]    = (int) $old( $int );
			} else {
				$errors[ $int ] = __( 'Please enter a whole number (0 or more).', 'affiliate-captain' );
				$out[ $int ]    = (int) $old( $int );
			}
		}
		if ( 100 < $out['fraud_max_refund_rate'] && ! isset( $errors['fraud_max_refund_rate'] ) ) {
			$errors['fraud_max_refund_rate'] = __( 'Please enter a percentage between 0 and 100.', 'affiliate-captain' );
			$out['fraud_max_refund_rate']    = (int) $old( 'fraud_max_refund_rate' );
		}

		// Not posted (another tab): the stored country, which also sets the retention minimum below.
		$out['operator_country'] = (string) $old( 'operator_country' );
		if ( isset( $in['operator_country'] ) ) {
			$country = strtoupper( trim( (string) $in['operator_country'] ) );
			if ( isset( self::countries()[ $country ] ) ) {
				$out['operator_country'] = $country;
			} else {
				$errors['operator_country'] = __( 'Please choose your country from the list.', 'affiliate-captain' );
				$out['operator_country']    = (string) $old( 'operator_country' );
			}
		}
		// Credit notes: at least the legal minimum of the operator's country (DE 8, AT 7, CH 10, else 10 years); 0 = forever.
		if ( $out['statement_retention_years'] > 0 ) {
			$minimum = \WPAffiliateCaptain\Privacy\Retention::minimum_years( $out['operator_country'] );
			if ( $out['statement_retention_years'] < $minimum ) {
				if ( isset( $in['statement_retention_years'] ) ) {
					/* translators: %d: years */
					$notices['statement_retention_years'] = sprintf( __( 'Raised to the legal minimum of %d years for your country.', 'affiliate-captain' ), $minimum );
				}
				$out['statement_retention_years'] = $minimum;
			}
		}

		// Fields of other tabs are not posted: fall back to the stored value (needed for amount conversions).
		$out['currency'] = self::get( 'currency' ) ? (string) self::get( 'currency' ) : $d['currency'];
		if ( isset( $in['currency'] ) ) {
			$currency = strtoupper( trim( (string) $in['currency'] ) );
			if ( preg_match( '/^[A-Z]{3}$/', $currency ) ) {
				$out['currency'] = $currency;
			} else {
				$errors['currency'] = __( 'Please enter a three-letter currency code (ISO 4217, e.g. EUR).', 'affiliate-captain' );
			}
		}

		$out['exchange_rates'] = '';
		if ( isset( $in['exchange_rates'] ) ) {
			$invalid = array();
			foreach ( preg_split( '/[\r\n;]+/', (string) $in['exchange_rates'] ) as $line ) {
				if ( '' !== trim( $line ) && ! Fx::parse( $line ) ) {
					$invalid[] = trim( $line );
				}
			}
			if ( $invalid ) {
				/* translators: %s: lines */
				$errors['exchange_rates'] = sprintf( __( 'Not understood: %s. Use one line per currency, e.g. USD=0.92.', 'affiliate-captain' ), implode( ', ', array_slice( $invalid, 0, 5 ) ) );
				$out['exchange_rates']    = (string) $old( 'exchange_rates' );
			} else {
				$lines = array();
				foreach ( Fx::parse( (string) $in['exchange_rates'] ) as $cur => $rate ) {
					$lines[] = $cur . '=' . $rate;
				}
				$out['exchange_rates'] = implode( "\n", $lines );
			}
		}

		// A new program currency: amounts stored in minor units of the old currency are converted
		// (with an exchange rate between the two currencies if one is known, otherwise the shown
		// value is kept, e.g. 50.00 EUR → 50 JPY – reported so it can be checked).
		$old_currency = self::get( 'currency' ) ? strtoupper( (string) self::get( 'currency' ) ) : $d['currency'];
		$fx_change    = $old_currency !== $out['currency'];
		$old_rates    = Fx::parse( (string) $old( 'exchange_rates' ) );
		$new_rates    = Fx::parse( $out['exchange_rates'] );
		if ( $fx_change ) {
			$rebased = self::rebase_rates( $old_rates, $old_currency, $out['currency'] );
			if ( null !== $rebased && Fx::parse( $out['exchange_rates'] ) === $old_rates && ! isset( $errors['exchange_rates'] ) ) {
				$lines = array();
				foreach ( $rebased as $cur => $rate ) {
					$lines[] = $cur . '=' . $rate;
				}
				$out['exchange_rates']     = implode( "\n", $lines );
				$new_rates                 = $rebased;
				/* translators: %s: currency code */
				$notices['exchange_rates'] = sprintf( __( 'Converted to the new program currency %s.', 'affiliate-captain' ), $out['currency'] );
			}
			$converted = array();
			$scaled    = array();
			foreach ( array( 'min_payout_minor' => 'min_payout', 'fraud_max_order_minor' => 'fraud_max_order' ) as $key => $field ) {
				if ( isset( $in[ $field ] ) ) {
					continue; // Entered together with the currency: parsed in the new currency.
				}
				list( $out[ $key ], $how ) = self::convert_minor( (int) $old( $key ), $old_currency, $out['currency'], $old_rates, $new_rates );
				if ( 'rate' === $how ) {
					$converted[] = $key;
				} elseif ( 'scaled' === $how && (int) $old( $key ) ) {
					$scaled[] = $key;
				}
			}
			$notices['currency'] = $scaled
				/* translators: 1: old currency, 2: new currency */
				? sprintf( __( 'No exchange rate between %1$s and %2$s is known, so amounts keep their value (e.g. 50.00 %1$s becomes 50 %2$s). Please check the minimum payout, the review limit for large orders, fixed commission rules, bonus tiers and the exchange rates.', 'affiliate-captain' ), $old_currency, $out['currency'] )
				/* translators: 1: old currency, 2: new currency */
				: sprintf( __( 'Amounts were converted from %1$s to %2$s. Please check fixed commission rules, bonus tiers and the exchange rates; bookings already made keep their currency.', 'affiliate-captain' ), $old_currency, $out['currency'] );
		}

		$out['default_rate'] = $d['default_rate'];
		if ( isset( $in['default_rate'] ) ) {
			if ( 'fixed' === $out['default_rate_type'] ) {
				// Fixed default rate is entered as a decimal amount, stored as minor units. The form
				// showed it in the stored currency: after a currency change it is converted.
				$shown_in = $fx_change && 'fixed' === (string) $old( 'default_rate_type' ) ? $old_currency : $out['currency'];
				$rate     = self::parse_amount( $in['default_rate'], $shown_in );
				$ok       = null !== $rate && (float) $rate >= 0;
				if ( $ok ) {
					$minor               = Money::to_minor( $rate, $shown_in );
					$out['default_rate'] = (string) ( $shown_in !== $out['currency'] ? self::convert_minor( $minor, $shown_in, $out['currency'], $old_rates, $new_rates )[0] : $minor );
				} else {
					$errors['default_rate'] = __( 'Please enter an amount (e.g. 25 or 25,50).', 'affiliate-captain' );
				}
			} else {
				$rate = self::parse_percent( $in['default_rate'] );
				$ok   = null !== $rate && (float) $rate >= 0 && (float) $rate <= 100;
				if ( $ok ) {
					$out['default_rate'] = $rate;
				} else {
					$errors['default_rate'] = __( 'Please enter a percentage between 0 and 100 (e.g. 10 or 12,5).', 'affiliate-captain' );
				}
			}
			if ( ! $ok ) {
				// Rate and type belong together: keep both as stored – and say so for the type too
				// (the form shows both entered values again with the messages).
				if ( $out['default_rate_type'] !== (string) $old( 'default_rate_type' ) ) {
					$errors['default_rate_type'] = __( 'Not changed, because the default rate entered with it is not valid.', 'affiliate-captain' );
				}
				$out['default_rate']      = (string) $old( 'default_rate' );
				$out['default_rate_type'] = (string) $old( 'default_rate_type' );
			}
		}

		$out['visit_log_percent'] = $d['visit_log_percent'];
		if ( isset( $in['visit_log_percent'] ) ) {
			$raw = trim( (string) $in['visit_log_percent'] );
			if ( preg_match( '/^\d+$/', $raw ) && (int) $raw <= 100 ) {
				$out['visit_log_percent'] = (int) $raw;
			} else {
				$errors['visit_log_percent'] = __( 'Please enter a percentage between 0 and 100.', 'affiliate-captain' );
				$out['visit_log_percent']    = (int) $old( 'visit_log_percent' );
			}
		}

		// Cookie domain for landing pages and shop on different subdomains: must be this site's host
		// or a parent domain of it (a browser rejects anything else).
		$out['cookie_domain'] = '';
		if ( isset( $in['cookie_domain'] ) && '' !== trim( (string) $in['cookie_domain'] ) ) {
			$domain = ltrim( strtolower( trim( (string) $in['cookie_domain'] ) ), '.' );
			$host   = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
			if ( preg_match( '/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $domain ) && ( $host === $domain || substr( $host, -strlen( $domain ) - 1 ) === '.' . $domain ) ) {
				$out['cookie_domain'] = '.' . $domain;
			} else {
				/* translators: %s: host name */
				$errors['cookie_domain'] = sprintf( __( 'Please enter this site\'s domain or a parent domain of %s (e.g. .example.com), or leave the field empty.', 'affiliate-captain' ), $host );
				$out['cookie_domain']    = (string) $old( 'cookie_domain' );
			}
		}

		$out['trusted_proxies'] = '';
		if ( isset( $in['trusted_proxies'] ) ) {
			$proxies = array();
			$invalid = array();
			foreach ( preg_split( '/[\s,]+/', (string) $in['trusted_proxies'], -1, PREG_SPLIT_NO_EMPTY ) as $entry ) {
				$parts = explode( '/', $entry, 2 );
				$max   = filter_var( $parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ? 128 : 32;
				if ( filter_var( $parts[0], FILTER_VALIDATE_IP ) && ( ! isset( $parts[1] ) || ( ctype_digit( $parts[1] ) && (int) $parts[1] <= $max ) ) ) {
					$proxies[] = $entry;
				} else {
					$invalid[] = $entry;
				}
			}
			if ( $invalid ) {
				/* translators: %s: entries */
				$errors['trusted_proxies'] = sprintf( __( 'Not an IP address or range: %s', 'affiliate-captain' ), implode( ', ', array_slice( $invalid, 0, 5 ) ) );
				$out['trusted_proxies']    = (string) $old( 'trusted_proxies' );
			} else {
				$out['trusted_proxies'] = implode( "\n", $proxies );
			}
		}

		$out['fraud_max_order_minor'] = isset( $out['fraud_max_order_minor'] ) ? $out['fraud_max_order_minor'] : 0; // Converted above after a currency change.
		if ( isset( $in['fraud_max_order'] ) && '' !== trim( (string) $in['fraud_max_order'] ) ) {
			$max_order = self::parse_amount( $in['fraud_max_order'], $out['currency'] );
			if ( null === $max_order || (float) $max_order < 0 ) {
				$errors['fraud_max_order']    = __( 'Please enter an amount (e.g. 1.000 or 1000,00; 0 = rule off).', 'affiliate-captain' );
				$out['fraud_max_order_minor'] = (int) $old( 'fraud_max_order_minor' );
			} else {
				$out['fraud_max_order_minor'] = Money::to_minor( $max_order, $out['currency'] );
			}
		}
		$out['fraud_blocked_domains'] = isset( $in['fraud_blocked_domains'] ) ? implode( "\n", array_filter( array_map( 'sanitize_text_field', preg_split( '/[\s,]+/', strtolower( (string) $in['fraud_blocked_domains'] ) ) ) ) ) : '';

		$out['min_payout_minor'] = isset( $out['min_payout_minor'] ) ? $out['min_payout_minor'] : $d['min_payout_minor'];
		if ( isset( $in['min_payout'] ) ) {
			$min = '' === trim( (string) $in['min_payout'] ) ? null : self::parse_amount( $in['min_payout'], $out['currency'] );
			if ( null === $min || (float) $min < 0 ) {
				$errors['min_payout']    = __( 'Please enter an amount (e.g. 50 or 50,00; 0 = no minimum).', 'affiliate-captain' );
				$out['min_payout_minor'] = (int) $old( 'min_payout_minor' );
			} else {
				$out['min_payout_minor'] = Money::to_minor( $min, $out['currency'] );
			}
		}

		$out['landing_prefix'] = $d['landing_prefix'];
		if ( isset( $in['landing_prefix'] ) ) {
			$landing = sanitize_title( (string) $in['landing_prefix'] );
			if ( '' === $landing ) {
				$errors['landing_prefix'] = __( 'Please enter a path (letters, digits and "-").', 'affiliate-captain' );
				$out['landing_prefix']    = (string) $old( 'landing_prefix' );
			} else {
				$out['landing_prefix'] = $landing;
			}
		}
		$out['landing_page_id']    = isset( $in['landing_page_id'] ) ? absint( $in['landing_page_id'] ) : 0;
		$out['default_disclosure'] = $d['default_disclosure'];
		if ( isset( $in['default_disclosure'] ) ) {
			if ( '' === trim( (string) $in['default_disclosure'] ) ) {
				$errors['default_disclosure'] = __( 'Please enter a disclosure note (e.g. "Advertisement"); partners need it for their posts.', 'affiliate-captain' );
				$out['default_disclosure']    = (string) $old( 'default_disclosure' );
			} else {
				$out['default_disclosure'] = sanitize_text_field( (string) $in['default_disclosure'] );
			}
		}
		$out['promotion_guidelines'] = isset( $in['promotion_guidelines'] ) ? wp_kses_post( (string) $in['promotion_guidelines'] ) : '';

		$out['terms_version'] = $d['terms_version'];
		if ( isset( $in['terms_version'] ) ) {
			$version = trim( sanitize_text_field( (string) $in['terms_version'] ) );
			if ( '' === $version ) {
				$errors['terms_version'] = __( 'Please enter a version (e.g. 1.0).', 'affiliate-captain' );
				$out['terms_version']    = (string) $old( 'terms_version' );
			} else {
				$out['terms_version'] = Db::cut( $version, 20 );
			}
		}
		$out['admin_email'] = '';
		if ( isset( $in['admin_email'] ) && '' !== trim( (string) $in['admin_email'] ) ) {
			$email = sanitize_email( (string) $in['admin_email'] );
			if ( ! is_email( $email ) ) {
				$errors['admin_email'] = __( 'Please enter a valid e-mail address or leave the field empty.', 'affiliate-captain' );
				$out['admin_email']    = (string) $old( 'admin_email' );
			} else {
				$out['admin_email'] = $email;
			}
		}
		$out['mail_from_name']  = isset( $in['mail_from_name'] ) ? Db::cut( trim( str_replace( array( '<', '>', '"' ), '', sanitize_text_field( (string) $in['mail_from_name'] ) ) ), 100 ) : '';
		$out['mail_from_email'] = '';
		if ( isset( $in['mail_from_email'] ) && '' !== trim( (string) $in['mail_from_email'] ) ) {
			$email = sanitize_email( (string) $in['mail_from_email'] );
			if ( ! is_email( $email ) ) {
				$errors['mail_from_email'] = __( 'Please enter a valid e-mail address or leave the field empty.', 'affiliate-captain' );
				$out['mail_from_email']    = (string) $old( 'mail_from_email' );
			} else {
				$out['mail_from_email'] = $email;
			}
		}
		// Only a hash of the monitor token is stored; the form field stays empty and an empty field
		// keeps the current token ("Remove token" clears it).
		$out['monitor_token'] = (string) $old( 'monitor_token' );
		if ( ! empty( $in['monitor_token_clear'] ) ) {
			$out['monitor_token'] = '';
		} elseif ( isset( $in['monitor_token'] ) && '' !== trim( (string) $in['monitor_token'] ) ) {
			$token = trim( (string) $in['monitor_token'] );
			if ( ! preg_match( '/^[A-Za-z0-9]{20,64}$/', $token ) ) {
				$errors['monitor_token'] = __( 'The token needs 20 to 64 letters and digits (no spaces or special characters). The previous token stays valid.', 'affiliate-captain' );
			} else {
				$out['monitor_token'] = self::monitor_token_hash( $token );
			}
		}
		$out['debtor_name'] = isset( $in['debtor_name'] ) ? sanitize_text_field( (string) $in['debtor_name'] ) : '';
		$out['vat_rate']    = $d['vat_rate'];
		if ( isset( $in['vat_rate'] ) ) {
			$vat = self::parse_percent( $in['vat_rate'] );
			if ( null === $vat || (float) $vat < 0 || (float) $vat >= 100 ) {
				$errors['vat_rate'] = __( 'Please enter a percentage between 0 and 99 (e.g. 19 or 7,7).', 'affiliate-captain' );
				$out['vat_rate']    = (string) $old( 'vat_rate' );
			} else {
				$out['vat_rate'] = $vat;
			}
		}
		$out['operator_address']    = isset( $in['operator_address'] ) ? sanitize_textarea_field( (string) $in['operator_address'] ) : '';
		$out['operator_vat_id']     = isset( $in['operator_vat_id'] ) ? strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $in['operator_vat_id'] ) ) : '';
		$out['operator_tax_number'] = isset( $in['operator_tax_number'] ) ? sanitize_text_field( (string) $in['operator_tax_number'] ) : '';
		foreach ( array( 'credit_prefix', 'storno_prefix' ) as $k ) {
			$out[ $k ] = $d[ $k ];
			if ( ! isset( $in[ $k ] ) ) {
				continue;
			}
			$prefix = strtoupper( trim( (string) $in[ $k ] ) );
			if ( ! preg_match( '/^[A-Z0-9]{1,8}$/', $prefix ) ) {
				$errors[ $k ] = __( 'Please enter 1 to 8 letters or digits (e.g. GS).', 'affiliate-captain' );
				$out[ $k ]    = (string) $old( $k );
			} else {
				$out[ $k ] = $prefix;
			}
		}
		$out['remittance_prefix'] = isset( $in['remittance_prefix'] ) ? sanitize_text_field( (string) $in['remittance_prefix'] ) : $d['remittance_prefix'];
		$out['debtor_bic']        = '';
		if ( isset( $in['debtor_bic'] ) && '' !== trim( (string) $in['debtor_bic'] ) ) {
			$bic = strtoupper( preg_replace( '/\s+/', '', (string) $in['debtor_bic'] ) );
			if ( ! preg_match( '/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $bic ) ) {
				$errors['debtor_bic'] = __( 'This BIC is not valid (8 or 11 characters, e.g. COBADEFFXXX). Leave the field empty if you do not know it.', 'affiliate-captain' );
				$out['debtor_bic']    = (string) $old( 'debtor_bic' );
			} else {
				$out['debtor_bic'] = $bic;
			}
		}
		$iban               = isset( $in['debtor_iban'] ) ? Iban::normalize( (string) $in['debtor_iban'] ) : '';
		$out['debtor_iban'] = $iban;
		if ( '' !== $iban && ! Iban::is_valid( $iban ) ) {
			$errors['debtor_iban'] = '' !== (string) self::get( 'debtor_iban' )
				? __( 'This IBAN is not valid (check digits). The previous IBAN is still used.', 'affiliate-captain' )
				: __( 'This IBAN is not valid (check digits).', 'affiliate-captain' );
			$out['debtor_iban']    = (string) self::get( 'debtor_iban' );
		}

		return $out;
	}

	/**
	 * Convert an amount in minor units to another currency (program currency change): with the
	 * rate of the old currency in the new rates (1 OLD = x NEW), or of the new currency in the old
	 * rates (1 NEW = x OLD); without a rate the shown value is kept (50.00 EUR → 50 JPY).
	 *
	 * @param int    $minor     Amount in minor units of $from.
	 * @param string $from      Old currency.
	 * @param string $to        New currency.
	 * @param array  $old_rates Rates relative to $from (Fx::parse()).
	 * @param array  $new_rates Rates relative to $to.
	 * @return array [ minor units of $to, 'same'|'rate'|'scaled' ]
	 */
	public static function convert_minor( $minor, $from, $to, array $old_rates, array $new_rates ) {
		$from = strtoupper( (string) $from );
		$to   = strtoupper( (string) $to );
		if ( $from === $to ) {
			return array( (int) $minor, 'same' );
		}
		if ( isset( $new_rates[ $from ] ) ) {
			return array( (int) Fx::to_program( (int) $minor, $from, $to, $new_rates ), 'rate' );
		}
		if ( isset( $old_rates[ $to ] ) ) {
			return array( (int) Fx::from_program( (int) $minor, $to, $from, $old_rates ), 'rate' );
		}
		return array( Money::to_minor( Money::to_decimal( (int) $minor, $from ), $to ), 'scaled' );
	}

	/**
	 * Exchange rates relative to a new program currency, from rates relative to the old one
	 * (possible when they contain the new currency): 1 X = r_X / r_NEW NEW, 1 OLD = 1 / r_NEW NEW.
	 *
	 * @param array  $rates Rates relative to $old (currency => decimal string).
	 * @param string $old   Old program currency.
	 * @param string $new   New program currency.
	 * @return array|null Null when the new currency has no rate.
	 */
	public static function rebase_rates( array $rates, $old, $new ) {
		$old = strtoupper( (string) $old );
		$new = strtoupper( (string) $new );
		if ( empty( $rates[ $new ] ) || (float) $rates[ $new ] <= 0 ) {
			return null;
		}
		$base = (float) $rates[ $new ];
		$fmt  = static function ( $value ) {
			$text = rtrim( rtrim( sprintf( '%.6F', $value ), '0' ), '.' );
			return '0' === $text || '' === $text ? '0.000001' : $text;
		};
		$out = array( $old => $fmt( 1 / $base ) );
		foreach ( $rates as $cur => $rate ) {
			if ( $cur !== $new && $cur !== $old ) {
				$out[ $cur ] = $fmt( (float) $rate / $base );
			}
		}
		return $out;
	}

	/**
	 * Parse a percentage as typed by people: "15", "15 %", "15,5", "15.5%".
	 *
	 * @param mixed $raw Input.
	 * @return string|null Normalized decimal or null.
	 */
	public static function parse_percent( $raw ) {
		$value = trim( str_replace( array( '%', "\u{00A0}", "\u{202F}" ), array( '', ' ', ' ' ), (string) $raw ) );
		return Money::normalize_decimal( $value );
	}

	/**
	 * Parse an amount as typed by people: "25", "25 €", "€ 25,50", "1.000", "1.000,00 EUR",
	 * "1,000.00". See Money::parse_amount(): grouped thousands are recognized, but for currencies
	 * with three decimals (KWD …) "5.000" means 5 – the way the value is shown in the form.
	 *
	 * @param mixed  $raw      Input.
	 * @param string $currency Currency of the amount ('' = no three-decimal rule).
	 * @return string|null Normalized decimal or null.
	 */
	public static function parse_amount( $raw, $currency = '' ) {
		return Money::parse_amount( (string) $raw, (string) $currency );
	}

	/**
	 * Countries the operator can choose (ISO 3166-1 alpha-2 => name): the EU, the EEA,
	 * Switzerland, the United Kingdom and other common shop countries. Names come from
	 * WooCommerce, else from PHP intl, else from the plugin's own (translated) list; sorted by
	 * the reader's language ("Österreich" before "Polen", not after "Zypern").
	 *
	 * @return array
	 */
	public static function countries() {
		$own   = self::country_names();
		$names = array();
		if ( function_exists( 'WC' ) && WC() && isset( WC()->countries ) && is_object( WC()->countries ) ) {
			$names = (array) WC()->countries->get_countries();
		}
		$locale = function_exists( 'get_user_locale' ) ? get_user_locale() : 'en_US';
		$out    = array();
		foreach ( $own as $code => $fallback ) {
			$name = isset( $names[ $code ] ) ? html_entity_decode( (string) $names[ $code ], ENT_QUOTES, 'UTF-8' ) : '';
			if ( '' === $name && class_exists( '\Locale' ) ) {
				$name = (string) \Locale::getDisplayRegion( 'und-' . $code, $locale );
			}
			$out[ $code ] = '' !== $name && $name !== $code ? $name : $fallback;
		}
		return self::sort_names( $out, $locale );
	}

	/**
	 * Sort names for display in a language: with intl's Collator, else by the name without
	 * accents (byte order would put every "Ö…" after "Z…").
	 *
	 * @param array  $names  Key => name.
	 * @param string $locale Locale.
	 * @return array
	 */
	public static function sort_names( array $names, $locale = 'en_US' ) {
		if ( class_exists( '\Collator' ) ) {
			$collator = new \Collator( (string) $locale );
			if ( $collator->asort( $names ) ) {
				return $names;
			}
		}
		$keys = array();
		foreach ( $names as $key => $name ) {
			$plain        = function_exists( 'remove_accents' ) ? remove_accents( (string) $name ) : strtr(
				(string) $name,
				array(
					'Ä' => 'A',
					'Ö' => 'O',
					'Ü' => 'U',
					'ä' => 'a',
					'ö' => 'o',
					'ü' => 'u',
					'ß' => 'ss',
					'É' => 'E',
					'é' => 'e',
					'È' => 'E',
					'è' => 'e',
					'Í' => 'I',
					'í' => 'i',
					'Č' => 'C',
					'č' => 'c',
					'Š' => 'S',
					'š' => 's',
					'Ž' => 'Z',
					'ž' => 'z',
					'Å' => 'A',
					'å' => 'a',
					'Ø' => 'O',
					'ø' => 'o',
					'Ñ' => 'N',
					'ñ' => 'n',
					'Ç' => 'C',
					'ç' => 'c',
				)
			);
			$keys[ $key ] = strtolower( $plain );
		}
		uksort(
			$names,
			static function ( $a, $b ) use ( $keys ) {
				return strnatcmp( $keys[ $a ], $keys[ $b ] );
			}
		);
		return $names;
	}

	/**
	 * Own country names (used without WooCommerce and PHP intl).
	 *
	 * @return array
	 */
	private static function country_names() {
		return array(
			'AT' => __( 'Austria', 'affiliate-captain' ),
			'BE' => __( 'Belgium', 'affiliate-captain' ),
			'BG' => __( 'Bulgaria', 'affiliate-captain' ),
			'HR' => __( 'Croatia', 'affiliate-captain' ),
			'CY' => __( 'Cyprus', 'affiliate-captain' ),
			'CZ' => __( 'Czechia', 'affiliate-captain' ),
			'DK' => __( 'Denmark', 'affiliate-captain' ),
			'EE' => __( 'Estonia', 'affiliate-captain' ),
			'FI' => __( 'Finland', 'affiliate-captain' ),
			'FR' => __( 'France', 'affiliate-captain' ),
			'DE' => __( 'Germany', 'affiliate-captain' ),
			'GR' => __( 'Greece', 'affiliate-captain' ),
			'HU' => __( 'Hungary', 'affiliate-captain' ),
			'IE' => __( 'Ireland', 'affiliate-captain' ),
			'IT' => __( 'Italy', 'affiliate-captain' ),
			'LV' => __( 'Latvia', 'affiliate-captain' ),
			'LT' => __( 'Lithuania', 'affiliate-captain' ),
			'LU' => __( 'Luxembourg', 'affiliate-captain' ),
			'MT' => __( 'Malta', 'affiliate-captain' ),
			'NL' => __( 'Netherlands', 'affiliate-captain' ),
			'PL' => __( 'Poland', 'affiliate-captain' ),
			'PT' => __( 'Portugal', 'affiliate-captain' ),
			'RO' => __( 'Romania', 'affiliate-captain' ),
			'SK' => __( 'Slovakia', 'affiliate-captain' ),
			'SI' => __( 'Slovenia', 'affiliate-captain' ),
			'ES' => __( 'Spain', 'affiliate-captain' ),
			'SE' => __( 'Sweden', 'affiliate-captain' ),
			'CH' => __( 'Switzerland', 'affiliate-captain' ),
			'LI' => __( 'Liechtenstein', 'affiliate-captain' ),
			'NO' => __( 'Norway', 'affiliate-captain' ),
			'IS' => __( 'Iceland', 'affiliate-captain' ),
			'GB' => __( 'United Kingdom', 'affiliate-captain' ),
			'US' => __( 'United States', 'affiliate-captain' ),
			'CA' => __( 'Canada', 'affiliate-captain' ),
			'AU' => __( 'Australia', 'affiliate-captain' ),
			'NZ' => __( 'New Zealand', 'affiliate-captain' ),
		);
	}

	/**
	 * Settings of a new installation: the defaults with the shop's currency and country from
	 * WooCommerce, the disclosure note in the site language and the recommended fraud thresholds.
	 *
	 * @return array
	 */
	public static function initial() {
		$values = self::defaults();
		if ( function_exists( 'get_woocommerce_currency' ) ) {
			$currency = strtoupper( (string) get_woocommerce_currency() );
			if ( preg_match( '/^[A-Z]{3}$/', $currency ) ) {
				$values['currency'] = $currency;
			}
		}
		if ( function_exists( 'WC' ) && WC() && isset( WC()->countries ) && is_object( WC()->countries ) && method_exists( WC()->countries, 'get_base_country' ) ) {
			$country = strtoupper( (string) WC()->countries->get_base_country() );
			if ( isset( self::country_names()[ $country ] ) ) {
				$values['operator_country'] = $country;
			}
		}
		$values['default_disclosure'] = __( 'Advertisement', 'affiliate-captain' );
		return array_merge( $values, self::recommended_fraud( (string) $values['currency'] ) );
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
