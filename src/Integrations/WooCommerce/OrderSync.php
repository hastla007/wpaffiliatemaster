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
use WPAffiliateCaptain\Core\Log;
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

	/** Hold-until placeholder for "until the order is completed" (setting hold_start = completed). */
	const HOLD_OPEN = 4102444800; // 2100-01-01.

	/**
	 * Build the contract payload for an order.
	 *
	 * @param \WC_Order $order Order.
	 * @return array
	 */
	public static function payload( $order ) {
		$currency = $order->get_currency();
		$items    = array();
		$base     = self::base_lines( $order );
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
		foreach ( $base['lines'] as $item_id => $line ) {
			/** @var \WC_Order_Item_Product $item */
			$item       = $line['item'];
			$product    = $item->get_product();
			$product_id = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
			$parent_id  = $item->get_variation_id() ? $item->get_product_id() : 0;
			$categories = $item->get_product_id() ? self::terms( (int) $item->get_product_id(), 'product_cat' ) : array( array(), array() );
			$tag_ids    = $item->get_product_id() ? wc_get_product_term_ids( $item->get_product_id(), 'product_tag' ) : array();
			$items[]    = array(
				'item_id'               => (string) $item_id,
				'product_id'            => (int) $product_id,
				'parent_id'             => (int) $parent_id,
				'sku'                   => $product ? (string) $product->get_sku() : '',
				'category_ids'          => $categories[0],
				// Parent categories: rules on a parent category apply to its subcategories.
				'category_ancestor_ids' => $categories[1],
				'tag_ids'               => array_map( 'intval', $tag_ids ),
				'name'                  => $item->get_name(),
				'quantity'              => (int) $item->get_quantity(),
				// Line total = net after discounts, excluding tax and shipping (bundle/composite parts
				// included, capped to the net product total of the order), minus earlier refunds.
				'net_minor'             => max( 0, $line['net'] - ( isset( $refunded[ (string) $item_id ] ) ? $refunded[ (string) $item_id ] : 0 ) ),
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
			'contract'          => WPAC_CONTRACT_VERSION,
			'source'            => WooCommerce::SOURCE,
			'order_id'          => (string) $order->get_id(),
			'currency'          => $currency,
			'paid_at'           => $paid ? $paid->getTimestamp() : time(),
			'items'             => $items,
			'coupon_codes'      => $order->get_coupon_codes(),
			'partner_code'      => $code,
			'affiliate_id'      => $manual,
			'touch'             => is_array( $touch ) ? $touch : null,
			'customer'          => array(
				'user_id'  => (int) $order->get_customer_id(),
				'email'    => (string) $order->get_billing_email(),
				// Only for the self-referral comparison with the partner's payout profile; never stored.
				'name'     => trim( self::billing( $order, 'first_name' ) . ' ' . self::billing( $order, 'last_name' ) ),
				'address'  => self::billing( $order, 'address_1' ),
				'postcode' => self::billing( $order, 'postcode' ),
			),
			// These refunds are deducted above: their queued refund jobs must not deduct them again.
			'netted_refund_ids' => self::$netted_refunds,
		);
		// A renewal without its own manual assignment belongs to the partner who brought the
		// subscription: the partner of the subscription's first (parent) order. Part of the
		// payload so retries of the queued order (run_job()) keep the partner as well.
		if ( $renewal && ! $manual ) {
			$parent = self::subscription_parent_attribution( $order );
			if ( $parent ) {
				$payload['affiliate_id']       = (int) $parent->affiliate_id;
				$payload['attribution_method'] = 'renewal';
				if ( 'review' === $parent->status ) {
					// The first order is under review (fraud or self-referral flags): so is the renewal.
					$payload['review'] = true;
				}
			}
		}
		return $payload;
	}

	/**
	 * Billing field of an order ('' when the order object has no such getter).
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $field Field (first_name, last_name, address_1, postcode).
	 * @return string
	 */
	private static function billing( $order, $field ) {
		$getter = 'get_billing_' . $field;
		return is_callable( array( $order, $getter ) ) ? (string) $order->$getter() : '';
	}

	/**
	 * Commission-relevant lines of an order.
	 *
	 * - Parts of a bundle or composite product (Product Bundles, Composite Products) are merged
	 *   into their parent line: the rules of the bundle apply, a fixed rate counts per bundle.
	 * - Gift card products are left out (the redeemed card is paid with real money later).
	 * - The line totals are capped to the net product total the customer really paid (order total
	 *   minus tax, shipping, positive fees and gift cards): discounts booked as negative fees and
	 *   gift cards redeemed as payment lower the commission base.
	 *
	 * @param \WC_Order $order Order.
	 * @return array { lines: item_id => [ item, raw, tax, net ], parent: child item_id => line item_id }
	 */
	public static function base_lines( $order ) {
		$currency = $order->get_currency();
		$all      = $order->get_items( 'line_item' );
		$parent   = self::bundle_parents( $order, $all );
		$lines    = array();
		$excluded = 0;
		foreach ( $all as $item_id => $item ) {
			$total = Money::to_minor( (string) $item->get_total(), $currency );
			$tax   = Money::to_minor( (string) $item->get_total_tax(), $currency );
			if ( isset( $parent[ $item_id ] ) ) {
				continue; // Added to its parent below.
			}
			if ( self::is_gift_card( $item ) ) {
				$excluded += $total;
				continue;
			}
			$lines[ $item_id ] = array(
				'item' => $item,
				'raw'  => $total,
				'tax'  => $tax,
				'net'  => $total,
			);
		}
		foreach ( $parent as $child_id => $line_id ) {
			if ( isset( $lines[ $line_id ] ) ) {
				$lines[ $line_id ]['raw'] += Money::to_minor( (string) $all[ $child_id ]->get_total(), $currency );
				$lines[ $line_id ]['tax'] += Money::to_minor( (string) $all[ $child_id ]->get_total_tax(), $currency );
				$lines[ $line_id ]['net']  = $lines[ $line_id ]['raw'];
			} else {
				$excluded += Money::to_minor( (string) $all[ $child_id ]->get_total(), $currency ); // Part of an excluded gift card.
			}
		}
		$sum = 0;
		foreach ( $lines as $line ) {
			$sum += max( 0, $line['raw'] );
		}
		$fees = 0;
		foreach ( $order->get_items( 'fee' ) as $fee ) {
			$fees += max( 0, Money::to_minor( (string) $fee->get_total(), $currency ) );
		}
		$cap = Money::to_minor( (string) $order->get_total(), $currency ) - Money::to_minor( (string) $order->get_total_tax(), $currency ) - Money::to_minor( (string) $order->get_shipping_total(), $currency ) - $fees - $excluded;
		/**
		 * Net product total the commission base of a WooCommerce order is capped to (minor units).
		 *
		 * @param int       $cap   Cap.
		 * @param \WC_Order $order Order.
		 */
		$cap = (int) apply_filters( 'wpac_wc_base_cap', $cap, $order );
		// Rounding of the totals may differ by a cent per line; only a real gap scales the lines.
		if ( $sum > 0 && $cap + count( $lines ) < $sum ) {
			foreach ( $lines as $id => $line ) {
				$lines[ $id ]['net'] = Money::prorate( max( 0, $line['raw'] ), max( 0, $cap ), $sum );
			}
		}
		return array(
			'lines'  => $lines,
			'parent' => $parent,
		);
	}

	/**
	 * Child lines of bundles/composites: child item_id => item_id of the top-level parent line.
	 *
	 * @param \WC_Order $order Order.
	 * @param array     $items Line items.
	 * @return array
	 */
	private static function bundle_parents( $order, array $items ) {
		$keys = array();
		foreach ( $items as $id => $item ) {
			foreach ( array( '_bundle_cart_key', '_composite_cart_key' ) as $k ) {
				$v = $item->get_meta( $k );
				if ( is_string( $v ) && '' !== $v ) {
					$keys[ $v ] = $id;
				}
			}
		}
		$parent = array();
		foreach ( $items as $id => $item ) {
			$p = 0;
			foreach ( array( '_bundled_by', '_composite_parent' ) as $k ) {
				$v = $item->get_meta( $k );
				if ( is_string( $v ) && isset( $keys[ $v ] ) && $keys[ $v ] !== $id ) {
					$p = $keys[ $v ];
					break;
				}
			}
			/**
			 * Line item that a line belongs to (e.g. other bundle plugins): its amount is added to
			 * that line and the rules of that line apply. 0 = an own line.
			 *
			 * @param int                    $parent_item_id Parent line item ID.
			 * @param \WC_Order_Item_Product $item           Line item.
			 * @param \WC_Order              $order          Order.
			 */
			$p = (int) apply_filters( 'wpac_wc_parent_line', $p, $item, $order );
			if ( $p && $p !== (int) $id && isset( $items[ $p ] ) ) {
				$parent[ $id ] = $p;
			}
		}
		// Nested parts (a composite inside a bundle) belong to the top-level line.
		foreach ( $parent as $child => $p ) {
			for ( $i = 0; $i < 10 && isset( $parent[ $p ] ); $i++ ) {
				$p = $parent[ $p ];
			}
			$parent[ $child ] = $p;
		}
		return $parent;
	}

	/**
	 * Whether a line is a gift card / store credit purchase (no commission: the card is
	 * commissioned when it is spent).
	 *
	 * @param \WC_Order_Item_Product $item Line item.
	 * @return bool
	 */
	public static function is_gift_card( $item ) {
		$product = $item->get_product();
		$gift    = false;
		if ( $product ) {
			$gift = in_array( $product->get_type(), array( 'pw-gift-card', 'gift-card', 'giftcard', 'gift_card' ), true )
				|| 'yes' === $product->get_meta( '_gift_card' )
				|| 'yes' === $product->get_meta( '_is_gift_card' );
		}
		/**
		 * Whether a WooCommerce line is a gift card purchase (left out of the commission base).
		 *
		 * @param bool                   $gift    Gift card.
		 * @param \WC_Order_Item_Product $item    Line item.
		 * @param \WC_Product|null       $product Product.
		 */
		return (bool) apply_filters( 'wpac_wc_is_gift_card', $gift, $item, $product );
	}

	/**
	 * Terms of a product with their parents: [ own term IDs, parent term IDs ].
	 *
	 * @param int    $product_id Product.
	 * @param string $taxonomy   Taxonomy.
	 * @return array
	 */
	public static function terms( $product_id, $taxonomy ) {
		$own       = array_values( array_unique( array_map( 'intval', (array) wc_get_product_term_ids( $product_id, $taxonomy ) ) ) );
		$ancestors = array();
		if ( function_exists( 'get_ancestors' ) ) {
			foreach ( $own as $term_id ) {
				foreach ( (array) get_ancestors( $term_id, $taxonomy, 'taxonomy' ) as $a ) {
					if ( ! in_array( (int) $a, $own, true ) ) {
						$ancestors[ (int) $a ] = (int) $a;
					}
				}
			}
		}
		return array( $own, array_values( $ancestors ) );
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

	/** Why the order has no partner (reason code of the attribution, see unresolved_label()). */
	const META_UNRESOLVED_REASON = '_wpac_unresolved_reason';

	/** Last error reported for the order (avoids the same order note three times). */
	const META_LAST_ERROR = '_wpac_last_error';

	/**
	 * Mark an order as resolved without a partner.
	 *
	 * @param \WC_Order $order  Order.
	 * @param string    $reason Reason code.
	 */
	private static function mark_unresolved( $order, $reason = '' ) {
		$order->update_meta_data( '_wpac_resolved', 'none' );
		$order->update_meta_data( self::META_RESOLVED_FOR, (int) $order->get_id() );
		$order->update_meta_data( self::META_UNRESOLVED_REASON, sanitize_key( (string) $reason ) );
		$order->save_meta_data();
	}

	/**
	 * Readable reason why an order has no partner.
	 *
	 * @param string $reason Reason code.
	 * @return string
	 */
	public static function unresolved_label( $reason ) {
		$labels = array(
			'no_candidate'         => __( 'No partner link, partner code, coupon or lifetime customer.', 'affiliate-captain' ),
			'code_not_found'       => __( 'The partner code or link is unknown, expired, blocked or used up.', 'affiliate-captain' ),
			'partner_not_eligible' => __( 'The partner is not active or processing is restricted.', 'affiliate-captain' ),
			'self_referral'        => __( 'The partner is the buyer (self-referral).', 'affiliate-captain' ),
			'renewals_off'         => __( 'Commission on subscription renewals is switched off.', 'affiliate-captain' ),
			'renewal_no_partner'   => __( 'The first order of the subscription has no partner, its commission was rejected or reversed, or the renewal period has ended.', 'affiliate-captain' ),
		);
		return isset( $labels[ $reason ] ) ? $labels[ $reason ] : __( 'No partner found.', 'affiliate-captain' );
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
			self::mark_unresolved( $order, 'renewals_off' );
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

		if ( is_wp_error( $result ) ) {
			self::report_error( $order, $result, 'sale' );
			return;
		}
		if ( is_array( $result ) && 'unattributed' === $result['status'] ) {
			$reason = (string) $result['reason'];
			if ( self::is_renewal( $order ) && ! self::manual_affiliate( $order ) && empty( $payload['affiliate_id'] ) ) {
				$reason = 'renewal_no_partner';
			}
			self::mark_unresolved( $order, $reason );
		}
		if ( is_array( $result ) && 'created' === $result['status'] ) {
			$attr = Ledger::attribution_for( WooCommerce::SOURCE, (string) $order->get_id() );
			if ( $attr ) {
				$aff = AffiliateRepository::find( (int) $attr->affiliate_id );
				/* translators: 1: affiliate name, 2: method */
				$order->add_order_note( sprintf( __( 'Affiliate Captain: attributed to partner "%1$s" (%2$s).', 'affiliate-captain' ), $aff ? $aff->name : '#' . $attr->affiliate_id, Ledger::method_label( $attr->method ) ) );
			}
		}
	}

	/**
	 * Leave a trace of a sale/refund the contract did not accept or could not book yet: order
	 * note (once per error) and log entry. Without it the order silently earns no commission.
	 *
	 * @param \WC_Order $order  Order.
	 * @param \WP_Error $error  Error.
	 * @param string    $what   sale|refund.
	 */
	private static function report_error( $order, $error, $what ) {
		$code = (string) $error->get_error_code();
		$key  = $what . ':' . $code;
		if ( 'wpac_queued' !== $code ) {
			Log::error(
				'WooCommerce ' . $what . ' not booked',
				array(
					'order' => (int) $order->get_id(),
					'code'  => $code,
				)
			);
		}
		if ( (string) $order->get_meta( self::META_LAST_ERROR ) === $key ) {
			return;
		}
		$order->update_meta_data( self::META_LAST_ERROR, $key );
		$order->save_meta_data();
		if ( 'wpac_queued' === $code ) {
			$note = 'sale' === $what
				? __( 'Affiliate Captain: the partner commission could not be booked yet and will be retried automatically (Affiliate Captain → Integrations).', 'affiliate-captain' )
				: __( 'Affiliate Captain: the refund could not be deducted from the partner commission yet and will be retried automatically (Affiliate Captain → Integrations).', 'affiliate-captain' );
		} else {
			$note = sprintf(
				'sale' === $what
					/* translators: 1: error message, 2: error code */
					? __( 'Affiliate Captain: the sale could not be booked: %1$s (%2$s)', 'affiliate-captain' )
					/* translators: 1: error message, 2: error code */
					: __( 'Affiliate Captain: the refund could not be booked: %1$s (%2$s)', 'affiliate-captain' ),
				$error->get_error_message(),
				$code
			);
		}
		$order->add_order_note( $note );
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

		$result = ContractApi::record_refund(
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
		if ( is_wp_error( $result ) ) {
			self::report_error( $order, $result, 'refund' );
		}
	}

	/**
	 * Attribution of the order that started the subscription(s) of a renewal order – only while
	 * that commission stands: no renewal commission for a first order whose commission was
	 * rejected (e.g. fraud) or fully reversed, nor after the renewal period (setting
	 * renewal_limit_months).
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
		$months = (int) Settings::get( 'renewal_limit_months' );
		foreach ( $parents as $parent_id ) {
			$attr = Ledger::attribution_for( WooCommerce::SOURCE, (string) $parent_id );
			if ( ! $attr ) {
				continue;
			}
			$ok = Ledger::commission_stands( WooCommerce::SOURCE, (string) $parent_id );
			if ( $ok && $months > 0 && strtotime( $attr->created_at . ' UTC +' . $months . ' months' ) < time() ) {
				$ok = false;
			}
			/**
			 * Whether a renewal still earns commission for the partner of the first order.
			 *
			 * @param bool      $ok        Earns commission.
			 * @param object    $attr      Attribution of the first order.
			 * @param \WC_Order $order     Renewal order.
			 * @param int       $parent_id First order.
			 */
			if ( apply_filters( 'wpac_renewal_attribution', $ok, $attr, $order, $parent_id ) ) {
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
	 * Absolute amount (minor units) of a refund line of any type. Tax lines have no get_total()
	 * (WC_Order_Item_Tax: get_tax_total()/get_shipping_tax_total()).
	 *
	 * @param object $item     Order item.
	 * @param string $currency Currency.
	 * @return int
	 */
	private static function item_amount( $item, $currency ) {
		$sum = 0;
		foreach ( array( 'get_total', 'get_total_tax', 'get_tax_total', 'get_shipping_tax_total' ) as $method ) {
			if ( method_exists( $item, $method ) ) {
				$sum += abs( Money::to_minor( (string) $item->$method(), $currency ) );
			}
		}
		return $sum;
	}

	/**
	 * Commission-relevant lines of a WooCommerce refund: [ item_id, net_minor ].
	 *
	 * - Refund lines with an amount: position-exact (parts of a bundle count for the bundle).
	 * - Only shipping/fee/tax lines with an amount: nothing (the net product base is unchanged).
	 * - Otherwise (amount-only refund, or lines with 0 total, e.g. only a restock quantity
	 *   with a manually typed amount): the amount without its tax share, pro rata over the net
	 *   line totals (not over the gross order total with shipping, fees and gift cards).
	 * Amounts are scaled like the commission base of the line (base_lines()).
	 *
	 * @param \WC_Order        $order  Order.
	 * @param \WC_Order_Refund $refund Refund.
	 * @return array
	 */
	public static function refund_lines( $order, $refund ) {
		$currency = $order->get_currency();
		$base     = self::base_lines( $order );
		$lines    = $base['lines'];
		$sums     = array();
		foreach ( $refund->get_items( 'line_item' ) as $refund_item ) {
			$original = (int) $refund_item->get_meta( '_refunded_item_id' );
			$original = isset( $base['parent'][ $original ] ) ? (int) $base['parent'][ $original ] : $original;
			$net      = abs( Money::to_minor( (string) $refund_item->get_total(), $currency ) );
			if ( ! $original || $net <= 0 || ! isset( $lines[ $original ] ) ) {
				continue; // No commission line (e.g. a gift card).
			}
			$line = $lines[ $original ];
			if ( $line['raw'] > 0 && $line['net'] !== $line['raw'] ) {
				$net = Money::prorate( $net, $line['net'], $line['raw'] );
			}
			$sums[ $original ] = ( isset( $sums[ $original ] ) ? $sums[ $original ] : 0 ) + $net;
		}
		$has_lines = false;
		foreach ( $refund->get_items( 'line_item' ) as $refund_item ) {
			if ( 0 !== self::item_amount( $refund_item, $currency ) ) {
				$has_lines = true;
			}
		}
		$items = array();
		foreach ( $sums as $item_id => $net ) {
			if ( $net > 0 ) {
				$items[] = array(
					'item_id'   => (string) $item_id,
					'net_minor' => $net,
				);
			}
		}
		if ( $items || $has_lines ) {
			return $items; // Position-exact (lines without commission give nothing).
		}
		foreach ( $refund->get_items( array( 'shipping', 'fee', 'tax' ) ) as $other ) {
			if ( 0 !== self::item_amount( $other, $currency ) ) {
				return array(); // Only shipping/fee/tax refunded.
			}
		}
		$refunded = abs( Money::to_minor( (string) $refund->get_amount(), $currency ) );
		$raw      = 0;
		$gross    = 0;
		foreach ( $lines as $line ) {
			$raw   += max( 0, $line['raw'] );
			$gross += max( 0, $line['raw'] + $line['tax'] );
		}
		if ( $raw <= 0 || $refunded <= 0 || $gross <= 0 ) {
			return array();
		}
		// Refunded amounts include tax: the net share of the product lines, at most all of it.
		$net_refund = min( $raw, Money::prorate( $refunded, $raw, $gross ) );
		foreach ( $lines as $item_id => $line ) {
			$items[] = array(
				'item_id'   => (string) $item_id,
				'net_minor' => Money::prorate( max( 0, $line['net'] ), $net_refund, $raw ),
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
		self::full_reversal( $order_id, 'refund', __( 'Order refunded', 'affiliate-captain' ) );
	}

	/**
	 * Order cancelled.
	 *
	 * @param int $order_id Order.
	 */
	public static function full_reversal_cancelled( $order_id ) {
		self::full_reversal( $order_id, 'cancellation', __( 'Order cancelled', 'affiliate-captain' ) );
	}

	/**
	 * Paid statuses of WooCommerce (incl. custom ones registered via woocommerce_order_is_paid_statuses).
	 *
	 * @return string[]
	 */
	public static function paid_statuses() {
		$paid = function_exists( 'wc_get_is_paid_statuses' ) ? (array) wc_get_is_paid_statuses() : array( 'processing', 'completed' );
		return array_map( 'sanitize_key', $paid );
	}

	/**
	 * Statuses treated as chargeback (filter wpac_wc_chargeback_statuses).
	 *
	 * @return string[]
	 */
	public static function chargeback_statuses() {
		// Default: "failed" after the order was paid (payment reversed/disputed by the gateway).
		return array_map( 'sanitize_key', (array) apply_filters( 'wpac_wc_chargeback_statuses', array( 'failed' ) ) );
	}

	/**
	 * Status change: treat configured statuses as chargeback; a change back to a paid status
	 * reinstates a reversed commission and books commission for custom paid statuses.
	 *
	 * @param int    $order_id Order.
	 * @param string $from     Old status.
	 * @param string $to       New status.
	 */
	public static function maybe_chargeback( $order_id, $from, $to ) {
		$from = sanitize_key( $from );
		$to   = sanitize_key( $to );
		$paid = self::paid_statuses();
		if ( in_array( $to, $paid, true ) ) {
			// Back to a paid status from any other (cancelled, failed, refunded, on hold, a
			// chargeback status of the filter, trash): a chargeback won or a cancellation reversed
			// – the reversed commission is reinstated (does nothing when nothing was reversed).
			if ( ! in_array( $from, $paid, true ) ) {
				self::reinstate( $order_id );
			}
			// Custom paid statuses have no own action hook: book the sale here (idempotent).
			self::sync_order( $order_id );
			return;
		}
		// Commissions only exist for paid orders, so this never affects unpaid ones.
		if ( in_array( $to, self::chargeback_statuses(), true ) ) {
			self::full_reversal_chargeback( $order_id );
		}
	}

	/**
	 * Hold back the release of commissions while the order is on hold or in a chargeback status
	 * (PayPal/Stripe put disputed orders on hold). Filter wpac_hold_release.
	 *
	 * @param bool   $hold     Hold back.
	 * @param string $source   Source.
	 * @param string $order_id Order.
	 * @return bool
	 */
	public static function hold_release( $hold, $source, $order_id ) {
		if ( $hold || WooCommerce::SOURCE !== $source ) {
			return $hold;
		}
		$order = wc_get_order( (int) $order_id );
		if ( ! $order ) {
			return $hold;
		}
		/**
		 * WooCommerce statuses during which commissions are not released (default: on-hold and
		 * the chargeback statuses).
		 *
		 * @param string[] $statuses Statuses.
		 */
		$statuses = array_map( 'sanitize_key', (array) apply_filters( 'wpac_wc_hold_statuses', array_merge( array( 'on-hold' ), self::chargeback_statuses() ) ) );
		return in_array( sanitize_key( $order->get_status() ), $statuses, true );
	}

	/**
	 * Setting "hold period starts when the order is completed": until then the commission is
	 * held (placeholder far in the future), afterwards the hold counts from the completion date.
	 *
	 * @param int   $hold Hold-until timestamp.
	 * @param array $sale Normalized sale.
	 * @return int
	 */
	public static function hold_until( $hold, $sale ) {
		if ( ! is_array( $sale ) || ! isset( $sale['source'] ) || WooCommerce::SOURCE !== $sale['source'] || 'completed' !== Settings::get( 'hold_start' ) ) {
			return $hold;
		}
		$order = wc_get_order( (int) $sale['order_id'] );
		if ( ! $order ) {
			return $hold;
		}
		if ( ! $order->has_status( 'completed' ) ) {
			return max( (int) $hold, self::HOLD_OPEN );
		}
		$done = $order->get_date_completed();
		$from = $done ? $done->getTimestamp() : time();
		return max( (int) $hold, $from + DAY_IN_SECONDS * (int) Settings::get( 'hold_days' ) );
	}

	/**
	 * Order completed: with the setting "hold period starts when completed" the hold of its open
	 * commissions starts now.
	 *
	 * @param int $order_id Order.
	 */
	public static function order_completed( $order_id ) {
		if ( 'completed' !== Settings::get( 'hold_start' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$done = $order->get_date_completed();
		Ledger::restart_hold( WooCommerce::SOURCE, (string) $order_id, $done ? $done->getTimestamp() : time() );
	}

	/**
	 * Chargeback status (configured via the wpac_wc_chargeback_statuses filter).
	 *
	 * @param int $order_id Order.
	 */
	public static function full_reversal_chargeback( $order_id ) {
		self::full_reversal( $order_id, 'chargeback', __( 'Chargeback', 'affiliate-captain' ) );
	}

	/**
	 * Order moved to the trash: its commission is reversed (test and fraud orders are usually
	 * deleted, not cancelled). Restoring the order reinstates it.
	 *
	 * @param int $order_id Order.
	 */
	public static function order_trashed( $order_id ) {
		self::full_reversal( $order_id, 'cancellation', __( 'Order moved to the trash', 'affiliate-captain' ), 'trash' );
	}

	/**
	 * Order deleted permanently: its commission is reversed.
	 *
	 * @param int $order_id Order.
	 */
	public static function order_deleted( $order_id ) {
		self::full_reversal( $order_id, 'cancellation', __( 'Order deleted', 'affiliate-captain' ), 'deleted' );
	}

	/**
	 * Order restored from the trash: reinstate when it is paid.
	 *
	 * @param int $order_id Order.
	 */
	public static function order_untrashed( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order && $order->is_paid() ) {
			self::reinstate( $order_id );
		}
	}

	/**
	 * Classic order storage: trash/untrash/delete arrive as post hooks.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function post_trashed( $post_id ) {
		if ( self::is_order_post( $post_id ) ) {
			self::order_trashed( (int) $post_id );
		}
	}

	/**
	 * Classic order storage: post restored from the trash.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function post_untrashed( $post_id ) {
		if ( self::is_order_post( $post_id ) ) {
			self::order_untrashed( (int) $post_id );
		}
	}

	/**
	 * Classic order storage: post deleted.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function post_deleted( $post_id ) {
		if ( self::is_order_post( $post_id ) ) {
			self::order_deleted( (int) $post_id );
		}
	}

	/**
	 * Whether a post is a WooCommerce order (classic storage).
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private static function is_order_post( $post_id ) {
		return function_exists( 'get_post_type' ) && 'shop_order' === get_post_type( $post_id );
	}

	/**
	 * Line items of a paid, attributed order were changed in the admin (quantity changed, line
	 * added or removed, price changed): the commission follows lower amounts with a correction
	 * and higher amounts with an additional booking (Ledger::record_increase()).
	 *
	 * @param int $order_id Order.
	 */
	public static function items_changed( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || is_a( $order, 'WC_Order_Refund' ) || ! $order->is_paid() ) {
			return;
		}
		$attr = Ledger::attribution_for( WooCommerce::SOURCE, (string) $order_id );
		if ( ! $attr || Jobs::refunds_pending( WooCommerce::SOURCE, (string) $order_id ) ) {
			return; // Waiting refunds would be deducted twice; the next save catches up.
		}
		$payload = self::payload( $order );
		$targets = array();
		foreach ( $payload['items'] as $item ) {
			$targets[ $item['item_id'] ] = (int) $item['net_minor'];
		}
		$rows = array();
		foreach ( Ledger::sale_commissions( WooCommerce::SOURCE, (string) $order_id ) as $c ) {
			if ( (int) $c->affiliate_id === (int) $attr->affiliate_id && 'rejected' !== $c->status && ( ! isset( $rows[ $c->source_item_id ] ) || (int) $c->id > (int) $rows[ $c->source_item_id ]->id ) ) {
				$rows[ (string) $c->source_item_id ] = $c;
			}
		}
		$lines  = array();
		$booked = array();
		foreach ( $rows as $item_id => $c ) {
			$base     = (int) $c->base_minor;
			$snap     = $c->rule_snapshot ? json_decode( (string) $c->rule_snapshot, true ) : null;
			$line_net = is_array( $snap ) && ! empty( $snap['line_net'] ) ? (int) $snap['line_net'] : $base;
			if ( $base <= 0 || $line_net <= 0 ) {
				continue;
			}
			// Booked for the line: the original base plus later increases, minus refunds.
			$remaining          = $base + Ledger::increased( (int) $c->id )['base'] - Ledger::refunded_base( (int) $c->id );
			$booked[ $item_id ] = $remaining;
			$target             = isset( $targets[ $item_id ] ) ? Money::prorate( $targets[ $item_id ], $base, $line_net ) : 0;
			if ( $remaining > $target ) {
				$lines[] = array(
					'item_id'   => (string) $item_id,
					'net_minor' => Money::prorate( $remaining - $target, $line_net, $base ),
				);
			}
		}
		if ( $lines ) {
			$result = ContractApi::record_refund(
				array(
					'contract'  => WPAC_CONTRACT_VERSION,
					'source'    => WooCommerce::SOURCE,
					'order_id'  => (string) $order_id,
					// The booked state is part of the ID: lowering to the same amounts again after an
					// increase is a new correction, saving twice is not.
					'refund_id' => 'edit-' . substr( md5( (string) wp_json_encode( array( $targets, $booked ) ) ), 0, 12 ),
					'type'      => 'refund',
					'items'     => $lines,
					'reason'    => __( 'Order items changed after payment', 'affiliate-captain' ),
				)
			);
			if ( is_wp_error( $result ) ) {
				self::report_error( $order, $result, 'refund' );
			} elseif ( ! empty( $result['adjustment_ids'] ) ) {
				$order->add_order_note( __( 'Affiliate Captain: the partner commission was reduced because the order items were changed.', 'affiliate-captain' ) );
			}
		}
		self::book_increase( $order, $payload );
	}

	/**
	 * Refunds deleted in this request (several hooks report the same deletion).
	 *
	 * @var array<int, bool>
	 */
	private static $deleted_refunds = array();

	/**
	 * Parent orders of refunds about to be deleted (captured before the deletion, read after it).
	 *
	 * @var array<int, int>
	 */
	private static $deleting_refunds = array();

	/**
	 * Orders being deleted in this request: their refunds go with them (no reinstatement).
	 *
	 * @var array<int, bool>
	 */
	private static $deleting_orders = array();

	/**
	 * A refund was deleted (order edit screen, REST API, ERP/POS connectors through the CRUD
	 * classes): the deduction booked for it is given back exactly as it was booked
	 * (Ledger::reverse_refund(), from the stored refund rows – also for fixed rates per piece),
	 * idempotent per refund, so several hooks for the same deletion book it once. A refund still
	 * waiting in the job queue is closed instead. The order note says what happened.
	 *
	 * Hooks: woocommerce_refund_deleted (admin screen), woocommerce_order_refund_deleted
	 * (WooCommerce 11.3+, both order storages), and woocommerce_delete_order_refund /
	 * deleted_post with the parent captured before the deletion (older versions).
	 *
	 * @param int $refund_id Deleted refund.
	 * @param int $order_id  Order.
	 */
	public static function refund_deleted( $refund_id, $order_id ) {
		$refund_id = (int) $refund_id;
		$order_id  = (int) $order_id;
		if ( ! $refund_id || ! $order_id || isset( self::$deleted_refunds[ $refund_id ] ) || isset( self::$deleting_orders[ $order_id ] ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || is_a( $order, 'WC_Order_Refund' ) ) {
			return;
		}
		self::$deleted_refunds[ $refund_id ] = true;
		Jobs::supersede_refunds( WooCommerce::SOURCE, (string) $order_id, array( (string) $refund_id ) );
		if ( ! Ledger::sale_commissions( WooCommerce::SOURCE, (string) $order_id ) ) {
			return; // No commission for this order: nothing was deducted.
		}
		/* translators: %s: refund ID */
		$reason = sprintf( __( 'Refund #%s deleted in WooCommerce', 'affiliate-captain' ), (string) $refund_id );
		try {
			$result = Ledger::reverse_refund( WooCommerce::SOURCE, (string) $order_id, (string) $refund_id, $reason );
			$extra  = null;
			if ( $result['reduced'] && $order->is_paid() ) {
				// The refund took back an increase that was not released yet (R13-K4): the order
				// without it is the target again (Ledger::record_increase(), at the rate of the sale).
				$payload = self::payload( $order );
				if ( $payload['items'] ) {
					$sale  = ContractApi::normalize_sale( $payload );
					$extra = Ledger::record_increase(
						array(
							'contract' => $sale['contract'],
							'source'   => WooCommerce::SOURCE,
							'order_id' => (string) $order_id,
							'currency' => $sale['currency'],
							'items'    => $sale['items'],
							'reason'   => $reason,
						)
					);
				}
			}
		} catch ( \Throwable $e ) {
			unset( self::$deleted_refunds[ $refund_id ] );
			Log::error(
				'WooCommerce refund deletion not booked',
				array(
					'order'   => $order_id,
					'refund'  => $refund_id,
					'message' => \WPAffiliateCaptain\Core\Db::cut( $e->getMessage(), 300 ),
				)
			);
			$order->add_order_note( __( 'Affiliate Captain: a refund was deleted, but the partner commission could not be restored. Please save the order items again later.', 'affiliate-captain' ) );
			return;
		}
		$amount = (int) $result['amount_minor'] + ( $extra && 'booked' === $extra['status'] ? (int) $extra['amount_minor'] : 0 );
		if ( $amount > 0 ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: refund ID, 2: commission amount */
					__( 'Affiliate Captain: refund #%1$s was deleted; the partner commission deducted for it (%2$s) was booked again.', 'affiliate-captain' ),
					(string) $refund_id,
					Money::format( $amount, (string) $order->get_currency() )
				)
			);
		} elseif ( 'skipped' === $result['status'] || ( $extra && 'skipped' === $extra['status'] ) ) {
			$order->add_order_note( __( 'Affiliate Captain: a refund was deleted; the partner commission was not restored automatically (commission under review, rejected or reversed). If the partner should get it back, book a correction (Affiliate Captain → Commissions → commission → Correction).', 'affiliate-captain' ) );
		} elseif ( 'none' === $result['status'] && ! $result['reduced'] ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: refund ID */
					__( 'Affiliate Captain: refund #%s was deleted; no partner commission had been deducted for it, so nothing was booked again.', 'affiliate-captain' ),
					(string) $refund_id
				)
			);
		}
	}

	/**
	 * WooCommerce 11.3+: woocommerce_order_refund_deleted (order ID first; not fired while the
	 * order itself is deleted).
	 *
	 * @param int $order_id  Order.
	 * @param int $refund_id Deleted refund.
	 */
	public static function order_refund_deleted( $order_id, $refund_id ) {
		self::refund_deleted( (int) $refund_id, (int) $order_id );
	}

	/**
	 * Filter woocommerce_pre_delete_order_refund (WooCommerce 8.1+, any order storage): remember
	 * the parent order of a refund about to be deleted; booked once it is gone. Returns the
	 * filter value unchanged.
	 *
	 * @param mixed  $check  Null to go ahead with the deletion.
	 * @param object $refund Refund.
	 * @return mixed
	 */
	public static function before_refund_delete( $check, $refund = null ) {
		if ( null === $check && is_object( $refund ) && method_exists( $refund, 'get_id' ) && method_exists( $refund, 'get_parent_id' ) && $refund->get_id() ) {
			self::$deleting_refunds[ (int) $refund->get_id() ] = (int) $refund->get_parent_id();
		}
		return $check;
	}

	/**
	 * Classic order storage (also wp_delete_post() on a refund): remember the parent order of a
	 * refund post about to be deleted, and mark orders being deleted (their refunds go with them).
	 *
	 * @param int $post_id Post ID.
	 */
	public static function before_post_delete( $post_id ) {
		if ( ! function_exists( 'get_post_type' ) ) {
			return;
		}
		$type = get_post_type( $post_id );
		if ( 'shop_order_refund' === $type && function_exists( 'wp_get_post_parent_id' ) ) {
			self::$deleting_refunds[ (int) $post_id ] = (int) wp_get_post_parent_id( $post_id );
		} elseif ( 'shop_order' === $type ) {
			self::$deleting_orders[ (int) $post_id ] = true;
		}
	}

	/**
	 * An order is about to be deleted (HPOS): its refunds are deleted with it.
	 *
	 * @param int $order_id Order.
	 */
	public static function order_deleting( $order_id ) {
		self::$deleting_orders[ (int) $order_id ] = true;
	}

	/**
	 * A refund was deleted (woocommerce_delete_order_refund, deleted_post): book it with the
	 * parent order captured before the deletion.
	 *
	 * @param int $refund_id Deleted refund (or post).
	 */
	public static function refund_gone( $refund_id ) {
		$refund_id = (int) $refund_id;
		if ( ! empty( self::$deleting_refunds[ $refund_id ] ) ) {
			self::refund_deleted( $refund_id, self::$deleting_refunds[ $refund_id ] );
		}
	}

	/**
	 * Book raised and added lines of a paid order (Ledger::record_increase()) and say in an
	 * order note what was booked, or why nothing was.
	 *
	 * @param \WC_Order $order   Order.
	 * @param array     $payload Sale payload of the order as it is now (payload()).
	 */
	private static function book_increase( $order, array $payload ) {
		if ( ! $payload['items'] ) {
			return;
		}
		try {
			$sale   = ContractApi::normalize_sale( $payload );
			$result = Ledger::record_increase(
				array(
					'contract' => $sale['contract'],
					'source'   => WooCommerce::SOURCE,
					'order_id' => (string) $order->get_id(),
					'currency' => $sale['currency'],
					'items'    => $sale['items'],
					'reason'   => __( 'Order items increased after payment', 'affiliate-captain' ),
				)
			);
		} catch ( \Throwable $e ) {
			Log::error(
				'WooCommerce increase not booked',
				array(
					'order'   => (int) $order->get_id(),
					'message' => \WPAffiliateCaptain\Core\Db::cut( $e->getMessage(), 300 ),
				)
			);
			$order->add_order_note( __( 'Affiliate Captain: the order items were increased after payment, but the additional partner commission could not be booked. Please save the order items again later.', 'affiliate-captain' ) );
			return;
		}
		if ( 'booked' === $result['status'] ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: commission amount */
					__( 'Affiliate Captain: the order items were increased after payment; an additional partner commission of %s was booked (released after the hold period of the order).', 'affiliate-captain' ),
					Money::format( (int) $result['amount_minor'], (string) $payload['currency'] )
				)
			);
		} elseif ( 'skipped' === $result['status'] ) {
			$order->add_order_note( __( 'Affiliate Captain: the order items were increased after payment; the partner commission was not raised automatically. If the partner should earn more, book a correction (Affiliate Captain → Commissions → commission → Correction).', 'affiliate-captain' ) );
		}
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
						? __( 'Affiliate Captain: the partner commission could not be reinstated yet and will be retried automatically (Affiliate Captain → Integrations).', 'affiliate-captain' )
						: __( 'Affiliate Captain: the partner commission could not be reinstated. Please change the order status again later.', 'affiliate-captain' )
				);
			}
		}
	}

	/**
	 * Order note when a refund was deducted although the credit note of the commission already
	 * has a correction credit note (R12-M2, hook wpac_refund_after_correction).
	 *
	 * @param array  $refund      Normalized refund.
	 * @param string $credit_note Credit note number.
	 * @param array  $corrections Correction credit note numbers.
	 */
	public static function refund_after_correction( $refund, $credit_note, $corrections ) {
		if ( ! is_array( $refund ) || WooCommerce::SOURCE !== (string) $refund['source'] || ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( (int) $refund['order_id'] );
		if ( ! $order ) {
			return;
		}
		/* translators: 1: credit note number, 2: correction credit note numbers */
		$order->add_order_note( sprintf( __( 'Affiliate Captain: the refund was deducted from the partner commission, but credit note %1$s already has correction credit note(s) %2$s. Please check whether the correction already covered this order (Affiliate Captain → Payouts); otherwise the partner is charged twice.', 'affiliate-captain' ), (string) $credit_note, implode( ', ', (array) $corrections ) ) );
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
		$n = Ledger::reinstate( WooCommerce::SOURCE, (string) $order_id, __( 'Order paid again (chargeback won or cancellation reversed)', 'affiliate-captain' ) );
		if ( $n ) {
			$order->update_meta_data( '_wpac_reinstated', (int) $order->get_meta( '_wpac_reinstated' ) + 1 );
			$order->save_meta_data();
			$order->add_order_note( __( 'Partner commission reinstated.', 'affiliate-captain' ) );
			// Money that really went back stays deducted: a full refund sets the status "refunded"
			// first (full reversal) and its refund lines found nothing left to deduct. Apply the
			// refunds of the order now (idempotent per refund).
			foreach ( $order->get_refunds() as $refund ) {
				self::sync_refund( (int) $order_id, (int) $refund->get_id() );
			}
		}
	}

	/**
	 * Reverse all remaining commission of an order.
	 *
	 * @param int    $order_id Order.
	 * @param string $type     Type.
	 * @param string $reason   Reason.
	 * @param string $event    Event name in the refund ID (default: the type).
	 */
	private static function full_reversal( $order_id, $type, $reason, $event = '' ) {
		if ( ! Ledger::sale_commissions( WooCommerce::SOURCE, (string) $order_id ) && ! Jobs::sale_pending( WooCommerce::SOURCE, (string) $order_id ) ) {
			return;
		}
		// After a reinstatement a new reversal of the same kind is a new event (own key).
		$order  = wc_get_order( $order_id );
		$round  = $order ? (int) $order->get_meta( '_wpac_reinstated' ) : 0;
		$result = ContractApi::record_refund(
			array(
				'contract'  => WPAC_CONTRACT_VERSION,
				'source'    => WooCommerce::SOURCE,
				'order_id'  => (string) $order_id,
				'refund_id' => 'status-' . ( '' !== $event ? $event : $type ) . ( $round ? '-' . $round : '' ),
				'type'      => $type,
				'full'      => true,
				'reason'    => $reason,
			)
		);
		if ( $order && is_wp_error( $result ) ) {
			self::report_error( $order, $result, 'refund' );
		}
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
			$sale = ContractApi::normalize_sale( apply_filters( 'wpac_wc_sale_payload', self::payload( $order ), $order ) );
			// These refunds are already deducted from the rebuilt sale: their queued refund jobs
			// must not deduct them a second time (after_booking()).
			ContractApi::after_booking( $sale, Ledger::record_sale( $sale ) );
			Jobs::supersede_refunds( WooCommerce::SOURCE, (string) $order->get_id(), (array) $sale['netted_refund_ids'] );
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
