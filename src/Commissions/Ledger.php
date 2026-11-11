<?php
/**
 * Commission ledger: creates commissions from sales, corrects them for refunds,
 * releases them after the hold period and handles manual decisions.
 *
 * Principles:
 *  - Amounts in minor units + currency, never floats.
 *  - Every commission row has a unique idempotency key → repeated events never book twice.
 *  - Refunds create negative adjustment rows (append-only), status changes are logged
 *    in commission_events.
 *  - Rate/base/rule snapshot is stored per row; later rule changes never alter history.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Commissions;

use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Core\Audit;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\IntegrationException;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Tracking\AttributionResolver;
use WPAffiliateCaptain\Tracking\CustomerLinks;

defined( 'ABSPATH' ) || exit;

final class Ledger {

	const STATUSES = array( 'pending', 'review', 'approved', 'paid', 'rejected' );

	/**
	 * Labels.
	 *
	 * @return array
	 */
	public static function status_labels() {
		return array(
			'pending'  => __( 'Pending (hold period)', 'wp-affiliate-captain' ),
			'review'   => __( 'Needs review', 'wp-affiliate-captain' ),
			'approved' => __( 'Approved', 'wp-affiliate-captain' ),
			'paid'     => __( 'Paid', 'wp-affiliate-captain' ),
			'rejected' => __( 'Rejected', 'wp-affiliate-captain' ),
		);
	}

	/**
	 * Record a normalized sale.
	 *
	 * @param array $sale Normalized sale (see ContractApi::normalize_sale()).
	 * @return array { status: created|duplicate|unattributed, attribution_id, commission_ids, reason }
	 * @throws IntegrationException On DB errors.
	 */
	public static function record_sale( array $sale ) {
		global $wpdb;

		$existing = self::attribution_for( $sale['source'], $sale['order_id'] );
		if ( $existing ) {
			return array(
				'status'         => 'duplicate',
				'attribution_id' => (int) $existing->id,
				'commission_ids' => array(),
				'reason'         => 'already_processed',
			);
		}

		$resolved = AttributionResolver::resolve( $sale );
		if ( ! $resolved['attribution'] ) {
			if ( 'self_referral' === $resolved['reason'] ) {
				Audit::log(
					'attribution.self_referral_blocked',
					'order',
					0,
					array(
						'source'   => $sale['source'],
						'order_id' => $sale['order_id'],
					)
				);
			}
			return array(
				'status'         => 'unattributed',
				'attribution_id' => 0,
				'commission_ids' => array(),
				'reason'         => $resolved['reason'],
			);
		}
		$attr = $resolved['attribution'];
		$aff  = AffiliateRepository::find( (int) $attr['affiliate_id'] );

		$rules   = RuleRepository::active();
		$default = RuleRepository::default_rate();
		if ( strtoupper( (string) Settings::get( 'currency' ) ) !== $sale['currency'] ) {
			// Fixed amounts are stored in the program currency and are never applied to other currencies.
			$rules = array_values(
				array_filter(
					$rules,
					static function ( $r ) {
						return 'fixed' !== $r['rate_type'];
					}
				)
			);
			if ( 'fixed' === $default['rate_type'] ) {
				$default = array(
					'rate_type' => 'percent',
					'rate'      => '0',
				);
			}
		}
		if ( 'lifetime' === $attr['method'] && '' !== (string) Settings::get( 'lifetime_rate' ) ) {
			// Separate rate for repeat purchases of linked customers (exclusions still apply).
			$rules   = array_values(
				array_filter(
					$rules,
					static function ( $r ) {
						return 'exclude' === $r['rate_type'];
					}
				)
			);
			$default = array(
				'rate_type' => 'percent',
				'rate'      => (string) Settings::get( 'lifetime_rate' ),
			);
		}
		$hold    = self::hold_until( $sale );
		$status  = $attr['review'] ? 'review' : 'pending';

		$result = Db::transaction(
			static function () use ( $wpdb, $sale, $attr, $aff, $rules, $default, $hold, $status ) {
				$ok = $wpdb->insert(
					Db::table( 'attributions' ),
					array(
						'source'          => $sale['source'],
						'source_order_id' => $sale['order_id'],
						'affiliate_id'    => (int) $attr['affiliate_id'],
						'code_id'         => (int) $attr['code_id'],
						'method'          => substr( (string) $attr['method'], 0, 20 ),
						'campaign'        => substr( (string) $attr['campaign'], 0, 100 ),
						'content_id'      => (int) $attr['content_id'],
						'consent'         => (int) $attr['consent'],
						'touch_model'     => (string) $attr['touch_model'],
						'status'          => $attr['review'] ? 'review' : 'attributed',
						'note'            => (string) $attr['note'],
						'created_at'      => Db::now(),
					)
				);
				if ( ! $ok ) {
					// Unique (source, order) – a concurrent request won.
					if ( self::attribution_for( $sale['source'], $sale['order_id'] ) ) {
						return array(
							'status'         => 'duplicate',
							'attribution_id' => 0,
							'commission_ids' => array(),
							'reason'         => 'already_processed',
						);
					}
					throw new IntegrationException( 'db_attribution', $wpdb->last_error );
				}
				$attribution_id = (int) $wpdb->insert_id;
				$ids            = array();

				foreach ( $sale['items'] as $item ) {
					$calc = Calculator::calculate( $item, $rules, (int) $aff->id, (int) $aff->group_id, $default );

					/**
					 * Adjust the net commission base of a line (minor units, same currency).
					 * Return 0 to exclude the line (e.g. group packages/upgrades).
					 *
					 * @param int   $base Base in minor units.
					 * @param array $item Normalized item.
					 * @param array $sale Normalized sale.
					 */
					$base = (int) apply_filters( 'wpac_commission_base', $calc['base_minor'], $item, $sale );
					if ( $base !== $calc['base_minor'] && ! $calc['excluded'] ) {
						$item['net_minor'] = max( 0, $base );
						$calc              = Calculator::calculate( $item, $rules, (int) $aff->id, (int) $aff->group_id, $default );
					}
					if ( $calc['amount_minor'] <= 0 ) {
						continue;
					}

					$snapshot = array(
						'rule'          => $calc['rule'],
						'default'       => $calc['rule'] ? null : $default,
						'touch_model'   => $attr['touch_model'],
						'method'        => $attr['method'],
						'contract'      => $sale['contract'],
						'calculated_at' => Db::now(),
					);
					$key      = 'sale:' . $sale['source'] . ':' . $sale['order_id'] . ':' . $item['item_id'];
					$ok       = $wpdb->insert(
						Db::table( 'commissions' ),
						array(
							'affiliate_id'    => (int) $aff->id,
							'source'          => $sale['source'],
							'source_order_id' => $sale['order_id'],
							'source_item_id'  => $item['item_id'],
							'kind'            => 'sale',
							'parent_id'       => 0,
							'product_ref'     => substr( (string) ( $item['sku'] ? $item['sku'] : $item['product_id'] ), 0, 190 ),
							'description'     => substr( (string) $item['name'], 0, 255 ),
							'currency'        => $sale['currency'],
							'base_minor'      => $calc['base_minor'],
							'amount_minor'    => $calc['amount_minor'],
							'rate_type'       => $calc['rate_type'],
							'rate'            => $calc['rate'],
							'rule_id'         => $calc['rule_id'],
							'rule_snapshot'   => wp_json_encode( $snapshot ),
							'status'          => $status,
							'hold_until'      => Db::now( $hold ),
							'idempotency_key' => substr( $key, 0, 191 ),
							'created_at'      => Db::now(),
							'updated_at'      => Db::now(),
						)
					);
					if ( ! $ok ) {
						throw new IntegrationException( 'db_commission', $wpdb->last_error );
					}
					$cid   = (int) $wpdb->insert_id;
					$ids[] = $cid;
					self::event( $cid, 'created', '', $status, $calc['amount_minor'], $attr['review'] ? 'self_referral_review' : '', $sale['idempotency_key'] );
				}

				if ( $attr['code_id'] ) {
					CodeRepository::increment_usage( (int) $attr['code_id'] );
				}

				return array(
					'status'         => 'created',
					'attribution_id' => $attribution_id,
					'commission_ids' => $ids,
					'reason'         => '',
				);
			}
		);

		// Notify listeners only after COMMIT.
		if ( 'created' === $result['status'] ) {
			if ( Settings::get( 'lifetime_enabled' ) && 'lifetime' !== $attr['method'] && ! $attr['review'] ) {
				CustomerLinks::link( (int) $aff->id, CustomerLinks::hashes( $sale['customer']['user_id'], $sale['customer']['email'] ), $sale['source'], $sale['order_id'] );
			}
			/**
			 * Fires after an attribution was persisted.
			 *
			 * @param string $order_id     Source order ID.
			 * @param int    $affiliate_id Affiliate.
			 * @param string $method       code|link|cookie|coupon|app|manual.
			 * @param string $source       Sales source.
			 */
			do_action( 'wpac_affiliate_attributed', $sale['order_id'], (int) $aff->id, $attr['method'], $sale['source'] );
			foreach ( $result['commission_ids'] as $cid ) {
				do_action( 'wpac_commission_created', $cid );
			}
		}
		return $result;
	}

	/**
	 * Record a normalized refund/cancellation/chargeback.
	 *
	 * @param array $refund Normalized refund.
	 * @return array { status, adjustment_ids }
	 * @throws IntegrationException On DB errors.
	 */
	public static function record_refund( array $refund ) {
		global $wpdb;
		$originals = self::sale_commissions( $refund['source'], $refund['order_id'] );
		if ( ! $originals ) {
			return array(
				'status'         => 'no_commission',
				'adjustment_ids' => array(),
			);
		}
		$by_item = array();
		foreach ( $originals as $c ) {
			$by_item[ (string) $c->source_item_id ] = $c;
		}

		$lines = array();
		if ( $refund['full'] ) {
			foreach ( $by_item as $item_id => $c ) {
				$lines[ $item_id ] = 0;
			}
		} else {
			foreach ( $refund['items'] as $line ) {
				if ( isset( $by_item[ $line['item_id'] ] ) ) {
					$lines[ $line['item_id'] ] = (int) $line['net_minor'];
				}
			}
		}

		return Db::transaction(
			static function () use ( $wpdb, $refund, $by_item, $lines ) {
				$ids = array();
				foreach ( $lines as $item_id => $refunded_net ) {
					$parent = $by_item[ $item_id ];
					if ( 'rejected' === $parent->status ) {
						continue;
					}
					$key = substr( 'refund:' . $refund['source'] . ':' . $refund['order_id'] . ':' . $refund['refund_id'] . ':' . $item_id, 0, 191 );
					if ( self::find_by_key( $key ) ) {
						continue; // Idempotent.
					}
					$adjusted = self::adjusted_sum( (int) $parent->id );
					$amount   = Calculator::refund_adjustment( (int) $parent->amount_minor, (int) $parent->base_minor, $adjusted, $refunded_net, $refund['full'] );
					if ( 0 === $amount ) {
						continue;
					}
					// Pending/review parents: adjustment follows the parent. Approved/paid: offset in next payout.
					$status = in_array( $parent->status, array( 'pending', 'review' ), true ) ? $parent->status : 'approved';
					$ok     = $wpdb->insert(
						Db::table( 'commissions' ),
						array(
							'affiliate_id'    => (int) $parent->affiliate_id,
							'source'          => $parent->source,
							'source_order_id' => $parent->source_order_id,
							'source_item_id'  => $parent->source_item_id,
							'kind'            => $refund['type'],
							'parent_id'       => (int) $parent->id,
							'product_ref'     => $parent->product_ref,
							'description'     => substr( $refund['reason'], 0, 255 ),
							'currency'        => $parent->currency,
							'base_minor'      => $refund['full'] ? -(int) $parent->base_minor : -abs( (int) $refunded_net ),
							'amount_minor'    => $amount,
							'rate_type'       => $parent->rate_type,
							'rate'            => $parent->rate,
							'rule_id'         => (int) $parent->rule_id,
							'rule_snapshot'   => null,
							'status'          => $status,
							'hold_until'      => $parent->hold_until,
							'idempotency_key' => $key,
							'created_at'      => Db::now(),
							'updated_at'      => Db::now(),
						)
					);
					if ( ! $ok ) {
						if ( self::find_by_key( $key ) ) {
							continue;
						}
						throw new IntegrationException( 'db_adjustment', $wpdb->last_error );
					}
					$aid   = (int) $wpdb->insert_id;
					$ids[] = $aid;
					self::event( $aid, 'created', '', $status, $amount, $refund['type'] . ': ' . $refund['reason'], $refund['refund_id'] );
					self::event( (int) $parent->id, 'adjusted', $parent->status, $parent->status, $amount, $refund['type'], $refund['refund_id'] );
				}
				if ( $ids ) {
					do_action( 'wpac_commission_adjusted', $refund, $ids );
				}
				return array(
					'status'         => $ids ? 'adjusted' : 'unchanged',
					'adjustment_ids' => $ids,
				);
			}
		);
	}

	/**
	 * Release pending commissions whose hold period has passed (cron).
	 *
	 * @return int Number released.
	 */
	public static function approve_due() {
		global $wpdb;
		$t   = Db::table( 'commissions' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE status = 'pending' AND hold_until <= %s ORDER BY id ASC LIMIT 500", Db::now() ) );
		foreach ( $ids as $id ) {
			self::transition( (int) $id, 'approved', __( 'Hold period ended', 'wp-affiliate-captain' ), 0 );
		}
		return count( $ids );
	}

	/**
	 * Manually approve (early release or after review).
	 *
	 * @param int    $id     Commission.
	 * @param string $reason Reason.
	 * @return true|\WP_Error
	 */
	public static function approve( $id, $reason ) {
		$c = self::find( $id );
		if ( ! $c || ! in_array( $c->status, array( 'pending', 'review' ), true ) ) {
			return new \WP_Error( 'wpac_state', __( 'Only pending commissions or commissions under review can be approved.', 'wp-affiliate-captain' ) );
		}
		$to = 'review' === $c->status && strtotime( $c->hold_until . ' UTC' ) > time() ? 'pending' : 'approved';
		self::transition( (int) $id, $to, $reason, get_current_user_id() );
		foreach ( self::children( (int) $id ) as $child ) {
			if ( $child->status === $c->status ) {
				self::transition( (int) $child->id, $to, $reason, get_current_user_id() );
			}
		}
		return true;
	}

	/**
	 * Manually reject (requires a reason). Open adjustments of the commission are rejected as well.
	 *
	 * @param int    $id     Commission.
	 * @param string $reason Reason.
	 * @return true|\WP_Error
	 */
	public static function reject( $id, $reason ) {
		$c = self::find( $id );
		if ( ! $c || ! in_array( $c->status, array( 'pending', 'review', 'approved' ), true ) ) {
			return new \WP_Error( 'wpac_state', __( 'Paid or rejected commissions cannot be rejected.', 'wp-affiliate-captain' ) );
		}
		if ( (int) $c->payout_id ) {
			return new \WP_Error( 'wpac_state', __( 'This commission is part of a payout. Cancel the payout first.', 'wp-affiliate-captain' ) );
		}
		if ( '' === trim( (string) $reason ) ) {
			return new \WP_Error( 'wpac_reason_required', __( 'Please enter a reason.', 'wp-affiliate-captain' ) );
		}
		self::transition( (int) $id, 'rejected', $reason, get_current_user_id() );
		foreach ( self::children( (int) $id ) as $child ) {
			if ( in_array( $child->status, array( 'pending', 'review', 'approved' ), true ) ) {
				self::transition( (int) $child->id, 'rejected', $reason, get_current_user_id() );
			}
		}
		return true;
	}

	/**
	 * Reject all open commissions of an affiliate (termination policy).
	 *
	 * @param int    $affiliate_id Affiliate.
	 * @param string $reason       Reason.
	 */
	public static function reject_open_for_affiliate( $affiliate_id, $reason ) {
		global $wpdb;
		$t = Db::table( 'commissions' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE affiliate_id = %d AND status IN ('pending','review','approved') AND amount_minor > 0", $affiliate_id ) );
		foreach ( $ids as $id ) {
			self::reject( (int) $id, $reason );
		}
	}

	/**
	 * Status transition with event.
	 *
	 * @param int    $id     Commission.
	 * @param string $to     New status.
	 * @param string $reason Reason.
	 * @param int    $actor  Actor.
	 * @param int    $payout Payout ID (for paid).
	 */
	public static function transition( $id, $to, $reason = '', $actor = 0, $payout = 0 ) {
		global $wpdb;
		$c = self::find( $id );
		if ( ! $c || $c->status === $to ) {
			return;
		}
		$fields = array(
			'status'     => $to,
			'updated_at' => Db::now(),
		);
		if ( $payout ) {
			$fields['payout_id'] = (int) $payout;
		}
		$wpdb->update( Db::table( 'commissions' ), $fields, array( 'id' => (int) $id ) );
		self::event( (int) $id, $to, $c->status, $to, (int) $c->amount_minor, $reason, '', $actor );
		do_action( 'wpac_commission_' . $to, (int) $id );
	}

	/**
	 * Append an event.
	 *
	 * @param int    $id       Commission.
	 * @param string $event    Event.
	 * @param string $from     From status.
	 * @param string $to       To status.
	 * @param int    $amount   Amount.
	 * @param string $reason   Reason.
	 * @param string $event_id Source event ID.
	 * @param int    $actor    Actor (defaults to current user).
	 */
	public static function event( $id, $event, $from, $to, $amount, $reason = '', $event_id = '', $actor = null ) {
		global $wpdb;
		$wpdb->insert(
			Db::table( 'commission_events' ),
			array(
				'commission_id'   => (int) $id,
				'event'           => $event,
				'from_status'     => $from,
				'to_status'       => $to,
				'amount_minor'    => (int) $amount,
				'reason'          => substr( (string) $reason, 0, 255 ),
				'actor_id'        => null === $actor ? get_current_user_id() : (int) $actor,
				'source_event_id' => substr( (string) $event_id, 0, 191 ),
				'created_at'      => Db::now(),
			)
		);
	}

	/**
	 * Hold-until timestamp: paid date + hold days, extended by integrations
	 * (e.g. guarantee end) via the payload or the `wpac_hold_until` filter. The later date wins.
	 *
	 * @param array $sale Sale.
	 * @return int
	 */
	public static function hold_until( array $sale ) {
		$paid = $sale['paid_at'] ? strtotime( $sale['paid_at'] . ' UTC' ) : time();
		$hold = $paid + DAY_IN_SECONDS * (int) Settings::get( 'hold_days' );
		if ( ! empty( $sale['hold_until'] ) ) {
			$hold = max( $hold, (int) strtotime( $sale['hold_until'] . ' UTC' ) );
		}
		/**
		 * Latest guarantee/withdrawal end for the order (UNIX timestamp). Only later dates extend the hold.
		 *
		 * @param int   $hold Timestamp.
		 * @param array $sale Sale.
		 */
		$filtered = (int) apply_filters( 'wpac_hold_until', $hold, $sale );
		return max( $hold, $filtered );
	}

	/* ---------------------------------------------------------------------
	 * Queries
	 * ------------------------------------------------------------------- */

	/**
	 * Find by ID.
	 *
	 * @param int $id ID.
	 * @return object|null
	 */
	public static function find( $id ) {
		global $wpdb;
		$t = Db::table( 'commissions' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) );
	}

	/**
	 * Find by idempotency key.
	 *
	 * @param string $key Key.
	 * @return object|null
	 */
	public static function find_by_key( $key ) {
		global $wpdb;
		$t = Db::table( 'commissions' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE idempotency_key = %s", $key ) );
	}

	/**
	 * Attribution of an order.
	 *
	 * @param string $source   Source.
	 * @param string $order_id Order.
	 * @return object|null
	 */
	public static function attribution_for( $source, $order_id ) {
		global $wpdb;
		$t = Db::table( 'attributions' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE source = %s AND source_order_id = %s", $source, (string) $order_id ) );
	}

	/**
	 * Sale commissions of an order.
	 *
	 * @param string $source   Source.
	 * @param string $order_id Order.
	 * @return array
	 */
	public static function sale_commissions( $source, $order_id ) {
		global $wpdb;
		$t = Db::table( 'commissions' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE source = %s AND source_order_id = %s AND kind = 'sale'", $source, (string) $order_id ) );
	}

	/**
	 * Adjustments of a commission.
	 *
	 * @param int $id Parent.
	 * @return array
	 */
	public static function children( $id ) {
		global $wpdb;
		$t = Db::table( 'commissions' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE parent_id = %d", $id ) );
	}

	/**
	 * Sum of non-rejected adjustments.
	 *
	 * @param int $id Parent.
	 * @return int
	 */
	public static function adjusted_sum( $id ) {
		global $wpdb;
		$t = Db::table( 'commissions' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount_minor),0) FROM {$t} WHERE parent_id = %d AND status <> 'rejected'", $id ) );
	}

	/**
	 * Events of a commission.
	 *
	 * @param int $id Commission.
	 * @return array
	 */
	public static function events( $id ) {
		global $wpdb;
		$t = Db::table( 'commission_events' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE commission_id = %d ORDER BY id ASC", $id ) );
	}

	/**
	 * List commissions.
	 *
	 * @param array $args affiliate_id, status, source, from, to (Y-m-d), order_id, limit, offset.
	 * @return array [ rows, total ]
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$t = Db::table( 'commissions' );
		list( $where, $params ) = self::where( $args );
		$count_sql              = "SELECT COUNT(*) FROM {$t} WHERE {$where}";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );
		$limit = isset( $args['limit'] ) ? (int) $args['limit'] : 50;
		if ( $limit > 0 ) {
			$params[] = $limit;
			$params[] = isset( $args['offset'] ) ? (int) $args['offset'] : 0;
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", $params ) );
		} else {
			$sql = "SELECT * FROM {$t} WHERE {$where} ORDER BY id DESC";
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_results( $sql );
		}
		return array( $rows, $total );
	}

	/**
	 * Sums per status and currency.
	 *
	 * @param array $args Same filters as query().
	 * @return array status => [ currency => minor ]
	 */
	public static function totals( array $args = array() ) {
		global $wpdb;
		$t                      = Db::table( 'commissions' );
		list( $where, $params ) = self::where( $args );
		$sql                    = "SELECT status, currency, SUM(amount_minor) total FROM {$t} WHERE {$where} GROUP BY status, currency";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_results( $sql );
		$out  = array();
		foreach ( $rows as $r ) {
			$out[ $r->status ][ $r->currency ] = (int) $r->total;
		}
		return $out;
	}

	/**
	 * WHERE builder.
	 *
	 * @param array $args Args.
	 * @return array [ where, params ]
	 */
	private static function where( array $args ) {
		$where  = '1=1';
		$params = array();
		if ( ! empty( $args['affiliate_id'] ) ) {
			$where   .= ' AND affiliate_id = %d';
			$params[] = (int) $args['affiliate_id'];
		}
		if ( ! empty( $args['status'] ) && in_array( $args['status'], self::STATUSES, true ) ) {
			$where   .= ' AND status = %s';
			$params[] = $args['status'];
		}
		if ( ! empty( $args['source'] ) ) {
			$where   .= ' AND source = %s';
			$params[] = $args['source'];
		}
		if ( ! empty( $args['order_id'] ) ) {
			$where   .= ' AND source_order_id = %s';
			$params[] = (string) $args['order_id'];
		}
		if ( ! empty( $args['from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $args['from'] ) ) {
			$where   .= ' AND created_at >= %s';
			$params[] = $args['from'] . ' 00:00:00';
		}
		if ( ! empty( $args['to'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $args['to'] ) ) {
			$where   .= ' AND created_at <= %s';
			$params[] = $args['to'] . ' 23:59:59';
		}
		return array( $where, $params );
	}
}
