<?php
/**
 * Integration checks of package L1 (round 12 legal items): privacy text matches the cookie mode
 * and the shop session (R12-L4); minimum group size of the partner's campaign/sub-ID report
 * (R12-L5). Included from tests/integration/run.php (uses its check() helper and database).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Privacy\Privacy;
use WPAffiliateCaptain\Reports\Reports;
use WPAffiliateCaptain\Tracking\Tracker;

echo "L1 privacy text and sub-ID grouping\n";

if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
	function wp_add_privacy_policy_content( $plugin, $html ) { $GLOBALS['g4_policy'] = $html; }
}
$l1_root     = dirname( __DIR__, 2 );
$l1_src      = static function ( $f ) use ( $l1_root ) {
	return (string) file_get_contents( $l1_root . '/' . $f );
};
$l1_settings = get_option( 'wpac_settings' );
$l1_set      = static function ( array $values ) {
	update_option( 'wpac_settings', array_merge( (array) get_option( 'wpac_settings' ), $values ) );
	Settings::flush_cache();
};
$l1_policy   = static function ( array $values ) use ( $l1_set ) {
	$l1_set( $values );
	unset( $GLOBALS['g4_policy'] );
	Privacy::policy_text();
	return (string) ( $GLOBALS['g4_policy'] ?? '' );
};
$l1_has      = static function ( $html, $needle ) {
	return false !== strpos( $html, $needle );
};
$l1_note     = static function () {
	return (bool) array_filter( Tracker::tracking_problems(), static function ( $p ) { return false !== strpos( $p[1], '§ 25(2) no. 2 TDDDG' ); } );
};

// ---------------------------------------------------------------------------------------------
// R12-L4: legal basis of the cookie follows the cookie mode.
$l1_consent = $l1_policy( array( 'cookie_mode' => 'consent', 'use_wc_session' => 0, 'js_tracking' => 1 ) );
check(
	'R12-L4 consent mode: cookie on consent (Art. 6(1)(a) GDPR, § 25(1) TDDDG), no operator note, no Site Health note',
	array( true, true, false, false, true, false ),
	array(
		$l1_has( $l1_consent, 'your consent (Art. 6(1)(a) GDPR, § 25(1) TDDDG)' ),
		$l1_has( $l1_consent, 'only set if you consented to marketing cookies' ),
		$l1_has( $l1_consent, 'privacy-policy-tutorial' ),
		$l1_has( $l1_consent, 'shop session' ),
		$l1_has( $l1_consent, 'only with your marketing consent' ),
		$l1_note(),
	)
);
$l1_always = $l1_policy( array( 'cookie_mode' => 'always', 'use_wc_session' => 0, 'js_tracking' => 1 ) );
check(
	'R12-L4 always mode: no consent claimed, cookie "without asking for your consent", basis left to the operator, note on § 25(2) no. 2 TDDDG',
	array( false, false, true, true, true, true, true ),
	array(
		$l1_has( $l1_always, 'Art. 6(1)(a)' ),
		$l1_has( $l1_always, 'only with your marketing consent' ),
		$l1_has( $l1_always, 'set when you follow a partner link, without asking for your consent' ),
		$l1_has( $l1_always, '[legal basis to be assessed and completed by the shop operator]' ),
		$l1_has( $l1_always, '<p class="privacy-policy-tutorial"><strong>' ),
		$l1_has( $l1_always, '§ 25(2) no. 2 TDDDG' ),
		$l1_has( $l1_always, 'usually require consent' ),
	)
);
check( 'R12-L4 always mode: Site Health recommends consent (note names § 25(2) no. 2 TDDDG)', true, $l1_note() );
$l1_off = $l1_policy( array( 'cookie_mode' => 'off', 'use_wc_session' => 0, 'js_tracking' => 1 ) );
check(
	'R12-L4 off mode: no cookie basis, previous page not stored',
	array( false, false, true ),
	array( $l1_has( $l1_off, 'affiliate cookie on your device' ), $l1_has( $l1_off, 'Art. 6(1)(a)' ), $l1_has( $l1_off, 'The address of the previous website is not stored.' ) )
);
$l1_sess_c = $l1_policy( array( 'cookie_mode' => 'consent', 'use_wc_session' => 1, 'js_tracking' => 0 ) );
$l1_sess_a = $l1_policy( array( 'cookie_mode' => 'always', 'use_wc_session' => 1, 'js_tracking' => 0 ) );
$l1_sess_o = $l1_policy( array( 'cookie_mode' => 'off', 'use_wc_session' => 1, 'js_tracking' => 0 ) );
check(
	'R12-L4 use_wc_session: storage on the server, no own cookie, independent of consent, basis legitimate interest (all modes)',
	array( true, true, true, true, true, false, true ),
	array(
		$l1_has( $l1_sess_c, 'kept with your shop session on our server until checkout' ),
		$l1_has( $l1_sess_c, 'This does not depend on your consent to marketing cookies.' ),
		$l1_has( $l1_sess_c, 'keeping the partner code in the shop session: legitimate interest in paying the partner correctly (Art. 6(1)(f) GDPR)' ),
		$l1_has( $l1_sess_o, 'This does not depend on your consent to marketing cookies.' ),
		$l1_has( $l1_sess_a, 'also kept with your shop session on our server' ),
		$l1_has( $l1_sess_a, 'This does not depend on your consent' ),
		$l1_has( $l1_sess_a, 'keeping the partner code in the shop session: legitimate interest' ),
	)
);
check(
	'R12-L4 defaults unchanged (cookie only with consent, shop session on)',
	array( 'consent', 1 ),
	array( Settings::defaults()['cookie_mode'], (int) Settings::defaults()['use_wc_session'] )
);
update_option( 'wpac_settings', $l1_settings );
Settings::flush_cache();
unset( $GLOBALS['g4_policy'] );

// ---------------------------------------------------------------------------------------------
// R12-L5: partner-facing campaign/sub-ID rows with fewer than N sales are combined.
$l1_aff  = AffiliateRepository::create( array( 'name' => 'L1 Newsletter', 'email' => 'l1-news@example.de', 'status' => 'active' ) );
$l1_code = CodeRepository::main_code( $l1_aff );
$l1_n    = 0;
$l1_sale = static function ( $campaign, $sub, $net ) use ( &$l1_n, $l1_code ) {
	++$l1_n;
	return wpac_record_sale(
		array(
			'source'   => 'l1',
			'order_id' => 'l1-' . $l1_n,
			'currency' => 'EUR',
			'items'    => array( array( 'item_id' => '1', 'product_id' => 77, 'name' => 'Kurs L1', 'net_minor' => $net ) ),
			'customer' => array( 'user_id' => 0, 'email' => 'l1-' . $l1_n . '@example.de' ),
			'touch'    => array( 'code' => $l1_code, 'method' => 'link', 'ts' => time(), 'campaign' => $campaign, 'sub_id' => $sub ),
		)
	);
};
$l1_visit = static function ( $campaign, $sub, $n ) use ( $wpdb, $l1_aff ) {
	for ( $i = 0; $i < $n; $i++ ) {
		$wpdb->insert( t( 'visits' ), array( 'affiliate_id' => $l1_aff, 'code_id' => 0, 'campaign' => $campaign, 'sub_id' => $sub, 'created_at' => Db::now( time() - 60 * ( $i + 1 ) ) ) );
	}
};
// "news/big": 3 sales (shown); "news/reader-1" and "news/reader-2": 1 sale each (unique sub-IDs);
// "promo" without sub-ID: 2 sales; "news/clicks": visits only; generic link: 1 sale.
$l1_visit( 'news', 'big', 6 );
$l1_visit( 'news', 'reader-1', 2 );
$l1_visit( 'news', 'reader-2', 1 );
$l1_visit( 'promo', '', 3 );
$l1_visit( 'news', 'clicks', 5 );
for ( $i = 0; $i < 3; $i++ ) {
	$l1_sale( 'news', 'big', 10000 );
}
$l1_sale( 'news', 'reader-1', 10000 );
$l1_sale( 'news', 'reader-2', 20000 );
$l1_sale( 'promo', '', 10000 );
$l1_sale( 'promo', '', 10000 );
$l1_sale( '', '', 10000 );
$l1_from = gmdate( 'Y-m-d', time() - 2 * DAY_IN_SECONDS );
$l1_to   = gmdate( 'Y-m-d', time() + DAY_IN_SECONDS );
$l1_key  = static function ( array $rows ) {
	$out = array();
	foreach ( $rows as $r ) {
		$out[ ! empty( $r['other'] ) ? 'OTHER' : $r['campaign'] . '/' . $r['sub_id'] ] = array( $r['visits'], $r['orders'], $r['commission'] );
	}
	return $out;
};
$l1_raw  = Reports::partner_campaigns( $l1_aff, $l1_from, $l1_to );
$l1_safe = Reports::partner_campaigns( $l1_aff, $l1_from, $l1_to, 3 );
check( 'R12-L5 default minimum group size 3 (filter wpac_subid_min_group)', 3, Reports::subid_min_group() );
check(
	'R12-L5 admin/raw report unchanged: every sub-ID on its own',
	array( true, true, false ),
	array( isset( $l1_key( $l1_raw )['news/reader-1'] ), isset( $l1_key( $l1_raw )['promo/'] ), isset( $l1_key( $l1_raw )['OTHER'] ) )
);
$l1_k = $l1_key( $l1_safe );
check(
	'R12-L5 partner report: rows with 1–2 sales merged into one "Other" row (sums of visits, sales, commission); 3+ sales, visit-only and generic rows stay',
	array(
		'news/big'    => array( 6, 3, array( 'EUR' => 3000 ) ),
		'/'           => array( 0, 1, array( 'EUR' => 1000 ) ),
		'news/clicks' => array( 5, 0, array() ),
		'OTHER'       => array( 6, 4, array( 'EUR' => 5000 ) ),
	),
	$l1_k
);
$l1_last = end( $l1_safe );
check(
	'R12-L5 "Other" row last, without campaign/sub-ID, labelled with the threshold, conversion from its own clicks',
	array( true, '', '', true, round( 4 * 100 / 6, 1 ) ),
	array( ! empty( $l1_last['other'] ), $l1_last['campaign'], $l1_last['sub_id'], false !== strpos( $l1_last['label'], 'fewer than 3 sales' ), $l1_last['conversion'] )
);
check(
	'R12-L5 no row shown to the partner has 1 or 2 sales except "Other"/generic; no sub-ID of a small row appears',
	array( array(), false ),
	array(
		array_values( array_filter( $l1_safe, static function ( $r ) { return empty( $r['other'] ) && ( '' !== $r['campaign'] || '' !== $r['sub_id'] ) && $r['orders'] > 0 && $r['orders'] < 3; } ) ),
		false !== strpos( wp_json_encode( $l1_safe ), 'reader-' ),
	)
);
$l1_page = Reports::partner_campaigns_page( $l1_aff, $l1_from, $l1_to, 1, 25 );
check( 'R12-L5 partner area (paged, cached) uses the threshold', array( 4, false ), array( $l1_page['total'], false !== strpos( wp_json_encode( $l1_page['rows'] ), 'reader-' ) ) );
check( 'R12-L5 partner_cached(campaigns) uses the threshold', $l1_k, $l1_key( Reports::partner_cached( 'campaigns', $l1_aff, $l1_from, $l1_to ) ) );
add_filter( 'wpac_subid_min_group', static function () { return 1; } );
$l1_off_page = Reports::partner_campaigns_page( $l1_aff, $l1_from, $l1_to, 1, 25 );
remove_all_filters( 'wpac_subid_min_group' );
check( 'R12-L5 filter 1 switches the grouping off (own cache entry)', array( count( $l1_raw ), true ), array( $l1_off_page['total'], false !== strpos( wp_json_encode( $l1_off_page['rows'] ), 'reader-1' ) ) );
add_filter( 'wpac_subid_min_group', static function () { return 0; } );
check( 'R12-L5 filter values below 1 count as 1', 1, Reports::subid_min_group() );
remove_all_filters( 'wpac_subid_min_group' );
$l1_portal = $l1_src( 'src/Portal/Portal.php' );
$l1_rest   = $l1_src( 'src/Rest/RestApi.php' );
check(
	'R12-L5 partner area explains the grouping; partner commission list/CSV/REST carry no campaign or sub-ID per order',
	array( true, false, false ),
	array(
		$l1_has( $l1_portal, 'are shown together as "Other"' ),
		(bool) preg_match( '/function export_rows.*?(sub_id|campaign).*?function day/s', $l1_portal ),
		(bool) preg_match( '/function commission_data.*?(sub_id|campaign).*?function commissions\(/s', $l1_rest ),
	)
);
