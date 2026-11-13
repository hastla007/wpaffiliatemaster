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
use WPAffiliateCaptain\Core\Fx;
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
	 * Labels for commission kinds.
	 *
	 * @return array
	 */
	public static function kind_labels() {
		return array(
			'sale'         => __( 'Sale', 'wp-affiliate-captain' ),
			'refund'       => __( 'Refund', 'wp-affiliate-captain' ),
			'cancellation' => __( 'Cancellation', 'wp-affiliate-captain' ),
			'chargeback'   => __( 'Chargeback', 'wp-affiliate-captain' ),
			'bonus'        => __( 'Monthly bonus', 'wp-affiliate-captain' ),
			'writeoff'     => __( 'Write-off', 'wp-affiliate-captain' ),
			'reinstatement' => __( 'Reinstatement', 'wp-affiliate-captain' ),
			'repayment'    => __( 'Repayment by the partner', 'wp-affiliate-captain' ),
		);
	}

	/**
	 * Human readable sales source.
	 *
	 * @param string $source Source slug.
	 * @return string
	 */
	public static function source_label( $source ) {
		$map = array(
			'woocommerce'     => 'WooCommerce',
			'wpac'            => 'Affiliate Captain',
			'pass-captain'    => 'Pass Captain',
			'voucher-captain' => 'Voucher Captain',
		);
		return isset( $map[ $source ] ) ? $map[ $source ] : ucwords( str_replace( array( '-', '_' ), ' ', (string) $source ) );
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

		// Re-attribution (reassign()): the order keeps its attribution row, which moves to the new
		// partner; the new commissions get their own keys (round number).
		$round    = isset( $sale['reassign_round'] ) ? (int) $sale['reassign_round'] : 0;
		$existing = self::attribution_for( $sale['source'], $sale['order_id'] );
		if ( $existing && ! $round ) {
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
		$attr  = $resolved['attribution'];
		$aff   = AffiliateRepository::find( (int) $attr['affiliate_id'] );
		$flags = \WPAffiliateCaptain\Tracking\FraudCheck::flags( $sale, $attr );
		if ( $attr['review'] ) {
			array_unshift( $flags, 'self_referral' );
		}
		if ( $flags ) {
			$attr['review'] = true;
			$attr['note']   = substr( implode( ',', array_unique( $flags ) ), 0, 255 );
			Audit::log(
				'attribution.flagged',
				'affiliate',
				(int) $attr['affiliate_id'],
				array(
					'source'   => $sale['source'],
					'order_id' => $sale['order_id'],
					'flags'    => $flags,
				)
			);
		}

		$rules   = RuleRepository::active();
		$default = RuleRepository::default_rate();
		$program = strtoupper( (string) Settings::get( 'currency' ) );
		if ( $program !== $sale['currency'] ) {
			// Fixed amounts are stored in the program currency: convert them with the
			// configured rates. Without a rate the matching rule still wins (and pays 0)
			// so a broader percent rule never takes over a more specific fixed rule.
			$rates = Fx::rates();
			foreach ( $rules as $i => $r ) {
				if ( 'fixed' === $r['rate_type'] ) {
					$converted = Fx::from_program( (int) $r['rate'], $sale['currency'], $program, $rates );
					if ( null === $converted ) {
						// Stays a (zero) fixed rule: it competes by specificity like any other rule.
						// As 'exclude' it would beat every more specific rule and the lifetime rate.
						$rules[ $i ]['rate']    = '0';
						$rules[ $i ]['no_rate'] = 1;
					} else {
						$rules[ $i ]['rate'] = (string) $converted;
					}
				}
			}
			if ( 'fixed' === $default['rate_type'] ) {
				$converted = Fx::from_program( (int) $default['rate'], $sale['currency'], $program, $rates );
				$default   = null === $converted ? array(
					'rate_type' => 'percent',
					'rate'      => '0',
				) : array(
					'rate_type' => 'fixed',
					'rate'      => (string) $converted,
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
			static function () use ( $wpdb, $sale, $attr, $aff, $rules, $default, $hold, $status, $round, $existing ) {
				// A concurrent report of the same order loses on the unique key; that is expected under
				// load and answered as "duplicate" below, so it must not flood the PHP error log. Any other
				// failure is raised as IntegrationException with the database error (retry queue, audit).
				$suppress = $wpdb->suppress_errors( true );
				$fields   = array(
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
				);
				if ( $round && $existing ) {
					unset( $fields['created_at'] );
					$ok = false !== $wpdb->update( Db::table( 'attributions' ), $fields, array( 'id' => (int) $existing->id ) );
				} else {
					$ok = $wpdb->insert( Db::table( 'attributions' ), $fields );
				}
				$wpdb->suppress_errors( $suppress );
				if ( ! $ok && $round ) {
					throw new IntegrationException( 'db_attribution', $wpdb->last_error );
				}
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
				$attribution_id = $round && $existing ? (int) $existing->id : (int) $wpdb->insert_id;
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
					$line_net = (int) $calc['base_minor'];
					$base     = (int) apply_filters( 'wpac_commission_base', $calc['base_minor'], $item, $sale );
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
						// Unfiltered line net: refunds arrive as line net and are scaled to the base.
						'line_net'      => $line_net,
					);
					$key      = 'sale:' . $sale['source'] . ':' . $sale['order_id'] . ':' . $item['item_id'] . ( $round ? ':r' . $round : '' );
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
							'idempotency_key' => self::key( $key ),
							'created_at'      => Db::now(),
							'updated_at'      => Db::now(),
						)
					);
					if ( ! $ok ) {
						throw new IntegrationException( 'db_commission', $wpdb->last_error );
					}
					$cid   = (int) $wpdb->insert_id;
					$ids[] = $cid;
					self::event( $cid, 'created', '', $status, $calc['amount_minor'], $attr['review'] ? 'review: ' . $attr['note'] : '', $sale['idempotency_key'] );
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
					// Several refund lines for the same item add up.
					$lines[ $line['item_id'] ] = ( isset( $lines[ $line['item_id'] ] ) ? $lines[ $line['item_id'] ] : 0 ) + (int) $line['net_minor'];
				}
			}
		}

		$result = Db::transaction(
			static function () use ( $wpdb, $refund, $by_item, $lines ) {
				$ids = array();
				$t   = Db::table( 'commissions' );
				foreach ( $lines as $item_id => $refunded_net ) {
					// Lock the original commission so parallel refunds/chargebacks are serialized.
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$parent = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d FOR UPDATE", $by_item[ $item_id ]->id ) );
					if ( ! $parent || 'rejected' === $parent->status ) {
						continue;
					}
					$key = self::key( 'refund:' . $refund['source'] . ':' . $refund['order_id'] . ':' . $refund['refund_id'] . ':' . $item_id );
					if ( self::find_by_key( $key ) ) {
						continue; // Idempotent.
					}
					// The base may differ from the line net (wpac_commission_base filter): scale the refund.
					$snap = $parent->rule_snapshot ? json_decode( (string) $parent->rule_snapshot, true ) : null;
					if ( ! $refund['full'] && is_array( $snap ) && ! empty( $snap['line_net'] ) && (int) $snap['line_net'] !== (int) $parent->base_minor ) {
						$refunded_net = (int) round( $refunded_net * (int) $parent->base_minor / (int) $snap['line_net'] );
					}
					$adjusted = self::adjusted_sum( (int) $parent->id, true );
					$refunded = self::refunded_base( (int) $parent->id, true );
					$amount   = Calculator::refund_adjustment( (int) $parent->amount_minor, (int) $parent->base_minor, $adjusted, $refunded_net, $refund['full'], $refunded );
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
							'base_minor'      => -( $refund['full'] ? max( 0, (int) $parent->base_minor - $refunded ) : min( abs( (int) $refunded_net ), max( 0, (int) $parent->base_minor - $refunded ) ) ),
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
					if ( 'approved' === $status ) {
						// Correction of an approved/paid commission: payable balance changes immediately.
						Balance::post( (int) $parent->affiliate_id, $parent->currency, 'approved', $amount, $aid, 0, $refund['type'] );
					}
					self::event( $aid, 'created', '', $status, $amount, $refund['type'] . ': ' . $refund['reason'], $refund['refund_id'] );
					self::event( (int) $parent->id, 'adjusted', $parent->status, $parent->status, $amount, $refund['type'], $refund['refund_id'] );
				}
				return array(
					'status'         => $ids ? 'adjusted' : 'unchanged',
					'adjustment_ids' => $ids,
				);
			}
		);
		if ( $result['adjustment_ids'] ) {
			// A correction of a commission that sits in a payout not sent to the bank yet: the
			// payout would pay too much now and offset it only later (clawback). Flag it so the
			// operator can rebuild the payout before exporting it.
			foreach ( $originals as $o ) {
				if ( (int) $o->payout_id ) {
					\WPAffiliateCaptain\Payouts\PayoutService::flag_clawback( (int) $o->payout_id, $refund['source'] . ' #' . $refund['order_id'] );
				}
			}
			self::maybe_forget_links( $refund['source'], $refund['order_id'] );
			do_action( 'wpac_commission_adjusted', $refund, $result['adjustment_ids'] ); // After COMMIT.
		}
		return $result;
	}

	/**
	 * Move an attributed order to another partner: the current partner's commission is reversed
	 * (correction "cancellation" with the reason), the order is booked again for the new partner
	 * with the same rules as a new sale. Both steps are in the audit log.
	 *
	 * @param array  $sale         Normalized sale payload of the order (as reported originally).
	 * @param int    $affiliate_id New partner.
	 * @param string $reason       Reason (required).
	 * @return array|\WP_Error Result of the new booking.
	 */
	public static function reassign( array $sale, $affiliate_id, $reason ) {
		$reason = trim( (string) $reason );
		$attr   = self::attribution_for( $sale['source'], $sale['order_id'] );
		$to     = AffiliateRepository::find( (int) $affiliate_id );
		if ( '' === $reason ) {
			return new \WP_Error( 'wpac_reason_required', __( 'Please enter a reason.', 'wp-affiliate-captain' ) );
		}
		if ( ! $attr ) {
			return new \WP_Error( 'wpac_state', __( 'This order is not attributed yet; assign it instead.', 'wp-affiliate-captain' ) );
		}
		if ( ! $to || 'active' !== $to->status ) {
			return new \WP_Error( 'wpac_state', __( 'The new partner must be active.', 'wp-affiliate-captain' ) );
		}
		if ( (int) $attr->affiliate_id === (int) $to->id ) {
			return new \WP_Error( 'wpac_state', __( 'The order is already attributed to this partner.', 'wp-affiliate-captain' ) );
		}
		$from  = (int) $attr->affiliate_id;
		$round = 1 + (int) get_option( 'wpac_reassign_' . md5( $sale['source'] . ':' . $sale['order_id'] ), 0 );
		$label = sprintf( /* translators: %s: reason */ __( 'Reassigned to another partner: %s', 'wp-affiliate-captain' ), $reason );
		self::record_refund(
			array(
				'source'    => $sale['source'],
				'order_id'  => (string) $sale['order_id'],
				'refund_id' => 'reassign-' . $round,
				'type'      => 'cancellation',
				'full'      => true,
				'reason'    => $label,
				'items'     => array(),
			)
		);
		update_option( 'wpac_reassign_' . md5( $sale['source'] . ':' . $sale['order_id'] ), $round, false );
		CustomerLinks::forget_order( $sale['source'], (string) $sale['order_id'] );
		$sale['affiliate_id']       = (int) $to->id;
		$sale['attribution_method'] = 'manual';
		$sale['partner_code']       = '';
		$sale['touch']              = null;
		$sale['coupon_codes']       = array();
		$sale['reassign_round']     = $round;
		$result = self::record_sale( $sale );
		Audit::log(
			'attribution.reassigned',
			'order',
			0,
			array(
				'source' => $sale['source'],
				'order'  => (string) $sale['order_id'],
				'from'   => $from,
				'to'     => (int) $to->id,
				'reason' => $reason,
			)
		);
		return $result;
	}

	/**
	 * Reinstate the commission of an order whose full reversal (cancellation, chargeback, status
	 * refund) turned out to be wrong: chargeback won, cancelled order paid after all. Reversals
	 * that are not paid out yet are rejected; reversals already deducted in a payout get a
	 * positive reinstatement booking (next payout). Idempotent per reversal.
	 *
	 * @param string $source   Source.
	 * @param string $order_id Order.
	 * @param string $reason   Reason (shown in the history).
	 * @return int Number of reversals reinstated.
	 */
	public static function reinstate( $source, $order_id, $reason ) {
		global $wpdb;
		$t = Db::table( 'commissions' );
		// Full status reversals of this order (refund id "status-…"), not rejected.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$children = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE source = %s AND source_order_id = %s AND parent_id > 0 AND kind IN ('refund','cancellation','chargeback') AND status <> 'rejected' AND amount_minor < 0", $source, (string) $order_id ) );
		$n        = 0;
		foreach ( $children as $child ) {
			$events = self::events( (int) $child->id );
			$status_reversal = false;
			foreach ( $events as $e ) {
				if ( 0 === strpos( (string) $e->source_event_id, 'status-' ) ) {
					$status_reversal = true;
				}
			}
			if ( ! $status_reversal ) {
				continue; // Partial refunds stay (money really went back).
			}
			if ( ! (int) $child->payout_id && in_array( $child->status, array( 'pending', 'review', 'approved' ), true ) ) {
				if ( self::transition( (int) $child->id, 'rejected', $reason, get_current_user_id(), 0, $child->status ) ) {
					++$n;
				}
				continue;
			}
			$rkey = self::key( 'reinstate:' . (int) $child->id );
			if ( self::find_by_key( $rkey ) ) {
				continue;
			}
			$parent = self::find( (int) $child->parent_id );
			if ( ! $parent || 'rejected' === $parent->status ) {
				continue;
			}
			$amount = -(int) $child->amount_minor;
			Db::transaction(
				static function () use ( $wpdb, $t, $child, $parent, $amount, $rkey, $reason ) {
					$ok = $wpdb->insert(
						$t,
						array(
							'affiliate_id'    => (int) $child->affiliate_id,
							'source'          => $child->source,
							'source_order_id' => $child->source_order_id,
							'source_item_id'  => $child->source_item_id,
							'kind'            => 'reinstatement',
							'parent_id'       => (int) $parent->id,
							'product_ref'     => $child->product_ref,
							'description'     => substr( (string) $reason, 0, 255 ),
							'currency'        => $child->currency,
							'base_minor'      => -(int) $child->base_minor,
							'amount_minor'    => $amount,
							'rate_type'       => $child->rate_type,
							'rate'            => $child->rate,
							'rule_id'         => (int) $child->rule_id,
							'status'          => 'approved',
							'hold_until'      => Db::now(),
							'idempotency_key' => $rkey,
							'created_at'      => Db::now(),
							'updated_at'      => Db::now(),
						)
					);
					if ( ! $ok ) {
						throw new IntegrationException( 'db_reinstatement', $wpdb->last_error );
					}
					$id = (int) $wpdb->insert_id;
					Balance::post( (int) $child->affiliate_id, $child->currency, 'approved', $amount, $id, 0, 'reinstatement' );
					self::event( $id, 'created', '', 'approved', $amount, 'reinstatement: ' . $reason, 'reinstate-' . (int) $child->id );
					self::event( (int) $parent->id, 'adjusted', $parent->status, $parent->status, $amount, 'reinstatement', 'reinstate-' . (int) $child->id );
				}
			);
			++$n;
		}
		if ( $n ) {
			Audit::log( 'commission.reinstated', 'order', 0, array( 'source' => $source, 'order' => (string) $order_id, 'count' => $n ) );
		}
		return $n;
	}

	/**
	 * Allowed status transitions: to => allowed from-statuses.
	 * Paid and rejected are final.
	 */
	const TRANSITIONS = array(
		'pending'  => array( 'review' ),
		'approved' => array( 'pending', 'review' ),
		'rejected' => array( 'pending', 'review', 'approved' ),
		'paid'     => array( 'approved' ),
	);

	/**
	 * Release pending commissions whose hold period has passed (cron).
	 * Works in batches until done (bounded per run).
	 *
	 * @return int Number released.
	 */
	public static function approve_due() {
		global $wpdb;
		$t     = Db::table( 'commissions' );
		$count = 0;
		// Time budget per run: the rest follows in the next (hourly) run, so one
		// WP-Cron request never runs into max_execution_time.
		$deadline = microtime( true ) + (float) apply_filters( 'wpac_approve_due_budget', 20 );
		// Keyset pagination (id > last): rows that cannot be approved right now (changed by a
		// concurrent request, or a correction whose parent is still open) are skipped instead of
		// being selected again and stopping the whole run.
		$last = 0;
		for ( $batch = 0; $batch < 40 && microtime( true ) < $deadline; $batch++ ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE status = 'pending' AND hold_until <= %s AND id > %d ORDER BY id ASC LIMIT 500", Db::now(), $last ) );
			if ( ! $ids ) {
				break;
			}
			foreach ( $ids as $id ) {
				$last = (int) $id;
				if ( self::transition( (int) $id, 'approved', __( 'Hold period ended', 'wp-affiliate-captain' ), 0 ) ) {
					++$count;
				}
			}
		}
		return $count;
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
		if ( 'approved' === $to && (int) $c->parent_id ) {
			$parent = self::find( (int) $c->parent_id );
			if ( $parent && ! in_array( $parent->status, array( 'approved', 'paid' ), true ) ) {
				return new \WP_Error( 'wpac_parent_open', __( 'This correction belongs to a commission that is not approved yet. Approve or reject the original commission first.', 'wp-affiliate-captain' ) );
			}
		}
		if ( ! self::transition( (int) $id, $to, $reason, get_current_user_id(), 0, $c->status ) ) {
			return new \WP_Error( 'wpac_conflict', __( 'The commission was changed in the meantime. Please reload and try again.', 'wp-affiliate-captain' ) );
		}
		foreach ( self::children( (int) $id ) as $child ) {
			if ( $child->status === $c->status ) {
				self::transition( (int) $child->id, $to, $reason, get_current_user_id(), 0, $c->status );
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
		foreach ( self::children( (int) $id ) as $child ) {
			if ( (int) $child->payout_id && 'rejected' !== $child->status ) {
				// The correction would stay in that payout and be deducted for a rejected commission.
				return new \WP_Error( 'wpac_state', __( 'A correction of this commission is part of a payout. Cancel that payout first.', 'wp-affiliate-captain' ) );
			}
		}
		// Atomic: only if still unassigned to a payout and in the status we saw.
		if ( ! self::transition( (int) $id, 'rejected', $reason, get_current_user_id(), 0, $c->status ) ) {
			return new \WP_Error( 'wpac_conflict', __( 'The commission was changed in the meantime (e.g. added to a payout). Please reload and try again.', 'wp-affiliate-captain' ) );
		}
		foreach ( self::children( (int) $id ) as $child ) {
			if ( in_array( $child->status, array( 'pending', 'review', 'approved' ), true ) ) {
				self::transition( (int) $child->id, 'rejected', $reason, get_current_user_id() );
			}
		}
		if ( 'sale' === $c->kind ) {
			self::maybe_forget_links( $c->source, $c->source_order_id );
		}
		return true;
	}

	/**
	 * Remove lifetime customer links of an order once nothing of its commission is left
	 * (fully refunded, cancelled or rejected).
	 *
	 * @param string $source   Source.
	 * @param string $order_id Order.
	 */
	private static function maybe_forget_links( $source, $order_id ) {
		global $wpdb;
		$t = Db::table( 'commissions' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount_minor),0) FROM {$t} WHERE source = %s AND source_order_id = %s AND status <> 'rejected' AND kind <> 'bonus'", $source, $order_id ) );
		if ( $left <= 0 ) {
			CustomerLinks::forget_order( $source, $order_id );
		}
	}

	/**
	 * Write off a negative balance (e.g. clawbacks of a partner who no longer earns):
	 * books an approved counter entry so the account is settled at 0. Audited.
	 *
	 * @param int    $affiliate_id Partner.
	 * @param string $currency     Currency.
	 * @param string $reason       Reason (required).
	 * @return int|\WP_Error Commission ID of the write-off.
	 */
	public static function write_off( $affiliate_id, $currency, $reason ) {
		global $wpdb;
		$reason = trim( (string) $reason );
		if ( '' === $reason ) {
			return new \WP_Error( 'wpac_reason_required', __( 'Please enter a reason.', 'wp-affiliate-captain' ) );
		}
		$currency = strtoupper( (string) $currency );
		$t        = Db::table( 'commissions' );
		try {
			return Db::transaction(
				static function () use ( $wpdb, $t, $affiliate_id, $currency, $reason ) {
					// The open (approved, unpaid) rows define the balance; lock them.
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$open = (int) array_sum( array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT amount_minor FROM {$t} WHERE affiliate_id = %d AND currency = %s AND status = 'approved' AND payout_id = 0 FOR UPDATE", $affiliate_id, $currency ) ) ) );
					if ( $open >= 0 ) {
						throw new IntegrationException( 'not_negative', __( 'The balance is not negative.', 'wp-affiliate-captain' ) );
					}
					$key = self::key( 'writeoff:' . (int) $affiliate_id . ':' . $currency . ':' . Db::now() . ':' . wp_generate_password( 6, false ) );
					$ok  = $wpdb->insert(
						$t,
						array(
							'affiliate_id'    => (int) $affiliate_id,
							'source'          => 'wpac',
							'source_order_id' => 'writeoff',
							'source_item_id'  => '',
							'kind'            => 'writeoff',
							'parent_id'       => 0,
							'product_ref'     => '',
							'description'     => substr( $reason, 0, 255 ),
							'currency'        => $currency,
							'base_minor'      => 0,
							'amount_minor'    => -$open,
							'rate_type'       => 'fixed',
							'rate'            => '0',
							'rule_id'         => 0,
							'rule_snapshot'   => null,
							'status'          => 'approved',
							'hold_until'      => Db::now(),
							'idempotency_key' => $key,
							'created_at'      => Db::now(),
							'updated_at'      => Db::now(),
						)
					);
					if ( ! $ok ) {
						throw new IntegrationException( 'db_writeoff', $wpdb->last_error );
					}
					$id = (int) $wpdb->insert_id;
					Balance::post( (int) $affiliate_id, $currency, 'approved', -$open, $id, 0, 'write-off' );
					self::event( $id, 'created', '', 'approved', -$open, 'write-off: ' . $reason, $key, get_current_user_id() );
					Audit::log(
						'commission.written_off',
						'affiliate',
						(int) $affiliate_id,
						array(
							'amount'   => -$open,
							'currency' => $currency,
							'reason'   => $reason,
						)
					);
					return $id;
				}
			);
		} catch ( IntegrationException $e ) {
			return new \WP_Error( 'wpac_' . $e->error_code, $e->getMessage() );
		}
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
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE affiliate_id = %d AND status IN ('pending','review','approved') AND payout_id = 0 AND amount_minor > 0", $affiliate_id ) );
		foreach ( $ids as $id ) {
			self::reject( (int) $id, $reason );
		}
	}

	/**
	 * Atomic status transition (compare-and-set) with event.
	 *
	 * The UPDATE only succeeds if the row is still in an allowed (or the
	 * expected) from-status; for "rejected" it must not be assigned to a payout,
	 * for "paid" it must be assigned to the given payout.
	 *
	 * @param int         $id         Commission.
	 * @param string      $to         New status.
	 * @param string      $reason     Reason.
	 * @param int         $actor      Actor.
	 * @param int         $payout     Payout ID (required for paid).
	 * @param string|null $expected   Expected current status (optional, stricter).
	 * @param bool        $fire_hooks Fire wpac_commission_{status} (false inside transactions; caller fires later).
	 * @return bool Whether the transition happened.
	 */
	public static function transition( $id, $to, $reason = '', $actor = 0, $payout = 0, $expected = null, $fire_hooks = true ) {
		global $wpdb;
		if ( ! isset( self::TRANSITIONS[ $to ] ) ) {
			return false;
		}
		$from = null === $expected ? self::TRANSITIONS[ $to ] : array_intersect( array( $expected ), self::TRANSITIONS[ $to ] );
		if ( ! $from ) {
			return false;
		}
		$c = self::find( $id );
		if ( ! $c ) {
			return false;
		}
		$t     = Db::table( 'commissions' );
		$in    = implode( ',', array_fill( 0, count( $from ), '%s' ) );
		$extra = '';
		$args  = array( $to, Db::now(), (int) $id );
		if ( 'rejected' === $to ) {
			$extra = ' AND payout_id = 0';
		} elseif ( 'paid' === $to ) {
			$extra  = ' AND payout_id = %d';
			$args[] = (int) $payout;
		}
		$args = array_merge( $args, array_values( $from ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "UPDATE {$t} SET status = %s, updated_at = %s WHERE id = %d{$extra} AND status IN ({$in})";
		$ok  = Db::transaction(
			static function () use ( $wpdb, $t, $sql, $args, $id, $to, $c, $reason, $actor, $from ) {
				// Lock the row and read the status it really has now: the balance
				// booking depends on it (e.g. approved -> rejected reverses the balance),
				// and the row may have changed since the read outside the transaction.
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$from_status = (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$t} WHERE id = %d FOR UPDATE", $id ) );
				if ( ! in_array( $from_status, $from, true ) ) {
					return false;
				}
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$rows = (int) $wpdb->query( $wpdb->prepare( $sql, $args ) );
				if ( 1 !== $rows ) {
					return false;
				}
				self::event( (int) $id, $to, $from_status, $to, (int) $c->amount_minor, $reason, '', $actor );
				if ( 'approved' === $to ) {
					Balance::post( (int) $c->affiliate_id, $c->currency, 'approved', (int) $c->amount_minor, (int) $id );
				} elseif ( 'rejected' === $to && 'approved' === $from_status ) {
					Balance::post( (int) $c->affiliate_id, $c->currency, 'rejected', -(int) $c->amount_minor, (int) $id, 0, substr( (string) $reason, 0, 191 ) );
				}
				return true;
			}
		);
		if ( ! $ok ) {
			return false;
		}
		if ( $fire_hooks ) {
			do_action( 'wpac_commission_' . $to, (int) $id );
		}
		return true;
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
	 * Idempotency key that fits the column: long keys are hashed instead of
	 * truncated, so distinct keys never collide. Short keys stay unchanged.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	public static function key( $key ) {
		$key = (string) $key;
		return strlen( $key ) <= 191 ? $key : substr( $key, 0, 140 ) . ':h:' . sha1( $key );
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
	public static function adjusted_sum( $id, $lock = false ) {
		global $wpdb;
		$t = Db::table( 'commissions' );
		// A locking read sees rows committed by parallel refunds (a plain read in a REPEATABLE READ
		// transaction would use the older snapshot and reverse the same amount twice).
		$for = $lock ? ' FOR UPDATE' : ''; // Constant fragment, no input.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount_minor),0) FROM {$t} WHERE parent_id = %d AND status <> 'rejected'{$for}", $id ) );
	}

	/**
	 * Net base already refunded (positive), from non-rejected corrections.
	 *
	 * @param int  $id   Parent.
	 * @param bool $lock Locking read (inside a transaction).
	 * @return int
	 */
	public static function refunded_base( $id, $lock = false ) {
		global $wpdb;
		$t = Db::table( 'commissions' );
		$for = $lock ? ' FOR UPDATE' : ''; // Constant fragment, no input.
		// Reinstatements give back the base of a reversal that turned out to be wrong.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) abs( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(base_minor),0) FROM {$t} WHERE parent_id = %d AND status <> 'rejected' AND ( base_minor < 0 OR kind = 'reinstatement' ){$for}", $id ) ) );
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
		return \WPAffiliateCaptain\Core\Paged::fetch( $t, $where, $params, $args, array( 'id', 'created_at', 'amount_minor', 'status', 'affiliate_id' ) );
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
