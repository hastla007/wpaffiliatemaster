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
use WPAffiliateCaptain\Core\Money;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Tracking\AttributionResolver;
use WPAffiliateCaptain\Tracking\CustomerLinks;

defined( 'ABSPATH' ) || exit;

final class Ledger {

	const STATUSES = array( 'pending', 'review', 'approved', 'paid', 'rejected' );

	/**
	 * Kinds booked by hand (admin, wpac_create_adjustment()). Linked to a commission they stay
	 * untouched by refunds of its order (R12-N3, owner decision 2026-11-15).
	 */
	const MANUAL_KINDS = array( 'adjustment' );

	/**
	 * Commission event that links a manual booking of wpac_create_adjustment() to a commission
	 * for information only (P15-1, since 0.15.0): source_event_id = LINK_PREFIX . commission ID,
	 * reason = the add-on's reference. Unlike parent_id it has no effect on the ledger (refunds,
	 * corrections, payouts and credit notes ignore it).
	 */
	const LINK_EVENT = 'linked';

	/** Prefix of source_event_id of a LINK_EVENT. */
	const LINK_PREFIX = 'parent:';

	/**
	 * History entry (commission_events.id) of the last transition per commission in this request,
	 * for callers that fire the status action themselves later (AC-5).
	 *
	 * @var array<int,int>
	 */
	private static $last_event_ids = array();

	/**
	 * ID of the history entry written by the last successful transition() of a commission in
	 * this request (0 = none). Third parameter of the status actions (contract 1.10.0, AC-5).
	 *
	 * @param int $id Commission.
	 * @return int
	 */
	public static function last_event_id( $id ) {
		return isset( self::$last_event_ids[ (int) $id ] ) ? self::$last_event_ids[ (int) $id ] : 0;
	}

	/**
	 * Labels.
	 *
	 * @return array
	 */
	public static function status_labels() {
		return array(
			'pending'  => __( 'Pending (hold period)', 'affiliate-captain' ),
			'review'   => __( 'Needs review', 'affiliate-captain' ),
			'approved' => __( 'Approved', 'affiliate-captain' ),
			'paid'     => __( 'Paid', 'affiliate-captain' ),
			'rejected' => __( 'Rejected', 'affiliate-captain' ),
		);
	}

	/**
	 * Labels for commission kinds.
	 *
	 * @return array
	 */
	public static function kind_labels() {
		return array(
			'sale'         => __( 'Sale', 'affiliate-captain' ),
			'refund'       => __( 'Refund', 'affiliate-captain' ),
			'cancellation' => __( 'Cancellation', 'affiliate-captain' ),
			'chargeback'   => __( 'Chargeback', 'affiliate-captain' ),
			'bonus'        => __( 'Monthly bonus', 'affiliate-captain' ),
			'writeoff'     => __( 'Write-off', 'affiliate-captain' ),
			'reinstatement' => __( 'Reinstatement', 'affiliate-captain' ),
			'increase'     => __( 'Order increase', 'affiliate-captain' ),
			'adjustment'   => __( 'Manual booking', 'affiliate-captain' ),
			'repayment'    => __( 'Repayment by the partner', 'affiliate-captain' ),
			'correction'   => __( 'Correction credit note', 'affiliate-captain' ),
		);
	}

	/**
	 * Human readable attribution method.
	 *
	 * @param string $method Method slug (code|link|cookie|coupon|app|manual|lifetime|renewal …).
	 * @return string
	 */
	public static function method_label( $method ) {
		$labels = array(
			'link'     => __( 'Partner link', 'affiliate-captain' ),
			'cookie'   => __( 'Cookie', 'affiliate-captain' ),
			'code'     => __( 'Partner code', 'affiliate-captain' ),
			'coupon'   => __( 'Coupon', 'affiliate-captain' ),
			'lifetime' => __( 'Lifetime customer', 'affiliate-captain' ),
			'manual'   => __( 'Manual assignment', 'affiliate-captain' ),
			'app'      => __( 'App', 'affiliate-captain' ),
			'renewal'  => __( 'Subscription renewal', 'affiliate-captain' ),
		);
		return isset( $labels[ $method ] ) ? $labels[ $method ] : ucwords( str_replace( array( '-', '_' ), ' ', (string) $method ) );
	}

	/**
	 * Human readable label of a commission kind (never the raw code).
	 *
	 * @param string $kind Kind.
	 * @return string
	 */
	public static function kind_label( $kind ) {
		$labels = self::kind_labels();
		return isset( $labels[ $kind ] ) ? $labels[ $kind ] : ucwords( str_replace( array( '-', '_' ), ' ', (string) $kind ) );
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
		if ( ! $round && self::pseudonymised_order( (string) $sale['source'], (string) $sale['order_id'] ) ) {
			// Booked long ago; past the retention period its attribution was deleted and the order
			// number pseudonymised (Retention::pseudonymise_commissions()): still a duplicate.
			return array(
				'status'         => 'duplicate',
				'attribution_id' => 0,
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
		if ( ! empty( $sale['review'] ) ) {
			// The source asks for a review (e.g. renewal of a subscription whose first order is under review).
			$flags[] = 'source_review';
		}
		if ( $flags ) {
			$attr['review'] = true;
			$attr['note']   = Db::cut( implode( ',', array_unique( $flags ) ), 255 );
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

		list( $rules, $default, $precedence ) = self::rules_for( $aff, $sale['currency'], (string) $attr['method'] );
		$hold    = self::hold_until( $sale, (int) $attr['affiliate_id'] );
		$status  = $attr['review'] ? 'review' : 'pending';

		$result = Db::transaction(
			static function () use ( $wpdb, $sale, $attr, $aff, $rules, $default, $hold, $status, $round, $existing, $precedence ) {
				// Usage limit of the code: counted first with a conditional UPDATE, so parallel orders
				// cannot all pass a limit read without a lock (the row lock lasts until COMMIT; a later
				// failure rolls the count back with everything else).
				$counted = false;
				if ( $attr['code_id'] ) {
					if ( ! CodeRepository::increment_usage( (int) $attr['code_id'] ) ) {
						return array(
							'status'         => 'unattributed',
							'attribution_id' => 0,
							'commission_ids' => array(),
							'reason'         => 'code_limit',
						);
					}
					$counted = true;
				}

				// A concurrent report of the same order loses on the unique key; that is expected under
				// load and answered as "duplicate" below, so it must not flood the PHP error log. Any other
				// failure is raised as IntegrationException with the database error (retry queue, audit).
				$suppress = $wpdb->suppress_errors( true );
				$fields   = array(
					'source'          => $sale['source'],
					'source_order_id' => $sale['order_id'],
					'affiliate_id'    => (int) $attr['affiliate_id'],
					'code_id'         => (int) $attr['code_id'],
					'method'          => Db::cut( (string) $attr['method'], 20 ),
					'campaign'        => Db::cut( (string) $attr['campaign'], 100 ),
					'content_id'      => (int) $attr['content_id'],
					'sub_id'          => Db::cut( isset( $attr['sub_id'] ) ? (string) $attr['sub_id'] : '', 100 ),
					'visit_id'        => isset( $attr['visit_id'] ) ? (int) $attr['visit_id'] : 0,
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
					throw new IntegrationException( 'db_attribution', esc_html( $wpdb->last_error ) );
				}
				if ( ! $ok ) {
					// Unique (source, order) – a concurrent request won.
					if ( self::attribution_for( $sale['source'], $sale['order_id'] ) ) {
						if ( $counted ) {
							CodeRepository::decrement_usage( (int) $attr['code_id'] );
						}
						return array(
							'status'         => 'duplicate',
							'attribution_id' => 0,
							'commission_ids' => array(),
							'reason'         => 'already_processed',
						);
					}
					throw new IntegrationException( 'db_attribution', esc_html( $wpdb->last_error ) );
				}
				$attribution_id = $round && $existing ? (int) $existing->id : (int) $wpdb->insert_id;
				$ids            = array();

				// Lines of the order at the time of the sale: lines added later are booked with
				// record_increase() (as new lines), lines known here never are.
				$order_items = array_values( array_map( 'strval', array_column( $sale['items'], 'item_id' ) ) );
				if ( strlen( (string) wp_json_encode( $order_items ) ) > 30000 ) {
					$order_items = null; // Too long for the snapshot column: new lines are told by their ID.
				}
				foreach ( $sale['items'] as $item ) {
					$line = self::line_commission( $item, $sale, $aff, $rules, $default, $precedence );
					if ( ! $line ) {
						continue;
					}
					$calc     = $line['calc'];
					$snapshot = array(
						'rule'          => $calc['rule'],
						'default'       => $calc['rule'] ? null : $default,
						'touch_model'   => $attr['touch_model'],
						'method'        => $attr['method'],
						'contract'      => $sale['contract'],
						'calculated_at' => Db::now(),
						// Unfiltered line net: refunds arrive as line net and are scaled to the base.
						'line_net'      => $line['line_net'],
						// Quantity booked (fixed rates per piece).
						'quantity'      => isset( $item['quantity'] ) ? (int) $item['quantity'] : 1,
					);
					if ( ! $ids && null !== $order_items ) {
						$snapshot['items'] = $order_items; // Once per order (first row).
					}
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
							'product_ref'     => Db::cut( (string) ( $item['sku'] ? $item['sku'] : $item['product_id'] ), 190 ),
							'description'     => Db::cut( (string) $item['name'], 255 ),
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
						throw new IntegrationException( 'db_commission', esc_html( $wpdb->last_error ) );
					}
					$cid   = (int) $wpdb->insert_id;
					$ids[] = $cid;
					self::event( $cid, 'created', '', $status, $calc['amount_minor'], $attr['review'] ? 'review: ' . $attr['note'] : '', $sale['idempotency_key'] );
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
			// Lifetime link only for an order that earned a commission, and only for the verified
			// identity (customer account, account e-mail) – never for a guest's typed e-mail. Under
			// review the link waits for the approval (approve()).
			if ( Settings::get( 'lifetime_enabled' ) && 'lifetime' !== $attr['method'] && $result['commission_ids'] ) {
				CustomerLinks::link( (int) $aff->id, CustomerLinks::link_hashes( $sale['customer']['user_id'], $sale['customer']['email'] ), $sale['source'], $sale['order_id'], (bool) $attr['review'] );
			}
			/**
			 * Fires after an attribution was persisted.
			 *
			 * @param string $order_id     Source order ID.
			 * @param int    $affiliate_id Affiliate.
			 * @param string $method       code|link|cookie|coupon|app|manual.
			 * @param string $source       Sales source.
			 */
			// Inside an outer transaction (reassign()) only once it is committed.
			Db::after_commit(
				static function () use ( $sale, $aff, $attr, $result ) {
					do_action( 'wpac_affiliate_attributed', $sale['order_id'], (int) $aff->id, $attr['method'], $sale['source'] );
					foreach ( $result['commission_ids'] as $cid ) {
						do_action( 'wpac_commission_created', $cid );
					}
				}
			);
		}
		return $result;
	}

	/**
	 * Rules for a partner's sale: the rules that can apply to the partner (global, own, own
	 * group), fixed amounts converted to the sale currency, the lifetime rate for repeat
	 * purchases of linked customers.
	 *
	 * @param object $aff      Partner row.
	 * @param string $currency Sale currency.
	 * @param string $method   Attribution method.
	 * @return array [ rules, default, precedence ]
	 */
	private static function rules_for( $aff, $currency, $method ) {
		// Only the rules that can apply to this partner (global, own, own group).
		$rules      = RuleRepository::active_for( (int) $aff->id, (int) $aff->group_id );
		$default    = RuleRepository::default_rate( (int) $aff->id );
		$precedence = RuleRepository::precedence();
		$program = strtoupper( (string) Settings::get( 'currency' ) );
		if ( $program !== $currency ) {
			// Fixed amounts are stored in the program currency: convert them with the
			// configured rates. Without a rate the matching rule still wins (and pays 0)
			// so a broader percent rule never takes over a more specific fixed rule.
			$rates = Fx::rates();
			foreach ( $rules as $i => $r ) {
				// SET-AC-22: cap and minimum base are stored in the program currency as well.
				// Without a rate a minimum cannot be shown to be reached (the rule does not match)
				// and a cap cannot be shown to be kept (the rule pays 0, like a fixed rule).
				if ( ! empty( $r['min_base_minor'] ) ) {
					$converted                     = Fx::from_program( (int) $r['min_base_minor'], $currency, $program, $rates );
					$rules[ $i ]['min_base_minor'] = null === $converted ? PHP_INT_MAX : max( 1, (int) $converted );
				}
				if ( ! empty( $r['max_amount_minor'] ) && 'exclude' !== $r['rate_type'] ) {
					$converted = Fx::from_program( (int) $r['max_amount_minor'], $currency, $program, $rates );
					if ( null === $converted ) {
						$rules[ $i ]['rate']    = '0';
						$rules[ $i ]['no_rate'] = 1;
					} else {
						$rules[ $i ]['max_amount_minor'] = max( 1, (int) $converted );
					}
				}
				if ( 'fixed' === $r['rate_type'] && empty( $rules[ $i ]['no_rate'] ) ) {
					$converted = Fx::from_program( (int) $r['rate'], $currency, $program, $rates );
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
				$converted = Fx::from_program( (int) $default['rate'], $currency, $program, $rates );
				$default   = null === $converted ? array(
					'rate_type' => 'percent',
					'rate'      => '0',
				) : array(
					'rate_type' => 'fixed',
					'rate'      => (string) $converted,
				);
			}
		}
		if ( 'lifetime' === $method && '' !== (string) Settings::get( 'lifetime_rate' ) ) {
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
		return array( $rules, $default, $precedence );
	}

	/**
	 * Commission of one sale line with the given rules (base and amount filters applied).
	 *
	 * @param array  $item       Normalized item.
	 * @param array  $sale       Normalized sale.
	 * @param object $aff        Partner row.
	 * @param array  $rules      Rules (rules_for()).
	 * @param array  $default    Program default.
	 * @param string $precedence Rule precedence.
	 * @return array|null [ calc, line_net ], null when the line earns nothing.
	 */
	private static function line_commission( array $item, array $sale, $aff, array $rules, array $default, $precedence ) {
		// Validity of the rules (SET-AC-22): the day of the payment in the site's time zone; a line
		// added to the order later (no payment date) is booked with the rules valid today.
		$paid              = ! empty( $sale['paid_at'] ) ? strtotime( $sale['paid_at'] . ' UTC' ) : false;
		$item['sale_date'] = Calculator::local_date( false === $paid ? null : $paid );
		// The amount filter runs once, after the base filter (Calculator::filter_amount()).
		$calc = Calculator::calculate( $item, $rules, (int) $aff->id, (int) $aff->group_id, $default, false, $precedence );

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
			$calc              = Calculator::calculate( $item, $rules, (int) $aff->id, (int) $aff->group_id, $default, false, $precedence );
		}
		if ( ! $calc['excluded'] ) {
			$calc['amount_minor'] = Calculator::filter_amount( $calc['amount_minor'], $item, (int) $aff->id, $calc['rule'], (int) $calc['base_minor'] );
		}
		if ( $calc['amount_minor'] <= 0 ) {
			return null;
		}
		return array(
			'calc'     => $calc,
			'line_net' => $line_net,
		);
	}

	/**
	 * Book the increase of an attributed, paid order whose line items were raised after the sale
	 * was recorded (quantity or net total raised, line added in the admin).
	 *
	 * - Raised line with a commission: an "increase" row under the original commission for the
	 *   difference between the current line base and the base still booked (original + earlier
	 *   increases − refunds/lowered corrections), with the rate of the original commission
	 *   (rule changes never alter history), same partner and hold period.
	 * - Line added after the sale: a sale row with the current rules (same partner and hold).
	 * - Lines known at the time of the sale that earned nothing stay without commission.
	 *
	 * Nothing is booked when the commission of the order is under review, rejected or fully
	 * reversed (status "skipped" with the reason). Idempotent: the key of an increase row is the
	 * base booked after it, the key of a new line the key of its sale row.
	 *
	 * @param array $args { source, order_id, currency, contract, items: normalized items whose
	 *                    net_minor is the current net of the line (after refunds, capped to the
	 *                    order total like the sale), reason }.
	 * @return array { status: booked|unchanged|skipped|no_commission, commission_ids, amount_minor, reason }
	 * @throws IntegrationException On DB errors.
	 */
	public static function record_increase( array $args ) {
		global $wpdb;
		$result = array(
			'status'         => 'unchanged',
			'commission_ids' => array(),
			'amount_minor'   => 0,
			'reason'         => '',
		);
		$attr   = self::attribution_for( $args['source'], $args['order_id'] );
		if ( ! $attr ) {
			$result['status'] = 'no_commission';
			return $result;
		}
		$cur_aff = (int) $attr->affiliate_id;
		$rows    = array(); // Newest sale row of the current partner per line (any status).
		$known   = array(); // Lines known at the time of the sale.
		$listed  = false;
		$max_id  = 0;
		foreach ( self::sale_commissions( $args['source'], $args['order_id'] ) as $c ) {
			$item_id           = (string) $c->source_item_id;
			$known[ $item_id ] = true;
			$max_id            = max( $max_id, (int) $item_id );
			$snap              = $c->rule_snapshot ? json_decode( (string) $c->rule_snapshot, true ) : null;
			if ( is_array( $snap ) && isset( $snap['items'] ) && is_array( $snap['items'] ) ) {
				$listed = true;
				foreach ( $snap['items'] as $known_id ) {
					$known[ (string) $known_id ] = true;
				}
			}
			if ( (int) $c->affiliate_id === $cur_aff && ( ! isset( $rows[ $item_id ] ) || (int) $c->id > (int) $rows[ $item_id ]->id ) ) {
				$rows[ $item_id ] = $c;
			}
		}

		// Candidates (read without lock; checked again under lock below).
		$raised = array();
		$added  = array();
		foreach ( $args['items'] as $item ) {
			$item_id = (string) $item['item_id'];
			if ( isset( $rows[ $item_id ] ) ) {
				$c = $rows[ $item_id ];
				if ( self::increase_of( $c, $item, false ) ) { // Rejected rows too: reported as skipped.
					$raised[ $item_id ] = $item;
				}
			} elseif ( ! isset( $known[ $item_id ] ) && ( $listed || (int) $item_id > $max_id ) && (int) $item['net_minor'] > 0 ) {
				// Orders booked before the line list was stored: a line is new when its ID is higher
				// than every line with a commission (WooCommerce item IDs grow).
				$added[ $item_id ] = $item;
			}
		}
		if ( ! $raised && ! $added ) {
			return $result;
		}

		// Only for a commission that stands and is not waiting for (or failed) a review.
		$open = array();
		foreach ( $rows as $c ) {
			if ( 'rejected' !== $c->status ) {
				$open[] = $c;
			}
		}
		$result['status'] = 'skipped';
		// The attribution keeps its status after a review; the commissions tell the decision.
		if ( in_array( 'review', array_column( $open, 'status' ), true ) ) {
			$result['reason'] = 'review';
			return $result;
		}
		if ( ! $open ) {
			$result['reason'] = 'rejected';
			return $result;
		}
		if ( ! self::commission_stands( $args['source'], $args['order_id'] ) ) {
			$result['reason'] = 'reversed';
			return $result;
		}
		foreach ( array_keys( $raised ) as $item_id ) {
			if ( 'rejected' === $rows[ $item_id ]->status ) {
				unset( $raised[ $item_id ] ); // This line's commission was rejected on its own.
			}
		}
		if ( ! $raised && ! $added ) {
			$result['reason'] = 'rejected';
			return $result;
		}
		$aff = AffiliateRepository::find( $cur_aff );
		if ( ! $aff ) {
			$result['reason'] = 'partner';
			return $result;
		}
		// Hold period of the order: the latest hold of its commissions.
		$hold = '';
		foreach ( $open as $c ) {
			$hold = max( $hold, (string) $c->hold_until );
		}
		$sale = array(
			'contract' => isset( $args['contract'] ) ? (string) $args['contract'] : WPAC_CONTRACT_VERSION,
			'source'   => (string) $args['source'],
			'order_id' => (string) $args['order_id'],
			'currency' => (string) $args['currency'],
			'items'    => array_values( $args['items'] ),
		);
		$new_lines = array();
		if ( $added && $sale['currency'] === (string) $open[0]->currency ) {
			list( $rules, $default, $precedence ) = self::rules_for( $aff, $sale['currency'], (string) $attr->method );
			foreach ( $added as $item_id => $item ) {
				$line = self::line_commission( $item, $sale, $aff, $rules, $default, $precedence );
				if ( $line ) {
					$line['item']          = $item;
					$line['default']       = $default;
					$new_lines[ $item_id ] = $line;
				}
			}
		}
		$round  = (int) get_option( 'wpac_reassign_' . md5( $args['source'] . ':' . $args['order_id'] ), 0 );
		$reason = isset( $args['reason'] ) ? (string) $args['reason'] : '';

		$booked = Db::transaction(
			static function () use ( $wpdb, $args, $raised, $rows, $new_lines, $aff, $attr, $hold, $round, $reason ) {
				$t      = Db::table( 'commissions' );
				$booked = array();
				foreach ( $raised as $item_id => $item ) {
					// Lock the original commission so parallel saves/refunds are serialized.
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$parent = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d FOR UPDATE", $rows[ $item_id ]->id ) );
					Db::check( 'Locking commission ' . (int) $rows[ $item_id ]->id ); // R13-K1: an error is no "nothing to do".
					if ( ! $parent || ! in_array( $parent->status, array( 'pending', 'approved', 'paid' ), true ) ) {
						continue;
					}
					$inc = self::increase_of( $parent, $item, true );
					if ( ! $inc ) {
						continue;
					}
					$key = self::key( 'increase:' . (int) $parent->id . ':' . $inc['booked_after'] );
					if ( self::find_by_key( $key ) ) {
						continue; // Idempotent (also when an increase was rejected by hand).
					}
					// Released by approve_due() once the hold period of the order has passed (at once
					// when it already has; a paid-out commission: with the next payout).
					$status = 'pending';
					$ok     = $wpdb->insert(
						$t,
						array(
							'affiliate_id'    => (int) $parent->affiliate_id,
							'source'          => $parent->source,
							'source_order_id' => $parent->source_order_id,
							'source_item_id'  => $parent->source_item_id,
							'kind'            => 'increase',
							'parent_id'       => (int) $parent->id,
							'product_ref'     => $parent->product_ref,
							'description'     => Db::cut( $reason, 255 ),
							'currency'        => $parent->currency,
							'base_minor'      => $inc['base'],
							'amount_minor'    => $inc['amount'],
							'rate_type'       => $parent->rate_type,
							'rate'            => $parent->rate,
							'rule_id'         => (int) $parent->rule_id,
							'rule_snapshot'   => wp_json_encode(
								array(
									'increase_of'   => (int) $parent->id,
									'line_net'      => $inc['line_net'],
									'quantity'      => $inc['quantity'],
									'calculated_at' => Db::now(),
								)
							),
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
						throw new IntegrationException( 'db_increase', esc_html( $wpdb->last_error ) );
					}
					$id = (int) $wpdb->insert_id;
					self::event( $id, 'created', '', $status, $inc['amount'], 'increase: ' . $reason, 'increase-' . $inc['booked_after'] );
					self::event( (int) $parent->id, 'adjusted', $parent->status, $parent->status, $inc['amount'], 'increase', 'increase-' . $inc['booked_after'] );
					$booked[ $id ] = $inc['amount'];
				}
				foreach ( $new_lines as $item_id => $line ) {
					$key = self::key( 'sale:' . $args['source'] . ':' . $args['order_id'] . ':' . $item_id . ( $round ? ':r' . $round : '' ) );
					if ( self::find_by_key( $key ) ) {
						continue;
					}
					$calc   = $line['calc'];
					$item   = $line['item'];
					$status = 'pending'; // Released by approve_due() like the other lines of the order.
					$ok     = $wpdb->insert(
						$t,
						array(
							'affiliate_id'    => (int) $aff->id,
							'source'          => (string) $args['source'],
							'source_order_id' => (string) $args['order_id'],
							'source_item_id'  => (string) $item_id,
							'kind'            => 'sale',
							'parent_id'       => 0,
							'product_ref'     => Db::cut( (string) ( ! empty( $item['sku'] ) ? $item['sku'] : ( isset( $item['product_id'] ) ? $item['product_id'] : '' ) ), 190 ),
							'description'     => Db::cut( (string) ( isset( $item['name'] ) ? $item['name'] : '' ), 255 ),
							'currency'        => (string) $args['currency'],
							'base_minor'      => $calc['base_minor'],
							'amount_minor'    => $calc['amount_minor'],
							'rate_type'       => $calc['rate_type'],
							'rate'            => $calc['rate'],
							'rule_id'         => $calc['rule_id'],
							'rule_snapshot'   => wp_json_encode(
								array(
									'rule'          => $calc['rule'],
									'default'       => $calc['rule'] ? null : $line['default'],
									'touch_model'   => $attr->touch_model,
									'method'        => $attr->method,
									'contract'      => isset( $args['contract'] ) ? (string) $args['contract'] : WPAC_CONTRACT_VERSION,
									'calculated_at' => Db::now(),
									'line_net'      => $line['line_net'],
									'quantity'      => isset( $item['quantity'] ) ? (int) $item['quantity'] : 1,
									'added_later'   => true,
								)
							),
							'status'          => $status,
							'hold_until'      => $hold,
							'idempotency_key' => $key,
							'created_at'      => Db::now(),
							'updated_at'      => Db::now(),
						)
					);
					if ( ! $ok ) {
						if ( self::find_by_key( $key ) ) {
							continue;
						}
						throw new IntegrationException( 'db_commission', esc_html( $wpdb->last_error ) );
					}
					$id = (int) $wpdb->insert_id;
					self::event( $id, 'created', '', $status, (int) $calc['amount_minor'], 'increase: ' . $reason, 'increase-new-' . $item_id );
					$booked[ $id ] = (int) $calc['amount_minor'];
				}
				return $booked;
			}
		);
		$result['status']         = $booked ? 'booked' : 'unchanged';
		$result['reason']         = '';
		$result['commission_ids'] = array_keys( $booked );
		$result['amount_minor']   = array_sum( $booked );
		if ( $booked ) {
			Audit::log(
				'commission.increased',
				'order',
				0,
				array(
					'source'      => (string) $args['source'],
					'order'       => (string) $args['order_id'],
					'commissions' => array_keys( $booked ),
					'amount'      => array_sum( $booked ),
				)
			);
			Db::after_commit(
				static function () use ( $booked ) {
					foreach ( array_keys( $booked ) as $cid ) {
						do_action( 'wpac_commission_created', $cid );
					}
				}
			);
		}
		return $result;
	}

	/**
	 * Increase of a line over what is booked for it (original commission + earlier increases −
	 * refunds and lowered corrections), at the rate of the original commission.
	 *
	 * @param object $parent Original sale commission.
	 * @param array  $item   Normalized item with the current net of the line.
	 * @param bool   $lock   Locking reads (inside the transaction).
	 * @return array|null [ base, amount, line_net, quantity, booked_after ], null without increase.
	 */
	private static function increase_of( $parent, array $item, $lock ) {
		$base     = (int) $parent->base_minor;
		$snap     = $parent->rule_snapshot ? json_decode( (string) $parent->rule_snapshot, true ) : null;
		$line_net = is_array( $snap ) && ! empty( $snap['line_net'] ) ? (int) $snap['line_net'] : $base;
		if ( $base <= 0 || $line_net <= 0 ) {
			return null;
		}
		$inc       = self::increased( (int) $parent->id, $lock );
		$remaining = $base + $inc['base'] - self::refunded_base( (int) $parent->id, $lock );
		// The current line net scaled like the original (wpac_commission_base filter).
		$target = Money::prorate( max( 0, (int) $item['net_minor'] ), $base, $line_net );
		$delta  = $target - $remaining;
		if ( $delta <= 1 ) {
			return null; // Rounding tolerance, as for lowered lines.
		}
		$qty = null;
		if ( is_array( $snap ) && isset( $snap['quantity'] ) ) {
			$qty = max( 0, ( isset( $item['quantity'] ) ? (int) $item['quantity'] : 1 ) - (int) $snap['quantity'] - $inc['quantity'] );
		}
		if ( 'fixed' === $parent->rate_type ) {
			// Fixed rate per piece: only added pieces earn more. Rows booked before the quantity
			// was stored: in proportion to the original commission.
			$amount = null === $qty ? Money::prorate( (int) $parent->amount_minor, $delta, $base ) : min( (int) $parent->rate * $qty, $delta );
		} else {
			$amount = Money::percent_of( $delta, (string) $parent->rate );
		}
		$rule = is_array( $snap ) && ! empty( $snap['rule'] ) ? $snap['rule'] : null;
		if ( is_array( $rule ) && ! empty( $rule['max_amount_minor'] ) ) {
			// Cap of the original rule per line (SET-AC-22): original + increases stay below it.
			$amount = min( (int) $amount, max( 0, (int) $rule['max_amount_minor'] - (int) $parent->amount_minor - (int) $inc['amount'] ) );
		}
		$amount = Calculator::filter_amount(
			max( 0, (int) $amount ),
			array_merge(
				$item,
				array(
					'net_minor' => $delta,
					'quantity'  => null === $qty ? 0 : $qty,
				)
			),
			(int) $parent->affiliate_id,
			$rule,
			$delta
		);
		if ( $amount <= 0 ) {
			return null;
		}
		return array(
			'base'         => $delta,
			'amount'       => $amount,
			'line_net'     => Money::prorate( $delta, $line_net, $base ),
			'quantity'     => null === $qty ? 0 : $qty,
			// Base booked for the line in total after this increase (refunds not deducted, nor the
			// part of an open increase a refund took back, R13-K4): grows with every increase, so
			// the same increase is never booked twice.
			'booked_after' => $base + $inc['booked'] + $delta,
		);
	}

	/**
	 * Base, commission and quantity added to a commission by non-rejected increase rows.
	 *
	 * @param int  $id   Original commission.
	 * @param bool $lock Locking read (inside a transaction).
	 * @return array [ base, amount, quantity, booked ] – booked: base as booked originally (before
	 *               a refund reduced an open increase, R13-K4).
	 */
	public static function increased( $id, $lock = false ) {
		global $wpdb;
		$t   = Db::table( 'commissions' );
		$for = $lock ? ' FOR UPDATE' : ''; // Constant fragment, no input.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT base_minor, amount_minor, rule_snapshot FROM {$t} WHERE parent_id = %d AND kind = 'increase' AND status <> 'rejected'{$for}", $id ) );
		$sum  = array(
			'base'     => 0,
			'amount'   => 0,
			'quantity' => 0,
			'booked'   => 0,
		);
		foreach ( $rows as $r ) {
			$snap             = $r->rule_snapshot ? json_decode( (string) $r->rule_snapshot, true ) : null;
			$sum['base']     += (int) $r->base_minor;
			$sum['amount']   += (int) $r->amount_minor;
			$sum['quantity'] += is_array( $snap ) && isset( $snap['quantity'] ) ? (int) $snap['quantity'] : 0;
			$sum['booked']   += is_array( $snap ) && isset( $snap['booked_base'] ) ? (int) $snap['booked_base'] : (int) $r->base_minor;
		}
		return $sum;
	}

	/**
	 * Reject the increases of a commission that are not released yet (pending/review, in no
	 * payout), inside the caller's transaction (full refund or cancellation of the order).
	 *
	 * @param int    $id     Original commission (locked by the caller).
	 * @param string $reason Reason.
	 * @return int[] Rejected increase IDs.
	 */
	private static function reject_open_increases( $id, $reason ) {
		global $wpdb;
		$t   = Db::table( 'commissions' );
		$ids = array();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$open = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, status FROM {$t} WHERE parent_id = %d AND kind = 'increase' AND status IN ('pending','review') AND payout_id = 0 FOR UPDATE", (int) $id ) );
		Db::check( 'Locking the open increases of commission ' . (int) $id ); // R13-K1.
		foreach ( $open as $inc ) {
			// The row is locked, so the status read above is the status before the transition.
			if ( self::transition( (int) $inc->id, 'rejected', Db::cut( $reason, 191 ), 0, 0, (string) $inc->status, false ) ) {
				$ids[ (int) $inc->id ] = array( (string) $inc->status, self::last_event_id( (int) $inc->id ) );
			}
		}
		if ( $ids ) {
			Db::after_commit(
				static function () use ( $ids ) {
					foreach ( $ids as $inc_id => $info ) {
						do_action( 'wpac_commission_rejected', (int) $inc_id, $info[0], $info[1] );
					}
				}
			);
		}
		return array_keys( $ids );
	}

	/**
	 * Marker reason of the parent event when a refund took back an open increase (R13-K4).
	 */
	const REDUCED_REASON = 'open increase reduced';

	/**
	 * Commission taken back from open increases of a commission by a refund (R13-K4): the
	 * idempotency of that path (no refund row may be booked at all) and what a deleted refund
	 * has to give back.
	 *
	 * @param int    $id        Original commission (locked by the caller).
	 * @param string $refund_id Refund ID.
	 * @return int Commission taken back (positive), 0 when none.
	 */
	public static function reduced_by( $id, $refund_id ) {
		global $wpdb;
		$e = Db::table( 'commission_events' );
		// Locking read: sees a parallel run that committed after this transaction began.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_col( $wpdb->prepare( "SELECT amount_minor FROM {$e} WHERE commission_id = %d AND source_event_id = %s AND event = 'adjusted' AND reason LIKE %s FOR UPDATE", (int) $id, Db::cut( (string) $refund_id, 191 ), '%' . $wpdb->esc_like( self::REDUCED_REASON ) ) );
		return $rows ? max( 1, -(int) array_sum( $rows ) ) : 0;
	}

	/**
	 * R13-K4: a partial refund takes back increases of the line that are not released yet
	 * (pending/review, in no payout), newest first, inside the caller's transaction. A fully
	 * absorbed increase is rejected, a partly absorbed one is lowered in proportion (its booked
	 * base stays in the snapshot, so the same increase is never booked twice).
	 *
	 * @param object $parent       Original commission (locked by the caller).
	 * @param int    $refunded_net Refunded base of the line (scaled like the commission base).
	 * @param array  $refund       Normalized refund.
	 * @return int Base taken back from open increases.
	 * @throws IntegrationException On DB errors.
	 */
	private static function reduce_open_increases( $parent, $refunded_net, array $refund ) {
		global $wpdb;
		$t = Db::table( 'commissions' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$open   = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE parent_id = %d AND kind = 'increase' AND status IN ('pending','review') AND payout_id = 0 AND base_minor > 0 ORDER BY id DESC FOR UPDATE", (int) $parent->id ) );
		$left   = (int) $refunded_net;
		$base   = 0;
		$amount = 0;
		$reason = $refund['type'] . ': ' . $refund['reason'];
		foreach ( $open as $inc ) {
			if ( $left <= 0 ) {
				break;
			}
			$take = min( $left, (int) $inc->base_minor );
			if ( $take >= (int) $inc->base_minor ) {
				if ( ! self::transition( (int) $inc->id, 'rejected', Db::cut( $reason, 191 ), 0, 0, (string) $inc->status, false ) ) {
					continue;
				}
				$cut = (int) $inc->amount_minor;
				$id  = (int) $inc->id;
				$old = (string) $inc->status;
				$evt = self::last_event_id( $id );
				Db::after_commit(
					static function () use ( $id, $old, $evt ) {
						do_action( 'wpac_commission_rejected', $id, $old, $evt );
					}
				);
			} else {
				$cut  = Money::prorate( (int) $inc->amount_minor, $take, (int) $inc->base_minor );
				$snap = $inc->rule_snapshot ? json_decode( (string) $inc->rule_snapshot, true ) : array();
				$snap = is_array( $snap ) ? $snap : array();
				if ( ! isset( $snap['booked_base'] ) ) {
					$snap['booked_base'] = (int) $inc->base_minor;
				}
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$ok = $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET base_minor = base_minor - %d, amount_minor = amount_minor - %d, rule_snapshot = %s, updated_at = %s WHERE id = %d AND status IN ('pending','review') AND payout_id = 0", $take, $cut, wp_json_encode( $snap ), Db::now(), (int) $inc->id ) );
				if ( 1 !== (int) $ok ) {
					throw new IntegrationException( 'db_increase', esc_html( 'Could not lower the open increase: ' . $wpdb->last_error ) );
				}
				self::event( (int) $inc->id, 'adjusted', (string) $inc->status, (string) $inc->status, -$cut, $reason, (string) $refund['refund_id'] );
			}
			$left   -= $take;
			$base   += $take;
			$amount += $cut;
		}
		if ( $base > 0 ) {
			// Marker on the original commission (idempotency, deleted refunds).
			self::event( (int) $parent->id, 'adjusted', (string) $parent->status, (string) $parent->status, -$amount, $refund['type'] . ': ' . self::REDUCED_REASON, (string) $refund['refund_id'] );
		}
		return $base;
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
		// After a reassignment the order has sale rows of several partners (and rounds) for the
		// same item. Refunds belong to the partner the order is attributed to now, newest round
		// first; the reversed rows of earlier partners are only used when no current row exists.
		$current = self::attribution_for( $refund['source'], $refund['order_id'] );
		$cur_aff = $current ? (int) $current->affiliate_id : 0;
		usort(
			$originals,
			static function ( $a, $b ) {
				return (int) $a->id - (int) $b->id;
			}
		);
		$by_item = array();
		foreach ( $originals as $c ) {
			$k = (string) $c->source_item_id;
			if ( ! isset( $by_item[ $k ] ) || (int) $c->affiliate_id === $cur_aff || (int) $by_item[ $k ]->affiliate_id !== $cur_aff ) {
				$by_item[ $k ] = $c;
			}
		}

		$lines = array();
		if ( $refund['full'] ) {
			foreach ( $by_item as $item_id => $c ) {
				$lines[ $item_id ] = 0;
			}
		} else {
			$unknown = array();
			foreach ( $refund['items'] as $line ) {
				if ( isset( $by_item[ $line['item_id'] ] ) ) {
					// Several refund lines for the same item add up.
					$lines[ $line['item_id'] ] = ( isset( $lines[ $line['item_id'] ] ) ? $lines[ $line['item_id'] ] : 0 ) + (int) $line['net_minor'];
				} else {
					$unknown[] = (string) $line['item_id'];
				}
			}
			if ( $unknown ) {
				// Not booked for this order (wrong item_id, or a line without commission, e.g. an
				// excluded product): say so instead of a silent "unchanged".
				Audit::log(
					'refund.unknown_items',
					'order',
					0,
					array(
						'source'    => $refund['source'],
						'order_id'  => $refund['order_id'],
						'refund_id' => $refund['refund_id'],
						'items'     => array_slice( array_unique( $unknown ), 0, 50 ),
					)
				);
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
					Db::check( 'Locking commission ' . (int) $by_item[ $item_id ]->id ); // R13-K1: never skip a line on a DB error.
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
					if ( $refund['full'] ) {
						// R12-M1: increases still waiting for release (pending/review) are rejected
						// together with the full reversal – otherwise an approved reversal would take
						// back money that was never approved, and rejecting the increase later would
						// leave the account too low.
						self::reject_open_increases( (int) $parent->id, $refund['type'] . ': ' . $refund['reason'] );
					} elseif ( $refunded_net > 0 ) {
						// R13-K4: a partial refund first takes back increases still waiting for release
						// (newest first); only the rest is deducted from the approved/paid commission –
						// never more than was approved, and rejecting the increase later leaves the
						// line right.
						if ( self::reduced_by( (int) $parent->id, (string) $refund['refund_id'] ) ) {
							continue; // Idempotent: this refund was absorbed by open increases before.
						}
						$refunded_net -= self::reduce_open_increases( $parent, (int) $refunded_net, $refund );
						if ( $refunded_net <= 0 ) {
							continue;
						}
					}
					// Increases booked after the sale (record_increase()) belong to the commission
					// being corrected: base and commission are the original plus the increases.
					$inc      = self::increased( (int) $parent->id, true );
					$eff_base = (int) $parent->base_minor + $inc['base'];
					// R12-N3: manual corrections of the partner linked to this commission (penalties,
					// bonuses) stay untouched – they are neither netted as earlier refunds nor reversed.
					$adjusted = self::adjusted_sum( (int) $parent->id, true, true ) - $inc['amount'];
					$refunded = self::refunded_base( (int) $parent->id, true );
					$amount   = Calculator::refund_adjustment( (int) $parent->amount_minor + $inc['amount'], $eff_base, $adjusted, $refunded_net, $refund['full'], $refunded );
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
							'description'     => Db::cut( $refund['reason'], 255 ),
							'currency'        => $parent->currency,
							'base_minor'      => -( $refund['full'] ? max( 0, $eff_base - $refunded ) : min( abs( (int) $refunded_net ), max( 0, $eff_base - $refunded ) ) ),
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
						throw new IntegrationException( 'db_adjustment', esc_html( $wpdb->last_error ) );
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
		if ( ! empty( $unknown ) ) {
			$result['unknown_items'] = array_values( array_unique( $unknown ) );
			if ( ! $result['adjustment_ids'] && ! $lines ) {
				$result['status'] = 'unknown_items';
			}
		}
		if ( $result['adjustment_ids'] ) {
			// Inside an outer transaction (reassign()) only once it is committed.
			Db::after_commit(
				static function () use ( $originals, $refund, $result ) {
					// A correction of a commission that sits in a payout not sent to the bank yet: the
					// payout would pay too much now and offset it only later (clawback). Flag it so the
					// operator can rebuild the payout before exporting it.
					$warned = array();
					foreach ( $originals as $o ) {
						if ( (int) $o->payout_id ) {
							\WPAffiliateCaptain\Payouts\PayoutService::flag_clawback( (int) $o->payout_id, $refund['source'] . ' #' . $refund['order_id'] );
							if ( ! isset( $warned[ (int) $o->payout_id ] ) ) {
								$warned[ (int) $o->payout_id ] = true;
								self::warn_after_correction( (int) $o->payout_id, $refund );
							}
						}
					}
					self::maybe_forget_links( $refund['source'], $refund['order_id'] );
					do_action( 'wpac_commission_adjusted', $refund, $result['adjustment_ids'] ); // After COMMIT.
				}
			);
		}
		return $result;
	}

	/**
	 * A refund hit a commission whose credit note already has a correction credit note (R12-M2):
	 * correction credit notes are not linked to commissions, so the correction may already have
	 * reduced the amount the refund now deducts again. The booking stays as it is; the operator
	 * is warned (audit entry shown with the corrections of the payout, hook for an order note).
	 *
	 * @param int   $payout_id Payout of the refunded commission.
	 * @param array $refund    Normalized refund.
	 */
	private static function warn_after_correction( $payout_id, array $refund ) {
		$list = \WPAffiliateCaptain\Payouts\Corrections::for_payout( (int) $payout_id );
		if ( ! $list ) {
			return;
		}
		$p       = \WPAffiliateCaptain\Payouts\PayoutService::find( (int) $payout_id );
		$numbers = array_map( 'strval', wp_list_pluck( $list, 'number' ) );
		Audit::log(
			'payout.refund_after_correction',
			'payout',
			(int) $payout_id,
			array(
				'source'      => (string) $refund['source'],
				'order'       => (string) $refund['order_id'],
				'refund_id'   => (string) $refund['refund_id'],
				'corrections' => $numbers,
			)
		);
		/**
		 * A refund of an order was deducted although the credit note of its commission already has
		 * a correction credit note (which may cover the refund already). The WooCommerce
		 * integration adds an order note.
		 *
		 * @param array  $refund      Normalized refund.
		 * @param string $credit_note Credit note number.
		 * @param array  $corrections Correction credit note numbers.
		 */
		do_action( 'wpac_refund_after_correction', $refund, $p ? (string) $p->number : '', $numbers );
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
			return new \WP_Error( 'wpac_reason_required', __( 'Please enter a reason.', 'affiliate-captain' ) );
		}
		if ( ! $attr ) {
			return new \WP_Error( 'wpac_state', __( 'This order is not attributed yet; assign it instead.', 'affiliate-captain' ) );
		}
		if ( ! $to || 'active' !== $to->status || \WPAffiliateCaptain\Privacy\Restriction::is_restricted( (int) $to->id ) ) {
			return new \WP_Error( 'wpac_state', __( 'The new partner must be active.', 'affiliate-captain' ) );
		}
		if ( (int) $attr->affiliate_id === (int) $to->id ) {
			return new \WP_Error( 'wpac_state', __( 'The order is already attributed to this partner.', 'affiliate-captain' ) );
		}
		$from       = (int) $attr->affiliate_id;
		$option     = 'wpac_reassign_' . md5( $sale['source'] . ':' . $sale['order_id'] );
		$round      = 1 + (int) get_option( $option, 0 );
		$label      = sprintf( /* translators: %s: reason */ __( 'Reassigned to another partner: %s', 'affiliate-captain' ), $reason );
		$new_sale   = $sale;
		$new_sale['affiliate_id']       = (int) $to->id;
		$new_sale['attribution_method'] = 'manual';
		$new_sale['partner_code']       = '';
		$new_sale['touch']              = null;
		$new_sale['coupon_codes']       = array();
		$new_sale['reassign_round']     = $round;

		// Check first whether the new partner can be booked at all (self-referral, restriction,
		// filters): the current partner must not lose the commission when the new booking fails.
		$check = AttributionResolver::resolve( $new_sale );
		if ( ! $check['attribution'] || (int) $check['attribution']['affiliate_id'] !== (int) $to->id ) {
			return new \WP_Error(
				'wpac_reassign_failed',
				'self_referral' === $check['reason'] || AttributionResolver::is_self_referral( (int) $to->id, $new_sale )
					? __( 'The order cannot be attributed to this partner: the partner is the buyer (self-referral).', 'affiliate-captain' )
					: __( 'The order cannot be attributed to this partner. Nothing was changed.', 'affiliate-captain' )
			);
		}

		// Reversal and new booking are one transaction: if the new booking fails, the reversal is
		// rolled back as well and the order stays with the current partner.
		try {
			$result = Db::transaction(
				static function () use ( $sale, $new_sale, $label, $round, $option ) {
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
					CustomerLinks::forget_order( $sale['source'], (string) $sale['order_id'] );
					$booked = self::record_sale( $new_sale );
					if ( ! is_array( $booked ) || 'created' !== $booked['status'] ) {
						throw new IntegrationException( 'reassign_failed', esc_html( 'New booking not created: ' . ( is_array( $booked ) ? $booked['status'] . ' ' . $booked['reason'] : '' ) ) );
					}
					update_option( $option, $round, false );
					return $booked;
				}
			);
		} catch ( \Throwable $e ) {
			$code = $e instanceof IntegrationException ? $e->error_code : 'exception';
			// The option may have been written inside the rolled back transaction: drop the cached value.
			wp_cache_delete( $option, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			\WPAffiliateCaptain\Core\Log::error(
				'Reassignment failed',
				array(
					'source'  => $sale['source'],
					'order'   => (string) $sale['order_id'],
					'message' => Db::cut( $e->getMessage(), 300 ),
				)
			);
			if ( 'reassign_failed' === $code ) {
				// Not a database problem: the new booking was refused (e.g. changed by a filter or no line earns commission).
				return new \WP_Error( 'wpac_reassign_failed', __( 'The order could not be booked for the new partner (the booking was refused, e.g. by a filter or because no line earns commission). Nothing was changed.', 'affiliate-captain' ) );
			}
			return new \WP_Error( 'wpac_reassign_failed', __( 'The order could not be attributed to the new partner (database error). Nothing was changed; please try again.', 'affiliate-captain' ) );
		}
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
	 * Reinstated are full status reversals of the WooCommerce adapter (refund ID "status-…") and
	 * cancellations/chargebacks reported by any source (contract: wpac_record_reinstatement());
	 * never refunds (the money really went back) and never the reversal of a reassignment.
	 *
	 * @param string $source    Source.
	 * @param string $order_id  Order.
	 * @param string $reason    Reason (shown in the history).
	 * @param string $refund_id Only this reversal ('' = all reinstatable reversals of the order).
	 * @return int Number of reversals reinstated.
	 */
	public static function reinstate( $source, $order_id, $reason, $refund_id = '' ) {
		global $wpdb;
		$t = Db::table( 'commissions' );
		// Full reversals of this order, not rejected.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$children = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE source = %s AND source_order_id = %s AND parent_id > 0 AND kind IN ('refund','cancellation','chargeback') AND status <> 'rejected' AND amount_minor < 0", $source, (string) $order_id ) );
		$n        = 0;
		foreach ( $children as $child ) {
			$event_id = '';
			foreach ( self::events( (int) $child->id ) as $e ) {
				if ( 'created' === $e->event ) {
					$event_id = (string) $e->source_event_id;
					break;
				}
			}
			if ( '' !== (string) $refund_id && (string) $refund_id !== $event_id ) {
				continue;
			}
			$reinstatable = 0 === strpos( $event_id, 'status-' ) || ( in_array( $child->kind, array( 'cancellation', 'chargeback' ), true ) && 0 !== strpos( $event_id, 'reassign-' ) );
			if ( ! $reinstatable ) {
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
							'description'     => Db::cut( (string) $reason, 255 ),
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
						throw new IntegrationException( 'db_reinstatement', esc_html( $wpdb->last_error ) );
					}
					$id = (int) $wpdb->insert_id;
					Balance::post( (int) $child->affiliate_id, $child->currency, 'approved', $amount, $id, 0, 'reinstatement' );
					self::event( $id, 'created', '', 'approved', $amount, 'reinstatement: ' . $reason, 'reinstate-' . (int) $child->id );
					self::event( (int) $parent->id, 'adjusted', $parent->status, $parent->status, $amount, 'reinstatement', 'reinstate-' . (int) $child->id );
					// After COMMIT (dropped on rollback), like the other ledger bookings.
					Db::after_commit(
						static function () use ( $id ) {
							do_action( 'wpac_commission_created', $id );
						}
					);
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
	 * A refund was deleted in the shop (R13-K3/K5): give back exactly what was booked for it,
	 * from the stored refund rows – whatever the rate type (a fixed rate per piece included: a
	 * refund does not change the quantity of the line). Refund rows not released yet
	 * (pending/review, in no payout) are rejected; approved or paid-out ones get a
	 * "reinstatement" booking, approved at once like the refund was (no hold period, reported with
	 * the corrections, never rejected by a later full reversal). Idempotent per refund row.
	 *
	 * @param string $source    Source.
	 * @param string $order_id  Order.
	 * @param string $refund_id Deleted refund.
	 * @param string $reason    Reason (shown in the history).
	 * @return array { status: booked|already|none|skipped, amount_minor (given back), commission_ids,
	 *               reduced (the refund also took back open increases, R13-K4) }
	 * @throws IntegrationException On DB errors.
	 */
	public static function reverse_refund( $source, $order_id, $refund_id, $reason ) {
		global $wpdb;
		$t = Db::table( 'commissions' );
		$e = Db::table( 'commission_events' );
		$result = array(
			'status'         => 'none',
			'amount_minor'   => 0,
			'commission_ids' => array(),
			'reduced'        => false,
		);
		// Rows booked for this refund: its ID is the source event of their "created" event.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT c.* FROM {$t} c INNER JOIN {$e} ev ON ev.commission_id = c.id AND ev.event = 'created' AND ev.source_event_id = %s WHERE c.source = %s AND c.source_order_id = %s AND c.parent_id > 0 AND c.kind = 'refund' ORDER BY c.id ASC", Db::cut( (string) $refund_id, 191 ), (string) $source, (string) $order_id ) );
		foreach ( self::sale_commissions( $source, $order_id ) as $sale ) {
			if ( self::reduced_by( (int) $sale->id, (string) $refund_id ) ) {
				$result['reduced'] = true;
			}
		}
		$given    = 0;
		$skipped  = 0;
		$already  = 0;
		$rejected      = array();
		$rejected_from = array();
		foreach ( $rows as $row ) {
			$rkey = self::key( 'reinstate:' . (int) $row->id );
			if ( 'rejected' === $row->status || self::find_by_key( $rkey ) ) {
				++$already;
				continue;
			}
			$parent = self::find( (int) $row->parent_id );
			if ( ! $parent || 'rejected' === $parent->status || self::fully_reversed( (int) $parent->id ) ) {
				++$skipped; // Rejected, or reversed in full after the refund: nothing is given back.
				continue;
			}
			if ( ! (int) $row->payout_id && in_array( $row->status, array( 'pending', 'review' ), true ) ) {
				// Not released yet: the refund row itself goes (no balance effect).
				if ( self::transition( (int) $row->id, 'rejected', Db::cut( (string) $reason, 191 ), get_current_user_id(), 0, (string) $row->status, false ) ) {
					$given                     -= (int) $row->amount_minor;
					$result['commission_ids'][] = (int) $row->id;
					$rejected[]                 = (int) $row->id;
					$rejected_from[ (int) $row->id ] = array( (string) $row->status, self::last_event_id( (int) $row->id ) );
				} else {
					++$already;
				}
				continue;
			}
			$amount = -(int) $row->amount_minor;
			$id     = Db::transaction(
				static function () use ( $wpdb, $t, $row, $parent, $amount, $rkey, $reason, $refund_id ) {
					// Lock the original commission, as refunds do (serialised with them).
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$t} WHERE id = %d FOR UPDATE", (int) $parent->id ) );
					if ( self::find_by_key( $rkey ) ) {
						return 0;
					}
					$ok = $wpdb->insert(
						$t,
						array(
							'affiliate_id'    => (int) $row->affiliate_id,
							'source'          => $row->source,
							'source_order_id' => $row->source_order_id,
							'source_item_id'  => $row->source_item_id,
							'kind'            => 'reinstatement',
							'parent_id'       => (int) $parent->id,
							'product_ref'     => $row->product_ref,
							'description'     => Db::cut( (string) $reason, 255 ),
							'currency'        => $row->currency,
							'base_minor'      => -(int) $row->base_minor,
							'amount_minor'    => $amount,
							'rate_type'       => $row->rate_type,
							'rate'            => $row->rate,
							'rule_id'         => (int) $row->rule_id,
							'rule_snapshot'   => wp_json_encode(
								array(
									'refund_deleted' => (string) $refund_id,
									'reverses'       => (int) $row->id,
								)
							),
							'status'          => 'approved',
							'hold_until'      => Db::now(),
							'idempotency_key' => $rkey,
							'created_at'      => Db::now(),
							'updated_at'      => Db::now(),
						)
					);
					if ( ! $ok ) {
						if ( self::find_by_key( $rkey ) ) {
							return 0;
						}
						throw new IntegrationException( 'db_reinstatement', esc_html( $wpdb->last_error ) );
					}
					$id = (int) $wpdb->insert_id;
					Balance::post( (int) $row->affiliate_id, $row->currency, 'approved', $amount, $id, 0, 'reinstatement' );
					self::event( $id, 'created', '', 'approved', $amount, 'reinstatement: ' . $reason, 'refund-deleted-' . $refund_id );
					self::event( (int) $parent->id, 'adjusted', $parent->status, $parent->status, $amount, 'reinstatement', 'refund-deleted-' . $refund_id );
					Db::after_commit(
						static function () use ( $id ) {
							do_action( 'wpac_commission_created', $id );
						}
					);
					return $id;
				}
			);
			if ( $id ) {
				$given                     += $amount;
				$result['commission_ids'][] = (int) $id;
			} else {
				++$already;
			}
		}
		if ( $result['commission_ids'] ) {
			$result['status']       = 'booked';
			$result['amount_minor'] = $given;
			Audit::log(
				'commission.refund_deleted',
				'order',
				ctype_digit( (string) $order_id ) ? (int) $order_id : 0, // Numeric order numbers are found by ID.

				array(
					'source'      => (string) $source,
					'order'       => (string) $order_id,
					'refund'      => (string) $refund_id,
					'commissions' => $result['commission_ids'],
					'amount'      => $given,
				)
			);
			if ( $rejected ) {
				// Refund rows taken back (reinstatements fire wpac_commission_created instead).
				$refund = array(
					'source'    => (string) $source,
					'order_id'  => (string) $order_id,
					'refund_id' => (string) $refund_id,
					'type'      => 'refund',
					'reason'    => (string) $reason,
					'deleted'   => true,
				);
				Db::after_commit(
					static function () use ( $refund, $rejected, $rejected_from ) {
						// Status change of each refund row, as everywhere else (contract 1.7.0; P2-01).
						foreach ( $rejected_from as $rid => $info ) {
							do_action( 'wpac_commission_rejected', (int) $rid, $info[0], $info[1] );
						}
						do_action( 'wpac_commission_adjusted', $refund, $rejected ); // Report cache, review count.
					}
				);
			}
		} elseif ( $skipped ) {
			$result['status'] = 'skipped';
		} elseif ( $already ) {
			$result['status'] = 'already';
		}
		return $result;
	}

	/**
	 * Whether a commission has a full reversal that stands (cancellation, chargeback, status
	 * refund or reassignment, not reinstated): what was left of it is reversed already.
	 *
	 * @param int $id Original commission.
	 * @return bool
	 */
	private static function fully_reversed( $id ) {
		global $wpdb;
		$t = Db::table( 'commissions' );
		$e = Db::table( 'commission_events' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = (array) $wpdb->get_col( $wpdb->prepare( "SELECT c.id FROM {$t} c LEFT JOIN {$e} ev ON ev.commission_id = c.id AND ev.event = 'created' WHERE c.parent_id = %d AND c.status <> 'rejected' AND c.amount_minor < 0 AND ( c.kind IN ('cancellation','chargeback') OR ev.source_event_id LIKE %s )", (int) $id, $wpdb->esc_like( 'status-' ) . '%' ) );
		foreach ( $ids as $cid ) {
			if ( ! self::find_by_key( self::key( 'reinstate:' . (int) $cid ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Allowed status transitions: to => allowed from-statuses.
	 * Paid is final; a rejection can only be reopened for review (reopen()).
	 */
	const TRANSITIONS = array(
		'pending'  => array( 'review' ),
		'review'   => array( 'rejected' ),
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
		if ( 'manual' === Settings::get( 'approval_mode' ) ) {
			return 0; // SET-AC-23: only approved by hand (commissions page, single or bulk).
		}
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
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, source, source_order_id FROM {$t} WHERE status = 'pending' AND hold_until <= %s AND id > %d ORDER BY id ASC LIMIT 500", Db::now(), $last ) );
			if ( ! $rows ) {
				break;
			}
			$held = array();
			foreach ( $rows as $row ) {
				$last  = (int) $row->id;
				$order = $row->source . ':' . $row->source_order_id;
				if ( ! isset( $held[ $order ] ) ) {
					/**
					 * Hold back the release of an order's commissions for now (e.g. the shop order is
					 * on hold or in a payment dispute). Checked again in every run.
					 *
					 * @param bool   $hold     Whether to wait.
					 * @param string $source   Sales source.
					 * @param string $order_id Source order ID.
					 */
					$held[ $order ] = (bool) apply_filters( 'wpac_hold_release', false, (string) $row->source, (string) $row->source_order_id );
				}
				if ( $held[ $order ] ) {
					continue;
				}
				if ( self::transition( (int) $row->id, 'approved', __( 'Hold period ended', 'affiliate-captain' ), 0 ) ) {
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
			return new \WP_Error( 'wpac_state', __( 'Only pending commissions or commissions under review can be approved.', 'affiliate-captain' ) );
		}
		$to = 'review' === $c->status && strtotime( $c->hold_until . ' UTC' ) > time() ? 'pending' : 'approved';
		if ( 'approved' === $to && (int) $c->parent_id ) {
			$parent = self::find( (int) $c->parent_id );
			if ( $parent && ! in_array( $parent->status, array( 'approved', 'paid' ), true ) ) {
				return new \WP_Error( 'wpac_parent_open', __( 'This correction belongs to a commission that is not approved yet. Approve or reject the original commission first.', 'affiliate-captain' ) );
			}
		}
		if ( ! self::transition( (int) $id, $to, $reason, get_current_user_id(), 0, $c->status ) ) {
			return new \WP_Error( 'wpac_conflict', __( 'The commission was changed in the meantime. Please reload and try again.', 'affiliate-captain' ) );
		}
		foreach ( self::children( (int) $id ) as $child ) {
			if ( $child->status === $c->status ) {
				self::transition( (int) $child->id, $to, $reason, get_current_user_id(), 0, $c->status );
			}
		}
		if ( 'sale' === $c->kind && 'review' === $c->status ) {
			// Review passed: the customer link of the order now counts.
			CustomerLinks::activate_order( $c->source, $c->source_order_id );
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
			return new \WP_Error( 'wpac_state', __( 'Paid or rejected commissions cannot be rejected.', 'affiliate-captain' ) );
		}
		if ( (int) $c->payout_id ) {
			return new \WP_Error( 'wpac_state', __( 'This commission is part of a payout. Cancel the payout first.', 'affiliate-captain' ) );
		}
		if ( 'correction' === $c->kind ) {
			// The amount is fixed by an issued correction credit note: a further change needs another one.
			return new \WP_Error( 'wpac_state', __( 'This booking settles a correction credit note and cannot be rejected. Issue a further correction credit note for the payout instead.', 'affiliate-captain' ) );
		}
		if ( '' === trim( (string) $reason ) ) {
			return new \WP_Error( 'wpac_reason_required', __( 'Please enter a reason.', 'affiliate-captain' ) );
		}
		foreach ( self::children( (int) $id ) as $child ) {
			if ( (int) $child->payout_id && 'rejected' !== $child->status ) {
				// The correction would stay in that payout and be deducted for a rejected commission.
				return new \WP_Error( 'wpac_state', __( 'A correction of this commission is part of a payout. Cancel that payout first.', 'affiliate-captain' ) );
			}
		}
		// Atomic: only if still unassigned to a payout and in the status we saw. A database error
		// (rolled back) is reported, never a fatal error with the commission left as it was.
		try {
			if ( ! self::transition( (int) $id, 'rejected', $reason, get_current_user_id(), 0, $c->status ) ) {
				return new \WP_Error( 'wpac_conflict', __( 'The commission was changed in the meantime (e.g. added to a payout). Please reload and try again.', 'affiliate-captain' ) );
			}
			foreach ( self::children( (int) $id ) as $child ) {
				if ( in_array( $child->status, array( 'pending', 'review', 'approved' ), true ) ) {
					self::transition( (int) $child->id, 'rejected', $reason, get_current_user_id() );
				}
			}
		} catch ( \Throwable $e ) {
			\WPAffiliateCaptain\Core\Log::error(
				'Rejecting a commission failed',
				array(
					'commission' => (int) $id,
					'message'    => Db::cut( $e->getMessage(), 300 ),
				)
			);
			return new \WP_Error( 'wpac_db_error', __( 'The commission could not be rejected (database error). Please try again.', 'affiliate-captain' ) );
		}
		if ( 'sale' === $c->kind ) {
			self::maybe_forget_links( $c->source, $c->source_order_id );
		}
		return true;
	}

	/**
	 * Reopen a rejected commission for review (the rejection turned out to be wrong). Corrections
	 * that were rejected together with it are reopened as well. The decision is made again with
	 * approve() or reject(). Sales and manual bookings only; bonuses and corrections follow
	 * their own rules.
	 *
	 * @param int    $id     Commission.
	 * @param string $reason Reason (required).
	 * @return true|\WP_Error
	 */
	public static function reopen( $id, $reason ) {
		$reason = trim( (string) $reason );
		$c      = self::find( $id );
		if ( '' === $reason ) {
			return new \WP_Error( 'wpac_reason_required', __( 'Please enter a reason.', 'affiliate-captain' ) );
		}
		if ( ! $c || 'rejected' !== $c->status || (int) $c->parent_id || ! in_array( $c->kind, array( 'sale', 'adjustment' ), true ) ) {
			return new \WP_Error( 'wpac_state', __( 'Only rejected sale commissions and manual bookings can be reopened.', 'affiliate-captain' ) );
		}
		// The rejection being undone: the last "rejected" event of the commission.
		$rejected_at = '';
		$rejected_by = '';
		foreach ( self::events( (int) $id ) as $e ) {
			if ( 'rejected' === $e->event ) {
				$rejected_at = (string) $e->created_at;
				$rejected_by = (string) $e->reason;
			}
		}
		try {
			if ( ! self::transition( (int) $id, 'review', $reason, get_current_user_id(), 0, 'rejected' ) ) {
				return new \WP_Error( 'wpac_conflict', __( 'The commission was changed in the meantime. Please reload and try again.', 'affiliate-captain' ) );
			}
			foreach ( self::children( (int) $id ) as $child ) {
				if ( 'rejected' !== $child->status || '' === $rejected_at ) {
					continue;
				}
				// Only corrections rejected together with the commission (same reason, same moment).
				foreach ( array_reverse( self::events( (int) $child->id ) ) as $e ) {
					if ( 'rejected' === $e->event ) {
						if ( (string) $e->reason === $rejected_by && abs( strtotime( $e->created_at . ' UTC' ) - strtotime( $rejected_at . ' UTC' ) ) <= 120 ) {
							self::transition( (int) $child->id, 'review', $reason, get_current_user_id(), 0, 'rejected' );
						}
						break;
					}
				}
			}
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'wpac_db_error', __( 'The commission could not be reopened (database error). Please try again.', 'affiliate-captain' ) );
		}
		Audit::log(
			'commission.reopened',
			'commission',
			(int) $id,
			array( 'reason' => $reason )
		);
		return true;
	}

	/**
	 * Manual booking: a commission, bonus or correction (positive or negative) for a partner,
	 * approved at once and payable with the next payout. With $parent_id it is a correction of
	 * that commission (same order, shown under it). Audited.
	 *
	 * @param int    $affiliate_id Partner.
	 * @param string $currency     Currency.
	 * @param int    $amount_minor Amount (minor units, not 0; negative = deduction).
	 * @param string $reason       Reason (required, shown to the partner).
	 * @param int    $parent_id    Commission this corrects (0 = none).
	 * @param array  $link         Information-only link (wpac_create_adjustment(), since 0.15.0):
	 *                             parent_id (existing commission, any partner), reference (≤ 64
	 *                             characters). Stored as a LINK_EVENT, never in parent_id.
	 * @return int|\WP_Error Commission ID.
	 */
	public static function manual_adjustment( $affiliate_id, $currency, $amount_minor, $reason, $parent_id = 0, array $link = array() ) {
		global $wpdb;
		$reason       = trim( (string) $reason );
		$amount_minor = (int) $amount_minor;
		$currency     = strtoupper( (string) $currency );
		$parent       = $parent_id ? self::find( (int) $parent_id ) : null;
		if ( '' === $reason ) {
			return new \WP_Error( 'wpac_reason_required', __( 'Please enter a reason.', 'affiliate-captain' ) );
		}
		if ( 0 === $amount_minor || abs( $amount_minor ) > \WPAffiliateCaptain\Sales\ContractApi::MAX_MINOR ) {
			return new \WP_Error( 'wpac_amount', __( 'Please enter an amount other than 0.', 'affiliate-captain' ) );
		}
		if ( $parent_id && ( ! $parent || 'rejected' === $parent->status ) ) {
			return new \WP_Error( 'wpac_state', __( 'Corrections can only be booked for commissions that are not rejected.', 'affiliate-captain' ) );
		}
		if ( $parent ) {
			$affiliate_id = (int) $parent->affiliate_id;
			$currency     = (string) $parent->currency;
		}
		if ( ! AffiliateRepository::find( (int) $affiliate_id ) || ! preg_match( '/^[A-Z]{3}$/', $currency ) ) {
			return new \WP_Error( 'wpac_state', __( 'Unknown partner or currency.', 'affiliate-captain' ) );
		}
		$t = Db::table( 'commissions' );
		// A correction of an open commission follows it (like a refund); otherwise payable at once.
		$status = $parent && in_array( $parent->status, array( 'pending', 'review' ), true ) ? $parent->status : 'approved';
		$link_parent    = isset( $link['parent_id'] ) ? (int) $link['parent_id'] : 0;
		$link_reference = isset( $link['reference'] ) ? (string) $link['reference'] : '';
		try {
			$id = Db::transaction(
				static function () use ( $wpdb, $t, $affiliate_id, $currency, $amount_minor, $reason, $parent, $status, $link_parent, $link_reference ) {
					$key = self::key( 'manual:' . (int) $affiliate_id . ':' . Db::now() . ':' . wp_generate_password( 8, false ) );
					$ok  = $wpdb->insert(
						$t,
						array(
							'affiliate_id'    => (int) $affiliate_id,
							'source'          => $parent ? $parent->source : 'wpac',
							'source_order_id' => $parent ? $parent->source_order_id : 'manual',
							'source_item_id'  => $parent ? $parent->source_item_id : '',
							'kind'            => 'adjustment',
							'parent_id'       => $parent ? (int) $parent->id : 0,
							'product_ref'     => $parent ? $parent->product_ref : '',
							'description'     => Db::cut( $reason, 255 ),
							'currency'        => $currency,
							'base_minor'      => 0,
							'amount_minor'    => $amount_minor,
							'rate_type'       => 'fixed',
							'rate'            => '0',
							'rule_id'         => 0,
							'rule_snapshot'   => null,
							'status'          => $status,
							'hold_until'      => $parent && 'approved' !== $status ? $parent->hold_until : Db::now(),
							'idempotency_key' => $key,
							'created_at'      => Db::now(),
							'updated_at'      => Db::now(),
						)
					);
					if ( ! $ok ) {
						throw new IntegrationException( 'db_adjustment', esc_html( $wpdb->last_error ) );
					}
					$id = (int) $wpdb->insert_id;
					if ( 'approved' === $status ) {
						Balance::post( (int) $affiliate_id, $currency, 'approved', $amount_minor, $id, 0, 'manual' );
					}
					self::event( $id, 'created', '', $status, $amount_minor, 'manual: ' . $reason, $key, get_current_user_id() );
					if ( $link_parent || '' !== $link_reference ) {
						// Information only (P15-1): not parent_id, so no refund, payout or credit-note rule sees it.
						self::event( $id, self::LINK_EVENT, $status, $status, 0, $link_reference, $link_parent ? self::LINK_PREFIX . $link_parent : '', get_current_user_id() );
					}
					if ( $parent ) {
						self::event( (int) $parent->id, 'adjusted', $parent->status, $parent->status, $amount_minor, 'manual', $key, get_current_user_id() );
					}
					return $id;
				}
			);
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'wpac_db_error', __( 'The booking could not be saved (database error). Please try again.', 'affiliate-captain' ) );
		}
		Audit::log(
			'commission.manual',
			'affiliate',
			(int) $affiliate_id,
			array(
				'commission' => $id,
				'amount'     => $amount_minor,
				'currency'   => $currency,
				'parent'     => $parent ? (int) $parent->id : 0,
				'reason'     => $reason,
			) + ( $link_parent || '' !== $link_reference ? array(
				'linked'    => $link_parent,
				'reference' => $link_reference,
			) : array() )
		);
		Db::after_commit(
			static function () use ( $id ) {
				do_action( 'wpac_commission_created', $id );
			}
		);
		return $id;
	}

	/**
	 * Start the hold period of an order's open commissions again from a later date (e.g. the
	 * order was shipped/completed). Only shortens a longer hold (the placeholder "until the
	 * order is completed"); never releases earlier than hold_until() for that date.
	 *
	 * @param string $source   Source.
	 * @param string $order_id Order.
	 * @param int    $from     Timestamp the hold period starts.
	 * @return int Rows changed.
	 */
	public static function restart_hold( $source, $order_id, $from ) {
		global $wpdb;
		$t = Db::table( 'commissions' );
		// The partner of the order (one attribution per order): its program may have its own hold days.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$aff  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT affiliate_id FROM {$t} WHERE source = %s AND source_order_id = %s AND status IN ('pending','review') ORDER BY id ASC LIMIT 1", (string) $source, (string) $order_id ) );
		$hold = self::hold_until(
			array(
				'source'     => (string) $source,
				'order_id'   => (string) $order_id,
				'paid_at'    => gmdate( 'Y-m-d H:i:s', (int) $from ),
				'hold_until' => '',
			),
			$aff
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET hold_until = %s, updated_at = %s WHERE source = %s AND source_order_id = %s AND status IN ('pending','review') AND hold_until > %s", Db::now( $hold ), Db::now(), (string) $source, (string) $order_id, Db::now( $hold ) ) );
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
		// Manual corrections (R12-N3) stay after a refund and say nothing about the order itself.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount_minor),0) FROM {$t} WHERE source = %s AND source_order_id = %s AND status <> 'rejected' AND kind NOT IN ('bonus','adjustment')", $source, $order_id ) );
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
			return new \WP_Error( 'wpac_reason_required', __( 'Please enter a reason.', 'affiliate-captain' ) );
		}
		$currency = strtoupper( (string) $currency );
		$t        = Db::table( 'commissions' );
		try {
			$written = Db::transaction(
				static function () use ( $wpdb, $t, $affiliate_id, $currency, $reason ) {
					// The open (approved, unpaid) rows define the balance; lock them. The claim includes
					// the VAT of refunded commissions that were paid out with VAT (the write-off itself
					// is not taxable, so it covers the gross amount).
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE affiliate_id = %d AND currency = %s AND status = 'approved' AND payout_id = 0 FOR UPDATE", $affiliate_id, $currency ) );
					$open = Dunning::open( (int) $affiliate_id, $currency );
					if ( $open >= 0 ) {
						throw new IntegrationException( 'not_negative', esc_html__( 'The balance is not negative.', 'affiliate-captain' ) );
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
							'description'     => Db::cut( $reason, 255 ),
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
						throw new IntegrationException( 'db_writeoff', esc_html( $wpdb->last_error ) );
					}
					$id = (int) $wpdb->insert_id;
					Balance::post( (int) $affiliate_id, $currency, 'approved', -$open, $id, 0, 'write-off' );
					self::event( $id, 'created', '', 'approved', -$open, 'write-off: ' . $reason, $key, get_current_user_id() );
					// After COMMIT (dropped on rollback), like the other ledger bookings.
					Db::after_commit(
						static function () use ( $id ) {
							do_action( 'wpac_commission_created', $id );
						}
					);
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
		// The debt is settled: a later new debt starts again with the first payment request.
		Dunning::reset( (int) $affiliate_id, $currency );
		// Correction document for the VAT corrections the settled balance contains.
		\WPAffiliateCaptain\Payouts\PayoutService::settle( (int) $affiliate_id, $currency );
		return $written;
	}

	/**
	 * Reject all open commissions of an affiliate (termination policy).
	 *
	 * @param int    $affiliate_id Affiliate.
	 * @param string $reason       Reason.
	 * @return int Number of commissions that could not be rejected.
	 */
	public static function reject_open_for_affiliate( $affiliate_id, $reason ) {
		global $wpdb;
		$t = Db::table( 'commissions' );
		// Earnings only: a write-off or a repayment settles a debt; rejecting it would revive the debt.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE affiliate_id = %d AND status IN ('pending','review','approved') AND payout_id = 0 AND amount_minor > 0 AND kind NOT IN ('writeoff','repayment','correction')", $affiliate_id ) );
		$failed = 0;
		foreach ( $ids as $id ) {
			// reject() reports errors instead of throwing: one failure must not stop the others.
			if ( is_wp_error( self::reject( (int) $id, $reason ) ) ) {
				++$failed;
			}
		}
		return $failed;
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
		$from_status = '';
		$event_row   = 0;
		$ok          = Db::transaction(
			static function () use ( $wpdb, $t, $sql, $args, $id, $to, $c, $reason, $actor, $from, &$from_status, &$event_row ) {
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
				$event_row = self::event( (int) $id, $to, $from_status, $to, (int) $c->amount_minor, $reason, '', $actor );
				if ( 'approved' === $to ) {
					Balance::post( (int) $c->affiliate_id, $c->currency, 'approved', (int) $c->amount_minor, (int) $id );
				} elseif ( 'rejected' === $to && 'approved' === $from_status ) {
					Balance::post( (int) $c->affiliate_id, $c->currency, 'rejected', -(int) $c->amount_minor, (int) $id, 0, Db::cut( (string) $reason, 191 ) );
				}
				return true;
			}
		);
		if ( ! $ok ) {
			return false;
		}
		self::$last_event_ids[ (int) $id ] = (int) $event_row; // AC-5: callers that fire the hook later.
		ReviewAlert::flush(); // Menu bubble count, also when the hooks are fired later.
		if ( $fire_hooks ) {
			// transition() may run inside an outer transaction (it nests): the hook waits for the
			// COMMIT of that one and is dropped on its rollback (AFF-WF-02).
			// Second parameter (contract 1.7.0): the status before this transition, read under the row lock.
			// Third parameter (contract 1.10.0, AC-5): ID of the history entry of this transition.
			Db::after_commit(
				static function () use ( $to, $id, $from_status, $event_row ) {
					do_action( 'wpac_commission_' . $to, (int) $id, (string) $from_status, (int) $event_row );
				}
			);
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
	 * @return int ID of the stored history entry (table commission_events).
	 * @throws IntegrationException When the event could not be stored: the history of a commission
	 *                              must not miss a step, so the caller's transaction is rolled back
	 *                              (and the booking reported as failed) instead of looking saved.
	 */
	public static function event( $id, $event, $from, $to, $amount, $reason = '', $event_id = '', $actor = null ) {
		global $wpdb;
		$ok = $wpdb->insert(
			Db::table( 'commission_events' ),
			array(
				'commission_id'   => (int) $id,
				'event'           => $event,
				'from_status'     => $from,
				'to_status'       => $to,
				'amount_minor'    => (int) $amount,
				'reason'          => Db::cut( (string) $reason, 255 ),
				'actor_id'        => null === $actor ? get_current_user_id() : (int) $actor,
				'source_event_id' => Db::cut( (string) $event_id, 191 ),
				'created_at'      => Db::now(),
			)
		);
		if ( ! $ok ) {
			throw new IntegrationException( 'db_commission_event', esc_html( 'Could not store the commission event: ' . $wpdb->last_error ) );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Hold-until timestamp: paid date + hold days, extended by integrations
	 * (e.g. guarantee end) via the payload or the `wpac_hold_until` filter. The later date wins.
	 *
	 * @param array $sale         Sale.
	 * @param int   $affiliate_id Partner of the sale (0 = unknown): the hold days pass the filter
	 *                            wpac_setting with the partner's program.
	 * @return int
	 */
	public static function hold_until( array $sale, $affiliate_id = 0 ) {
		$paid = $sale['paid_at'] ? strtotime( $sale['paid_at'] . ' UTC' ) : time();
		$hold = $paid + DAY_IN_SECONDS * max( 0, (int) Settings::for_affiliate( 'hold_days', (int) $affiliate_id ) );
		if ( ! empty( $sale['hold_until'] ) ) {
			$hold = max( $hold, (int) strtotime( $sale['hold_until'] . ' UTC' ) );
		}
		/**
		 * Latest guarantee/withdrawal end for the order (UNIX timestamp). Only later dates extend the hold.
		 *
		 * @param int   $hold         Timestamp.
		 * @param array $sale         Sale.
		 * @param int   $affiliate_id Partner of the sale (0 = unknown; since contract 1.5.0).
		 */
		$filtered = (int) apply_filters( 'wpac_hold_until', $hold, $sale, (int) $affiliate_id );
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
		return strlen( $key ) <= 191 ? $key : Db::cut( $key, 140 ) . ':h:' . sha1( $key );
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
		// Also the pseudonymised key of a booking past the retention period (R13-M3).
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE idempotency_key IN (%s, %s) LIMIT 1", $key, \WPAffiliateCaptain\Privacy\Retention::pseudonym_key( $key ) ) );
	}

	/**
	 * Whether commissions of an order exist under its pseudonym (past the retention period, see
	 * Retention::pseudonymise_commissions()).
	 *
	 * @param string $source   Source.
	 * @param string $order_id Order.
	 * @return bool
	 */
	public static function pseudonymised_order( $source, $order_id ) {
		global $wpdb;
		$t = Db::table( 'commissions' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$t} WHERE source = %s AND source_order_id = %s LIMIT 1", $source, \WPAffiliateCaptain\Privacy\Retention::pseudonym( $source . ':' . $order_id ) ) );
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
	 * Whether the commission of an order still stands: not rejected and not fully reversed
	 * (refunded, cancelled, chargeback). Orders attributed without any commission line count as
	 * standing. Used e.g. for subscription renewals of that order.
	 *
	 * @param string $source   Source.
	 * @param string $order_id Order.
	 * @return bool
	 */
	public static function commission_stands( $source, $order_id ) {
		global $wpdb;
		$rows = self::sale_commissions( $source, $order_id );
		if ( ! $rows ) {
			return true;
		}
		$sales = 0;
		$open  = false;
		foreach ( $rows as $r ) {
			if ( 'rejected' !== $r->status ) {
				$open   = true;
				$sales += (int) $r->amount_minor;
			}
		}
		if ( ! $open ) {
			return false;
		}
		$t = Db::table( 'commissions' );
		// Manual corrections (R12-N3) stay after a refund and say nothing about the order itself.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount_minor),0) FROM {$t} WHERE source = %s AND source_order_id = %s AND status <> 'rejected' AND kind NOT IN ('bonus','adjustment')", $source, (string) $order_id ) );
		return $sales <= 0 || $left > 0;
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
	 * @param int  $id             Parent.
	 * @param bool $lock           Locking read (inside a transaction).
	 * @param bool $automatic_only Only the automatically calculated corrections (refunds,
	 *                             reinstatements, increases), without manual corrections (R12-N3).
	 * @return int
	 */
	public static function adjusted_sum( $id, $lock = false, $automatic_only = false ) {
		global $wpdb;
		$t = Db::table( 'commissions' );
		// A locking read sees rows committed by parallel refunds (a plain read in a REPEATABLE READ
		// transaction would use the older snapshot and reverse the same amount twice).
		$for = $lock ? ' FOR UPDATE' : ''; // Constant fragment, no input.
		// R12-N3: manual corrections (penalties, bonuses) are not part of the calculated commission.
		$manual = $automatic_only ? " AND kind NOT IN ('" . implode( "','", self::MANUAL_KINDS ) . "')" : ''; // Constant list, no input.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount_minor),0) FROM {$t} WHERE parent_id = %d AND status <> 'rejected'{$manual}{$for}", $id ) );
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
	 * Information-only links of manual bookings (LINK_EVENT, P15-1).
	 *
	 * @param int[] $ids Commission IDs.
	 * @return array Commission ID => array( parent_id, reference ), only for linked bookings.
	 */
	public static function links( array $ids ) {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( ! $ids ) {
			return array();
		}
		$t   = Db::table( 'commission_events' );
		$in  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$out = array();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- %d placeholders built above.
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT commission_id, reason, source_event_id FROM {$t} WHERE event = %s AND commission_id IN ({$in}) ORDER BY id ASC", array_merge( array( self::LINK_EVENT ), $ids ) ) ) as $e ) {
			$ref = (string) $e->source_event_id;
			$out[ (int) $e->commission_id ] = array(
				'parent_id' => 0 === strpos( $ref, self::LINK_PREFIX ) ? (int) substr( $ref, strlen( self::LINK_PREFIX ) ) : 0,
				'reference' => (string) $e->reason,
			);
		}
		return $out;
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
	 * @param array $args affiliate_id, status, kind, source, from, to (Y-m-d), order_id, group_ids
	 *                    (list: partners of these groups, empty = none), limit, offset.
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
		$cache                  = '';
		if ( '1=1' === $where ) {
			// Without a filter the sums cover the whole table: cached until a commission is added or
			// changed (MAX(id) and MAX(updated_at) are index lookups).
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$cache = 'wpac_commission_totals_' . md5( wp_json_encode( $wpdb->get_row( "SELECT MAX(id), MAX(updated_at) FROM {$t}" ) ) );
			$hit   = get_transient( $cache );
			if ( is_array( $hit ) ) {
				return $hit;
			}
		}
		$sql                    = "SELECT status, currency, SUM(amount_minor) total FROM {$t} WHERE {$where} GROUP BY status, currency";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_results( $sql );
		$out  = array();
		foreach ( $rows as $r ) {
			$out[ $r->status ][ $r->currency ] = (int) $r->total;
		}
		if ( '' !== $cache ) {
			set_transient( $cache, $out, 5 * MINUTE_IN_SECONDS ); // Short: several changes within one second keep MAX(updated_at).
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
		if ( isset( $args['group_ids'] ) && is_array( $args['group_ids'] ) ) {
			// Program filter (variant A: the partner's current group).
			$groups = array_values( array_unique( array_map( 'intval', $args['group_ids'] ) ) );
			if ( $groups ) {
				$where .= ' AND affiliate_id IN (SELECT id FROM ' . Db::table( 'affiliates' ) . ' WHERE group_id IN (' . implode( ',', array_fill( 0, count( $groups ), '%d' ) ) . '))';
				$params = array_merge( $params, $groups );
			} else {
				$where   .= ' AND 1 = %d';
				$params[] = 0;
			}
		}
		if ( ! empty( $args['status'] ) && in_array( $args['status'], self::STATUSES, true ) ) {
			$where   .= ' AND status = %s';
			$params[] = $args['status'];
		}
		if ( ! empty( $args['kind'] ) && array_key_exists( (string) $args['kind'], self::kind_labels() ) ) {
			$where   .= ' AND kind = %s';
			$params[] = (string) $args['kind'];
		}
		if ( ! empty( $args['source'] ) ) {
			$where   .= ' AND source = %s';
			$params[] = $args['source'];
		}
		if ( ! empty( $args['order_id'] ) ) {
			$where   .= ' AND source_order_id = %s';
			$params[] = (string) $args['order_id'];
		}
		// Dates of the filter are local days (site time zone); stored times are UTC.
		if ( ! empty( $args['from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $args['from'] ) ) {
			$where   .= ' AND created_at >= %s';
			$params[] = Db::local_range( $args['from'], $args['from'] )[0];
		}
		if ( ! empty( $args['to'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $args['to'] ) ) {
			$where   .= ' AND created_at <= %s';
			$params[] = Db::local_range( $args['to'], $args['to'] )[1];
		}
		return array( $where, $params );
	}
}
