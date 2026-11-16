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
use WPAffiliateCaptain\Affiliates\Programs;
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
	 * @param int|int[]   $affiliate_id Optional single partner, or a list of partners (e.g. one mail batch).
	 * @param string|null $program      Only partners of this program (program key, '' = default
	 *                                  program; null = all; since contract 1.5.0).
	 * @return array
	 */
	public static function partners( $from, $to, $affiliate_id = 0, $program = null ) {
		global $wpdb;
		self::$unconverted = array();
		$c                 = Db::table( 'commissions' );
		$v                 = Db::table( 'visits' );
		$at                = Db::table( 'attributions' );
		list( $start, $end ) = Db::local_range( $from, $to );
		$aff_sql           = self::affiliate_sql( $affiliate_id ) . self::program_sql( $program );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$money = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT affiliate_id, currency,
					SUM(CASE WHEN kind = 'sale' AND status <> 'rejected' THEN base_minor ELSE 0 END) revenue,
					SUM(CASE WHEN kind = 'sale' AND status <> 'rejected' THEN amount_minor ELSE 0 END) commission,
					SUM(CASE WHEN kind IN ('refund','cancellation','chargeback','reinstatement','adjustment','increase','correction') AND status <> 'rejected' THEN amount_minor ELSE 0 END) corrections,
					SUM(CASE WHEN kind IN ('refund','cancellation','chargeback','reinstatement') AND status <> 'rejected' THEN amount_minor ELSE 0 END) refunds,
					SUM(CASE WHEN kind = 'bonus' AND status <> 'rejected' THEN amount_minor ELSE 0 END) bonus,
					SUM(CASE WHEN kind = 'writeoff' AND status <> 'rejected' THEN amount_minor ELSE 0 END) writeoffs
				FROM {$c} WHERE created_at BETWEEN %s AND %s{$aff_sql} GROUP BY affiliate_id, currency",
				$start,
				$end
			)
		);
		if ( VisitsDaily::usable( $from, $to ) ) {
			// Daily aggregate (R14-D9): the same local days, a few rows per partner and day.
			$d      = VisitsDaily::table();
			$visits = $wpdb->get_results( $wpdb->prepare( "SELECT affiliate_id, SUM(visits) n FROM {$d} WHERE day BETWEEN %s AND %s{$aff_sql} GROUP BY affiliate_id", $from, $to ), OBJECT_K );
		} else {
			$visits = $wpdb->get_results( $wpdb->prepare( "SELECT affiliate_id, COUNT(*) n FROM {$v} WHERE created_at BETWEEN %s AND %s{$aff_sql} GROUP BY affiliate_id", $start, $end ), OBJECT_K );
		}
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
				'refunds'      => 0,
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
			foreach ( array( 'revenue', 'commission', 'corrections', 'refunds', 'bonus', 'writeoffs' ) as $k ) {
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
			// Only reversals count (the kinds of FraudCheck::refund_stats()): a manual increase or a
			// positive correction must not hide refunds (R14-U5).
			$r['refund_rate'] = $r['commission'] > 0 ? round( abs( min( 0, $r['refunds'] ) ) * 100 / $r['commission'], 1 ) : 0;
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
		 * @param string|null $program Only this program (null = all; since contract 1.5.0).
		 */
		$rows = apply_filters( 'wpac_report_partners', array_values( $rows ), $from, $to, is_array( $affiliate_id ) ? 0 : (int) $affiliate_id, null === $program ? null : (string) $program );
		return is_array( $rows ) ? array_values( $rows ) : array();
	}

	/** Prefix of the transients with the cached admin reports (one transient per report). */
	const CACHE = 'wpac_reports';

	/** Option: generation of the report cache; a booking raises it (old entries expire). */
	const CACHE_GEN = 'wpac_reports_gen';

	/** Lifetime of a cached report; visits show up after at most this long. */
	const CACHE_TTL = 600;

	/**
	 * Hooks: bookings empty the report cache.
	 */
	public static function register_hooks() {
		VisitsDaily::register_hooks();
		foreach ( array( 'wpac_commission_created', 'wpac_commission_adjusted', 'wpac_commission_approved', 'wpac_commission_rejected', 'wpac_commission_paid', 'wpac_commission_pending', 'wpac_commission_review', 'wpac_payout_created', 'wpac_payout_cancelled' ) as $hook ) {
			add_action( $hook, array( self::class, 'flush_cache' ), 99, 0 );
		}
	}

	/**
	 * @var bool Whether the generation was raised in this request and no report was cached
	 *           since (a further raise would change nothing, R14-D5).
	 */
	private static $gen_raised = false;

	/**
	 * Drop all cached reports (after a booking).
	 */
	public static function flush_cache() {
		// One small write instead of deleting every report: the keys hold the generation, so the
		// old entries are never read again and expire on their own (R13-N12). At most one write
		// per request, however many bookings it makes (approve_due() with 2000 commissions), and
		// not autoloaded: the front end never reads it, and with a persistent object cache every
		// write of an autoloaded option rebuilds "alloptions" for all requests (R14-D5). Only a
		// report cached after the raise allows the next one (it may hold the older state).
		if ( self::$gen_raised ) {
			return;
		}
		self::$gen_raised = true;
		update_option( self::CACHE_GEN, self::generation() + 1, false );
	}

	/**
	 * Store a cached report (transient) and allow the next generation raise (R14-D5).
	 *
	 * @param string $name  Transient.
	 * @param mixed  $value Value.
	 */
	private static function store( $name, $value ) {
		self::$gen_raised = false;
		set_transient( $name, $value, self::CACHE_TTL );
	}

	/**
	 * Generation of the report cache.
	 *
	 * @return int
	 */
	private static function generation() {
		return (int) get_option( self::CACHE_GEN, 0 );
	}

	/**
	 * Name of the transient that holds one report (time bucket, generation and state in the key).
	 *
	 * @param string      $kind    partners|content_id|campaign.
	 * @param string      $from    Y-m-d (local).
	 * @param string      $to      Y-m-d (local).
	 * @param string|null $program Program (null = all).
	 * @return string
	 */
	public static function cache_name( $kind, $from, $to, $program = null ) {
		global $wpdb;
		$state = array( null === $program ? $kind : $kind . '@' . $program . '@' . implode( ',', Programs::groups_of( (string) $program ) ), $from, $to, (string) \WPAffiliateCaptain\Core\Settings::get( 'currency' ), wp_json_encode( Fx::rates() ), function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : '', function_exists( 'determine_locale' ) ? determine_locale() : '' ); // Labels such as "(no campaign)" are translated.
		$table = Db::table( 'commissions' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- constants only.
		$state[] = wp_json_encode( $wpdb->get_row( "SELECT MAX(id), MAX(updated_at) FROM {$table}" ) );
		$state[] = (int) floor( time() / self::CACHE_TTL );
		$state[] = self::generation();
		return self::CACHE . '_' . md5( implode( '|', $state ) );
	}

	/**
	 * Report for admin screens and the REST route, cached for a few minutes: the dashboard shows
	 * the same report on every visit (KPIs and table), and the aggregates scan the whole period.
	 * The key holds a time bucket (CACHE_TTL), a fingerprint of the newest commission (indexed
	 * MAX() lookups, so a sale or refund shows at once) and the currency settings. New visits
	 * show up with the next bucket – they no longer make every click a cache miss. Each report has
	 * its own small transient (a shared one grew to megabytes, beyond the 1 MB of Memcached, and
	 * evicted reports in turn); bookings raise the generation in the key (R13-N12).
	 *
	 * @param string      $kind    partners|content_id|campaign.
	 * @param string      $from    Y-m-d (local).
	 * @param string      $to      Y-m-d (local).
	 * @param string|null $program Only this program (null = all; since contract 1.5.0).
	 * @return array Rows (self::$unconverted is set as by the uncached call).
	 */
	public static function cached( $kind, $from, $to, $program = null ) {
		$name = self::cache_name( $kind, $from, $to, $program );
		$hit  = get_transient( $name );
		if ( is_array( $hit ) && isset( $hit['rows'], $hit['unconverted'] ) ) {
			self::$unconverted = (array) $hit['unconverted'];
			return (array) $hit['rows'];
		}
		$rows = 'partners' === $kind ? self::partners( $from, $to, 0, $program ) : self::by_dimension( $kind, $from, $to, $program );
		self::store(
			$name,
			array(
				'rows'        => $rows,
				'unconverted' => self::$unconverted,
			)
		);
		return $rows;
	}

	/** Prefix of the transients with the cached statistics of the partner area and editor panel. */
	const STATS_CACHE = 'wpac_pstats';

	/** Default minimum number of sales per campaign/sub-ID row shown to partners (R12-L5). */
	const SUBID_MIN_GROUP = 3;

	/**
	 * Minimum number of sales a campaign/sub-ID row needs before a partner sees it on its own
	 * (R12-L5, k-anonymity): with unique sub-IDs per reader, a row with one sale would tell the
	 * partner who bought. Rows with fewer sales are combined into one "Other" row. 1 switches
	 * the grouping off. Admin reports are not affected.
	 *
	 * @return int At least 1.
	 */
	public static function subid_min_group() {
		/**
		 * Filter the minimum number of sales per campaign/sub-ID row in the partner area.
		 *
		 * @param int $min Default 3; 1 = show every row.
		 */
		return max( 1, (int) apply_filters( 'wpac_subid_min_group', self::SUBID_MIN_GROUP ) );
	}

	/**
	 * Statistics of one partner for the partner area, cached for CACHE_TTL per partner and period
	 * (R14-D1): reloading the statistics tab must not scan the partner's visits again each time
	 * (a partner with a million visits a month made every reload a query of close to a minute).
	 * Bookings raise the generation in the key, so new sales show at once; new visits show with
	 * the next time bucket.
	 *
	 * @param string $kind         summary|campaigns.
	 * @param int    $affiliate_id Partner.
	 * @param string $from         Y-m-d (local).
	 * @param string $to           Y-m-d (local).
	 * @return array As partner_summary() resp. partner_campaigns().
	 */
	public static function partner_cached( $kind, $affiliate_id, $from, $to ) {
		$kind = 'campaigns' === $kind ? 'campaigns' : 'summary';
		$min  = 'campaigns' === $kind ? self::subid_min_group() : 0;
		$name = self::stats_name( array( $kind, (int) $affiliate_id, (string) $from, (string) $to, $min ) );
		$hit  = get_transient( $name );
		if ( is_array( $hit ) && isset( $hit['rows'] ) && is_array( $hit['rows'] ) ) {
			return $hit['rows'];
		}
		// Partner-facing: small campaign/sub-ID rows combined (R12-L5).
		$rows = 'campaigns' === $kind ? self::partner_campaigns( (int) $affiliate_id, $from, $to, $min ) : self::partner_summary( (int) $affiliate_id, $from, $to );
		self::store( $name, array( 'rows' => $rows ) );
		return $rows;
	}

	/**
	 * One page of the campaign statistics of a partner for the partner area (R14-D9), cached per
	 * page like partner_cached(): a partner with thousands of sub-IDs made one transient of several
	 * megabytes, which Memcached does not store (limit 1 MB), so every view computed it again.
	 *
	 * @param int    $affiliate_id Partner.
	 * @param string $from         Y-m-d (local).
	 * @param string $to           Y-m-d (local).
	 * @param int    $page         Page (1-based).
	 * @param int    $per          Rows per page.
	 * @return array rows (as partner_campaigns()), total (number of rows of all pages)
	 */
	public static function partner_campaigns_page( $affiliate_id, $from, $to, $page = 1, $per = 25 ) {
		$per  = max( 1, (int) $per );
		$page = max( 1, (int) $page );
		$min  = self::subid_min_group();
		$name = self::stats_name( array( 'campaigns_page', (int) $affiliate_id, (string) $from, (string) $to, $page, $per, $min ) );
		$hit  = get_transient( $name );
		if ( is_array( $hit ) && isset( $hit['rows'], $hit['total'] ) && is_array( $hit['rows'] ) ) {
			return array(
				'rows'  => $hit['rows'],
				'total' => (int) $hit['total'],
			);
		}
		$all   = self::partner_campaigns( (int) $affiliate_id, $from, $to, $min ); // Small rows combined (R12-L5).
		$value = array(
			'rows'  => array_slice( $all, ( $page - 1 ) * $per, $per ),
			'total' => count( $all ),
		);
		self::store( $name, $value );
		return $value;
	}

	/**
	 * Statistics of one content item for the editor panel, cached for CACHE_TTL (R14-D6): the
	 * panel counts 90 days of visits of the page each time the editor opens.
	 *
	 * @param int $post_id Post.
	 * @param int $days    Days.
	 * @return array As for_content() (self::$unconverted is set as by the uncached call).
	 */
	public static function content_cached( $post_id, $days = 90 ) {
		$name = self::stats_name( array( 'content', (int) $post_id, (int) $days, (string) \WPAffiliateCaptain\Core\Settings::get( 'currency' ), wp_json_encode( Fx::rates() ) ) );
		$hit  = get_transient( $name );
		if ( is_array( $hit ) && isset( $hit['rows'], $hit['unconverted'] ) && is_array( $hit['rows'] ) ) {
			self::$unconverted = (array) $hit['unconverted'];
			return $hit['rows'];
		}
		$rows = self::for_content( (int) $post_id, (int) $days );
		self::store(
			$name,
			array(
				'rows'        => $rows,
				'unconverted' => self::$unconverted,
			)
		);
		return $rows;
	}

	/**
	 * Name of a statistics transient: the parts plus time zone, language (labels), time bucket
	 * and generation.
	 *
	 * @param array $parts Parts of the key.
	 * @return string
	 */
	private static function stats_name( array $parts ) {
		$parts[] = function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : '';
		$parts[] = function_exists( 'determine_locale' ) ? determine_locale() : '';
		$parts[] = (int) floor( time() / self::CACHE_TTL );
		$parts[] = self::generation();
		return self::STATS_CACHE . '_' . md5( implode( '|', $parts ) );
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
	 * Condition "partner belongs to the program" (variant A: the partner's current group).
	 *
	 * @param string|null $program Program key (null = all partners).
	 * @param string      $column  Column with the partner ID (constant).
	 * @return string SQL (values are integers).
	 */
	private static function program_sql( $program, $column = 'affiliate_id' ) {
		if ( null === $program ) {
			return '';
		}
		$clean = Programs::clean( $program );
		return null === $clean ? ' AND 1=0' : Programs::affiliate_sql( $clean, $column );
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
		$daily = VisitsDaily::usable( $from, $to );
		foreach ( array( 'commissions', 'visits', 'attributions' ) as $table ) {
			if ( 'visits' === $table && $daily ) {
				// Daily aggregate (R14-D9).
				$d = VisitsDaily::table();
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$found = (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT affiliate_id FROM {$d} WHERE day BETWEEN %s AND %s AND affiliate_id > %d ORDER BY affiliate_id ASC LIMIT %d", $from, $to, (int) $after, (int) $limit ) );
			} else {
				$t = Db::table( $table );
				// Index (created_at, affiliate_id) resp. (affiliate_id, created_at).
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$found = (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT affiliate_id FROM {$t} WHERE created_at BETWEEN %s AND %s AND affiliate_id > %d ORDER BY affiliate_id ASC LIMIT %d", $start, $end, (int) $after, (int) $limit ) );
			}
			foreach ( $found as $id ) {
				$ids[ (int) $id ] = (int) $id;
			}
		}
		ksort( $ids );
		return array_slice( array_values( $ids ), 0, (int) $limit );
	}

	/**
	 * Report by a visit/attribution dimension.
	 *
	 * Campaign names are chosen by each partner (utm_campaign), so campaigns are reported per
	 * partner: "summer" of partner A and "summer" of partner B are two rows. Content (the post
	 * or page where the link landed) belongs to the shop and is summed over all partners.
	 *
	 * @param string      $dimension content_id|campaign.
	 * @param string      $from      Y-m-d.
	 * @param string      $to        Y-m-d.
	 * @param string|null $program   Only visits and orders of partners of this program (program
	 *                               key, '' = default program; null = all; since contract 1.5.0).
	 * @return array Rows with key, label, affiliate_id and partner (campaign only; 0/'' for
	 *               content), visits, partners, orders, revenue, commission, conversion.
	 */
	public static function by_dimension( $dimension, $from, $to, $program = null ) {
		global $wpdb;
		self::$unconverted = array();
		$dim               = 'campaign' === $dimension ? 'campaign' : 'content_id';
		$v                 = Db::table( 'visits' );
		$at                = Db::table( 'attributions' );
		$c                 = Db::table( 'commissions' );
		list( $start, $end ) = Db::local_range( $from, $to );
		// Per partner for campaigns (constant fragments, no input).
		$aid_v = 'campaign' === $dim ? 'affiliate_id' : '0';
		$aid_a = 'campaign' === $dim ? 'a.affiliate_id' : '0';
		$prog  = self::program_sql( $program );
		$prog_a = self::program_sql( $program, 'a.affiliate_id' );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( 'campaign' === $dim && VisitsDaily::usable( $from, $to ) ) {
			// Daily aggregate (R14-D9); campaigns are per partner, so "partners" is 1.
			$d      = VisitsDaily::table();
			$visits = $wpdb->get_results( $wpdb->prepare( "SELECT affiliate_id aid, campaign k, SUM(visits) n, 1 partners FROM {$d} WHERE day BETWEEN %s AND %s{$prog} GROUP BY aid, k", $from, $to ) );
		} else {
			$visits = $wpdb->get_results( $wpdb->prepare( "SELECT {$aid_v} aid, {$dim} k, COUNT(*) n, COUNT(DISTINCT affiliate_id) partners FROM {$v} WHERE created_at BETWEEN %s AND %s{$prog} GROUP BY aid, k", $start, $end ) );
		}
		$sales  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$aid_a} aid, a.{$dim} k, c.currency, COUNT(DISTINCT a.id) orders,
					SUM(CASE WHEN c.kind = 'sale' THEN c.base_minor ELSE 0 END) revenue,
					SUM(COALESCE(c.amount_minor,0)) commission
				FROM {$at} a LEFT JOIN {$c} c ON c.source = a.source AND c.source_order_id = a.source_order_id AND c.affiliate_id = a.affiliate_id AND c.status <> 'rejected' AND c.kind <> 'bonus'
				WHERE a.created_at BETWEEN %s AND %s{$prog_a} GROUP BY aid, k, c.currency",
				$start,
				$end
			)
		);
		// phpcs:enable
		$rows = array();
		$init = static function ( $aid, $k ) {
			return array(
				'key'          => (string) $k,
				'affiliate_id' => (int) $aid,
				'partner'      => '',
				'visits'       => 0,
				'partners'     => 0,
				'orders'       => 0,
				'revenue'      => 0,
				'commission'   => 0,
			);
		};
		foreach ( (array) $visits as $r ) {
			$id                     = (int) $r->aid . "\n" . $r->k;
			$rows[ $id ]             = $init( $r->aid, $r->k );
			$rows[ $id ]['visits']   = (int) $r->n;
			$rows[ $id ]['partners'] = (int) $r->partners;
		}
		foreach ( (array) $sales as $s ) {
			$id = (int) $s->aid . "\n" . $s->k;
			if ( ! isset( $rows[ $id ] ) ) {
				$rows[ $id ] = $init( $s->aid, $s->k );
			}
			$rows[ $id ]['orders'] += (int) $s->orders;
			self::add( $rows[ $id ], 'revenue', (int) $s->revenue, $s->currency );
			self::add( $rows[ $id ], 'commission', (int) $s->commission, $s->currency );
		}
		if ( 'campaign' === $dim ) {
			// One query for all partner names.
			AffiliateRepository::find_many( array_column( $rows, 'affiliate_id' ) );
			foreach ( $rows as &$r ) {
				$aff           = AffiliateRepository::find( $r['affiliate_id'] );
				$r['partner']  = $aff ? (string) $aff->name : '#' . $r['affiliate_id'];
				$r['partners'] = 1;
			}
			unset( $r );
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
	 * @return array[] source, source_order_id, created_at, click (brought by a click on a partner link)
	 */
	private static function partner_sales( $affiliate_id, $start, $end ) {
		global $wpdb;
		$c  = Db::table( 'commissions' );
		$at = Db::table( 'attributions' );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.source, c.source_order_id, MIN(s.created_at) created_at,
					SUM(c.amount_minor) net, SUM(CASE WHEN c.kind = 'sale' THEN c.amount_minor ELSE 0 END) gross,
					MAX(a.method) method
				FROM {$c} c LEFT JOIN {$at} a ON a.source = c.source AND a.source_order_id = c.source_order_id AND a.affiliate_id = c.affiliate_id
				JOIN (
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
					'click'           => in_array( (string) $r->method, self::CLICK_METHODS, true ),
				);
			}
		}
		return $out;
	}

	/**
	 * Methods of an attribution that come from a click on a partner link (a visit): only these
	 * orders count for the conversion rate; coupon or code sales have no visit (R14-U6).
	 */
	const CLICK_METHODS = array( 'link', 'cookie', 'app' );

	/**
	 * Statistics of one partner for the partner area: visits, sales, conversion rate and the
	 * commissions earned in the period per currency (amounts in the booking currency, no conversion).
	 *
	 * Days are the site's local days (time zone in the WordPress settings); stored times are UTC
	 * (R14-U1). The conversion rate only counts sales brought by a click and is null without
	 * visits (R14-U6).
	 *
	 * @param int    $affiliate_id Partner.
	 * @param string $from         Y-m-d (local).
	 * @param string $to           Y-m-d (local).
	 * @return array visits, sales, click_sales, conversion (float|null), earned (currency => minor)
	 */
	public static function partner_summary( $affiliate_id, $from, $to ) {
		global $wpdb;
		list( $start, $end ) = Db::local_range( $from, $to );
		$v                   = Db::table( 'visits' );
		$c                   = Db::table( 'commissions' );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( VisitsDaily::usable( $from, $to ) ) {
			$d      = VisitsDaily::table(); // Daily aggregate (R14-D9).
			$visits = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(visits), 0) FROM {$d} WHERE affiliate_id = %d AND day BETWEEN %s AND %s", $affiliate_id, $from, $to ) );
		} else {
			$visits = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$v} WHERE affiliate_id = %d AND created_at BETWEEN %s AND %s", $affiliate_id, $start, $end ) );
		}
		$earned = $wpdb->get_results( $wpdb->prepare( "SELECT currency, SUM(amount_minor) total FROM {$c} WHERE affiliate_id = %d AND status <> 'rejected' AND created_at BETWEEN %s AND %s GROUP BY currency", $affiliate_id, $start, $end ) );
		// phpcs:enable
		$sales = self::partner_sales( (int) $affiliate_id, $start, $end );
		$click = count( array_filter( wp_list_pluck( $sales, 'click' ) ) );
		$money = array();
		foreach ( (array) $earned as $e ) {
			$money[ (string) $e->currency ] = (int) $e->total;
		}
		return array(
			'visits'      => $visits,
			'sales'       => count( $sales ),
			'click_sales' => $click,
			'conversion'  => $visits > 0 ? round( $click * 100 / $visits, 1 ) : null,
			'earned'      => $money,
		);
	}

	/**
	 * A stored UTC time in the site's time zone (R14-U1).
	 *
	 * @param string $utc    Y-m-d H:i:s (UTC).
	 * @param string $format Date format, e.g. Y-m or Y-m-d.
	 * @return string
	 */
	public static function local_time( $utc, $format = 'Y-m-d' ) {
		$ts = strtotime( $utc . ' UTC' );
		if ( false === $ts ) {
			return substr( (string) $utc, 0, strlen( gmdate( $format, 0 ) ) );
		}
		$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
		return ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( $tz )->format( $format );
	}

	/**
	 * Monthly figures of one partner (newest first): sales and commissions earned per currency.
	 * Months are the site's local months, like the monthly tier bonus (R14-U1).
	 *
	 * @param int $affiliate_id Partner.
	 * @param int $months       Number of months including the current one.
	 * @return array Y-m => [ sales, earned => [ currency => minor ] ]
	 */
	public static function partner_months( $affiliate_id, $months = 12 ) {
		global $wpdb;
		$months = max( 1, min( 36, (int) $months ) );
		$out    = array();
		for ( $i = 0; $i < $months; $i++ ) {
			$out[ Db::local_month( -$i ) ] = array(
				'sales'  => 0,
				'earned' => array(),
			);
		}
		list( $start ) = Db::month_range( Db::local_month( 1 - $months ) );
		list( , $end ) = Db::month_range( Db::local_month( 0 ) );
		$c             = Db::table( 'commissions' );
		// Sums per UTC minute; PHP puts them into local months (offsets change with DST, some are :30).
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT LEFT(created_at, 16) h, currency, SUM(amount_minor) total FROM {$c} WHERE affiliate_id = %d AND status <> 'rejected' AND created_at BETWEEN %s AND %s GROUP BY h, currency", $affiliate_id, $start, $end ) );
		foreach ( (array) $rows as $r ) {
			$m = self::local_time( $r->h . ':00', 'Y-m' );
			if ( isset( $out[ $m ] ) ) {
				$cur                         = (string) $r->currency;
				$out[ $m ]['earned'][ $cur ] = ( isset( $out[ $m ]['earned'][ $cur ] ) ? $out[ $m ]['earned'][ $cur ] : 0 ) + (int) $r->total;
			}
		}
		foreach ( self::partner_sales( (int) $affiliate_id, $start, $end ) as $s ) {
			$m = self::local_time( $s['created_at'], 'Y-m' );
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
	 * With $min_group > 1 (partner area, R12-L5) every row with at least one but fewer than
	 * $min_group sales is combined into one last row "Other" (other = true, empty campaign and
	 * sub-ID): its visits, sales and commission are the sums of the combined rows. Rows without
	 * sales (visits only, no buyer) and the row without campaign and sub-ID (no label chosen by
	 * the partner) stay on their own.
	 *
	 * @param int    $affiliate_id Partner.
	 * @param string $from         Y-m-d.
	 * @param string $to           Y-m-d.
	 * @param int    $min_group    Minimum sales per row (0/1 = every row, as for admins).
	 * @return array[] campaign, sub_id, label, visits, orders, conversion, commission (currency => minor)[, other]
	 */
	public static function partner_campaigns( $affiliate_id, $from, $to, $min_group = 0 ) {
		global $wpdb;
		list( $start, $end ) = Db::local_range( $from, $to );
		$v                   = Db::table( 'visits' );
		$at                  = Db::table( 'attributions' );
		$c                   = Db::table( 'commissions' );
		$methods             = "'" . implode( "','", self::CLICK_METHODS ) . "'";
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( VisitsDaily::usable( $from, $to ) ) {
			$d      = VisitsDaily::table(); // Daily aggregate (R14-D9).
			$visits = $wpdb->get_results( $wpdb->prepare( "SELECT campaign, sub_id, SUM(visits) n FROM {$d} WHERE affiliate_id = %d AND day BETWEEN %s AND %s GROUP BY campaign, sub_id", $affiliate_id, $from, $to ) );
		} else {
			$visits = $wpdb->get_results( $wpdb->prepare( "SELECT campaign, sub_id, COUNT(*) n FROM {$v} WHERE affiliate_id = %d AND created_at BETWEEN %s AND %s GROUP BY campaign, sub_id", $affiliate_id, $start, $end ) );
		}
		$sales  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.campaign, a.sub_id, c.currency, COUNT(DISTINCT a.id) orders, COUNT(DISTINCT CASE WHEN a.method IN ({$methods}) THEN a.id END) click_orders, SUM(COALESCE(c.amount_minor,0)) commission
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
				'clicked'    => 0,
				'conversion' => null,
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
			$rows[ $k ]['orders']  += (int) $s->orders;
			$rows[ $k ]['clicked'] += (int) $s->click_orders;
			if ( null !== $s->currency && '' !== (string) $s->currency ) {
				$cur                                = (string) $s->currency;
				$rows[ $k ]['commission'][ $cur ]   = ( isset( $rows[ $k ]['commission'][ $cur ] ) ? $rows[ $k ]['commission'][ $cur ] : 0 ) + (int) $s->commission;
			}
		}
		$min_group = (int) $min_group;
		$other     = null;
		if ( $min_group > 1 ) {
			foreach ( $rows as $k => $r ) {
				if ( $r['orders'] < 1 || $r['orders'] >= $min_group || ( '' === $r['campaign'] && '' === $r['sub_id'] ) ) {
					continue;
				}
				if ( null === $other ) {
					$other          = $init( '', '' );
					$other['label'] = sprintf(
						/* translators: %d: minimum number of sales per row */
						_n( 'Other (campaigns and sub-IDs with fewer than %d sale)', 'Other (campaigns and sub-IDs with fewer than %d sales)', $min_group, 'affiliate-captain' ),
						$min_group
					);
					$other['other'] = true;
				}
				$other['visits']  += $r['visits'];
				$other['orders']  += $r['orders'];
				$other['clicked'] += $r['clicked'];
				foreach ( $r['commission'] as $cur => $minor ) {
					$other['commission'][ $cur ] = ( isset( $other['commission'][ $cur ] ) ? $other['commission'][ $cur ] : 0 ) + $minor;
				}
				unset( $rows[ $k ] );
			}
		}
		foreach ( $rows as &$r ) {
			// Only orders from a click, and no rate without visits (R14-U6).
			$r['conversion'] = $r['visits'] > 0 ? round( $r['clicked'] * 100 / $r['visits'], 1 ) : null;
			unset( $r['clicked'] );
		}
		unset( $r );
		usort(
			$rows,
			static function ( $a, $b ) {
				return array( $b['orders'], $b['visits'] ) <=> array( $a['orders'], $a['visits'] );
			}
		);
		if ( null !== $other ) {
			$other['conversion'] = $other['visits'] > 0 ? round( $other['clicked'] * 100 / $other['visits'], 1 ) : null;
			unset( $other['clicked'] );
			$rows[] = $other; // Always last.
		}
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
