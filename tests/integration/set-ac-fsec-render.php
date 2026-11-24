<?php
/**
 * Fix round after the final review (fsec, P4-05): the program export with tax and bank data
 * asks for the password like the transfer list; after the confirmation the kept request runs
 * the export. Included after the render files (wp_die()/wp_safe_redirect() stubs of r12-c.php,
 * session stand-ins of r14-f3.php).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Admin\Pages\ImportExportPage;
use WPAffiliateCaptain\Admin\Reauth;
use WPAffiliateCaptain\Core\Settings;

echo "fsec render: program export with bank data needs the password\n";

if ( ! function_exists( 'check_admin_referer' ) ) {
	function check_admin_referer( $action = -1, $query_arg = '_wpnonce' ) { return 1; }
}

$fsr_get  = $_GET;
$fsr_post = $_POST;
$fsr_user = $GLOBALS['test_user'];
$fsr_caps = $GLOBALS['test_caps'] ?? null;
unset( $GLOBALS['test_caps'] ); // Administrator: every capability.
$fsr_keep = get_option( 'wpac_settings' );
update_option( 'wpac_settings', array_merge( (array) $fsr_keep, array( 'reauth_required' => 1 ) ) );
Settings::flush_cache();

$fsr_reset = static function () {
	$p = new ReflectionProperty( Reauth::class, 'confirmed_now' );
	$p->setAccessible( true );
	$p->setValue( null, false );
};
$fsr_uid = 914377;
$GLOBALS['test_users'][ $fsr_uid ] = (object) array( 'ID' => $fsr_uid, 'user_email' => 'admin' . $fsr_uid . '@example.de', 'display_name' => 'Admin', 'user_login' => 'admin' . $fsr_uid, 'user_pass' => md5( 'Geheim-123' ), 'roles' => array( 'administrator' ) );
$GLOBALS['test_user'] = $fsr_uid;
$GLOBALS['f3_token']  = 'tok-fsec';
WP_Session_Tokens::get_instance( $fsr_uid )->update( 'tok-fsec', array( 'expiration' => time() + DAY_IN_SECONDS ) );
$fsr_reset();

// Marks that the ZIP is being written (the handler would send it and exit afterwards).
$fsr_mark = static function ( $files, $tax ) {
	throw new R12C_Halt( 'export:' . ( $tax ? 'tax' : 'plain' ), 200 );
};
add_filter( 'wpac_program_export_files', $fsr_mark, 10, 2 );

$fsr_run = static function ( callable $fn, array $post ) use ( $fsr_reset ) {
	$fsr_reset();
	$_GET     = array();
	$_POST    = $post;
	$_REQUEST = $_POST;
	try {
		$fn();
		$r = 'no halt';
	} catch ( R12C_Halt $e ) {
		$r = $e->getMessage();
	}
	$fsr_reset();
	return $r;
};
$fsr_export = array( 'action' => 'wpac_export', '_wpac_nonce' => 'nonce' );

$fsr_r1 = $fsr_run( array( ImportExportPage::class, 'handle_export' ), $fsr_export + array( 'include_tax_data' => '1' ) );
$fsr_tk = preg_match( '/page=wpac-reauth.*wpac_reauth=([a-f0-9]{32})/', $fsr_r1, $fsr_m ) ? $fsr_m[1] : '';
$fsr_r2 = $fsr_run( array( ImportExportPage::class, 'handle_export' ), $fsr_export );
$fsr_kept = '' !== $fsr_tk ? get_transient( 'wpac_reauth_' . $fsr_tk ) : null;
check(
	'P4-05 export with tax and bank data: to the confirmation step first (kept request, action program_export_tax); without bank data no question',
	array( true, 'program_export_tax', 'export:plain' ),
	array( '' !== $fsr_tk, is_array( $fsr_kept ) ? $fsr_kept['action'] : '', $fsr_r2 )
);
$fsr_r3 = $fsr_run( array( Reauth::class, 'handle' ), array( 'action' => 'wpac_reauth', '_wpac_nonce' => 'nonce', 'wpac_reauth' => $fsr_tk, 'wpac_reauth_password' => 'Geheim-123' ) );
WP_Session_Tokens::get_instance( $fsr_uid )->update( 'tok-fsec', array( 'expiration' => time() + DAY_IN_SECONDS, Reauth::SESSION_KEY => time() - 60 ) );
$fsr_r4 = $fsr_run( array( ImportExportPage::class, 'handle_export' ), $fsr_export + array( 'include_tax_data' => '1' ) );
check( 'P4-05 after the password the kept export runs (with bank data); within the window it is not asked again', array( 'export:tax', 'export:tax' ), array( $fsr_r3, $fsr_r4 ) );

remove_filter( 'wpac_program_export_files', $fsr_mark, 10 );
update_option( 'wpac_settings', $fsr_keep );
Settings::flush_cache();
$_GET                 = $fsr_get;
$_POST                = $fsr_post;
$_REQUEST             = array_merge( $_GET, $_POST );
$GLOBALS['test_user'] = $fsr_user;
$GLOBALS['f3_token']  = '';
if ( null !== $fsr_caps ) {
	$GLOBALS['test_caps'] = $fsr_caps;
}
