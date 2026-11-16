<?php
/**
 * Two-plugin test: Affiliate Captain + Form Captain in the WordPress test suite of Form Captain.
 *
 * Form Captain (repository wp-formmaster, with `composer install`) brings PHPUnit, the WordPress
 * test library, WordPress itself and its contract test kit (ConnectorContractAssertions); this
 * bootstrap loads both plugins into it. See tests/form-captain/README.md.
 *
 * Env: FC_DIR (default: ../wp-formmaster next to this repository), WP_TESTS_DB_NAME/USER/PASSWORD/HOST.
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable

$wpac_fc_dir = rtrim( (string) ( getenv( 'FC_DIR' ) ?: dirname( __DIR__, 3 ) . '/wp-formmaster' ), '/' );
if ( ! is_readable( $wpac_fc_dir . '/vendor/autoload.php' ) || ! is_readable( $wpac_fc_dir . '/form-captain.php' ) ) {
	fwrite( STDERR, "Form Captain with its Composer dependencies not found. Set FC_DIR to a checkout of wp-formmaster (composer install).\n" );
	exit( 2 );
}
define( 'WPAC_FC_DIR', $wpac_fc_dir );
require_once WPAC_FC_DIR . '/vendor/autoload.php';

putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . WPAC_FC_DIR . '/tests/wp-tests-config.php' );
$wpac_fc_tests = getenv( 'WP_PHPUNIT__DIR' ) ?: WPAC_FC_DIR . '/vendor/wp-phpunit/wp-phpunit';
define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', WPAC_FC_DIR . '/vendor/yoast/phpunit-polyfills' );

require_once $wpac_fc_tests . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require WPAC_FC_DIR . '/form-captain.php';
		require dirname( __DIR__, 2 ) . '/affiliate-captain.php';
	}
);
tests_add_filter(
	'init',
	static function () {
		\FormCaptain\Install\Activator::activate();
		if ( ! \WPAffiliateCaptain\Core\Installer::schema_current() ) {
			\WPAffiliateCaptain\Core\Installer::activate();
		}
	},
	2
);
// WP_UnitTestCase wraps each test in a transaction; Form Captain uses savepoints then.
tests_add_filter( 'form_captain_db_in_outer_transaction', '__return_true' );

require $wpac_fc_tests . '/includes/bootstrap.php';
require_once WPAC_FC_DIR . '/tests/integration/TestCase.php';
require_once WPAC_FC_DIR . '/tests/integration/Contract/ConnectorContractAssertions.php';
