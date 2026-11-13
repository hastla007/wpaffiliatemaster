<?php
/**
 * WooCommerce integration: partner code field in the classic and block checkout, and the referral touch stored on the order.
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

final class CheckoutField {
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
				'label'       => __( 'Partner code (optional)', 'affiliate-captain' ),
				'placeholder' => __( 'e.g. from your sailing school', 'affiliate-captain' ),
				'required'    => false,
				'class'       => array( 'form-row-wide' ),
			),
			// Not prefilled from the link/cookie: that touch is stored separately, and a
			// prefilled value would count as an entered code (beating coupons, skipping checks).
			$checkout->get_value( 'wpac_partner_code' )
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
		if ( '' === $code ) {
			return;
		}
		// Same limit as the REST code check, so the checkout cannot be used to guess codes.
		if ( ! \WPAffiliateCaptain\Tracking\RateLimiter::allow( 'code_check', 20, MINUTE_IN_SECONDS ) ) {
			$errors->add( 'wpac_partner_code', __( 'Too many attempts. Please wait a minute and try again.', 'affiliate-captain' ) );
			return;
		}
		if ( ! wpac_resolve_partner_code( $code, array( 'context' => 'checkout' ) ) ) {
			$errors->add( 'wpac_partner_code', __( 'The partner code is not valid.', 'affiliate-captain' ) );
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
			$order->update_meta_data( WooCommerce::META_CODE, $code );
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
				'id'                => WooCommerce::BLOCKS_FIELD,
				'label'             => __( 'Partner code (optional)', 'affiliate-captain' ),
				'optionalLabel'     => __( 'Partner code (optional)', 'affiliate-captain' ),
				// "order" replaced "additional" in newer WooCommerce versions (same meta key _wc_other/...).
				'location'          => ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '9.2', '<' ) ) ? 'additional' : 'order',
				'type'              => 'text',
				'required'          => false,
				'sanitize_callback' => static function ( $value ) {
					return CodeRepository::normalize( (string) $value );
				},
				'validate_callback' => array( __CLASS__, 'blocks_validate' ),
			)
		);
	}

	/** @var array<string,true|\WP_Error> Results of block validation in this request (WooCommerce may validate a field several times). */
	private static $block_checked = array();

	/**
	 * Validate the blocks field. Same limit as the classic checkout and the REST code
	 * check, so POST /wc/store/v1/checkout cannot be used to guess codes.
	 *
	 * @param mixed $value Sanitized value.
	 * @return true|\WP_Error
	 */
	public static function blocks_validate( $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return true;
		}
		if ( isset( self::$block_checked[ $value ] ) ) {
			return self::$block_checked[ $value ];
		}
		if ( ! \WPAffiliateCaptain\Tracking\RateLimiter::allow( 'code_check', 20, MINUTE_IN_SECONDS ) ) {
			$result = new \WP_Error( 'wpac_partner_code', __( 'Too many attempts. Please wait a minute and try again.', 'affiliate-captain' ) );
		} elseif ( ! wpac_resolve_partner_code( $value, array( 'context' => 'checkout' ) ) ) {
			$result = new \WP_Error( 'wpac_partner_code', __( 'The partner code is not valid.', 'affiliate-captain' ) );
		} else {
			$result = true;
		}
		self::$block_checked[ $value ] = $result;
		return $result;
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
				WooCommerce::META_TOUCH,
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
	public static function order_code( $order ) {
		$code = (string) $order->get_meta( WooCommerce::META_CODE );
		if ( '' === $code ) {
			$code = (string) $order->get_meta( '_wc_other/' . WooCommerce::BLOCKS_FIELD );
		}
		return $code;
	}
}
