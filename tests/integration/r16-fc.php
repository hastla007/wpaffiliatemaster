<?php
/**
 * AFF-SU-01 (owner decision 16.11.2026): Form Captain adapter for partner applications.
 * Checks the connector and its action "Partner application to Affiliate Captain" against a
 * stand-in of Form Captain's public contract v1 (ConnectorInterface, form_captain_loaded_v1,
 * form_captain_register_action_v1(), the deletion and consent events). The same flow against the
 * real Form Captain runs in tests/form-captain/ (two-plugin test, PHPUnit).
 * Included from run.php before r8-render.php (helpers check(), t() of run.php).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
namespace FormCaptain\Integrations {
	if ( ! interface_exists( ConnectorInterface::class ) ) {
		interface ConnectorInterface {
			public function key(): string;
			public function label(): string;
			public function version(): string;
			public function contract(): string;
			public function capabilities(): array;
			public function requirements(): array;
			public function settings_schema(): array;
			public function register_actions(): void;
		}
	}
}

namespace {
	use WPAffiliateCaptain\Affiliates\AffiliateRepository;
	use WPAffiliateCaptain\Affiliates\Groups;
	use WPAffiliateCaptain\Affiliates\Registration;
	use WPAffiliateCaptain\Codes\CodeRepository;
	use WPAffiliateCaptain\Core\Pending;
	use WPAffiliateCaptain\Core\Settings;
	use WPAffiliateCaptain\Integrations\FormCaptain\Connector;
	use WPAffiliateCaptain\Integrations\FormCaptain\FormCaptain;
	use WPAffiliateCaptain\Integrations\FormCaptain\Handover;

	echo "R16 FC: Form Captain hand-over of partner applications (AFF-SU-01)\n";
	global $wpdb;

	// Stand-in of form_captain_register_action_v1(): keeps the definition like Form Captain's registry.
	if ( ! function_exists( 'form_captain_register_action_v1' ) ) {
		function form_captain_register_action_v1( $key, array $definition, $callback ) {
			$GLOBALS['fc_actions'][ $key ] = array( 'definition' => $definition, 'callback' => $callback );
			return true;
		}
	}

	$fc_keep_settings = get_option( 'wpac_settings' );
	$fc_keep_user     = $GLOBALS['test_user'];
	$GLOBALS['test_user'] = 0;
	Settings::update( ( array( 'registration_page_id' => 0, 'terms_page_id' => 0, 'terms_version' => '', 'auto_approve' => 1, 'maintenance_mode' => 0 ) ) );
	Settings::flush_cache();
	$fc_mails = static function () { return array_map( static function ( $m ) { return $m[0]; }, $GLOBALS['test_mails'] ); };
	$fc_job   = static function ( $entry, array $values, array $settings = array(), $extra = array() ) {
		return array_merge(
			array(
				'form_id'         => 77,
				'entry_id'        => $entry,
				'idempotency_key' => 'form-captain:1:' . $entry . ':affiliate_captain.partner_application:a_0000000001',
				'action_key'      => 'affiliate_captain.partner_application',
				'instance_id'     => 'a_0000000001',
				'attempt'         => 1,
				'settings'        => $settings + array( 'group_id' => '0', 'terms_version' => '' ),
				'values'          => $values,
				'context'         => array(),
				'test_mode'       => false,
			),
			$extra
		);
	};
	$fc_values = static function ( $email, array $more = array() ) {
		return $more + array( 'name' => 'Paula Partner', 'email' => $email, 'website' => 'paula.example', 'channel' => 'Instagram', 'message' => 'Ich segle.', 'terms' => '1' );
	};
	$fc_affiliates = static function ( $email ) use ( $wpdb ) {
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . t( 'affiliates' ) . ' WHERE email = %s', $email ) );
	};
	$fc_finalize = new ReflectionMethod( Registration::class, 'finalize' );
	$fc_finalize->setAccessible( true );
	$fc_confirm = static function ( $form_id, $entry_id ) use ( $fc_finalize ) {
		$record = Handover::record( Handover::ref( $form_id, $entry_id ) );
		$data   = $record ? Pending::get( 'app_' . $record['token_hash'] ) : null;
		return is_array( $data ) ? $fc_finalize->invoke( null, $data ) : null;
	};

	// FC-1: registration only with Form Captain's contract major 1, after form_captain_loaded_v1.
	FormCaptain::boot( '2.0.0' );
	$fc_no_v2 = isset( $GLOBALS['test_hooks']['form_captain_connectors_v1'] );
	FormCaptain::boot( '' );
	$fc_no_fc = isset( $GLOBALS['test_hooks']['form_captain_connectors_v1'] );
	check( 'FC-1 nothing registered without Form Captain or with contract major 2', array( false, false, false, true, true ), array( $fc_no_v2, $fc_no_fc, FormCaptain::compatible( '2.0.0' ), FormCaptain::compatible( '1.1.0' ), FormCaptain::compatible( '1' ) ) );
	FormCaptain::register_hooks();
	do_action( 'form_captain_loaded_v1', '1.1.0' );
	do_action( 'form_captain_loaded_v1', '1.1.0' ); // A second call adds nothing twice.
	$fc_list = apply_filters( 'form_captain_connectors_v1', array( 'other' => 'kept' ) );
	check( 'FC-1 connector affiliate_captain added once, existing keys kept', array( true, 'kept', 1, 1 ), array( isset( $fc_list['affiliate_captain'] ) && $fc_list['affiliate_captain'] instanceof \FormCaptain\Integrations\ConnectorInterface, $fc_list['other'], count( $GLOBALS['test_hooks']['form_captain_connectors_v1'][10] ), count( $GLOBALS['test_hooks']['form_captain_entry_deleted_v1'][10] ) ) );
	$fc_conn = new Connector();
	check( 'FC-1 connector shape (key, contract ^1, capability)', array( 1, '1.0', array( 'affiliate_captain.partner_application' ), array() ), array( preg_match( '/^[a-z0-9_]+$/', $fc_conn->key() ), $fc_conn->contract(), $fc_conn->capabilities(), $fc_conn->settings_schema() ) );
	check( 'FC-1 requirements: a published registration page is needed', false, $fc_conn->requirements()['ok'] );
	Settings::update( ( array( 'registration_page_id' => 4242 ) ) );
	Settings::flush_cache();
	check( 'FC-1 requirements met with the registration page', true, $fc_conn->requirements()['ok'] );
	$fc_conn->register_actions();
	$fc_def = $GLOBALS['fc_actions']['affiliate_captain.partner_application']['definition'] ?? array();
	$fc_map = array();
	foreach ( $fc_def['mapping'] ?? array() as $fc_m ) {
		$fc_map[ $fc_m['key'] ] = array( ! empty( $fc_m['required'] ), $fc_m['types'], ! empty( $fc_m['allow_sensitive'] ) );
	}
	check(
		'FC-1 explicit mapping: name, e-mail, website, channel, message, terms consent (required), referrer; nothing sensitive',
		array( 'name' => array( true, array( 'text' ), false ), 'email' => array( true, array( 'email' ), false ), 'website' => array( false, array( 'text' ), false ), 'channel' => array( false, array( 'text', 'select', 'radio' ), false ), 'message' => array( false, array( 'textarea', 'text' ), false ), 'terms' => array( true, array( 'consent' ), false ), 'referrer' => array( false, array( 'text', 'hidden' ), false ) ),
		$fc_map
	);
	check( 'FC-1 action label, connector, contract and settings (group, terms version)', array( 'Partner application to Affiliate Captain', 'affiliate_captain', '^1.0', array( 'group_id', 'terms_version' ), Handover::FIELDS ), array( $fc_def['label'] ?? '', $fc_def['connector'] ?? '', $fc_def['contract'] ?? '', array_column( $fc_def['settings'] ?? array(), 'key' ), array_keys( $fc_map ) ) );

	// FC-2: test mode – configuration checked, no side effects.
	$GLOBALS['test_mails'] = array();
	$fc_test = Handover::handle( $fc_job( 0, $fc_values( 'test@example.com' ), array(), array( 'test_mode' => true, 'form_id' => 0 ) ) );
	check( 'FC-2 test mode: success test_ok, no mail, no record, no partner', array( 'success', 'test_ok', array(), 0 ), array( $fc_test['status'], $fc_test['code'], $GLOBALS['test_mails'], $fc_affiliates( 'test@example.com' ) ) );

	// FC-3: missing consent or invalid fields are final failures without side effects.
	$fc_r = Handover::handle( $fc_job( 501, $fc_values( 'noconsent@example.de', array( 'terms' => '0' ) ) ) );
	$fc_r2 = Handover::handle( $fc_job( 502, $fc_values( 'kaputt' ) ) );
	$fc_r3 = Handover::handle( $fc_job( 503, array( 'email' => 'noname@example.de', 'terms' => '1' ) ) );
	check( 'FC-3 missing consent -> fail terms_required; invalid e-mail / no name -> fail invalid_field', array( 'fail', 'terms_required', 'fail', 'invalid_field', 'fail', 'invalid_field' ), array( $fc_r['status'], $fc_r['code'], $fc_r2['status'], $fc_r2['code'], $fc_r3['status'], $fc_r3['code'] ) );
	check( 'FC-3 nothing stored, no mail', array( null, null, array() ), array( Handover::record( Handover::ref( 77, 501 ) ), Handover::record( Handover::ref( 77, 502 ) ), $GLOBALS['test_mails'] ) );

	// FC-4: a valid hand-over waits for the double opt-in; nothing is created yet.
	$fc_a = Handover::handle( $fc_job( 510, $fc_values( 'Paula@Example.de', array( 'iban' => 'DE89370400440532013000', 'phone' => '0171' ) ) ) );
	$fc_rec = Handover::record( Handover::ref( 77, 510 ) );
	$fc_app = $fc_rec ? Pending::get( 'app_' . $fc_rec['token_hash'] ) : null;
	check( 'FC-4 success confirm_sent with an opaque remote ID, confirmation mail to the applicant', array( 'success', 'confirm_sent', 1, array( 'Paula@Example.de' ) ), array( $fc_a['status'], $fc_a['code'], preg_match( '/^wpac-fc-[0-9a-f]{16}$/', $fc_a['remote_id'] ?? '' ), $fc_mails() ) );
	check( 'FC-4 no partner before the confirmation', 0, $fc_affiliates( 'paula@example.de' ) );
	check( 'FC-4 waiting application: pending_only, mapped fields only (no IBAN, no phone), confirmation on the registration page', array( true, 'Paula Partner', 'https://paula.example', 'Instagram', 'Ich segle.', array(), 0, false, 'http://example.test/?p=4242&wpac_review=1' ), is_array( $fc_app ) ? array( $fc_app['pending_only'], $fc_app['name'], $fc_app['website'], $fc_app['channel'], $fc_app['application_note'], $fc_app['application_data'], $fc_app['user_id'], false !== strpos( serialize( $fc_app ), 'DE89' ) || false !== strpos( serialize( $fc_app ), '0171' ), $fc_app['redirect'] ) : array() );
	check( 'FC-4 hand-over record holds no personal data', array( 'confirm_sent', false, false ), array( $fc_rec['state'] ?? '', false !== stripos( serialize( $fc_rec ), 'paula' ), false !== stripos( serialize( $fc_rec ), 'segle' ) ) );

	// FC-5: duplicate delivery (same job again, a retry, a second action instance) – same result, no second mail.
	$GLOBALS['test_mails'] = array();
	$fc_b = Handover::handle( $fc_job( 510, $fc_values( 'Paula@Example.de' ), array(), array( 'attempt' => 2 ) ) );
	$fc_c = Handover::handle( $fc_job( 510, $fc_values( 'Paula@Example.de' ), array(), array( 'instance_id' => 'a_0000000002', 'idempotency_key' => 'form-captain:1:510:affiliate_captain.partner_application:a_0000000002' ) ) );
	check( 'FC-5 idempotent: same status, code and remote ID, no further mail', array( 'success', 'confirm_sent', $fc_a['remote_id'], $fc_a['remote_id'], array() ), array( $fc_b['status'], $fc_b['code'], $fc_b['remote_id'], $fc_c['remote_id'], $GLOBALS['test_mails'] ) );

	// FC-6: the confirmation creates the partner – always pending (auto_approve is on), no code, no commission.
	$GLOBALS['test_mails'] = array();
	$fc_id   = $fc_confirm( 77, 510 );
	$fc_aff  = is_int( $fc_id ) ? AffiliateRepository::find( $fc_id ) : null;
	$fc_rec  = Handover::record( Handover::ref( 77, 510 ) );
	check( 'FC-6 confirmed: one partner, status pending despite automatic approval', array( true, 'pending', 1 ), array( is_int( $fc_id ), $fc_aff ? $fc_aff->status : '', $fc_affiliates( 'paula@example.de' ) ) );
	check( 'FC-6 no partner code, no commission, no approval', array( 0, 0, 0 ), array( (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'partner_codes' ) . ' WHERE affiliate_id = ' . (int) $fc_id ), (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'commissions' ) . ' WHERE affiliate_id = ' . (int) $fc_id ), (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'audit' ) . " WHERE action = 'affiliate.status' AND object_id = " . (int) $fc_id ) ) );
	check( 'FC-6 record knows the partner; repeated delivery still says confirm_sent', array( 'created', (int) $fc_id, 'confirm_sent', $fc_a['remote_id'] ), array( $fc_rec['state'] ?? '', (int) ( $fc_rec['affiliate_id'] ?? 0 ), Handover::handle( $fc_job( 510, $fc_values( 'Paula@Example.de' ) ) )['code'], Handover::handle( $fc_job( 510, $fc_values( 'Paula@Example.de' ) ) )['remote_id'] ) );
	check( 'FC-6 audit: hand-over and confirmation logged without e-mail address', array( true, false ), array( (bool) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'audit' ) . " WHERE action = 'form_captain.application_confirmed' AND object_id = " . (int) $fc_id ), (bool) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'audit' ) . " WHERE action LIKE 'form_captain.%' AND details LIKE '%paula%'" ) ) );

	// FC-7: an existing partner (any status) gets no second account; the result tells nothing about it.
	$GLOBALS['test_mails'] = array();
	$fc_d = Handover::handle( $fc_job( 520, $fc_values( 'paula@example.de', array( 'name' => 'Paula Zwei' ) ) ) );
	check( 'FC-7 duplicate e-mail: success already_exists, still one partner, no partner data in the result', array( 'success', 'already_exists', 1, false ), array( $fc_d['status'], $fc_d['code'], $fc_affiliates( 'paula@example.de' ), array( 'status', 'code', 'message', 'remote_id' ) !== array_keys( $fc_d ) || '' !== $fc_d['message'] || 0 !== strpos( $fc_d['remote_id'], 'wpac-fc-' ) ) );
	check( 'FC-7 the address gets the "already registered" notice of the built-in form, no confirmation link', array( array( 'paula@example.de' ), null ), array( $fc_mails(), Handover::record( Handover::ref( 77, 520 ) )['token_hash'] ? 'token' : null ) );

	// FC-8: Affiliate Captain temporarily unavailable -> retry; per-address mail limit -> retry.
	Settings::update( ( array( 'maintenance_mode' => 1 ) ) );
	Settings::flush_cache();
	$fc_e = Handover::handle( $fc_job( 530, $fc_values( 'later@example.de' ) ) );
	Settings::update( ( array( 'maintenance_mode' => 0 ) ) );
	Settings::flush_cache();
	check( 'FC-8 maintenance mode: retry affiliate_captain_unavailable, nothing stored', array( 'retry', 'affiliate_captain_unavailable', null ), array( $fc_e['status'], $fc_e['code'], Handover::record( Handover::ref( 77, 530 ) ) ) );
	Handover::handle( $fc_job( 531, $fc_values( 'flood@example.de' ) ) );
	Handover::handle( $fc_job( 532, $fc_values( 'flood@example.de' ) ) );
	$fc_f = Handover::handle( $fc_job( 533, $fc_values( 'flood@example.de' ) ) );
	check( 'FC-8 third confirmation mail to one address on one day: retry rate_limited', array( 'retry', 'rate_limited', null ), array( $fc_f['status'], $fc_f['code'], Handover::record( Handover::ref( 77, 533 ) ) ) );

	// FC-9: partner group from the action settings – only active public groups without required extra fields.
	$fc_g_pub  = Groups::create( 'FC Influencer' );
	Groups::update( $fc_g_pub, array( 'name' => 'FC Influencer', 'public' => 1 ) );
	$fc_g_priv = Groups::create( 'FC Privat' );
	$fc_g_req  = Groups::create( 'FC Pflichtfeld' );
	Groups::update( $fc_g_req, array( 'name' => 'FC Pflichtfeld', 'public' => 1, 'fields' => "reach|Reichweite|number|required" ) );
	$fc_h = Handover::handle( $fc_job( 540, $fc_values( 'gruppe@example.de' ), array( 'group_id' => (string) $fc_g_priv ) ) );
	$fc_i = Handover::handle( $fc_job( 541, $fc_values( 'gruppe@example.de' ), array( 'group_id' => (string) $fc_g_req ) ) );
	$fc_j = Handover::handle( $fc_job( 542, $fc_values( 'gruppe@example.de' ), array( 'group_id' => (string) $fc_g_pub ) ) );
	$fc_gid = $fc_confirm( 77, 542 );
	check( 'FC-9 private group -> group_invalid, required extra fields -> group_fields_unsupported, public group used', array( 'group_invalid', 'group_fields_unsupported', 'confirm_sent', $fc_g_pub ), array( $fc_h['code'], $fc_i['code'], $fc_j['code'], is_int( $fc_gid ) ? (int) AffiliateRepository::find( $fc_gid )->group_id : 0 ) );

	// FC-10: terms consent with the terms version of Affiliate Captain.
	Settings::update( ( array( 'terms_page_id' => 900, 'terms_version' => '2026-11' ) ) );
	Settings::flush_cache();
	$fc_k = Handover::handle( $fc_job( 550, $fc_values( 'agb@example.de' ), array( 'terms_version' => '2025-01' ) ) );
	$fc_l = Handover::handle( $fc_job( 551, $fc_values( 'agb@example.de' ), array( 'terms_version' => '2026-11' ) ) );
	$fc_tid = $fc_confirm( 77, 551 );
	$fc_agr = is_int( $fc_tid ) ? WPAffiliateCaptain\Affiliates\Agreements::latest( $fc_tid ) : null;
	check( 'FC-10 outdated terms version -> fail terms_version_mismatch; current version accepted and recorded', array( 'fail', 'terms_version_mismatch', 'confirm_sent', '2026-11' ), array( $fc_k['status'], $fc_k['code'], $fc_l['code'], $fc_agr ? (string) $fc_agr->version : '' ) );
	Settings::update( ( array( 'terms_page_id' => 0, 'terms_version' => '' ) ) );
	Settings::flush_cache();

	// FC-11: referrer code from the form – validated on the server, never the applicant, unknown codes ignored.
	$fc_rec_aff = AffiliateRepository::create( array( 'name' => 'FC Werber', 'email' => 'werber@example.de', 'status' => 'active' ) );
	CodeRepository::create( $fc_rec_aff, 'FCWERBER' );
	check( 'FC-11 valid code -> recruiter; unknown code, own address -> none', array( $fc_rec_aff, 0, 0 ), array( Handover::recruiter( 'FCWERBER', 'neu@example.de' ), Handover::recruiter( 'GIBTSNICHT', 'neu@example.de' ), Handover::recruiter( 'FCWERBER', 'werber@example.de' ) ) );
	Handover::handle( $fc_job( 560, $fc_values( 'geworben@example.de', array( 'referrer' => 'FCWERBER' ) ) ) );
	$fc_rid = $fc_confirm( 77, 560 );
	check( 'FC-11 recruiter stored on the pending partner', array( 'pending', $fc_rec_aff ), is_int( $fc_rid ) ? array( AffiliateRepository::find( $fc_rid )->status, (int) AffiliateRepository::find( $fc_rid )->referred_by ) : array() );

	// FC-12: deletion of the entry / withdrawal of consent reaches the application.
	Handover::handle( $fc_job( 570, $fc_values( 'unbestaetigt@example.de' ) ) );
	$fc_rec = Handover::record( Handover::ref( 77, 570 ) );
	do_action( 'form_captain_entry_deleted_v1', 570, 77, 'manual' );
	check( 'FC-12 unconfirmed application deleted with the entry (link expired, record gone)', array( null, null ), array( Pending::get( 'app_' . $fc_rec['token_hash'] ), Handover::record( Handover::ref( 77, 570 ) ) ) );
	do_action( 'form_captain_entry_deleted_v1', 510, 77, 'erasure' );
	AffiliateRepository::clear_cache( $fc_id );
	$fc_aff = AffiliateRepository::find( $fc_id );
	check( 'FC-12 pending partner from the entry anonymised by the privacy rules (erase_applicant)', array( 'rejected', 'privacy_erasure', 'deleted-' . $fc_id . '@invalid.invalid', 0, null ), array( $fc_aff->status, $fc_aff->status_reason, $fc_aff->email, $fc_affiliates( 'paula@example.de' ), Handover::record( Handover::ref( 77, 510 ) ) ) );
	AffiliateRepository::set_status( $fc_rid, 'active' );
	AffiliateRepository::clear_cache( $fc_rid );
	do_action( 'form_captain_consent_withdrawn_v1', 560, 77, 'admin' );
	AffiliateRepository::clear_cache( $fc_rid );
	check( 'FC-12 approved partner untouched on consent withdrawal (noted in the audit log)', array( 'active', 'geworben@example.de', 1 ), array( AffiliateRepository::find( $fc_rid )->status, AffiliateRepository::find( $fc_rid )->email, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'audit' ) . " WHERE action = 'form_captain.consent_withdrawn' AND object_id = " . (int) $fc_rid . " AND details LIKE '%kept%'" ) ) );
	do_action( 'form_captain_consent_withdrawn_v1', 542, 77, 'privacy_request' );
	AffiliateRepository::clear_cache( $fc_gid );
	check( 'FC-12 consent withdrawal of a still pending application anonymises it; unknown entries ignored', array( 'privacy_erasure', 'none' ), array( AffiliateRepository::find( $fc_gid )->status_reason, Handover::forget( 999, 77, 'entry_deleted' ) ) );

	// FC-13: the entry was deleted while the applicant confirmed: the partner does not stay.
	Handover::handle( $fc_job( 580, $fc_values( 'race@example.de' ) ) );
	$fc_rec  = Handover::record( Handover::ref( 77, 580 ) );
	$fc_data = Pending::get( 'app_' . $fc_rec['token_hash'] );
	Pending::delete( 'fc_' . Handover::ref( 77, 580 ) ); // The deletion removed the record ...
	$fc_race = $fc_finalize->invoke( null, $fc_data ); // ... after the confirmation had read the application.
	AffiliateRepository::clear_cache( $fc_race );
	check( 'FC-13 confirmation racing a deletion: new partner anonymised at once', 'privacy_erasure', is_int( $fc_race ) ? AffiliateRepository::find( $fc_race )->status_reason : '' );

	// FC-14: the built-in form keeps its behaviour (automatic approval without pending_only).
	$fc_own = $fc_finalize->invoke( null, array( 'user_id' => 0, 'name' => 'Eigenes Formular', 'email' => 'eigen@example.de', 'website' => '', 'channel' => '', 'application_note' => '', 'application_data' => array(), 'group_id' => 0, 'referred_by' => 0, 'terms' => null ) );
	check( 'FC-14 built-in form with automatic approval still creates an active partner', 'active', is_int( $fc_own ) ? AffiliateRepository::find( $fc_own )->status : '' );

	update_option( 'wpac_settings', $fc_keep_settings );
	Settings::flush_cache();
	$GLOBALS['test_user'] = $fc_keep_user;
	unset( $GLOBALS['test_hooks']['form_captain_connectors_v1'], $GLOBALS['test_hooks']['form_captain_entry_deleted_v1'], $GLOBALS['test_hooks']['form_captain_consent_withdrawn_v1'], $GLOBALS['test_hooks']['form_captain_loaded_v1'] );
}
