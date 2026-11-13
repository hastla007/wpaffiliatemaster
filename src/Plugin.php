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

		Installer::maybe_upgrade();
		Installer::register_multisite_hooks();

		CodeRepository::register_hooks();
		ContractApi::register_hooks();
		Tracker::register_hooks();
		\WPAffiliateCaptain\Tracking\CustomerLinks::register_hooks();
		\WPAffiliateCaptain\Tracking\Consent::register_hooks();
		Registration::register_hooks();
		\WPAffiliateCaptain\Affiliates\Notifications::register_hooks();
		Portal::register_hooks();
		\WPAffiliateCaptain\Promotion\Landing::register_hooks();
		\WPAffiliateCaptain\Content\Editor::register_hooks();
		\WPAffiliateCaptain\Rest\RestApi::register_hooks();
		Cron::register_hooks();
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
}
