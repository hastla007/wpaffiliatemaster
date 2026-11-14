<?php
// Demo data for the WordPress.org screenshots. php data.php <wp-dir>
// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository as AR;
use WPAffiliateCaptain\Affiliates\Groups;
use WPAffiliateCaptain\Codes\CodeRepository as CR;
use WPAffiliateCaptain\Commissions\RuleRepository as RR;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Payouts\ProfileRepository as PR;
use WPAffiliateCaptain\Payouts\PayoutService as PS;

$wp_dir = rtrim( $argv[1], '/' );
$_SERVER['HTTP_HOST']   = 'harbor-sailing.example.com';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/';
require $wp_dir . '/wp-load.php';
wp_set_current_user( 1 );
mt_srand( 42 );
global $wpdb;

function chk( $v, $what ) {
	if ( is_wp_error( $v ) ) { fwrite( STDERR, "$what: " . $v->get_error_message() . "\n" ); exit( 1 ); }
	return $v;
}
function page( $slug, $title, $content, $status = 'publish' ) {
	$p = get_page_by_path( $slug );
	if ( $p ) { return (int) $p->ID; }
	return (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => $status, 'post_name' => $slug, 'post_title' => $title, 'post_content' => $content, 'post_author' => 1 ) );
}

// Pages and settings.
$register = page( 'become-a-partner', 'Become a partner', "<!-- wp:paragraph --><p>Recommend our sailing courses to your readers, members or followers and earn a commission on every booking.</p><!-- /wp:paragraph -->\n<!-- wp:wpac/registration /-->" );
$portal   = page( 'partner-area', 'Partner area', '<!-- wp:wpac/portal /-->' );
$terms    = page( 'partner-terms', 'Partner program terms', WPAffiliateCaptain\Admin\Pages\DashboardPage::terms_template(), 'publish' );
Settings::update( array(
	'registration_page_id' => $register,
	'portal_page_id'       => $portal,
	'terms_page_id'        => $terms,
	'operator_address'     => "Harbor Sailing School GmbH\n1 Harbor Road\n24103 Kiel",
	'operator_country'     => 'DE',
	'operator_vat_id'      => 'DE123456789',
	'debtor_name'          => 'Harbor Sailing School GmbH',
	'debtor_iban'          => 'DE89370400440532013000',
	'debtor_bic'           => 'COBADEFFXXX',
	'promotion_guidelines' => 'Please label every post as an ad. No brand bidding, no coupon sites.',
) );

// Groups.
$g_clubs = Groups::create( 'Sailing clubs', 'Clubs and associations that recommend our courses to their members.' );
$g_blog  = Groups::create( 'Creators', 'Bloggers, YouTubers and podcasters.' );
$g_mar   = Groups::create( 'Marinas & charter', 'Marinas, charter companies and boat dealers.' );
chk( Groups::update( $g_clubs, array( 'name' => 'Sailing clubs', 'description' => 'Clubs and associations that recommend our courses to their members.', 'public' => 1, 'fields' => "members|Number of members|number|1" ) ), 'group' );
chk( Groups::update( $g_blog, array( 'name' => 'Creators', 'description' => 'Bloggers, YouTubers and podcasters.', 'public' => 1, 'fields' => "channel_url|Link to your channel|url|1\naudience|Monthly audience|number|0" ) ), 'group' );

// Rules.
foreach ( array(
	array( 'scope' => 'global', 'target' => 'all', 'rate_type' => 'percent', 'rate' => '10', 'note' => 'Standard rate', 'active' => 1 ),
	array( 'scope' => 'global', 'target' => 'sku', 'target_value' => 'SKIPPER-OFFSHORE', 'rate_type' => 'fixed', 'rate' => '60', 'note' => 'Offshore skipper course', 'active' => 1 ),
	array( 'scope' => 'global', 'target' => 'sku', 'target_value' => 'GIFT-CARD', 'rate_type' => 'exclude', 'note' => 'No commission on gift cards', 'active' => 1 ),
	array( 'scope' => 'group', 'scope_id' => $g_clubs, 'target' => 'all', 'rate_type' => 'percent', 'rate' => '15', 'note' => 'Club partners', 'active' => 1 ),
	array( 'scope' => 'group', 'scope_id' => $g_mar, 'target' => 'category', 'target_value' => '12', 'rate_type' => 'percent', 'rate' => '12', 'note' => 'Charter courses', 'active' => 1 ),
	array( 'scope' => 'global', 'target' => 'sku', 'target_value' => 'WINTER-THEORY', 'rate_type' => 'percent', 'rate' => '20', 'note' => 'Winter campaign (ended)', 'active' => 0 ),
) as $r ) {
	chk( RR::save( chk( RR::sanitize( $r ), 'rule' ), 0, 'Demo setup' ), 'rule save' );
}

// Partners: name, email, website, channel, group, status, country, tax, code, coupon, method.
$partners = array(
	array( 'Lena Hartmann', 'lena@coastal-sailing.example.com', 'https://coastal-sailing.example.com', 'Blog', $g_blog, 'active', 'DE', 'small_business', 'LENA', 'LENA10', 'sepa', 'DE02120300000000202051' ),
	array( 'Northshore Yacht Club', 'office@northshore-yc.example.com', 'https://northshore-yc.example.com', 'Club newsletter', $g_clubs, 'active', 'DE', 'business', 'NORTHSHORE', 'NYC-MEMBERS', 'sepa', 'DE02500105170137075030' ),
	array( 'Sophie Laurent', 'sophie@windandwaves.example.com', 'https://windandwaves.example.com', 'YouTube', $g_blog, 'active', 'FR', 'business', 'WINDWAVES', '', 'sepa', 'FR7630006000011234567890189' ),
	array( 'Marco Bianchi', 'marco.bianchi@example.com', 'https://instagram.example.com/marcosails', 'Instagram', $g_blog, 'active', 'IT', 'private', 'MARCO', '', 'manual', 'PayPal: marco.bianchi@example.com' ),
	array( 'Harbor View Marina', 'info@harborview-marina.example.com', 'https://harborview-marina.example.com', 'Marina office', $g_mar, 'active', 'DE', 'business', 'HARBORVIEW', 'MARINA5', 'sepa', 'DE02100100100006820101' ),
	array( 'Tom Becker', 'tom@sailcast.example.com', 'https://sailcast.example.com', 'Podcast', $g_blog, 'active', 'DE', 'private', 'SAILCAST', '', 'sepa', 'DE02300209000106531065' ),
	array( 'Clara Jensen', 'clara.jensen@example.com', 'https://nordic-sailing.example.com', 'Blog', $g_blog, 'active', 'DK', 'private', 'CLARA', '', 'sepa', 'DK5000400440116243' ),
	array( 'Bay Sailing Association', 'board@bay-sailing.example.com', 'https://bay-sailing.example.com', 'Members area', $g_clubs, 'active', 'NL', 'business', 'BAYSAIL', '', 'sepa', 'NL91ABNA0417164300' ),
	array( 'Julia Novak', 'julia.novak@example.com', 'https://julia-on-deck.example.com', 'Newsletter (8,000 readers)', $g_blog, 'pending', 'AT', 'private', '', '', '', '' ),
	array( 'Blue Anchor Charter', 'hello@blue-anchor.example.com', 'https://blue-anchor.example.com', 'Charter bookings', $g_mar, 'pending', 'DE', 'business', '', '', '', '' ),
	array( 'David Miller', 'david.miller@example.com', 'https://deals.example.com', 'Coupon site', 0, 'paused', 'DE', 'private', 'DAVIDM', '', 'sepa', 'DE02700100800030876808' ),
);
$ids = array();
$vat = array( 'DE' => 'DE811907980', 'FR' => 'FR40303265045', 'NL' => 'NL004495445B01' );
foreach ( $partners as $i => $p ) {
	list( $name, $email, $web, $channel, $group, $status, $country, $tax, $code, $coupon, $method, $pay ) = $p;
	$user_id = 0;
	if ( 0 === $i ) {
		$user_id = (int) chk( wp_insert_user( array( 'user_login' => 'lena', 'user_pass' => 'lena-pass-123', 'user_email' => $email, 'display_name' => $name, 'first_name' => 'Lena', 'role' => 'subscriber' ) ), 'user' );
	}
	$create_status = 'active' === $status || 'paused' === $status ? 'active' : $status;
	$id = (int) chk( AR::create( array( 'name' => $name, 'email' => $email, 'website' => $web, 'channel' => $channel, 'group_id' => $group, 'status' => $create_status, 'user_id' => $user_id, 'application_note' => 'pending' === $status ? 'I write about sailing holidays and would love to recommend your courses.' : '' ) ), 'aff' );
	$ids[ $i ] = $id;
	if ( $code ) {
		$main = CR::main_code( $id );
		$row  = CR::find_by_code( $main );
		if ( $row ) {
			$wpdb->update( Db::table( 'partner_codes' ), array( 'code' => $code, 'coupon_code' => strtolower( $coupon ) ), array( 'id' => (int) $row->id ) );
		}
	}
	if ( $method ) {
		$in = array( 'tax_status' => $tax, 'legal_name' => $name, 'address' => "Example Street " . ( $i + 3 ) . "\n12345 Example City", 'country' => $country, 'account_holder' => $name, 'payout_method' => $method );
		if ( 'business' === $tax ) { $in['vat_id'] = $vat[ $country ]; }
		if ( 'small_business' === $tax ) { $in['tax_number'] = '19/123/45678'; }
		if ( 'sepa' === $method ) { $in['iban'] = $pay; } else { $in['payment_details'] = $pay; }
		chk( PR::save( $id, $in, 'admin' ), "profile $name" );
	}
	if ( 'pending' !== $status ) {
		WPAffiliateCaptain\Affiliates\Agreements::record( $id );
	}
	if ( 'paused' === $status ) {
		chk( AR::set_status( $id, 'paused', 'Coupon codes posted on a deal site against the guidelines.' ), 'pause' );
	}
	// Spread sign-up dates.
	$wpdb->update( Db::table( 'affiliates' ), array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - ( 200 - $i * 15 ) * DAY_IN_SECONDS ) ), array( 'id' => $id ) );
}
// Secondary codes.
wp_delete_post( 2, true ); update_user_meta( (int) get_user_by( 'login', 'lena' )->ID, 'show_admin_bar_front', 'false' );
CR::create( $ids[1], 'NYC-JUNIORS', array() );
CR::create( $ids[2], 'WINDWAVES-SUMMER', array() );
AR::clear_cache();

// Products (course catalogue of the demo sailing school).
$products = array(
	array( 101, 'SBF-SEA', 'Coastal skipper licence course', 12, 49900 ),
	array( 102, 'SKIPPER-OFFSHORE', 'Offshore skipper course', 12, 129000 ),
	array( 103, 'DINGHY-BASIC', 'Dinghy sailing for beginners', 14, 24900 ),
	array( 104, 'RADIO-SRC', 'Marine radio certificate (SRC)', 15, 18900 ),
	array( 105, 'WEEKEND-TRIP', 'Weekend sailing trip, Baltic Sea', 16, 39000 ),
	array( 106, 'GIFT-CARD', 'Gift card', 17, 10000 ),
);
$weights = array( 0 => 16, 1 => 11, 2 => 13, 3 => 6, 4 => 8, 5 => 5, 6 => 4, 7 => 5, 10 => 2 );
$order   = 1000;
$sales   = array();
foreach ( $weights as $pi => $n ) {
	for ( $k = 0; $k < $n; $k++ ) {
		$days  = mt_rand( 1, 125 );
		$paid  = time() - $days * DAY_IN_SECONDS - mt_rand( 0, 80000 );
		$pr    = $products[ mt_rand( 0, 4 ) ];
		$items = array( array( 'item_id' => '1', 'product_id' => $pr[0], 'sku' => $pr[1], 'category_ids' => array( $pr[3] ), 'name' => $pr[2], 'quantity' => 1, 'net_minor' => $pr[4] ) );
		if ( 0 === mt_rand( 0, 4 ) ) {
			$g = $products[5];
			$items[] = array( 'item_id' => '2', 'product_id' => $g[0], 'sku' => $g[1], 'category_ids' => array( $g[3] ), 'name' => $g[2], 'quantity' => 1, 'net_minor' => $g[4] );
		}
		$sales[] = array( $paid, $pi, $items );
	}
}
usort( $sales, function ( $a, $b ) { return $a[0] - $b[0]; } );
foreach ( $sales as $s ) {
	list( $paid, $pi, $items ) = $s;
	$order++;
	$pid  = $ids[ $pi ];
	$code = CR::main_code( $pid );
	if ( 10 === $pi ) { $wpdb->update( Db::table( 'affiliates' ), array( 'status' => 'active' ), array( 'id' => $pid ) ); AR::clear_cache( $pid ); }
	$res = wpac_record_sale( array( 'source' => 'course-booking', 'order_id' => (string) $order, 'currency' => 'EUR', 'paid_at' => gmdate( 'Y-m-d H:i:s', $paid ), 'affiliate_id' => $pid, 'attribution_method' => 'code', 'items' => $items ) );
	if ( 10 === $pi ) { $wpdb->update( Db::table( 'affiliates' ), array( 'status' => 'paused' ), array( 'id' => $pid ) ); AR::clear_cache( $pid ); }
	if ( is_wp_error( $res ) ) { fwrite( STDERR, 'sale: ' . $res->get_error_message() . "\n" ); continue; }
	$at = gmdate( 'Y-m-d H:i:s', $paid );
	$wpdb->query( $wpdb->prepare( 'UPDATE ' . Db::table( 'commissions' ) . ' SET created_at = %s WHERE source = %s AND source_order_id = %s', $at, 'course-booking', (string) $order ) );
	$wpdb->query( $wpdb->prepare( 'UPDATE ' . Db::table( 'attributions' ) . ' SET created_at = %s WHERE source = %s AND source_order_id = %s', $at, 'course-booking', (string) $order ) );
}

// Visits: 30–90 per day spread over the partners for the last 90 days.
$vt   = Db::table( 'visits' );
$camp = array( '', '', '', 'summer-courses', 'newsletter-june', 'youtube-review' );
$src  = array( array( 'instagram', 'social', 'instagram.example.com' ), array( 'youtube', 'video', 'youtube.example.com' ), array( 'newsletter', 'email', '' ), array( '', '', 'coastal-sailing.example.com' ), array( '', '', 'northshore-yc.example.com' ) );
$vals = array();
foreach ( $weights as $pi => $n ) {
	$codes = CR::for_affiliate( $ids[ $pi ] );
	$cid   = $codes ? (int) $codes[0]->id : 0;
	$total = $n * mt_rand( 30, 55 );
	for ( $v = 0; $v < $total; $v++ ) {
		$t  = time() - mt_rand( 0, 124 * DAY_IN_SECONDS );
		$s  = $src[ mt_rand( 0, 4 ) ];
		$vals[] = $wpdb->prepare( '(%d,%d,%s,%s,%s,%s,%s,%s)', $ids[ $pi ], $cid, $camp[ mt_rand( 0, 5 ) ], $s[0], $s[1], $s[2], '/courses/', gmdate( 'Y-m-d H:i:s', $t ) );
	}
}
foreach ( array_chunk( $vals, 300 ) as $chunk ) {
	$wpdb->query( "INSERT INTO {$vt} (affiliate_id, code_id, campaign, utm_source, utm_medium, referrer_host, landing_path, created_at) VALUES " . implode( ',', $chunk ) );
}


// Hold periods: commissions paid more than 30 days ago are due. Approve in two phases around
// the payout run of the month before last, so the account statements stay chronological.
$ct   = Db::table( 'commissions' );
$end2 = gmdate( 'Y-m-d', strtotime( 'last day of -2 month' ) );
$wpdb->query( "UPDATE {$ct} SET hold_until = DATE_ADD(created_at, INTERVAL 30 DAY)" );
$backup = $wpdb->get_results( "SELECT id, hold_until FROM {$ct}" );
$wpdb->query( $wpdb->prepare( "UPDATE {$ct} SET hold_until = '2099-01-01 00:00:00' WHERE hold_until > %s", $end2 . ' 23:59:59' ) );
echo 'approved (phase 1): ', Ledger::approve_due(), "\n";
foreach ( $backup as $b ) {
	$wpdb->update( $ct, array( 'hold_until' => $b->hold_until ), array( 'id' => (int) $b->id ) );
}

$run = PS::create_run( $end2, 'EUR' );
echo 'run created: ', count( $run[0] ), ' skipped: ', $run[1], "\n";
PS::generate_missing_statements( 100, 60 );
$run_at  = gmdate( 'Y-m-d', strtotime( $end2 . ' +3 days' ) ) . ' 09:12:00';
$paid_at = gmdate( 'Y-m-d', strtotime( $end2 . ' +5 days' ) ) . ' 14:30:00';
$pt      = Db::table( 'payouts' );
$wpdb->query( $wpdb->prepare( "UPDATE {$pt} SET created_at = %s", $run_at ) );
foreach ( $run[0] as $n => $pid ) {
	$pid = is_object( $pid ) ? (int) $pid->id : (int) $pid;
	if ( $n < 6 ) {
		$r = PS::mark_paid( $pid );
		if ( is_wp_error( $r ) ) { fwrite( STDERR, 'paid: ' . $r->get_error_message() . "\n" ); }
		$wpdb->update( $pt, array( 'paid_at' => $paid_at ), array( 'id' => $pid ) );
	}
}
echo 'approved (phase 2): ', Ledger::approve_due(), "\n";

// One partial refund for a realistic statement.
$first = $wpdb->get_row( $wpdb->prepare( "SELECT source_order_id FROM {$ct} WHERE affiliate_id = %d AND status = %s AND base_minor >= 49900 ORDER BY id DESC LIMIT 1", $ids[2], 'pending' ) );
if ( $first ) {
	$r = wpac_record_refund( array( 'source' => 'course-booking', 'order_id' => $first->source_order_id, 'refund_id' => 'r-' . $first->source_order_id, 'currency' => 'EUR', 'items' => array( array( 'item_id' => '1', 'net_minor' => 10000 ) ) ) );
	if ( is_wp_error( $r ) ) { fwrite( STDERR, 'refund: ' . $r->get_error_message() . "\n" ); }
}

// Booking dates: approvals at the end of the hold period, the payout at the run date; then re-hash
// each account chain (the hash covers the date) so the integrity check still passes.
$et = Db::table( 'balance_entries' );
$bt = Db::table( 'balances' );
foreach ( $wpdb->get_results( "SELECT DISTINCT affiliate_id, currency FROM {$et}" ) as $acc ) {
	$prev = '';
	$last = '';
	foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$et} WHERE affiliate_id = %d AND currency = %s ORDER BY id ASC", $acc->affiliate_id, $acc->currency ), ARRAY_A ) as $row ) {
		$date = $row['created_at'];
		if ( $row['payout_id'] ) {
			$date = $run_at;
		} elseif ( $row['commission_id'] ) {
			$h = $wpdb->get_var( $wpdb->prepare( "SELECT hold_until FROM {$ct} WHERE id = %d", $row['commission_id'] ) );
			if ( $h ) { $date = gmdate( 'Y-m-d', strtotime( $h ) ) . ' 03:0' . ( $row['id'] % 10 ) . ':00'; }
		}
		if ( $last && $date < $last ) { $date = $last; }
		$last              = $date;
		$row['created_at'] = $date;
		$row['prev_hash']  = $prev;
		$row['hash']       = WPAffiliateCaptain\Commissions\Balance::hash( $row );
		$wpdb->update( $et, array( 'created_at' => $date, 'prev_hash' => $prev, 'hash' => $row['hash'] ), array( 'id' => (int) $row['id'] ) );
		$prev = $row['hash'];
	}
	$wpdb->update( $bt, array( 'last_hash' => $prev, 'updated_at' => $last, 'verified_id' => 0, 'verified_hash' => '', 'verified_balance' => 0, 'verified_at' => null ), array( 'affiliate_id' => (int) $acc->affiliate_id, 'currency' => $acc->currency ) );
}
// Commission history at the matching dates.
$ev = Db::table( 'commission_events' );
$wpdb->query( "UPDATE {$ev} e JOIN {$ct} c ON c.id = e.commission_id SET e.created_at = c.created_at WHERE e.to_status = 'pending'" );
$wpdb->query( "UPDATE {$ev} e JOIN {$ct} c ON c.id = e.commission_id SET e.created_at = c.hold_until WHERE e.to_status = 'approved'" );
echo "partner portal user: lena / lena-pass-123\n";
