<?php
/**
 * Plugin Name:       Affiliate Captain
 * Plugin URI:        https://github.com/hastla007/wpaffiliatemaster
 * Description:       Your own partner program in WordPress: affiliate applications, partner codes and referral links, attribution, commission ledger with refund corrections, payouts with SEPA export and an affiliate portal.
 * Version:           0.20.1
 * Requires at least: 7.0
 * Requires PHP:      8.5
 * Author:            Affiliate Captain
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       affiliate-captain
 * Domain Path:       /languages
 * WC requires at least: 11.2.2
 * WC tested up to: 11.3
 *
 * @package WPAffiliateCaptain
 */

defined( 'ABSPATH' ) || exit;

// Before the takeover below: on an unsupported PHP version an old copy keeps serving the site
// instead of being replaced by a copy that cannot run (PHP-1).
if ( version_compare( PHP_VERSION, '8.5', '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Affiliate Captain requires PHP 8.5 or newer.', 'affiliate-captain' ) . '</p></div>';
		}
	);
	return;
}
// Same for WordPress (MINVER-1, owner decision 17.11.2026: WordPress 7.0+ for all Captain plugins).
if ( isset( $GLOBALS['wp_version'] ) && version_compare( (string) $GLOBALS['wp_version'], '7.0', '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Affiliate Captain requires WordPress 7.0 or newer.', 'affiliate-captain' ) . '</p></div>';
		}
	);
	return;
}

/*
 * Until 0.10 the plugin lived in the folder "wp-affiliate-captain" (WordPress.org does not allow
 * "WP" in plugin names). If that copy is still active (in any folder), this copy takes its place –
 * without its deactivation or uninstall routine, so data, settings and schedules stay as they are.
 * Both copies share the same database tables, options and hooks. See Core\Lifecycle::take_over().
 */
if ( ! class_exists( \WPAffiliateCaptain\Core\Lifecycle::class, false ) ) {
	require_once __DIR__ . '/src/Core/Lifecycle.php';
}
\WPAffiliateCaptain\Core\Lifecycle::take_over( plugin_basename( __FILE__ ) );
// The old copy was already loaded in this request: it serves this request, this copy takes over
// from the next one.
if ( defined( 'WPAC_VERSION' ) ) {
	return;
}

define( 'WPAC_VERSION', '0.20.1' );
define( 'WPAC_DB_VERSION', 16 );
/** Version of the public integration contract (hooks + payload schema). */
define( 'WPAC_CONTRACT_VERSION', '1.8.0' );
define( 'WPAC_FILE', __FILE__ );
define( 'WPAC_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPAC_URL', plugin_dir_url( __FILE__ ) );

// PSR-4 autoloader (Composer's autoloader is used when present).
if ( file_exists( WPAC_DIR . 'vendor/autoload.php' ) ) {
	require_once WPAC_DIR . 'vendor/autoload.php';
} else {
	spl_autoload_register(
		static function ( $class ) {
			$prefix = 'WPAffiliateCaptain\\';
			if ( 0 !== strpos( $class, $prefix ) ) {
				return;
			}
			$file = WPAC_DIR . 'src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}
	);
}

require_once WPAC_DIR . 'src/functions.php';

register_activation_hook( __FILE__, array( \WPAffiliateCaptain\Core\Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \WPAffiliateCaptain\Core\Installer::class, 'deactivate' ) );

// Declare WooCommerce HPOS and Checkout Blocks compatibility.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WPAC_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', WPAC_FILE, true );
		}
	}
);

// Deleting the old copy ("wp-affiliate-captain", up to 0.10) runs its uninstall routine, which
// would remove the shared data when "Delete all data" is on: this copy keeps the data. (The
// takeover also switched the setting off for good, for when this copy is not loaded then.)
add_action(
	'pre_uninstall_plugin',
	static function ( $plugin ) {
		if ( ! \WPAffiliateCaptain\Core\Lifecycle::is_legacy( $plugin, plugin_basename( WPAC_FILE ) ) ) {
			return;
		}
		add_filter(
			'option_wpac_settings',
			static function ( $settings ) {
				if ( is_array( $settings ) ) {
					$settings['delete_on_uninstall'] = 0;
				}
				return $settings;
			}
		);
	}
);

add_action( 'plugins_loaded', array( \WPAffiliateCaptain\Plugin::class, 'boot' ), 5 );
