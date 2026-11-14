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
		$rows = apply_filters( 'wpac_report_partners', array_values( $rows ), $from, $to, (int) $affiliate_id );
		return is_array( $rows ) ? array_values( $rows ) : array();
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
	 * Orders of a partner in a period that count as sales: a sale commission created in the
	 * period that is not rejected and not completely reversed (full refund, cancellation).
	 * Orders without any commission amount (0 % rule) still count.
	 *
	 * @param int    $affiliate_id Partner.
	 * @param string $start        From (Y-m-d H:i:s, UTC).
	 * @param string $end          To (Y-m-d H:i:s, UTC).
	 * @return array[] source, source_order_id, created_at
	 */
	private static function partner_sales( $affiliate_id, $start, $end ) {
		global $wpdb;
		$c = Db::table( 'commissions' );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.source, c.source_order_id, MIN(s.created_at) created_at,
					SUM(c.amount_minor) net, SUM(CASE WHEN c.kind = 'sale' THEN c.amount_minor ELSE 0 END) gross
				FROM {$c} c JOIN (
					SELECT source, source_order_id, MIN(created_at) created_at FROM {$c}
					WHERE affiliate_id = %d AND kind = 'sale' AND status <> 'rejected' AND created_at BETWEEN %s AND %s
					GROUP BY source, source_order_id
				) s ON s.source = c.source AND s.source_order_id = c.source_order_id
				WHERE c.affiliate_id = %d AND c.status <> 'rejected' AND c.kind <> 'bonus'
				GROUP BY c.source, c.source_order_id",
				$affiliate_id,
				$start,
				$end,
				$affiliate_id
			)
		);
		// phpcs:enable
		$out = array();
		foreach ( (array) $rows as $r ) {
			if ( (int) $r->net > 0 || (int) $r->gross <= 0 ) {
				$out[] = array(
					'source'          => (string) $r->source,
					'source_order_id' => (string) $r->source_order_id,
					'created_at'      => (string) $r->created_at,
				);
			}
		}
		return $out;
	}

	/**
	 * Statistics of one partner for the partner area: visits, sales, conversion rate and the
	 * commissions earned in the period per currency (amounts in the booking currency, no conversion).
	 *
	 * @param int    $affiliate_id Partner.
	 * @param string $from         Y-m-d.
	 * @param string $to           Y-m-d.
	 * @return array visits, sales, conversion, earned (currency => minor)
	 */
	public static function partner_summary( $affiliate_id, $from, $to ) {
		global $wpdb;
		$start = $from . ' 00:00:00';
		$end   = $to . ' 23:59:59';
		$v     = Db::table( 'visits' );
		$c     = Db::table( 'commissions' );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$visits = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$v} WHERE affiliate_id = %d AND created_at BETWEEN %s AND %s", $affiliate_id, $start, $end ) );
		$earned = $wpdb->get_results( $wpdb->prepare( "SELECT currency, SUM(amount_minor) total FROM {$c} WHERE affiliate_id = %d AND status <> 'rejected' AND created_at BETWEEN %s AND %s GROUP BY currency", $affiliate_id, $start, $end ) );
		// phpcs:enable
		$sales = count( self::partner_sales( (int) $affiliate_id, $start, $end ) );
		$money = array();
		foreach ( (array) $earned as $e ) {
			$money[ (string) $e->currency ] = (int) $e->total;
		}
		return array(
			'visits'     => $visits,
			'sales'      => $sales,
			'conversion' => $visits > 0 ? round( $sales * 100 / $visits, 1 ) : 0,
			'earned'     => $money,
		);
	}

	/**
	 * Monthly figures of one partner (newest first): sales and commissions earned per currency.
	 *
	 * @param int $affiliate_id Partner.
	 * @param int $months       Number of months including the current one.
	 * @return array Y-m => [ sales, earned => [ currency => minor ] ]
	 */
	public static function partner_months( $affiliate_id, $months = 12 ) {
		global $wpdb;
		$months = max( 1, min( 36, (int) $months ) );
		$first  = gmdate( 'Y-m-01', (int) strtotime( gmdate( 'Y-m-01' ) . ' -' . ( $months - 1 ) . ' months' ) );
		$start  = $first . ' 00:00:00';
		$end    = gmdate( 'Y-m-d' ) . ' 23:59:59';
		$out    = array();
		for ( $i = 0; $i < $months; $i++ ) {
			$out[ gmdate( 'Y-m', (int) strtotime( gmdate( 'Y-m-01' ) . ' -' . $i . ' months' ) ) ] = array(
				'sales'  => 0,
				'earned' => array(),
			);
		}
		$c = Db::table( 'commissions' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT LEFT(created_at, 7) m, currency, SUM(amount_minor) total FROM {$c} WHERE affiliate_id = %d AND status <> 'rejected' AND created_at BETWEEN %s AND %s GROUP BY m, currency", $affiliate_id, $start, $end ) );
		foreach ( (array) $rows as $r ) {
			if ( isset( $out[ $r->m ] ) ) {
				$out[ $r->m ]['earned'][ (string) $r->currency ] = (int) $r->total;
			}
		}
		foreach ( self::partner_sales( (int) $affiliate_id, $start, $end ) as $s ) {
			$m = substr( $s['created_at'], 0, 7 );
			if ( isset( $out[ $m ] ) ) {
				++$out[ $m ]['sales'];
			}
		}
		return $out;
	}

	/**
	 * Report of one partner by campaign (utm_campaign) and sub-ID: visits, orders, conversion
	 * and commission per currency. Only the partner's own visits and orders are counted, so
	 * campaigns with the same name of other partners never mix.
	 *
	 * @param int    $affiliate_id Partner.
	 * @param string $from         Y-m-d.
	 * @param string $to           Y-m-d.
	 * @return array[] campaign, sub_id, label, visits, orders, conversion, commission (currency => minor)
	 */
	public static function partner_campaigns( $affiliate_id, $from, $to ) {
		global $wpdb;
		$start = $from . ' 00:00:00';
		$end   = $to . ' 23:59:59';
		$v     = Db::table( 'visits' );
		$at    = Db::table( 'attributions' );
		$c     = Db::table( 'commissions' );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$visits = $wpdb->get_results( $wpdb->prepare( "SELECT campaign, sub_id, COUNT(*) n FROM {$v} WHERE affiliate_id = %d AND created_at BETWEEN %s AND %s GROUP BY campaign, sub_id", $affiliate_id, $start, $end ) );
		$sales  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.campaign, a.sub_id, c.currency, COUNT(DISTINCT a.id) orders, SUM(COALESCE(c.amount_minor,0)) commission
				FROM {$at} a LEFT JOIN {$c} c ON c.source = a.source AND c.source_order_id = a.source_order_id AND c.affiliate_id = a.affiliate_id AND c.status <> 'rejected' AND c.kind <> 'bonus'
				WHERE a.affiliate_id = %d AND a.created_at BETWEEN %s AND %s GROUP BY a.campaign, a.sub_id, c.currency",
				$affiliate_id,
				$start,
				$end
			)
		);
		// phpcs:enable
		$rows = array();
		$init = static function ( $campaign, $sub ) {
			return array(
				'campaign'   => (string) $campaign,
				'sub_id'     => (string) $sub,
				'label'      => self::campaign_label( (string) $campaign ),
				'visits'     => 0,
				'orders'     => 0,
				'conversion' => 0,
				'commission' => array(),
			);
		};
		foreach ( (array) $visits as $r ) {
			$k                    = $r->campaign . "
" . $r->sub_id;
			$rows[ $k ]           = $init( $r->campaign, $r->sub_id );
			$rows[ $k ]['visits'] = (int) $r->n;
		}
		foreach ( (array) $sales as $s ) {
			$k = $s->campaign . "
" . $s->sub_id;
			if ( ! isset( $rows[ $k ] ) ) {
				$rows[ $k ] = $init( $s->campaign, $s->sub_id );
			}
			$rows[ $k ]['orders'] += (int) $s->orders;
			if ( null !== $s->currency && '' !== (string) $s->currency ) {
				$cur                                = (string) $s->currency;
				$rows[ $k ]['commission'][ $cur ]   = ( isset( $rows[ $k ]['commission'][ $cur ] ) ? $rows[ $k ]['commission'][ $cur ] : 0 ) + (int) $s->commission;
			}
		}
		foreach ( $rows as &$r ) {
			$r['conversion'] = $r['visits'] > 0 ? round( $r['orders'] * 100 / $r['visits'], 1 ) : 0;
		}
		unset( $r );
		usort(
			$rows,
			static function ( $a, $b ) {
				return array( $b['orders'], $b['visits'] ) <=> array( $a['orders'], $a['visits'] );
			}
		);
		return array_values( $rows );
	}

	/**
	 * Readable label of a campaign: creative links ("creative-12-v3") show the creative's title.
	 *
	 * @param string $campaign Campaign.
	 * @return string
	 */
	public static function campaign_label( $campaign ) {
		if ( '' === $campaign ) {
			return __( '(no campaign)', 'affiliate-captain' );
		}
		if ( preg_match( '/^creative-(\d+)-v(\d+)$/', $campaign, $m ) ) {
			$creative = \WPAffiliateCaptain\Promotion\Creatives::find_version( (int) $m[1], (int) $m[2] );
			if ( $creative ) {
				/* translators: 1: creative title, 2: version */
				return sprintf( __( 'Creative "%1$s" (v%2$d)', 'affiliate-captain' ), $creative->title, (int) $m[2] );
			}
		}
		return $campaign;
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
