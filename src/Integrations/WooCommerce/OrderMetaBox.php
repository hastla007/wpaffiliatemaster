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
		add_meta_box( 'wpac-order', __( 'Affiliate Captain', 'affiliate-captain' ), array( __CLASS__, 'render_meta_box' ), $screen, 'side', 'default' );
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
		wp_nonce_field( 'wpac_order_assign', '_wpac_order_nonce' );
		self::render_customer_link( $order );
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
			echo esc_html__( 'Method', 'affiliate-captain' ) . ': ' . esc_html( Ledger::method_label( $attr->method ) ) . '<br>';
			echo esc_html__( 'Commission', 'affiliate-captain' ) . ': ' . esc_html( Money::format( $sum, $order->get_currency() ) ) . '</p>';
			echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=wpac-commissions&order_id=' . $order->get_id() . '&source=' . WooCommerce::SOURCE ) ) . '">' . esc_html__( 'View commissions', 'affiliate-captain' ) . '</a></p>';
			if ( $order->is_paid() ) {
				// Re-attribution: the current partner's commission is reversed, the order is booked for the new one.
				list( $others ) = AffiliateRepository::query(
					array(
						'status' => 'active',
						'limit'  => 500,
					)
				);
				echo '<details><summary>' . esc_html__( 'Attribute to another partner', 'affiliate-captain' ) . '</summary><p><label for="wpac_reassign">' . esc_html__( 'New partner', 'affiliate-captain' ) . '</label><select name="wpac_reassign" id="wpac_reassign" style="width:100%"><option value="">—</option>';
				foreach ( $others as $a ) {
					if ( (int) $a->id !== (int) $attr->affiliate_id ) {
						echo '<option value="' . (int) $a->id . '">' . esc_html( $a->name . ' (' . $a->slug . ')' ) . '</option>';
					}
				}
				echo '</select></p><p><label for="wpac_reassign_reason">' . esc_html__( 'Reason (required, kept in the history)', 'affiliate-captain' ) . '</label><input type="text" name="wpac_reassign_reason" id="wpac_reassign_reason" style="width:100%"></p><p class="description">' . esc_html__( 'The current partner gets a cancellation correction; the order is booked for the new partner with the current rules.', 'affiliate-captain' ) . '</p></details>';
				// Ask before the order form is saved with a new partner selected (the reversal cannot be undone).
				$confirm = __( 'Attribute this order to the selected partner? The current partner\'s commission will be reversed. This cannot be undone.', 'affiliate-captain' );
				echo '<script>(function(){var s=document.getElementById("wpac_reassign");if(!s||!s.form){return;}s.form.addEventListener("submit",function(e){if(s.value&&!window.confirm(' . wp_json_encode( $confirm ) . ')){e.preventDefault();e.stopImmediatePropagation();}});})();</script>';
			}
			return;
		}

		$code = CheckoutField::order_code( $order );
		if ( $code ) {
			/* translators: %s: code */
			echo '<p>' . esc_html( sprintf( __( 'Partner code entered: %s', 'affiliate-captain' ), $code ) ) . '</p>';
		}
		echo '<p>' . esc_html__( 'Not attributed.', 'affiliate-captain' ) . '</p>';
		list( $affiliates ) = AffiliateRepository::query(
			array(
				'status' => 'active',
				'limit'  => 500,
			)
		);
		echo '<p><label for="wpac_assign">' . esc_html__( 'Assign manually', 'affiliate-captain' ) . '</label><select name="wpac_assign" id="wpac_assign" style="width:100%"><option value="">—</option>';
		foreach ( $affiliates as $a ) {
			echo '<option value="' . (int) $a->id . '">' . esc_html( $a->name . ' (' . $a->slug . ')' ) . '</option>';
		}
		echo '</select></p><p><label for="wpac_assign_reason">' . esc_html__( 'Reason (required)', 'affiliate-captain' ) . '</label><input type="text" name="wpac_assign_reason" id="wpac_assign_reason" style="width:100%"></p>';
		echo '<p class="description">' . esc_html__( 'Commission is created once the order is paid.', 'affiliate-captain' ) . '</p>';
	}

	/**
	 * Lifetime binding of the order's customer, with a checkbox to remove it.
	 *
	 * @param \WC_Order $order Order.
	 */
	private static function render_customer_link( $order ) {
		$rows = \WPAffiliateCaptain\Tracking\CustomerLinks::for_customer( (int) $order->get_customer_id(), (string) $order->get_billing_email() );
		if ( ! $rows ) {
			return;
		}
		$aff = AffiliateRepository::find( (int) $rows[0]->affiliate_id );
		/* translators: 1: partner name, 2: order number that created the binding */
		echo '<p>' . esc_html( sprintf( __( 'Lifetime customer of partner "%1$s" (since order %2$s).', 'affiliate-captain' ), $aff ? $aff->name : '#' . (int) $rows[0]->affiliate_id, $rows[0]->source_order_id ) );
		if ( (int) $rows[0]->pending ) {
			echo ' ' . esc_html__( 'Becomes active once the order\'s commission is approved.', 'affiliate-captain' );
		}
		echo '</p>';
		echo '<p><label><input type="checkbox" name="wpac_unlink_customer" value="1"> ' . esc_html__( 'Remove this customer\'s lifetime binding', 'affiliate-captain' ) . '</label></p>';
	}

	/**
	 * Save manual assignment.
	 *
	 * @param int $order_id Order.
	 */
	public static function save_meta_box( $order_id ) {
		if ( ! isset( $_POST['_wpac_order_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpac_order_nonce'] ) ), 'wpac_order_assign' ) ) {
			return;
		}
		if ( ! current_user_can( Capabilities::MANAGE_AFFILIATES ) ) {
			return;
		}
		if ( ! empty( $_POST['wpac_unlink_customer'] ) ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$n = \WPAffiliateCaptain\Tracking\CustomerLinks::unlink_customer( (int) $order->get_customer_id(), (string) $order->get_billing_email(), 'order ' . (int) $order_id );
				if ( $n ) {
					$order->add_order_note( __( 'Affiliate Captain: lifetime partner binding of the customer removed.', 'affiliate-captain' ) );
					self::notice( 'success', __( 'The customer\'s lifetime partner binding was removed.', 'affiliate-captain' ) );
				}
			}
		}
		if ( ! empty( $_POST['wpac_reassign'] ) ) {
			$order = wc_get_order( $order_id );
			if ( ! $order || ! $order->is_paid() ) {
				return;
			}
			$to = AffiliateRepository::find( absint( $_POST['wpac_reassign'] ) );
			try {
				$sale = ContractApi::normalize_sale( OrderSync::payload( $order ) );
			} catch ( \WPAffiliateCaptain\Core\IntegrationException $e ) {
				$sale = null;
			}
			$result = $sale ? Ledger::reassign(
				$sale,
				absint( $_POST['wpac_reassign'] ),
				isset( $_POST['wpac_reassign_reason'] ) ? sanitize_text_field( wp_unslash( $_POST['wpac_reassign_reason'] ) ) : ''
			) : new \WP_Error( 'wpac_invalid_order', __( 'The order has no line items that can be booked.', 'affiliate-captain' ) );
			if ( is_wp_error( $result ) ) {
				/* translators: %s: error message */
				$order->add_order_note( sprintf( __( 'Affiliate Captain: reassignment failed: %s', 'affiliate-captain' ), $result->get_error_message() ) );
				/* translators: %s: error message */
				self::notice( 'error', sprintf( __( 'The order was not attributed to another partner: %s', 'affiliate-captain' ), $result->get_error_message() ) );
				return;
			}
			$name = $to ? $to->name : '#' . absint( $_POST['wpac_reassign'] );
			/* translators: %s: partner name */
			$msg = sprintf( __( 'Order attributed to partner "%s"; the previous partner\'s commission was reversed.', 'affiliate-captain' ), $name );
			$order->add_order_note( $msg );
			self::notice( 'success', $msg );
			return;
		}
		if ( empty( $_POST['wpac_assign'] ) ) {
			return;
		}
		$aff_id = absint( $_POST['wpac_assign'] );
		$reason = isset( $_POST['wpac_assign_reason'] ) ? sanitize_text_field( wp_unslash( $_POST['wpac_assign_reason'] ) ) : '';
		$order  = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		if ( '' === trim( $reason ) ) {
			self::notice( 'error', __( 'The order was not assigned to a partner: please enter a reason.', 'affiliate-captain' ) );
			return;
		}
		if ( Ledger::attribution_for( WooCommerce::SOURCE, (string) $order_id ) ) {
			self::notice( 'error', __( 'The order is already attributed to a partner. Use "Attribute to another partner" instead.', 'affiliate-captain' ) );
			return;
		}
		$aff = AffiliateRepository::find( $aff_id );
		if ( ! \WPAffiliateCaptain\Tracking\AttributionResolver::eligible( $aff ) ) {
			// Paused, terminated or restricted (Art. 18 GDPR): the resolver would skip the partner and
			// book the order for another one (link/coupon) – refuse instead.
			self::notice( 'error', __( 'The order was not assigned: the selected partner is not active or processing of the partner\'s data is restricted.', 'affiliate-captain' ) );
			return;
		}
		$order->update_meta_data( WooCommerce::META_MANUAL, $aff_id );
		// Marks the assignment as made on this order (renewals ignore copies from the first order).
		$order->update_meta_data( OrderSync::META_MANUAL_OWN, (int) $order->get_id() );
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
		$name = $aff ? $aff->name : '#' . $aff_id;
		if ( ! $order->is_paid() ) {
			/* translators: %s: partner name */
			self::notice( 'info', sprintf( __( 'Order assigned to partner "%s". The commission is created once the order is paid.', 'affiliate-captain' ), $name ) );
			return;
		}
		OrderSync::sync_order( $order_id );
		$booked = Ledger::attribution_for( WooCommerce::SOURCE, (string) $order_id );
		if ( $booked && (int) $booked->affiliate_id !== $aff_id ) {
			// The chosen partner could not be booked (e.g. is the buyer); another candidate won.
			$other = AffiliateRepository::find( (int) $booked->affiliate_id );
			/* translators: 1: chosen partner, 2: partner the order was attributed to */
			self::notice( 'warning', sprintf( __( 'The order could not be attributed to partner "%1$s" (e.g. the partner is the buyer). It was attributed to partner "%2$s" instead (referral link, coupon or lifetime customer); use "Attribute to another partner" to change this.', 'affiliate-captain' ), $name, $other ? $other->name : '#' . (int) $booked->affiliate_id ) );
		} elseif ( $booked ) {
			/* translators: %s: partner name */
			self::notice( 'success', sprintf( __( 'Order attributed to partner "%s".', 'affiliate-captain' ), $name ) );
		} elseif ( Jobs::sale_pending( WooCommerce::SOURCE, (string) $order_id ) ) {
			self::notice( 'warning', __( 'The order could not be booked yet and was queued for an automatic retry (Affiliate Captain → Integrations).', 'affiliate-captain' ) );
		} else {
			/* translators: %s: partner name */
			self::notice( 'error', sprintf( __( 'The order could not be attributed to partner "%s" (partner not active, processing restricted or the partner is the buyer).', 'affiliate-captain' ), $name ) );
		}
	}

	/**
	 * Remember a notice for the next admin page view of the current user (the order screen
	 * redirects after saving).
	 *
	 * @param string $type    success|error|warning|info.
	 * @param string $message Message.
	 */
	private static function notice( $type, $message ) {
		set_transient(
			'wpac_order_notice_' . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => (string) $message,
			),
			5 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * Show the notice of the last order save (admin_notices).
	 */
	public static function admin_notice() {
		$key    = 'wpac_order_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}
		delete_transient( $key );
		$type = in_array( $notice['type'], array( 'success', 'error', 'warning', 'info' ), true ) ? $notice['type'] : 'info';
		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p><strong>' . esc_html__( 'Affiliate Captain', 'affiliate-captain' ) . ':</strong> ' . esc_html( $notice['message'] ) . '</p></div>';
	}
}
