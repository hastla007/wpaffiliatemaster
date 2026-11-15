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

		if ( ! $linked ) {
			// Renamed coupon: the code row linked by the coupon's ID follows the new code instead of
			// staying on the old text (a later coupon with the old name must not credit the partner).
			$by_id = self::linked_row( (int) $coupon_id );
			if ( $by_id && 'active' === $by_id->status && CodeRepository::normalize_coupon( (string) $by_id->coupon_code ) !== CodeRepository::normalize_coupon( $code ) ) {
				CodeRepository::set_coupon( (int) $by_id->id, $code );
				$linked = CodeRepository::find( (int) $by_id->id );
			}
		}
		if ( $linked && (int) $linked->affiliate_id === $aff_id ) {
			self::remember( (int) $linked->id, $code );
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
			self::remember( (int) $previous->id, $code );
			return;
		}
		// Coupon codes may contain characters partner codes cannot ("größe10", "лето20"): the partner
		// code is derived (or random), the coupon itself is matched by the coupon column.
		$created = CodeRepository::create( $aff_id, CodeRepository::code_for_coupon( $code ), array( 'coupon_code' => $code ) );
		if ( ! is_wp_error( $created ) ) {
			self::remember( (int) $created, $code );
		}
		if ( is_wp_error( $created ) && class_exists( '\\WC_Admin_Meta_Boxes' ) ) {
			/* translators: %s: error message */
			\WC_Admin_Meta_Boxes::add_error( sprintf( __( 'Affiliate Captain: the coupon was not linked to the partner: %s', 'affiliate-captain' ), $created->get_error_message() ) );
		}
	}

	/** Coupon meta: ID of the partner code row the coupon is linked to (R12-N14). */
	const META_CODE_ID = '_wpac_code_id';

	/**
	 * Whether coupons can be created from the partner page: WooCommerce active and the user may
	 * publish coupons (a partner manager without WooCommerce rights must not create discounts).
	 *
	 * @return bool
	 */
	public static function can_create() {
		return class_exists( 'WC_Coupon' ) && function_exists( 'wc_get_coupon_id_by_code' ) && current_user_can( 'publish_shop_coupons' );
	}

	/**
	 * Whether a partner code may be linked to a new coupon: active, not expired, not used up.
	 *
	 * @param object $row Code row.
	 * @return bool
	 */
	public static function code_usable( $row ) {
		if ( ! $row || 'active' !== $row->status ) {
			return false;
		}
		if ( $row->expires_at && strtotime( $row->expires_at . ' UTC' ) < time() ) {
			return false;
		}
		return ! ( (int) $row->usage_limit > 0 && (int) $row->usage_count >= (int) $row->usage_limit );
	}

	/**
	 * Remember on the WooCommerce coupon which partner code row it is linked to (WooCommerce
	 * CRUD). The link is then checked by the coupon's ID, not only by its code text.
	 *
	 * @param int    $code_id Partner code row.
	 * @param string $coupon  Coupon code.
	 */
	public static function remember( $code_id, $coupon ) {
		if ( ! function_exists( 'wc_get_coupon_id_by_code' ) || ! class_exists( 'WC_Coupon' ) || '' === (string) $coupon ) {
			return;
		}
		$id = (int) wc_get_coupon_id_by_code( (string) $coupon );
		if ( ! $id ) {
			return;
		}
		try {
			$wc = new \WC_Coupon( $id );
			if ( (int) $wc->get_meta( self::META_CODE_ID ) !== (int) $code_id ) {
				$wc->update_meta_data( self::META_CODE_ID, (int) $code_id );
				$wc->save_meta_data();
			}
		} catch ( \Throwable $e ) {
			return; // The link still works by the code text.
		}
	}

	/**
	 * Partner code row a coupon is linked to by its ID (meta), null when none.
	 *
	 * @param int $coupon_id Coupon ID.
	 * @return object|null
	 */
	private static function linked_row( $coupon_id ) {
		if ( ! $coupon_id || ! class_exists( 'WC_Coupon' ) ) {
			return null;
		}
		try {
			$code_id = (int) ( new \WC_Coupon( (int) $coupon_id ) )->get_meta( self::META_CODE_ID );
		} catch ( \Throwable $e ) {
			return null;
		}
		return $code_id ? CodeRepository::find( $code_id ) : null;
	}

	/**
	 * State of the WooCommerce coupon a partner code row is linked to (WooCommerce active):
	 * 'ok', 'missing' (no coupon with that code any more: deleted or renamed) or 'other' (a coupon
	 * with that code exists but belongs to another code row by its ID, e.g. a new coupon with the
	 * name of a deleted one). Without WooCommerce or without a link: 'ok'.
	 *
	 * @param object $row Code row.
	 * @return string
	 */
	public static function link_state( $row ) {
		if ( ! $row || '' === (string) $row->coupon_code || ! function_exists( 'wc_get_coupon_id_by_code' ) || ! class_exists( 'WC_Coupon' ) ) {
			return 'ok';
		}
		$id = (int) wc_get_coupon_id_by_code( (string) $row->coupon_code );
		if ( ! $id ) {
			return 'missing';
		}
		try {
			$code_id = (int) ( new \WC_Coupon( $id ) )->get_meta( self::META_CODE_ID );
		} catch ( \Throwable $e ) {
			return 'ok';
		}
		// Links made by older versions carry no ID: they count by the code text as before.
		return ( ! $code_id || (int) $row->id === $code_id ) ? 'ok' : 'other';
	}

	/**
	 * Create a new WooCommerce coupon for a partner and link it to one of the partner's codes in
	 * one step (WooCommerce CRUD only, never direct table writes). Nothing half-done is left
	 * behind: when the link cannot be saved, the new coupon is deleted again.
	 *
	 * @param int   $affiliate_id Partner.
	 * @param array $args {
	 *     @type int    $code_id        Partner code to link (0 = new partner code derived from the coupon).
	 *     @type string $coupon_code    Coupon code ('' = the selected partner code).
	 *     @type string $discount_type  percent|fixed_cart.
	 *     @type string $amount         Discount (decimal, comma or dot).
	 *     @type string $expires        Expiry date Y-m-d ('' = none).
	 *     @type int    $usage_limit    Total usage limit (0 = unlimited).
	 *     @type bool   $individual_use Cannot be combined with other coupons.
	 * }
	 * @return array|\WP_Error { coupon_id, code_id, coupon }
	 */
	public static function create_for_partner( $affiliate_id, array $args ) {
		// Creating a discount is a WooCommerce right: the partner right alone is not enough.
		if ( ! current_user_can( Capabilities::MANAGE_AFFILIATES ) || ! current_user_can( 'publish_shop_coupons' ) ) {
			return new \WP_Error( 'wpac_forbidden', __( 'You are not allowed to do this.', 'affiliate-captain' ) );
		}
		if ( ! self::can_create() ) {
			return new \WP_Error( 'wpac_no_woocommerce', __( 'Coupons can only be created while WooCommerce is active.', 'affiliate-captain' ) );
		}
		$affiliate_id = (int) $affiliate_id;
		$aff          = AffiliateRepository::find( $affiliate_id );
		if ( ! $aff ) {
			return new \WP_Error( 'wpac_not_found', __( 'Partner not found.', 'affiliate-captain' ) );
		}
		$code_id = isset( $args['code_id'] ) ? (int) $args['code_id'] : 0;
		$row     = $code_id ? CodeRepository::find( $code_id ) : null;
		if ( $code_id && ( ! $row || (int) $row->affiliate_id !== $affiliate_id ) ) {
			return new \WP_Error( 'wpac_code_foreign', __( 'Please choose one of the codes of this partner.', 'affiliate-captain' ) );
		}
		if ( $row && ! self::code_usable( $row ) ) {
			return new \WP_Error( 'wpac_code_unusable', __( 'This partner code is blocked, expired or used up. Please choose an active code.', 'affiliate-captain' ) );
		}
		$coupon = isset( $args['coupon_code'] ) ? trim( sanitize_text_field( (string) $args['coupon_code'] ) ) : '';
		if ( '' === $coupon && $row ) {
			$coupon = (string) $row->code;
		}
		if ( function_exists( 'wc_format_coupon_code' ) ) {
			$coupon = trim( (string) wc_format_coupon_code( $coupon ) );
		}
		if ( '' === $coupon ) {
			return new \WP_Error( 'wpac_coupon_empty', __( 'Please enter a coupon code.', 'affiliate-captain' ) );
		}
		if ( wc_get_coupon_id_by_code( $coupon ) ) {
			/* translators: %s: coupon code */
			return new \WP_Error( 'wpac_coupon_exists', sprintf( __( 'A WooCommerce coupon "%s" already exists. Link it in the code table instead, or choose another coupon code.', 'affiliate-captain' ), $coupon ) );
		}
		$linked = CodeRepository::find_by_coupon( $coupon, true );
		if ( $linked && (int) $linked->id !== $code_id ) {
			$owner = AffiliateRepository::find( (int) $linked->affiliate_id );
			/* translators: 1: coupon code, 2: partner name */
			return new \WP_Error( 'wpac_coupon_taken', sprintf( __( 'The coupon "%1$s" is already linked to partner "%2$s". Remove that link first.', 'affiliate-captain' ), $coupon, $owner ? $owner->name : '#' . (int) $linked->affiliate_id ) );
		}
		$type = isset( $args['discount_type'] ) && 'fixed_cart' === $args['discount_type'] ? 'fixed_cart' : 'percent';
		$raw  = isset( $args['amount'] ) ? str_replace( ',', '.', trim( (string) $args['amount'] ) ) : '';
		if ( ! is_numeric( $raw ) || (float) $raw <= 0 || ( 'percent' === $type && (float) $raw > 100 ) ) {
			return new \WP_Error( 'wpac_coupon_amount', __( 'Please enter a discount greater than 0 (at most 100 for a percentage discount).', 'affiliate-captain' ) );
		}
		$amount  = function_exists( 'wc_format_decimal' ) ? (string) wc_format_decimal( $raw ) : (string) (float) $raw;
		$expires = isset( $args['expires'] ) ? trim( (string) $args['expires'] ) : '';
		if ( '' !== $expires ) {
			$date = \DateTime::createFromFormat( '!Y-m-d', $expires );
			if ( ! $date || $date->format( 'Y-m-d' ) !== $expires || $expires < wp_date( 'Y-m-d' ) ) {
				return new \WP_Error( 'wpac_coupon_expiry', __( 'Please enter a valid expiry date that is not in the past.', 'affiliate-captain' ) );
			}
		}
		$limit = isset( $args['usage_limit'] ) ? max( 0, (int) $args['usage_limit'] ) : 0;

		try {
			$wc = new \WC_Coupon();
			$wc->set_code( $coupon );
			$wc->set_discount_type( $type );
			$wc->set_amount( $amount );
			$wc->set_date_expires( '' !== $expires ? $expires : null );
			$wc->set_usage_limit( $limit );
			$wc->set_individual_use( ! empty( $args['individual_use'] ) );
			/* translators: %s: partner name */
			$wc->set_description( sprintf( __( 'Partner coupon for %s (Affiliate Captain)', 'affiliate-captain' ), $aff->name ) );
			$coupon_id = (int) $wc->save();
		} catch ( \Exception $e ) {
			/* translators: %s: technical reason */
			return new \WP_Error( 'wpac_coupon_save', sprintf( __( 'The coupon could not be created: %s', 'affiliate-captain' ), $e->getMessage() ) );
		}
		if ( ! $coupon_id ) {
			return new \WP_Error( 'wpac_coupon_save', __( 'The coupon could not be created. Nothing was changed.', 'affiliate-captain' ) );
		}

		// Link: the chosen code, or a new partner code derived from the coupon.
		if ( $row ) {
			$ok = CodeRepository::set_coupon( (int) $row->id, $coupon );
		} else {
			$created = CodeRepository::create( $affiliate_id, CodeRepository::code_for_coupon( $coupon ), array( 'coupon_code' => $coupon ) );
			$ok      = ! is_wp_error( $created );
			$code_id = $ok ? (int) $created : 0;
		}
		if ( $ok ) {
			$wc->update_meta_data( self::META_CODE_ID, (int) ( $row ? $row->id : $code_id ) );
			$wc->save_meta_data();
		}
		if ( ! $ok ) {
			$wc->delete( true ); // Never leave a coupon that discounts without crediting the partner.
			return new \WP_Error( 'wpac_coupon_link', __( 'The coupon could not be linked to the partner code and was deleted again. Nothing was changed; please try again.', 'affiliate-captain' ) );
		}
		Audit::log(
			'coupon.created',
			'affiliate',
			$affiliate_id,
			array(
				'coupon'        => CodeRepository::normalize_coupon( $coupon ),
				'coupon_id'     => $coupon_id,
				'code_id'       => $code_id,
				'discount_type' => $type,
				'amount'        => $amount,
				'expires'       => $expires,
				'usage_limit'   => $limit,
			)
		);
		return array(
			'coupon_id' => $coupon_id,
			'code_id'   => $code_id,
			'coupon'    => $coupon,
		);
	}
}
