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

		load_plugin_textdomain( 'wp-affiliate-captain', false, dirname( plugin_basename( WPAC_FILE ) ) . '/languages' );

		Installer::maybe_upgrade();

		CodeRepository::register_hooks();
		ContractApi::register_hooks();
		Tracker::register_hooks();
		Registration::register_hooks();
		Portal::register_hooks();
		Cron::register_hooks();
		Privacy::register_hooks();

		if ( class_exists( 'WooCommerce' ) ) {
			WooCommerce::register_hooks();
		}

		if ( is_admin() ) {
			Admin::register_hooks();
		}

		/**
		 * Fires once WP Affiliate Captain is loaded. Integrations should wait for
		 * this action before calling the public API.
		 *
		 * @param string $contract_version Public contract version.
		 */
		do_action( 'wpac_loaded', WPAC_CONTRACT_VERSION );
	}
}
