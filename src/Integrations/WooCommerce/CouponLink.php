<?php
/**
 * WooCommerce integration: link a WooCommerce coupon to a partner on the coupon screen.
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

final class CouponLink {
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
		wp_nonce_field( 'wpac_coupon', '_wpac_coupon_nonce' );
		// Every active partner can be chosen (search field in large programs); the current partner
		// stays selected even if paused, so saving the coupon never unlinks it by accident.
		echo '<p class="form-field wpac_coupon_affiliate_field"><label for="wpac_coupon_affiliate">' . esc_html__( 'Affiliate partner', 'affiliate-captain' ) . '</label>';
		echo \WPAffiliateCaptain\Admin\Admin::partner_picker( 'wpac_coupon_affiliate', $current, 'active', array( 'id' => 'wpac_coupon_affiliate' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in partner_picker().
		echo ' <span class="description">' . esc_html__( 'Orders using this coupon are attributed to the partner. The discount is still controlled by the coupon.', 'affiliate-captain' ) . '</span></p>';
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
		$raw    = isset( $_POST['wpac_coupon_affiliate'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['wpac_coupon_affiliate'] ) ) ) : '';
		$aff_id = AffiliateRepository::resolve_input( $raw );
		if ( '' !== $raw && ! $aff_id ) {
			return; // Unknown entry in the search field: keep the current link instead of removing it.
		}
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
		// Coupon codes may contain characters partner codes cannot ("größe10", "лето20"): the partner
		// code is derived (or random), the coupon itself is matched by the coupon column.
		$created = CodeRepository::create( $aff_id, CodeRepository::code_for_coupon( $code ), array( 'coupon_code' => $code ) );
		if ( is_wp_error( $created ) && class_exists( '\\WC_Admin_Meta_Boxes' ) ) {
			/* translators: %s: error message */
			\WC_Admin_Meta_Boxes::add_error( sprintf( __( 'Affiliate Captain: the coupon was not linked to the partner: %s', 'affiliate-captain' ), $created->get_error_message() ) );
		}
	}
}
