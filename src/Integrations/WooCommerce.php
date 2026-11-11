<?php
/**
 * WooCommerce sales adapter (HPOS + classic/blocks checkout).
 *
 * Reads orders, items, coupons and refunds exclusively through WooCommerce CRUD
 * objects and hooks and reports normalized sales/refunds via the public
 * contract. WooCommerce stays the owner of orders, payments and discounts.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Integrations;

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

defined( 'ABSPATH' ) || exit;

final class WooCommerce {

	const SOURCE       = 'woocommerce';
	const META_CODE    = '_wpac_partner_code';
	const META_TOUCH   = '_wpac_touch';
	const META_MANUAL  = '_wpac_manual_affiliate';
	const BLOCKS_FIELD = 'wp-affiliate-captain/partner-code';

	public static function register_hooks() {
		// Checkout field.
		if ( Settings::get( 'checkout_field' ) ) {
			add_action( 'woocommerce_after_order_notes', array( __CLASS__, 'classic_field' ) );
			add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'classic_validate' ), 10, 2 );
			add_action( 'woocommerce_init', array( __CLASS__, 'register_blocks_field' ) );
		}
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'classic_save' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'blocks_save' ), 10, 1 );
		add_action( 'woocommerce_checkout_order_processed', array( Tracker::class, 'clear_touch' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( Tracker::class, 'clear_touch' ) );

		// Sales and refunds.
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'sync_order' ) );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'sync_order' ) );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'sync_order' ) );
		add_action( 'woocommerce_order_refunded', array( __CLASS__, 'sync_refund' ), 10, 2 );
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'full_reversal_refunded' ) );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'full_reversal_cancelled' ) );
		// Evaluated at status change time, so filters from themes/snippets (loaded later) apply.
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'maybe_chargeback' ), 10, 3 );
		add_filter( 'wpac_run_job', array( __CLASS__, 'run_job' ), 10, 3 );

		// Admin: order meta box and coupon ↔ affiliate link.
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'save_meta_box' ) );
		add_action( 'woocommerce_coupon_options', array( __CLASS__, 'coupon_field' ), 10, 2 );
		add_action( 'woocommerce_coupon_options_save', array( __CLASS__, 'coupon_save' ), 10, 2 );
	}

	/* ------------------------------------------------------------------
	 * Checkout
	 * ---------------------------------------------------------------- */

	/**
	 * Code from the current referral touch (to prefill the field).
	 *
	 * @return string
	 */
	private static function prefill() {
		$touch = Tracker::current_touch();
		return $touch ? (string) $touch['code'] : '';
	}

	/**
	 * Classic checkout field.
	 *
	 * @param \WC_Checkout $checkout Checkout.
	 */
	public static function classic_field( $checkout ) {
		woocommerce_form_field(
			'wpac_partner_code',
			array(
				'type'        => 'text',
				'label'       => __( 'Partner code (optional)', 'wp-affiliate-captain' ),
				'placeholder' => __( 'e.g. from your sailing school', 'wp-affiliate-captain' ),
				'required'    => false,
				'class'       => array( 'form-row-wide' ),
			),
			$checkout->get_value( 'wpac_partner_code' ) ? $checkout->get_value( 'wpac_partner_code' ) : self::prefill()
		);
	}

	/**
	 * Validate the classic field.
	 *
	 * @param array     $data   Posted data.
	 * @param \WP_Error $errors Errors.
	 */
	public static function classic_validate( $data, $errors ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the checkout nonce.
		$code = isset( $_POST['wpac_partner_code'] ) ? sanitize_text_field( wp_unslash( $_POST['wpac_partner_code'] ) ) : '';
		if ( '' !== $code && ! wpac_resolve_partner_code( $code, array( 'context' => 'checkout' ) ) ) {
			$errors->add( 'wpac_partner_code', __( 'The partner code is not valid.', 'wp-affiliate-captain' ) );
		}
	}

	/**
	 * Persist code + touch on classic checkout.
	 *
	 * @param \WC_Order $order Order.
	 * @param array     $data  Data.
	 */
	public static function classic_save( $order, $data ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$code = isset( $_POST['wpac_partner_code'] ) ? CodeRepository::normalize( sanitize_text_field( wp_unslash( $_POST['wpac_partner_code'] ) ) ) : '';
		if ( '' !== $code ) {
			$order->update_meta_data( self::META_CODE, $code );
		}
		self::save_touch( $order );
	}

	/**
	 * Blocks checkout: additional field (WooCommerce 8.9+).
	 */
	public static function register_blocks_field() {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}
		woocommerce_register_additional_checkout_field(
			array(
				'id'                => self::BLOCKS_FIELD,
				'label'             => __( 'Partner code (optional)', 'wp-affiliate-captain' ),
				'optionalLabel'     => __( 'Partner code (optional)', 'wp-affiliate-captain' ),
				// "order" replaced "additional" in newer WooCommerce versions (same meta key _wc_other/...).
				'location'          => ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '9.2', '<' ) ) ? 'additional' : 'order',
				'type'              => 'text',
				'required'          => false,
				'sanitize_callback' => static function ( $value ) {
					return CodeRepository::normalize( (string) $value );
				},
				'validate_callback' => static function ( $value ) {
					if ( '' !== (string) $value && ! wpac_resolve_partner_code( (string) $value, array( 'context' => 'checkout' ) ) ) {
						return new \WP_Error( 'wpac_partner_code', __( 'The partner code is not valid.', 'wp-affiliate-captain' ) );
					}
					return true;
				},
			)
		);
	}

	/**
	 * Blocks checkout: store referral touch on the order.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function blocks_save( $order ) {
		self::save_touch( $order );
	}

	/**
	 * Snapshot of the session/cookie touch on the order.
	 *
	 * @param \WC_Order $order Order.
	 */
	private static function save_touch( $order ) {
		$touch = Tracker::current_touch();
		if ( $touch ) {
			$order->update_meta_data(
				self::META_TOUCH,
				array(
					'code'       => (string) $touch['code'],
					'method'     => (string) $touch['method'],
					'campaign'   => isset( $touch['campaign'] ) ? (string) $touch['campaign'] : '',
					'content_id' => isset( $touch['content_id'] ) ? (int) $touch['content_id'] : 0,
					'ts'         => isset( $touch['ts'] ) ? (int) $touch['ts'] : 0,
				)
			);
		}
	}

	/**
	 * Partner code entered at checkout (classic or blocks).
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	private static function order_code( $order ) {
		$code = (string) $order->get_meta( self::META_CODE );
		if ( '' === $code ) {
			$code = (string) $order->get_meta( '_wc_other/' . self::BLOCKS_FIELD );
		}
		return $code;
	}

	/* ------------------------------------------------------------------
	 * Sales sync
	 * ---------------------------------------------------------------- */

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

		$touch  = $order->get_meta( self::META_TOUCH );
		$paid   = $order->get_date_paid() ? $order->get_date_paid() : $order->get_date_created();
		$manual = (int) $order->get_meta( self::META_MANUAL );

		return array(
			'contract'     => WPAC_CONTRACT_VERSION,
			'source'       => self::SOURCE,
			'order_id'     => (string) $order->get_id(),
			'currency'     => $currency,
			'paid_at'      => $paid ? $paid->getTimestamp() : time(),
			'items'        => $items,
			'coupon_codes' => $order->get_coupon_codes(),
			'partner_code' => self::order_code( $order ),
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
		if ( Ledger::attribution_for( self::SOURCE, (string) $order->get_id() ) ) {
			return;
		}
		// Already resolved without a partner (payment_complete + processing + completed fire for the same order).
		if ( 'none' === $order->get_meta( '_wpac_resolved' ) && ! $order->get_meta( self::META_MANUAL ) ) {
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
			$attr = Ledger::attribution_for( self::SOURCE, (string) $order->get_id() );
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
		if ( ! $order || ! $refund || ! Ledger::sale_commissions( self::SOURCE, (string) $order_id ) ) {
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
				'source'    => self::SOURCE,
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
		if ( ! Ledger::sale_commissions( self::SOURCE, (string) $order_id ) ) {
			return;
		}
		ContractApi::record_refund(
			array(
				'contract'  => WPAC_CONTRACT_VERSION,
				'source'    => self::SOURCE,
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
			if ( ! $order || ! $order->is_paid() || Ledger::attribution_for( self::SOURCE, (string) $order->get_id() ) ) {
				return true; // Nothing (left) to do.
			}
			// Rebuilt from the current order (incl. refunds so far); exceptions propagate to the job runner.
			Ledger::record_sale( ContractApi::normalize_sale( apply_filters( 'wpac_wc_sale_payload', self::payload( $order ), $order ) ) );
			return true;
		}
		return $handled;
	}

	/* ------------------------------------------------------------------
	 * Admin
	 * ---------------------------------------------------------------- */

	public static function add_meta_box() {
		if ( ! current_user_can( Capabilities::MANAGE_AFFILIATES ) ) {
			return;
		}
		$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		add_meta_box( 'wpac-order', __( 'Affiliate Captain', 'wp-affiliate-captain' ), array( __CLASS__, 'render_meta_box' ), $screen, 'side', 'default' );
	}

	/**
	 * Meta box output.
	 *
	 * @param \WP_Post|\WC_Order $post_or_order Post or order.
	 */
	public static function render_meta_box( $post_or_order ) {
		$order = $post_or_order instanceof \WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			return;
		}
		$attr = Ledger::attribution_for( self::SOURCE, (string) $order->get_id() );
		if ( $attr ) {
			$aff = AffiliateRepository::find( (int) $attr->affiliate_id );
			list( $rows ) = Ledger::query(
				array(
					'source'   => self::SOURCE,
					'order_id' => (string) $order->get_id(),
					'limit'    => 0,
				)
			);
			$sum = 0;
			foreach ( $rows as $r ) {
				if ( 'rejected' !== $r->status ) {
					$sum += (int) $r->amount_minor;
				}
			}
			echo '<p><strong>' . esc_html( $aff ? $aff->name : '#' . $attr->affiliate_id ) . '</strong><br>';
			echo esc_html__( 'Method', 'wp-affiliate-captain' ) . ': ' . esc_html( $attr->method ) . '<br>';
			echo esc_html__( 'Commission', 'wp-affiliate-captain' ) . ': ' . esc_html( Money::format( $sum, $order->get_currency() ) ) . '</p>';
			echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=wpac-commissions&order_id=' . $order->get_id() . '&source=' . self::SOURCE ) ) . '">' . esc_html__( 'View commissions', 'wp-affiliate-captain' ) . '</a></p>';
			return;
		}

		$code = self::order_code( $order );
		if ( $code ) {
			/* translators: %s: code */
			echo '<p>' . esc_html( sprintf( __( 'Partner code entered: %s', 'wp-affiliate-captain' ), $code ) ) . '</p>';
		}
		echo '<p>' . esc_html__( 'Not attributed.', 'wp-affiliate-captain' ) . '</p>';
		list( $affiliates ) = AffiliateRepository::query(
			array(
				'status' => 'active',
				'limit'  => 500,
			)
		);
		wp_nonce_field( 'wpac_order_assign', '_wpac_order_nonce' );
		echo '<p><label for="wpac_assign">' . esc_html__( 'Assign manually', 'wp-affiliate-captain' ) . '</label><select name="wpac_assign" id="wpac_assign" style="width:100%"><option value="">—</option>';
		foreach ( $affiliates as $a ) {
			echo '<option value="' . (int) $a->id . '">' . esc_html( $a->name . ' (' . $a->slug . ')' ) . '</option>';
		}
		echo '</select></p><p><label for="wpac_assign_reason">' . esc_html__( 'Reason (required)', 'wp-affiliate-captain' ) . '</label><input type="text" name="wpac_assign_reason" id="wpac_assign_reason" style="width:100%"></p>';
		echo '<p class="description">' . esc_html__( 'Commission is created once the order is paid.', 'wp-affiliate-captain' ) . '</p>';
	}

	/**
	 * Save manual assignment.
	 *
	 * @param int $order_id Order.
	 */
	public static function save_meta_box( $order_id ) {
		if ( empty( $_POST['wpac_assign'] ) || ! isset( $_POST['_wpac_order_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpac_order_nonce'] ) ), 'wpac_order_assign' ) ) {
			return;
		}
		if ( ! current_user_can( Capabilities::MANAGE_AFFILIATES ) ) {
			return;
		}
		$aff_id = absint( $_POST['wpac_assign'] );
		$reason = isset( $_POST['wpac_assign_reason'] ) ? sanitize_text_field( wp_unslash( $_POST['wpac_assign_reason'] ) ) : '';
		$order  = wc_get_order( $order_id );
		if ( ! $order || '' === $reason || Ledger::attribution_for( self::SOURCE, (string) $order_id ) ) {
			return;
		}
		$order->update_meta_data( self::META_MANUAL, $aff_id );
		$order->save_meta_data();
		Audit::log(
			'attribution.manual',
			'order',
			(int) $order_id,
			array(
				'affiliate_id' => $aff_id,
				'reason'       => $reason,
			)
		);
		if ( $order->is_paid() ) {
			self::sync_order( $order_id );
		}
	}

	/**
	 * Coupon edit screen: link coupon to an affiliate.
	 *
	 * @param int        $coupon_id Coupon ID.
	 * @param \WC_Coupon $coupon    Coupon.
	 */
	public static function coupon_field( $coupon_id, $coupon ) {
		if ( ! current_user_can( Capabilities::MANAGE_AFFILIATES ) ) {
			return;
		}
		$row     = CodeRepository::find_by_coupon( $coupon->get_code(), true );
		$current = $row ? (int) $row->affiliate_id : 0;
		list( $affiliates ) = AffiliateRepository::query(
			array(
				'status' => 'active',
				'limit'  => 500,
			)
		);
		$options = array( '' => '—' );
		foreach ( $affiliates as $a ) {
			$options[ (int) $a->id ] = $a->name . ' (' . $a->slug . ')';
		}
		// Keep the current partner selectable even if paused, so saving the coupon never unlinks it by accident.
		if ( $current && ! isset( $options[ $current ] ) ) {
			$aff                 = AffiliateRepository::find( $current );
			$options[ $current ] = ( $aff ? $aff->name : '#' . $current ) . ' – ' . ( $aff ? $aff->status : '' );
		}
		wp_nonce_field( 'wpac_coupon', '_wpac_coupon_nonce' );
		woocommerce_wp_select(
			array(
				'id'          => 'wpac_coupon_affiliate',
				'label'       => __( 'Affiliate partner', 'wp-affiliate-captain' ),
				'options'     => $options,
				'value'       => $current ? (string) $current : '',
				'description' => __( 'Orders using this coupon are attributed to the partner. The discount is still controlled by the coupon.', 'wp-affiliate-captain' ),
				'desc_tip'    => true,
			)
		);
	}

	/**
	 * Save coupon ↔ affiliate link. The link is stored on a partner code row
	 * (coupon_code column); partner codes of other affiliates are never touched.
	 *
	 * @param int        $coupon_id Coupon ID.
	 * @param \WC_Coupon $coupon    Coupon.
	 */
	public static function coupon_save( $coupon_id, $coupon ) {
		if ( ! isset( $_POST['_wpac_coupon_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpac_coupon_nonce'] ) ), 'wpac_coupon' ) || ! current_user_can( Capabilities::MANAGE_AFFILIATES ) ) {
			return;
		}
		$aff_id = isset( $_POST['wpac_coupon_affiliate'] ) ? absint( $_POST['wpac_coupon_affiliate'] ) : 0;
		$code   = $coupon->get_code();
		$linked = CodeRepository::find_by_coupon( $code, true );

		if ( $linked && (int) $linked->affiliate_id === $aff_id ) {
			return; // Unchanged.
		}
		if ( $linked ) {
			// Unlink from the previous partner; keep the row for history.
			CodeRepository::set_coupon( (int) $linked->id, '' );
			CodeRepository::set_status( (int) $linked->id, 'blocked' );
		}
		if ( ! $aff_id ) {
			return;
		}
		// Re-use an earlier (unlinked) row of the same partner for this coupon if there is one.
		$previous = CodeRepository::find_by_coupon( $code, false, $aff_id );
		if ( $previous ) {
			CodeRepository::set_coupon( (int) $previous->id, $code );
			CodeRepository::set_status( (int) $previous->id, 'active' );
			return;
		}
		CodeRepository::create( $aff_id, $code, array( 'coupon_code' => $code ) );
	}
}
