<?php
/**
 * Create a WooCommerce coupon for a partner from the admin (R7-PU5): template from the settings
 * ("Partner coupon template"), per-coupon overrides, link to a partner code, rollback.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Codes;

use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Core\Audit;
use WPAffiliateCaptain\Core\Capabilities;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class PartnerCoupon {

	/** Template fields (settings key = "coupon_tpl_" . field). */
	const FIELDS = array( 'discount_type', 'amount', 'individual_use', 'usage_limit_per_user', 'expiry_days', 'free_shipping', 'minimum_amount', 'exclude_sale_items' );

	/** Checkbox fields (absent from a form = off). */
	const FLAGS = array( 'individual_use', 'free_shipping', 'exclude_sale_items' );

	/** Settings prefix. */
	const PREFIX = 'coupon_tpl_';

	/** Coupon meta with the partner the coupon was created for. */
	const META_AFFILIATE = '_wpac_affiliate_id';

	/**
	 * Defaults of the template (merged into Settings::defaults()).
	 *
	 * @return array Settings key => value.
	 */
	public static function defaults() {
		return array(
			'coupon_tpl_discount_type'        => 'percent', // percent|fixed_cart.
			'coupon_tpl_amount'               => '10',
			'coupon_tpl_individual_use'       => 1,
			'coupon_tpl_usage_limit_per_user' => 1,
			'coupon_tpl_expiry_days'          => 0,         // 0 = no expiry date.
			'coupon_tpl_free_shipping'        => 0,
			'coupon_tpl_minimum_amount'       => '',        // '' = no minimum spend.
			'coupon_tpl_exclude_sale_items'   => 0,
		);
	}

	/**
	 * Whether coupons can be created (WooCommerce active).
	 *
	 * @return bool
	 */
	public static function available() {
		return class_exists( 'WC_Coupon' ) && function_exists( 'wc_get_coupon_id_by_code' );
	}

	/**
	 * Whether the current user may create partner coupons: partner manager and, with
	 * WooCommerce, allowed to edit coupons (a coupon grants discounts in the shop).
	 *
	 * @return bool
	 */
	public static function can_create() {
		return current_user_can( Capabilities::MANAGE_AFFILIATES ) && current_user_can( 'edit_shop_coupons' );
	}

	/**
	 * Currency of coupon amounts (the shop currency).
	 *
	 * @return string
	 */
	public static function currency() {
		return function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : (string) Settings::get( 'currency' );
	}

	/**
	 * Template from the settings (field => value).
	 *
	 * @return array
	 */
	public static function template() {
		$tpl = array();
		foreach ( self::defaults() as $key => $default ) {
			$value         = Settings::get( $key );
			$field         = substr( $key, strlen( self::PREFIX ) );
			$tpl[ $field ] = null === $value ? $default : $value;
		}
		return $tpl;
	}

	/**
	 * Discount types offered.
	 *
	 * @return array
	 */
	public static function discount_types() {
		return array(
			'percent'    => __( 'Percentage discount', 'affiliate-captain' ),
			'fixed_cart' => __( 'Fixed cart discount', 'affiliate-captain' ),
		);
	}

	/**
	 * Validate template input (settings form or the per-coupon form). Fields that are not given
	 * keep the value of $base (forms pass unchecked checkboxes as 0); invalid fields keep $base
	 * and are reported in $errors (field => message).
	 *
	 * @param array      $in     Raw input (field => value).
	 * @param array      $base   Current values (field => value).
	 * @param array|null $errors Out: field => message.
	 * @return array Clean values (field => value).
	 */
	public static function clean( array $in, array $base, &$errors = null ) {
		$errors = array();
		$out    = array();
		foreach ( self::FIELDS as $f ) {
			$out[ $f ] = isset( $base[ $f ] ) ? $base[ $f ] : self::defaults()[ self::PREFIX . $f ];
		}
		foreach ( self::FLAGS as $f ) {
			if ( isset( $in[ $f ] ) ) {
				$out[ $f ] = empty( $in[ $f ] ) ? 0 : 1;
			}
		}
		if ( isset( $in['discount_type'] ) ) {
			$type = sanitize_key( (string) $in['discount_type'] );
			if ( isset( self::discount_types()[ $type ] ) ) {
				$out['discount_type'] = $type;
			} else {
				$errors['discount_type'] = __( 'Please choose a discount type.', 'affiliate-captain' );
			}
		}
		$limits = array(
			'usage_limit_per_user' => 1000000,
			'expiry_days'          => 36500,
		);
		foreach ( $limits as $f => $max ) {
			if ( ! isset( $in[ $f ] ) ) {
				continue;
			}
			$raw = trim( (string) $in[ $f ] );
			if ( '' === $raw ) {
				$out[ $f ] = 0;
			} elseif ( preg_match( '/^\d{1,10}$/', $raw ) && (int) $raw <= $max ) {
				$out[ $f ] = (int) $raw;
			} else {
				/* translators: %d: largest allowed number */
				$errors[ $f ] = sprintf( __( 'Please enter a whole number between 0 and %d.', 'affiliate-captain' ), $max );
			}
		}
		$currency = self::currency();
		if ( isset( $in['amount'] ) ) {
			$amount = 'percent' === $out['discount_type'] ? Settings::parse_percent( $in['amount'] ) : Settings::parse_amount( $in['amount'], $currency );
			if ( null === $amount || (float) $amount < 0 || ( 'percent' === $out['discount_type'] && (float) $amount > 100 ) ) {
				$errors['amount'] = 'percent' === $out['discount_type']
					? __( 'Please enter a percentage between 0 and 100 (e.g. 10 or 12,5).', 'affiliate-captain' )
					: __( 'Please enter an amount (e.g. 10 or 10,00).', 'affiliate-captain' );
			} elseif ( 0.0 === (float) $amount && ! $out['free_shipping'] ) {
				$errors['amount'] = __( 'A coupon without a discount needs free shipping, otherwise it does nothing.', 'affiliate-captain' );
			} else {
				$out['amount'] = (string) $amount;
			}
		} elseif ( 'percent' === $out['discount_type'] && (float) $out['amount'] > 100 ) {
			$errors['amount'] = __( 'Please enter a percentage between 0 and 100 (e.g. 10 or 12,5).', 'affiliate-captain' );
		}
		if ( isset( $in['minimum_amount'] ) ) {
			$raw = trim( (string) $in['minimum_amount'] );
			$min = '' === $raw ? '' : Settings::parse_amount( $raw, $currency );
			if ( null === $min || ( '' !== $min && (float) $min < 0 ) ) {
				$errors['minimum_amount'] = __( 'Please enter an amount (e.g. 50 or 50,00) or leave the field empty.', 'affiliate-captain' );
			} else {
				$out['minimum_amount'] = '' === $min || 0.0 === (float) $min ? '' : (string) $min;
			}
		}
		return $out;
	}

	/**
	 * Suggested coupon code: the partner's main code in upper case, made unique (no WooCommerce
	 * coupon and no partner code link with it yet).
	 *
	 * @param int $affiliate_id Partner.
	 * @return string
	 */
	public static function suggest_code( $affiliate_id ) {
		$base = CodeRepository::main_code( $affiliate_id );
		if ( '' === $base ) {
			$aff  = AffiliateRepository::find( (int) $affiliate_id );
			$base = $aff ? CodeRepository::code_for_coupon( '' !== (string) $aff->slug ? $aff->slug : $aff->name ) : CodeRepository::random_code();
		}
		$base = strtoupper( substr( $base, 0, 60 ) );
		$code = $base;
		for ( $i = 2; $i < 100 && self::taken( $code ); $i++ ) {
			$code = $base . $i;
		}
		return self::taken( $code ) ? CodeRepository::random_code() : $code;
	}

	/**
	 * Whether a coupon code is in use (WooCommerce coupon or any partner code link).
	 *
	 * @param string $coupon Coupon code.
	 * @return string '' | 'exists' | 'linked'
	 */
	private static function taken( $coupon ) {
		if ( function_exists( 'wc_get_coupon_id_by_code' ) && wc_get_coupon_id_by_code( $coupon ) ) {
			return 'exists';
		}
		return CodeRepository::find_by_coupon( $coupon, false ) ? 'linked' : '';
	}

	/**
	 * Partner codes a new coupon can be linked to: non-batch, active codes without coupon.
	 *
	 * @param int $affiliate_id Partner.
	 * @return array Code rows.
	 */
	public static function linkable_codes( $affiliate_id ) {
		$out = array();
		foreach ( CodeRepository::for_affiliate( $affiliate_id ) as $row ) {
			if ( '' === (string) $row->batch && 'active' === $row->status && '' === (string) $row->coupon_code ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * Create a WooCommerce coupon for a partner and link it to a partner code.
	 *
	 * @param int   $affiliate_id Partner.
	 * @param array $input        coupon_code ('' = suggestion), code_id (0 = new partner code), and
	 *                            template fields overriding the settings.
	 * @return array|\WP_Error { coupon_id, coupon_code, code_id }
	 */
	public static function create( $affiliate_id, array $input ) {
		$affiliate_id = (int) $affiliate_id;
		if ( ! self::available() ) {
			return new \WP_Error( 'wpac_no_woocommerce', __( 'Coupons can only be created while WooCommerce is active.', 'affiliate-captain' ) );
		}
		if ( ! self::can_create() ) {
			return new \WP_Error( 'wpac_forbidden', __( 'You are not allowed to create coupons.', 'affiliate-captain' ) );
		}
		$aff = AffiliateRepository::find( $affiliate_id );
		if ( ! $aff ) {
			return new \WP_Error( 'wpac_not_found', __( 'Partner not found.', 'affiliate-captain' ) );
		}
		if ( 'active' !== $aff->status || \WPAffiliateCaptain\Privacy\Restriction::is_restricted( $affiliate_id ) ) {
			return new \WP_Error( 'wpac_partner_inactive', __( 'Coupons can only be created for active partners (not paused, terminated or restricted).', 'affiliate-captain' ) );
		}

		$coupon = isset( $input['coupon_code'] ) ? trim( sanitize_text_field( (string) $input['coupon_code'] ) ) : '';
		$coupon = '' === $coupon ? self::suggest_code( $affiliate_id ) : $coupon;
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $coupon, 'UTF-8' ) : strlen( $coupon );
		if ( $length < 3 || $length > 100 ) {
			return new \WP_Error( 'wpac_coupon_invalid', __( 'The coupon code needs 3 to 100 characters.', 'affiliate-captain' ) );
		}
		$taken = self::taken( $coupon );
		if ( 'exists' === $taken ) {
			/* translators: %s: coupon code */
			return new \WP_Error( 'wpac_coupon_exists', sprintf( __( 'A WooCommerce coupon "%s" already exists. Choose another code, or link the existing coupon to a partner code.', 'affiliate-captain' ), $coupon ) );
		}
		if ( 'linked' === $taken ) {
			/* translators: %s: coupon code */
			return new \WP_Error( 'wpac_coupon_taken', sprintf( __( 'The coupon code "%s" is already linked to a partner code. Please choose another code.', 'affiliate-captain' ), $coupon ) );
		}

		$code_id = isset( $input['code_id'] ) ? absint( $input['code_id'] ) : 0;
		if ( $code_id ) {
			$row = CodeRepository::find( $code_id );
			if ( ! $row || (int) $row->affiliate_id !== $affiliate_id || '' !== (string) $row->batch || 'active' !== $row->status || '' !== (string) $row->coupon_code ) {
				return new \WP_Error( 'wpac_code_invalid', __( 'Please choose an active partner code of this partner without a linked coupon, or "new partner code".', 'affiliate-captain' ) );
			}
		}

		$args = self::clean( $input, self::template(), $errors );
		if ( $errors ) {
			return new \WP_Error( 'wpac_coupon_args', implode( ' ', $errors ) );
		}
		/**
		 * Adjust the properties of a partner coupon before it is created. Keys are WC_Coupon
		 * properties (set_{key}() is called), e.g. product_ids, email_restrictions, description.
		 * "expiry_days" is turned into date_expires.
		 *
		 * @param array  $args         Properties.
		 * @param object $affiliate    Partner row.
		 * @param string $coupon       Coupon code.
		 */
		$args = (array) apply_filters( 'wpac_partner_coupon_args', $args, $aff, $coupon );

		$wc = null;
		try {
			$wc = new \WC_Coupon();
			$wc->set_code( $coupon );
			/* translators: %s: partner name */
			$wc->set_description( sprintf( __( 'Partner coupon of %s (Affiliate Captain)', 'affiliate-captain' ), $aff->name ) );
			foreach ( $args as $prop => $value ) {
				if ( 'expiry_days' === $prop ) {
					if ( (int) $value > 0 ) {
						$wc->set_date_expires( time() + (int) $value * DAY_IN_SECONDS );
					}
					continue;
				}
				$setter = 'set_' . $prop;
				if ( method_exists( $wc, $setter ) ) {
					$wc->$setter( in_array( $prop, self::FLAGS, true ) ? (bool) $value : $value );
				}
			}
			$wc->update_meta_data( self::META_AFFILIATE, $affiliate_id );
			$coupon_id = (int) $wc->save();
		} catch ( \Exception $e ) {
			/* translators: %s: technical reason */
			return new \WP_Error( 'wpac_coupon_failed', sprintf( __( 'WooCommerce could not create the coupon: %s', 'affiliate-captain' ), $e->getMessage() ) );
		}
		if ( ! $coupon_id ) {
			return new \WP_Error( 'wpac_coupon_failed', __( 'WooCommerce could not create the coupon. Nothing was changed.', 'affiliate-captain' ) );
		}

		// Link (same as the manual link of a code), then check it like a manual link: the coupon
		// exists and no other code got it meanwhile. Otherwise undo everything.
		$new_code = 0;
		if ( $code_id ) {
			$linked = CodeRepository::set_coupon( $code_id, $coupon );
		} else {
			$created  = CodeRepository::create( $affiliate_id, CodeRepository::code_for_coupon( $coupon ), array( 'coupon_code' => $coupon ) );
			$linked   = ! is_wp_error( $created );
			$new_code = $linked ? (int) $created : 0;
			$code_id  = $new_code;
		}
		$check = $linked ? CodeRepository::check_coupon( $coupon, $code_id ) : false;
		if ( true !== $check ) {
			global $wpdb;
			if ( $new_code ) {
				$wpdb->delete( Db::table( 'partner_codes' ), array( 'id' => $new_code ) );
			} elseif ( $code_id && $linked ) {
				$wpdb->update( Db::table( 'partner_codes' ), array( 'coupon_code' => '' ), array( 'id' => $code_id ) );
			}
			$wc->delete( false ); // Into the trash: nothing is lost if the cause was temporary.
			Audit::log(
				'code.coupon_create_failed',
				'affiliate',
				$affiliate_id,
				array(
					'coupon'    => $coupon,
					'coupon_id' => $coupon_id,
				)
			);
			return new \WP_Error( 'wpac_coupon_link_failed', is_wp_error( $check ) ? $check->get_error_message() . ' ' . __( 'The new coupon was moved to the trash.', 'affiliate-captain' ) : __( 'The coupon could not be linked to the partner code and was moved to the trash. Please try again.', 'affiliate-captain' ) );
		}

		Audit::log(
			'code.coupon_created',
			'affiliate',
			$affiliate_id,
			array(
				'coupon'    => $coupon,
				'coupon_id' => $coupon_id,
				'code_id'   => $code_id,
				'args'      => array_intersect_key( $args, array_flip( self::FIELDS ) ),
			)
		);
		return array(
			'coupon_id'   => $coupon_id,
			'coupon_code' => $coupon,
			'code_id'     => $code_id,
		);
	}

	/**
	 * Admin link to edit a coupon.
	 *
	 * @param int $coupon_id Coupon post ID.
	 * @return string
	 */
	public static function edit_url( $coupon_id ) {
		return admin_url( 'post.php?post=' . (int) $coupon_id . '&action=edit' );
	}

}
