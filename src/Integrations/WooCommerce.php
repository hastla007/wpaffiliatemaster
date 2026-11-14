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

use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Integrations\WooCommerce\CheckoutField;
use WPAffiliateCaptain\Integrations\WooCommerce\CouponLink;
use WPAffiliateCaptain\Integrations\WooCommerce\OrderMetaBox;
use WPAffiliateCaptain\Integrations\WooCommerce\OrderSync;
use WPAffiliateCaptain\Tracking\Tracker;

defined( 'ABSPATH' ) || exit;

final class WooCommerce {

	const SOURCE       = 'woocommerce';
	const META_CODE    = '_wpac_partner_code';
	const META_TOUCH   = '_wpac_touch';
	const META_MANUAL  = '_wpac_manual_affiliate';
	const BLOCKS_FIELD = 'wp-affiliate-captain/partner-code';

	/**
	 * Register all WooCommerce hooks; the parts live in Integrations\WooCommerce\*.
	 */
	public static function register_hooks() {
		// Checkout field.
		if ( Settings::get( 'checkout_field' ) ) {
			add_action( 'woocommerce_after_order_notes', array( CheckoutField::class, 'classic_field' ) );
			add_action( 'woocommerce_after_checkout_validation', array( CheckoutField::class, 'classic_validate' ), 10, 2 );
			add_action( 'woocommerce_init', array( CheckoutField::class, 'register_blocks_field' ) );
		}
		// Always registered: it also stores the referral touch. The partner code itself is only taken
		// over while the checkout field is on (checked inside).
		add_action( 'woocommerce_checkout_create_order', array( CheckoutField::class, 'classic_save' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( CheckoutField::class, 'blocks_save' ), 10, 1 );
		// The session referral is cleared once the order is paid – not when it is placed: an aborted
		// payment (PayPal, card) makes the next attempt a new order that still needs the referral.
		add_action( 'woocommerce_payment_complete', array( Tracker::class, 'clear_touch_for_order' ) );
		add_action( 'woocommerce_thankyou', array( Tracker::class, 'clear_touch_for_order' ) );

		// Sales and refunds.
		add_action( 'woocommerce_payment_complete', array( OrderSync::class, 'sync_order' ) );
		add_action( 'woocommerce_order_status_processing', array( OrderSync::class, 'sync_order' ) );
		add_action( 'woocommerce_order_status_completed', array( OrderSync::class, 'sync_order' ) );
		add_action( 'woocommerce_order_refunded', array( OrderSync::class, 'sync_refund' ), 10, 2 );
		add_action( 'woocommerce_order_status_refunded', array( OrderSync::class, 'full_reversal_refunded' ) );
		add_action( 'woocommerce_order_status_cancelled', array( OrderSync::class, 'full_reversal_cancelled' ) );
		// Evaluated at status change time, so filters from themes/snippets (loaded later) apply.
		add_action( 'woocommerce_order_status_changed', array( OrderSync::class, 'maybe_chargeback' ), 10, 3 );
		add_action( 'woocommerce_order_status_completed', array( OrderSync::class, 'order_completed' ), 20 );
		// Trash, restore and delete (HPOS and classic order storage), item changes after payment.
		add_action( 'woocommerce_trash_order', array( OrderSync::class, 'order_trashed' ) );
		add_action( 'woocommerce_untrash_order', array( OrderSync::class, 'order_untrashed' ) );
		add_action( 'woocommerce_before_delete_order', array( OrderSync::class, 'order_deleted' ) );
		add_action( 'wp_trash_post', array( OrderSync::class, 'post_trashed' ) );
		add_action( 'untrashed_post', array( OrderSync::class, 'post_untrashed' ) );
		add_action( 'before_delete_post', array( OrderSync::class, 'post_deleted' ) );
		add_action( 'woocommerce_saved_order_items', array( OrderSync::class, 'items_changed' ), 20 );
		// No release while the order is on hold or disputed; optional hold period from completion.
		add_filter( 'wpac_hold_release', array( OrderSync::class, 'hold_release' ), 10, 3 );
		add_filter( 'wpac_hold_until', array( OrderSync::class, 'hold_until' ), 10, 2 );
		add_filter( 'wpac_run_job', array( OrderSync::class, 'run_job' ), 10, 3 );
		// WooCommerce Subscriptions must not copy the partner code/touch to renewals (loaded by then).
		add_action( 'init', array( OrderSync::class, 'register_subscription_hooks' ), 1 );

		// Admin: order meta box and coupon ↔ affiliate link.
		add_action( 'add_meta_boxes', array( OrderMetaBox::class, 'add_meta_box' ) );
		add_action( 'woocommerce_process_shop_order_meta', array( OrderMetaBox::class, 'save_meta_box' ) );
		add_action( 'admin_notices', array( OrderMetaBox::class, 'admin_notice' ) );
		add_action( 'woocommerce_coupon_options', array( CouponLink::class, 'coupon_field' ), 10, 2 );
		add_action( 'woocommerce_coupon_options_save', array( CouponLink::class, 'coupon_save' ), 10, 2 );
	}
}
