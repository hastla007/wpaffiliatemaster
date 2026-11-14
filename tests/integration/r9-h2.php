<?php
/**
 * Integration checks of work package H2: commissions that need review are visible to the
 * operator – menu count bubble, dashboard notice, optional hourly digest e-mail (R5-W58/R7-PU6).
 * Included from tests/integration/run.php (uses its check() helper and database).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Admin\Admin;
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Commissions\ReviewAlert;
use WPAffiliateCaptain\Core\Capabilities;
use WPAffiliateCaptain\Core\Settings;

echo "H2 review visibility\n";

$h2_root = dirname( __DIR__, 2 );
$h2_src  = static function ( $file ) use ( $h2_root ) {
	return (string) file_get_contents( $h2_root . '/' . $file );
};
$h2_set = static function ( array $values ) {
	update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), $values ) );
	Settings::flush_cache();
};
$GLOBALS['h2_sent'] = array();
add_action(
	'wpac_mail_sent',
	static function ( $type, $mail, $ok ) {
		if ( 'review_digest' === $type ) {
			$GLOBALS['h2_sent'][] = $mail;
		}
	},
	10,
	3
);
$h2_sale = static function ( $order, $code ) {
	return wpac_record_sale(
		array(
			'source'       => 'h2',
			'order_id'     => (string) $order,
			'currency'     => 'EUR',
			'partner_code' => $code,
			'review'       => true,
			'items'        => array( array( 'item_id' => '1', 'product_id' => 91, 'name' => 'Kurs H2', 'net_minor' => 10000 ) ),
			'customer'     => array( 'user_id' => 0, 'email' => 'h2-' . $order . '@example.de' ),
		)
	);
};
ReviewAlert::register_hooks();

$h2_aff  = AffiliateRepository::create( array( 'name' => 'Hanna Zwei', 'email' => 'hanna2@example.de', 'status' => 'active' ) );
$h2_code = CodeRepository::main_code( $h2_aff );
$h2_db   = static function () use ( $wpdb ) {
	return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'commissions' ) . " WHERE status = 'review'" );
};

// Count is cached and follows creation and status changes.
$h2_before = ReviewAlert::count( true );
check( 'R5-W58 review count matches the database', $h2_db(), $h2_before );
check( 'R5-W58 review count is cached', $h2_before, get_transient( ReviewAlert::CACHE ) );
$h2_r1 = $h2_sale( 'H2-1', $h2_code );
$h2_c1 = (int) $h2_r1['commission_ids'][0];
check( 'R5-W58 flagged sale is in review', 'review', Ledger::find( $h2_c1 )->status );
check( 'R5-W58 cache dropped when a commission enters review', array( false, $h2_before + 1 ), array( get_transient( ReviewAlert::CACHE ), ReviewAlert::count() ) );
Ledger::reject( $h2_c1, 'test' );
check( 'R5-W58 cache dropped on a status change out of review', array( false, $h2_before ), array( get_transient( ReviewAlert::CACHE ), ReviewAlert::count() ) );

// Menu bubble (WordPress "awaiting-mod" markup), only for users who can open the list.
$h2_r2 = $h2_sale( 'H2-2', $h2_code );
$h2_n  = ReviewAlert::count();
$h2_b  = Admin::review_bubble( $h2_n );
check( 'R5-W58 menu bubble with awaiting-mod markup and screen reader text', array( true, true, true ), array( false !== strpos( $h2_b, 'class="awaiting-mod count-' . $h2_n . '"' ), false !== strpos( $h2_b, '<span class="pending-count" aria-hidden="true">' . $h2_n . '</span>' ), false !== strpos( $h2_b, 'screen-reader-text' ) ) );
check( 'R5-W58 no bubble without commissions in review', '', Admin::review_bubble( 0 ) );
$GLOBALS['test_caps'] = array( 'read' );
check( 'R5-W58 no count for users without the partner capability', 0, ReviewAlert::count_for_user() );
$GLOBALS['test_caps'] = array( Capabilities::MANAGE_AFFILIATES );
check( 'R5-W58 count for users with the partner capability', $h2_n, ReviewAlert::count_for_user() );
unset( $GLOBALS['test_caps'] );
$h2_admin = $h2_src( 'src/Admin/Admin.php' );
check( 'R5-W58 bubble on the parent menu and the commissions item', 2, substr_count( $h2_admin, "'affiliate-captain' ) . \$bubble" ) );
check( 'R5-W58 dashboard notice links to the filtered list', true, false !== strpos( $h2_src( 'src/Admin/Pages/DashboardPage.php' ), "Admin::url( 'wpac-commissions', array( 'status' => 'review' ) )" ) );

// Digest e-mail: off by default, then at most one per hour for many commissions.
check( 'R5-W58 review e-mail off by default', 0, Settings::defaults()['notify_admin_review'] );
check( 'R5-W58 nothing collected while the e-mail is off', false, get_option( ReviewAlert::PENDING ) );
check( 'R5-W58 no digest while off', false, ReviewAlert::digest() );
$h2_set( array( 'notify_admin_review' => 1 ) );
delete_option( ReviewAlert::SENT );
for ( $i = 3; $i <= 7; $i++ ) {
	$h2_sale( 'H2-' . $i, $h2_code );
}
check( 'R5-W58 commissions entering review are collected', 5, (int) get_option( ReviewAlert::PENDING )['count'] );
check( 'R5-W58 hourly cron step sends the digest', true, false !== strpos( $h2_src( 'src/Core/Cron.php' ), "'review'     => array( '\\WPAffiliateCaptain\\Commissions\\ReviewAlert', 'digest' )" ) );
check( 'R5-W58 one digest for many commissions', array( true, 1 ), array( ReviewAlert::digest(), count( $GLOBALS['h2_sent'] ) ) );
$h2_m = $GLOBALS['h2_sent'][0];
check( 'R5-W58 digest names the counts and links to the filtered list', array( true, true, true ), array( false !== strpos( $h2_m['message'], '5 commissions entered' ), false !== strpos( $h2_m['message'], 'In total ' . ReviewAlert::count( true ) . ' commissions' ), false !== strpos( $h2_m['message'], 'page=wpac-commissions&status=review' ) ) );
check( 'R5-W58 marker cleared after the digest', false, get_option( ReviewAlert::PENDING ) );
$h2_sale( 'H2-8', $h2_code );
check( 'R5-W58 no second digest within the hour (kept for later)', array( false, 1, 1 ), array( ReviewAlert::digest(), count( $GLOBALS['h2_sent'] ), (int) get_option( ReviewAlert::PENDING )['count'] ) );
update_option( ReviewAlert::SENT, time() - HOUR_IN_SECONDS, false );
check( 'R5-W58 next digest after an hour', array( true, 2 ), array( ReviewAlert::digest(), count( $GLOBALS['h2_sent'] ) ) );
check( 'R5-W58 no digest without new commissions', false, ReviewAlert::digest() );

// Setting in the e-mails tab, cleaned on uninstall.
check( 'R5-W58 setting saved in the e-mails tab', array( true, true ), array( false !== strpos( $h2_src( 'src/Admin/Pages/SettingsPage.php' ), "'notify_commissions', 'notify_admin_review', 'report_admin'" ), false !== strpos( $h2_src( 'src/Admin/Pages/SettingsPage.php' ), "self::checkbox( 'notify_admin_review'" ) ) );
check( 'R5-W58 setting is a flag', 0, Settings::sanitize( array( 'notify_admin_review' => '' ) )['notify_admin_review'] ?? null );
check( 'R5-W58 options removed on uninstall', true, false !== strpos( $h2_src( 'uninstall.php' ), "'wpac_review_alert', 'wpac_review_alert_sent'" ) );
$h2_set( array( 'notify_admin_review' => 0 ) );
// Leave no commissions of this package in review for later checks.
foreach ( $wpdb->get_col( 'SELECT id FROM ' . t( 'commissions' ) . " WHERE source = 'h2' AND status = 'review'" ) as $h2_id ) {
	Ledger::reject( (int) $h2_id, 'test cleanup' );
}
