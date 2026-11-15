<?php
/**
 * Audit round 12, UX/accessibility/i18n items (R12-K8, M3, M24–M28, N4–N8, N10).
 *
 * Included from run.php after r11-api-portal.php (needs the template stubs and r_render() of
 * r8-render.php and the data of the earlier files).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Admin\Admin;
use WPAffiliateCaptain\Admin\Pages\CommissionsPage;
use WPAffiliateCaptain\Admin\Pages\PayoutsPage;
use WPAffiliateCaptain\Admin\Pages\RulesPage;
use WPAffiliateCaptain\Admin\Tables\CreativesTable;
use WPAffiliateCaptain\Affiliates\Registration;
use WPAffiliateCaptain\Core\Capabilities;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Portal\Portal;

echo "R12 C: accessibility, i18n and admin wiring\n";
global $wpdb;

$r12c_root = dirname( __DIR__, 2 );
$r12c_src  = static function ( $f ) use ( $r12c_root ) { return (string) file_get_contents( $r12c_root . '/' . $f ); };
$r12c_get  = $_GET;
$r12c_post = $_POST;
$r12c_user = $GLOBALS['test_user'];
$r12c_caps = $GLOBALS['test_caps'] ?? null;

class R12C_Halt extends RuntimeException {}
if ( ! function_exists( 'wp_die' ) ) {
	function wp_die( $msg = '', $code = 0 ) { throw new R12C_Halt( (string) $msg, is_int( $code ) ? $code : 0 ); }
}
if ( ! function_exists( 'wp_safe_redirect' ) ) {
	function wp_safe_redirect( $url ) { throw new R12C_Halt( 'redirect:' . $url, 302 ); }
}
foreach ( array( 'wp_get_raw_referer' => 'return false;', 'wp_validate_redirect' => 'return (string) ( $a[0] ?: ( $a[1] ?? "" ) );' ) as $r12c_fn => $r12c_body ) {
	if ( ! function_exists( $r12c_fn ) ) {
		eval( 'function ' . $r12c_fn . '( ...$a ) { ' . $r12c_body . ' }' );
	}
}

/* R12-K8: broadcast_send is routed by dispatch() with the partner capability ------------------- */
$r12c_dispatch = static function ( $do, $caps ) {
	$GLOBALS['test_caps'] = $caps;
	$_POST                = array( 'do' => $do, '_wpac_nonce' => 'nonce', 'broadcast_subject' => '', 'broadcast_message' => '' );
	$_REQUEST             = $_POST;
	try {
		Admin::dispatch();
		return array( 'no halt', 0 );
	} catch ( R12C_Halt $e ) {
		return array( $e->getMessage(), $e->getCode() );
	}
};
$r12c_unknown = $r12c_dispatch( 'r12c_bogus', array( Capabilities::MANAGE_AFFILIATES ) );
$r12c_denied  = $r12c_dispatch( 'broadcast_send', array( Capabilities::MANAGE_PAYOUTS ) );
delete_transient( 'wpac_notice_' . get_current_user_id() );
$r12c_routed  = $r12c_dispatch( 'broadcast_send', array( Capabilities::MANAGE_AFFILIATES ) );
$r12c_notice  = get_transient( 'wpac_notice_' . get_current_user_id() );
check(
	'R12-K8 dispatch(): unknown action 400; broadcast_send needs MANAGE_AFFILIATES (403 without) and reaches its handler (empty subject: error notice, redirect)',
	array( 400, 403, 302, 'error', 'Please enter a subject and a text.' ),
	array( $r12c_unknown[1], $r12c_denied[1], $r12c_routed[1], is_array( $r12c_notice ) ? $r12c_notice['type'] : '', is_array( $r12c_notice ) ? $r12c_notice['message'] : '' )
);
$_POST    = $r12c_post;
$_REQUEST = array();
unset( $GLOBALS['test_caps'] ); // Administrator for the renders below.

/* R12-M3: no fixed reload after a payout export ------------------------------------------------- */
$r12c_js = $r12c_src( 'assets/js/admin.js' );
$_GET    = array( 'page' => 'wpac-payouts' );
$r12c    = r_render( array( PayoutsPage::class, 'render' ) );
check(
	'R12-M3 export: download cookie per token instead of a 2.5 s reload; "Refresh page" fallback; status box on the page',
	array( false, true, true, true, true, array(), '' ),
	array(
		false !== strpos( $r12c_js, '2500' ),
		false !== strpos( $r12c_js, "'wpac_dl_' + token" ) && false !== strpos( $r12c_js, "field.name = 'wpac_dl_token'" ),
		false !== strpos( $r12c_src( 'src/Admin/Pages/PayoutsPage.php' ), "'wpac_dl_' . \$token" ),
		false !== strpos( $r12c_js, 'data-wpac-download-refresh' ),
		false !== strpos( $r12c[0], 'class="wpac-download-status" role="status"' ) && false !== strpos( $r12c[0], 'data-wpac-download-refresh="Refresh page"' ),
		$r12c[1],
		$r12c[2],
	)
);
check( 'R12-N6 admin scroll boxes are focusable named regions', true, false !== strpos( $r12c_js, "box.setAttribute( 'tabindex', '0' )" ) && false !== strpos( $r12c_js, "box.setAttribute( 'role', 'region' )" ) && false !== strpos( $r12c_js, "box.setAttribute( 'aria-label', label )" ) );

/* R12-M24 / N5: labels and placeholders in the admin -------------------------------------------- */
$_GET = array( 'page' => 'wpac-commissions' );
$r12c = r_render( array( CommissionsPage::class, 'render' ) );
check(
	'R12-M24 commission filters "Source" and "Order" have labels',
	array( true, true, array(), '' ),
	array( false !== strpos( $r12c[0], '<label class="screen-reader-text" for="wpac-filter-source">' ) && false !== strpos( $r12c[0], 'id="wpac-filter-source" name="source"' ), false !== strpos( $r12c[0], 'for="wpac-filter-order"' ) && false !== strpos( $r12c[0], 'id="wpac-filter-order" name="order_id"' ), $r12c[1], $r12c[2] )
);
check( 'R12-N5 amount placeholders follow the locale (no hard-coded decimal comma)', array( false, false, 2 ), array( false !== strpos( $r12c_src( 'src/Admin/Pages/CommissionsPage.php' ), 'placeholder="25,00"' ), false !== strpos( $r12c_src( 'src/Admin/Pages/CommissionsPage.php' ), 'placeholder="-5,00"' ), substr_count( $r12c_src( 'src/Admin/Pages/CommissionsPage.php' ), 'number_format_i18n( ' ) >= 2 ? 2 : 0 ) );

// Groups: <label for>, IDs unique per group, own ID for each page dropdown.
$r12c_dd   = array();
$r12c_orig = $GLOBALS['r_stub_fns']['wp_dropdown_pages'] ?? null;
if ( null !== $r12c_orig ) {
	$GLOBALS['r_stub_fns']['wp_dropdown_pages'] = static function ( $a = array() ) use ( &$r12c_dd ) {
		$r12c_dd[] = $a['id'] ?? '';
		echo '<select name="' . ( $a['name'] ?? 'page_id' ) . '" id="' . ( $a['id'] ?? ( $a['name'] ?? 'page_id' ) ) . '"></select>';
		return '';
	};
}
$_GET = array( 'page' => 'wpac-rules', 'tab' => 'groups' );
$r12c = r_render( array( RulesPage::class, 'render' ) );
if ( null !== $r12c_orig ) {
	$GLOBALS['r_stub_fns']['wp_dropdown_pages'] = $r12c_orig;
}
$r12c_groups = WPAffiliateCaptain\Affiliates\Groups::all();
$r12c_g      = $r12c_groups ? (int) reset( $r12c_groups )->id : 0;
preg_match_all( '/\bid="([^"]+)"/', $r12c[0], $r12c_ids );
check(
	'R12-M24 group forms: labels with for=, unique IDs, one dropdown ID per group, German placeholder gone',
	array( true, array(), true, false, array(), '' ),
	array(
		$r12c_g > 0 && false !== strpos( $r12c[0], '<label for="wpac-group-' . $r12c_g . '-name">' ) && false !== strpos( $r12c[0], 'id="wpac-group-' . $r12c_g . '-name"' ) && false !== strpos( $r12c[0], 'aria-describedby="wpac-group-' . $r12c_g . '-fields-help"' ),
		array_values( array_diff_assoc( $r12c_ids[1], array_unique( $r12c_ids[1] ) ) ),
		count( $r12c_groups ) === count( array_unique( array_filter( $r12c_dd ) ) ),
		false !== strpos( $r12c[0], 'Bootsschule' ),
		$r12c[1],
		$r12c[2],
	)
);
$_GET = array( 'page' => 'wpac-rules', 'tab' => 'tiers' );
$r12c = r_render( array( RulesPage::class, 'render' ) );
check(
	'R12-M24 tier plan form: every control labelled, step format as visible help (aria-describedby), month field labelled',
	array( true, true, true, array(), '' ),
	array(
		4 === count( array_filter( array( 'scope', 'group', 'basis', 'mode' ), static function ( $f ) use ( $r12c ) { return false !== strpos( $r12c[0], 'for="wpac-tier-' . $f . '"' ) && false !== strpos( $r12c[0], 'id="wpac-tier-' . $f . '"' ); } ) ),
		false !== strpos( $r12c[0], '<label for="wpac-tier-steps">' ) && false !== strpos( $r12c[0], 'aria-describedby="wpac-tier-steps-help"' ) && false !== strpos( $r12c[0], 'id="wpac-tier-steps-help"' ),
		false !== strpos( $r12c[0], '<label for="wpac-tier-close-month">' ),
		$r12c[1],
		$r12c[2],
	)
);
$r12c_closed = (string) WPAffiliateCaptain\Core\Settings::get( 'tier_month_closed' );
check( 'R12-N5 last closed month shown as a localized month name, not Y-m', true, '' === $r12c_closed || ! preg_match( '/^\d{4}-\d{2}$/', $r12c_closed ) || false !== strpos( $r12c[0], gmdate( 'F Y', (int) strtotime( $r12c_closed . '-01 UTC' ) ) ) );

/* Partner area: profile, links, notices ------------------------------------------------------- */
$r12c_row = $wpdb->get_row( 'SELECT id, user_id, email FROM ' . t( 'affiliates' ) . " WHERE user_id > 0 AND status = 'active' ORDER BY id LIMIT 1" );
$GLOBALS['test_user'] = (int) $r12c_row->user_id;
if ( ! isset( $GLOBALS['test_users'][ (int) $r12c_row->user_id ] ) ) {
	$GLOBALS['test_users'][ (int) $r12c_row->user_id ] = (object) array( 'ID' => (int) $r12c_row->user_id, 'user_email' => (string) $r12c_row->email, 'display_name' => 'Partner', 'user_login' => 'p' . (int) $r12c_row->user_id, 'roles' => array( 'customer' ) );
}
$_GET = array( 'wpac_tab' => 'profile', 'wpac_msg' => 'saved' );
$r12c = r_render( array( Portal::class, 'render' ) );
$html = $r12c[0];
check(
	'R12-M25 autocomplete tokens on personal data (name, street-address, country, account holder)',
	array( true, true, true, true, array(), '' ),
	array(
		(bool) preg_match( '/id="wpac_legal_name"[^>]*autocomplete="name"/', $html ),
		false !== strpos( $html, 'name="wpac_address" rows="3" required autocomplete="street-address"' ),
		(bool) preg_match( '/id="wpac_country"[^>]*autocomplete="country"/', $html ),
		(bool) preg_match( '/id="wpac_account_holder"[^>]*autocomplete="name"/', $html ),
		$r12c[1],
		$r12c[2],
	)
);
check( 'R12-M28 both payout method sections are visible without JavaScript', array( true, true ), array( false !== strpos( $html, '<div class="wpac-method-sepa">' ), false !== strpos( $html, '<div class="wpac-method-manual">' ) ) );
check( 'R12-N4 success message is focusable for the announcement (portal.js focuses it)', array( true, true ), array( (bool) preg_match( '/role="status" id="wpac-portal-error" tabindex="-1" data-wpac-focus>/', $html ), false !== strpos( $r12c_src( 'assets/js/portal.js' ), "document.querySelector( '.wpac-notice[data-wpac-focus]' )" ) ) );
$_GET = array( 'wpac_tab' => 'account' );
$r12c = r_render( array( Portal::class, 'render' ) );
check( 'R12-M25 account form: name and website autocomplete', array( true, true ), array( (bool) preg_match( '/id="wpac_acc_name"[^>]*autocomplete="name"/', $r12c[0] ), false !== strpos( $r12c[0], 'name="wpac_acc_website"' ) && (bool) preg_match( '/name="wpac_acc_website"[^>]*autocomplete="url"/', $r12c[0] ) ) );

$_GET = array( 'wpac_tab' => 'links', 'target' => 'https://evil.example/' );
$r12c = r_render( array( Portal::class, 'render' ) );
check(
	'R12-N4 link generator: invalid target marked and linked to its message; N6 copy buttons hidden until JS shows them',
	array( true, true, true, false, array(), '' ),
	array(
		(bool) preg_match( '/name="target"[^>]*aria-invalid="true" aria-describedby="wpac-link-error"/', $r12c[0] ),
		false !== strpos( $r12c[0], 'role="alert" id="wpac-link-error"' ),
		false !== strpos( $r12c[0], 'class="wpac-copy-btn" hidden' ),
		(bool) preg_match( '/class="wpac-copy-btn" data-wpac-copy/', $r12c[0] ),
		$r12c[1],
		$r12c[2],
	)
);
check( 'R12-N6 portal.js reveals the copy buttons', true, false !== strpos( $r12c_src( 'assets/js/portal.js' ), "document.querySelectorAll( '.wpac-copy-btn[hidden]' )" ) );

/* R12-N5: no string concatenation in labels and percentages --------------------------------- */
$r12c_portal = $r12c_src( 'src/Portal/Portal.php' );
check( 'R12-N5 IBAN/payment details labels and percentages use placeholders', array( false, false, true, true ), array( false !== strpos( $r12c_portal, "__( 'stored', 'affiliate-captain' ) . ': '" ), false !== strpos( $r12c_portal, ", 1 ) . ' %'" ), false !== strpos( $r12c_portal, "__( 'IBAN (stored: %s)', 'affiliate-captain' )" ), false !== strpos( $r12c_portal, "__( '%s%%', 'affiliate-captain' )" ) ) );

/* R12-N8: period in the site time zone, public alt text, source strings ------------------------ */
$r12c_tz = get_option( 'timezone_string' );
update_option( 'timezone_string', 'Europe/Berlin' );
$r12c_now = gmmktime( 23, 30, 0, 10, 31, 2026 ); // 31 Oct 23:30 UTC = 1 Nov 00:30 in Berlin.
$r12c_p   = array( Portal::period( 'month', $r12c_now ), Portal::period( 'last_month', $r12c_now ), Portal::period( '7', $r12c_now ) );
update_option( 'timezone_string', (string) $r12c_tz );
check(
	'R12-N8 "this month" / "last month" / last 7 days follow the site time zone',
	array( array( 'month', '2026-11-01', '2026-11-01' ), array( 'last_month', '2026-10-01', '2026-10-31' ), array( '7', '2026-10-26', '2026-11-01' ) ),
	array( array_values( $r12c_p[0] ), array_values( $r12c_p[1] ), array_values( $r12c_p[2] ) )
);
update_post_meta( 9912, '_wp_attachment_image_alt', 'Segelkurs am See' );
check( 'R12-N8 embed alt text: media alt text, else the site name (never the internal title)', array( 'Segelkurs am See', 'Test Shop' ), array( Portal::embed_alt( 9912 ), Portal::embed_alt( 9913 ) ) );
check( 'R12-N6 new tabs announced', array( true, 2 ), array( false !== strpos( $r12c_portal, "esc_html__( '(opens in a new tab)', 'affiliate-captain' )" ), substr_count( $r12c_src( 'src/Affiliates/Registration.php' ), "esc_html__( '(opens in a new tab)', 'affiliate-captain' )" ) ) );
check( 'R12-N8 English source "after you have approved it"', array( false, true ), array( false !== strpos( $r12c_src( 'src/Affiliates/Notifications.php' ), 'after you approved it' ), false !== strpos( $r12c_src( 'src/Affiliates/Notifications.php' ), 'after you have approved it' ) ) );

/* R12-M26 / N7: registration errors per field, dead ends -------------------------------------- */
$GLOBALS['test_user'] = 0;
$r12c_inst = new ReflectionProperty( Registration::class, 'instances' );
$r12c_inst->setAccessible( true );
$r12c_inst->setValue( null, 0 );
$r12c_public = WPAffiliateCaptain\Affiliates\Groups::public_groups();
set_transient(
	'wpac_regkeep_R12cTokenAbcdefghijk',
	array(
		'values' => array( 'wpac_name' => '', 'wpac_email' => 'x' ),
		'field'  => 'wpac_name',
		'errors' => array( 'wpac_name' => 'Please enter your name or company.', 'wpac_email' => 'Please enter a valid e-mail address.' ),
	)
);
$_GET = array( 'wpac_err' => 'invalid', 'wpac_form' => 'R12cTokenAbcdefghijk' ) + ( $r12c_public ? array( 'wpac_group' => (string) (int) reset( $r12c_public )->id ) : array() );
$r12c = r_render( array( Registration::class, 'render' ) );
$html = $r12c[0];
check(
	'R12-M26 registration: every invalid field marked and linked to its message, list of errors with links, focus on the first field only',
	array( true, true, true, true, 1, array(), '' ),
	array(
		(bool) preg_match( '/id="wpac_name"[^>]*aria-invalid="true" aria-describedby="wpac-err-wpac_name"/', $html ) && false !== strpos( $html, 'id="wpac-err-wpac_name"' ),
		(bool) preg_match( '/id="wpac_email"[^>]*aria-invalid="true" aria-describedby="wpac-err-wpac_email"/', $html ) && false !== strpos( $html, 'id="wpac-err-wpac_email"' ),
		false !== strpos( $html, '<ul class="wpac-error-list"><li><a href="#wpac_name">Please enter your name or company.</a></li><li><a href="#wpac_email">' ),
		false !== strpos( $html, 'role="alert" id="wpac-form-error"' ),
		substr_count( $html, 'autofocus' ),
		$r12c[1],
		$r12c[2],
	)
);
check( 'R12-N7 back link to the partner type choice after choosing a group', (bool) $r12c_public, false !== strpos( $html, 'Choose another partner type' ) );
$r12c_inst->setValue( null, 0 );
$_GET = array( 'wpac_err' => 'exists' ) + ( $r12c_public ? array( 'wpac_group' => (string) (int) reset( $r12c_public )->id ) : array() );
$r12c = r_render( array( Registration::class, 'render' ) );
check( 'R12-N7 "account exists": links to log in and to reset the password; spam error explains what to do', array( true, true, true ), array( false !== strpos( $r12c[0], 'Log in to the partner area</a>' ), false !== strpos( $r12c[0], 'action=lostpassword' ), false !== strpos( $r12c_src( 'src/Affiliates/Registration.php' ), 'Please reload the page and send the form again' ) ) );
// Older kept state without the error list: the single field still points to the form message.
$r12c_inst->setValue( null, 0 );
set_transient( 'wpac_regkeep_R12cOldAbcdefghijklm', array( 'values' => array( 'wpac_email' => 'x' ), 'field' => 'wpac_email' ) );
$_GET = array( 'wpac_err' => 'invalid', 'wpac_form' => 'R12cOldAbcdefghijklm' ) + ( $r12c_public ? array( 'wpac_group' => (string) (int) reset( $r12c_public )->id ) : array() );
$r12c = r_render( array( Registration::class, 'render' ) );
check( 'R12-M26 older kept state (no error list): field marked and linked to the form message', true, (bool) preg_match( '/id="wpac_email"[^>]*aria-invalid="true" aria-describedby="wpac-form-error" autofocus/', $r12c[0] ) );

/* R12-M27: contrast ---------------------------------------------------------------------------- */
$r12c_css = $r12c_src( 'assets/css/portal.css' );
check(
	'R12-M27 error text on a light chip, negative amounts mixed with the text colour, muted text mixed towards grey, RTL disclosure like LTR',
	array( true, true, true, false, true ),
	array(
		false !== strpos( $r12c_css, '.wpac-field-error{color:#b71c1c;background:#fdecea' ),
		false !== strpos( $r12c_css, '.wpac-negative{color:color-mix(in srgb,#c62828 55%,currentColor)}' ),
		false !== strpos( $r12c_css, '--wpac-muted:color-mix(in srgb,currentColor 75%,#808080)' ),
		false !== strpos( $r12c_src( 'assets/css/portal-rtl.css' ), '#f9a825' ),
		false !== strpos( $r12c_src( 'assets/css/portal-rtl.css' ), 'border-right-color:#b26a00' ),
	)
);

/* R12-N10: creatives list primes the image caches; failed jobs counted --------------------------- */
$GLOBALS['r12c_primed'] = array();
if ( ! function_exists( '_prime_post_caches' ) ) {
	function _prime_post_caches( $ids, $terms = true, $meta = true ) { $GLOBALS['r12c_primed'][] = array( array_values( $ids ), $terms, $meta ); }
}
$wpdb->insert( t( 'creatives' ), array( 'family_id' => 0, 'version' => 1, 'title' => 'R12C Bild', 'type' => 'image', 'attachment_id' => 4711, 'content' => '', 'disclosure' => 'Werbung', 'status' => 'approved', 'created_at' => Db::now() ) );
$wpdb->update( t( 'creatives' ), array( 'family_id' => (int) $wpdb->insert_id ), array( 'id' => (int) $wpdb->insert_id ) );
$_GET       = array( 'page' => 'wpac-creatives', 's' => 'R12C' );
$r12c_table = new CreativesTable();
$r12c_table->prepare_items();
check( 'R12-N10 creatives list: attachment posts and meta loaded in one go', array( array( array( 4711 ), false, true ) ), $GLOBALS['r12c_primed'] );
check( 'R12-N10 dashboard counts failed jobs with COUNT (not the 100-row list)', array( true, false ), array( false !== strpos( $r12c_src( 'src/Admin/Pages/DashboardPage.php' ), "Jobs::count( 'failed' )" ), false !== strpos( $r12c_src( 'src/Admin/Pages/DashboardPage.php' ), "count( Jobs::query( 'failed' ) )" ) ) );

/* R12-M6 follow-up: admin dates of payout runs in the site time zone ----------------------------- */
check( 'R12-M6 payout preview and default period end use local dates', array( false, true ), array( false !== strpos( $r12c_src( 'src/Admin/Admin.php' ), "PayoutService::preview( gmdate( 'Y-m-d' )" ), false !== strpos( $r12c_src( 'src/Admin/Pages/PayoutsPage.php' ), "Db::local_month( -1 ) . '-01 00:00:00 UTC'" ) ) );

$_GET                 = $r12c_get;
$_POST                = $r12c_post;
$GLOBALS['test_user'] = $r12c_user;
if ( null !== $r12c_caps ) {
	$GLOBALS['test_caps'] = $r12c_caps;
}
