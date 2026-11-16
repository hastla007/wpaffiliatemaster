<?php
/**
 * Plugin bootstrap.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain;

use WPAffiliateCaptain\Admin\Admin;
use WPAffiliateCaptain\Affiliates\Registration;
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Core\Cron;
use WPAffiliateCaptain\Core\Installer;
use WPAffiliateCaptain\Integrations\WooCommerce;
use WPAffiliateCaptain\Portal\Portal;
use WPAffiliateCaptain\Privacy\Privacy;
use WPAffiliateCaptain\Sales\ContractApi;
use WPAffiliateCaptain\Tracking\Tracker;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	/** @var bool */
	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		// Translations are loaded at init (WordPress 6.7+ warns about earlier loading).
		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'affiliate-captain', false, dirname( plugin_basename( WPAC_FILE ) ) . '/languages' );
			},
			1
		);

		self::prime_options();

		// An old main file left in the folder loaded this code: the new one takes over next request.
		\WPAffiliateCaptain\Core\Lifecycle::switch_main_file( WPAC_FILE );
		Installer::maybe_upgrade();
		Installer::register_multisite_hooks();
		add_action( 'init', array( \WPAffiliateCaptain\Core\Lifecycle::class, 'followup' ), 5 );
		add_action( 'init', array( \WPAffiliateCaptain\Core\Capabilities::class, 'translate_role_name' ), 2 ); // R12-N13.
		add_action( 'wp_roles_init', array( \WPAffiliateCaptain\Core\Capabilities::class, 'translate_role_name' ), 10, 1 ); // After switch_to_blog() (R13-N10).
		add_action( 'admin_init', array( \WPAffiliateCaptain\Core\Lifecycle::class, 'run_check_clone' ), 5 );
		\WPAffiliateCaptain\Affiliates\AffiliateRepository::register_user_hooks();

		CodeRepository::register_hooks();
		ContractApi::register_hooks();
		Tracker::register_hooks();
		\WPAffiliateCaptain\Tracking\CustomerLinks::register_hooks();
		\WPAffiliateCaptain\Tracking\Consent::register_hooks();
		Registration::register_hooks();
		\WPAffiliateCaptain\Affiliates\Notifications::register_hooks();
		\WPAffiliateCaptain\Payouts\EInvoice::register_hooks(); // E-invoice XML with the credit note mails (R14-L1).
		\WPAffiliateCaptain\Reports\Reports::register_hooks(); // Bookings empty the report cache (R12-M23).
		\WPAffiliateCaptain\Commissions\ReviewAlert::register_hooks(); // Review count cache and digest mail (R5-W58).
		Portal::register_hooks();
		\WPAffiliateCaptain\Promotion\Landing::register_hooks();
		\WPAffiliateCaptain\Content\Editor::register_hooks();
		\WPAffiliateCaptain\Rest\RestApi::register_hooks();
		Cron::register_hooks();
		\WPAffiliateCaptain\Payouts\PayoutService::register_hooks(); // Credit notes of payout runs (job queue).
		\WPAffiliateCaptain\Payouts\StatementSweep::register_hooks(); // Retention deletion on every app server (R6-CC5).
		Privacy::register_hooks();
		\WPAffiliateCaptain\Cli\Command::register();
		add_filter( 'site_status_tests', array( \WPAffiliateCaptain\Core\Monitor::class, 'site_status_tests' ) );
		add_filter( 'debug_information', array( \WPAffiliateCaptain\Core\Monitor::class, 'debug_information' ) );

		if ( class_exists( 'WooCommerce' ) ) {
			WooCommerce::register_hooks();
		}

		if ( is_admin() ) {
			Admin::register_hooks();
		}

		/**
		 * Fires once Affiliate Captain is loaded. Integrations should wait for
		 * this action before calling the public API.
		 *
		 * @param string $contract_version Public contract version.
		 */
		do_action( 'wpac_loaded', WPAC_CONTRACT_VERSION );
	}

	/**
	 * Load the status options read on (almost) every request in one query: missing or not
	 * autoloaded options otherwise cost one query each without a persistent object cache
	 * (R12-N9). wp_prime_option_caches() exists from WordPress 6.4 on.
	 */
	private static function prime_options() {
		if ( ! function_exists( 'wp_prime_option_caches' ) ) {
			return;
		}
		$names = array( 'wpac_schema_problems', 'wpac_legacy_takeover', 'wpac_restricted' );
		if ( is_admin() ) {
			$names = array_merge( $names, array( 'wpac_activation_redirect', 'wpac_schema_warnings', 'wpac_balance_problems', 'wpac_statement_retry', 'wpac_storno_retry', 'wpac_clone_detected', 'wpac_takeover_notice', 'wpac_takeover_seen', 'wpac_retained_notice', 'wpac_migration_status', 'wpac_schema_problems_since' ) );
		}
		wp_prime_option_caches( $names );
	}
}
