<?php
/**
 * SET-AC-03 country profiles and SET-AC-04 taxes per country as settings (owner decisions S8, S9).
 * Included from run.php right before r8-render.php.
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Core\CountryProfiles;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Core\SettingsSchema;
use WPAffiliateCaptain\Core\SettingsTransfer;
use WPAffiliateCaptain\Payouts\EInvoice;
use WPAffiliateCaptain\Payouts\PayoutService;
use WPAffiliateCaptain\Payouts\Statement;
use WPAffiliateCaptain\Payouts\Vat;
use WPAffiliateCaptain\Privacy\Retention;

echo "SET-AC-03/04 country profiles, taxes per country\n";

global $wpdb;
$cp_saved = get_option( 'wpac_settings' );
$cp_set   = static function ( array $values ) {
	update_option( 'wpac_settings', array_merge( Settings::all(), $values ) );
	Settings::flush_cache();
};
SettingsSchema::flush();
$cp_new_keys = array( 'statement_retention_minimum', 'small_business_limit_previous', 'small_business_limit_current', 'note_self_billing', 'note_reverse_charge', 'note_small_business', 'note_private_person' );

// Profiles of S8: DE, AT, CH, EU, OTHER; none reviewed; only known keys; all new keys carry the legal mark.
$cp_all   = CountryProfiles::all();
$cp_legal = array();
foreach ( $cp_new_keys as $cp_k ) {
	$cp_legal[ $cp_k ] = ! empty( SettingsSchema::field( $cp_k )['legal'] );
}
$cp_unknown = array();
foreach ( $cp_all as $cp_code => $cp_p ) {
	$cp_unknown = array_merge( $cp_unknown, array_diff( array_keys( $cp_p['values'] ), CountryProfiles::KEYS ) );
}
check(
	'SET-AC-03 five profiles (S8), all marked "not reviewed", only known keys, new values with legal mark',
	array( array( 'DE', 'AT', 'CH', 'EU', 'OTHER' ), array( false ), array(), array_fill_keys( $cp_new_keys, true ), true ),
	array( array_keys( $cp_all ), array_values( array_unique( array_column( $cp_all, 'reviewed' ) ) ), $cp_unknown, $cp_legal, false !== strpos( CountryProfiles::name( 'AT' ), 'not legally or tax reviewed' ) )
);
check(
	'SET-AC-03 proposal per country: own profile, Liechtenstein → CH, EU member → EU, else OTHER',
	array( 'DE', 'AT', 'CH', 'CH', 'EU', 'EU', 'OTHER', 'OTHER' ),
	array( CountryProfiles::for_country( 'DE' ), CountryProfiles::for_country( 'AT' ), CountryProfiles::for_country( 'CH' ), CountryProfiles::for_country( 'LI' ), CountryProfiles::for_country( 'FR' ), CountryProfiles::for_country( 'PL' ), CountryProfiles::for_country( 'US' ), CountryProfiles::for_country( '' ) )
);

// A test per profile: from the German defaults, the preview is valid and lists old → new; nothing is changed by the preview.
$cp_base = array_merge(
	Settings::all(),
	array( 'operator_country' => 'DE', 'currency' => 'EUR', 'vat_rate' => '19', 'statement_retention_years' => 10, 'statement_retention_minimum' => 0, 'self_billing' => 1, 'credit_prefix' => 'GS', 'storno_prefix' => 'ST', 'correction_prefix' => 'KG', 'default_disclosure' => 'Advertisement', 'einvoice_mail' => 'auto', 'cookie_mode' => 'consent', 'cookie_days' => 30, 'hold_days' => 30, 'small_business_limit_previous' => '25000', 'small_business_limit_current' => '100000', 'note_self_billing' => '', 'note_reverse_charge' => '', 'note_small_business' => '', 'note_private_person' => '', 'exchange_rates' => '', 'min_payout_minor' => 5000, 'fraud_max_order_minor' => 0, 'default_rate_type' => 'percent', 'default_rate' => '10' )
);
$cp_expect = array(
	'DE'    => array(
		'statement_retention_minimum' => array( 0, 8 ),
		'statement_retention_years'   => array( 10, 8 ),
	),
	'AT'    => array(
		'operator_country'              => array( 'DE', 'AT' ),
		'vat_rate'                      => array( '19', '20' ),
		'statement_retention_minimum'   => array( 0, 7 ),
		'statement_retention_years'     => array( 10, 7 ),
		'default_disclosure'            => array( 'Advertisement', 'Paid advertisement' ),
		'small_business_limit_previous' => array( '25000', '55000' ),
		'small_business_limit_current'  => array( '100000', '55000' ),
	),
	'CH'    => array( 'operator_country', 'currency', 'vat_rate', 'statement_retention_minimum', 'small_business_limit_previous' ),
	'EU'    => array( 'vat_rate', 'statement_retention_minimum', 'credit_prefix', 'storno_prefix', 'correction_prefix', 'small_business_limit_previous', 'small_business_limit_current' ),
	'OTHER' => array( 'vat_rate', 'statement_retention_minimum', 'self_billing', 'credit_prefix', 'storno_prefix', 'correction_prefix', 'small_business_limit_previous', 'small_business_limit_current', 'note_self_billing', 'note_reverse_charge', 'note_small_business' ),
);
foreach ( $cp_expect as $cp_code => $cp_want ) {
	update_option( 'wpac_settings', $cp_base );
	Settings::flush_cache();
	$cp_prev  = CountryProfiles::preview( $cp_code );
	$cp_wkeys = isset( $cp_want['statement_retention_minimum'][1] ) ? array_keys( $cp_want ) : $cp_want;
	$cp_pairs = isset( $cp_want['statement_retention_minimum'][1] ) ? array_intersect_key( $cp_prev['changes'], $cp_want ) : array();
	ksort( $cp_pairs );
	if ( isset( $cp_want['statement_retention_minimum'][1] ) ) {
		ksort( $cp_want );
	} else {
		$cp_want = array();
	}
	check( "SET-AC-03 profile {$cp_code}: preview old → new without errors, expected keys listed, nothing changed yet", array( array(), array(), $cp_want, 10 ), array( $cp_prev['errors'], array_values( array_diff( $cp_wkeys, array_keys( $cp_prev['changes'] ) ) ), $cp_pairs, (int) Settings::get( 'statement_retention_years' ) ) );
}
check( 'SET-AC-03 unknown profile is refused', true, is_wp_error( CountryProfiles::preview( 'XX' ) ) );

// "Done when": an Austrian shop gets Austrian values without code – apply, audit entry with the profile name.
update_option( 'wpac_settings', $cp_base );
Settings::flush_cache();
delete_option( CountryProfiles::OPTION );
$cp_audit_before = (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . t( 'audit' ) );
$cp_at           = CountryProfiles::preview( 'AT' );
$cp_ok           = CountryProfiles::apply( 'AT', $cp_at, 'admin' );
$cp_audit        = $wpdb->get_row( $wpdb->prepare( 'SELECT action, details FROM ' . t( 'audit' ) . " WHERE id > %d AND action = 'settings.profile' ORDER BY id DESC LIMIT 1", $cp_audit_before ) );
$cp_details      = $cp_audit ? json_decode( (string) $cp_audit->details, true ) : array();
check(
	'SET-AC-03 AT applied: Austrian VAT, retention 7, disclosure, limits; audit entry with profile name and old → new; profile remembered',
	array( true, 'AT', '20', 7, 7, 'Paid advertisement', '55000', 'AT', 'Austria', 0, array( 'from' => '19', 'to' => '20' ), 'AT' ),
	array( $cp_ok, Settings::get( 'operator_country' ), Settings::get( 'vat_rate' ), Retention::minimum_years(), Retention::years(), Settings::get( 'default_disclosure' ), Settings::get( 'small_business_limit_previous' ), $cp_details['profile'] ?? '', $cp_details['profile_name'] ?? '', $cp_details['reviewed'] ?? null, $cp_details['values']['vat_rate'] ?? null, CountryProfiles::applied()['code'] ?? '' )
);
check(
	'SET-AC-03 AT documents: Austrian legal references, no e-invoice by mail on "automatic"; DE still yes',
	array( true, true, false, true ),
	array( false !== strpos( Statement::vat_note( Vat::REVERSE_CHARGE ), '§19 Abs. 1 UStG (AT)' ), false !== strpos( Statement::vat_note( Vat::SMALL_BUSINESS ), '§6 Abs. 1 Z 27 UStG (AT)' ), EInvoice::by_mail( Vat::STANDARD, array( 'country' => 'AT' ) ), EInvoice::by_mail( Vat::STANDARD, array( 'country' => 'DE' ) ) )
);
// Everything stays changeable afterwards (S8).
$cp_after = SettingsTransfer::check( array( 'vat_rate' => '10', 'statement_retention_years' => 12 ), Settings::all() );
check( 'SET-AC-03 values stay changeable after the profile', array( array(), array( 'vat_rate' => '10', 'statement_retention_years' => 12 ) ), array( $cp_after['errors'], $cp_after['values'] ) );

// CH: currency, rate, amounts converted with the currency like the settings page.
update_option( 'wpac_settings', $cp_base );
Settings::flush_cache();
$cp_ch = CountryProfiles::preview( 'CH' );
CountryProfiles::apply( 'CH', $cp_ch, 'cli' );
check( 'SET-AC-03 CH applied: CHF, 8.1 %, retention minimum 10, small business 100,000', array( 'CH', 'CHF', '8.1', 10, '100000' ), array( Settings::get( 'operator_country' ), Settings::get( 'currency' ), Settings::get( 'vat_rate' ), Retention::minimum_years(), Settings::get( 'small_business_limit_previous' ) ) );

// OTHER: neutral – no VAT rate (payouts with domestic VAT wait), no small business check, texts without EU/German norms.
update_option( 'wpac_settings', array_merge( $cp_base, array( 'operator_country' => 'US', 'currency' => 'USD', 'terms_page_id' => 0 ) ) );
Settings::flush_cache();
CountryProfiles::apply( 'OTHER', CountryProfiles::preview( 'OTHER' ) );
$cp_other_rc = Statement::vat_note( Vat::REVERSE_CHARGE, 'US', PayoutService::operator_snapshot( true ) );
$cp_aff      = \WPAffiliateCaptain\Affiliates\AffiliateRepository::create( array( 'name' => 'CP Partner', 'email' => 'cp-partner@example.com', 'status' => 'active' ) );
$cp_prof     = \WPAffiliateCaptain\Payouts\ProfileRepository::save( $cp_aff, array( 'tax_status' => 'business', 'legal_name' => 'CP Partner LLC', 'address' => "1 Main St\nAustin", 'country' => 'US', 'tax_number' => '12-3456789', 'account_holder' => 'CP Partner', 'iban' => 'DE89370400440532013000' ) );
PayoutService::blocked_reason( $cp_aff, null, $cp_code_out );
check(
	'SET-AC-03 OTHER: VAT rate empty, payouts with domestic VAT held back (vat_rate), no § 19 check, neutral texts, self-billing off',
	array( '', 'vat_rate', array(), false, false, 0 ),
	array( Settings::get( 'vat_rate' ), is_wp_error( $cp_prof ) ? $cp_prof->get_error_message() : $cp_code_out, PayoutService::small_business_warnings(), false !== strpos( $cp_other_rc, 'Directive' ), false !== strpos( (string) Settings::get( 'note_self_billing' ), 'Art.' ), (int) Settings::get( 'self_billing' ) )
);

// SET-AC-04 a: own minimum with a warning below the country value; the filter wins; Site Health asks while none is set (S9).
$cp_set( array( 'operator_country' => 'DE', 'currency' => 'EUR', 'statement_retention_minimum' => 0, 'statement_retention_years' => 10 ) );
$cp_health_unset = CountryProfiles::site_health_result();
Settings::sanitize( array( 'statement_retention_minimum' => '5', 'statement_retention_years' => '6', 'operator_country' => 'DE' ), $cp_err, $cp_notes );
$cp_low = Settings::sanitize( array( 'statement_retention_minimum' => '5', 'statement_retention_years' => '6', 'operator_country' => 'DE' ), $cp_err, $cp_notes );
$cp_set( array( 'statement_retention_minimum' => 9 ) );
$cp_min_set = Retention::minimum_years();
$cp_health  = CountryProfiles::site_health_result();
add_filter( 'wpac_retention_minimum_years', static function ( $years, $country ) { return 'DE' === $country ? 11 : $years; }, 10, 2 );
$cp_min_filtered = Retention::minimum_years();
remove_all_filters( 'wpac_retention_minimum_years' );
check(
	'SET-AC-04 a retention minimum: country value without setting, own value with warning below it, filter wins, Site Health recommendation only while unset',
	array( 8, 'recommended', true, 6, 9, 11, 'good', 8 ),
	array( Retention::minimum_years( 'DE', 0 ), $cp_health_unset['status'], isset( $cp_notes['statement_retention_minimum'] ), $cp_low['statement_retention_years'], $cp_min_set, $cp_min_filtered, $cp_health['status'], Retention::minimum_years( 'DE', 0 ) )
);
check( 'SET-AC-04 a other countries keep the former minimum (AT 7, CH 10, others 10) without a setting', array( 7, 10, 10, 10 ), array( Retention::minimum_years( 'AT', 0 ), Retention::minimum_years( 'CH', 0 ), Retention::minimum_years( 'FR', 0 ), Retention::minimum_years( 'US', 0 ) ) );

// SET-AC-04 b: two amount fields, empty = no check; installations before 0.21.0 keep the § 19 check (DE, EUR).
$cp_limits = null;
add_filter( 'wpac_small_business_limits', static function ( $limits ) use ( &$cp_limits ) { $cp_limits = $limits; return $limits; }, 10, 1 );
$cp_set( array( 'small_business_limit_previous' => '30000', 'small_business_limit_current' => '' ) );
PayoutService::small_business_warnings();
$cp_l1 = $cp_limits;
$cp_set( array( 'small_business_limit_previous' => '', 'small_business_limit_current' => '' ) );
PayoutService::small_business_warnings();
$cp_l2 = $cp_limits;
remove_all_filters( 'wpac_small_business_limits' );
$cp_legacy_saved = array_diff_key( Settings::all(), array_flip( array( 'small_business_limit_previous', 'small_business_limit_current' ) ) );
update_option( 'wpac_settings', array_merge( $cp_legacy_saved, array( 'operator_country' => 'DE', 'currency' => 'EUR' ) ) );
Settings::flush_cache();
$cp_legacy_de = array( Settings::get( 'small_business_limit_previous' ), Settings::get( 'small_business_limit_current' ) );
update_option( 'wpac_settings', array_merge( $cp_legacy_saved, array( 'operator_country' => 'AT', 'currency' => 'EUR' ) ) );
Settings::flush_cache();
$cp_legacy_at = array( Settings::get( 'small_business_limit_previous' ), Settings::get( 'small_business_limit_current' ) );
$cp_sb        = Settings::sanitize( array( 'small_business_limit_previous' => 'abc', 'small_business_limit_current' => '60.500,00' ), $cp_sb_err );
check(
	'SET-AC-04 b limits from the settings (empty = no check), old installations keep the former check, input checked',
	array( array( 'EUR' => array( 3000000, PHP_INT_MAX ) ), array(), array( '25000', '100000' ), array( '', '' ), true, '60500.00' ),
	array( $cp_l1, $cp_l2, $cp_legacy_de, $cp_legacy_at, isset( $cp_sb_err['small_business_limit_previous'] ), $cp_sb['small_business_limit_current'] )
);

// SET-AC-04 c: own notes are frozen per document; documents issued before keep their text.
$cp_set( array( 'operator_country' => 'DE', 'currency' => 'EUR', 'vat_rate' => '19', 'self_billing' => 1, 'note_self_billing' => '', 'note_reverse_charge' => '', 'note_small_business' => '', 'note_private_person' => '' ) );
$cp_op_old = PayoutService::operator_snapshot( true );
$cp_p      = $wpdb->get_row( 'SELECT * FROM ' . t( 'payouts' ) . " WHERE operator_snapshot IS NOT NULL AND operator_snapshot <> '' AND storno_number IS NULL ORDER BY id DESC LIMIT 1" );
if ( ! $cp_p ) {
	$cp_p = $wpdb->get_row( 'SELECT * FROM ' . t( 'payouts' ) . " WHERE operator_snapshot IS NOT NULL AND operator_snapshot <> '' ORDER BY id DESC LIMIT 1" );
}
$cp_p->vat_mode = Vat::REVERSE_CHARGE;
$cp_pdf_before  = Statement::build( $cp_p );
$cp_xml_before  = $cp_p ? EInvoice::payout_data( $cp_p, json_decode( (string) $cp_p->operator_snapshot, true ), false ) : null;
$cp_set( array( 'note_self_billing' => 'CP-OWN-SELF-BILLING-NOTE', 'note_reverse_charge' => 'CP-OWN-REVERSE-CHARGE-NOTE', 'note_small_business' => 'CP-OWN-SMALL-BUSINESS-NOTE' ) );
$cp_op_new     = PayoutService::operator_snapshot( true );
$cp_pdf_after  = Statement::build( $cp_p );
$cp_xml_after  = EInvoice::payout_data( $cp_p, json_decode( (string) $cp_p->operator_snapshot, true ), false );
$cp_p_new      = clone $cp_p;
$cp_p_new->operator_snapshot = wp_json_encode( array_merge( json_decode( (string) $cp_p->operator_snapshot, true ), array( 'notes' => $cp_op_new['notes'] ) ) );
$cp_pdf_new    = Statement::build( $cp_p_new );
$cp_xml_new    = EInvoice::payout_data( $cp_p_new, json_decode( (string) $cp_p_new->operator_snapshot, true ), false );
check(
	'SET-AC-04 c notes: frozen in the snapshot of new documents only; old documents (PDF and e-invoice) unchanged; new ones print the own text',
	array( false, array( 'self_billing' => 'CP-OWN-SELF-BILLING-NOTE', Vat::REVERSE_CHARGE => 'CP-OWN-REVERSE-CHARGE-NOTE', Vat::SMALL_BUSINESS => 'CP-OWN-SMALL-BUSINESS-NOTE' ), false, true, true, true, true, 'CP-OWN-REVERSE-CHARGE-NOTE', 'CP-OWN-SMALL-BUSINESS-NOTE' ),
	array( isset( $cp_op_old['notes'] ), $cp_op_new['notes'], isset( PayoutService::operator_snapshot()['notes'] ), $cp_pdf_before === $cp_pdf_after, $cp_xml_before === $cp_xml_after, false !== strpos( $cp_pdf_new, 'CP-OWN-REVERSE-CHARGE-NOTE' ), false !== strpos( $cp_pdf_new, 'CP-OWN-SELF-BILLING-NOTE' ), $cp_xml_new['exemptions']['AE'][0], $cp_xml_new['exemptions']['E'][0] )
);
check( 'SET-AC-04 c without own note the built-in texts stay (German § 19 exemption reason in the e-invoice)', array( EInvoice::SMALL_BUSINESS_DE, true ), array( $cp_xml_before['exemptions']['E'][0], false !== strpos( Statement::self_billing_note( 'DE' ), '§14 Abs. 2 S. 3 UStG' ) ) );

// SET-AC-04 d: all countries for the operator country; e: "automatic" follows the country profile.
$cp_cty = Settings::sanitize( array( 'operator_country' => 'BR' ), $cp_cty_err );
add_filter( 'wpac_country_profiles', static function ( $p ) { $p['FR'] = array( 'label' => 'France', 'countries' => array( 'FR' ), 'einvoice_auto' => true, 'retention_minimum' => 10, 'values' => array( 'operator_country' => 'FR', 'vat_rate' => '20', 'unknown_key' => 'x' ) ); return $p; } );
$cp_fr = array( EInvoice::by_mail( Vat::STANDARD, array( 'country' => 'FR' ) ), EInvoice::by_mail( Vat::REVERSE_CHARGE, array( 'country' => 'FR' ) ), CountryProfiles::for_country( 'FR' ), array_keys( CountryProfiles::values( 'FR' ) ) );
remove_all_filters( 'wpac_country_profiles' );
check(
	'SET-AC-04 d/e: Brazil can be chosen (249 countries); "automatic" e-invoice by profile, also for a profile added by filter (unknown keys dropped)',
	array( 'BR', false, 249, array( true, false, 'FR', array( 'operator_country', 'vat_rate' ) ), false ),
	array( $cp_cty['operator_country'], isset( $cp_cty_err['operator_country'] ), count( Settings::countries() ), $cp_fr, EInvoice::by_mail( Vat::STANDARD, array( 'country' => 'FR' ) ) )
);

// First setup (P3-03): country from WooCommerce, VAT rate not 19 outside Germany, the profile is only proposed.
if ( ! class_exists( 'CP_Countries' ) ) {
	class CP_Countries { public $base = 'AT'; public function get_countries() { return array(); } public function get_base_country() { return $this->base; } }
}
$cp_wc            = $GLOBALS['g1_wc'] ?? null;
$cp_c             = new CP_Countries();
$GLOBALS['g1_wc'] = (object) array( 'countries' => $cp_c );
$cp_init_at       = Settings::initial();
$cp_prop_at       = CountryProfiles::proposed();
$cp_c->base       = 'DE';
$cp_init_de       = Settings::initial();
$cp_c->base       = 'US';
$cp_init_us       = Settings::initial();
$cp_prop_us       = CountryProfiles::proposed();
$GLOBALS['g1_wc'] = $cp_wc;
$cp_vat_empty     = Settings::sanitize( array( 'vat_rate' => '' ), $cp_vat_err );
check(
	'SET-AC-03 first setup: country from WooCommerce, no VAT rate outside Germany, profile proposed (not applied); empty rate is accepted',
	array( 'AT', '', 'AT', '7', 'DE', '19', 'US', '', 'OTHER', 0, '', false ),
	array( $cp_init_at['operator_country'], $cp_init_at['vat_rate'], $cp_prop_at, (string) Retention::minimum_years( 'AT', 0 ), $cp_init_de['operator_country'], $cp_init_de['vat_rate'], $cp_init_us['operator_country'], $cp_init_us['vat_rate'], $cp_prop_us, (int) $cp_init_at['statement_retention_minimum'], $cp_vat_empty['vat_rate'], isset( $cp_vat_err['vat_rate'] ) )
);

// Wiring: settings page actions (preview, then apply with password confirmation), CLI command, Site Health, audit label.
$cp_src = static function ( $file ) { return (string) file_get_contents( WPAC_DIR . $file ); };
check(
	'SET-AC-03 wiring: admin actions, password confirmation for applying, WP-CLI "profile", Site Health test, audit label',
	array( true, true, true, true, true, true ),
	array(
		false !== strpos( $cp_src( 'src/Admin/Admin.php' ), "'settings_profile_apply'" ),
		in_array( 'settings_profile_apply', \WPAffiliateCaptain\Admin\Reauth::ACTIONS, true ),
		method_exists( '\WPAffiliateCaptain\Cli\SettingsCommand', 'profile' ),
		false !== strpos( $cp_src( 'src/Plugin.php' ), 'CountryProfiles::class' ),
		false !== strpos( $cp_src( 'src/Admin/Pages/AuditPage.php' ), "'settings.profile'" ),
		false !== strpos( $cp_src( 'src/Admin/Pages/SettingsPage.php' ), 'render_profiles()' ),
	)
);

update_option( 'wpac_settings', $cp_saved );
delete_option( CountryProfiles::OPTION );
Settings::flush_cache();
SettingsSchema::flush();
