<?php
/**
 * Reports: by partner, by content (post/page/CPT) and by campaign.
 *
 * Amounts in other currencies are converted into the program currency with the
 * configured reference rates (reporting only). Currencies without a rate are
 * excluded from money columns and listed in "unconverted".
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Reports;

use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Fx;

defined( 'ABSPATH' ) || exit;

final class Reports {

	/** @var string[] Currencies without reference rate seen in the last report. */
	public static $unconverted = array();

	/**
	 * Add a converted amount to a row.
	 *
	 * @param array  $row      Row.
	 * @param string $key      Key.
	 * @param int    $minor    Amount.
	 * @param string $currency Currency.
	 */
	private static function add( array &$row, $key, $minor, $currency ) {
		if ( null === $currency || '' === $currency ) {
			return;
		}
		$conv = Fx::convert( (int) $minor, $currency );
		if ( null === $conv ) {
			self::$unconverted[ $currency ] = true;
			return;
		}
		$row[ $key ] += $conv;
	}

	/**
	 * Per-partner report.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @param int    $affiliate_id Optional single partner.
	 * @return array
	 */
	public static function partners( $from, $to, $affiliate_id = 0 ) {
		global $wpdb;
		self::$unconverted = array();
		$c                 = Db::table( 'commissions' );
		$v                 = Db::table( 'visits' );
		$at                = Db::table( 'attributions' );
		$start             = $from . ' 00:00:00';
		$end               = $to . ' 23:59:59';
		$aff_sql           = $affiliate_id ? $wpdb->prepare( ' AND affiliate_id = %d', $affiliate_id ) : '';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$money = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT affiliate_id, currency,
					SUM(CASE WHEN kind = 'sale' AND status <> 'rejected' THEN base_minor ELSE 0 END) revenue,
					SUM(CASE WHEN kind = 'sale' AND status <> 'rejected' THEN amount_minor ELSE 0 END) commission,
					SUM(CASE WHEN kind IN ('refund','cancellation','chargeback','reinstatement') AND status <> 'rejected' THEN amount_minor ELSE 0 END) corrections,
					SUM(CASE WHEN kind = 'bonus' AND status <> 'rejected' THEN amount_minor ELSE 0 END) bonus,
					SUM(CASE WHEN kind = 'writeoff' AND status <> 'rejected' THEN amount_minor ELSE 0 END) writeoffs
				FROM {$c} WHERE created_at BETWEEN %s AND %s{$aff_sql} GROUP BY affiliate_id, currency",
				$start,
				$end
			)
		);
		$visits = $wpdb->get_results( $wpdb->prepare( "SELECT affiliate_id, COUNT(*) n FROM {$v} WHERE created_at BETWEEN %s AND %s{$aff_sql} GROUP BY affiliate_id", $start, $end ), OBJECT_K );
		$orders = $wpdb->get_results( $wpdb->prepare( "SELECT affiliate_id, COUNT(*) n FROM {$at} WHERE created_at BETWEEN %s AND %s{$aff_sql} GROUP BY affiliate_id", $start, $end ), OBJECT_K );
		// phpcs:enable
		AffiliateRepository::find_many( array_merge( wp_list_pluck( $money, 'affiliate_id' ), array_keys( $visits ), array_keys( $orders ) ) );
		$rows = array();
		$init = static function ( $id ) {
			$aff = AffiliateRepository::find( (int) $id );
			return array(
				'affiliate_id' => (int) $id,
				'name'         => $aff ? $aff->name : '#' . $id,
				'visits'       => 0,
				'orders'       => 0,
				'revenue'      => 0,
				'commission'   => 0,
				'corrections'  => 0,
				'bonus'        => 0,
				'writeoffs'    => 0,
				'refund_rate'  => 0,
			);
		};
		foreach ( $money as $m ) {
			$id = (int) $m->affiliate_id;
			if ( ! isset( $rows[ $id ] ) ) {
				$rows[ $id ] = $init( $id );
			}
			foreach ( array( 'revenue', 'commission', 'corrections', 'bonus', 'writeoffs' ) as $k ) {
				self::add( $rows[ $id ], $k, (int) $m->$k, $m->currency );
			}
		}
		foreach ( array( 'visits' => $visits, 'orders' => $orders ) as $k => $list ) {
			foreach ( $list as $id => $r ) {
				if ( ! isset( $rows[ $id ] ) ) {
					$rows[ $id ] = $init( $id );
				}
				$rows[ $id ][ $k ] = (int) $r->n;
			}
		}
		foreach ( $rows as &$r ) {
			$r['refund_rate'] = $r['commission'] > 0 ? round( abs( $r['corrections'] ) * 100 / $r['commission'], 1 ) : 0;
		}
		unset( $r );
		usort(
			$rows,
			static function ( $a, $b ) {
				return $b['commission'] <=> $a['commission'];
			}
		);
		return array_values( $rows );
	}

	/**
	 * Report by a visit/attribution dimension.
	 *
	 * @param string $dimension content_id|campaign.
	 * @param string $from      Y-m-d.
	 * @param string $to        Y-m-d.
	 * @return array
	 */
	public static function by_dimension( $dimension, $from, $to ) {
		global $wpdb;
		self::$unconverted = array();
		$dim               = 'campaign' === $dimension ? 'campaign' : 'content_id';
		$v                 = Db::table( 'visits' );
		$at                = Db::table( 'attributions' );
		$c                 = Db::table( 'commissions' );
		$start             = $from . ' 00:00:00';
		$end               = $to . ' 23:59:59';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$visits = $wpdb->get_results( $wpdb->prepare( "SELECT {$dim} k, COUNT(*) n, COUNT(DISTINCT affiliate_id) partners FROM {$v} WHERE created_at BETWEEN %s AND %s GROUP BY k", $start, $end ), OBJECT_K );
		$sales  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.{$dim} k, c.currency, COUNT(DISTINCT a.id) orders,
					SUM(CASE WHEN c.kind = 'sale' THEN c.base_minor ELSE 0 END) revenue,
					SUM(COALESCE(c.amount_minor,0)) commission
				FROM {$at} a LEFT JOIN {$c} c ON c.source = a.source AND c.source_order_id = a.source_order_id AND c.status <> 'rejected' AND c.kind <> 'bonus'
				WHERE a.created_at BETWEEN %s AND %s GROUP BY k, c.currency",
				$start,
				$end
			)
		);
		// phpcs:enable
		$rows = array();
		$init = static function ( $k ) {
			return array(
				'key'        => (string) $k,
				'visits'     => 0,
				'partners'   => 0,
				'orders'     => 0,
				'revenue'    => 0,
				'commission' => 0,
			);
		};
		foreach ( $visits as $k => $r ) {
			$rows[ $k ]             = $init( $k );
			$rows[ $k ]['visits']   = (int) $r->n;
			$rows[ $k ]['partners'] = (int) $r->partners;
		}
		foreach ( $sales as $s ) {
			$k = (string) $s->k;
			if ( ! isset( $rows[ $k ] ) ) {
				$rows[ $k ] = $init( $k );
			}
			$rows[ $k ]['orders'] += (int) $s->orders;
			self::add( $rows[ $k ], 'revenue', (int) $s->revenue, $s->currency );
			self::add( $rows[ $k ], 'commission', (int) $s->commission, $s->currency );
		}
		if ( 'content_id' === $dim && function_exists( '_prime_post_caches' ) ) {
			// One query for all titles instead of one per row.
			_prime_post_caches( array_filter( array_map( 'intval', array_column( $rows, 'key' ) ) ), false, false );
		}
		foreach ( $rows as &$r ) {
			$r['conversion'] = $r['visits'] > 0 ? round( $r['orders'] * 100 / $r['visits'], 1 ) : 0;
			if ( 'content_id' === $dim ) {
				$r['label'] = (int) $r['key'] ? ( get_the_title( (int) $r['key'] ) ? get_the_title( (int) $r['key'] ) : '#' . $r['key'] ) : __( '(no content / direct)', 'wp-affiliate-captain' );
			} else {
				$r['label'] = '' !== $r['key'] ? $r['key'] : __( '(no campaign)', 'wp-affiliate-captain' );
			}
		}
		unset( $r );
		usort(
			$rows,
			static function ( $a, $b ) {
				return array( $b['revenue'], $b['visits'] ) <=> array( $a['revenue'], $a['visits'] );
			}
		);
		return array_values( $rows );
	}

	/**
	 * Stats of one content item (editor panel) – targeted queries for this ID only.
	 *
	 * @param int $post_id Post.
	 * @param int $days    Days.
	 * @return array visits, partners, orders, revenue, commission, conversion
	 */
	public static function for_content( $post_id, $days = 90 ) {
		global $wpdb;
		self::$unconverted = array();
		$since             = Db::now( time() - $days * DAY_IN_SECONDS );
		$v                 = Db::table( 'visits' );
		$at                = Db::table( 'attributions' );
		$c                 = Db::table( 'commissions' );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$visits = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) n, COUNT(DISTINCT affiliate_id) partners FROM {$v} WHERE content_id = %d AND created_at >= %s", $post_id, $since ) );
		$sales  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.currency, COUNT(DISTINCT a.id) orders,
					SUM(CASE WHEN c.kind = 'sale' THEN c.base_minor ELSE 0 END) revenue,
					SUM(COALESCE(c.amount_minor,0)) commission
				FROM {$at} a LEFT JOIN {$c} c ON c.source = a.source AND c.source_order_id = a.source_order_id AND c.status <> 'rejected' AND c.kind <> 'bonus'
				WHERE a.content_id = %d AND a.created_at >= %s GROUP BY c.currency",
				$post_id,
				$since
			)
		);
		// phpcs:enable
		$row = array(
			'visits'     => $visits ? (int) $visits->n : 0,
			'partners'   => $visits ? (int) $visits->partners : 0,
			'orders'     => 0,
			'revenue'    => 0,
			'commission' => 0,
		);
		foreach ( $sales as $s ) {
			$row['orders'] += (int) $s->orders;
			self::add( $row, 'revenue', (int) $s->revenue, $s->currency );
			self::add( $row, 'commission', (int) $s->commission, $s->currency );
		}
		$row['conversion'] = $row['visits'] > 0 ? round( $row['orders'] * 100 / $row['visits'], 1 ) : 0;
		return $row;
	}
}
