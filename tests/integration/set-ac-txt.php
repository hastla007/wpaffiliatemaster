<?php
/**
 * SET-AC-17 (texts per language, document language per partner) and SET-AC-18 (application form:
 * wording of the terms checkbox, additional required checkboxes, imprint, minimum age; each consent
 * stored with wording and time and included in the personal data export).
 * Included from run.php before r8-render.php (uses the locale stubs of r8-g10.php).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Affiliates\Agreements;
use WPAffiliateCaptain\Affiliates\Notifications;
use WPAffiliateCaptain\Affiliates\Registration;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Core\Locale;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Core\SettingsSchema;
use WPAffiliateCaptain\Core\SettingsTransfer;
use WPAffiliateCaptain\Payouts\PayoutService;
use WPAffiliateCaptain\Payouts\ProfileRepository;
use WPAffiliateCaptain\Payouts\Statement;

echo "SET-AC-17/18 texts per language, application consents\n";

$txt_saved     = get_option( 'wpac_settings' );
$txt_templates = get_option( Notifications::TEMPLATES_OPTION );
$txt_locale    = $GLOBALS['g10_locale'];
$txt_set       = static function ( array $values ) {
	update_option( 'wpac_settings', array_merge( (array) get_option( 'wpac_settings' ), $values ) );
	Settings::flush_cache();
};
SettingsSchema::flush();
// German site; the request runs in German too (the admin who triggers the mails).
$GLOBALS['g10_locale'] = 'de_DE';

/* ---------- SET-AC-17: languages, partner language ---------- */
$txt_langs = Locale::installed();
check( 'SET-AC-17 installed languages: site language first, English always offered', array( 'de_DE', true ), array( (string) key( $txt_langs ), isset( $txt_langs['en_US'] ) ) );

$txt_en = AffiliateRepository::create( array( 'name' => 'Emma English', 'email' => 'emma-txt@example.com', 'status' => 'active' ) );
$txt_de = AffiliateRepository::create( array( 'name' => 'Dora Deutsch', 'email' => 'dora-txt@example.de', 'status' => 'active' ) );
check( 'SET-AC-17 partner language: default site language (nothing stored), unknown language rejected', array( '', false, '' ), array( Locale::partner( $txt_en ), Locale::set_partner( $txt_en, 'xx_YY' ), Locale::partner( $txt_en ) ) );
check( 'SET-AC-17 partner language en_US stored; site language means "not stored"', array( true, 'en_US', true, '' ), array( Locale::set_partner( $txt_en, 'en_US' ), Locale::partner( $txt_en ), Locale::set_partner( $txt_de, 'de_DE' ), Locale::partner( $txt_de ) ) );
check( 'SET-AC-17 mails follow the partner language', array( 'en_US', 'de_DE' ), array( Locale::for_affiliate( AffiliateRepository::find( $txt_en ) ), Locale::for_affiliate( AffiliateRepository::find( $txt_de ) ) ) );
check( 'SET-AC-17 document language: partner language, else site language; non-Latin still falls back to English', array( 'en_US', 'de_DE', 'de_DE' ), array( Statement::document_locale( 'de_DE', $txt_en ), Statement::document_locale( 'de_DE', $txt_de ), Statement::document_locale( 'de_DE' ) ) );
check( 'SET-AC-17 operator snapshot of a credit note carries the partner language', array( 'en_US', 'de_DE', 'de_DE' ), array( PayoutService::operator_snapshot( $txt_en )['locale'], PayoutService::operator_snapshot( $txt_de )['locale'], PayoutService::operator_snapshot()['locale'] ) );

/* ---------- SET-AC-17: e-mail templates per language ---------- */
update_option( Notifications::TEMPLATES_OPTION, array() );
check( 'SET-AC-17 base template saved (site language)', true, Notifications::save_template( 'application_approved', 'Willkommen {name}', 'Hallo {name}, willkommen.' ) );
check( 'SET-AC-17 template for an uninstalled language rejected', true, is_wp_error( Notifications::save_template( 'application_approved', 'Bienvenue', 'Bonjour', 'fr_FR' ) ) );
check( 'SET-AC-17 English template saved separately', true, Notifications::save_template( 'application_approved', 'Welcome {name}', 'Hello {name}, welcome.', 'en_US' ) );
check( 'SET-AC-17 stored per language, the site language is the base', array( array( 'Willkommen {name}', 'Hallo {name}, willkommen.' ), array( 'Welcome {name}', 'Hello {name}, welcome.' ), array( 'Willkommen {name}', 'Hallo {name}, willkommen.' ) ), array( Notifications::stored_template( 'application_approved', '' ), Notifications::stored_template( 'application_approved', 'en_US' ), Notifications::stored_template( 'application_approved', 'de_DE' ) ) );
check( 'SET-AC-17 fallback: a language without own text gets the text of the site language', array( 'Willkommen {name}', null ), array( Notifications::custom_template( 'application_approved', 'nl_NL' )[0], Notifications::stored_template( 'application_approved', 'nl_NL' ) ) );

$txt_mails = array();
$txt_cap   = static function ( $mail, $type, $context ) use ( &$txt_mails ) {
	$txt_mails[] = array( (int) $context['affiliate_id'], (string) $type, (string) $mail['subject'], Locale::current() );
	return $mail;
};
add_filter( 'wpac_mail', $txt_cap, 10, 3 );
Notifications::application_approved( $txt_en );
Notifications::application_approved( $txt_de );
remove_filter( 'wpac_mail', $txt_cap, 10 );
check(
	'SET-AC-17 done: partner en_US on a German site gets the English mail, the German partner the German one, language restored',
	array( array( $txt_en, 'application_approved', 'Welcome Emma English', 'en_US' ), array( $txt_de, 'application_approved', 'Willkommen Dora Deutsch', 'de_DE' ), 'de_DE' ),
	array( $txt_mails[0] ?? null, $txt_mails[1] ?? null, $GLOBALS['g10_locale'] )
);
Notifications::reset_template( 'application_approved', 'en_US' );
check( 'SET-AC-17 reset of a language: back to the text of the site language, base untouched', array( null, true ), array( Notifications::stored_template( 'application_approved', 'en_US' ), null !== Notifications::stored_template( 'application_approved', '' ) ) );
update_option( Notifications::TEMPLATES_OPTION, array() );

/* ---------- SET-AC-17: English credit note end to end ---------- */
$txt_set( array( 'terms_page_id' => 900, 'maintenance_mode' => 0, 'min_payout_minor' => 5000, 'vies_check' => 0, 'self_billing' => 1, 'notify_payout_paid' => 1 ) );
ProfileRepository::save( $txt_en, array( 'tax_status' => 'private', 'legal_name' => 'Emma English', 'address' => "1 High Street\nLondon", 'country' => 'GB', 'account_holder' => 'Emma English', 'iban' => 'DE89370400440532013000' ) );
Agreements::record( $txt_en, 'accepted' );
$txt_sale = sale( 'txt-1', WPAffiliateCaptain\Codes\CodeRepository::main_code( $txt_en ), array( 100000 ) );
Ledger::transition( (int) $txt_sale['commission_ids'][0], 'approved' );
$txt_watch    = static function ( $mail, $type, $context ) use ( &$txt_mails ) {
	$txt_mails[] = array( (int) $context['affiliate_id'], (string) $type, (string) $mail['subject'], Locale::current() );
	return $mail;
};
$txt_mails = array();
add_filter( 'wpac_mail', $txt_watch, 10, 3 );
$txt_pid = PayoutService::create_for_affiliate( $txt_en, gmdate( 'Y-m-d' ), 'EUR' );
remove_filter( 'wpac_mail', $txt_watch, 10 );
$txt_p  = $txt_pid ? PayoutService::find( $txt_pid ) : null;
$txt_op = $txt_p ? json_decode( (string) $txt_p->operator_snapshot, true ) : array();
check( 'SET-AC-17 done: the credit note of the en_US partner is frozen in English', array( true, 'en_US' ), array( $txt_pid > 0, $txt_op['locale'] ?? null ) );
$GLOBALS['g10_stack'] = array();
$txt_seen             = array();
$txt_pdf_hook         = static function ( $doc ) use ( &$txt_seen ) {
	$txt_seen[] = Locale::current();
	return $doc;
};
if ( $txt_p ) {
	add_filter( 'wpac_statement_note', $txt_pdf_hook );
	Statement::build( $txt_p );
	remove_filter( 'wpac_statement_note', $txt_pdf_hook );
}
check( 'SET-AC-17 the document is built in English and the language restored afterwards', array( array( 'en_US' ), 'de_DE', array() ), array( array_values( array_unique( $txt_seen ) ), $GLOBALS['g10_locale'], $GLOBALS['g10_stack'] ) );
$txt_mails = array();
add_filter( 'wpac_mail', $txt_watch, 10, 3 );
Notifications::credit_note_issued( (int) $txt_pid );
remove_filter( 'wpac_mail', $txt_watch, 10 );
$txt_cn = array_values( array_filter( $txt_mails, static function ( $m ) use ( $txt_en ) { return $m[0] === $txt_en && 'credit_note' === $m[1]; } ) );
check( 'SET-AC-17 the credit note e-mail goes out in English', array( 1, 'en_US', 'de_DE' ), array( count( $txt_cn ), $txt_cn[0][3] ?? null, $GLOBALS['g10_locale'] ) );

/* ---------- SET-AC-17: disclosure and guidelines per language ---------- */
$txt_set( array( 'default_disclosure' => 'Werbung', 'promotion_guidelines' => 'Nur ehrliche Aussagen.' ) );
$txt_out = Settings::sanitize( array( 'default_disclosure_i18n' => array( 'en_US' => ' Advertisement ', 'de_DE' => 'ignoriert' ), 'promotion_guidelines_i18n' => array( 'en_US' => '' ) ), $txt_err, $txt_notes );
check( 'SET-AC-17 per-language texts: stored as JSON, the site language and empty texts dropped', array( '{"en_US":"Advertisement"}', '', array() ), array( $txt_out['default_disclosure_i18n'], $txt_out['promotion_guidelines_i18n'], $txt_err ) );
$txt_out = Settings::sanitize( array( 'default_disclosure_i18n' => '{"fr_FR":"Publicité"}' ), $txt_err, $txt_notes );
check( 'SET-AC-17 per-language texts: uninstalled languages rejected (value kept), JSON accepted as from an import', array( true, '' ), array( isset( $txt_err['default_disclosure_i18n'] ), $txt_out['default_disclosure_i18n'] ) );
$txt_set( array( 'default_disclosure_i18n' => '{"en_US":"Advertisement"}', 'promotion_guidelines_i18n' => '{"en_US":"Honest claims only."}' ) );
check( 'SET-AC-17 localized texts: English for en_US, site language otherwise', array( 'Advertisement', 'Werbung', 'Werbung', 'Honest claims only.', 'Nur ehrliche Aussagen.' ), array( Settings::localized( 'default_disclosure', 'en_US' ), Settings::localized( 'default_disclosure', 'de_DE' ), Settings::localized( 'default_disclosure', 'nl_NL' ), Settings::localized( 'promotion_guidelines', 'en_US' ), Settings::localized( 'promotion_guidelines' ) ) );
$txt_export = SettingsTransfer::export();
$txt_check  = SettingsTransfer::check( array( 'default_disclosure_i18n' => '{"en_US":"Ad"}' ), Settings::all() );
check( 'SET-AC-17 exported and importable; disclosure texts marked legal', array( '{"en_US":"Advertisement"}', array( 'default_disclosure_i18n' => '{"en_US":"Ad"}' ), true, false ), array( $txt_export['settings']['default_disclosure_i18n'] ?? null, $txt_check['values'], ! empty( SettingsSchema::field( 'default_disclosure_i18n' )['legal'] ), ! empty( SettingsSchema::field( 'promotion_guidelines_i18n' )['legal'] ) ) );
$txt_portal = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Portal/Portal.php' );
check( 'SET-AC-17 partner area shows disclosure and guidelines in the language of the page', array( true, true ), array( false !== strpos( $txt_portal, "Settings::localized( 'default_disclosure' )" ), false !== strpos( $txt_portal, "Settings::localized( 'promotion_guidelines' )" ) ) );

/* ---------- SET-AC-17: privacy ---------- */
$txt_items = WPAffiliateCaptain\Privacy\Privacy::export( 'emma-txt@example.com' )['data'];
$txt_lang  = '';
foreach ( $txt_items as $txt_item ) {
	foreach ( $txt_item['data'] as $txt_row ) {
		if ( 'Language of e-mails and credit notes' === $txt_row['name'] ) {
			$txt_lang = $txt_row['value'];
		}
	}
}
check( 'SET-AC-17 the partner language is part of the personal data export', 'en_US', $txt_lang );
Locale::forget_partner( $txt_en );
check( 'SET-AC-17 forgotten with the partner', '', Locale::partner( $txt_en ) );

/* ---------- SET-AC-18: settings ---------- */
$txt_out = Settings::sanitize( array( 'consent_terms_label' => 'Ich akzeptiere die Bedingungen.' ), $txt_err, $txt_notes );
check( 'SET-AC-18 terms wording without {terms} rejected', array( true, '' ), array( isset( $txt_err['consent_terms_label'] ), $txt_out['consent_terms_label'] ) );
$txt_out = Settings::sanitize(
	array(
		'consent_terms_label' => 'Ich habe die {terms} (Version {version}) gelesen.',
		'consent_extra_1'     => 'Ich halte die <a href="https://example.test/richtlinien">Werberichtlinien</a> ein.<script>x</script>',
		'consent_extra_2'     => '',
		'consent_extra_3'     => 'Ich bin mit dem Gutschriftverfahren einverstanden.',
		'imprint_page_id'     => '901',
		'min_partner_age'     => '18',
	),
	$txt_err,
	$txt_notes
);
check(
	'SET-AC-18 settings: wording with placeholders, links kept, scripts removed, imprint page, minimum age',
	array( 'Ich habe die {terms} (Version {version}) gelesen.', 'Ich halte die <a href="https://example.test/richtlinien">Werberichtlinien</a> ein.x', '', 901, 18, array() ),
	array( $txt_out['consent_terms_label'], $txt_out['consent_extra_1'], $txt_out['consent_extra_2'], $txt_out['imprint_page_id'], $txt_out['min_partner_age'], $txt_err )
);
$txt_out = Settings::sanitize( array( 'min_partner_age' => '150' ), $txt_err, $txt_notes );
check( 'SET-AC-18 minimum age at most 99', true, isset( $txt_err['min_partner_age'] ) );
check( 'SET-AC-18 neutral defaults: built-in terms wording, no extra boxes, no imprint, no age question', array( '', '', '', '', 0, 0 ), array_values( array_intersect_key( SettingsSchema::defaults(), array_flip( array( 'consent_terms_label', 'consent_extra_1', 'consent_extra_2', 'consent_extra_3', 'imprint_page_id', 'min_partner_age' ) ) ) ) );
$txt_legal = array();
foreach ( array( 'consent_terms_label', 'consent_extra_1', 'consent_extra_2', 'consent_extra_3', 'imprint_page_id', 'min_partner_age' ) as $txt_k ) {
	$txt_legal[] = ! empty( SettingsSchema::field( $txt_k )['legal'] ) && 'program' === SettingsSchema::field( $txt_k )['tab'];
}
check( 'SET-AC-18 all on the Program tab and marked legal', array( true, true, true, true, true, true ), $txt_legal );

/* ---------- SET-AC-18: form and consents ---------- */
$txt_set( array( 'terms_page_id' => 900, 'terms_version' => '2.0', 'consent_terms_label' => '', 'consent_extra_1' => '', 'consent_extra_2' => '', 'consent_extra_3' => '', 'min_partner_age' => 0, 'imprint_page_id' => 0 ) );
$txt_terms = Agreements::current( 0 );
check( 'SET-AC-18 built-in terms wording unchanged; no further boxes by default', array( 'I accept the partner program terms (http://example.test/?p=900) (version 2.0).', array() ), array( Registration::terms_label( $txt_terms, true ), Registration::consent_boxes() ) );
$txt_set( $txt_out = array( 'consent_terms_label' => 'Ich habe die {terms} (Version {version}) gelesen.', 'consent_extra_1' => 'Ich halte die <a href="https://example.test/richtlinien">Werberichtlinien</a> ein.', 'consent_extra_3' => 'Ich bin mit dem Gutschriftverfahren einverstanden.', 'min_partner_age' => 18, 'imprint_page_id' => 900 ) );
$txt_boxes = Registration::consent_boxes();
check( 'SET-AC-18 boxes: extra 1 and 3 (2 is empty) and the age confirmation', array( 'extra_1', 'extra_3', 'age' ), array_keys( $txt_boxes ) );
check( 'SET-AC-18 terms wording of the setting with link and version', array( true, 'Ich habe die partner program terms (http://example.test/?p=900) (Version 2.0) gelesen.' ), array( false !== strpos( Registration::terms_label( $txt_terms ), '<a href="http://example.test/?p=900"' ), Registration::terms_label( $txt_terms, true ) ) );
list( $txt_inv, $txt_cons ) = Registration::consents( array( 'extra_1' => true, 'extra_3' => false, 'age' => false ), $txt_terms );
check( 'SET-AC-18 every box is required (missing ones reported per field)', array( 'wpac_consent_extra_3', 'wpac_consent_age' ), array_keys( $txt_inv ) );
list( $txt_inv, $txt_cons ) = Registration::consents( array( 'extra_1' => true, 'extra_3' => true, 'age' => true ), $txt_terms );
check(
	'SET-AC-18 consents with wording (links as text with address) and time of the tick',
	array( array(), array( 'terms', 'extra_1', 'extra_3', 'age' ), 'Ich halte die Werberichtlinien (https://example.test/richtlinien) ein.', 'I confirm that I am at least 18 years old.', true ),
	array( $txt_inv, array_column( $txt_cons, 'key' ), $txt_cons[1]['text'], $txt_cons[3]['text'], (bool) preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $txt_cons[0]['accepted_at'] ) )
);

if ( ! function_exists( 'remove_query_arg' ) ) {
	function remove_query_arg( $keys, $url = '' ) { return (string) $url; }
}
$GLOBALS['test_the_id'] = 0;
$txt_inst = new ReflectionProperty( Registration::class, 'instances' );
$txt_inst->setAccessible( true );
$txt_inst->setValue( null, 0 );
$txt_get = $_GET;
$txt_pub = WPAffiliateCaptain\Affiliates\Groups::public_groups();
$_GET    = $txt_pub ? array( 'wpac_group' => (int) $txt_pub[0]->id ) : array(); // Public groups of earlier tests: open the form of one.
$txt_html = Registration::render( array( 'group' => 0 ) );
$_GET     = $txt_get;
check(
	'SET-AC-18 form: own terms wording, required boxes with their text, age confirmation, imprint link',
	array( true, true, true, true, true ),
	array(
		false !== strpos( $txt_html, 'Ich habe die <a href="http://example.test/?p=900"' ),
		false !== strpos( $txt_html, 'name="wpac_consent_extra_1" value="1" required' ),
		false !== strpos( $txt_html, 'Ich halte die <a href="https://example.test/richtlinien">Werberichtlinien</a> ein.' ),
		false !== strpos( $txt_html, 'name="wpac_consent_age" value="1" required' ) && false !== strpos( $txt_html, 'at least 18 years old' ),
		false !== strpos( $txt_html, '>Imprint<' ),
	)
);

// Stored like the terms: agreements rows with result "consent", wording once per hash.
$txt_app = AffiliateRepository::create( array( 'name' => 'Anna Antrag', 'email' => 'anna-txt@example.de', 'status' => 'pending' ) );
Agreements::record( $txt_app, 'accepted', $txt_terms + array( 'accepted_at' => $txt_cons[0]['accepted_at'] ) );
check( 'SET-AC-18 four consents recorded', 4, Agreements::record_consents( $txt_app, $txt_cons ) );
$txt_rows = Agreements::for_affiliate( $txt_app );
$txt_c    = array_values( array_filter( $txt_rows, static function ( $r ) { return Agreements::CONSENT === $r->result; } ) );
check(
	'SET-AC-18 stored with wording and time; the terms acceptance stays the latest acceptance',
	array( 4, 'consent:age', 'I confirm that I am at least 18 years old.', $txt_cons[3]['accepted_at'], true, true ),
	array( count( $txt_c ), $txt_c[0]->version, Agreements::consent_text( $txt_c[0]->doc_hash ), $txt_c[0]->created_at, 'accepted' === Agreements::latest( $txt_app )->result, Agreements::has_current( $txt_app ) )
);
$txt_items  = WPAffiliateCaptain\Privacy\Privacy::export( 'anna-txt@example.de' )['data'];
$txt_export = array();
foreach ( $txt_items as $txt_item ) {
	if ( 'wpac-consents' === $txt_item['group_id'] ) {
		$txt_export[] = $txt_item['data'][0]['value'] . ' | ' . $txt_item['data'][1]['value'];
	}
}
sort( $txt_export );
check(
	'SET-AC-18 done: the consents are part of the personal data export (wording, box, date)',
	array( 'I confirm that I am at least 18 years old. | age', 'Ich bin mit dem Gutschriftverfahren einverstanden. | extra_3', 'Ich habe die partner program terms (http://example.test/?p=900) (Version 2.0) gelesen. | terms', 'Ich halte die Werberichtlinien (https://example.test/richtlinien) ein. | extra_1' ),
	$txt_export
);
$txt_src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Admin/Tables/AffiliatesTable.php' );
check( 'SET-AC-18 partner list: consents do not hide the latest terms acceptance', true, false !== strpos( $txt_src, "AND result <> 'consent' GROUP BY affiliate_id" ) );
$txt_fin = new ReflectionMethod( Registration::class, 'finalize' );
$txt_fin->setAccessible( true );
$txt_new = $txt_fin->invoke( null, array( 'user_id' => 0, 'name' => 'Fin Final', 'email' => 'fin-txt@example.de', 'website' => '', 'channel' => '', 'application_note' => '', 'application_data' => array(), 'group_id' => 0, 'referred_by' => 0, 'terms' => $txt_terms + array( 'accepted_at' => '2026-11-23 10:00:00' ), 'consents' => $txt_cons, 'pending_only' => true ) );
check( 'SET-AC-18 confirmation of the application records terms and consents', array( true, 4 ), array( 0 < count( array_filter( Agreements::for_affiliate( (int) $txt_new ), static function ( $r ) { return 'accepted' === $r->result; } ) ), count( array_filter( Agreements::for_affiliate( (int) $txt_new ), static function ( $r ) { return Agreements::CONSENT === $r->result; } ) ) ) );

// Restore.
update_option( 'wpac_settings', $txt_saved );
update_option( Notifications::TEMPLATES_OPTION, $txt_templates );
Settings::flush_cache();
SettingsSchema::flush();
$GLOBALS['g10_locale'] = $txt_locale;
