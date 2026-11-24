<?php
/**
 * SET-AC-08a on the settings page (after the render files: template stubs): section choice in the
 * export form, preview of the sections of a checked import with one checkbox per section, and the
 * Apply button also when no setting changes. Uses the stubs of set-ac-xfer.php.
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Admin\Pages\ImportExportPage;
use WPAffiliateCaptain\Admin\Pages\SettingsPage;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Core\SettingsTransfer;

echo "SET-AC-08a render: settings page export and import preview of the sections\n";

$xfr_file = array(
	'format'   => 1,
	'settings' => array( 'hold_days' => (int) Settings::get( 'hold_days' ) ),
	'sections' => array(
		'groups' => array( 'items' => array( array( 'name' => 'Xfer Render Gruppe', 'description' => 'neu' ) ) ),
		'rules'  => array(
			'currency' => 'EUR',
			'items'    => array( array( 'scope' => 'group', 'group' => 'Gibt es nicht', 'target' => 'all', 'rate_type' => 'percent', 'rate' => '5' ) ),
		),
	),
);
$xfr_check = SettingsTransfer::preview( $xfr_file );
set_transient( 'wpac_settings_pending_' . get_current_user_id(), array( 'kind' => 'import', 'tab' => '', 'check' => $xfr_check ), 3600 );
$_GET  = array( 'page' => 'wpac-settings', 'tab' => 'data' );
$xfr_r = r_render( array( SettingsPage::class, 'render' ) );
delete_transient( 'wpac_settings_pending_' . get_current_user_id() );
$xfr_e = r_render( array( SettingsPage::class, 'render' ) );
check(
	'SET-AC-08a page: export offers the four sections; the import preview lists new and rejected items per section, a checkbox per section bound to the apply form, Apply without setting changes',
	array( 4, true, true, true, true, true, false, array(), '' ),
	array(
		substr_count( $xfr_e[0], 'name="sections[]"' ),
		false !== strpos( $xfr_r[0], 'id="wpac-settings-apply"' ) && false !== strpos( $xfr_r[0], 'value="settings_import_apply"' ),
		false !== strpos( $xfr_r[0], 'name="import_sections[]" value="groups" form="wpac-settings-apply" checked' ),
		false !== strpos( $xfr_r[0], '<td>New</td><td>Xfer Render Gruppe</td>' ),
		false !== strpos( $xfr_r[0], '<td>Not imported</td>' ) && false !== strpos( $xfr_r[0], 'Gibt es nicht' ),
		false !== strpos( $xfr_r[0], 'name="import_sections_shown" value="1" form="wpac-settings-apply"' ),
		false !== strpos( $xfr_r[0], 'name="import_sections[]" value="rules"' ),
		array_merge( $xfr_r[1], $xfr_e[1] ),
		$xfr_r[2] . $xfr_e[2],
	)
);
$_GET  = array( 'page' => ImportExportPage::SLUG, 'tab' => 'export' );
$xfr_i = r_render( array( ImportExportPage::class, 'render' ) );
check( 'SET-AC-08a import & export page points to the settings export for the configuration', array( true, array(), '' ), array( false !== strpos( $xfr_i[0], 'tab=data' ) && false !== strpos( $xfr_i[0], 'use the settings export' ), $xfr_i[1], $xfr_i[2] ) );
$_GET = array();
