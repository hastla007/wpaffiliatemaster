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
	 * Build the contract payload for an order.
	 *
	 * @param \WC_Order $order Order.
	 * @return array
	 */
	public static function payload( $order ) {
		$currency = $order->get_currency();
		$items    = array();
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
				// Line total = net after discounts, excluding tax and shipping, minus refunds
				// that happened before the sale was booked (e.g. retries, late manual assignment).
				'net_minor'    => max( 0, Money::to_minor( (string) $item->get_total(), $currency ) - abs( Money::to_minor( (string) $order->get_total_refunded_for_item( $item_id ), $currency ) ) ),
			);
		}

		$touch  = $order->get_meta( WooCommerce::META_TOUCH );
		$paid   = $order->get_date_paid() ? $order->get_date_paid() : $order->get_date_created();
		$manual = (int) $order->get_meta( WooCommerce::META_MANUAL );

		return array(
			'contract'     => WPAC_CONTRACT_VERSION,
			'source'       => WooCommerce::SOURCE,
			'order_id'     => (string) $order->get_id(),
			'currency'     => $currency,
			'paid_at'      => $paid ? $paid->getTimestamp() : time(),
			'items'        => $items,
			'coupon_codes' => $order->get_coupon_codes(),
			'partner_code' => CheckoutField::order_code( $order ),
			'affiliate_id' => $manual,
			'touch'        => is_array( $touch ) ? $touch : null,
			'customer'     => array(
				'user_id' => (int) $order->get_customer_id(),
				'email'   => (string) $order->get_billing_email(),
			),
		);
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
		if ( 'none' === $order->get_meta( '_wpac_resolved' ) && ! $order->get_meta( WooCommerce::META_MANUAL ) ) {
			return;
		}
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
			$order->update_meta_data( '_wpac_resolved', 'none' );
			$order->save_meta_data();
		}
		if ( is_array( $result ) && 'created' === $result['status'] ) {
			$attr = Ledger::attribution_for( WooCommerce::SOURCE, (string) $order->get_id() );
			if ( $attr ) {
				$aff = AffiliateRepository::find( (int) $attr->affiliate_id );
				/* translators: 1: affiliate name, 2: method */
				$order->add_order_note( sprintf( __( 'Affiliate Captain: attributed to partner "%1$s" (%2$s).', 'wp-affiliate-captain' ), $aff ? $aff->name : '#' . $attr->affiliate_id, $attr->method ) );
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
		if ( ! $order || ! $refund || ! Ledger::sale_commissions( WooCommerce::SOURCE, (string) $order_id ) ) {
			return;
		}
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

		if ( ! $items && $refund->get_items( array( 'line_item', 'shipping', 'fee', 'tax' ) ) ) {
			return; // Only shipping/fee/tax refunded – the commission base (net products) is unchanged.
		}
		if ( ! $items ) {
			// Amount-only refund (no items at all): distribute pro rata over the net line totals.
			$order_total = Money::to_minor( (string) $order->get_total(), $currency );
			$refunded    = abs( Money::to_minor( (string) $refund->get_amount(), $currency ) );
			if ( $order_total > 0 && $refunded > 0 ) {
				foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
					$net = Money::to_minor( (string) $item->get_total(), $currency );
					$items[] = array(
						'item_id'   => (string) $item_id,
						'net_minor' => Money::prorate( $net, min( $refunded, $order_total ), $order_total ),
					);
				}
			}
		}
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
		$statuses = array_map( 'sanitize_key', (array) apply_filters( 'wpac_wc_chargeback_statuses', array() ) );
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
	 * Reverse all remaining commission of an order.
	 *
	 * @param int    $order_id Order.
	 * @param string $type     Type.
	 * @param string $reason   Reason.
	 */
	private static function full_reversal( $order_id, $type, $reason ) {
		if ( ! Ledger::sale_commissions( WooCommerce::SOURCE, (string) $order_id ) ) {
			return;
		}
		ContractApi::record_refund(
			array(
				'contract'  => WPAC_CONTRACT_VERSION,
				'source'    => WooCommerce::SOURCE,
				'order_id'  => (string) $order_id,
				'refund_id' => 'status-' . $type,
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
			Ledger::record_sale( ContractApi::normalize_sale( apply_filters( 'wpac_wc_sale_payload', self::payload( $order ), $order ) ) );
			return true;
		}
		return $handled;
	}
}
