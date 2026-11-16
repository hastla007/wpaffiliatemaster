<?php
/**
 * Read actions for Workflow Captain (AFF-WF-03): partner status, campaign status and aggregated
 * figures. Read only – nothing is written, booked, approved or paid. See docs/WORKFLOW-PROVIDER.md.
 *
 * Rights: with a logged-in user the capability wpac_manage_affiliates is required; without a
 * user only in WP-Cron and WP-CLI (background runs of the workflow engine). Every other context
 * (e.g. a REST or AJAX request without login) gets wpac_forbidden.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Core;

use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Privacy\Restriction;
use WPAffiliateCaptain\Reports\Reports;
use WPAffiliateCaptain\Reports\VisitsDaily;

defined( 'ABSPATH' ) || exit;

final class WorkflowRead {

	/** Read actions and their arguments. */
	const ACTIONS = array(
		'partner.status'  => array( 'affiliate_id' ),
		'campaign.status' => array( 'affiliate_id', 'campaign', 'from', 'to' ),
		'metrics.summary' => array( 'affiliate_id', 'from', 'to' ),
		'metrics.monthly' => array( 'affiliate_id', 'months' ),
	);

	/** Longest period of one read (days). */
	const MAX_DAYS = 366;

	/**
	 * Run a read action.
	 *
	 * @param string $action Action (see ACTIONS).
	 * @param array  $args   Arguments.
	 * @return array|\WP_Error
	 */
	public static function read( $action, array $args = array() ) {
		$action = (string) $action;
		if ( ! isset( self::ACTIONS[ $action ] ) ) {
			return new \WP_Error( 'wpac_unknown_action', __( 'Unknown read action.', 'affiliate-captain' ) );
		}
		$denied = self::check_access( $action );
		if ( $denied ) {
			return $denied;
		}
		foreach ( $args as $key => $value ) {
			if ( ! in_array( $key, self::ACTIONS[ $action ], true ) ) {
				/* translators: %s: argument name */
				return new \WP_Error( 'wpac_invalid_field', sprintf( __( 'Unknown argument "%s".', 'affiliate-captain' ), (string) $key ) );
			}
			if ( ! is_scalar( $value ) ) {
				return self::invalid( (string) $key );
			}
		}
		$aff_id = 0;
		if ( isset( $args['affiliate_id'] ) && '' !== (string) $args['affiliate_id'] ) {
			$aff_id = self::whole( $args['affiliate_id'] );
			if ( null === $aff_id ) {
				return self::invalid( 'affiliate_id' );
			}
		}
		$aff = $aff_id ? AffiliateRepository::find( $aff_id ) : null;
		if ( $aff_id && ! $aff ) {
			return new \WP_Error( 'wpac_not_found', __( 'Affiliate not found.', 'affiliate-captain' ) );
		}
		if ( ! $aff && in_array( $action, array( 'partner.status', 'campaign.status', 'metrics.monthly' ), true ) ) {
			return self::invalid( 'affiliate_id' );
		}
		if ( 'partner.status' === $action ) {
			return self::partner_status( $aff );
		}
		if ( 'metrics.monthly' === $action ) {
			$months = isset( $args['months'] ) ? self::whole( $args['months'] ) : 12;
			if ( null === $months || $months < 1 || $months > 36 ) {
				return self::invalid( 'months' );
			}
			$out = array();
			foreach ( Reports::partner_months( (int) $aff->id, $months ) as $month => $row ) {
				$out[] = array(
					'month'      => (string) $month,
					'sales'      => (int) $row['sales'],
					'commission' => $row['earned'],
				);
			}
			return array(
				'affiliate_id' => (int) $aff->id,
				'months'       => $out,
			);
		}
		$range = self::range( $args );
		if ( is_wp_error( $range ) ) {
			return $range;
		}
		list( $from, $to ) = $range;
		if ( 'campaign.status' === $action ) {
			$campaign = isset( $args['campaign'] ) ? (string) $args['campaign'] : '';
			return self::campaign_status( $aff, $campaign, $from, $to );
		}
		return $aff ? self::partner_metrics( $aff, $from, $to ) : self::program_metrics( $from, $to );
	}

	/**
	 * Rights check (see class comment).
	 *
	 * @param string $action Action.
	 * @return \WP_Error|null
	 */
	private static function check_access( $action ) {
		if ( get_current_user_id() > 0 ) {
			return current_user_can( Capabilities::MANAGE_AFFILIATES ) ? null : self::forbidden();
		}
		$background = ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) || ( defined( 'WP_CLI' ) && WP_CLI );
		/**
		 * Whether a read action may run without a logged-in user outside WP-Cron and WP-CLI (e.g. an
		 * async request of a job runner that checks its own secret). Default false.
		 *
		 * @since 0.15.0
		 * @param bool   $allowed Allowed.
		 * @param string $action  Read action.
		 */
		if ( $background || apply_filters( 'wpac_workflow_read_allowed', false, $action ) ) {
			return null;
		}
		return self::forbidden();
	}

	/**
	 * Status of a partner (no personal data).
	 *
	 * @param object $aff Partner row.
	 * @return array
	 */
	private static function partner_status( $aff ) {
		return array(
			'affiliate_id' => (int) $aff->id,
			'status'       => (string) $aff->status,
			'group_id'     => (int) $aff->group_id,
			'referred_by'  => isset( $aff->referred_by ) ? (int) $aff->referred_by : 0,
			'restricted'   => Restriction::is_restricted( (int) $aff->id ),
			'approved_at'  => $aff->approved_at ? (string) $aff->approved_at : null,
			'updated_at'   => (string) $aff->updated_at,
		);
	}

	/**
	 * One campaign (utm_campaign) of a partner in a period: totals and the rows per sub-ID, from
	 * the partner's campaign report.
	 *
	 * @param object $aff      Partner row.
	 * @param string $campaign Campaign ('' = no campaign).
	 * @param string $from     Y-m-d.
	 * @param string $to       Y-m-d.
	 * @return array
	 */
	private static function campaign_status( $aff, $campaign, $from, $to ) {
		$rows   = array();
		$visits = 0;
		$orders = 0;
		$money  = array();
		foreach ( Reports::partner_campaigns( (int) $aff->id, $from, $to ) as $r ) {
			if ( (string) $r['campaign'] !== $campaign ) {
				continue;
			}
			$visits += (int) $r['visits'];
			$orders += (int) $r['orders'];
			foreach ( (array) $r['commission'] as $cur => $minor ) {
				$money[ $cur ] = ( isset( $money[ $cur ] ) ? $money[ $cur ] : 0 ) + (int) $minor;
			}
			$rows[] = array(
				'sub_id'     => (string) $r['sub_id'],
				'visits'     => (int) $r['visits'],
				'orders'     => (int) $r['orders'],
				'conversion' => $r['conversion'],
				'commission' => (array) $r['commission'],
			);
		}
		return array(
			'affiliate_id'   => (int) $aff->id,
			'campaign'       => $campaign,
			'label'          => Reports::campaign_label( $campaign ),
			'from'           => $from,
			'to'             => $to,
			'partner_status' => (string) $aff->status,
			'active'         => 'active' === (string) $aff->status && ( $visits > 0 || $orders > 0 ),
			'clicks'         => $visits,
			'conversions'    => $orders,
			'commission'     => $money,
			'sub_ids'        => $rows,
		);
	}

	/**
	 * Figures of one partner (as in the partner area).
	 *
	 * @param object $aff  Partner row.
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return array
	 */
	private static function partner_metrics( $aff, $from, $to ) {
		$s = Reports::partner_summary( (int) $aff->id, $from, $to );
		return array(
			'affiliate_id'      => (int) $aff->id,
			'from'              => $from,
			'to'                => $to,
			'clicks'            => (int) $s['visits'],
			'conversions'       => (int) $s['sales'],
			'click_conversions' => (int) $s['click_sales'],
			'conversion_rate'   => $s['conversion'],
			'commission'        => $s['earned'],
		);
	}

	/**
	 * Figures of the whole program: visits, attributed orders, commission per currency (not
	 * rejected; every kind of booking, like the partner figures).
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return array
	 */
	private static function program_metrics( $from, $to ) {
		global $wpdb;
		list( $start, $end ) = Db::local_range( $from, $to );
		$c                   = Db::table( 'commissions' );
		$at                  = Db::table( 'attributions' );
		$methods             = "'" . implode( "','", Reports::CLICK_METHODS ) . "'";
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( VisitsDaily::usable( $from, $to ) ) {
			$d      = VisitsDaily::table();
			$visits = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(visits), 0) FROM {$d} WHERE day BETWEEN %s AND %s", $from, $to ) );
		} else {
			$v      = Db::table( 'visits' );
			$visits = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$v} WHERE created_at BETWEEN %s AND %s", $start, $end ) );
		}
		$orders = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) n, COALESCE(SUM(CASE WHEN method IN ({$methods}) THEN 1 ELSE 0 END), 0) clicked FROM {$at} WHERE created_at BETWEEN %s AND %s", $start, $end ) );
		$earned = $wpdb->get_results( $wpdb->prepare( "SELECT currency, SUM(amount_minor) total FROM {$c} WHERE status <> 'rejected' AND created_at BETWEEN %s AND %s GROUP BY currency", $start, $end ) );
		// phpcs:enable
		$money = array();
		foreach ( (array) $earned as $e ) {
			$money[ (string) $e->currency ] = (int) $e->total;
		}
		$n       = $orders ? (int) $orders->n : 0;
		$clicked = $orders ? (int) $orders->clicked : 0;
		return array(
			'affiliate_id'      => 0,
			'from'              => $from,
			'to'                => $to,
			'clicks'            => $visits,
			'conversions'       => $n,
			'click_conversions' => $clicked,
			'conversion_rate'   => $visits > 0 ? round( $clicked * 100 / $visits, 1 ) : null,
			'commission'        => $money,
		);
	}

	/**
	 * Period from the arguments: local days Y-m-d, default the last 30 days up to today.
	 *
	 * @param array $args Arguments.
	 * @return array|\WP_Error [ from, to ]
	 */
	private static function range( array $args ) {
		$today = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
		$to    = isset( $args['to'] ) && '' !== (string) $args['to'] ? (string) $args['to'] : $today;
		if ( ! self::is_day( $to ) ) {
			return self::invalid( 'to' );
		}
		$from = isset( $args['from'] ) && '' !== (string) $args['from'] ? (string) $args['from'] : gmdate( 'Y-m-d', strtotime( $to . ' 00:00:00 UTC' ) - 29 * DAY_IN_SECONDS );
		if ( ! self::is_day( $from ) ) {
			return self::invalid( 'from' );
		}
		$days = ( strtotime( $to . ' 00:00:00 UTC' ) - strtotime( $from . ' 00:00:00 UTC' ) ) / DAY_IN_SECONDS;
		if ( $days < 0 || $days >= self::MAX_DAYS ) {
			return new \WP_Error( 'wpac_invalid_range', __( 'The period must start before it ends and be at most 366 days long.', 'affiliate-captain' ) );
		}
		return array( $from, $to );
	}

	/**
	 * Whether a value is a calendar day Y-m-d.
	 *
	 * @param string $day Value.
	 * @return bool
	 */
	private static function is_day( $day ) {
		return (bool) preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $day, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) && (int) $m[1] >= 2000;
	}

	/**
	 * Whole number ≥ 0 (integer or digits), else null.
	 *
	 * @param mixed $value Value.
	 * @return int|null
	 */
	private static function whole( $value ) {
		if ( is_int( $value ) ) {
			return $value >= 0 ? $value : null;
		}
		return is_string( $value ) && preg_match( '/^\d{1,18}$/', $value ) ? (int) $value : null;
	}

	/**
	 * Error for an invalid argument.
	 *
	 * @param string $field Argument.
	 * @return \WP_Error
	 */
	private static function invalid( $field ) {
		return new \WP_Error(
			'wpac_invalid_' . sanitize_key( $field ),
			/* translators: %s: argument name */
			sprintf( __( 'Invalid value for "%s".', 'affiliate-captain' ), $field )
		);
	}

	/**
	 * Error for a missing right.
	 *
	 * @return \WP_Error
	 */
	private static function forbidden() {
		return new \WP_Error( 'wpac_forbidden', __( 'You are not allowed to read these figures.', 'affiliate-captain' ) );
	}
}
