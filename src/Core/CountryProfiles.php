<?php
/**
 * Country profiles (SET-AC-03, owner decision S8): a set of proposed settings per country – VAT rate,
 * currency, operator country, retention periods, cookie and hold periods, e-invoice mail,
 * self-billing, number prefixes, disclosure note, small business limits and document notes.
 *
 * A profile is only ever a proposal: applying it first shows every change (old → new), goes through
 * the checks of the settings page (SettingsTransfer::check()) and is logged with the profile name.
 * Everything stays changeable afterwards. The values are NOT legally or tax reviewed ("reviewed"
 * false) until the owner's adviser released them; the settings page shows this next to the profile.
 *
 * Profiles at the start: DE, AT, CH, EU (general) and OTHER (outside the EU) with neutral values.
 * Add-ons add or change profiles with the filter wpac_country_profiles (docs/CONTRACT.md).
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Core;

defined( 'ABSPATH' ) || exit;

final class CountryProfiles {

	/** Option with the profile applied last ({ code, name, at, reviewed }). */
	const OPTION = 'wpac_country_profile';

	/** Member states of the European Union (ISO 3166-1 alpha-2). */
	const EU = array( 'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE' );

	/** Setting keys a profile may set (everything else in a profile's values is ignored). */
	const KEYS = array(
		'operator_country',
		'currency',
		'vat_rate',
		'statement_retention_minimum',
		'statement_retention_years',
		'cookie_mode',
		'cookie_days',
		'hold_days',
		'einvoice_mail',
		'self_billing',
		'credit_prefix',
		'storno_prefix',
		'correction_prefix',
		'default_disclosure',
		'small_business_limit_previous',
		'small_business_limit_current',
		'note_self_billing',
		'note_reverse_charge',
		'note_small_business',
		'note_private_person',
	);

	/**
	 * All profiles, code => { label, reviewed, countries, retention_minimum, einvoice_auto, values }.
	 * Labels in the reader's language; texts in the values in the current language (see values()).
	 *
	 * @return array
	 */
	public static function all() {
		$own_notes     = array(
			// Empty = the built-in text of the operator country in the document language (outside the EU
			// the neutral texts, P3-11); own texts per language are set on the settings page.
			'note_self_billing'   => '',
			'note_reverse_charge' => '',
			'note_small_business' => '',
			'note_private_person' => '',
		);
		$common        = array(
			'cookie_mode'  => 'consent',
			'cookie_days'  => 30,
			'hold_days'    => 30,
			'einvoice_mail' => 'auto',
		);
		$profiles      = array(
			'DE'    => array(
				'label'             => __( 'Germany', 'affiliate-captain' ),
				'countries'         => array( 'DE' ),
				'retention_minimum' => \WPAffiliateCaptain\Privacy\Retention::MINIMUM['DE'],
				'einvoice_auto'     => true, // Domestic B2B e-invoices from 2027/2028 (XRechnung / EN 16931).
				'values'            => array_merge(
					$common,
					array(
						'operator_country'              => 'DE',
						'currency'                      => 'EUR',
						'vat_rate'                      => '19',
						'statement_retention_minimum'   => \WPAffiliateCaptain\Privacy\Retention::MINIMUM['DE'],
						'statement_retention_years'     => \WPAffiliateCaptain\Privacy\Retention::MINIMUM['DE'],
						'self_billing'                  => 1,
						'credit_prefix'                 => 'GS',
						'storno_prefix'                 => 'ST',
						'correction_prefix'             => 'KG',
						'default_disclosure'            => __( 'Advertisement', 'affiliate-captain' ),
						'small_business_limit_previous' => '25000',
						'small_business_limit_current'  => '100000',
					),
					$own_notes
				),
			),
			'AT'    => array(
				'label'             => __( 'Austria', 'affiliate-captain' ),
				'countries'         => array( 'AT' ),
				'retention_minimum' => \WPAffiliateCaptain\Privacy\Retention::MINIMUM['AT'],
				'einvoice_auto'     => false,
				'values'            => array_merge(
					$common,
					array(
						'operator_country'              => 'AT',
						'currency'                      => 'EUR',
						'vat_rate'                      => '20',
						'statement_retention_minimum'   => \WPAffiliateCaptain\Privacy\Retention::MINIMUM['AT'],
						'statement_retention_years'     => \WPAffiliateCaptain\Privacy\Retention::MINIMUM['AT'],
						'self_billing'                  => 1,
						'credit_prefix'                 => 'GS',
						'storno_prefix'                 => 'ST',
						'correction_prefix'             => 'KG',
						'default_disclosure'            => __( 'Paid advertisement', 'affiliate-captain' ),
						'small_business_limit_previous' => '55000',
						'small_business_limit_current'  => '55000',
					),
					$own_notes
				),
			),
			'CH'    => array(
				'label'             => __( 'Switzerland', 'affiliate-captain' ),
				'countries'         => array( 'CH', 'LI' ),
				'retention_minimum' => \WPAffiliateCaptain\Privacy\Retention::MINIMUM['CH'],
				'einvoice_auto'     => false,
				'values'            => array_merge(
					$common,
					array(
						'operator_country'              => 'CH',
						'currency'                      => 'CHF',
						'vat_rate'                      => '8.1',
						'statement_retention_minimum'   => \WPAffiliateCaptain\Privacy\Retention::MINIMUM['CH'],
						'statement_retention_years'     => \WPAffiliateCaptain\Privacy\Retention::MINIMUM['CH'],
						'self_billing'                  => 1,
						'credit_prefix'                 => 'GS',
						'storno_prefix'                 => 'ST',
						'correction_prefix'             => 'KG',
						'default_disclosure'            => __( 'Advertisement', 'affiliate-captain' ),
						'small_business_limit_previous' => '100000',
						'small_business_limit_current'  => '100000',
					),
					$own_notes
				),
			),
			// Neutral: no country, currency or VAT rate (to be entered), no small business check.
			'EU'    => array(
				'label'             => __( 'EU (general)', 'affiliate-captain' ),
				'countries'         => self::EU,
				'retention_minimum' => 10,
				'einvoice_auto'     => false,
				'values'            => array_merge(
					$common,
					array(
						'vat_rate'                      => '',
						'statement_retention_minimum'   => 10,
						'statement_retention_years'     => 10,
						'self_billing'                  => 1,
						'credit_prefix'                 => 'SB',
						'storno_prefix'                 => 'CX',
						'correction_prefix'             => 'CC',
						'default_disclosure'            => __( 'Advertisement', 'affiliate-captain' ),
						'small_business_limit_previous' => '',
						'small_business_limit_current'  => '',
					),
					$own_notes
				),
			),
			'OTHER' => array(
				'label'             => __( 'Outside the EU', 'affiliate-captain' ),
				'countries'         => array(),
				'retention_minimum' => 10,
				'einvoice_auto'     => false,
				'values'            => array_merge(
					$common,
					array(
						'vat_rate'                      => '',
						'statement_retention_minimum'   => 10,
						'statement_retention_years'     => 10,
						'self_billing'                  => 0,
						'credit_prefix'                 => 'SB',
						'storno_prefix'                 => 'CX',
						'correction_prefix'             => 'CC',
						'default_disclosure'            => __( 'Advertisement', 'affiliate-captain' ),
						'small_business_limit_previous' => '',
						'small_business_limit_current'  => '',
					),
					$own_notes
				),
			),
		);
		foreach ( $profiles as $code => $profile ) {
			$profiles[ $code ]['reviewed'] = false; // S8: not legally or tax reviewed until released.
		}
		/**
		 * Country profiles (SET-AC-03): add a profile (code => definition) or change one.
		 * Definition: label, reviewed (bool, true only after a legal/tax review), countries (ISO codes
		 * the profile is proposed for), retention_minimum (years), einvoice_auto (bool: "automatic"
		 * attaches the e-invoice to credit notes with domestic VAT), values (setting key => value in
		 * stored form; only the keys of CountryProfiles::KEYS are used).
		 *
		 * @param array $profiles code => definition.
		 */
		$filtered = function_exists( 'apply_filters' ) ? apply_filters( 'wpac_country_profiles', $profiles ) : $profiles;
		$out      = array();
		foreach ( (array) $filtered as $code => $p ) {
			$code = self::clean( $code );
			if ( '' === $code || ! is_array( $p ) || empty( $p['label'] ) ) {
				continue;
			}
			$values = isset( $p['values'] ) && is_array( $p['values'] ) ? array_intersect_key( $p['values'], array_flip( self::KEYS ) ) : array();
			$out[ $code ] = array(
				'label'             => (string) $p['label'],
				'reviewed'          => ! empty( $p['reviewed'] ),
				'countries'         => array_values( array_filter( array_map( 'strtoupper', array_map( 'strval', isset( $p['countries'] ) ? (array) $p['countries'] : array() ) ) ) ),
				'retention_minimum' => isset( $p['retention_minimum'] ) ? max( 0, (int) $p['retention_minimum'] ) : 10,
				'einvoice_auto'     => ! empty( $p['einvoice_auto'] ),
				'values'            => array_map(
					static function ( $v ) {
						return is_scalar( $v ) || null === $v ? ( null === $v ? '' : $v ) : '';
					},
					$values
				),
			);
		}
		return $out;
	}

	/**
	 * A profile code as stored ('' when not valid): letters, digits and "_", upper case.
	 *
	 * @param mixed $code Code.
	 * @return string
	 */
	public static function clean( $code ) {
		$code = strtoupper( trim( is_scalar( $code ) ? (string) $code : '' ) );
		return preg_match( '/^[A-Z0-9_]{2,12}$/', $code ) ? $code : '';
	}

	/**
	 * One profile or null.
	 *
	 * @param string $code Code.
	 * @return array|null
	 */
	public static function get( $code ) {
		$all  = self::all();
		$code = self::clean( $code );
		return isset( $all[ $code ] ) ? $all[ $code ] : null;
	}

	/**
	 * Name of a profile with the review state, e.g. "Austria (AT) – not legally or tax reviewed".
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function name( $code ) {
		$p = self::get( $code );
		if ( ! $p ) {
			return (string) $code;
		}
		$name = $p['label'];
		if ( false === strpos( $name, self::clean( $code ) ) ) {
			/* translators: 1: profile name, 2: profile code */
			$name = sprintf( __( '%1$s (%2$s)', 'affiliate-captain' ), $name, self::clean( $code ) );
		}
		return $p['reviewed'] ? $name : $name . ' – ' . __( 'not legally or tax reviewed', 'affiliate-captain' );
	}

	/**
	 * Profile proposed for a country: the profile with that code, else one naming the country,
	 * else "EU" for a member state, else "OTHER" (or the first profile when those were removed).
	 *
	 * @param string $country ISO country.
	 * @return string Code ('' when there are no profiles).
	 */
	public static function for_country( $country ) {
		$country = strtoupper( (string) $country );
		$all     = self::all();
		if ( '' !== $country && isset( $all[ $country ] ) ) {
			return $country;
		}
		foreach ( $all as $code => $p ) {
			if ( ! in_array( $code, array( 'EU', 'OTHER' ), true ) && in_array( $country, $p['countries'], true ) ) {
				return $code;
			}
		}
		if ( in_array( $country, self::EU, true ) && isset( $all['EU'] ) ) {
			return 'EU';
		}
		if ( isset( $all['OTHER'] ) ) {
			return 'OTHER';
		}
		return $all ? (string) key( $all ) : '';
	}

	/**
	 * Profile proposed at the first setup: from the WooCommerce shop country, else the operator country.
	 *
	 * @return string
	 */
	public static function proposed() {
		$country = '';
		if ( function_exists( 'WC' ) && WC() && isset( WC()->countries ) && is_object( WC()->countries ) && method_exists( WC()->countries, 'get_base_country' ) ) {
			$country = strtoupper( (string) WC()->countries->get_base_country() );
		}
		return self::for_country( '' !== $country ? $country : (string) Settings::get( 'operator_country' ) );
	}

	/**
	 * Legal minimum retention of credit notes for a country, from its profile (S9: the profile
	 * proposes the period; DE 8, AT 7, CH 10, otherwise 10 years).
	 *
	 * @param string $country ISO country.
	 * @return int
	 */
	public static function retention_minimum( $country ) {
		$p = self::get( self::for_country( $country ) );
		return $p ? (int) $p['retention_minimum'] : 10;
	}

	/**
	 * Whether "automatic" attaches the e-invoice for an operator of this country (SET-AC-04 e).
	 *
	 * @param string $country ISO country.
	 * @return bool
	 */
	public static function einvoice_auto( $country ) {
		$p = self::get( self::for_country( $country ) );
		return $p ? (bool) $p['einvoice_auto'] : false;
	}

	/**
	 * Values of a profile in stored form. Texts (the disclosure note) in the site language: the base
	 * settings hold the site language, other languages are set per language (SET-AC-17). Document
	 * notes are not set as texts: empty = the built-in text in each document language (P3-11).
	 *
	 * @param string $code Code.
	 * @return array|null
	 */
	public static function values( $code ) {
		$switched = Locale::switch_to( Locale::site() );
		try {
			$p = self::get( $code );
		} finally {
			Locale::restore( $switched );
		}
		return $p ? $p['values'] : null;
	}

	/**
	 * What applying a profile would change (old → new), checked like the settings page.
	 *
	 * @param string $code Code.
	 * @return array|\WP_Error { changes, errors, values }
	 */
	public static function preview( $code ) {
		$values = self::values( $code );
		if ( null === $values ) {
			return new \WP_Error( 'wpac_profile_unknown', __( 'Unknown country profile.', 'affiliate-captain' ) );
		}
		$current = Settings::all();
		return SettingsTransfer::check( self::keep_country( $code, $values, $current ), $current );
	}

	/**
	 * A profile proposed for several countries (CH for Switzerland and Liechtenstein) keeps an
	 * operator country of its list: a Liechtenstein operator stays in LI (P3-09).
	 *
	 * @param string $code    Code.
	 * @param array  $values  Values of the profile.
	 * @param array  $current Current settings.
	 * @return array
	 */
	public static function keep_country( $code, array $values, array $current ) {
		$p       = self::get( $code );
		$country = isset( $current['operator_country'] ) ? strtoupper( (string) $current['operator_country'] ) : '';
		if ( $p && isset( $values['operator_country'] ) && '' !== $country && in_array( $country, $p['countries'], true ) ) {
			$values['operator_country'] = $country;
		}
		return $values;
	}

	/**
	 * Apply the checked values of a profile (only the valid ones), log it with the profile name and
	 * remember the profile.
	 *
	 * @param string $code    Code.
	 * @param array  $checked Result of preview() as shown to the user.
	 * @param string $source  admin or cli.
	 * @return bool
	 */
	public static function apply( $code, array $checked, $source = 'admin' ) {
		$p = self::get( $code );
		if ( ! $p ) {
			return false;
		}
		$code = self::clean( $code );
		$ok   = SettingsTransfer::apply(
			$checked,
			'settings.profile',
			array(
				'profile'      => $code,
				'profile_name' => $p['label'],
				'reviewed'     => $p['reviewed'] ? 1 : 0,
				'source'       => (string) $source,
			)
		);
		if ( $ok ) {
			update_option(
				self::OPTION,
				array(
					'code'     => $code,
					'name'     => $p['label'],
					'at'       => gmdate( 'Y-m-d H:i:s' ),
					'reviewed' => $p['reviewed'],
				),
				false
			);
		}
		return $ok;
	}

	/**
	 * The profile applied last, or null.
	 *
	 * @return array|null { code, name, at, reviewed }
	 */
	public static function applied() {
		$a = get_option( self::OPTION, null );
		return is_array( $a ) && ! empty( $a['code'] ) ? $a : null;
	}

	/**
	 * Site Health (S9): a recommendation as long as no retention minimum for credit notes is set.
	 *
	 * @param array $tests Tests.
	 * @return array
	 */
	public static function site_status_tests( $tests ) {
		$tests['direct']['wpac_retention_minimum'] = array(
			'label' => __( 'Retention of credit notes (Affiliate Captain)', 'affiliate-captain' ),
			'test'  => array( __CLASS__, 'site_health_result' ),
		);
		return $tests;
	}

	/**
	 * Site Health result of the retention check.
	 *
	 * @return array
	 */
	public static function site_health_result() {
		$set     = (int) Settings::get( 'statement_retention_minimum' ) > 0;
		$country = strtoupper( (string) Settings::get( 'operator_country' ) );
		$years   = \WPAffiliateCaptain\Privacy\Retention::minimum_years();
		return array(
			'label'       => $set ? __( 'A retention period for credit notes is set', 'affiliate-captain' ) : __( 'No retention period for credit notes is set yet', 'affiliate-captain' ),
			'status'      => $set ? 'good' : 'recommended',
			'badge'       => array(
				'label' => __( 'Partner program', 'affiliate-captain' ),
				'color' => $set ? 'blue' : 'orange',
			),
			'description' => '<p>' . esc_html(
				$set
					/* translators: %d: years */
					? sprintf( __( 'Credit notes are kept for at least %d years.', 'affiliate-captain' ), $years )
					/* translators: 1: years, 2: country code */
					: sprintf( __( 'Until you set the legal minimum retention of credit notes, the built-in value of %1$d years for your country (%2$s) applies; it is not legally reviewed. Apply a country profile (Settings → Payouts & credit notes) or enter the minimum on the "Data & maintenance" tab, after checking it with your adviser.', 'affiliate-captain' ), $years, '' !== $country ? $country : '—' )
			) . '</p>',
			'actions'     => '<p><a href="' . esc_url( admin_url( 'admin.php?page=wpac-settings&tab=data' ) ) . '">' . esc_html__( 'Open the settings', 'affiliate-captain' ) . '</a></p>',
			'test'        => 'wpac_retention_minimum',
		);
	}

	/**
	 * English names of all countries (ISO 3166-1 alpha-2), used for the operator country when
	 * neither WooCommerce nor PHP intl give a name (SET-AC-04 d: full list instead of 36 countries).
	 */
	const ISO_COUNTRIES = array(
		'AD' => 'Andorra',
		'AE' => 'United Arab Emirates',
		'AF' => 'Afghanistan',
		'AG' => 'Antigua & Barbuda',
		'AI' => 'Anguilla',
		'AL' => 'Albania',
		'AM' => 'Armenia',
		'AO' => 'Angola',
		'AQ' => 'Antarctica',
		'AR' => 'Argentina',
		'AS' => 'American Samoa',
		'AT' => 'Austria',
		'AU' => 'Australia',
		'AW' => 'Aruba',
		'AX' => 'Åland Islands',
		'AZ' => 'Azerbaijan',
		'BA' => 'Bosnia & Herzegovina',
		'BB' => 'Barbados',
		'BD' => 'Bangladesh',
		'BE' => 'Belgium',
		'BF' => 'Burkina Faso',
		'BG' => 'Bulgaria',
		'BH' => 'Bahrain',
		'BI' => 'Burundi',
		'BJ' => 'Benin',
		'BL' => 'St. Barthélemy',
		'BM' => 'Bermuda',
		'BN' => 'Brunei',
		'BO' => 'Bolivia',
		'BQ' => 'Caribbean Netherlands',
		'BR' => 'Brazil',
		'BS' => 'Bahamas',
		'BT' => 'Bhutan',
		'BV' => 'Bouvet Island',
		'BW' => 'Botswana',
		'BY' => 'Belarus',
		'BZ' => 'Belize',
		'CA' => 'Canada',
		'CC' => 'Cocos (Keeling) Islands',
		'CD' => 'Congo - Kinshasa',
		'CF' => 'Central African Republic',
		'CG' => 'Congo - Brazzaville',
		'CH' => 'Switzerland',
		'CI' => 'Côte d’Ivoire',
		'CK' => 'Cook Islands',
		'CL' => 'Chile',
		'CM' => 'Cameroon',
		'CN' => 'China',
		'CO' => 'Colombia',
		'CR' => 'Costa Rica',
		'CU' => 'Cuba',
		'CV' => 'Cape Verde',
		'CW' => 'Curaçao',
		'CX' => 'Christmas Island',
		'CY' => 'Cyprus',
		'CZ' => 'Czechia',
		'DE' => 'Germany',
		'DJ' => 'Djibouti',
		'DK' => 'Denmark',
		'DM' => 'Dominica',
		'DO' => 'Dominican Republic',
		'DZ' => 'Algeria',
		'EC' => 'Ecuador',
		'EE' => 'Estonia',
		'EG' => 'Egypt',
		'EH' => 'Western Sahara',
		'ER' => 'Eritrea',
		'ES' => 'Spain',
		'ET' => 'Ethiopia',
		'FI' => 'Finland',
		'FJ' => 'Fiji',
		'FK' => 'Falkland Islands',
		'FM' => 'Micronesia',
		'FO' => 'Faroe Islands',
		'FR' => 'France',
		'GA' => 'Gabon',
		'GB' => 'United Kingdom',
		'GD' => 'Grenada',
		'GE' => 'Georgia',
		'GF' => 'French Guiana',
		'GG' => 'Guernsey',
		'GH' => 'Ghana',
		'GI' => 'Gibraltar',
		'GL' => 'Greenland',
		'GM' => 'Gambia',
		'GN' => 'Guinea',
		'GP' => 'Guadeloupe',
		'GQ' => 'Equatorial Guinea',
		'GR' => 'Greece',
		'GS' => 'South Georgia & South Sandwich Islands',
		'GT' => 'Guatemala',
		'GU' => 'Guam',
		'GW' => 'Guinea-Bissau',
		'GY' => 'Guyana',
		'HK' => 'Hong Kong SAR China',
		'HM' => 'Heard & McDonald Islands',
		'HN' => 'Honduras',
		'HR' => 'Croatia',
		'HT' => 'Haiti',
		'HU' => 'Hungary',
		'ID' => 'Indonesia',
		'IE' => 'Ireland',
		'IL' => 'Israel',
		'IM' => 'Isle of Man',
		'IN' => 'India',
		'IO' => 'British Indian Ocean Territory',
		'IQ' => 'Iraq',
		'IR' => 'Iran',
		'IS' => 'Iceland',
		'IT' => 'Italy',
		'JE' => 'Jersey',
		'JM' => 'Jamaica',
		'JO' => 'Jordan',
		'JP' => 'Japan',
		'KE' => 'Kenya',
		'KG' => 'Kyrgyzstan',
		'KH' => 'Cambodia',
		'KI' => 'Kiribati',
		'KM' => 'Comoros',
		'KN' => 'St. Kitts & Nevis',
		'KP' => 'North Korea',
		'KR' => 'South Korea',
		'KW' => 'Kuwait',
		'KY' => 'Cayman Islands',
		'KZ' => 'Kazakhstan',
		'LA' => 'Laos',
		'LB' => 'Lebanon',
		'LC' => 'St. Lucia',
		'LI' => 'Liechtenstein',
		'LK' => 'Sri Lanka',
		'LR' => 'Liberia',
		'LS' => 'Lesotho',
		'LT' => 'Lithuania',
		'LU' => 'Luxembourg',
		'LV' => 'Latvia',
		'LY' => 'Libya',
		'MA' => 'Morocco',
		'MC' => 'Monaco',
		'MD' => 'Moldova',
		'ME' => 'Montenegro',
		'MF' => 'St. Martin',
		'MG' => 'Madagascar',
		'MH' => 'Marshall Islands',
		'MK' => 'North Macedonia',
		'ML' => 'Mali',
		'MM' => 'Myanmar (Burma)',
		'MN' => 'Mongolia',
		'MO' => 'Macao SAR China',
		'MP' => 'Northern Mariana Islands',
		'MQ' => 'Martinique',
		'MR' => 'Mauritania',
		'MS' => 'Montserrat',
		'MT' => 'Malta',
		'MU' => 'Mauritius',
		'MV' => 'Maldives',
		'MW' => 'Malawi',
		'MX' => 'Mexico',
		'MY' => 'Malaysia',
		'MZ' => 'Mozambique',
		'NA' => 'Namibia',
		'NC' => 'New Caledonia',
		'NE' => 'Niger',
		'NF' => 'Norfolk Island',
		'NG' => 'Nigeria',
		'NI' => 'Nicaragua',
		'NL' => 'Netherlands',
		'NO' => 'Norway',
		'NP' => 'Nepal',
		'NR' => 'Nauru',
		'NU' => 'Niue',
		'NZ' => 'New Zealand',
		'OM' => 'Oman',
		'PA' => 'Panama',
		'PE' => 'Peru',
		'PF' => 'French Polynesia',
		'PG' => 'Papua New Guinea',
		'PH' => 'Philippines',
		'PK' => 'Pakistan',
		'PL' => 'Poland',
		'PM' => 'St. Pierre & Miquelon',
		'PN' => 'Pitcairn Islands',
		'PR' => 'Puerto Rico',
		'PS' => 'Palestinian Territories',
		'PT' => 'Portugal',
		'PW' => 'Palau',
		'PY' => 'Paraguay',
		'QA' => 'Qatar',
		'RE' => 'Réunion',
		'RO' => 'Romania',
		'RS' => 'Serbia',
		'RU' => 'Russia',
		'RW' => 'Rwanda',
		'SA' => 'Saudi Arabia',
		'SB' => 'Solomon Islands',
		'SC' => 'Seychelles',
		'SD' => 'Sudan',
		'SE' => 'Sweden',
		'SG' => 'Singapore',
		'SH' => 'St. Helena',
		'SI' => 'Slovenia',
		'SJ' => 'Svalbard & Jan Mayen',
		'SK' => 'Slovakia',
		'SL' => 'Sierra Leone',
		'SM' => 'San Marino',
		'SN' => 'Senegal',
		'SO' => 'Somalia',
		'SR' => 'Suriname',
		'SS' => 'South Sudan',
		'ST' => 'São Tomé & Príncipe',
		'SV' => 'El Salvador',
		'SX' => 'Sint Maarten',
		'SY' => 'Syria',
		'SZ' => 'Eswatini',
		'TC' => 'Turks & Caicos Islands',
		'TD' => 'Chad',
		'TF' => 'French Southern Territories',
		'TG' => 'Togo',
		'TH' => 'Thailand',
		'TJ' => 'Tajikistan',
		'TK' => 'Tokelau',
		'TL' => 'Timor-Leste',
		'TM' => 'Turkmenistan',
		'TN' => 'Tunisia',
		'TO' => 'Tonga',
		'TR' => 'Türkiye',
		'TT' => 'Trinidad & Tobago',
		'TV' => 'Tuvalu',
		'TW' => 'Taiwan',
		'TZ' => 'Tanzania',
		'UA' => 'Ukraine',
		'UG' => 'Uganda',
		'UM' => 'U.S. Outlying Islands',
		'US' => 'United States',
		'UY' => 'Uruguay',
		'UZ' => 'Uzbekistan',
		'VA' => 'Vatican City',
		'VC' => 'St. Vincent & Grenadines',
		'VE' => 'Venezuela',
		'VG' => 'British Virgin Islands',
		'VI' => 'U.S. Virgin Islands',
		'VN' => 'Vietnam',
		'VU' => 'Vanuatu',
		'WF' => 'Wallis & Futuna',
		'WS' => 'Samoa',
		'YE' => 'Yemen',
		'YT' => 'Mayotte',
		'ZA' => 'South Africa',
		'ZM' => 'Zambia',
		'ZW' => 'Zimbabwe',
	);
}
