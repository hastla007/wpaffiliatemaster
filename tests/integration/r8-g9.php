<?php
/**
 * Integration checks of work package G9: partner portal UX and accessibility – sub-ID and campaign
 * report, statistics (period, conversion, monthly earnings), currencies, link generator, pager,
 * mail preferences and broadcast mail, login redirect, account link resend, creatives.
 * Included from tests/integration/run.php (uses its check() helper and database).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Affiliates\Notifications;
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Portal\Portal;
use WPAffiliateCaptain\Reports\Reports;
use WPAffiliateCaptain\Tracking\Tracker;

echo "G9 partner portal\n";

$g9_root = dirname( __DIR__, 2 );
$g9_src  = static function ( $file ) use ( $g9_root ) {
	return (string) file_get_contents( $g9_root . '/' . $file );
};
$g9_sale = static function ( $order, $aff_code, $net, array $touch = array(), $currency = 'EUR' ) {
	$p = array(
		'source'   => 'g9',
		'order_id' => (string) $order,
		'currency' => $currency,
		'items'    => array( array( 'item_id' => '1', 'product_id' => 77, 'name' => 'Kurs G9', 'net_minor' => $net ) ),
		'customer' => array( 'user_id' => 0, 'email' => 'g9-' . $order . '@example.de' ),
	);
	if ( $touch ) {
		$p['touch'] = $touch + array( 'code' => $aff_code, 'method' => 'link', 'ts' => time() );
	} else {
		$p['partner_code'] = $aff_code;
	}
	return wpac_record_sale( $p );
};

$g9_aff  = AffiliateRepository::create( array( 'name' => 'Gina Neun', 'email' => 'gina9@example.de', 'status' => 'active' ) );
$g9_a    = AffiliateRepository::find( $g9_aff );
$g9_code = CodeRepository::main_code( $g9_aff );
$g9_oth  = AffiliateRepository::create( array( 'name' => 'Otto Neun', 'email' => 'otto9@example.de', 'status' => 'active' ) );
$g9_ocode = CodeRepository::main_code( $g9_oth );

// R7-AD16 / R7-PU2: sub-ID parameter – cleaning, cookie with sub-ID and visit ID, old cookies.
check( 'R7-AD16 sub-ID parameter default "sub"', 'sub', Tracker::sub_param() );
check( 'R7-AD16 sub-ID cleaned (allowed characters, 100 characters)', array( 'ig-story_12:ab', 100 ), array( Tracker::clean_sub( 'ig-story_12:a <b>"' ), strlen( Tracker::clean_sub( str_repeat( 'x', 150 ) ) ) ) );
$g9_cookie = Tracker::cookie_value( array( 'code' => $g9_code, 'campaign' => 'herbst', 'content_id' => 5, 'ts' => time(), 'sub_id' => 'post-7', 'visit_id' => 42 ) );
$g9_parsed = Tracker::parse_cookie( $g9_cookie );
check( 'R7-AD16 cookie carries sub-ID and visit ID (signed)', array( $g9_code, 'herbst', 'post-7', 42 ), array( $g9_parsed['code'] ?? null, $g9_parsed['campaign'] ?? null, $g9_parsed['sub_id'] ?? null, $g9_parsed['visit_id'] ?? null ) );
check( 'R7-AD16 cookie without sub-ID keeps the five-part format', 5, count( explode( '|', Tracker::cookie_value( array( 'code' => $g9_code, 'campaign' => '', 'content_id' => 0, 'ts' => time() ) ) ) ) );
check( 'R7-AD16 forged sub-ID breaks the signature', null, Tracker::parse_cookie( str_replace( '|post-7|', '|post-8|', $g9_cookie ) ) );

// Sale with a touch: sub-ID and visit ID are stored on the attribution.
$wpdb->insert( t( 'visits' ), array( 'affiliate_id' => $g9_aff, 'code_id' => 0, 'campaign' => 'herbst', 'sub_id' => 'post-7', 'created_at' => Db::now() ) );
$g9_visit = (int) $wpdb->insert_id;
$g9_sale( 'g9-1', $g9_code, 10000, array( 'campaign' => 'herbst', 'sub_id' => 'post-7', 'visit_id' => $g9_visit ) );
$g9_attr = $wpdb->get_row( "SELECT campaign, sub_id, visit_id FROM " . t( 'attributions' ) . " WHERE source = 'g9' AND source_order_id = 'g9-1'" );
check( 'R7-AD16 attribution stores campaign, sub-ID and visit ID', array( 'herbst', 'post-7', $g9_visit ), array( $g9_attr->campaign ?? null, $g9_attr->sub_id ?? null, (int) ( $g9_attr->visit_id ?? 0 ) ) );
check( 'R7-AD16 wpac_current_touch() contract documents sub_id and visit_id', true, false !== strpos( $g9_src( 'src/functions.php' ), "'sub_id'     => isset( \$touch['sub_id'] )" ) );
check( 'R7-AD16 beacon sends the sub-ID, REST route accepts it', array( true, true ), array( false !== strpos( Tracker::beacon_script( array( 'url' => 'x', 'param' => 'partner', 'sub' => 'sub' ) ), 'sub:q.get(c.sub||"sub")' ), false !== strpos( $g9_src( 'src/Rest/RestApi.php' ), "'sub'        => array( 'type' => 'string' )" ) ) );

// More visits and sales: same campaign name at another partner must not mix (R7-PU2).
foreach ( array( array( 'herbst', 'post-7' ), array( 'herbst', '' ), array( '', '' ) ) as $v ) {
	$wpdb->insert( t( 'visits' ), array( 'affiliate_id' => $g9_aff, 'code_id' => 0, 'campaign' => $v[0], 'sub_id' => $v[1], 'created_at' => Db::now() ) );
}
$wpdb->insert( t( 'visits' ), array( 'affiliate_id' => $g9_oth, 'code_id' => 0, 'campaign' => 'herbst', 'sub_id' => 'post-7', 'created_at' => Db::now() ) );
$g9_sale( 'g9-o1', $g9_ocode, 50000, array( 'campaign' => 'herbst', 'sub_id' => 'post-7' ) );
$g9_today = gmdate( 'Y-m-d' );
$g9_rows  = array();
foreach ( Reports::partner_campaigns( $g9_aff, $g9_today, $g9_today ) as $r ) {
	$g9_rows[ $r['campaign'] . '/' . $r['sub_id'] ] = array( $r['visits'], $r['orders'], $r['commission'] );
}
ksort( $g9_rows );
check( 'R7-PU2 partner report by campaign and sub-ID (own visits and orders only)', array( '/' => array( 1, 0, array() ), 'herbst/' => array( 1, 0, array() ), 'herbst/post-7' => array( 2, 1, array( 'EUR' => 1000 ) ) ), $g9_rows );
check( 'R7-PU2 conversion per campaign/sub-ID', 50.0, Reports::partner_campaigns( $g9_aff, $g9_today, $g9_today )[0]['conversion'] );
check( 'R7-PU2 campaign without name gets a label', '(no campaign)', Reports::campaign_label( '' ) );
$wpdb->insert( t( 'creatives' ), array( 'family_id' => 0, 'version' => 3, 'title' => 'Herbstbanner', 'type' => 'text', 'content' => 'x {link}', 'status' => 'approved', 'disclosure' => 'Werbung', 'created_at' => Db::now() ) );
$g9_cr = (int) $wpdb->insert_id;
$wpdb->update( t( 'creatives' ), array( 'family_id' => $g9_cr ), array( 'id' => $g9_cr ) );
check( 'R7-PU2 creative key shown with its title', 'Creative "Herbstbanner" (v3)', Reports::campaign_label( 'creative-' . $g9_cr . '-v3' ) );

// R7-PU7: creative links carry the sub-ID; embed code with size, alt text and rel="sponsored".
$g9_creative = \WPAffiliateCaptain\Promotion\Creatives::find( $g9_cr );
$g9_clink    = \WPAffiliateCaptain\Promotion\Creatives::link( $g9_creative, $g9_code, 'story 1' );
check( 'R7-PU7 creative link with sub-ID of the partner', true, false !== strpos( $g9_clink, 'sub=story1' ) && false !== strpos( $g9_clink, 'utm_campaign=creative-' . $g9_cr . '-v3' ) );
check( 'R7-PU7 personalized text uses the link with sub-ID', true, false !== strpos( \WPAffiliateCaptain\Promotion\Creatives::personalize( $g9_creative, $g9_code, 'Gina', 'a1' ), 'sub=a1' ) );
check( 'R7-PU7 embed code with link, size and alt text', '<a href="http://example.test/?partner=X" rel="sponsored noopener" target="_blank"><img src="http://example.test/b.png" width="300" height="250" alt="Banner &quot;A&quot;"></a>', Portal::embed_code( 'http://example.test/?partner=X', 'http://example.test/b.png', 300, 250, 'Banner "A"' ) );

// R7-PU3: statistics – sales without rejected and fully refunded orders, conversion, earnings per month.
$g9_sale( 'g9-2', $g9_code, 20000 );
$g9_sale( 'g9-3', $g9_code, 30000 );
$g9_sale( 'g9-4', $g9_code, 40000, array(), 'USD' );
$g9_rej = (int) $wpdb->get_var( "SELECT id FROM " . t( 'commissions' ) . " WHERE source = 'g9' AND source_order_id = 'g9-2' AND kind = 'sale'" );
Ledger::reject( $g9_rej, 'test' );
wpac_record_refund( array( 'source' => 'g9', 'order_id' => 'g9-3', 'refund_id' => 'g9-r3', 'type' => 'refund', 'reason' => 'test', 'full' => true ) );
$g9_sum = Reports::partner_summary( $g9_aff, $g9_today, $g9_today );
check( 'R7-PU3 sales count neither rejected nor fully refunded orders', 2, $g9_sum['sales'] );
check( 'R7-PU3 conversion rate = sales / visits', array( 4, 50.0 ), array( $g9_sum['visits'], $g9_sum['conversion'] ) );
check( 'R7-PU3 / R7-PU1 earnings per booking currency (refund corrected, USD separate)', array( 'EUR' => 1000, 'USD' => 4000 ), $g9_sum['earned'] );
$g9_months = Reports::partner_months( $g9_aff, 12 );
check( 'R7-PU3 monthly table: 12 months, current month first with sales and earnings', array( 12, gmdate( 'Y-m' ), 2, array( 'EUR' => 1000, 'USD' => 4000 ) ), array( count( $g9_months ), key( $g9_months ), $g9_months[ gmdate( 'Y-m' ) ]['sales'], $g9_months[ gmdate( 'Y-m' ) ]['earned'] ) );
$g9_now = strtotime( '2026-03-15 12:00:00 UTC' );
check( 'R7-PU3 periods', array( array( '7', '2026-03-09', '2026-03-15' ), array( 'month', '2026-03-01', '2026-03-15' ), array( 'last_month', '2026-02-01', '2026-02-28' ), array( '30', '2026-02-14', '2026-03-15' ) ), array( array_values( Portal::period( '7', $g9_now ) ), array_values( Portal::period( 'month', $g9_now ) ), array_values( Portal::period( 'last_month', $g9_now ) ), array_values( Portal::period( 'bogus', $g9_now ) ) ) );
check( 'R7-PU3 statistics tab exists and can be hidden like the other core tabs', true, false !== strpos( $g9_src( 'src/Portal/Portal.php' ), "array( 'overview', 'statistics', 'links', 'promotion', 'commissions', 'payouts' ) as \$key" ) );

// R7-PU1 / R7-PU6: amounts in every currency; "being reviewed" separate from "pending".
$g9_tot = Ledger::totals( array( 'affiliate_id' => $g9_aff ) );
check( 'R7-PU1 sums per currency (USD sale visible)', true, isset( Portal::sum_statuses( $g9_tot, array( 'pending' ) )['USD'] ) );
check( 'R7-PU1 money list: program currency first, other currencies after it', true, 0 === strpos( Portal::money_list( array( 'USD' => 100, 'EUR' => 200 ) ), WPAffiliateCaptain\Core\Money::format( 200, 'EUR' ) ) && false !== strpos( Portal::money_list( array( 'USD' => 100, 'EUR' => 200 ) ), ' · ' ) );
check( 'R7-PU1 REST /me returns all currencies', true, false !== strpos( $g9_src( 'src/Rest/RestApi.php' ), "'totals_by_currency'" ) );
check( 'R7-PU6 "available from" only for the hold period, review without a date', true, false !== strpos( $g9_src( 'src/Portal/Portal.php' ), "if ( 'pending' === \$r->status && \$r->hold_until )" ) && false !== strpos( $g9_src( 'src/Portal/Portal.php' ), "array( 'pending' ) ) )" ) );

// R7-PU8: conditions of the partner.
$g9_cond = Portal::conditions( $g9_a );
check( 'R7-PU8 conditions show rate, cookie duration and hold period', array( true, true, true ), array( false !== strpos( (string) ( $g9_cond['Your commission'] ?? '' ), '%' ), isset( $g9_cond['Referral valid for'] ), isset( $g9_cond['Hold period'] ) ) );
$wpdb->insert( t( 'rules' ), array( 'scope' => 'affiliate', 'scope_id' => $g9_aff, 'target' => 'all', 'rate_type' => 'percent', 'rate' => '15', 'active' => 1, 'created_at' => Db::now(), 'updated_at' => Db::now() ) );
$wpdb->insert( t( 'rules' ), array( 'scope' => 'global', 'scope_id' => 0, 'target' => 'product', 'target_value' => '77', 'rate_type' => 'percent', 'rate' => '5', 'active' => 1, 'created_at' => Db::now(), 'updated_at' => Db::now() ) );
$g9_cond = Portal::conditions( $g9_a );
check( 'R7-PU8 own partner rule is shown, product rules as a hint', array( 0, true ), array( strpos( str_replace( "\u{00A0}", ' ', (string) $g9_cond['Your commission'] ), '15 %' ), isset( $g9_cond['Special rates'] ) ) );
$wpdb->query( "DELETE FROM " . t( 'rules' ) . " WHERE scope_id = " . (int) $g9_aff . " OR target_value = '77'" );

// R7-PU13: link generator accepts www/upper case/path variants, rejects other sites; sub-ID; no forced source.
check(
	'R7-PU13 own page variants accepted and normalized',
	array( 'http://example.test/kurse/?a=1', 'http://example.test/kurse/', 'http://example.test/kurse', '', '' ),
	array( Portal::own_page_url( 'https://WWW.Example.TEST/kurse/?a=1' ), Portal::own_page_url( '/kurse/' ), Portal::own_page_url( 'www.example.test/kurse' ), Portal::own_page_url( 'https://evil.test/kurse' ), Portal::own_page_url( '//evil.test/x' ) )
);
$g9_gen = Portal::generated_link( 'http://example.test/kurse/', 'GINA', 'herbst', 'newsletter', 'mail-1' );
check( 'R7-AD16 generated link: own source, medium, campaign and sub-ID', true, false !== strpos( $g9_gen, 'utm_source=newsletter' ) && false !== strpos( $g9_gen, 'utm_campaign=herbst' ) && false !== strpos( $g9_gen, 'sub=mail-1' ) );
check( 'R7-AD16 without campaign no forced utm_source', false, strpos( Portal::generated_link( 'http://example.test/', 'GINA', '', '', 'x' ), 'utm_source' ) );

// R5-O42 / R7-PU14: pager with a window, filters, CSV export.
check( 'R5-O42 pager links: first, last and two around the current page', array( 1, 8, 9, 10, 11, 12, 50 ), Portal::pager_pages( 10, 50 ) );
check( 'R5-O42 pager drops old messages', true, false !== strpos( $g9_src( 'src/Portal/Portal.php' ), "remove_query_arg( array( 'wpac_msg', 'wpac_page' ) )" ) );
check( 'R7-PU14 list filters sanitized (invalid dates dropped)', array( 'status' => 'approved', 'from' => '2026-01-01', 'to' => '' ), Portal::list_filters( array( 'wpac_status' => 'Approved', 'wpac_from' => '2026-01-01', 'wpac_to' => '2026-02-31' ) ) );
list( $g9_h, $g9_csv ) = Portal::export_rows( $g9_a, 'commissions', array( 'status' => 'rejected', 'from' => '', 'to' => '' ) );
check( 'R7-PU14 CSV export of own commissions with the status filter', array( 'date', 1, 'g9-2', 'rejected' ), array( $g9_h[0], count( $g9_csv ), $g9_csv[0][1] ?? null, $g9_csv[0][8] ?? null ) );
list( , $g9_csvp ) = Portal::export_rows( $g9_a, 'payouts', array( 'status' => '', 'from' => '', 'to' => '' ) );
check( 'R7-PU14 payouts export works without payouts', array(), $g9_csvp );
check( 'R7-PU14 payouts are paged instead of limited to 100', false, strpos( $g9_src( 'src/Portal/Portal.php' ), "'limit'        => 100," ) );

// R7-PU10: mail preferences in the portal and broadcast mail in batches.
Portal::save_mail_preferences( $g9_aff, array( 'commission' ) );
check( 'R7-PU10 preferences: unchecked types are switched off', array( false, true, true ), array( Notifications::opted_out( $g9_aff, 'commission' ), Notifications::opted_out( $g9_aff, 'monthly_partner' ), Notifications::opted_out( $g9_aff, 'broadcast' ) ) );
Portal::save_mail_preferences( $g9_aff, array( 'commission', 'monthly_partner' ) );
check( 'R7-PU10 preferences can be switched on again', array( false, true ), array( Notifications::opted_out( $g9_aff, 'monthly_partner' ), Notifications::opted_out( $g9_aff, 'broadcast' ) ) );
check( 'R7-PU10 empty broadcast refused', 'wpac_broadcast_empty', Notifications::start_broadcast( '', 'x' )->get_error_code() );
$g9_mails = array();
add_action(
	'wpac_mail_sent',
	static function ( $type, $mail ) use ( &$g9_mails ) {
		if ( 'broadcast' === $type ) {
			$g9_mails[] = $mail;
		}
	},
	10,
	2
);
$g9_active = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . t( 'affiliates' ) . " WHERE status = 'active'" );
check( 'R7-PU10 broadcast addresses all active partners', $g9_active, Notifications::start_broadcast( 'Neuigkeiten', "Neue Kurse!\nViele Grüße" ) );
check( 'R7-PU10 second broadcast while one runs is refused', 'wpac_broadcast_running', Notifications::start_broadcast( 'Noch eine', 'x' )->get_error_code() );
$g9_guard = 0;
while ( ( $g9_state = Notifications::broadcast_state() ) && empty( $g9_state['done'] ) && $g9_guard++ < 50 ) {
	Notifications::broadcast_batch( 3 );
}
$g9_state = Notifications::broadcast_state();
$g9_to    = array_map( static function ( $m ) { return $m['to']; }, $g9_mails );
check( 'R7-PU10 broadcast sent in batches, opted-out partner skipped', array( true, $g9_active - 1, 1, false, true ), array( ! empty( $g9_state['done'] ), (int) $g9_state['sent'], (int) $g9_state['skipped'], in_array( 'gina9@example.de', $g9_to, true ), in_array( 'otto9@example.de', $g9_to, true ) ) );
$g9_one = null;
foreach ( $g9_mails as $m ) {
	if ( 'otto9@example.de' === $m['to'] ) {
		$g9_one = $m;
	}
}
check( 'R7-PU10 broadcast mail: greeting, text, unsubscribe link and List-Unsubscribe header', array( true, true, true ), array( 0 === strpos( (string) $g9_one['message'], 'Hello Otto Neun,' ), false !== strpos( (string) $g9_one['message'], 'wpac_unsubscribe=broadcast' ), (bool) preg_grep( '/^List-Unsubscribe: <.*wpac_unsubscribe=broadcast/', (array) $g9_one['headers'] ) ) );
check( 'R7-PU10 broadcast hook cleared on deactivation', true, false !== strpos( $g9_src( 'src/Core/Cron.php' ), 'Notifications::BROADCAST_HOOK' ) );

// R5-W59: login redirect of partners into the partner area.
update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), array( 'portal_page_id' => 321 ) ) );
Settings::flush_cache();
$wpdb->update( t( 'affiliates' ), array( 'user_id' => 9009 ), array( 'id' => $g9_aff ) );
AffiliateRepository::clear_cache();
$g9_user = (object) array( 'ID' => 9009, 'user_email' => 'gina9@example.de' );
check( 'R5-W59 partner lands in the partner area after the login', 'http://example.test/?p=321', Portal::login_redirect( admin_url(), '', $g9_user ) );
check( 'R5-W59 explicit target and non-partners stay', array( 'http://example.test/kasse/', admin_url() ), array( Portal::login_redirect( 'http://example.test/kasse/', 'http://example.test/kasse/', $g9_user ), Portal::login_redirect( admin_url(), '', (object) array( 'ID' => 9999 ) ) ) );

// R5-O17: resend of the account link – same answer with or without partner, limit per user.
$g9_cust = (object) array( 'ID' => 9100, 'user_email' => 'nobody9@example.de' );
check( 'R5-O17 no partner: same answer as with a partner (no probing)', 'link_sent', Portal::resend_link( $g9_cust ) );
Portal::resend_link( $g9_cust );
Portal::resend_link( $g9_cust );
check( 'R5-O17 limit per user', 'link_wait', Portal::resend_link( $g9_cust ) );
check( 'R5-O17 maintenance mode blocks the resend', true, false !== strpos( $g9_src( 'src/Portal/Portal.php' ), "Settings::get( 'maintenance_mode' ) ? 'maintenance' : self::resend_link(" ) );

// Landing page passes the sub-ID on (R7-AD16).
$g9_target = WPAffiliateCaptain\Promotion\Landing::target_url( $g9_a, array( 'sub' => 'flyer-2', 'utm_campaign' => 'messe' ) );
check( 'R7-AD16 /partner/slug/ keeps the sub-ID', true, false !== strpos( $g9_target, 'sub=flyer-2' ) && false !== strpos( $g9_target, 'utm_campaign=messe' ) );

// Markup, CSS and JavaScript fixes (static checks).
$g9_portal = $g9_src( 'src/Portal/Portal.php' );
$g9_css    = $g9_src( 'assets/css/portal.css' );
$g9_js     = $g9_src( 'assets/js/portal.js' );
check( 'R6-CC7 portal page sets DONOTCACHEPAGE before output', true, false !== strpos( $g9_portal, "add_action( 'template_redirect', array( __CLASS__, 'prepare_page' ), 2 )" ) && false !== strpos( $g9_portal, 'Landing::no_cache();' ) );
check( 'R5-O41 portal CSS in the head and via block.json "style"', array( true, true, true ), array( false !== strpos( $g9_portal, "wp_enqueue_style( 'wpac-portal' );\n\t}" ), false !== strpos( $g9_src( 'blocks/portal/block.json' ), '"style": "wpac-portal"' ), false !== strpos( $g9_src( 'src/Content/Editor.php' ), 'Portal::register_assets()' ) ) );
check( 'R5-W62 no forced light text colors in dark mode, disclosure with text color', array( false, true ), array( false !== strpos( $g9_css, 'prefers-color-scheme' ), false !== strpos( $g9_css, '.wpac-disclosure{background:#fff8e1;color:#3d2e00' ) ) );
check( 'R5-W63 buttons and tabs win over theme link colors', array( true, true ), array( false !== strpos( $g9_css, 'a.wpac-button.wpac-button:link' ), false !== strpos( $g9_css, '.wpac-tabs.wpac-tabs a{' ) ) );
check( 'R5-W66 focus ring with enough contrast', array( false, true ), array( false !== strpos( $g9_css, 'outline:3px solid #f9a825' ), false !== strpos( $g9_css, 'box-shadow:0 0 0 5px #fff' ) ) );
check( 'R5-O38 notices outside the wrapper have the accent', true, false !== strpos( $g9_css, '.wpac-portal,.wpac-form,.wpac-notice,.wpac-welcome{--wpac-accent' ) );
check( 'R5-W65 copy button restores the original label', array( true, false ), array( false !== strpos( $g9_js, "btn.setAttribute( 'data-wpac-label', btn.textContent )" ), false !== strpos( $g9_js, 'var label = btn.textContent;' ) ) );
check( 'R5-O40 copy buttons named for screen readers', true, false !== strpos( $g9_portal, "__( 'Copy: %s', 'affiliate-captain' )" ) );
check( 'R5-W64 code table with caption, scope and data-label', true, false !== strpos( $g9_portal, "esc_html__( 'Your codes', 'affiliate-captain' ) . '</caption>" ) && false !== strpos( $g9_portal, '<tr><td data-label="%6$s"><code>%1$s</code>' ) );
check( 'R5-O53 short link and QR code with explanation in "Links & codes"', true, false !== strpos( $g9_portal, "esc_html__( 'Short link and QR code', 'affiliate-captain' )" ) );
check( 'R7-PU9 terms with version and date in the overview and the account tab', 2, substr_count( $g9_portal, '$line = self::terms_line( $aff );' ) );
check( 'R5-W58 "being reviewed" card and hint', true, false !== strpos( $g9_portal, "__( 'Being reviewed', 'affiliate-captain' )" ) );
check( 'R5-W59 WooCommerce "My account" entry', true, false !== strpos( $g9_portal, "add_filter( 'woocommerce_account_menu_items'" ) );
