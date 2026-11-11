<?php
/**
 * Pure monthly tier bonus calculation (no WordPress dependency → unit tested).
 *
 * A tier plan has steps [ threshold, rate ] where rate is an ADDITIONAL bonus
 * percentage of the month's net revenue (after refunds).
 *
 * Basis:
 *  - revenue: threshold is a net revenue amount in minor units ("from X € per month")
 *  - orders:  threshold is a number of orders ("from the N-th order per month")
 *
 * Mode:
 *  - retroactive: once a step is reached, its rate applies to the whole month's revenue.
 *  - marginal:    each step's rate only applies to revenue above its threshold
 *                 (revenue basis) or to orders from the N-th order on (orders basis).
 *                 Rates of steps do not add up – the highest reached step applies to each portion.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Commissions;

use WPAffiliateCaptain\Core\Money;

final class Tiers {

	/**
	 * Parse "threshold:rate" lines; returns steps sorted by threshold.
	 *
	 * @param string $text     Input (one step per line, e.g. "1000:2" or "10:1,5").
	 * @param string $basis    revenue|orders.
	 * @param string $currency Currency (for revenue thresholds).
	 * @return array|null Steps or null on invalid input.
	 */
	public static function parse_steps( $text, $basis, $currency ) {
		$steps = array();
		foreach ( preg_split( '/[\r\n;]+/', (string) $text ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$parts = explode( ':', $line );
			if ( 2 !== count( $parts ) ) {
				return null;
			}
			$threshold = Money::normalize_decimal( $parts[0] );
			$rate      = Money::normalize_decimal( $parts[1] );
			if ( null === $threshold || null === $rate || (float) $threshold < 0 || (float) $rate < 0 || (float) $rate > 100 ) {
				return null;
			}
			$steps[] = array(
				'threshold' => 'orders' === $basis ? (int) $threshold : Money::to_minor( $threshold, $currency ),
				'rate'      => $rate,
			);
		}
		if ( ! $steps ) {
			return null;
		}
		usort(
			$steps,
			static function ( $a, $b ) {
				return $a['threshold'] <=> $b['threshold'];
			}
		);
		return $steps;
	}

	/**
	 * Highest step reached by a measure (or null).
	 *
	 * @param array $steps   Sorted steps.
	 * @param int   $measure Measure.
	 * @return array|null
	 */
	public static function reached( array $steps, $measure ) {
		$hit = null;
		foreach ( $steps as $step ) {
			if ( $measure >= $step['threshold'] ) {
				$hit = $step;
			}
		}
		return $hit;
	}

	/**
	 * Calculate the bonus.
	 *
	 * @param array  $orders Chronological list of [ revenue (minor, >= 0) ].
	 * @param array  $steps  Sorted steps.
	 * @param string $basis  revenue|orders.
	 * @param string $mode   marginal|retroactive.
	 * @return array amount, revenue, orders, reached (step|null)
	 */
	public static function bonus( array $orders, array $steps, $basis, $mode ) {
		$revenues = array();
		foreach ( $orders as $o ) {
			$revenues[] = max( 0, (int) $o['revenue'] );
		}
		$total   = array_sum( $revenues );
		$count   = count( array_filter( $revenues ) );
		$measure = 'orders' === $basis ? $count : $total;
		$reached = self::reached( $steps, $measure );
		$amount  = 0;

		if ( $reached ) {
			if ( 'retroactive' === $mode ) {
				$amount = Money::percent_of( $total, $reached['rate'] );
			} elseif ( 'orders' === $basis ) {
				$n = 0;
				foreach ( $revenues as $rev ) {
					if ( $rev <= 0 ) {
						continue; // Fully refunded orders do not count.
					}
					++$n;
					$step = self::reached( $steps, $n );
					if ( $step ) {
						$amount += Money::percent_of( $rev, $step['rate'] );
					}
				}
			} else {
				$n = count( $steps );
				for ( $i = 0; $i < $n; $i++ ) {
					$from    = $steps[ $i ]['threshold'];
					$to      = $i + 1 < $n ? $steps[ $i + 1 ]['threshold'] : PHP_INT_MAX;
					$portion = max( 0, min( $total, $to ) - $from );
					$amount += Money::percent_of( $portion, $steps[ $i ]['rate'] );
				}
			}
		}

		return array(
			'amount'  => $amount,
			'revenue' => $total,
			'orders'  => $count,
			'reached' => $reached,
		);
	}
}
