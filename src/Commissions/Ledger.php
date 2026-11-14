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
			'adjustment'   => __( 'Manual booking', 'affiliate-captain' ),
			'repayment'    => __( 'Repayment by the partner', 'affiliate-captain' ),
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
		$hold    = self::hold_until( $sale );
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

				foreach ( $sale['items'] as $item ) {
					list( $calc, $line_net ) = self::line_commission( $item, $sale, $aff, $rules, $default, $precedence );
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
	 * Rules, program default and precedence that apply to a sale of a partner (same for the
	 * original booking and later increases of an order).
	 *
	 * @param object $aff      Partner.
	 * @param string $currency Currency of the sale.
	 * @param string $method   Attribution method (lifetime customers may have their own rate).
	 * @return array [ rules, default, precedence ]
	 */
	private static function rules_for( $aff, $currency, $method ) {
		// Only the rules that can apply to this partner (global, own, own group).
		$rules      = RuleRepository::active_for( (int) $aff->id, (int) $aff->group_id );
		$default    = RuleRepository::default_rate();
		$precedence = RuleRepository::precedence();
		$program = strtoupper( (string) Settings::get( 'currency' ) );
		if ( $program !== $currency ) {
			// Fixed amounts are stored in the program currency: convert them with the
			// configured rates. Without a rate the matching rule still wins (and pays 0)
			// so a broader percent rule never takes over a more specific fixed rule.
			$rates = Fx::rates();
			foreach ( $rules as $i => $r ) {
				if ( 'fixed' === $r['rate_type'] ) {
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
	 * Commission of one line of a sale: rule, base filter and amount filter (one path for the
	 * original booking and later increases).
	 *
	 * @param array  $item       Normalized item.
	 * @param array  $sale       Normalized sale.
	 * @param object $aff        Partner.
	 * @param array  $rules      Rules.
	 * @param array  $default    Program default.
	 * @param string $precedence Rule precedence.
	 * @return array [ calc (Calculator::calculate()), unfiltered line net ]
	 */
	private static function line_commission( array $item, array $sale, $aff, array $rules, array $default, $precedence ) {
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
		return array( $calc, $line_net );
	}

	/** Idempotency key prefix of the bookings for increased orders (record_increase()). */
	const INCREASE_KEY = 'increase:';

	/**
	 * Book the additional commission of an order that was increased after its sale was booked
	 * (higher quantity or line total, new lines – e.g. edited in the shop admin after payment).
	 *
	 * The sale is the current state of the whole order (net amounts after refunds), built by the
	 * same code as the original sale (caps, gift cards, bundles). Per line the commission is
	 * calculated on the same path as a sale: a line that already earned commission keeps the
	 * rule stored with it (rate snapshot – later rule changes never alter history), a new line
	 * gets the rules that apply now (precedence, exclusions, lifetime rate). Only the positive
	 * difference to what is booked and still stands (after refunds) is booked: as an own sale
	 * row of the same order, line and partner (the attribution is never changed). Commissions
	 * that are approved or paid are never touched; the difference is a new commission with its
	 * own hold period (counted from now, never shorter than the open original) and the status a
	 * sale gets (review while the line or the order is under review, otherwise pending).
	 *
	 * Idempotent: the booked base is read from the ledger and the booking key contains it, so
	 * saving the order again books nothing.
	 *
	 * No booking for a partner who is no longer active or whose processing is restricted, for
	 * lines that were rejected or fully reversed, and when switched off (setting
	 * book_order_increase, filter wpac_book_order_increase).
	 *
	 * @param array $sale Normalized sale (ContractApi::normalize_sale()) with the current order state.
	 * @return array { status: created|unchanged|no_commission|disabled|ineligible|reversed, commission_ids, amount_minor, reason }
	 * @throws IntegrationException On DB errors.
	 */
	public static function record_increase( array $sale ) {
		global $wpdb;
		$none = static function ( $status, $reason ) {
			return array(
				'status'         => $status,
				'commission_ids' => array(),
				'amount_minor'   => 0,
				'reason'         => $reason,
			);
		};
		$attr = self::attribution_for( $sale['source'], $sale['order_id'] );
		if ( ! $attr ) {
			return $none( 'no_commission', 'no_attribution' );
		}
		/**
		 * Whether the additional commission of an order increased after its sale was booked is
		 * booked automatically (default: setting "book_order_increase", on). Off: the order only
		 * gets a note and a correction can be booked manually.
		 *
		 * @param bool   $book Book it.
		 * @param array  $sale Normalized sale (current state of the order).
		 * @param object $attr Attribution of the order.
		 */
		if ( ! apply_filters( 'wpac_book_order_increase', (bool) Settings::get( 'book_order_increase' ), $sale, $attr ) ) {
			return $none( 'disabled', 'disabled' );
		}
		$aff = AffiliateRepository::find( (int) $attr->affiliate_id );
		if ( ! $aff ) {
			return $none( 'ineligible', 'partner_missing' );
		}
		if ( \WPAffiliateCaptain\Privacy\Restriction::is_restricted( (int) $aff->id ) ) {
			return $none( 'ineligible', 'partner_restricted' );
		}
		if ( 'active' !== $aff->status ) {
			return $none( 'ineligible', 'partner_' . sanitize_key( (string) $aff->status ) );
		}
		if ( ! self::commission_stands( $sale['source'], $sale['order_id'] ) ) {
			return $none( 'reversed', 'commission_reversed' );
		}
		list( $rules, $default, $precedence ) = self::rules_for( $aff, $sale['currency'], (string) $attr->method );
		$round  = (int) get_option( 'wpac_reassign_' . md5( $sale['source'] . ':' . $sale['order_id'] ), 0 );
		$hold   = self::hold_until( array_merge( $sale, array( 'paid_at' => Db::now() ) ) );
		$review = 'review' === (string) $attr->status;

		$result = Db::transaction(
			static function () use ( $wpdb, $sale, $attr, $aff, $rules, $default, $precedence, $round, $hold, $review ) {
				// One booking per order at a time (parallel saves of the order).
				$at = Db::table( 'attributions' );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$at} WHERE id = %d FOR UPDATE", (int) $attr->id ) );
				$lines = self::sale_lines( $sale['source'], $sale['order_id'], (int) $aff->id, true );
				$ids   = array();
				$sum   = 0;
				foreach ( $sale['items'] as $item ) {
					$item_id      = (string) $item['item_id'];
					$line         = isset( $lines[ $item_id ] ) ? $lines[ $item_id ] : null;
					$until        = $hold;
					$line_default = $default;
					if ( $line ) {
						$o = $line['original'];
						if ( 'rejected' === $o->status || $line['reversed'] ) {
							continue; // Rejected (e.g. fraud) or reversed lines never earn more.
						}
						// The rule stored with the line (snapshot), not the rules of today.
						$snap       = $o->rule_snapshot ? json_decode( (string) $o->rule_snapshot, true ) : null;
						$line_rules = is_array( $snap ) && ! empty( $snap['rule'] ) && is_array( $snap['rule'] ) ? array( $snap['rule'] ) : array();
						if ( is_array( $snap ) && ! empty( $snap['default'] ) && is_array( $snap['default'] ) && isset( $snap['default']['rate_type'], $snap['default']['rate'] ) ) {
							$line_default = $snap['default'];
						}
						list( $calc, $line_net ) = self::line_commission( $item, $sale, $aff, $line_rules, $line_default, $precedence );
						$delta_base              = (int) $calc['base_minor'] - (int) $line['remaining'];
						$delta_amount            = (int) $calc['amount_minor'] - (int) $line['standing'];
						// One minor unit per line is rounding of the shop totals, not an increase.
						if ( $calc['excluded'] || $delta_base <= 1 || $delta_amount <= 0 ) {
							continue;
						}
						$status = $review || 'review' === $o->status ? 'review' : 'pending';
						if ( in_array( $o->status, array( 'pending', 'review' ), true ) ) {
							$until = max( $until, (int) strtotime( $o->hold_until . ' UTC' ) ); // Never released before the original.
						}
						$key      = self::INCREASE_KEY . $sale['source'] . ':' . $sale['order_id'] . ':' . $item_id . ':' . (int) $o->id . ':' . (int) $line['remaining'] . ':' . (int) $calc['base_minor'];
						$of       = (int) $o->id;
						$line_net = (int) $calc['base_minor'] > 0 ? Money::prorate( $delta_base, $line_net, (int) $calc['base_minor'] ) : $delta_base;
					} else {
						// A new line (or a line that earned nothing so far): booked like a line of the sale.
						list( $calc, $line_net ) = self::line_commission( $item, $sale, $aff, $rules, $default, $precedence );
						if ( $calc['excluded'] || (int) $calc['amount_minor'] <= 0 ) {
							continue;
						}
						$delta_base   = (int) $calc['base_minor'];
						$delta_amount = (int) $calc['amount_minor'];
						$status       = $review ? 'review' : 'pending';
						$key          = 'sale:' . $sale['source'] . ':' . $sale['order_id'] . ':' . $item_id . ( $round ? ':r' . $round : '' );
						$of           = 0;
					}
					$key = self::key( $key );
					if ( self::find_by_key( $key ) ) {
						continue; // Booked already (idempotent).
					}
					$snapshot = array(
						'rule'          => $calc['rule'],
						'default'       => $calc['rule'] ? null : $line_default,
						'touch_model'   => $attr->touch_model,
						'method'        => $attr->method,
						'contract'      => $sale['contract'],
						'calculated_at' => Db::now(),
						'line_net'      => $line_net,
					);
					if ( $of ) {
						$snapshot['increase_of'] = $of;
					}
					$suppress = $wpdb->suppress_errors( true );
					$ok       = $wpdb->insert(
						Db::table( 'commissions' ),
						array(
							'affiliate_id'    => (int) $aff->id,
							'source'          => $sale['source'],
							'source_order_id' => $sale['order_id'],
							'source_item_id'  => $item_id,
							'kind'            => 'sale',
							'parent_id'       => 0,
							'product_ref'     => Db::cut( (string) ( $item['sku'] ? $item['sku'] : $item['product_id'] ), 190 ),
							'description'     => Db::cut( (string) $item['name'], 255 ),
							'currency'        => $sale['currency'],
							'base_minor'      => $delta_base,
							'amount_minor'    => $delta_amount,
							'rate_type'       => $calc['rate_type'],
							'rate'            => $calc['rate'],
							'rule_id'         => $calc['rule_id'],
							'rule_snapshot'   => wp_json_encode( $snapshot ),
							'status'          => $status,
							'hold_until'      => Db::now( $until ),
							'idempotency_key' => $key,
							'created_at'      => Db::now(),
							'updated_at'      => Db::now(),
						)
					);
					$wpdb->suppress_errors( $suppress );
					if ( ! $ok ) {
						if ( self::find_by_key( $key ) ) {
							continue; // A parallel request booked it.
						}
						throw new IntegrationException( 'db_commission', esc_html( $wpdb->last_error ) );
					}
					$cid   = (int) $wpdb->insert_id;
					$ids[] = $cid;
					$sum  += $delta_amount;
					self::event( $cid, 'created', '', $status, $delta_amount, $of ? 'increase of #' . $of : 'increase: new line', $key );
				}
				return array(
					'status'         => $ids ? 'created' : 'unchanged',
					'commission_ids' => $ids,
					'amount_minor'   => $sum,
					'reason'         => '',
				);
			}
		);
		if ( $result['commission_ids'] ) {
			// Inside an outer transaction only once it is committed.
			Db::after_commit(
				static function () use ( $sale, $aff, $result ) {
					Audit::log(
						'commission.increased',
						'order',
						0,
						array(
							'source'      => $sale['source'],
							'order_id'    => $sale['order_id'],
							'affiliate'   => (int) $aff->id,
							'commissions' => $result['commission_ids'],
							'amount'      => (int) $result['amount_minor'],
							'currency'    => $sale['currency'],
						)
					);
					foreach ( $result['commission_ids'] as $cid ) {
						do_action( 'wpac_commission_created', $cid );
					}
					/**
					 * Fires after the additional commission of an increased order was booked.
					 *
					 * @param array $sale           Normalized sale (current state of the order).
					 * @param int[] $commission_ids New commissions.
					 */
					do_action( 'wpac_commission_increased', $sale, $result['commission_ids'] );
				}
			);
		}
		return $result;
	}

	/**
	 * Sale row that a row books an increase of (record_increase()); 0 for an original sale row.
	 *
	 * @param object $row Commission row.
	 * @return int ID of the original sale row.
	 */
	public static function increase_of( $row ) {
		if ( 0 !== strpos( (string) $row->idempotency_key, self::INCREASE_KEY ) ) {
			return 0;
		}
		$snap = $row->rule_snapshot ? json_decode( (string) $row->rule_snapshot, true ) : null;
		return is_array( $snap ) && ! empty( $snap['increase_of'] ) ? (int) $snap['increase_of'] : 0;
	}

	/**
	 * Rows booked for increases of a sale row (record_increase()), rejected ones included.
	 *
	 * @param object $c Sale row.
	 * @return array
	 */
	public static function increases( $c ) {
		if ( ! $c || 'sale' !== $c->kind || self::increase_of( $c ) ) {
			return array();
		}
		$out = array();
		foreach ( self::sale_commissions( $c->source, $c->source_order_id ) as $r ) {
			if ( self::increase_of( $r ) === (int) $c->id ) {
				$out[] = $r;
			}
		}
		return $out;
	}

	/**
	 * Booked lines of an order for one partner: per item the original sale row (the newest
	 * booking round) and the rows booked for increases of it, with the base that is still booked
	 * (after refunds and corrections) and the commission that still stands.
	 *
	 * @param string $source       Source.
	 * @param string $order_id     Order.
	 * @param int    $affiliate_id Partner.
	 * @param bool   $lock         Locking reads (inside a transaction).
	 * @return array item_id => { original, rows, base, line_net, remaining, standing, reversed }
	 */
	public static function sale_lines( $source, $order_id, $affiliate_id, $lock = false ) {
		$rows = self::sale_commissions( $source, $order_id );
		usort(
			$rows,
			static function ( $a, $b ) {
				return (int) $a->id - (int) $b->id;
			}
		);
		$lines     = array();
		$increases = array();
		foreach ( $rows as $r ) {
			if ( (int) $r->affiliate_id !== (int) $affiliate_id ) {
				continue;
			}
			$of = self::increase_of( $r );
			if ( $of ) {
				$increases[ $of ][] = $r;
				continue;
			}
			$lines[ (string) $r->source_item_id ] = array( 'original' => $r ); // A newer round replaces older ones.
		}
		foreach ( $lines as $item_id => $line ) {
			$o     = $line['original'];
			$chain = array_merge( array( $o ), isset( $increases[ (int) $o->id ] ) ? $increases[ (int) $o->id ] : array() );
			$line  = array(
				'original'  => $o,
				'rows'      => array(),
				'base'      => 0,
				'line_net'  => 0,
				'remaining' => 0,
				'standing'  => 0,
				'reversed'  => 'rejected' !== $o->status && self::is_reversed( (int) $o->id ),
			);
			foreach ( $chain as $r ) {
				if ( 'rejected' === $r->status ) {
					continue;
				}
				$snap               = $r->rule_snapshot ? json_decode( (string) $r->rule_snapshot, true ) : null;
				$line['rows'][]     = $r;
				$line['base']      += (int) $r->base_minor;
				$line['line_net']  += is_array( $snap ) && ! empty( $snap['line_net'] ) ? (int) $snap['line_net'] : (int) $r->base_minor;
				$line['remaining'] += max( 0, (int) $r->base_minor - self::refunded_base( (int) $r->id, $lock ) );
				$line['standing']  += (int) $r->amount_minor + self::adjusted_sum( (int) $r->id, $lock );
			}
			$lines[ $item_id ] = $line;
		}
		return $lines;
	}

	/**
	 * Whether a sale row was fully reversed (cancellation, chargeback, status reversal such as
	 * trash or "refunded", reassignment) and not reinstated.
	 *
	 * @param int $id Sale row.
	 * @return bool
	 */
	private static function is_reversed( $id ) {
		foreach ( self::children( (int) $id ) as $child ) {
			if ( 'rejected' === $child->status || (int) $child->amount_minor >= 0 || ! in_array( $child->kind, array( 'refund', 'cancellation', 'chargeback' ), true ) ) {
				continue;
			}
			$event_id = '';
			foreach ( self::events( (int) $child->id ) as $e ) {
				if ( 'created' === $e->event ) {
					$event_id = (string) $e->source_event_id;
					break;
				}
			}
			$full = 0 === strpos( $event_id, 'status-' ) || 0 === strpos( $event_id, 'reassign-' ) || 'refund' !== $child->kind;
			if ( $full && ! self::find_by_key( self::key( 'reinstate:' . (int) $child->id ) ) ) {
				return true;
			}
		}
		return false;
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
		$by_item   = array();
		$increases = array();
		foreach ( $originals as $c ) {
			$of = self::increase_of( $c );
			if ( $of ) {
				// Booked for an increase of the order (record_increase()): refunded together with its line.
				$increases[ $of ] = array_merge( array( $c ), isset( $increases[ $of ] ) ? $increases[ $of ] : array() ); // Newest first.
				continue;
			}
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
			static function () use ( $wpdb, $refund, $by_item, $increases, $lines ) {
				$ids = array();
				$t   = Db::table( 'commissions' );
				foreach ( $lines as $item_id => $refunded_net ) {
					// The line and the rows booked for increases of it: a partial refund takes the
					// increases first (newest first), the rest from the original sale row.
					$chain   = isset( $increases[ (int) $by_item[ $item_id ]->id ] ) ? $increases[ (int) $by_item[ $item_id ]->id ] : array();
					$last    = count( $chain );
					$chain[] = $by_item[ $item_id ];
					$left    = (int) $refunded_net;
					foreach ( $chain as $i => $row ) {
						// Lock the commission so parallel refunds/chargebacks are serialized.
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$parent = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d FOR UPDATE", $row->id ) );
						if ( ! $parent || 'rejected' === $parent->status ) {
							continue;
						}
						$snap  = $parent->rule_snapshot ? json_decode( (string) $parent->rule_snapshot, true ) : null;
						$share = $left;
						if ( ! $refund['full'] && $i < $last ) {
							// Net of the line still booked in this row (line net units, like the refund).
							$open  = max( 0, (int) $parent->base_minor - self::refunded_base( (int) $parent->id, true ) );
							$net   = is_array( $snap ) && ! empty( $snap['line_net'] ) ? (int) $snap['line_net'] : (int) $parent->base_minor;
							$share = min( $left, (int) $parent->base_minor > 0 ? Money::prorate( $open, $net, (int) $parent->base_minor ) : 0 );
						}
						if ( ! $refund['full'] ) {
							$left -= $share;
							if ( $share <= 0 ) {
								continue;
							}
						}
						$aid = self::refund_row( $refund, $parent, $snap, $share, $item_id . ( $i < $last ? ':c' . (int) $parent->id : '' ) );
						if ( $aid ) {
							$ids[] = $aid;
						}
					}
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
					foreach ( $originals as $o ) {
						if ( (int) $o->payout_id ) {
							\WPAffiliateCaptain\Payouts\PayoutService::flag_clawback( (int) $o->payout_id, $refund['source'] . ' #' . $refund['order_id'] );
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
	 * Book the correction of one sale row for a refund (inside the transaction of record_refund(),
	 * the row is locked).
	 *
	 * @param array      $refund       Normalized refund.
	 * @param object     $parent       Sale row.
	 * @param array|null $snap         Rule snapshot of the row.
	 * @param int        $refunded_net Refunded line net for this row (ignored for full reversals).
	 * @param string     $key_suffix   Line part of the booking key.
	 * @return int Correction ID (0 = nothing to book).
	 * @throws IntegrationException On DB errors.
	 */
	private static function refund_row( array $refund, $parent, $snap, $refunded_net, $key_suffix ) {
		global $wpdb;
		$key = self::key( 'refund:' . $refund['source'] . ':' . $refund['order_id'] . ':' . $refund['refund_id'] . ':' . $key_suffix );
		if ( self::find_by_key( $key ) ) {
			return 0; // Idempotent.
		}
		// The base may differ from the line net (wpac_commission_base filter): scale the refund.
		if ( ! $refund['full'] && is_array( $snap ) && ! empty( $snap['line_net'] ) && (int) $snap['line_net'] !== (int) $parent->base_minor ) {
			$refunded_net = (int) round( $refunded_net * (int) $parent->base_minor / (int) $snap['line_net'] );
		}
		$adjusted = self::adjusted_sum( (int) $parent->id, true );
		$refunded = self::refunded_base( (int) $parent->id, true );
		$amount   = Calculator::refund_adjustment( (int) $parent->amount_minor, (int) $parent->base_minor, $adjusted, $refunded_net, $refund['full'], $refunded );
		if ( 0 === $amount ) {
			return 0;
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
				return 0;
			}
			throw new IntegrationException( 'db_adjustment', esc_html( $wpdb->last_error ) );
		}
		$aid = (int) $wpdb->insert_id;
		if ( 'approved' === $status ) {
			// Correction of an approved/paid commission: payable balance changes immediately.
			Balance::post( (int) $parent->affiliate_id, $parent->currency, 'approved', $amount, $aid, 0, $refund['type'] );
		}
		self::event( $aid, 'created', '', $status, $amount, $refund['type'] . ': ' . $refund['reason'], $refund['refund_id'] );
		self::event( (int) $parent->id, 'adjusted', $parent->status, $parent->status, $amount, $refund['type'], $refund['refund_id'] );
		return $aid;
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
		foreach ( self::increases( $c ) as $inc ) {
			if ( $inc->status === $c->status ) {
				self::approve( (int) $inc->id, $reason ); // Additional commission of the same line (increased order).
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
		foreach ( self::increases( $c ) as $inc ) {
			if ( in_array( $inc->status, array( 'pending', 'review', 'approved' ), true ) ) {
				self::reject( (int) $inc->id, $reason ); // Additional commission of the same line (increased order).
			}
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
	 * @return int|\WP_Error Commission ID.
	 */
	public static function manual_adjustment( $affiliate_id, $currency, $amount_minor, $reason, $parent_id = 0 ) {
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
		try {
			$id = Db::transaction(
				static function () use ( $wpdb, $t, $affiliate_id, $currency, $amount_minor, $reason, $parent, $status ) {
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
			)
		);
		do_action( 'wpac_commission_created', $id );
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
		$t    = Db::table( 'commissions' );
		$hold = self::hold_until(
			array(
				'source'     => (string) $source,
				'order_id'   => (string) $order_id,
				'paid_at'    => gmdate( 'Y-m-d H:i:s', (int) $from ),
				'hold_until' => '',
			)
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
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE affiliate_id = %d AND status IN ('pending','review','approved') AND payout_id = 0 AND amount_minor > 0 AND kind NOT IN ('writeoff','repayment')", $affiliate_id ) );
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
					Balance::post( (int) $c->affiliate_id, $c->currency, 'rejected', -(int) $c->amount_minor, (int) $id, 0, Db::cut( (string) $reason, 191 ) );
				}
				return true;
			}
		);
		if ( ! $ok ) {
			return false;
		}
		ReviewAlert::flush(); // Menu bubble count, also when the hooks are fired later.
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
	 * @return true
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
		return true;
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
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount_minor),0) FROM {$t} WHERE source = %s AND source_order_id = %s AND status <> 'rejected' AND kind <> 'bonus'", $source, (string) $order_id ) );
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
