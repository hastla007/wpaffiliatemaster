<?php
/**
 * Plugin Name:       WP Affiliate Captain
 * Plugin URI:        https://github.com/hastla007/wpaffiliatemaster
 * Description:       Your own partner program in WordPress: affiliate applications, partner codes and referral links, attribution, commission ledger with refund corrections, payouts with SEPA export and an affiliate portal.
 * Version:           0.8.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            WP Affiliate Captain
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-affiliate-captain
 * Domain Path:       /languages
 * WC requires at least: 8.0
 *
 * @package WPAffiliateCaptain
 */

defined( 'ABSPATH' ) || exit;

define( 'WPAC_VERSION', '0.8.0' );
define( 'WPAC_DB_VERSION', 7 );
/** Version of the public integration contract (hooks + payload schema). */
define( 'WPAC_CONTRACT_VERSION', '1.1.0' );
define( 'WPAC_FILE', __FILE__ );
define( 'WPAC_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPAC_URL', plugin_dir_url( __FILE__ ) );

if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'WP Affiliate Captain requires PHP 7.4 or newer.', 'wp-affiliate-captain' ) . '</p></div>';
		}
	);
	return;
}

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

add_action( 'plugins_loaded', array( \WPAffiliateCaptain\Plugin::class, 'boot' ), 5 );
