<?php
/**
 * SET-AC-25 render checks (accessibility of the settings page): every help text and field error is
 * linked with aria-describedby to an existing element, number fields carry min/max from the
 * register, required fields carry the attribute and a visible mark, add-on rows are groups with
 * their description linked. Included from run.php after the render files (r_render()).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Admin\Admin;
use WPAffiliateCaptain\Admin\Pages\SettingsPage;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Core\SettingsSchema;

echo "SET-AC-25 render: aria-describedby, limits and required marks\n";

$a11y_get  = $_GET;
$a11y_caps = $GLOBALS['test_caps'] ?? null;
unset( $GLOBALS['test_caps'] );
SettingsSchema::flush();
Settings::flush_cache();

$a11y_render = static function ( $tab ) {
	$_GET = array( 'page' => 'wpac-settings', 'tab' => $tab );
	$r    = r_render( array( SettingsPage::class, 'render' ) );
	return $r;
};
$a11y_xpath = static function ( $html ) {
	$doc  = new DOMDocument();
	$prev = libxml_use_internal_errors( true );
	$doc->loadHTML( '<?xml encoding="utf-8"?><html><body>' . $html . '</body></html>' );
	libxml_clear_errors();
	libxml_use_internal_errors( $prev );
	return new DOMXPath( $doc );
};
// Form field name of a key (amounts are entered as decimals; SET-AC-29 table).
$a11y_field = static function ( $key ) {
	return array( 'fraud_max_order_minor' => 'fraud_max_order', 'min_payout_minor' => 'min_payout', 'max_payout_minor' => 'max_payout', 'exchange_rates' => 'fx_rows][0][rate' )[ $key ] ?? $key;
};
// Broken references: aria-describedby tokens without an element of that id.
$a11y_broken = static function ( DOMXPath $x ) {
	$ids = array();
	foreach ( $x->query( '//*[@id]' ) as $n ) {
		$ids[ $n->getAttribute( 'id' ) ] = true;
	}
	$out = array();
	foreach ( $x->query( '//*[@aria-describedby]' ) as $n ) {
		foreach ( preg_split( '/\s+/', trim( $n->getAttribute( 'aria-describedby' ) ) ) as $t ) {
			if ( '' === $t || ! isset( $ids[ $t ] ) ) {
				$out[] = $n->getAttribute( 'name' ) . '→' . $t;
			}
		}
	}
	return $out;
};

$a11y_required  = SettingsSchema::required();
$a11y_problems  = array();
$a11y_broken_all = array();
$a11y_undescribed = array();
$a11y_limits     = array();
$a11y_req_wrong  = array();
$a11y_note       = array();
$a11y_money      = array();
$a11y_numbers    = 0;
$a11y_req_seen   = 0;
foreach ( SettingsPage::tab_keys() as $a11y_tab => $a11y_keys ) {
	$a11y_r = $a11y_render( $a11y_tab );
	if ( $a11y_r[1] || '' !== $a11y_r[2] ) {
		$a11y_problems[ $a11y_tab ] = array( $a11y_r[1], $a11y_r[2] );
	}
	$a11y_x          = $a11y_xpath( $a11y_r[0] );
	$a11y_broken_all = array_merge( $a11y_broken_all, $a11y_broken( $a11y_x ) );
	$a11y_has_req    = (bool) array_intersect( $a11y_keys, $a11y_required );
	if ( $a11y_has_req !== ( 1 === $a11y_x->query( '//p[@class="wpac-required-note"]' )->length ) ) {
		$a11y_note[] = $a11y_tab;
	}
	foreach ( $a11y_keys as $a11y_k ) {
		$a11y_f    = SettingsSchema::field( $a11y_k );
		$a11y_name = 'wpac[' . $a11y_field( $a11y_k ) . ']';
		$a11y_ctl  = $a11y_x->query( '//*[@name="' . $a11y_name . '"]' )->item( 0 );
		if ( ! $a11y_ctl ) {
			if ( 'i18n' !== $a11y_f['type'] && 'min_payout_currency' !== $a11y_k ) { // Per-language texts need a second language; the minimum per currency is a column of the rate table.
				$a11y_undescribed[] = $a11y_k . ' (no control)';
			}
			continue;
		}
		if ( '' === trim( $a11y_ctl->getAttribute( 'aria-describedby' ) ) ) {
			$a11y_undescribed[] = $a11y_k;
		}
		// Whole numbers (and the percentage fields): type number with min/max of the register.
		if ( 'int' === $a11y_f['type'] || in_array( $a11y_k, SettingsPage::PERCENT_KEYS, true ) ) {
			++$a11y_numbers;
			$a11y_lim  = SettingsPage::int_limits( $a11y_k );
			$a11y_reg  = array( SettingsSchema::int_min()[ $a11y_k ] ?? 0, in_array( $a11y_k, SettingsPage::PERCENT_KEYS, true ) ? 100 : ( SettingsSchema::int_max()[ $a11y_k ] ?? null ) );
			$a11y_have = array( $a11y_ctl->getAttribute( 'type' ), $a11y_ctl->getAttribute( 'min' ), $a11y_ctl->getAttribute( 'max' ), $a11y_ctl->getAttribute( 'step' ) );
			if ( array( 'number', (string) $a11y_reg[0], (string) $a11y_reg[1], '1' ) !== $a11y_have || $a11y_lim !== $a11y_reg ) {
				$a11y_limits[] = $a11y_k . ':' . implode( '/', $a11y_have );
			}
		}
		// Amounts stay text fields (decimal comma, thousands separators, ambiguity check when saving).
		if ( 'money' === $a11y_f['type'] && 'decimal' !== $a11y_ctl->getAttribute( 'inputmode' ) ) {
			$a11y_money[] = $a11y_k;
		}
		// Required: attribute and visible mark exactly for the keys saving rejects empty.
		$a11y_req   = in_array( $a11y_k, $a11y_required, true );
		$a11y_label = $a11y_x->query( '//label[@for="wpac-' . $a11y_field( $a11y_k ) . '"]' )->item( 0 );
		$a11y_mark  = $a11y_label && $a11y_x->query( './/span[@class="wpac-required"][@aria-hidden="true"]', $a11y_label )->length === 1;
		if ( $a11y_req !== $a11y_ctl->hasAttribute( 'required' ) || $a11y_req !== $a11y_mark ) {
			$a11y_req_wrong[] = $a11y_k;
		}
		$a11y_req_seen += $a11y_req ? 1 : 0;
	}
}
check( 'SET-AC-25 every tab renders without notices or errors', array(), $a11y_problems );
check( 'SET-AC-25 every aria-describedby id on every tab belongs to an existing element', array(), $a11y_broken_all );
check( 'SET-AC-25 every field of the register is linked to its help text (aria-describedby)', array(), $a11y_undescribed );
check( 'SET-AC-25 every int field (and the percentages) is type=number with min/max/step from the register', array( array(), true ), array( $a11y_limits, $a11y_numbers >= count( SettingsSchema::keys_of( 'int' ) ) ) );
check( 'SET-AC-25 amounts (money) stay decimal text fields (no min/max on text inputs), linked to their help', array(), $a11y_money );
check( 'SET-AC-25 required attribute and visible mark exactly on the required keys', array( array(), count( $a11y_required ) ), array( $a11y_req_wrong, $a11y_req_seen ) );
check( 'SET-AC-25 note "Fields marked with * are required" exactly on tabs with required fields', array(), $a11y_note );

// Field errors after a rejected save: linked to the field, together with the help text.
$a11y_row = static function ( $rows, $tab ) {
	if ( 'tracking' === $tab ) {
		$rows[] = array( 'id' => 'a11y_demo', 'tab' => 'tracking', 'title' => 'Demo add-on', 'text' => 'Demo help text', 'render' => static function () { echo '<label><input type="checkbox" name="a11y_demo" value="1"> Demo switch</label>'; } );
	}
	return $rows;
};
add_filter( 'wpac_settings_rows', $a11y_row, 10, 2 );
Admin::keep_form( 'settings', array( 'values' => array( 'referral_param' => '', 'cookie_days' => 'x' ), 'errors' => array( 'referral_param' => 'Please enter a parameter name.', 'cookie_days' => 'Please enter a whole number.', 'a11y_demo' => 'Demo error' ) ) );
$a11y_r = $a11y_render( 'tracking' );
$a11y_x = $a11y_xpath( $a11y_r[0] );
$a11y_attr = static function ( $xpath, $attr ) use ( &$a11y_x ) {
	$n = $a11y_x->query( $xpath )->item( 0 );
	return $n ? $n->getAttribute( $attr ) : null;
};
check(
	'SET-AC-25 field errors after saving: aria-invalid, aria-describedby = error + help, both elements exist; no role=alert at the field',
	array( 'true', 'wpac-err-referral_param wpac-desc-referral_param', 'wpac-err-cookie_days wpac-desc-cookie_days', array(), 0 ),
	array( $a11y_attr( '//*[@name="wpac[referral_param]"]', 'aria-invalid' ), $a11y_attr( '//*[@name="wpac[referral_param]"]', 'aria-describedby' ), $a11y_attr( '//*[@name="wpac[cookie_days]"]', 'aria-describedby' ), $a11y_broken( $a11y_x ), $a11y_x->query( '//p[@class="wpac-field-error"][@role="alert"]' )->length )
);
check(
	'SET-AC-25 add-on row: group with the row title as legend, described by its error and description (ids exist)',
	array( 'wpac-addon-err-a11y_demo wpac-addon-desc-a11y_demo', 'Demo add-on', 'Demo error', 'Demo help text' ),
	array( $a11y_attr( '//fieldset[@class="wpac-addon-field"]', 'aria-describedby' ), trim( (string) $a11y_x->query( '//fieldset[@class="wpac-addon-field"]/legend' )->item( 0 )->textContent ), trim( (string) $a11y_x->query( '//*[@id="wpac-addon-err-a11y_demo"]' )->item( 0 )->textContent ), trim( (string) $a11y_x->query( '//*[@id="wpac-addon-desc-a11y_demo"]' )->item( 0 )->textContent ) )
);
// The next render shows no stale error (errors are read once).
$a11y_x = $a11y_xpath( $a11y_render( 'tracking' )[0] );
check( 'SET-AC-25 errors are shown once: the next render has no aria-invalid and the add-on group only its description', array( 0, 'wpac-addon-desc-a11y_demo' ), array( $a11y_x->query( '//*[@aria-invalid]' )->length, $a11y_attr( '//fieldset[@class="wpac-addon-field"]', 'aria-describedby' ) ) );
remove_filter( 'wpac_settings_rows', $a11y_row, 10 );

// Checkbox error (last payout method) is shown and linked at the checkbox.
Admin::keep_form( 'settings', array( 'values' => array( 'payout_method_sepa' => '' ), 'errors' => array( 'payout_method_sepa' => 'At least one payout method must stay on.' ) ) );
$a11y_x = $a11y_xpath( $a11y_render( 'payouts' )[0] );
check( 'SET-AC-25 checkbox error linked at the checkbox', array( 'wpac-err-payout_method_sepa wpac-desc-payout_method_sepa', 1, array() ), array( $a11y_attr( '//*[@name="wpac[payout_method_sepa]"]', 'aria-describedby' ), $a11y_x->query( '//*[@id="wpac-err-payout_method_sepa"]' )->length, $a11y_broken( $a11y_x ) ) );

$_GET = $a11y_get;
if ( null !== $a11y_caps ) {
	$GLOBALS['test_caps'] = $a11y_caps;
}
