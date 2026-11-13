<?php
/**
 * WooCommerce integration: reports paid orders, refunds, cancellations and chargebacks via the public contract (idempotent, retryable).
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Integrations\WooCommerce;

use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Core\Audit;
use WPAffiliateCaptain\Core\Capabilities;
use WPAffiliateCaptain\Core\Jobs;
use WPAffiliateCaptain\Core\Money;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Sales\ContractApi;
use WPAffiliateCaptain\Tracking\Tracker;
use WPAffiliateCaptain\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class OrderSync {
	/**
	 * Refund IDs deducted by the last payload() call.
	 *
	 * @var string[]
	 */
	private static $netted_refunds = array();

	/**
	 * Build the contract payload for an order.
	 *
	 * @param \WC_Order $order Order.
	 * @return array
	 */
	public static function payload( $order ) {
		$currency = $order->get_currency();
		$items    = array();
		// Refunds that happened before the sale was booked (retries, late manual assignment),
		// including amount-only refunds, per line.
		$refunded             = array();
		self::$netted_refunds = array();
		foreach ( $order->get_refunds() as $refund ) {
			self::$netted_refunds[] = (string) $refund->get_id();
			foreach ( self::refund_lines( $order, $refund ) as $line ) {
				$refunded[ $line['item_id'] ] = ( isset( $refunded[ $line['item_id'] ] ) ? $refunded[ $line['item_id'] ] : 0 ) + $line['net_minor'];
			}
		}
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			/** @var \WC_Order_Item_Product $item */
			$product      = $item->get_product();
			$product_id   = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
			$parent_id    = $item->get_variation_id() ? $item->get_product_id() : 0;
			$category_ids = $item->get_product_id() ? wc_get_product_term_ids( $item->get_product_id(), 'product_cat' ) : array();
			$tag_ids      = $item->get_product_id() ? wc_get_product_term_ids( $item->get_product_id(), 'product_tag' ) : array();
			$items[]      = array(
				'item_id'      => (string) $item_id,
				'product_id'   => (int) $product_id,
				'parent_id'    => (int) $parent_id,
				'sku'          => $product ? (string) $product->get_sku() : '',
				'category_ids' => array_map( 'intval', $category_ids ),
				'tag_ids'      => array_map( 'intval', $tag_ids ),
				'name'         => $item->get_name(),
				'quantity'     => (int) $item->get_quantity(),
				// Line total = net after discounts, excluding tax and shipping, minus earlier refunds.
				'net_minor'    => max( 0, Money::to_minor( (string) $item->get_total(), $currency ) - ( isset( $refunded[ (string) $item_id ] ) ? $refunded[ (string) $item_id ] : 0 ) ),
			);
		}

		$touch   = $order->get_meta( WooCommerce::META_TOUCH );
		$paid    = $order->get_date_paid() ? $order->get_date_paid() : $order->get_date_created();
		$manual  = self::manual_affiliate( $order );
		$code    = CheckoutField::order_code( $order );
		$renewal = self::is_renewal( $order );
		if ( $renewal ) {
			// Renewals have no checkout: partner code and touch on a renewal were copied from the
			// first order by WooCommerce Subscriptions (older subscriptions, before the copy was
			// excluded) and must not override the partner of the subscription.
			$code  = '';
			$touch = null;
		}

		$payload = array(
			'contract'     => WPAC_CONTRACT_VERSION,
			'source'       => WooCommerce::SOURCE,
			'order_id'     => (string) $order->get_id(),
			'currency'     => $currency,
			'paid_at'      => $paid ? $paid->getTimestamp() : time(),
			'items'        => $items,
			'coupon_codes' => $order->get_coupon_codes(),
			'partner_code' => $code,
			'affiliate_id' => $manual,
			'touch'        => is_array( $touch ) ? $touch : null,
			'customer'     => array(
				'user_id' => (int) $order->get_customer_id(),
				'email'   => (string) $order->get_billing_email(),
			),
		);
		// A renewal without its own manual assignment belongs to the partner who brought the
		// subscription: the partner of the subscription's first (parent) order. Part of the
		// payload so retries of the queued order (run_job()) keep the partner as well.
		if ( $renewal && ! $manual ) {
			$parent = self::subscription_parent_attribution( $order );
			if ( $parent ) {
				$payload['affiliate_id']       = (int) $parent->affiliate_id;
				$payload['attribution_method'] = 'renewal';
			}
		}
		return $payload;
	}

	/**
	 * Partner assigned manually to this order. On a renewal only an assignment made on the
	 * renewal itself counts (marked by the order box), never one copied from the first order.
	 *
	 * @param \WC_Order $order Order.
	 * @return int
	 */
	public static function manual_affiliate( $order ) {
		$manual = (int) $order->get_meta( WooCommerce::META_MANUAL );
		if ( $manual && self::is_renewal( $order ) && (int) $order->get_meta( self::META_MANUAL_OWN ) !== (int) $order->get_id() ) {
			return 0;
		}
		return $manual;
	}

	/** Marks a manual assignment made on this order (value: the order ID). */
	const META_MANUAL_OWN = '_wpac_manual_own';

	/** Order ID the "resolved without partner" marker belongs to (copies on renewals don't count). */
	const META_RESOLVED_FOR = '_wpac_resolved_for';

	/**
	 * Mark an order as resolved without a partner.
	 *
	 * @param \WC_Order $order Order.
	 */
	private static function mark_unresolved( $order ) {
		$order->update_meta_data( '_wpac_resolved', 'none' );
		$order->update_meta_data( self::META_RESOLVED_FOR, (int) $order->get_id() );
		$order->save_meta_data();
	}

	/**
	 * Whether the order was already resolved without a partner (its own marker, not a copy).
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	private static function is_unresolved( $order ) {
		if ( 'none' !== $order->get_meta( '_wpac_resolved' ) ) {
			return false;
		}
		if ( self::is_renewal( $order ) ) {
			return (int) $order->get_meta( self::META_RESOLVED_FOR ) === (int) $order->get_id();
		}
		return true;
	}

	/**
	 * Meta keys of this plugin that WooCommerce Subscriptions must not copy from the first order
	 * to the subscription or from the subscription to renewals and resubscriptions.
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	public static function is_own_meta( $key ) {
		$key = (string) $key;
		return 0 === strpos( $key, '_wpac_' ) || 0 === strpos( $key, '_wc_other/' . WooCommerce::BLOCKS_FIELD ) || 0 === strpos( $key, '_wc_billing/' . WooCommerce::BLOCKS_FIELD ) || 0 === strpos( $key, '_wc_shipping/' . WooCommerce::BLOCKS_FIELD );
	}

	/**
	 * Filter for WooCommerce Subscriptions' meta copy. Handles both shapes: the current
	 * "wc_subscriptions_{type}_data" filter (meta key => value) and the older
	 * "wcs_{type}_meta" filter (list of [ meta_key, meta_value ] rows).
	 *
	 * @param mixed $data Meta to copy.
	 * @return mixed
	 */
	public static function exclude_subscription_meta( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		$rows = false;
		foreach ( $data as $k => $row ) {
			$key = $k;
			if ( is_array( $row ) && isset( $row['meta_key'] ) ) {
				$key  = $row['meta_key'];
				$rows = true;
			} elseif ( is_object( $row ) && isset( $row->meta_key ) ) {
				$key  = $row->meta_key;
				$rows = true;
			}
			if ( is_string( $key ) && self::is_own_meta( $key ) ) {
				unset( $data[ $k ] );
			}
		}
		return $rows ? array_values( $data ) : $data;
	}

	/**
	 * Keep this plugin's order meta out of WooCommerce Subscriptions' copies (first order →
	 * subscription → renewal/resubscription), so renewals are attributed via the subscription.
	 */
	public static function register_subscription_hooks() {
		foreach ( array( 'subscription', 'renewal_order', 'resubscribe_order' ) as $type ) {
			add_filter( 'wc_subscriptions_' . $type . '_data', array( __CLASS__, 'exclude_subscription_meta' ), 20 );
			// Older versions only know the (since deprecated) row filter. Hooking it on newer
			// versions would switch them to the legacy copy path with a deprecation notice.
			if ( ! class_exists( 'WC_Subscriptions_Data_Copier' ) ) {
				add_filter( 'wcs_' . $type . '_meta', array( __CLASS__, 'exclude_subscription_meta' ), 20 );
			}
		}
	}

	/**
	 * Report a paid order (idempotent).
	 *
	 * @param int $order_id Order ID.
	 */
	public static function sync_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || is_a( $order, 'WC_Order_Refund' ) || ! $order->is_paid() ) {
			return;
		}
		if ( Ledger::attribution_for( WooCommerce::SOURCE, (string) $order->get_id() ) ) {
			return;
		}
		// Already resolved without a partner (payment_complete + processing + completed fire for the same order).
		if ( self::is_unresolved( $order ) && ! self::manual_affiliate( $order ) ) {
			return;
		}
		// Subscription renewals (WooCommerce Subscriptions) only earn commission when enabled.
		if ( ! Settings::get( 'renewal_commission' ) && self::is_renewal( $order ) && ! self::manual_affiliate( $order ) ) {
			self::mark_unresolved( $order );
			return;
		}
		// Renewals: partner of the subscription's first order (see payload()).
		$payload = self::payload( $order );
		/**
		 * Filter the WooCommerce sale payload before it is recorded (e.g. connector add-ons
		 * can add hold_until or item meta).
		 *
		 * @param array     $payload Payload.
		 * @param \WC_Order $order   Order.
		 */
		$payload = apply_filters( 'wpac_wc_sale_payload', $payload, $order );
		$result  = ContractApi::record_sale(
			$payload,
			array(
				'type'    => 'woo_order',
				'payload' => array( 'order_id' => (int) $order->get_id() ),
			)
		);

		if ( is_array( $result ) && 'unattributed' === $result['status'] ) {
			self::mark_unresolved( $order );
		}
		if ( is_array( $result ) && 'created' === $result['status'] ) {
			$attr = Ledger::attribution_for( WooCommerce::SOURCE, (string) $order->get_id() );
			if ( $attr ) {
				$aff = AffiliateRepository::find( (int) $attr->affiliate_id );
				/* translators: 1: affiliate name, 2: method */
				$order->add_order_note( sprintf( __( 'Affiliate Captain: attributed to partner "%1$s" (%2$s).', 'wp-affiliate-captain' ), $aff ? $aff->name : '#' . $attr->affiliate_id, Ledger::method_label( $attr->method ) ) );
			}
		}
	}

	/**
	 * Report a WooCommerce refund, position-exact where possible.
	 *
	 * @param int $order_id  Order.
	 * @param int $refund_id Refund.
	 */
	public static function sync_refund( $order_id, $refund_id ) {
		$order  = wc_get_order( $order_id );
		$refund = wc_get_order( $refund_id );
		if ( ! $order || ! $refund ) {
			return;
		}
		// No commission yet: only report when the sale is being booked or queued (the refund is
		// then queued as well); otherwise the order has no partner and there is nothing to do.
		if ( ! Ledger::sale_commissions( WooCommerce::SOURCE, (string) $order_id ) && ! Jobs::sale_pending( WooCommerce::SOURCE, (string) $order_id ) ) {
			return;
		}
		$items = self::refund_lines( $order, $refund );
		if ( ! $items ) {
			return;
		}

		ContractApi::record_refund(
			array(
				'contract'  => WPAC_CONTRACT_VERSION,
				'source'    => WooCommerce::SOURCE,
				'order_id'  => (string) $order_id,
				'refund_id' => (string) $refund_id,
				'type'      => 'refund',
				'items'     => $items,
				'reason'    => (string) $refund->get_reason(),
			)
		);
	}

	/**
	 * Attribution of the order that started the subscription(s) of a renewal order.
	 *
	 * @param \WC_Order $order Renewal order.
	 * @return object|null
	 */
	public static function subscription_parent_attribution( $order ) {
		$parents = array();
		if ( function_exists( 'wcs_get_subscriptions_for_renewal_order' ) ) {
			foreach ( (array) wcs_get_subscriptions_for_renewal_order( $order ) as $subscription ) {
				if ( is_object( $subscription ) && method_exists( $subscription, 'get_parent_id' ) && $subscription->get_parent_id() ) {
					$parents[] = (int) $subscription->get_parent_id();
				}
			}
		}
		$sub_id = (int) $order->get_meta( '_subscription_renewal' );
		if ( ! $parents && $sub_id && function_exists( 'wc_get_order' ) ) {
			$subscription = wc_get_order( $sub_id );
			if ( $subscription && method_exists( $subscription, 'get_parent_id' ) && $subscription->get_parent_id() ) {
				$parents[] = (int) $subscription->get_parent_id();
			}
		}
		foreach ( $parents as $parent_id ) {
			$attr = Ledger::attribution_for( WooCommerce::SOURCE, (string) $parent_id );
			if ( $attr ) {
				return $attr;
			}
		}
		return null;
	}

	/**
	 * Whether an order is a subscription renewal (WooCommerce Subscriptions).
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	public static function is_renewal( $order ) {
		if ( function_exists( 'wcs_order_contains_renewal' ) ) {
			return (bool) wcs_order_contains_renewal( $order );
		}
		return (bool) $order->get_meta( '_subscription_renewal' );
	}

	/**
	 * Commission-relevant lines of a WooCommerce refund: [ item_id, net_minor ].
	 *
	 * - Refund lines with an amount: position-exact.
	 * - Only shipping/fee/tax lines with an amount: nothing (the net product base is unchanged).
	 * - Otherwise (amount-only refund, or lines with 0 total, e.g. only a restock quantity
	 *   with a manually typed amount): the amount pro rata over the net line totals.
	 *
	 * @param \WC_Order        $order  Order.
	 * @param \WC_Order_Refund $refund Refund.
	 * @return array
	 */
	public static function refund_lines( $order, $refund ) {
		$currency = $order->get_currency();
		$items    = array();
		foreach ( $refund->get_items( 'line_item' ) as $refund_item ) {
			$original = (int) $refund_item->get_meta( '_refunded_item_id' );
			$net      = abs( Money::to_minor( (string) $refund_item->get_total(), $currency ) );
			if ( $original && $net > 0 ) {
				$items[] = array(
					'item_id'   => (string) $original,
					'net_minor' => $net,
				);
			}
		}
		if ( $items ) {
			return $items;
		}
		foreach ( $refund->get_items( array( 'shipping', 'fee', 'tax' ) ) as $other ) {
			if ( 0 !== Money::to_minor( (string) $other->get_total(), $currency ) || ( method_exists( $other, 'get_total_tax' ) && 0 !== Money::to_minor( (string) $other->get_total_tax(), $currency ) ) ) {
				return array(); // Only shipping/fee/tax refunded.
			}
		}
		$order_total = Money::to_minor( (string) $order->get_total(), $currency );
		$refunded    = abs( Money::to_minor( (string) $refund->get_amount(), $currency ) );
		if ( $order_total <= 0 || $refunded <= 0 ) {
			return array();
		}
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			$items[] = array(
				'item_id'   => (string) $item_id,
				'net_minor' => Money::prorate( Money::to_minor( (string) $item->get_total(), $currency ), min( $refunded, $order_total ), $order_total ),
			);
		}
		return $items;
	}

	/**
	 * Order fully refunded.
	 *
	 * @param int $order_id Order.
	 */
	public static function full_reversal_refunded( $order_id ) {
		self::full_reversal( $order_id, 'refund', __( 'Order refunded', 'wp-affiliate-captain' ) );
	}

	/**
	 * Order cancelled.
	 *
	 * @param int $order_id Order.
	 */
	public static function full_reversal_cancelled( $order_id ) {
		self::full_reversal( $order_id, 'cancellation', __( 'Order cancelled', 'wp-affiliate-captain' ) );
	}

	/**
	 * Status change: treat configured statuses as chargeback.
	 *
	 * @param int    $order_id Order.
	 * @param string $from     Old status.
	 * @param string $to       New status.
	 */
	public static function maybe_chargeback( $order_id, $from, $to ) {
		// Back to a paid status after a cancellation, chargeback or status refund (chargeback won,
		// cancelled order paid after all): the commission is reinstated.
		if ( in_array( sanitize_key( $from ), array( 'cancelled', 'failed', 'refunded' ), true ) && in_array( sanitize_key( $to ), array( 'processing', 'completed' ), true ) ) {
			self::reinstate( $order_id );
			return;
		}
		// Default: "failed" after the order was paid (payment reversed/disputed by the gateway).
		// Commissions only exist for paid orders, so this never affects unpaid ones.
		$statuses = array_map( 'sanitize_key', (array) apply_filters( 'wpac_wc_chargeback_statuses', array( 'failed' ) ) );
		if ( in_array( sanitize_key( $to ), $statuses, true ) ) {
			self::full_reversal_chargeback( $order_id );
		}
	}

	/**
	 * Chargeback status (configured via the wpac_wc_chargeback_statuses filter).
	 *
	 * @param int $order_id Order.
	 */
	public static function full_reversal_chargeback( $order_id ) {
		self::full_reversal( $order_id, 'chargeback', __( 'Chargeback', 'wp-affiliate-captain' ) );
	}

	/**
	 * Reinstate the commission after a reversal that turned out to be wrong.
	 *
	 * @param int $order_id Order.
	 */
	public static function reinstate( $order_id ) {
		try {
			self::reinstate_now( $order_id );
		} catch ( \Throwable $e ) {
			// Database error: retried by the job queue (Ledger::reinstate() is idempotent per reversal).
			$queued = Jobs::enqueue( 'woo_reinstate', WooCommerce::SOURCE . ':' . (int) $order_id, array( 'order_id' => (int) $order_id ), $e instanceof \WPAffiliateCaptain\Core\IntegrationException ? $e->error_code : 'exception', $e->getMessage() );
			$order  = wc_get_order( $order_id );
			if ( $order ) {
				$order->add_order_note(
					$queued
						? __( 'Affiliate Captain: the partner commission could not be reinstated yet and will be retried automatically (Affiliate Captain → Integrations).', 'wp-affiliate-captain' )
						: __( 'Affiliate Captain: the partner commission could not be reinstated. Please change the order status again later.', 'wp-affiliate-captain' )
				);
			}
		}
	}

	/**
	 * Reinstate now; database errors are thrown (job queue).
	 *
	 * @param int $order_id Order.
	 * @throws \Throwable On database errors.
	 */
	private static function reinstate_now( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$n = Ledger::reinstate( WooCommerce::SOURCE, (string) $order_id, __( 'Order paid again (chargeback won or cancellation reversed)', 'wp-affiliate-captain' ) );
		if ( $n ) {
			$order->update_meta_data( '_wpac_reinstated', (int) $order->get_meta( '_wpac_reinstated' ) + 1 );
			$order->save_meta_data();
			$order->add_order_note( __( 'Partner commission reinstated.', 'wp-affiliate-captain' ) );
		}
	}

	/**
	 * Reverse all remaining commission of an order.
	 *
	 * @param int    $order_id Order.
	 * @param string $type     Type.
	 * @param string $reason   Reason.
	 */
	private static function full_reversal( $order_id, $type, $reason ) {
		if ( ! Ledger::sale_commissions( WooCommerce::SOURCE, (string) $order_id ) && ! Jobs::sale_pending( WooCommerce::SOURCE, (string) $order_id ) ) {
			return;
		}
		// After a reinstatement a new reversal of the same kind is a new event (own key).
		$order  = wc_get_order( $order_id );
		$round  = $order ? (int) $order->get_meta( '_wpac_reinstated' ) : 0;
		ContractApi::record_refund(
			array(
				'contract'  => WPAC_CONTRACT_VERSION,
				'source'    => WooCommerce::SOURCE,
				'order_id'  => (string) $order_id,
				'refund_id' => 'status-' . $type . ( $round ? '-' . $round : '' ),
				'type'      => $type,
				'full'      => true,
				'reason'    => $reason,
			)
		);
	}

	/**
	 * Retry handler for queued WooCommerce jobs.
	 *
	 * @param bool   $handled Handled.
	 * @param string $type    Type.
	 * @param array  $payload Payload.
	 * @return bool
	 */
	public static function run_job( $handled, $type, $payload ) {
		if ( 'woo_order' === $type && ! empty( $payload['order_id'] ) ) {
			$order = wc_get_order( (int) $payload['order_id'] );
			if ( ! $order || ! $order->is_paid() || Ledger::attribution_for( WooCommerce::SOURCE, (string) $order->get_id() ) ) {
				return true; // Nothing (left) to do.
			}
			// Rebuilt from the current order (incl. refunds so far); exceptions propagate to the job runner.
			$payload = self::payload( $order );
			$netted  = self::$netted_refunds;
			Ledger::record_sale( ContractApi::normalize_sale( apply_filters( 'wpac_wc_sale_payload', $payload, $order ) ) );
			// These refunds are already deducted from the rebuilt sale: their queued refund jobs
			// must not deduct them a second time.
			Jobs::supersede_refunds( WooCommerce::SOURCE, (string) $order->get_id(), $netted );
			return true;
		}
		if ( 'woo_reinstate' === $type && ! empty( $payload['order_id'] ) ) {
			$order = wc_get_order( (int) $payload['order_id'] );
			if ( ! $order || ! $order->is_paid() ) {
				return true; // Reversed again meanwhile: nothing to reinstate.
			}
			self::reinstate_now( (int) $payload['order_id'] ); // Exceptions propagate to the job runner.
			return true;
		}
		return $handled;
	}
}
