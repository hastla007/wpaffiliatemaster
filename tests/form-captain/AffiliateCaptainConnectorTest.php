<?php
/**
 * Two-plugin test (AFF-SU-01): the connector `affiliate_captain` in a real Form Captain – opt-in,
 * explicit mapping, outbox worker, idempotency, results, deletion and consent withdrawal. Uses
 * Form Captain's contract test kit (ConnectorContractAssertions) like its reference connector test.
 *
 * Run: see tests/form-captain/README.md.
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable

use FormCaptain\Integrations\ConnectorRegistry;
use FormCaptain\Jobs\JobRunner;
use FormCaptain\Repository\EntryRepository;
use FormCaptain\Repository\JobRepository;
use FormCaptain\Service\ConsentService;
use FormCaptain\Service\EntryDeletionService;
use FormCaptain\Support\Clock;
use FormCaptain\Tests\Integration\Contract\ConnectorContractAssertions;
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Affiliates\Registration;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Pending;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Integrations\FormCaptain\Connector;
use WPAffiliateCaptain\Integrations\FormCaptain\FormCaptain;
use WPAffiliateCaptain\Integrations\FormCaptain\Handover;

/**
 * @group two-plugin
 */
final class AffiliateCaptainConnectorTest extends \FormCaptain\Tests\Integration\TestCase {

	use ConnectorContractAssertions;

	const ACTION   = 'affiliate_captain.partner_application';
	const INSTANCE = 'a_0affca0001';

	/** @var int Registration page. */
	private $page = 0;

	public function set_up(): void {
		parent::set_up();
		delete_option( ConnectorRegistry::OPTION );
		$this->page = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Partner werden', 'post_content' => '[wpac_affiliate_registration]' ) );
		Settings::update( array( 'registration_page_id' => $this->page, 'auto_approve' => 1, 'terms_page_id' => 0, 'terms_version' => '', 'maintenance_mode' => 0 ) );
		Settings::flush_cache();
		AffiliateRepository::clear_cache();
		reset_phpmailer_instance();
		$this->registry()->reset();
		$this->registry()->reload();
	}

	public function tear_down(): void {
		$this->registry()->reset();
		Settings::flush_cache();
		AffiliateRepository::clear_cache();
		parent::tear_down();
	}

	private function activate(): void {
		$this->as_admin();
		$this->assertTrue( $this->registry()->activate( FormCaptain::CONNECTOR ) );
		wp_set_current_user( 0 );
	}

	/**
	 * Published application form with the action, one entry and its outbox job.
	 *
	 * @param array $values field_id => value.
	 * @return array{0:array,1:int,2:int} form, entry, job
	 */
	private function submission( array $values, array $settings = array() ): array {
		static $form = null;
		$form  = $this->create_published_form(
			array(
				array( 'id' => 'f_name01', 'type' => 'text', 'key' => 'name', 'label' => 'Name', 'required' => true ),
				array( 'id' => 'f_mail01', 'type' => 'email', 'key' => 'email', 'label' => 'E-Mail', 'required' => true ),
				array( 'id' => 'f_site01', 'type' => 'text', 'key' => 'website', 'label' => 'Website', 'required' => false ),
				array( 'id' => 'f_chan01', 'type' => 'select', 'key' => 'channel', 'label' => 'Kanal', 'required' => false, 'options' => array( array( 'value' => 'Instagram', 'label' => 'Instagram' ), array( 'value' => 'Blog', 'label' => 'Blog' ) ) ),
				array( 'id' => 'f_msg001', 'type' => 'textarea', 'key' => 'message', 'label' => 'Nachricht', 'required' => false ),
				array( 'id' => 'f_tel001', 'type' => 'tel', 'key' => 'phone', 'label' => 'Telefon', 'required' => false ),
				array( 'id' => 'f_agb001', 'type' => 'consent', 'key' => 'terms', 'label' => 'Ich akzeptiere die Teilnahmebedingungen', 'required' => false ),
				array( 'id' => 'f_ref001', 'type' => 'hidden', 'key' => 'referrer', 'label' => 'Werber', 'required' => false ),
			),
			array(
				'actions' => array(
					self::action_instance(
						self::ACTION,
						self::INSTANCE,
						array( 'name' => 'f_name01', 'email' => 'f_mail01', 'website' => 'f_site01', 'channel' => 'f_chan01', 'message' => 'f_msg001', 'terms' => 'f_agb001', 'referrer' => 'f_ref001' ),
						$settings + array( 'group_id' => '0', 'terms_version' => '' )
					),
				),
			)
		);
		$entry = $this->make_entry( $form, $values );
		return array( $form, $entry, $this->enqueue_action_job( $form, $entry, self::ACTION, self::INSTANCE ) );
	}

	private function values( string $email, array $more = array() ): array {
		return $more + array( 'f_name01' => 'Paula Partner', 'f_mail01' => $email, 'f_site01' => 'paula.example', 'f_chan01' => 'Instagram', 'f_msg001' => 'Ich segle.', 'f_tel001' => '0171 1234567', 'f_agb001' => '1' );
	}

	private function run_job( int $id ): ?string {
		return $this->plugin()->get( JobRunner::class )->run_one( $id );
	}

	private function job( int $id ): array {
		return $this->plugin()->get( JobRepository::class )->find( $id );
	}

	/** Confirmation click of the applicant (Registration::handle_confirm() ends with a redirect). */
	private function confirm( int $form_id, int $entry_id ) {
		$record = Handover::record( Handover::ref( $form_id, $entry_id ) );
		$data   = $record ? Pending::get( 'app_' . $record['token_hash'] ) : null;
		$this->assertIsArray( $data, 'Waiting application' );
		$finalize = new \ReflectionMethod( Registration::class, 'finalize' );
		$finalize->setAccessible( true );
		$id = $finalize->invoke( null, $data );
		AffiliateRepository::clear_cache();
		return $id;
	}

	private function partners( string $email ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Db::table( 'affiliates' ) . ' WHERE email = %s', $email ) );
	}

	private function mails_to( string $email ): int {
		$n = 0;
		foreach ( tests_retrieve_phpmailer_instance()->mock_sent as $mail ) {
			foreach ( (array) $mail['to'] as $to ) {
				$n += strtolower( (string) $to[0] ) === strtolower( $email ) ? 1 : 0;
			}
		}
		return $n;
	}

	public function test_connector_follows_the_contract_and_is_opt_in(): void {
		$connector = new Connector();
		$this->assertConnectorShape( $connector );
		$this->assertSame( ConnectorRegistry::STATE_DETECTED, $this->registry()->state( FormCaptain::CONNECTOR ), 'Disabled after installation' );
		$actions = $this->registry()->actions();
		$this->assertArrayHasKey( self::ACTION, $actions );
		$this->assertSame( 'Partner application to Affiliate Captain', $actions[ self::ACTION ]['label'] );
		$this->assertSame( array( 'name', 'email', 'website', 'channel', 'message', 'terms', 'referrer' ), array_column( $actions[ self::ACTION ]['mapping'], 'key' ) );
		$this->assertSame( array( true, true, true ), array( $actions[ self::ACTION ]['mapping'][0]['required'], $actions[ self::ACTION ]['mapping'][1]['required'], $actions[ self::ACTION ]['mapping'][5]['required'] ) );
		$this->assertNotContains( true, array_column( $actions[ self::ACTION ]['mapping'], 'allow_sensitive' ) );

		// Not activated: the job is parked, nothing reaches Affiliate Captain.
		list( , , $id ) = $this->submission( $this->values( 'parked@example.org' ) );
		$this->assertSame( 'paused', $this->run_job( $id ) );
		$this->assertSame( 0, $this->partners( 'parked@example.org' ) );
		$this->assertSame( 0, $this->mails_to( 'parked@example.org' ) );
	}

	public function test_test_run_has_no_side_effects(): void {
		$this->activate();
		$res = $this->registry()->test_action( self::ACTION, array( 'group_id' => '0' ) );
		$this->assertSame( array( 'success', 'test_ok' ), array( $res['status'], $res['code'] ) );
		$this->assertSame( 0, $this->partners( 'test@example.com' ) );
		$this->assertSame( array(), tests_retrieve_phpmailer_instance()->mock_sent );
	}

	public function test_hand_over_waits_for_double_opt_in_and_creates_only_a_pending_partner(): void {
		$this->activate();
		list( $form, $entry, $id ) = $this->submission( $this->values( 'paula@example.org' ) );
		$this->assertSame( 'succeeded', $this->run_job( $id ) );
		$job = $this->job( $id );
		$this->assertActionResultShape( array( 'status' => 'success', 'code' => (string) $job['result']['code'], 'remote_id' => (string) $job['result']['remote_id'] ) );
		$this->assertSame( 'confirm_sent', $job['result']['code'] );
		$this->assertMatchesRegularExpression( '/^wpac-fc-[0-9a-f]{16}$/', $job['result']['remote_id'] );
		$this->assertSame( 1, $this->mails_to( 'paula@example.org' ), 'Confirmation mail of Affiliate Captain' );
		$this->assertStringContainsString( 'wpac_confirm=', tests_retrieve_phpmailer_instance()->get_sent()->body );
		$this->assertSame( 0, $this->partners( 'paula@example.org' ), 'Nothing is created before the confirmation' );

		$aff_id = $this->confirm( (int) $form['id'], $entry );
		$aff    = AffiliateRepository::find( $aff_id );
		$this->assertSame( 'pending', $aff->status, 'Pending even with automatic approval' );
		$this->assertSame( array( 'Paula Partner', 'https://paula.example', 'Instagram', 'Ich segle.' ), array( $aff->name, $aff->website, $aff->channel, $aff->application_note ) );
		$this->assertStringNotContainsString( '0171', wp_json_encode( $aff ), 'Unmapped field (phone) never arrives' );

		global $wpdb;
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Db::table( 'partner_codes' ) . ' WHERE affiliate_id = %d', $aff_id ) ), 'No partner code' );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Db::table( 'commissions' ) . ' WHERE affiliate_id = %d', $aff_id ) ), 'No commission' );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Db::table( 'rules' ) . " WHERE scope = 'affiliate' AND scope_id = %d", $aff_id ) ), 'No commission rule' );
	}

	public function test_duplicate_delivery_is_idempotent(): void {
		$this->activate();
		list( $form, $entry, $id ) = $this->submission( $this->values( 'twice@example.org' ) );
		$this->assertSame( 'succeeded', $this->run_job( $id ) );
		$remote = $this->job( $id )['result']['remote_id'];
		// Same idempotency key → same job; running the handler again (timeout after success, admin retry).
		$this->assertSame( $id, $this->enqueue_action_job( $form, $entry, self::ACTION, self::INSTANCE ) );
		$again = $this->registry()->handle_job( $this->job( $id ) );
		$this->assertSame( array( 'success', $remote ), array( $again->status, $again->data['remote_id'] ) );
		$this->assertIdempotentCallback( array( Handover::class, 'handle' ), array( 'form_id' => (int) $form['id'], 'entry_id' => $entry, 'idempotency_key' => 'form-captain:1:' . $entry . ':' . self::ACTION . ':' . self::INSTANCE, 'settings' => array( 'group_id' => '0' ), 'values' => array( 'name' => 'X', 'email' => 'twice@example.org', 'terms' => '1' ), 'context' => array(), 'test_mode' => false ) );
		$this->assertSame( 1, $this->mails_to( 'twice@example.org' ), 'One confirmation mail' );
		$this->confirm( (int) $form['id'], $entry );
		$this->assertSame( 1, $this->partners( 'twice@example.org' ) );
	}

	public function test_existing_partner_gets_no_second_account(): void {
		$this->activate();
		AffiliateRepository::create( array( 'name' => 'Schon da', 'email' => 'known@example.org', 'status' => 'active' ) );
		list( , , $id ) = $this->submission( $this->values( 'known@example.org' ) );
		$this->assertSame( 'succeeded', $this->run_job( $id ) );
		$job = $this->job( $id );
		$this->assertSame( 'already_exists', $job['result']['code'] );
		$this->assertStringStartsWith( 'wpac-fc-', $job['result']['remote_id'], 'No partner ID in the result' );
		$this->assertSame( 1, $this->partners( 'known@example.org' ) );
	}

	public function test_missing_consent_is_a_final_failure(): void {
		$this->activate();
		list( , , $id ) = $this->submission( $this->values( 'noconsent@example.org', array( 'f_agb001' => '0' ) ) );
		$this->assertSame( 'dead', $this->run_job( $id ) );
		$this->assertSame( 'terms_required', $this->job( $id )['last_error_code'] );
		$this->assertSame( 0, $this->mails_to( 'noconsent@example.org' ) );
	}

	public function test_inactive_affiliate_captain_means_retry(): void {
		$this->activate();
		list( , , $id ) = $this->submission( $this->values( 'inactive@example.org' ) );
		// Affiliate Captain deactivated: its connector and action are no longer registered.
		remove_filter( 'form_captain_connectors_v1', array( FormCaptain::class, 'add_connector' ) );
		$this->registry()->reset();
		$this->registry()->reload();
		$this->assertSame( 'failed', $this->run_job( $id ), 'Retried later, not lost' );
		$this->assertSame( 'action_not_found', $this->job( $id )['last_error_code'] );

		// Back again (plugin active): the next attempt delivers.
		add_filter( 'form_captain_connectors_v1', array( FormCaptain::class, 'add_connector' ) );
		$this->registry()->reset();
		$this->registry()->reload();
		Clock::freeze( time() + 7 * HOUR_IN_SECONDS );
		$this->assertSame( 'succeeded', $this->run_job( $id ) );
	}

	public function test_maintenance_mode_means_retry(): void {
		$this->activate();
		Settings::update( array( 'maintenance_mode' => 1 ) );
		Settings::flush_cache();
		list( , , $id ) = $this->submission( $this->values( 'maint@example.org' ) );
		$this->assertSame( 'failed', $this->run_job( $id ) );
		$this->assertSame( 'affiliate_captain_unavailable', $this->job( $id )['last_error_code'] );
	}

	public function test_entry_deletion_removes_the_pending_application(): void {
		$this->activate();
		list( $form, $entry, $id ) = $this->submission( $this->values( 'delete@example.org' ) );
		$this->run_job( $id );
		$record = Handover::record( Handover::ref( (int) $form['id'], $entry ) );
		$this->assertTrue( $this->plugin()->get( EntryDeletionService::class )->delete( $entry, 'manual' ) );
		$this->assertNull( Pending::get( 'app_' . $record['token_hash'] ), 'Unconfirmed application deleted' );
		$this->assertSame( 'expired', Registration::token_state( 'unknown' ) );

		// Confirmed (pending partner): anonymised by the privacy rules of Affiliate Captain.
		list( $form2, $entry2, $id2 ) = $this->submission( $this->values( 'delete2@example.org' ) );
		$this->run_job( $id2 );
		$aff_id = $this->confirm( (int) $form2['id'], $entry2 );
		$this->plugin()->get( EntryDeletionService::class )->delete( $entry2, 'manual' );
		AffiliateRepository::clear_cache();
		$aff = AffiliateRepository::find( $aff_id );
		$this->assertSame( array( 'rejected', 'privacy_erasure', 0 ), array( $aff->status, $aff->status_reason, $this->partners( 'delete2@example.org' ) ) );
	}

	public function test_consent_withdrawal_erases_pending_and_keeps_approved_partners(): void {
		$this->activate();
		list( $form, $entry, $id ) = $this->submission( $this->values( 'withdraw@example.org' ) );
		$this->run_job( $id );
		$pending = $this->confirm( (int) $form['id'], $entry );
		$this->plugin()->get( ConsentService::class )->withdraw( $entry, 'admin', 'keep' );
		AffiliateRepository::clear_cache();
		$this->assertSame( 'privacy_erasure', AffiliateRepository::find( $pending )->status_reason );

		list( $form2, $entry2, $id2 ) = $this->submission( $this->values( 'approved@example.org' ) );
		$this->run_job( $id2 );
		$approved = $this->confirm( (int) $form2['id'], $entry2 );
		AffiliateRepository::set_status( $approved, 'active' );
		$this->plugin()->get( ConsentService::class )->withdraw( $entry2, 'admin', 'keep' );
		AffiliateRepository::clear_cache();
		$this->assertSame( array( 'active', 'approved@example.org' ), array( AffiliateRepository::find( $approved )->status, AffiliateRepository::find( $approved )->email ), 'Approved partner untouched' );
	}
}
