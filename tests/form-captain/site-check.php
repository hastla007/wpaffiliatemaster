<?php
/**
 * End-to-end check on a WordPress test site with Form Captain and Affiliate Captain active
 * (WP-CLI: wp eval-file tests/form-captain/site-check.php). Only for test installations: it
 * creates a page, a form, entries and partner applications and deletes them again at the end.
 *
 * Flow: connector opt-in → submission through form_captain_submit_v1() → Form Captain worker →
 * confirmation mail → confirmation → pending partner (also with automatic approval) → duplicate
 * delivery → entry deletion anonymises the application. Exit code 0 when everything passed.
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable

use FormCaptain\Integrations\ConnectorRegistry;
use FormCaptain\Jobs\JobRunner;
use FormCaptain\Plugin;
use FormCaptain\Repository\FormRepository;
use FormCaptain\Repository\JobRepository;
use FormCaptain\Service\EntryDeletionService;
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Affiliates\Registration;
use WPAffiliateCaptain\Core\Pending;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Integrations\FormCaptain\Handover;

$wpac_fails = 0;
$wpac_check = static function ( $name, $expected, $actual ) use ( &$wpac_fails ) {
	$ok = $expected === $actual;
	$wpac_fails += $ok ? 0 : 1;
	echo ( $ok ? 'ok    ' : 'FAIL  ' ) . $name . ( $ok ? '' : ' – expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) ) . "\n";
};

if ( ! function_exists( 'form_captain_submit_v1' ) || ! class_exists( Handover::class ) ) {
	echo "Form Captain and Affiliate Captain must both be active.\n";
	exit( 2 );
}
$fc       = Plugin::instance();
$registry = $fc->get( ConnectorRegistry::class );
$keep     = array(
	'settings' => get_option( 'wpac_settings' ),
	'optins'   => get_option( ConnectorRegistry::OPTION ),
);
// Fresh state of the opt-in (as after installing the plugin); restored at the end.
$optins = is_array( $keep['optins'] ) ? $keep['optins'] : array();
unset( $optins['affiliate_captain'] );
update_option( ConnectorRegistry::OPTION, $optins );
$page     = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Partner werden (Test)', 'post_content' => '[wpac_affiliate_registration]' ) );
Settings::update( array( 'registration_page_id' => $page, 'auto_approve' => 1 ) );
Settings::flush_cache();
$registry->reload();

$wpac_check( 'connector detected, not active (opt-in)', 'detected', $registry->state( 'affiliate_captain' ) );
$wpac_check( 'activation', true, $registry->activate( 'affiliate_captain' ) );

$repo   = $fc->get( FormRepository::class );
$schema = array(
	'schema_version' => 1,
	'fields'         => array(
		array( 'id' => 'f_name01', 'type' => 'text', 'key' => 'name', 'label' => 'Name', 'required' => true ),
		array( 'id' => 'f_mail01', 'type' => 'email', 'key' => 'email', 'label' => 'E-Mail', 'required' => true ),
		array( 'id' => 'f_site01', 'type' => 'text', 'key' => 'website', 'label' => 'Website', 'required' => false ),
		array( 'id' => 'f_tel001', 'type' => 'tel', 'key' => 'phone', 'label' => 'Telefon', 'required' => false ),
		array( 'id' => 'f_agb001', 'type' => 'consent', 'key' => 'terms', 'label' => 'Ich akzeptiere die Teilnahmebedingungen', 'required' => true ),
	),
);
$form_id = $repo->create(
	'Partnerbewerbung (Test)',
	$schema,
	array(
		'notification' => array( 'enabled' => false, 'to' => array(), 'subject' => '', 'fields' => 'all_non_sensitive' ),
		'confirmation' => array( 'type' => 'message', 'message' => 'Danke' ),
		'actions'      => array(
			array(
				'instance_id' => 'a_5173c0ec01',
				'action_key'  => 'affiliate_captain.partner_application',
				'enabled'     => true,
				'settings'    => array( 'group_id' => '0', 'terms_version' => (string) Settings::get( 'terms_version' ) ),
				'mapping'     => array( 'name' => 'f_name01', 'email' => 'f_mail01', 'website' => 'f_site01', 'terms' => 'f_agb001' ),
			),
		),
	)
);
$repo->publish_version( $form_id, $schema );

$email  = 'sitecheck-' . strtolower( wp_generate_password( 6, false, false ) ) . '@example.org';
$mails  = array();
add_filter( 'pre_wp_mail', static function ( $pre, $atts ) use ( &$mails ) { $mails[] = $atts; return true; }, 10, 2 );
$sub = form_captain_submit_v1( $form_id, array( 'name' => 'Site Check', 'email' => $email, 'website' => 'sitecheck.example', 'phone' => '0171 999', 'terms' => '1' ), array( 'source' => 'wpac-site-check', 'idempotency_key' => 'wpac-site-check-' . $email ) );
$wpac_check( 'submission accepted', true, ! empty( $sub['success'] ) );
$entry = (int) $sub['entry_id'];
$jobs  = $fc->get( JobRepository::class );
$key   = sprintf( 'form-captain:%d:%d:%s:%s', get_current_blog_id(), $entry, 'affiliate_captain.partner_application', 'a_5173c0ec01' );
$job   = $jobs->find_by_key( $key );
$wpac_check( 'outbox job created after the submission', true, is_array( $job ) );
$state = $job ? $fc->get( JobRunner::class )->run_one( (int) $job['id'] ) : null;
$job   = $job ? $jobs->find( (int) $job['id'] ) : null;
$wpac_check( 'worker: job succeeded with confirm_sent', array( 'succeeded', 'confirm_sent' ), array( $state, (string) ( $job['result']['code'] ?? '' ) ) );
$wpac_check( 'one confirmation mail with link to the registration page', array( 1, true ), array( count( array_filter( $mails, static function ( $m ) use ( $email ) { return in_array( $email, (array) $m['to'], true ); } ) ), false !== strpos( (string) ( $mails[0]['message'] ?? '' ), 'wpac_confirm=' ) ) );
$wpac_check( 'no partner before the confirmation', null, AffiliateRepository::find_by_email( $email ) );

$again = $registry->handle_job( $job );
$wpac_check( 'repeated delivery: same result, no second mail', array( 'success', $job['result']['remote_id'], 1 ), array( $again->status, $again->data['remote_id'] ?? '', count( $mails ) ) );

$record   = Handover::record( Handover::ref( $form_id, $entry ) );
$finalize = new ReflectionMethod( Registration::class, 'finalize' );
$finalize->setAccessible( true );
$aff_id = $finalize->invoke( null, Pending::get( 'app_' . $record['token_hash'] ) );
AffiliateRepository::clear_cache();
$aff = AffiliateRepository::find( (int) $aff_id );
$wpac_check( 'confirmation: partner pending despite automatic approval, no phone number', array( 'pending', 'https://sitecheck.example', false ), array( $aff ? $aff->status : '', $aff ? $aff->website : '', $aff && false !== strpos( wp_json_encode( $aff ), '0171' ) ) );

$fc->get( EntryDeletionService::class )->delete( $entry, 'manual' );
AffiliateRepository::clear_cache();
$aff = AffiliateRepository::find( (int) $aff_id );
$wpac_check( 'entry deleted: pending application anonymised', array( 'rejected', 'privacy_erasure' ), array( $aff ? $aff->status : '', $aff ? $aff->status_reason : '' ) );

// Clean up.
$repo->delete( $form_id );
wp_delete_post( $page, true );
update_option( 'wpac_settings', $keep['settings'] );
false === $keep['optins'] ? delete_option( ConnectorRegistry::OPTION ) : update_option( ConnectorRegistry::OPTION, $keep['optins'] );
echo 0 === $wpac_fails ? "All checks passed.\n" : $wpac_fails . " check(s) failed.\n";
exit( $wpac_fails ? 1 : 0 );
