<?php
/**
 * AFF-SU-03 (agent su3, since plugin 0.17.0, provisional until the shared suite specification is
 * accepted): read-only review quality of partners and campaigns from a review plugin via the
 * filter wpac_review_aggregates, and the read function wpac_get_order_attribution().
 * Minimum sample (10 reviews from 5 orders), rolling window (30/90/365 days), only admins,
 * nothing stored, deletion of a review drops it out at the next computation, restricted and
 * erased partners, malformed answers, a failing review plugin.
 * Included from run.php before r8-render.php (helpers check(), t(), q()).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Core\Capabilities;
use WPAffiliateCaptain\Privacy\Restriction;
use WPAffiliateCaptain\Reports\ReviewQuality;

echo "SU3 review quality (AFF-SU-03)\n";
global $wpdb;

$su3_caps = $GLOBALS['test_caps'] ?? null;
unset( $GLOBALS['test_caps'] );
remove_all_filters( 'wpac_review_aggregates' );
remove_all_filters( 'wpac_review_aggregate_minimum' );

$su3_a     = (int) AffiliateRepository::create( array( 'name' => 'SU3 Anna', 'email' => 'su3-anna@example.de', 'status' => 'active', 'user_id' => 915931 ) );
$su3_b     = (int) AffiliateRepository::create( array( 'name' => 'SU3 Bert', 'email' => 'su3-bert@example.de', 'status' => 'active' ) );
$su3_order = static function ( $aff, $order, $campaign ) {
	return wpac_record_sale(
		array(
			'source'   => 'su3',
			'order_id' => $order,
			'currency' => 'EUR',
			'items'    => array( array( 'item_id' => '1', 'product_id' => 5, 'name' => 'Kurs SU3', 'net_minor' => 10000 ) ),
			'customer' => array( 'user_id' => 0, 'email' => 'buyer-' . $order . '@example.de' ),
			'touch'    => array( 'code' => CodeRepository::main_code( $aff ), 'method' => 'link', 'ts' => time(), 'campaign' => $campaign ),
		)
	);
};
for ( $i = 1; $i <= 6; $i++ ) {
	$su3_order( $su3_a, 'su3-' . $i, 'spring' );
}
$su3_order( $su3_a, 'su3-7', 'autumn' );
$su3_order( $su3_b, 'su3-b1', 'spring' );
$GLOBALS['su3_fixture'] = array( 'a' => $su3_a, 'b' => $su3_b );

/* ---------------------------------------------------------------------------------------------
 * wpac_get_order_attribution().
 * ------------------------------------------------------------------------------------------- */
$su3_attr = wpac_get_order_attribution( 'su3', 'su3-7' );
check(
	'SU3-1 wpac_get_order_attribution(): partner, campaign, method, status of the order',
	array( 'su3', 'su3-7', $su3_a, 'autumn', 'link', 'attributed', true ),
	is_array( $su3_attr ) ? array( $su3_attr['source'], $su3_attr['order_id'], $su3_attr['affiliate_id'], $su3_attr['campaign'], $su3_attr['method'], $su3_attr['status'], '' !== $su3_attr['created_at'] ) : $su3_attr
);
check( 'SU3-1 fields: no buyer data, only the documented keys', array( 'source', 'order_id', 'affiliate_id', 'campaign', 'method', 'status', 'created_at' ), array_keys( (array) $su3_attr ) );
$su3_err = static function ( $r ) {
	return is_wp_error( $r ) ? $r->get_error_code() : ( null === $r ? 'null' : 'array' );
};
check(
	'SU3-1 unknown order → null; invalid source/order_id → WP_Error; integer order ID accepted',
	array( 'null', 'wpac_invalid_source', 'wpac_invalid_source', 'wpac_invalid_source', 'wpac_invalid_order_id', 'wpac_invalid_order_id', 'wpac_invalid_order_id', 'null' ),
	array(
		$su3_err( wpac_get_order_attribution( 'su3', 'nope' ) ),
		$su3_err( wpac_get_order_attribution( '', 'su3-1' ) ),
		$su3_err( wpac_get_order_attribution( array( 'su3' ), 'su3-1' ) ),
		$su3_err( wpac_get_order_attribution( str_repeat( 's', 41 ), 'su3-1' ) ),
		$su3_err( wpac_get_order_attribution( 'su3', '' ) ),
		$su3_err( wpac_get_order_attribution( 'su3', array( 'su3-1' ) ) ),
		$su3_err( wpac_get_order_attribution( 'su3', str_repeat( '1', 65 ) ) ),
		$su3_err( wpac_get_order_attribution( 'su3', 4711 ) ),
	)
);

/* ---------------------------------------------------------------------------------------------
 * Without a review plugin: nothing is asked, nothing shown.
 * ------------------------------------------------------------------------------------------- */
$su3_ref = array( array( 'affiliate_id' => $su3_a ) );
$su3_one = static function ( array $refs, $days = 90 ) {
	$r = ReviewQuality::get( $refs, $days );
	return $r ? reset( $r ) : array();
};
check( 'SU3-2 without a review plugin: not available, status no_provider, no figures', array( false, 'no_provider', false ), array( ReviewQuality::available(), $su3_one( $su3_ref )['status'], isset( $su3_one( $su3_ref )['reviews'] ) ) );
check( 'SU3-2 invalid references are skipped (no partner, 0, negative, string, not an array)', array(), ReviewQuality::get( array( array(), array( 'affiliate_id' => 0 ), array( 'affiliate_id' => -1 ), array( 'affiliate_id' => 'x' ), 'a' ) ) );

/* ---------------------------------------------------------------------------------------------
 * A review plugin as Review Captain could fill the filter: its own reviews with order reference,
 * mapped to partner and campaign only through wpac_get_order_attribution().
 * ------------------------------------------------------------------------------------------- */
$su3_now                = time();
$su3_day                = 86400;
$GLOBALS['su3_reviews'] = array();
$su3_add                = static function ( $order, $rating, $age_days = 1, $verified = true, $status = 'published' ) use ( $su3_now, $su3_day ) {
	$GLOBALS['su3_reviews'][] = array(
		'id'       => count( $GLOBALS['su3_reviews'] ) + 1,
		'source'   => 'su3',
		'order_id' => $order,
		'rating'   => $rating,
		'verified' => $verified,
		'status'   => $status,
		'date'     => gmdate( 'Y-m-d H:i:s', $su3_now - $age_days * $su3_day ),
		'text'     => 'Geheimer Text ' . $order,
		'email'    => 'buyer-' . $order . '@example.de',
	);
};
// Orders su3-1 … su3-5 (spring): two reviews each = 10 reviews from 5 orders.
foreach ( array( 1 => array( 5, 5 ), 2 => array( 4, 4 ), 3 => array( 5, 3 ), 4 => array( 2, 1 ), 5 => array( 5, 4 ) ) as $su3_o => $su3_r ) {
	foreach ( $su3_r as $su3_rating ) {
		$su3_add( 'su3-' . $su3_o, $su3_rating );
	}
}
$su3_add( 'su3-6', 1, 2, false );              // Not verified: never counted.
$su3_add( 'su3-6', 1, 2, true, 'pending' );    // Not published: never counted.
$su3_add( 'su3-6', 5, 120 );                  // Older than 90 days: only in the 365-day window.
$su3_add( 'su3-7', 2 );                       // Campaign "autumn".
$su3_add( 'unknown-order', 1 );               // No attribution: belongs to no partner.
$GLOBALS['su3_queries'] = array();
$su3_provider           = static function ( $agg, $query ) {
	$GLOBALS['su3_queries'][] = $query;
	$out                      = array();
	foreach ( $GLOBALS['su3_reviews'] as $rv ) {
		if ( ( $query['verified_only'] && ! $rv['verified'] ) || ( $query['published_only'] && 'published' !== $rv['status'] ) || $rv['date'] < $query['from'] || $rv['date'] > $query['to'] ) {
			continue;
		}
		$attr = wpac_get_order_attribution( $rv['source'], $rv['order_id'] );
		if ( ! is_array( $attr ) ) {
			continue;
		}
		foreach ( $query['refs'] as $key => $ref ) {
			if ( $ref['affiliate_id'] !== $attr['affiliate_id'] || ( null !== $ref['campaign'] && $ref['campaign'] !== $attr['campaign'] ) ) {
				continue;
			}
			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = array( 'reviews' => 0, 'orders' => array(), 'stars' => array( 1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0 ) );
			}
			++$out[ $key ]['reviews'];
			$out[ $key ]['orders'][ $rv['order_id'] ] = true;
			++$out[ $key ]['stars'][ $rv['rating'] ];
		}
	}
	foreach ( $out as $key => $v ) {
		$out[ $key ]['orders'] = count( $v['orders'] );
	}
	return $out;
};
add_filter( 'wpac_review_aggregates', $su3_provider, 10, 2 );

$su3_figures = static function ( $r ) {
	return isset( $r['status'] ) && 'ok' === $r['status'] ? array( $r['reviews'], $r['orders'], $r['average'], $r['positive'], $r['negative'] ) : ( $r['status'] ?? null );
};
$su3_refs = array(
	array( 'affiliate_id' => $su3_a ),
	array( 'affiliate_id' => $su3_a, 'campaign' => 'spring' ),
	array( 'affiliate_id' => $su3_a, 'campaign' => 'autumn' ),
	array( 'affiliate_id' => $su3_b ),
);
$su3_res = ReviewQuality::get( $su3_refs );
// Spring: stars 1×1, 2×1, 3×1, 4×3, 5×4 → 38/10 = 3.8; 70 % 4–5 stars, 20 % 1–2 stars.
check(
	'SU3-3 provider available; keys a:<partner> and a:<partner>|c:<campaign>; partner 11 reviews/6 orders, spring 10/5 (3.8, 70 %, 20 %), autumn and Bert below the minimum',
	array( true, array( 'a:' . $su3_a, 'a:' . $su3_a . '|c:spring', 'a:' . $su3_a . '|c:autumn', 'a:' . $su3_b ), array( 11, 6, 3.6, 64, 27 ), array( 10, 5, 3.8, 70, 20 ), 'insufficient_sample', 'insufficient_sample' ),
	array( ReviewQuality::available(), array_keys( $su3_res ), $su3_figures( $su3_res[ 'a:' . $su3_a ] ), $su3_figures( $su3_res[ 'a:' . $su3_a . '|c:spring' ] ), $su3_figures( $su3_res[ 'a:' . $su3_a . '|c:autumn' ] ), $su3_figures( $su3_res[ 'a:' . $su3_b ] ) )
);
check( 'SU3-3 below the minimum: no figures at all (only status and window)', array( 'status', 'days', 'from', 'to', 'computed_at' ), array_keys( $su3_res[ 'a:' . $su3_a . '|c:autumn' ] ) );
$su3_q = end( $GLOBALS['su3_queries'] );
check(
	'SU3-3 query: version 1, one call for all references, verified and published only, rolling 90 days (from/to UTC)',
	array( 1, 1, true, true, 90, 90 * 86400, array( 'affiliate_id' => $su3_a, 'campaign' => 'spring' ), array( 'affiliate_id' => $su3_b, 'campaign' => null ) ),
	array( $su3_q['version'], count( $GLOBALS['su3_queries'] ), $su3_q['verified_only'], $su3_q['published_only'], $su3_q['days'], strtotime( $su3_q['to'] . ' UTC' ) - strtotime( $su3_q['from'] . ' UTC' ), $su3_q['refs'][ 'a:' . $su3_a . '|c:spring' ], $su3_q['refs'][ 'a:' . $su3_b ] )
);
check( 'SU3-3 result window: days, from, to, computed_at as in the query', array( 90, $su3_q['from'], $su3_q['to'] ), array( $su3_res[ 'a:' . $su3_a ]['days'], $su3_res[ 'a:' . $su3_a ]['from'], $su3_res[ 'a:' . $su3_a ]['to'] ) );

// Window: 30/90/365, anything else 90.
$su3_365 = $su3_one( array( array( 'affiliate_id' => $su3_a, 'campaign' => 'spring' ) ), 365 );
check(
	'SU3-4 window: 365 days includes the older review (11 reviews from 6 orders); 45, "abc", -1 fall back to 90; "30" accepted',
	array( array( 11, 6 ), 365, 90, 90, 90, 30 ),
	array( array( $su3_365['reviews'], $su3_365['orders'] ), $su3_365['days'], ReviewQuality::days( 45 ), ReviewQuality::days( 'abc' ), ReviewQuality::days( -1 ), ReviewQuality::days( '30' ) )
);

// Minimum sample: only raised by the filter, never lowered.
add_filter( 'wpac_review_aggregate_minimum', static function () { return array( 'reviews' => 3, 'orders' => 1 ); } );
$su3_low = $su3_one( array( array( 'affiliate_id' => $su3_a, 'campaign' => 'autumn' ) ) );
remove_all_filters( 'wpac_review_aggregate_minimum' );
add_filter( 'wpac_review_aggregate_minimum', static function () { return array( 'reviews' => 11, 'orders' => '9' ); } );
$su3_high = array( ReviewQuality::minimum(), $su3_one( array( array( 'affiliate_id' => $su3_a, 'campaign' => 'spring' ) ) )['status'] );
remove_all_filters( 'wpac_review_aggregate_minimum' );
check(
	'SU3-5 minimum sample: default 10 reviews from 5 orders; the filter cannot lower it; raising to 11 reviews hides spring (10); non-integer values are ignored',
	array( array( 'reviews' => 10, 'orders' => 5 ), 'insufficient_sample', array( array( 'reviews' => 11, 'orders' => 5 ), 'insufficient_sample' ) ),
	array( ReviewQuality::minimum(), $su3_low['status'], $su3_high )
);

// 10 reviews from only 4 orders: below the minimum.
$GLOBALS['su3_reviews'][] = array_merge( $GLOBALS['su3_reviews'][0], array( 'id' => 99, 'order_id' => 'su3-1' ) ); // 11 reviews from 5 orders.
$su3_keep = $GLOBALS['su3_reviews'];
$GLOBALS['su3_reviews'] = array_values( array_filter( $GLOBALS['su3_reviews'], static function ( $rv ) { return ! in_array( $rv['order_id'], array( 'su3-5' ), true ); } ) ); // Orders 1–4: 9 reviews.
$GLOBALS['su3_reviews'][] = array_merge( $su3_keep[0], array( 'id' => 100 ) ); // 10 reviews from 4 orders.
check( 'SU3-5 10 reviews from only 4 different orders: insufficient_sample', 'insufficient_sample', $su3_one( array( array( 'affiliate_id' => $su3_a, 'campaign' => 'spring' ) ) )['status'] );
$GLOBALS['su3_reviews'] = $su3_keep;
array_pop( $GLOBALS['su3_reviews'] ); // Back to the 10 reviews from 5 orders.

// Deletion/withdrawal at the review plugin: the review drops out at the next computation.
$su3_before = $su3_one( array( array( 'affiliate_id' => $su3_a, 'campaign' => 'spring' ) ) )['status'];
array_shift( $GLOBALS['su3_reviews'] );
$su3_after = $su3_one( array( array( 'affiliate_id' => $su3_a, 'campaign' => 'spring' ) ) )['status'];
check( 'SU3-6 a deleted or withdrawn review drops out at the next computation (no copy in Affiliate Captain): ok → insufficient_sample', array( 'ok', 'insufficient_sample' ), array( $su3_before, $su3_after ) );
$su3_add( 'su3-1', 5 ); // Back to 10 reviews from 5 orders.

/* ---------------------------------------------------------------------------------------------
 * Nothing stored, nothing changed.
 * ------------------------------------------------------------------------------------------- */
$su3_tables = $wpdb->get_col( "SHOW TABLES LIKE '" . $wpdb->prefix . "wpac%'" );
$su3_state  = static function () use ( $wpdb, $su3_tables ) {
	$sums = array();
	foreach ( $su3_tables as $tbl ) {
		$row          = $wpdb->get_row( "CHECKSUM TABLE `{$tbl}`" );
		$sums[ $tbl ] = $row ? (string) $row->Checksum : '';
	}
	return array( md5( serialize( $GLOBALS['test_options'] ) ), $sums, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->options ) );
};
$su3_s0    = $su3_state();
$su3_fired = count( $GLOBALS['test_fired'] );
ReviewQuality::get( $su3_refs, 90 );
ReviewQuality::get( $su3_refs, 365 );
check( 'SU3-7 computing stores nothing (all plugin tables, options) and fires no action', array( $su3_s0, $su3_fired ), array( $su3_state(), count( $GLOBALS['test_fired'] ) ) );
check( 'SU3-7 the result carries no review texts, IDs or buyer data', array( false, false, false ), ( static function ( $j ) { return array( false !== strpos( $j, 'Geheimer' ), false !== strpos( $j, '@example.de' ), false !== strpos( $j, '"id"' ) ); } )( (string) wp_json_encode( ReviewQuality::get( $su3_refs ) ) ) );

/* ---------------------------------------------------------------------------------------------
 * Only admins; restricted and erased partners.
 * ------------------------------------------------------------------------------------------- */
$GLOBALS['su3_queries'] = array();
$GLOBALS['test_caps']   = array( Capabilities::MANAGE_PAYOUTS );
$su3_forbidden          = $su3_one( $su3_ref );
unset( $GLOBALS['test_caps'] );
check( 'SU3-8 without wpac_manage_affiliates: status forbidden, no figures, the review plugin is not asked', array( 'forbidden', false, 0 ), array( $su3_forbidden['status'], isset( $su3_forbidden['reviews'] ), count( $GLOBALS['su3_queries'] ) ) );

Restriction::add( $su3_a, Restriction::REQUEST, 'SU3' );
$su3_restricted = ReviewQuality::get( array( array( 'affiliate_id' => $su3_a ), array( 'affiliate_id' => $su3_b ) ) );
$su3_asked      = array_keys( end( $GLOBALS['su3_queries'] )['refs'] );
Restriction::remove( $su3_a, '', false );
check( 'SU3-8 restricted partner (Art. 18): status restricted, not asked; others unaffected', array( 'restricted', 'insufficient_sample', array( 'a:' . $su3_b ) ), array( $su3_restricted[ 'a:' . $su3_a ]['status'], $su3_restricted[ 'a:' . $su3_b ]['status'], $su3_asked ) );

Restriction::add( $su3_b, Restriction::ERASURE );
$GLOBALS['su3_queries'] = array();
check(
	'SU3-8 erased partner (Art. 17) and unknown ID: not_found without asking; wpac_get_order_attribution() of the erased partner\'s order → null',
	array( 'not_found', 'not_found', 0, 'null' ),
	array( $su3_one( array( array( 'affiliate_id' => $su3_b ) ) )['status'], $su3_one( array( array( 'affiliate_id' => 99999999 ) ) )['status'], count( $GLOBALS['su3_queries'] ), $su3_err( wpac_get_order_attribution( 'su3', 'su3-b1' ) ) )
);
Restriction::remove( $su3_b, '', false );

/* ---------------------------------------------------------------------------------------------
 * Malformed answers and a failing review plugin.
 * ------------------------------------------------------------------------------------------- */
remove_all_filters( 'wpac_review_aggregates' );
$su3_answer = null;
add_filter(
	'wpac_review_aggregates',
	static function ( $agg, $query ) use ( &$su3_answer ) {
		return is_callable( $su3_answer ) ? $su3_answer( $query ) : $su3_answer;
	},
	10,
	2
);
$su3_key  = 'a:' . $su3_a;
$su3_try  = static function ( $entry ) use ( &$su3_answer, $su3_key, $su3_one, $su3_ref ) {
	$su3_answer = array( $su3_key => $entry );
	return $su3_one( $su3_ref )['status'];
};
$su3_good = array( 'reviews' => 10, 'orders' => 5, 'stars' => array( 1 => 0, 2 => 0, 3 => 0, 4 => 5, 5 => 5 ) );
check(
	'SU3-9 malformed entries → invalid (sum of stars, more orders than reviews, negative, float, star 6, no stars, not an array); digit strings accepted',
	array( 'ok', 'invalid', 'invalid', 'invalid', 'invalid', 'invalid', 'invalid', 'invalid', 'invalid', 'ok' ),
	array(
		$su3_try( $su3_good ),
		$su3_try( array_merge( $su3_good, array( 'reviews' => 11 ) ) ),
		$su3_try( array_merge( $su3_good, array( 'orders' => 11 ) ) ),
		$su3_try( array_merge( $su3_good, array( 'stars' => array( 1 => -1, 4 => 6, 5 => 5 ) ) ) ),
		$su3_try( array_merge( $su3_good, array( 'reviews' => 10.0 ) ) ),
		$su3_try( array_merge( $su3_good, array( 'stars' => array( 4 => 5, 5 => 4, 6 => 1 ) ) ) ),
		$su3_try( array( 'reviews' => 10, 'orders' => 5 ) ),
		$su3_try( 'ten' ),
		$su3_try( array_merge( $su3_good, array( 'orders' => 0 ) ) ),
		$su3_try( array( 'reviews' => '10', 'orders' => '5', 'stars' => array( '4' => '5', '5' => '5' ) ) ),
	)
);
$su3_answer = 'not an array';
$su3_na     = $su3_one( $su3_ref )['status'];
$su3_answer = array();
$su3_empty  = $su3_one( $su3_ref )['status'];
$su3_answer = static function () {
	throw new \RuntimeException( 'Geheimer Text buyer@example.de' );
};
$su3_thrown = $su3_one( $su3_ref )['status'];
$su3_answer = null;
check( 'SU3-9 answer not an array / key missing → insufficient_sample; review plugin throws → error (caught, page keeps working)', array( 'insufficient_sample', 'insufficient_sample', 'error' ), array( $su3_na, $su3_empty, $su3_thrown ) );
check( 'SU3-9 short()/reason(): figures only with ok', array( '–', true ), array( ReviewQuality::short( array( 'status' => 'insufficient_sample' ) ), false !== strpos( ReviewQuality::reason( array( 'status' => 'insufficient_sample' ) ), '10 reviews from 5' ) ) );

/* ---------------------------------------------------------------------------------------------
 * A review never changes commissions; nothing in partner-facing outputs (sources).
 * ------------------------------------------------------------------------------------------- */
$su3_root = dirname( __DIR__, 2 );
$su3_hits = array();
foreach ( array( 'src/Portal/Portal.php', 'src/Rest/RestApi.php', 'src/Reports/MonthlyMail.php', 'src/Affiliates/Notifications.php', 'src/Commissions/Ledger.php', 'src/Commissions/Calculator.php', 'src/Payouts/PayoutService.php', 'src/Commissions/TierService.php' ) as $su3_f ) {
	if ( false !== stripos( (string) file_get_contents( $su3_root . '/' . $su3_f ), 'ReviewQuality' ) || false !== stripos( (string) file_get_contents( $su3_root . '/' . $su3_f ), 'wpac_review_aggregates' ) ) {
		$su3_hits[] = $su3_f;
	}
}
check( 'SU3-10 the review quality is not used in partner area, partner REST, partner mails, ledger, calculator, payouts or tiers', array(), $su3_hits );

// Record of processing activities: entry 5 only while a review plugin listens.
$su3_ropa_on = array( \WPAffiliateCaptain\Privacy\Privacy::ropa( 'de' ), \WPAffiliateCaptain\Privacy\Privacy::ropa( 'en' ) );
remove_all_filters( 'wpac_review_aggregates' );
$su3_ropa_off = array( \WPAffiliateCaptain\Privacy\Privacy::ropa( 'de' ), \WPAffiliateCaptain\Privacy\Privacy::ropa( 'en' ) );
check(
	'SU3-11 record of processing: entry "Review-Qualität"/"Review quality" only with a review plugin, markers never in the download, the next section follows',
	array( true, true, false, false, true, true ),
	array(
		false !== strpos( $su3_ropa_on[0], '## 5. Review-Qualität der Partner' ) && false !== strpos( $su3_ropa_on[0], 'ab 10 Bewertungen aus 5 verschiedenen Bestellungen' ),
		false !== strpos( $su3_ropa_on[1], '## 5. Review quality of partners' ),
		false !== strpos( $su3_ropa_off[0] . $su3_ropa_off[1], 'Review-Qualität der Partner' ) || false !== strpos( $su3_ropa_off[0] . $su3_ropa_off[1], 'Review quality of partners' ),
		false !== strpos( implode( '', $su3_ropa_on ) . implode( '', $su3_ropa_off ), 'review-quality -->' ),
		false !== strpos( $su3_ropa_off[0], '## Technische und organisatorische Maßnahmen' ),
		false !== strpos( $su3_ropa_off[1], '## Technical and organisational measures' ),
	)
);
if ( null !== $su3_caps ) {
	$GLOBALS['test_caps'] = $su3_caps;
}
