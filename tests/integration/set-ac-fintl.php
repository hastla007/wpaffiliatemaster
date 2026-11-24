<?php
/**
 * Final review fix round (24.11.2026), agent fintl: P3-08 Austrian/Swiss German translations,
 * P3-09 Liechtenstein keeps its operator country, P3-10 Form Captain hand-overs and the extra
 * consents, P3-11 document notes per language, P6-09 length limit of per-language texts.
 * Included from run.php right before r8-render.php (uses the locale stubs of r8-g10.php).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Affiliates\Agreements;
use WPAffiliateCaptain\Affiliates\Registration;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Core\CountryProfiles;
use WPAffiliateCaptain\Core\Locale;
use WPAffiliateCaptain\Core\Pending;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Core\SettingsSchema;
use WPAffiliateCaptain\Integrations\FormCaptain\Handover;
use WPAffiliateCaptain\Payouts\Corrections;
use WPAffiliateCaptain\Payouts\PayoutService;
use WPAffiliateCaptain\Payouts\ProfileRepository;
use WPAffiliateCaptain\Payouts\Statement;
use WPAffiliateCaptain\Payouts\Vat;

echo "R24 fintl: translations AT/CH, Liechtenstein, Form Captain consents, notes per language\n";

global $wpdb;
$fi_saved  = get_option( 'wpac_settings' );
$fi_locale = $GLOBALS['g10_locale'];
$fi_set    = static function ( array $values ) {
	update_option( 'wpac_settings', array_merge( (array) get_option( 'wpac_settings' ), $values ) );
	Settings::flush_cache();
};
SettingsSchema::flush();
$GLOBALS['g10_locale'] = 'de_DE';

/* ---------- P3-08: de_AT, de_CH, de_CH_informal use the German translations ---------- */
$fi_lang = WPAC_DIR . 'languages/';
check(
	'P3-08 fallbacks: de_AT/de_CH formal German (Sie), de_CH_informal German (du), others none',
	array( 'de_DE_formal', 'de_DE_formal', 'de_DE', '', '' ),
	array( Locale::fallback( 'de_AT' ), Locale::fallback( 'de_CH' ), Locale::fallback( 'de_CH_informal' ), Locale::fallback( 'de_DE' ), Locale::fallback( 'fr_FR' ) )
);
check(
	'P3-08 load_textdomain_mofile: missing de_AT / de_CH_informal file → German file of the plugin; own file and other domains untouched',
	array( $fi_lang . 'affiliate-captain-de_DE_formal.mo', $fi_lang . 'affiliate-captain-de_DE.mo', $fi_lang . 'affiliate-captain-de_DE.mo', '/x/other-de_AT.mo', $fi_lang . 'affiliate-captain-fr_FR.mo' ),
	array(
		Locale::mofile( WP_LANG_DIR . '/plugins/affiliate-captain-de_AT.mo', 'affiliate-captain' ),
		Locale::mofile( $fi_lang . 'affiliate-captain-de_CH_informal.mo', 'affiliate-captain' ),
		Locale::mofile( $fi_lang . 'affiliate-captain-de_DE.mo', 'affiliate-captain' ),
		Locale::mofile( '/x/other-de_AT.mo', 'other' ),
		Locale::mofile( $fi_lang . 'affiliate-captain-fr_FR.mo', 'affiliate-captain' ),
	)
);
$fi_json = glob( $fi_lang . 'affiliate-captain-de_DE_formal-*.json' );
$fi_md5  = $fi_json ? substr( basename( $fi_json[0] ), strlen( 'affiliate-captain-de_DE_formal' ) ) : '-x.json';
check(
	'P3-08 load_script_translation_file: block editor JSON of de_CH falls back to de_DE_formal',
	array( true, $fi_json ? $fi_json[0] : '', false ),
	array( (bool) $fi_json, Locale::script_file( WP_LANG_DIR . '/plugins/affiliate-captain-de_CH' . $fi_md5, 'wpac-blocks', 'affiliate-captain' ), Locale::script_file( false, 'wpac-blocks', 'affiliate-captain' ) )
);
check(
	'P3-08 Locale::reload() files: de_AT loads the formal German .mo, de_DE its own, fr_FR none',
	array( array( $fi_lang . 'affiliate-captain-de_DE_formal.mo' ), array( $fi_lang . 'affiliate-captain-de_DE.mo' ), array() ),
	array( Locale::mo_files( 'de_AT' ), Locale::mo_files( 'de_DE' ), Locale::mo_files( 'fr_FR' ) )
);
$fi_src = (string) file_get_contents( WPAC_DIR . 'src/Plugin.php' );
check( 'P3-08 the filters are registered at boot', array( true, true ), array( false !== strpos( $fi_src, "'load_textdomain_mofile', array( \\WPAffiliateCaptain\\Core\\Locale::class, 'mofile' )" ), false !== strpos( $fi_src, "'load_script_translation_file', array( \\WPAffiliateCaptain\\Core\\Locale::class, 'script_file' )" ) ) );
// The formal German file really is the "Sie" translation.
$fi_po = (string) file_get_contents( $fi_lang . 'affiliate-captain-de_DE_formal.po' );
check( 'P3-08 de_DE_formal is the formal translation (Sie)', array( true, false ), array( false !== strpos( $fi_po, 'Zahlen Sie Ihre Partner' ), false !== strpos( $fi_po, 'Zahle deine Partner' ) ) );

/* ---------- P3-09: Liechtenstein keeps its operator country with the CH profile ---------- */
$fi_set( array( 'operator_country' => 'LI', 'currency' => 'EUR', 'vat_rate' => '19' ) );
$fi_prev = CountryProfiles::preview( 'CH' );
check( 'P3-09 LI: CH profile proposed, the preview leaves the operator country alone (currency changes)', array( 'CH', false, true ), array( CountryProfiles::for_country( 'LI' ), isset( $fi_prev['changes']['operator_country'] ), isset( $fi_prev['changes']['currency'] ) ) );
CountryProfiles::apply( 'CH', $fi_prev, 'cli' );
Settings::flush_cache();
check( 'P3-09 after applying CH the operator country is still LI', array( 'LI', 'CHF' ), array( Settings::get( 'operator_country' ), Settings::get( 'currency' ) ) );
$fi_set( array( 'operator_country' => 'DE' ) );
$fi_prev = CountryProfiles::preview( 'CH' );
check( 'P3-09 an operator outside the list still gets the country of the profile (DE → CH)', array( 'DE', 'CH' ), $fi_prev['changes']['operator_country'] ?? array() );
$fi_set( array( 'operator_country' => 'DE', 'currency' => 'EUR', 'vat_rate' => '19' ) );

/* ---------- P3-10: Form Captain hand-overs and the extra consents ---------- */
$fi_keep_user         = $GLOBALS['test_user'];
$GLOBALS['test_user'] = 0;
$fi_set( array( 'registration_page_id' => 4242, 'terms_page_id' => 0, 'terms_version' => '', 'maintenance_mode' => 0, 'min_partner_age' => 0, 'consent_extra_1' => '', 'consent_extra_2' => '', 'consent_extra_3' => '' ) );
$fi_job = static function ( $entry, $email, $test = false, $version = '' ) {
	return array(
		'form_id'   => 78,
		'entry_id'  => $entry,
		'settings'  => array( 'group_id' => '0', 'terms_version' => $version ),
		'values'    => array( 'name' => 'Fio Intl', 'email' => $email, 'terms' => '1' ),
		'test_mode' => $test,
	);
};
$fi_set( array( 'min_partner_age' => 18 ) );
$fi_a = Handover::handle( $fi_job( 801, 'fi-age@example.de' ) );
$fi_t = Handover::handle( $fi_job( 802, 'fi-age@example.de', true ) );
$fi_set( array( 'min_partner_age' => 0, 'consent_extra_1' => 'Ich akzeptiere die Datenschutzhinweise.' ) );
$fi_b = Handover::handle( $fi_job( 803, 'fi-extra@example.de' ) );
check(
	'P3-10 minimum age or an extra required box: fail consents_unsupported (also in test mode), nothing stored',
	array( 'fail', 'consents_unsupported', 'consents_unsupported', 'fail', 'consents_unsupported', null, null ),
	array( $fi_a['status'], $fi_a['code'], $fi_t['code'], $fi_b['status'], $fi_b['code'], Handover::record( Handover::ref( 78, 801 ) ), Handover::record( Handover::ref( 78, 803 ) ) )
);
$fi_set( array( 'consent_extra_1' => '', 'terms_page_id' => 900, 'terms_version' => '2026-11' ) );
$fi_c   = Handover::handle( $fi_job( 804, 'fi-ok@example.de', false, '2026-11' ) );
$fi_rec = Handover::record( Handover::ref( 78, 804 ) );
$fi_app = $fi_rec ? Pending::get( 'app_' . $fi_rec['token_hash'] ) : null;
$fi_fin = new ReflectionMethod( Registration::class, 'finalize' );
$fi_fin->setAccessible( true );
$fi_id   = is_array( $fi_app ) ? $fi_fin->invoke( null, $fi_app ) : 0;
$fi_rows = is_int( $fi_id ) ? $wpdb->get_results( $wpdb->prepare( 'SELECT version, doc_hash, result FROM ' . t( 'agreements' ) . ' WHERE affiliate_id = %d ORDER BY id', $fi_id ) ) : array();
$fi_cons = array_values( array_filter( $fi_rows, static function ( $r ) { return 'consent:terms' === $r->version; } ) );
$fi_text = $fi_cons ? Agreements::consent_text( $fi_cons[0]->doc_hash ) : '';
check(
	'P3-10 without extra boxes: confirm_sent; after the confirmation the terms acceptance and the terms consent (with form and entry) are stored',
	array( 'confirm_sent', true, 1, true, true ),
	array( $fi_c['code'], is_int( $fi_id ), count( $fi_cons ), false !== strpos( $fi_text, 'Form Captain form 78, entry 804' ), false !== strpos( $fi_text, '2026-11' ) )
);
$GLOBALS['test_user'] = $fi_keep_user;
$fi_set( array( 'terms_page_id' => 0, 'terms_version' => '1.0' ) );

/* ---------- P6-09: length limit of per-language texts ---------- */
$fi_err = array();
$fi_out = Settings::sanitize( array( 'promotion_guidelines_i18n' => array( 'en_US' => str_repeat( 'a', Settings::I18N_MAX + 1 ) ), 'default_disclosure_i18n' => array( 'en_US' => str_repeat( 'ä', Settings::I18N_MAX ) ) ), $fi_err );
check(
	'P6-09 a text over 20,000 characters is rejected at its field (stored value kept); 20,000 multibyte characters are fine',
	array( Settings::I18N_MAX, true, true, (string) Settings::get( 'promotion_guidelines_i18n' ), false ),
	array( 20000, isset( $fi_err['promotion_guidelines_i18n'] ), false !== strpos( (string) ( $fi_err['promotion_guidelines_i18n'] ?? '' ), 'en_US' ), $fi_out['promotion_guidelines_i18n'], isset( $fi_err['default_disclosure_i18n'] ) )
);
$fi_err = array();
$fi_out = Settings::sanitize( array( 'note_reverse_charge_i18n' => array( 'en_US' => str_repeat( 'n', Settings::NOTE_MAX + 1 ) ), 'note_self_billing_i18n' => array( 'en_US' => "Line 1\nLine 2 <b>x</b>" ) ), $fi_err );
check( 'P6-09 notes per language: at most 1,000 characters like the site-language note; line breaks kept, tags removed', array( true, '{"en_US":"Line 1\nLine 2 x"}' ), array( isset( $fi_err['note_reverse_charge_i18n'] ), json_encode( json_decode( $fi_out['note_self_billing_i18n'], true ), JSON_UNESCAPED_UNICODE ) ) );

/* ---------- P3-11: document notes per language ---------- */
check(
	'P3-11 register: note_*_i18n of type i18n with base and legal mark',
	array_fill( 0, 4, array( 'i18n', true, 'payouts' ) ),
	array_map(
		static function ( $k ) {
			$f = SettingsSchema::field( $k . '_i18n' );
			return array( $f['type'] ?? '', ( $f['base'] ?? '' ) === $k && ! empty( $f['legal'] ), $f['tab'] ?? '' );
		},
		array( 'note_self_billing', 'note_reverse_charge', 'note_small_business', 'note_private_person' )
	)
);
$fi_set(
	array(
		'operator_country'         => 'DE',
		'self_billing'             => 1,
		'terms_page_id'            => 900,
		'min_payout_minor'         => 5000,
		'vies_check'               => 0,
		'note_self_billing'        => 'DE-GUTSCHRIFT-HINWEIS',
		'note_private_person'      => 'DE-PRIVATPERSON-HINWEIS',
		'note_reverse_charge'      => 'DE-REVERSE-HINWEIS',
		'note_self_billing_i18n'   => '{"en_US":"EN-SELF-BILLING-NOTE"}',
		'note_private_person_i18n' => '',
	)
);
$fi_en = AffiliateRepository::create( array( 'name' => 'Ella Intl', 'email' => 'ella-fintl@example.com', 'status' => 'active' ) );
$fi_de = AffiliateRepository::create( array( 'name' => 'Dieter Intl', 'email' => 'dieter-fintl@example.de', 'status' => 'active' ) );
Locale::set_partner( $fi_en, 'en_US' );
$fi_snap_en = PayoutService::operator_snapshot( $fi_en, true );
$fi_snap_de = PayoutService::operator_snapshot( $fi_de, true );
check(
	'P3-11 snapshot en_US: the English note; no German own text (built-in English text applies instead)',
	array( 'en_US', array( 'self_billing' => 'EN-SELF-BILLING-NOTE' ) ),
	array( $fi_snap_en['locale'], $fi_snap_en['notes'] ?? array() )
);
check(
	'P3-11 snapshot in the site language: the own German texts as before',
	array( 'de_DE', array( 'self_billing' => 'DE-GUTSCHRIFT-HINWEIS', Vat::REVERSE_CHARGE => 'DE-REVERSE-HINWEIS', Vat::PRIVATE_PERSON => 'DE-PRIVATPERSON-HINWEIS' ) ),
	array( $fi_snap_de['locale'], $fi_snap_de['notes'] ?? array() )
);
check(
	'P3-11 Settings::localized(): site text as fallback by default, none for notes (fallback false)',
	array( 'DE-REVERSE-HINWEIS', '', 'DE-REVERSE-HINWEIS', 'EN-SELF-BILLING-NOTE' ),
	array( Settings::localized( 'note_reverse_charge', 'en_US' ), Settings::localized( 'note_reverse_charge', 'en_US', false ), Settings::localized( 'note_reverse_charge', 'de_DE', false ), Settings::localized( 'note_self_billing', 'en_US', false ) )
);

// Credit note, cancellation and correction credit note of the English partner.
ProfileRepository::save( $fi_en, array( 'tax_status' => 'private', 'legal_name' => 'Ella Intl', 'address' => "2 High Street\nLondon", 'country' => 'GB', 'account_holder' => 'Ella Intl', 'iban' => 'DE89370400440532013000' ) );
Agreements::record( $fi_en, 'accepted' );
$fi_code  = WPAffiliateCaptain\Codes\CodeRepository::main_code( $fi_en );
$fi_sale  = sale( 'fintl-1', $fi_code, array( 100000 ) );
Ledger::transition( (int) $fi_sale['commission_ids'][0], 'approved' );
$fi_notes = array();
$fi_hook  = static function ( $note ) use ( &$fi_notes ) {
	$fi_notes[] = $note;
	return $note;
};
$fi_p1 = PayoutService::create_for_affiliate( $fi_en, gmdate( 'Y-m-d' ), 'EUR' );
$fi_po = $fi_p1 ? PayoutService::find( $fi_p1 ) : null;
$fi_op = $fi_po ? json_decode( (string) $fi_po->operator_snapshot, true ) : array();
check( 'P3-11 credit note: frozen in English with the English own note only', array( true, 'en_US', array( 'self_billing' => 'EN-SELF-BILLING-NOTE' ) ), array( $fi_p1 > 0, $fi_op['locale'] ?? '', $fi_op['notes'] ?? array() ) );
add_filter( 'wpac_statement_note', $fi_hook );
if ( $fi_po ) {
	Statement::build( $fi_po );
}
PayoutService::cancel( (int) $fi_p1, 'fintl test' );
$fi_po = $fi_p1 ? PayoutService::find( $fi_p1 ) : null;
if ( $fi_po && '' !== (string) $fi_po->storno_number ) {
	Statement::build( $fi_po, true );
}
remove_filter( 'wpac_statement_note', $fi_hook );
$fi_has = static function ( $list, $needle ) {
	return array_map( static function ( $n ) use ( $needle ) { return false !== strpos( (string) $n, $needle ); }, $list );
};
check(
	'P3-11 credit note (English own note) and cancellation (built when cancelled and again): no German own text, built-in private-person note in English',
	array( true, true, array(), array() ),
	array( count( $fi_notes ) >= 2, $fi_has( $fi_notes, 'EN-SELF-BILLING-NOTE' )[0] ?? false, array_filter( $fi_has( $fi_notes, 'DE-' ) ), array_filter( $fi_has( $fi_notes, Statement::vat_note( Vat::PRIVATE_PERSON, 'DE' ) ), static function ( $v ) { return ! $v; } ) )
);
// Correction credit note: a new payout of the same partner, paid, then corrected.
$fi_p2 = PayoutService::create_for_affiliate( $fi_en, gmdate( 'Y-m-d' ), 'EUR' );
PayoutService::mark_paid( (int) $fi_p2 );
$fi_po2 = PayoutService::find( (int) $fi_p2 );
$fi_set( array( 'note_private_person_i18n' => '{"en_US":"EN-PRIVATE-NOTE"}' ) );
$fi_kg  = Corrections::create( (int) $fi_p2, (int) $fi_po2->net_minor + 1000, 'fintl correction', 'fintl-kg-1', (int) $fi_po2->net_minor );
$fi_k   = is_wp_error( $fi_kg ) ? null : Corrections::find( $fi_kg );
$fi_kop = $fi_k ? json_decode( (string) $fi_k->operator_snapshot, true ) : array();
check(
	'P3-11 correction credit note: notes frozen at its issue in its language (texts stored for en_US then)',
	array( true, 'en_US', array( 'self_billing' => 'EN-SELF-BILLING-NOTE', Vat::PRIVATE_PERSON => 'EN-PRIVATE-NOTE' ) ),
	array( null !== $fi_k, $fi_kop['locale'] ?? '', $fi_kop['notes'] ?? ( is_wp_error( $fi_kg ) ? $fi_kg->get_error_code() : array() ) )
);

/* ---------- P3-11: the OTHER profile writes no texts; neutral built-in texts outside the EU ---------- */
$fi_other = CountryProfiles::get( 'OTHER' );
check(
	'P3-11 OTHER profile: note settings empty (built-in texts per language)',
	array( '', '', '', '' ),
	array( $fi_other['values']['note_self_billing'], $fi_other['values']['note_reverse_charge'], $fi_other['values']['note_small_business'], $fi_other['values']['note_private_person'] )
);
check(
	'P3-11 built-in notes outside the EU without EU or German law; EU, DE, CH unchanged',
	array( false, false, false, true, true, true, true ),
	array(
		false !== strpos( Statement::vat_note( Vat::REVERSE_CHARGE, 'US' ), 'Directive' ),
		false !== strpos( Statement::vat_note( Vat::SMALL_BUSINESS, 'LI' ), 'MWSTG' ) || false !== strpos( Statement::vat_note( Vat::SMALL_BUSINESS, 'LI' ), 'small enterprises' ),
		false !== strpos( Statement::self_billing_note( 'GB' ), 'Art. 224' ),
		false !== strpos( Statement::vat_note( Vat::REVERSE_CHARGE, 'FR' ), 'Art. 196' ),
		false !== strpos( Statement::vat_note( Vat::REVERSE_CHARGE, 'DE' ), '§13b' ),
		false !== strpos( Statement::vat_note( Vat::REVERSE_CHARGE, 'CH' ), 'MWSTG' ),
		false !== strpos( Statement::self_billing_note( 'AT' ), 'Art. 224' ),
	)
);

update_option( 'wpac_settings', $fi_saved );
Settings::flush_cache();
$GLOBALS['g10_locale'] = $fi_locale;
