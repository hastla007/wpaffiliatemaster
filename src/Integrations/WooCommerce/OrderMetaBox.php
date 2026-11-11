<?php
/**
 * WooCommerce integration: "Affiliate Captain" box on the order screen (attribution, commissions, manual assignment with reason).
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

final class OrderMetaBox {
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
		$attr = Ledger::attribution_for( WooCommerce::SOURCE, (string) $order->get_id() );
		if ( $attr ) {
			$aff = AffiliateRepository::find( (int) $attr->affiliate_id );
			list( $rows ) = Ledger::query(
				array(
					'source'   => WooCommerce::SOURCE,
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
			echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=wpac-commissions&order_id=' . $order->get_id() . '&source=' . WooCommerce::SOURCE ) ) . '">' . esc_html__( 'View commissions', 'wp-affiliate-captain' ) . '</a></p>';
			return;
		}

		$code = CheckoutField::order_code( $order );
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
		if ( ! $order || '' === $reason || Ledger::attribution_for( WooCommerce::SOURCE, (string) $order_id ) ) {
			return;
		}
		$order->update_meta_data( WooCommerce::META_MANUAL, $aff_id );
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
			OrderSync::sync_order( $order_id );
		}
	}
}
