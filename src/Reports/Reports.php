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
	 * Days are the site's local days (time zone in the WordPress settings); stored times are UTC.
	 *
	 * @param string    $from         Y-m-d (local).
	 * @param string    $to           Y-m-d (local).
	 * @param int|int[] $affiliate_id Optional single partner, or a list of partners (e.g. one mail batch).
	 * @return array
	 */
	public static function partners( $from, $to, $affiliate_id = 0 ) {
		global $wpdb;
		self::$unconverted = array();
		$c                 = Db::table( 'commissions' );
		$v                 = Db::table( 'visits' );
		$at                = Db::table( 'attributions' );
		list( $start, $end ) = Db::local_range( $from, $to );
		$aff_sql           = self::affiliate_sql( $affiliate_id );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$money = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT affiliate_id, currency,
					SUM(CASE WHEN kind = 'sale' AND status <> 'rejected' THEN base_minor ELSE 0 END) revenue,
					SUM(CASE WHEN kind = 'sale' AND status <> 'rejected' THEN amount_minor ELSE 0 END) commission,
					SUM(CASE WHEN kind IN ('refund','cancellation','chargeback','reinstatement','adjustment') AND status <> 'rejected' THEN amount_minor ELSE 0 END) corrections,
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
		/**
		 * Partner report rows (per partner and currency), e.g. extra figures of an add-on.
		 *
		 * @since contract 1.2.0
		 * @param array  $rows         Rows.
		 * @param string $from         From (Y-m-d).
		 * @param string $to           To (Y-m-d).
		 * @param int    $affiliate_id Only this partner (0 = all).
		 */
		$rows = apply_filters( 'wpac_report_partners', array_values( $rows ), $from, $to, is_array( $affiliate_id ) ? 0 : (int) $affiliate_id );
		return is_array( $rows ) ? array_values( $rows ) : array();
	}

	/**
	 * Report for admin screens, cached until the data changes: the dashboard shows the same
	 * report on every visit (KPIs and table), and the aggregates scan the whole period. The cache
	 * key contains a fingerprint of the newest commission, visit and attribution (indexed MAX()
	 * lookups) and of the currency settings, so a new sale or refund shows at once.
	 *
	 * @param string $kind partners|content_id|campaign.
	 * @param string $from Y-m-d (local).
	 * @param string $to   Y-m-d (local).
	 * @return array Rows (self::$unconverted is set as by the uncached call).
	 */
	public static function cached( $kind, $from, $to ) {
		global $wpdb;
		$state = array( $kind, $from, $to, (string) \WPAffiliateCaptain\Core\Settings::get( 'currency' ), wp_json_encode( Fx::rates() ), function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : '', function_exists( 'determine_locale' ) ? determine_locale() : '' ); // Labels such as "(no campaign)" are translated.
		foreach ( array( 'commissions' => 'MAX(id), MAX(updated_at)', 'visits' => 'MAX(id)', 'attributions' => 'MAX(id)' ) as $table => $expr ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- constants only.
			$state[] = wp_json_encode( $wpdb->get_row( "SELECT {$expr} FROM " . Db::table( $table ) ) );
		}
		$key = 'wpac_report_' . md5( implode( '|', $state ) );
		$hit = get_transient( $key );
		if ( is_array( $hit ) && isset( $hit['rows'], $hit['unconverted'] ) ) {
			self::$unconverted = (array) $hit['unconverted'];
			return (array) $hit['rows'];
		}
		$rows = 'partners' === $kind ? self::partners( $from, $to ) : self::by_dimension( $kind, $from, $to );
		set_transient(
			$key,
			array(
				'rows'        => $rows,
				'unconverted' => self::$unconverted,
			),
			10 * MINUTE_IN_SECONDS
		);
		return $rows;
	}

	/**
	 * Condition on the partner (one ID or a list).
	 *
	 * @param int|int[] $affiliate_id Partner(s), 0/empty = all.
	 * @return string SQL (prepared/escaped).
	 */
	private static function affiliate_sql( $affiliate_id ) {
		global $wpdb;
		if ( is_array( $affiliate_id ) ) {
			$ids = array_values( array_unique( array_filter( array_map( 'intval', $affiliate_id ) ) ) );
			return $ids ? ' AND affiliate_id IN (' . implode( ',', $ids ) . ')' : ' AND 1=0';
		}
		return $affiliate_id ? $wpdb->prepare( ' AND affiliate_id = %d', $affiliate_id ) : '';
	}

	/**
	 * Partners with activity (commission, visit or attributed order) in a period, in ID order –
	 * for cutting batches by partner (monthly mails) without computing the whole report.
	 *
	 * @param string $from  Y-m-d (local).
	 * @param string $to    Y-m-d (local).
	 * @param int    $after Only IDs above this one.
	 * @param int    $limit Max IDs.
	 * @return int[]
	 */
	public static function active_partners( $from, $to, $after = 0, $limit = 50 ) {
		global $wpdb;
		list( $start, $end ) = Db::local_range( $from, $to );
		$ids = array();
		foreach ( array( 'commissions', 'visits', 'attributions' ) as $table ) {
			$t = Db::table( $table );
			// Index (created_at, affiliate_id) resp. (affiliate_id, created_at).
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT affiliate_id FROM {$t} WHERE created_at BETWEEN %s AND %s AND affiliate_id > %d ORDER BY affiliate_id ASC LIMIT %d", $start, $end, (int) $after, (int) $limit ) ) as $id ) {
				$ids[ (int) $id ] = (int) $id;
			}
		}
		ksort( $ids );
		return array_slice( array_values( $ids ), 0, (int) $limit );
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
		list( $start, $end ) = Db::local_range( $from, $to );
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
				$r['label'] = (int) $r['key'] ? ( get_the_title( (int) $r['key'] ) ? get_the_title( (int) $r['key'] ) : '#' . $r['key'] ) : __( '(no content / direct)', 'affiliate-captain' );
			} else {
				$r['label'] = '' !== $r['key'] ? $r['key'] : __( '(no campaign)', 'affiliate-captain' );
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
