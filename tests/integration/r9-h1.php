<?php
/**
 * R9 package H1: failed writes are reported (R6-QA8 rest), campaign report per partner (R7-PU2 rest).
 * Included from run.php (helpers check(), t() come from there).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Commissions\Balance;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\IntegrationException;
use WPAffiliateCaptain\Promotion\Creatives;
use WPAffiliateCaptain\Reports\Reports;

echo "R9 H1 failed writes, campaign report per partner\n";
global $wpdb;

$h1_code = static function ( $r ) {
	return is_wp_error( $r ) ? $r->get_error_code() : $r;
};
$h1_audits = static function ( $action, $object_id = 0 ) use ( $wpdb ) {
	$sql = 'SELECT COUNT(*) FROM ' . t( 'audit' ) . ' WHERE action = %s' . ( $object_id ? ' AND object_id = ' . (int) $object_id : '' );
	return (int) $wpdb->get_var( $wpdb->prepare( $sql, $action ) );
};
$h1_src = static function ( $file ) {
	return (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . $file );
};

/* R6-QA8: AffiliateRepository::update() --------------------------------------------------------- */
$h1_aff = AffiliateRepository::create( array( 'name' => 'H1 Partner', 'email' => 'h1-partner@example.de', 'status' => 'active', 'channel' => 'Blog' ) );
$h1_before = $h1_audits( 'affiliate.updated', $h1_aff );
$h1_r = AffiliateRepository::update( $h1_aff, array( 'name' => str_repeat( 'N', 191 ) ) );
AffiliateRepository::clear_cache( $h1_aff );
check( 'R6-QA8 partner name too long: clear error, nothing changed, no audit entry', array( 'wpac_too_long_name', 'H1 Partner', $h1_before ), array( $h1_code( $h1_r ), AffiliateRepository::find( $h1_aff )->name, $h1_audits( 'affiliate.updated', $h1_aff ) ) );
$h1_r = AffiliateRepository::update( $h1_aff, array( 'channel' => str_repeat( 'k', 256 ) ) );
check( 'R6-QA8 channel too long refused', 'wpac_too_long_channel', $h1_code( $h1_r ) );
$h1_r = AffiliateRepository::update( $h1_aff, array( 'landing_message' => str_repeat( 'w', AffiliateRepository::LANDING_MESSAGE_MAX + 1 ) ) );
check( 'R6-QA8 welcome message too long refused', 'wpac_too_long_landing_message', $h1_code( $h1_r ) );
// 190 multibyte characters fit (characters, not bytes, count).
$h1_r = AffiliateRepository::update( $h1_aff, array( 'name' => str_repeat( 'ä', 190 ) ) );
AffiliateRepository::clear_cache( $h1_aff );
check( 'R6-QA8 190 umlauts fit the name column', array( true, 190 ), array( $h1_r, mb_strlen( AffiliateRepository::find( $h1_aff )->name, 'UTF-8' ) ) );
AffiliateRepository::update( $h1_aff, array( 'name' => 'H1 Partner' ) );

// Database refuses the UPDATE (column renamed: no trigger needed, works for a non-SUPER user).
$h1_before = $h1_audits( 'affiliate.updated', $h1_aff );
$wpdb->query( 'ALTER TABLE ' . t( 'affiliates' ) . " CHANGE channel channel_blocked varchar(255) NOT NULL DEFAULT ''" );
$h1_r = AffiliateRepository::update( $h1_aff, array( 'channel' => 'Instagram' ) );
$wpdb->query( 'ALTER TABLE ' . t( 'affiliates' ) . " CHANGE channel_blocked channel varchar(255) NOT NULL DEFAULT ''" );
AffiliateRepository::clear_cache( $h1_aff );
check( 'R6-QA8 partner update database error: error instead of success, no audit entry', array( 'wpac_affiliate_db', 'Blog', $h1_before ), array( $h1_code( $h1_r ), AffiliateRepository::find( $h1_aff )->channel, $h1_audits( 'affiliate.updated', $h1_aff ) ) );

// Public change (name/website): checked before the review; an approval that cannot be saved stays pending.
$GLOBALS['test_mails'] = array();
$h1_r = AffiliateRepository::request_public_change( $h1_aff, str_repeat( 'N', 191 ), 'https://h1.example' );
check( 'R6-QA8 public change too long: error, nothing pending, no mail', array( 'wpac_too_long_name', null, array() ), array( $h1_code( $h1_r ), AffiliateRepository::pending_public_change( $h1_aff ), $GLOBALS['test_mails'] ) );
check( 'R6-QA8 public change of an approved partner still pending', 'pending', AffiliateRepository::request_public_change( $h1_aff, 'H1 Neu', 'https://h1.example' ) );
$GLOBALS['test_mails'] = array();
$wpdb->query( 'ALTER TABLE ' . t( 'affiliates' ) . " CHANGE website website_blocked varchar(255) NOT NULL DEFAULT ''" );
$h1_r = AffiliateRepository::resolve_public_change( $h1_aff, true );
$wpdb->query( 'ALTER TABLE ' . t( 'affiliates' ) . " CHANGE website_blocked website varchar(255) NOT NULL DEFAULT ''" );
AffiliateRepository::clear_cache( $h1_aff );
check( 'R6-QA8 approval not saved: error, change stays pending, partner not informed', array( 'wpac_affiliate_db', 'H1 Neu', 'H1 Partner', array() ), array( $h1_code( $h1_r ), AffiliateRepository::pending_public_change( $h1_aff )['name'] ?? null, AffiliateRepository::find( $h1_aff )->name, $GLOBALS['test_mails'] ) );
check( 'R6-QA8 approval saved afterwards', array( true, 'H1 Neu' ), array( AffiliateRepository::resolve_public_change( $h1_aff, true ), AffiliateRepository::find( $h1_aff )->name ) );
check(
	'R6-QA8 admin page and portal show an error instead of "saved"',
	array( true, true, true, true ),
	array(
		false !== strpos( $h1_src( 'src/Admin/Pages/AffiliatesPage.php' ), "if ( is_wp_error( \$saved ) ) {\n\t\t\t\t\tAdmin::back( \$saved->get_error_message(), 'error' );" ),
		false !== strpos( $h1_src( 'src/Admin/Pages/AffiliatesPage.php' ), "if ( is_wp_error( \$resolved ) ) {" ),
		false !== strpos( $h1_src( 'src/Portal/Portal.php' ), "'wpac_msg' => 'account_failed'" ),
		false !== strpos( $h1_src( 'src/Portal/Portal.php' ), 'name="wpac_acc_website" value="%2$s" maxlength="255"' ),
	)
);

/* R6-QA8: Creatives::save_draft() --------------------------------------------------------------- */
$h1_in = array( 'title' => 'H1 Banner', 'type' => 'text', 'content' => 'Jetzt buchen {link}', 'disclosure' => 'Werbung' );
check( 'R6-QA8 creative title too long: clear error', 'wpac_too_long_title', $h1_code( Creatives::sanitize( array( 'title' => str_repeat( 'T', 191 ) ) + $h1_in ) ) );
check( 'R6-QA8 creative disclosure too long: clear error', 'wpac_too_long_disclosure', $h1_code( Creatives::sanitize( array( 'disclosure' => str_repeat( 'W', 256 ) ) + $h1_in ) ) );
$h1_data   = Creatives::sanitize( $h1_in );
$h1_rows   = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'creatives' ) );
$h1_before = $h1_audits( 'creative.draft' );
$wpdb->query( 'ALTER TABLE ' . t( 'creatives' ) . " CHANGE disclosure disclosure_blocked varchar(255) NOT NULL DEFAULT ''" );
$h1_r = Creatives::save_draft( $h1_data, 0, 'neu' );
$wpdb->query( 'ALTER TABLE ' . t( 'creatives' ) . " CHANGE disclosure_blocked disclosure varchar(255) NOT NULL DEFAULT ''" );
check( 'R6-QA8 creative draft database error: error, no row, no audit entry', array( 'wpac_creative_db', $h1_rows, $h1_before ), array( $h1_code( $h1_r ), (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'creatives' ) ), $h1_audits( 'creative.draft' ) ) );
$h1_r = Creatives::save_draft( $h1_data, 0, 'neu' );
check( 'R6-QA8 creative draft saved: version ID, own family', array( true, $h1_r ), array( is_int( $h1_r ) && $h1_r > 0, (int) Creatives::find( $h1_r )->family_id ) );
check( 'R6-QA8 creatives page shows the error of save_draft()', true, false !== strpos( $h1_src( 'src/Admin/Pages/CreativesPage.php' ), "if ( is_wp_error( \$saved ) ) {" ) );

/* R6-QA8: Ledger::event() ----------------------------------------------------------------------- */
$h1_res = wpac_record_sale(
	array(
		'source'       => 'h1',
		'order_id'     => 'h1-1',
		'currency'     => 'EUR',
		'partner_code' => CodeRepository::main_code( $h1_aff ),
		'items'        => array( array( 'item_id' => '1', 'product_id' => 10, 'name' => 'Kurs H1', 'net_minor' => 10000 ) ),
		'customer'     => array( 'user_id' => 0, 'email' => 'h1-kunde@example.de' ),
	)
);
$h1_cid    = (int) ( $h1_res['commission_ids'][0] ?? 0 );
$h1_events = count( Ledger::events( $h1_cid ) );
$h1_bal    = Balance::get( $h1_aff, 'EUR' );
$wpdb->query( 'ALTER TABLE ' . t( 'commission_events' ) . " CHANGE reason reason_blocked varchar(255) NOT NULL DEFAULT ''" );
$h1_err = '';
try {
	Ledger::transition( $h1_cid, 'approved', 'H1' );
} catch ( IntegrationException $h1_e ) {
	$h1_err = $h1_e->error_code;
}
$wpdb->query( 'ALTER TABLE ' . t( 'commission_events' ) . " CHANGE reason_blocked reason varchar(255) NOT NULL DEFAULT ''" );
check(
	'R6-QA8 commission event not stored: error, status change and balance booking rolled back',
	array( 'db_commission_event', 'pending', $h1_events, $h1_bal ),
	array( $h1_err, Ledger::find( $h1_cid )->status, count( Ledger::events( $h1_cid ) ), Balance::get( $h1_aff, 'EUR' ) )
);
check( 'R6-QA8 commission event stored normally', array( true, true ), array( Ledger::event( $h1_cid, 'note', 'pending', 'pending', 0, 'H1' ), Ledger::transition( $h1_cid, 'approved', 'H1' ) ) );
check( 'R6-QA8 admin actions show a refused booking as an error notice', true, false !== strpos( $h1_src( 'src/Admin/Admin.php' ), "} catch ( \\WPAffiliateCaptain\\Core\\IntegrationException \$e ) {" ) );

/* R7-PU2: admin report by campaign is per partner ---------------------------------------------- */
$h1_b     = AffiliateRepository::create( array( 'name' => 'H1 Zweit', 'email' => 'h1-zweit@example.de', 'status' => 'active' ) );
$h1_today = wp_date( 'Y-m-d' );
foreach ( array( array( $h1_aff, 2 ), array( $h1_b, 3 ) ) as $h1_v ) {
	for ( $i = 0; $i < $h1_v[1]; $i++ ) {
		$wpdb->insert( t( 'visits' ), array( 'affiliate_id' => $h1_v[0], 'code_id' => 0, 'campaign' => 'h1-sommer', 'content_id' => 987001, 'created_at' => Db::now() ) );
	}
}
foreach ( array( array( $h1_aff, 'h1-a', 10000 ), array( $h1_b, 'h1-b', 30000 ) ) as $h1_s ) {
	wpac_record_sale(
		array(
			'source'   => 'h1',
			'order_id' => $h1_s[1],
			'currency' => 'EUR',
			'items'    => array( array( 'item_id' => '1', 'product_id' => 10, 'name' => 'Kurs H1', 'net_minor' => $h1_s[2] ) ),
			'customer' => array( 'user_id' => 0, 'email' => 'h1-' . $h1_s[1] . '@example.de' ),
			'touch'    => array( 'code' => CodeRepository::main_code( $h1_s[0] ), 'method' => 'link', 'ts' => time(), 'campaign' => 'h1-sommer', 'content_id' => 987001 ),
		)
	);
}
$h1_rows = array();
foreach ( Reports::by_dimension( 'campaign', $h1_today, $h1_today ) as $r ) {
	if ( 'h1-sommer' === $r['key'] ) {
		$h1_rows[ $r['partner'] ] = array( $r['affiliate_id'], $r['visits'], $r['orders'], $r['revenue'] );
	}
}
ksort( $h1_rows );
check( 'R7-PU2 same campaign name of two partners: one row per partner with its own numbers', array( 'H1 Neu' => array( $h1_aff, 2, 1, 10000 ), 'H1 Zweit' => array( $h1_b, 3, 1, 30000 ) ), $h1_rows );
if ( ! function_exists( 'get_the_title' ) ) {
	// The same stub as r8-render.php (it keeps an existing function).
	$GLOBALS['r_stub_fns']['get_the_title'] = static function ( $p = 0 ) { return 'Page ' . (int) ( is_object( $p ) ? $p->ID : $p ); };
	eval( 'function get_the_title( ...$a ) { return ( $GLOBALS["r_stub_fns"]["get_the_title"] )( ...$a ); }' );
}
$h1_content = array();
foreach ( Reports::by_dimension( 'content_id', $h1_today, $h1_today ) as $r ) {
	if ( '987001' === $r['key'] ) {
		$h1_content[] = array( $r['affiliate_id'], $r['visits'], $r['partners'], $r['orders'], $r['revenue'] );
	}
}
check( 'R7-PU2 content (shop page) still summed over all partners', array( array( 0, 5, 2, 2, 40000 ) ), $h1_content );
check( 'R7-PU2 dashboard and CSV show the partner of a campaign', array( true, true ), array( false !== strpos( $h1_src( 'src/Admin/Pages/DashboardPage.php' ), "esc_html( \$r['partner'] )" ), false !== strpos( $h1_src( 'src/Admin/Pages/DashboardPage.php' ), "array( 'affiliate_id', 'partner' )" ) ) );
