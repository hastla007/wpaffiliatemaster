<?php
/**
 * SET-AC-35 (suite standard for uninstall): "delete all data" is off by default, switching it on
 * needs the typed word LÖSCHEN / LOESCHEN / DELETE (any letter case, checked on the server),
 * switching off and saving other fields need no word, the value is not exported or imported,
 * and the settings page shows the confirmation field. Included from run.php after set-ac1-render.php.
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Core\SettingsSchema;
use WPAffiliateCaptain\Core\SettingsTransfer;

echo "SET-AC-35 uninstall: typed confirmation word, not exported\n";

$s35_saved = get_option( 'wpac_settings' );
SettingsSchema::flush();

check( 'SET-AC-35 default: data are kept', 0, SettingsSchema::defaults()['delete_on_uninstall'] );
check( 'SET-AC-35 words: LÖSCHEN/loeschen/Delete accepted, others not', array( true, true, true, true, false, false, false ), array( Settings::uninstall_word_ok( 'LÖSCHEN' ), Settings::uninstall_word_ok( ' löschen ' ), Settings::uninstall_word_ok( 'loeschen' ), Settings::uninstall_word_ok( 'Delete' ), Settings::uninstall_word_ok( '' ), Settings::uninstall_word_ok( 'ja' ), Settings::uninstall_word_ok( 'LOSCHEN' ) ) );

$s35_off = array_merge( Settings::all(), array( 'delete_on_uninstall' => 0 ) );
update_option( 'wpac_settings', $s35_off ); Settings::flush_cache();
$s35_c1 = SettingsTransfer::check( array( 'delete_on_uninstall' => 1 ), Settings::all() );
$s35_c2 = SettingsTransfer::check( array( 'delete_on_uninstall' => 1, 'delete_on_uninstall_confirm' => 'nein' ), Settings::all() );
$s35_c3 = SettingsTransfer::check( array( 'delete_on_uninstall' => 1, 'delete_on_uninstall_confirm' => 'löschen' ), Settings::all() );
check( 'SET-AC-35 switching on without / with a wrong word is rejected, with the word accepted', array( true, true, array( 'delete_on_uninstall' => 1 ) ), array( isset( $s35_c1['errors']['delete_on_uninstall'] ), isset( $s35_c2['errors']['delete_on_uninstall'] ), $s35_c3['values'] ) );

update_option( 'wpac_settings', array_merge( $s35_off, array( 'delete_on_uninstall' => 1 ) ) ); Settings::flush_cache();
$s35_c4 = SettingsTransfer::check( array( 'delete_on_uninstall' => 0 ), Settings::all() );
$s35_c5 = SettingsTransfer::check( array( 'hold_days' => 14 ), Settings::all() );
check( 'SET-AC-35 switching off and saving other fields need no word (an enabled value stays on)', array( array(), array( 'delete_on_uninstall' => 0 ), array(), 1 ), array( $s35_c4['errors'], $s35_c4['values'], $s35_c5['errors'], (int) Settings::sanitize( Settings::all() )['delete_on_uninstall'] ) );

$s35_export = SettingsTransfer::export( true );
$s35_prev   = SettingsTransfer::preview( array( 'format' => 1, 'settings' => array( 'delete_on_uninstall' => 1 ) ) );
check( 'SET-AC-35 not exported (also not with private values) and ignored by the import', array( false, array( 'delete_on_uninstall' ), array() ), array( array_key_exists( 'delete_on_uninstall', $s35_export['settings'] ), $s35_prev['ignored'], $s35_prev['values'] ) );

update_option( 'wpac_settings', $s35_off ); Settings::flush_cache();
SettingsSchema::flush();
$s35_get = $_GET;
$_GET    = array( 'page' => 'wpac-settings', 'tab' => 'data' );
$s35_r   = r_render( array( \WPAffiliateCaptain\Admin\Pages\SettingsPage::class, 'render' ) );
update_option( 'wpac_settings', array_merge( $s35_off, array( 'delete_on_uninstall' => 1 ) ) ); Settings::flush_cache();
$s35_r2  = r_render( array( \WPAffiliateCaptain\Admin\Pages\SettingsPage::class, 'render' ) );
$_GET    = $s35_get;
update_option( 'wpac_settings', $s35_off ); Settings::flush_cache();
check( 'SET-AC-35 page: confirmation field while off (labelled, described), none while on', array( true, true, false ), array( false !== strpos( $s35_r[0], 'name="wpac[delete_on_uninstall_confirm]"' ), false !== strpos( $s35_r[0], '<label for="wpac-delete_on_uninstall_confirm">' ), false !== strpos( $s35_r2[0], 'delete_on_uninstall_confirm' ) ) );
check( 'SET-AC-35 label of the suite standard', 'Delete all data when the plugin is deleted', SettingsSchema::label( 'delete_on_uninstall' ) );

update_option( 'wpac_settings', $s35_saved ); Settings::flush_cache();
SettingsSchema::flush();
