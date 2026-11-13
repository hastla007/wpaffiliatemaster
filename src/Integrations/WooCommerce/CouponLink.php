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
				'label'       => __( 'Affiliate partner', 'affiliate-captain' ),
				'options'     => $options,
				'value'       => $current ? (string) $current : '',
				'description' => __( 'Orders using this coupon are attributed to the partner. The discount is still controlled by the coupon.', 'affiliate-captain' ),
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
