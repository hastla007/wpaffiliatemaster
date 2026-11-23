<?php
/**
 * SET-AC-13 / SET-AC-29 on the settings page: exchange rate table, "as of" date, dunning fields
 * with the "Check legally" mark. Included from run.php after r8-render.php (template stubs).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Core\SettingsSchema;

echo "SET-AC-13 / SET-AC-29 settings page\n";

$fx_saved = get_option( 'wpac_settings' );
$fx_set   = static function ( array $values ) use ( $fx_saved ) {
	update_option( 'wpac_settings', array_merge( is_array( $fx_saved ) ? $fx_saved : array(), $values ) );
	Settings::flush_cache();
};
SettingsSchema::flush();

// ---------------------------------------------------------------- Settings page.
$fx_render = static function ( $tab ) {
	$get  = $_GET;
	$_GET = array( 'page' => 'wpac-settings', 'tab' => $tab );
	$r    = r_render( array( WPAffiliateCaptain\Admin\Pages\SettingsPage::class, 'render' ) );
	$_GET = $get;
	return '' === $r[2] && ! $r[1] ? $r[0] : implode( ' ', $r[1] ) . $r[2];
};
$fx_caps = $GLOBALS['test_caps'] ?? null;
unset( $GLOBALS['test_caps'] );
$fx_set( array( 'currency' => 'EUR', 'exchange_rates' => "USD=0.92\nCHF=1.05", 'exchange_rates_date' => '2026-03-01', 'min_payout_currency' => "USD=50.00\nGBP=40.00" ) );
$fx_html = $fx_render( 'commissions' );
check(
	'SET-AC-29 page: table with one row per currency (GBP only with a minimum) plus an empty row, add/remove buttons, template, date shown read-only, no text field any more',
	array( true, true, true, true, true, true, true, true, false ),
	array(
		false !== strpos( $fx_html, 'name="wpac[fx_rows][0][currency]" value="USD"' ),
		false !== strpos( $fx_html, 'name="wpac[fx_rows][0][min]" value="50.00"' ),
		false !== strpos( $fx_html, 'name="wpac[fx_rows][2][currency]" value="GBP"' ),
		false !== strpos( $fx_html, 'name="wpac[fx_rows][3][currency]" value=""' ),
		false !== strpos( $fx_html, 'data-wpac-row-add' ) && false !== strpos( $fx_html, 'data-wpac-row-remove' ),
		false !== strpos( $fx_html, '<template data-wpac-row-template>' ) && false !== strpos( $fx_html, 'wpac[fx_rows][__N__][rate]' ),
		false !== strpos( $fx_html, 'readonly id="wpac-exchange_rates_date" name="wpac[exchange_rates_date]" value="2026-03-01"' ),
		false !== strpos( $fx_html, 'Rate (1 unit = x EUR)' ),
		false !== strpos( $fx_html, '<textarea id="wpac-exchange_rates"' ),
	)
);
$fx_html = $fx_render( 'payouts' );
$fx_legal = 0;
foreach ( array( 'dunning_levels', 'dunning_days', 'dunning_final_days' ) as $fx_k ) {
	if ( preg_match( '/name="wpac\[' . $fx_k . '\]".*?wpac-legal-mark/s', $fx_html, $fx_m ) && false === strpos( $fx_m[0], '</tr>' ) ) {
		$fx_legal++;
	}
}
check( 'SET-AC-13 page: the three dunning fields on the Payouts tab, each with the "Check legally" mark and the lower limit', array( 3, true ), array( $fx_legal, false !== strpos( $fx_html, 'min="7" step="1" id="wpac-dunning_days"' ) ) );
if ( null !== $fx_caps ) {
	$GLOBALS['test_caps'] = $fx_caps;
}

$fx_set( array() );
SettingsSchema::flush();
