<?php
/**
 * Upgrade test: the site was installed with the previous release (and test data); the plugin
 * folder now points to this checkout. A cron request runs the migration with the real dbDelta;
 * afterwards the schema must be current and complete and the data unchanged.
 *
 * Settings (P6-13): every setting of the previous version keeps its effective value after the
 * update (Settings::all() before and after), except the conversions listed in $converted below;
 * the limits of the small business check and the legal minimum retention of credit notes
 * (Retention::minimum_years()) stay the same. Settings the new version adds are not compared.
 *
 *   php tests/ci/upgrade-check.php <wp-dir> <host> <before.json>   (writes before.json when missing)
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
$_SERVER['HTTP_HOST']   = $argv[2];
$_SERVER['REQUEST_URI'] = '/';
$snapshot_file          = $argv[3];
$before                 = is_file( $snapshot_file ) ? json_decode( (string) file_get_contents( $snapshot_file ), true ) : null;
if ( $before ) {
	define( 'DOING_CRON', true ); // Migrations run in cron, admin or WP-CLI requests.
}
require rtrim( $argv[1], '/' ) . '/wp-load.php';
global $wpdb;
$p     = $wpdb->prefix . 'wpac_';
$state = array(
	'affiliates'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}affiliates" ),
	'commissions' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}commissions" ),
	'sum'         => (int) $wpdb->get_var( "SELECT COALESCE(SUM(amount_minor),0) FROM {$p}commissions" ),
	'codes'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}partner_codes" ),
	'version'     => (int) get_option( 'wpac_db_version' ),
);
// Effective settings and what depends on them, read through the API of the version that runs.
$settings_class   = 'WPAffiliateCaptain\\Core\\Settings';
$state['settings'] = class_exists( $settings_class ) && method_exists( $settings_class, 'all' ) ? $settings_class::all() : (array) get_option( 'wpac_settings', array() );
$state['stored']   = array_keys( (array) get_option( 'wpac_settings', array() ) );
$retention_class   = 'WPAffiliateCaptain\\Privacy\\Retention';
$state['retention_minimum_years'] = class_exists( $retention_class ) && method_exists( $retention_class, 'minimum_years' ) ? (int) $retention_class::minimum_years() : null;
// Limits of the small business check (currency => [ previous year, current year ] in minor units):
// captured from the filter wpac_small_business_limits after every other callback, so the value
// is the one the version that runs really uses (fixed up to 0.21.0, settings since 0.22.0).
$payout_class            = 'WPAffiliateCaptain\\Payouts\\PayoutService';
$state['small_business'] = null;
if ( class_exists( $payout_class ) && method_exists( $payout_class, 'small_business_warnings' ) ) {
	$captured = array();
	add_filter(
		'wpac_small_business_limits',
		static function ( $limits ) use ( &$captured ) {
			$captured = (array) $limits;
			return $limits;
		},
		PHP_INT_MAX
	);
	$warnings                = $payout_class::small_business_warnings();
	$state['small_business'] = array(
		'limits'   => $captured,
		'warnings' => count( (array) $warnings ),
	);
}
$state = json_decode( (string) json_encode( $state ), true ); // Same types as the stored snapshot.
if ( ! $before ) {
	file_put_contents( $snapshot_file, json_encode( $state ) );
	echo 'before upgrade: ', json_encode( array_diff_key( $state, array( 'settings' => 1, 'stored' => 1 ) ) ), ' + ', count( $state['settings'] ), " settings\n";
	exit( 0 );
}
$fail = 0;
$chk  = static function ( $name, $cond, $info = '' ) use ( &$fail ) {
	echo ( $cond ? '  ok   ' : '  FAIL ' ), $name, $info ? "  [$info]" : '', "\n";
	$fail += $cond ? 0 : 1;
};
echo 'after upgrade: ', json_encode( array_diff_key( $state, array( 'settings' => 1, 'stored' => 1 ) ) ), ' + ', count( $state['settings'] ), " settings\n";
$chk( 'schema version raised to ' . WPAC_DB_VERSION, WPAC_DB_VERSION === $state['version'], 'was ' . $before['version'] );
$problems = WPAffiliateCaptain\Core\Installer::verify_schema();
$chk( 'schema complete (real dbDelta)', array() === $problems, implode( '; ', $problems ) );
$chk( 'no migration problems stored', ! get_option( 'wpac_schema_problems' ) );
foreach ( array( 'affiliates', 'commissions', 'sum', 'codes' ) as $k ) {
	$chk( "data unchanged: $k", $before[ $k ] === $state[ $k ], $before[ $k ] . ' → ' . $state[ $k ] );
}
$chk( 'accounts consistent', array() === WPAffiliateCaptain\Commissions\Balance::verify_all() );

// Settings of the previous version: same effective value after the update. Deliberate
// conversions of a release are listed here with the reason (never to silence a regression).
$converted = array(
	// SET-AC-19/31 (0.21.0): never had an effect; becomes payout_reminder "off" (former value kept in wpac_legacy_payout_period).
	'payout_period' => 'removed',
	// Older versions stored the monitoring token in plain text; it is hashed on the first read.
	'monitor_token' => 'hashed',
	// Switched on and off again by tests/ci/switch-plugin.php between the two snapshots (takeover check).
	'delete_on_uninstall' => 'test helper',
);
// Runtime state that moved out of the settings into own options (read through Settings::get()).
$runtime = defined( 'WPAffiliateCaptain\\Core\\Settings::RUNTIME' ) ? WPAffiliateCaptain\Core\Settings::RUNTIME : array();
$changed = array();
foreach ( (array) $before['settings'] as $key => $value ) {
	if ( isset( $converted[ $key ] ) ) {
		continue;
	}
	if ( isset( $runtime[ $key ] ) ) {
		if ( (string) $value !== (string) WPAffiliateCaptain\Core\Settings::get( $key ) ) {
			$changed[] = $key . ': ' . json_encode( $value ) . ' → ' . json_encode( WPAffiliateCaptain\Core\Settings::get( $key ) ) . ' (runtime state)';
		}
	} elseif ( ! array_key_exists( $key, $state['settings'] ) ) {
		$changed[] = $key . ' (dropped)';
	} elseif ( $value !== $state['settings'][ $key ] ) {
		$changed[] = $key . ': ' . json_encode( $value ) . ' → ' . json_encode( $state['settings'][ $key ] ) . ( in_array( $key, (array) $before['stored'], true ) ? ' (stored)' : ' (default)' );
	}
}
$chk( 'settings of the previous version unchanged (' . count( (array) $before['settings'] ) . ' keys)', array() === $changed, implode( '; ', $changed ) );
if ( in_array( 'payout_period', (array) $before['stored'], true ) ) {
	$chk( 'payout_period converted: payout_reminder off unless it was set', array_key_exists( 'payout_reminder', (array) $before['settings'] ) ? $before['settings']['payout_reminder'] === $state['settings']['payout_reminder'] : 'off' === $state['settings']['payout_reminder'], json_encode( $state['settings']['payout_reminder'] ?? null ) );
}
if ( null !== $before['retention_minimum_years'] ) {
	$chk( 'legal minimum retention of credit notes unchanged', $before['retention_minimum_years'] === $state['retention_minimum_years'], $before['retention_minimum_years'] . ' → ' . $state['retention_minimum_years'] );
}
if ( null !== $before['small_business'] ) {
	$chk( 'small business limits and warnings unchanged', $before['small_business'] === $state['small_business'], json_encode( $before['small_business'] ) . ' → ' . json_encode( $state['small_business'] ) );
}
exit( $fail ? 1 : 0 );
