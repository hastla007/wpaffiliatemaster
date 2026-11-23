<?php
/**
 * Read-only review quality of partners and campaigns (AFF-SU-03, since plugin 0.17.0,
 * provisional until the shared suite specification is accepted).
 *
 * A review plugin (e.g. Review Captain) fills the filter `wpac_review_aggregates` with counts of
 * its verified, published reviews per partner or per partner and campaign. The core asks only on
 * admin screens, for users with `wpac_manage_affiliates`, applies the minimum sample and the
 * rolling window and shows nothing below the minimum. It stores nothing (no option, transient,
 * table row or log entry with review data), never changes commissions, rules, tiers or payouts,
 * and knows nothing about the review plugin beyond the filter having a callback.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Reports;

use WPAffiliateCaptain\Core\Capabilities;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Log;
use WPAffiliateCaptain\Privacy\Restriction;

defined( 'ABSPATH' ) || exit;

/**
 * Review quality aggregates (read only, never stored).
 */
final class ReviewQuality {

	/** Filter a review plugin fills: ( array $aggregates, array $query ). */
	const FILTER = 'wpac_review_aggregates';

	/** Minimum number of reviews in the window. */
	const MIN_REVIEWS = 10;

	/** Minimum number of different orders these reviews come from. */
	const MIN_ORDERS = 5;

	/** Rolling window in days (default). */
	const DEFAULT_DAYS = 90;

	/** Selectable windows in days. */
	const DAYS = array( 30, 90, 365 );

	/** Version of the query and answer format. */
	const VERSION = 1;

	/**
	 * Whether a review plugin listens (a callback on the filter). The core does not check which.
	 *
	 * @return bool
	 */
	public static function available() {
		return function_exists( 'has_filter' ) && false !== has_filter( self::FILTER );
	}

	/**
	 * Window in days: 30, 90 or 365; anything else is the default (90).
	 *
	 * @param mixed $raw Requested days.
	 * @return int
	 */
	public static function days( $raw ) {
		$days = is_scalar( $raw ) && ctype_digit( (string) $raw ) ? (int) $raw : 0;
		return in_array( $days, self::DAYS, true ) ? $days : self::DEFAULT_DAYS;
	}

	/**
	 * Minimum sample. The filter `wpac_review_aggregate_minimum` may only raise it, never lower it
	 * below 10 reviews from 5 orders.
	 *
	 * @return array{reviews:int, orders:int}
	 */
	public static function minimum() {
		$min = array(
			'reviews' => self::MIN_REVIEWS,
			'orders'  => self::MIN_ORDERS,
		);
		/**
		 * Raise the minimum sample of the review quality (never below 10 reviews from 5 orders).
		 *
		 * @since plugin 0.17.0
		 * @param array $min array( 'reviews' => int, 'orders' => int ).
		 */
		$filtered = apply_filters( 'wpac_review_aggregate_minimum', $min );
		if ( is_array( $filtered ) ) {
			foreach ( $min as $k => $v ) {
				if ( isset( $filtered[ $k ] ) && is_int( $filtered[ $k ] ) && $filtered[ $k ] > $v ) {
					$min[ $k ] = $filtered[ $k ];
				}
			}
		}
		$min['orders'] = min( $min['orders'], $min['reviews'] ); // Never more orders than reviews.
		return $min;
	}

	/**
	 * Key of a reference in query and answer: "a:<partner>" (all reviews of the partner) or
	 * "a:<partner>|c:<campaign>" (reviews of orders of this partner and campaign; '' = without
	 * campaign).
	 *
	 * @param int         $affiliate_id Partner.
	 * @param string|null $campaign     Campaign, null = all.
	 * @return string
	 */
	public static function key( $affiliate_id, $campaign = null ) {
		return 'a:' . (int) $affiliate_id . ( null === $campaign ? '' : '|c:' . (string) $campaign );
	}

	/**
	 * Aggregates for the references, keyed by key(). Each result has `status` and `days`, `from`,
	 * `to`, `computed_at` (UTC); with status `ok` also `reviews`, `orders`, `average` (one
	 * decimal), `positive` and `negative` (percent of 4–5 and of 1–2 stars, whole numbers).
	 * Other statuses carry no figures: `insufficient_sample`, `no_provider`, `forbidden` (current
	 * user lacks wpac_manage_affiliates), `not_found` (unknown or erased partner), `restricted`
	 * (processing restricted, Art. 18 GDPR), `invalid` (malformed answer of the review plugin),
	 * `error` (the review plugin failed).
	 *
	 * @param array $refs List of array( 'affiliate_id' => int, 'campaign' => string|null ).
	 * @param int   $days Window (30, 90 or 365).
	 * @return array<string, array>
	 */
	public static function get( array $refs, $days = self::DEFAULT_DAYS ) {
		$days   = self::days( $days );
		$now    = time();
		$window = array(
			'days'        => $days,
			'from'        => gmdate( 'Y-m-d H:i:s', $now - $days * DAY_IN_SECONDS ),
			'to'          => gmdate( 'Y-m-d H:i:s', $now ),
			'computed_at' => gmdate( 'Y-m-d H:i:s', $now ),
		);
		$clean  = array();
		foreach ( $refs as $ref ) {
			if ( ! is_array( $ref ) || ! isset( $ref['affiliate_id'] ) || ! is_scalar( $ref['affiliate_id'] ) || ! ctype_digit( (string) $ref['affiliate_id'] ) || (int) $ref['affiliate_id'] < 1 ) {
				continue;
			}
			$campaign = isset( $ref['campaign'] ) && is_scalar( $ref['campaign'] ) ? Db::cut( (string) $ref['campaign'], 100 ) : null;
			$key      = self::key( (int) $ref['affiliate_id'], $campaign );
			$clean[ $key ] = array(
				'affiliate_id' => (int) $ref['affiliate_id'],
				'campaign'     => $campaign,
			);
		}
		$out = array();
		if ( ! $clean ) {
			return $out;
		}
		$status = ! current_user_can( Capabilities::MANAGE_AFFILIATES ) ? 'forbidden' : ( self::available() ? '' : 'no_provider' );
		if ( '' !== $status ) {
			foreach ( array_keys( $clean ) as $key ) {
				$out[ $key ] = array( 'status' => $status ) + $window;
			}
			return $out;
		}

		// Unknown, erased and restricted partners: the review plugin is not even asked.
		$known = self::existing( array_unique( array_column( $clean, 'affiliate_id' ) ) );
		$ask   = array();
		foreach ( $clean as $key => $ref ) {
			$restriction = Restriction::get( $ref['affiliate_id'] );
			if ( ! isset( $known[ $ref['affiliate_id'] ] ) || ( $restriction && Restriction::ERASURE === $restriction['kind'] ) ) {
				$out[ $key ] = array( 'status' => 'not_found' ) + $window;
			} elseif ( $restriction ) {
				$out[ $key ] = array( 'status' => 'restricted' ) + $window;
			} else {
				$ask[ $key ] = $ref;
			}
		}
		if ( ! $ask ) {
			return $out;
		}

		$query = array(
			'version'        => self::VERSION,
			'refs'           => $ask,
			'days'           => $days,
			'from'           => $window['from'],
			'to'             => $window['to'],
			'verified_only'  => true,
			'published_only' => true,
		);
		try {
			/**
			 * Review counts per reference (filled by a review plugin; read only).
			 *
			 * Answer per key of `$query['refs']`: array( 'reviews' => int, 'orders' => int (different
			 * orders), 'stars' => array( 1 => int, …, 5 => int ) ), counting only verified (with an
			 * order), published reviews dated within `from`–`to` (UTC). No texts, no single values,
			 * no customer data. Missing keys count as no reviews.
			 *
			 * @since plugin 0.17.0 (provisional until the shared suite specification is accepted)
			 * @param array $aggregates Empty array.
			 * @param array $query      version, refs (key => affiliate_id, campaign|null), days, from,
			 *                          to, verified_only, published_only.
			 */
			$answer = apply_filters( self::FILTER, array(), $query );
		} catch ( \Throwable $e ) {
			Log::error( 'Review aggregates: the review plugin failed.', array( 'class' => get_class( $e ) ) ); // No message: it could carry review data.
			foreach ( array_keys( $ask ) as $key ) {
				$out[ $key ] = array( 'status' => 'error' ) + $window;
			}
			return $out;
		}
		$answer = is_array( $answer ) ? $answer : array();
		$min    = self::minimum();
		foreach ( array_keys( $ask ) as $key ) {
			$out[ $key ] = self::evaluate( array_key_exists( $key, $answer ) ? $answer[ $key ] : null, $min ) + $window;
		}
		return $out;
	}

	/**
	 * Result of one answer entry.
	 *
	 * @param mixed $entry Answer of the review plugin for one key (null = none).
	 * @param array $min   Minimum sample.
	 * @return array
	 */
	private static function evaluate( $entry, array $min ) {
		if ( null === $entry ) {
			return array( 'status' => 'insufficient_sample' );
		}
		if ( ! is_array( $entry ) || ! isset( $entry['stars'] ) || ! is_array( $entry['stars'] ) ) {
			return array( 'status' => 'invalid' );
		}
		$reviews = self::whole( isset( $entry['reviews'] ) ? $entry['reviews'] : null );
		$orders  = self::whole( isset( $entry['orders'] ) ? $entry['orders'] : null );
		$stars   = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$stars[ $i ] = self::whole( array_key_exists( $i, $entry['stars'] ) ? $entry['stars'][ $i ] : 0 );
		}
		$extra = array_diff( array_map( 'strval', array_keys( $entry['stars'] ) ), array( '1', '2', '3', '4', '5' ) );
		if ( null === $reviews || null === $orders || in_array( null, $stars, true ) || $extra || array_sum( $stars ) !== $reviews || $orders > $reviews || ( $reviews > 0 && $orders < 1 ) ) {
			return array( 'status' => 'invalid' );
		}
		if ( $reviews < $min['reviews'] || $orders < $min['orders'] ) {
			return array( 'status' => 'insufficient_sample' );
		}
		$sum = 0;
		foreach ( $stars as $i => $n ) {
			$sum += $i * $n;
		}
		return array(
			'status'   => 'ok',
			'reviews'  => $reviews,
			'orders'   => $orders,
			'average'  => round( $sum / $reviews, 1 ),
			'positive' => (int) round( 100 * ( $stars[4] + $stars[5] ) / $reviews ),
			'negative' => (int) round( 100 * ( $stars[1] + $stars[2] ) / $reviews ),
		);
	}

	/**
	 * Whole number ≥ 0 (integer or digit string), else null.
	 *
	 * @param mixed $v Value.
	 * @return int|null
	 */
	private static function whole( $v ) {
		if ( is_int( $v ) ) {
			return $v >= 0 ? $v : null;
		}
		return is_string( $v ) && '' !== $v && strlen( $v ) < 10 && ctype_digit( $v ) ? (int) $v : null;
	}

	/**
	 * IDs of existing partners among the given ones.
	 *
	 * @param int[] $ids Partner IDs.
	 * @return array<int, true>
	 */
	private static function existing( array $ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		$t     = Db::table( 'affiliates' );
		$found = array();
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table name; one %d per ID.
			$rows = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE id IN (" . implode( ',', array_fill( 0, count( $chunk ), '%d' ) ) . ')', $chunk ) );
			foreach ( (array) $rows as $id ) {
				$found[ (int) $id ] = true;
			}
		}
		return $found;
	}

	/**
	 * Plain-text summary of one result for an admin screen (escaped by the caller).
	 *
	 * @param array $r Result of get().
	 * @return string
	 */
	public static function short( array $r ) {
		if ( isset( $r['status'] ) && 'ok' === $r['status'] ) {
			/* translators: 1: average rating, 2: number of reviews */
			return sprintf( __( '%1$s / 5 (%2$s reviews)', 'affiliate-captain' ), number_format_i18n( $r['average'], 1 ), number_format_i18n( $r['reviews'] ) );
		}
		return '–';
	}

	/**
	 * Explanation of a result without figures (escaped by the caller).
	 *
	 * @param array $r Result of get().
	 * @return string
	 */
	public static function reason( array $r ) {
		$min = self::minimum();
		switch ( isset( $r['status'] ) ? $r['status'] : '' ) {
			case 'ok':
				return '';
			case 'insufficient_sample':
				/* translators: 1: minimum number of reviews, 2: minimum number of orders */
				return sprintf( __( 'Not enough reviews in this period (at least %1$d reviews from %2$d different orders). No figures are shown.', 'affiliate-captain' ), $min['reviews'], $min['orders'] );
			case 'restricted':
				return __( 'Processing of this partner is restricted (Art. 18 GDPR). No review figures are shown.', 'affiliate-captain' );
			case 'not_found':
				return __( 'Partner not found.', 'affiliate-captain' );
			case 'invalid':
			case 'error':
				return __( 'The review plugin returned no valid data.', 'affiliate-captain' );
			default:
				return __( 'No review figures available.', 'affiliate-captain' );
		}
	}
}
