<?php
/**
 * SET-AC-25 (accessibility of the settings page), register part: the required keys match what
 * saving rejects when empty, every whole number has limits in the register. Included from run.php
 * before r8-render.php; the render checks are in set-ac-a11y-render.php.
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Admin\Pages\SettingsPage;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Core\SettingsSchema;

echo "SET-AC-25 settings register: required keys and limits\n";

SettingsSchema::flush();
$a11y_core     = SettingsSchema::core();
$a11y_required = SettingsSchema::required();
check( 'SET-AC-25 required keys are core keys on a tab', array(), array_values( array_diff( $a11y_required, array_keys( SettingsSchema::defaults() ) ) ) );

// Saving rejects an empty value of every required key …
$a11y_not_rejected = array();
foreach ( $a11y_required as $a11y_k ) {
	Settings::sanitize( array( $a11y_k => '' ), $a11y_err );
	if ( ! isset( $a11y_err[ $a11y_k ] ) ) {
		$a11y_not_rejected[] = $a11y_k;
	}
}
check( 'SET-AC-25 every required key is rejected empty when saving (mark = behaviour)', array(), $a11y_not_rejected );

// … and accepts an empty value of every other text and whole-number field.
$a11y_rejected = array();
foreach ( $a11y_core as $a11y_k => $a11y_f ) {
	if ( in_array( $a11y_k, $a11y_required, true ) || ! in_array( $a11y_f['type'], array( 'text', 'int', 'textarea', 'email' ), true ) || ! empty( $a11y_f['hidden'] ) ) {
		continue;
	}
	Settings::sanitize( array( $a11y_k => '' ), $a11y_err );
	if ( isset( $a11y_err[ $a11y_k ] ) ) {
		$a11y_rejected[] = $a11y_k;
	}
}
check( 'SET-AC-25 no other text or number field is rejected empty (no missing required mark)', array(), $a11y_rejected );

// Every whole number has an upper limit in the register; percentages end at 100.
$a11y_no_max = array();
foreach ( SettingsSchema::keys_of( 'int' ) as $a11y_k ) {
	if ( ! isset( SettingsSchema::int_max()[ $a11y_k ] ) ) {
		$a11y_no_max[] = $a11y_k;
	}
}
check( 'SET-AC-25 every int key has a max in the register', array(), $a11y_no_max );
check(
	'SET-AC-25 field limits: register min/max, percentages 0–100, no min = 0',
	array( array( 7, 60 ), array( 0, 100 ), array( 0, 100 ), array( 0, 3650 ) ),
	array( SettingsPage::int_limits( 'dunning_days' ), SettingsPage::int_limits( 'fraud_max_refund_rate' ), SettingsPage::int_limits( 'visit_log_percent' ), SettingsPage::int_limits( 'cookie_days' ) )
);
