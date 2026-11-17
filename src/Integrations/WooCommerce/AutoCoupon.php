<?php
/**
 * WooCommerce integration: apply the partner's linked coupon automatically when a visitor came
 * through the partner's link (setting "Apply the partner's coupon automatically", default off).
 *
 * The coupon is applied once per shop session, only with a valid referral of this session
 * (Tracker::current_touch(): shop session or consented cookie), never for the partner's own
 * purchases or for paused/blocked partners, only when WooCommerce itself accepts the coupon
 * (validity, usage limits, minimum spend) and never at the expense of a coupon the customer
 * entered (individual use). The order remembers that the coupon was applied this way.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Integrations\WooCommerce;

use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Tracking\AttributionResolver;
use WPAffiliateCaptain\Tracking\Tracker;

defined( 'ABSPATH' ) || exit;

final class AutoCoupon {

	/** Shop session key: state of the automatic coupon in this session. */
	const SESSION_KEY = 'wpac_auto_coupon';

	/** Order meta: coupon applied automatically { coupon, affiliate_id }. */
	const META_ORDER = '_wpac_auto_coupon';

	/** @var bool Re-entrancy guard (validating a coupon reads the cart). */
	private static $running = false;

	/** @var bool The plugin itself removes a coupon (not the customer). */
	private static $removing = false;

	/**
	 * Hooks (registered with the WooCommerce integration; the setting is checked inside).
	 */
	public static function register_hooks() {
		// After Tracker::capture_on_add_to_cart() (priority 5): the referral of this request is stored.
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'on_add_to_cart' ), 20, 0 );
		// An existing cart (classic pages, Cart/Checkout blocks via the Store API).
		add_action( 'woocommerce_cart_loaded_from_session', array( __CLASS__, 'on_cart_loaded' ), 20, 0 );
		add_action( 'woocommerce_removed_coupon', array( __CLASS__, 'coupon_removed' ), 10, 1 );
		// At most one partner coupon per cart (R15-S5; also without the automatic coupon).
		add_filter( 'woocommerce_coupon_is_valid', array( __CLASS__, 'one_partner_coupon' ), 20, 3 );
		add_action( 'woocommerce_applied_coupon', array( __CLASS__, 'coupon_applied' ), 10, 1 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'mark_order' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'mark_order' ), 20, 1 );
	}

	/**
	 * Whether the feature is switched on.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) Settings::get( 'auto_apply_coupon' );
	}

	/**
	 * A product was added to the cart: always evaluated (a new referral may have been captured
	 * in this very request).
	 */
	public static function on_add_to_cart() {
		self::maybe_apply( true );
	}

	/**
	 * The cart was loaded from the session: only while nothing was decided in this session yet,
	 * and a coupon WooCommerce refused is tried again only after the cart changed (minimum spend).
	 */
	public static function on_cart_loaded() {
		self::maybe_apply( false );
	}

	/**
	 * Apply the partner's coupon if every condition holds.
	 *
	 * @param bool $adding Called from add-to-cart.
	 * @return string Result for diagnostics and tests: applied|disabled|no_cart|done|unchanged|no_referral|self|no_coupon|filtered|present|individual|invalid|failed|busy.
	 */
	public static function maybe_apply( $adding = false ) {
		if ( ! self::enabled() ) {
			return 'disabled';
		}
		if ( self::$running ) {
			return 'busy';
		}
		if ( ! function_exists( 'WC' ) || ! WC() || empty( WC()->cart ) || empty( WC()->session ) || ! class_exists( 'WC_Coupon' ) ) {
			return 'no_cart';
		}
		$cart = WC()->cart;
		if ( $cart->is_empty() || ( function_exists( 'wc_coupons_enabled' ) && ! wc_coupons_enabled() ) ) {
			return 'no_cart';
		}
		$state = self::state();
		if ( ! $adding && ( ! empty( $state['applied'] ) || ! empty( $state['removed'] ) ) && ! self::touch_changed( $state ) ) {
			return 'done';
		}
		// Page loads with an unchanged cart and referral repeat no checks (no queries per request).
		$signature = self::signature( $cart );
		if ( ! $adding && isset( $state['tried'] ) && $state['tried'] === $signature ) {
			return 'unchanged';
		}
		self::$running = true;
		try {
			$result = self::apply( $cart, $state );
		} finally {
			self::$running = false;
		}
		$after = self::state();
		if ( 'applied' !== $result ) {
			// Also after "present" (another partner coupon in the cart, R15-S2): page loads with the
			// same cart and referral repeat nothing.
			$after['tried'] = $signature;
		}
		$after['touch'] = self::touch_key(); // The referral this decision was made for (touch_changed()).
		self::save_state( $after );
		return $result;
	}

	/**
	 * Whether the referral changed to another partner since the coupon was applied (or removed by
	 * the customer): a later link of another partner (last touch) replaces the coupon the plugin
	 * applied also when no product is added, so coupon and link agree (R15-S2). Page loads with
	 * the same referral cost no query (key of session referral and cookie).
	 *
	 * @param array $state Session state.
	 * @return bool
	 */
	private static function touch_changed( array $state ) {
		$key = self::touch_key();
		if ( isset( $state['touch'] ) && $state['touch'] === $key ) {
			return false;
		}
		$touch    = Tracker::current_touch();
		$resolved = $touch ? wpac_resolve_partner_code( (string) $touch['code'], array( 'context' => 'touch' ) ) : null;
		$changed  = $resolved && ! empty( $resolved['affiliate_id'] ) && (int) $resolved['affiliate_id'] !== ( isset( $state['affiliate_id'] ) ? (int) $state['affiliate_id'] : 0 );
		if ( ! $changed ) {
			$state['touch'] = $key; // Same partner (or no valid referral): not checked again until it changes.
			self::save_state( $state );
		}
		return $changed;
	}

	/**
	 * Decide and apply (see maybe_apply()).
	 *
	 * @param object $cart  WC_Cart.
	 * @param array  $state Session state.
	 * @return string
	 */
	private static function apply( $cart, array $state ) {
		$touch    = Tracker::current_touch();
		$resolved = $touch ? wpac_resolve_partner_code( (string) $touch['code'], array( 'context' => 'touch' ) ) : null;
		if ( ! $resolved ) {
			return 'no_referral';
		}
		$affiliate_id = (int) $resolved['affiliate_id'];
		$applied      = array_map( array( CodeRepository::class, 'normalize_coupon' ), (array) $cart->get_applied_coupons() );

		if ( ! empty( $state['affiliate_id'] ) && (int) $state['affiliate_id'] === $affiliate_id && ( ! empty( $state['applied'] ) || ! empty( $state['removed'] ) ) ) {
			return 'done'; // Once per session and partner; a coupon the customer removed is not added again.
		}
		if ( $state && ( empty( $state['affiliate_id'] ) || (int) $state['affiliate_id'] !== $affiliate_id ) ) {
			// A later link of another partner (last touch): the coupon the plugin applied for the
			// previous partner goes (never one the customer entered), so coupon and link agree.
			$previous = ! empty( $state['applied'] ) ? CodeRepository::normalize_coupon( (string) $state['coupon'] ) : '';
			if ( '' !== $previous && in_array( $previous, $applied, true ) ) {
				self::$removing = true;
				$cart->remove_coupon( $previous );
				self::$removing = false;
				$applied        = array_values( array_diff( $applied, array( $previous ) ) );
			}
			$state = array();
			self::save_state( $state );
		}
		$state['affiliate_id'] = $affiliate_id;
		if ( self::is_self( $affiliate_id ) ) {
			return 'self';
		}
		$coupon = self::coupon_for( $affiliate_id, isset( $resolved['code_id'] ) ? (int) $resolved['code_id'] : 0 );
		if ( '' === $coupon ) {
			return 'no_coupon';
		}
		/**
		 * Whether the partner's coupon is applied automatically for this visitor.
		 *
		 * @since 0.15.0
		 * @param bool   $apply        Default true (all checks passed).
		 * @param string $coupon_code  Coupon code.
		 * @param int    $affiliate_id Partner.
		 */
		if ( ! apply_filters( 'wpac_auto_apply_coupon', true, $coupon, $affiliate_id ) ) {
			return 'filtered';
		}
		$normalized = CodeRepository::normalize_coupon( $coupon );
		if ( in_array( $normalized, $applied, true ) ) {
			// Already in the cart (e.g. typed by the customer): nothing to do, nothing to report.
			self::save_state(
				array(
					'coupon'       => $coupon,
					'affiliate_id' => $affiliate_id,
					'applied'      => 0,
					'removed'      => 1,
				)
			);
			return 'present';
		}
		$wc = new \WC_Coupon( $coupon );
		if ( ! $wc->get_id() ) {
			return 'no_coupon';
		}
		foreach ( $applied as $other ) {
			if ( self::partner_of( $other ) ) {
				return 'present'; // Another partner coupon is in the cart: never two partner discounts.
			}
			$existing = new \WC_Coupon( $other );
			if ( $existing->get_individual_use() ) {
				return 'individual'; // The customer's coupon cannot be combined.
			}
		}
		if ( $applied && $wc->get_individual_use() ) {
			return 'individual'; // Applying it would remove the customer's coupons.
		}
		if ( class_exists( 'WC_Discounts' ) ) {
			$valid = ( new \WC_Discounts( $cart ) )->is_coupon_valid( $wc );
			if ( true !== $valid ) {
				// Expired, used up, minimum spend not reached …: silently, tried again after a cart change.
				return 'invalid';
			}
		}

		$notices = function_exists( 'wc_get_notices' ) ? wc_get_notices() : null;
		$message = static function ( $msg, $code = 0 ) use ( $wc ) {
			if ( class_exists( 'WC_Coupon' ) && \WC_Coupon::WC_COUPON_SUCCESS === (int) $code ) {
				/* translators: %s: coupon code */
				return sprintf( __( 'Your partner discount (coupon %s) was applied automatically.', 'affiliate-captain' ), $wc->get_code() );
			}
			return $msg;
		};
		add_filter( 'woocommerce_coupon_message', $message, 10, 2 );
		$ok = (bool) $cart->apply_coupon( $coupon );
		remove_filter( 'woocommerce_coupon_message', $message, 10 );
		if ( null !== $notices && ( ! $ok || self::rest_request() ) && function_exists( 'wc_set_notices' ) ) {
			// No error notice for a coupon that was not applied; in Store API requests (Cart/Checkout
			// blocks show the coupon themselves) no notice that would show up later on another page.
			wc_set_notices( $notices );
		}
		if ( ! $ok ) {
			return 'failed';
		}
		self::save_state(
			array(
				'coupon'       => $coupon,
				'affiliate_id' => $affiliate_id,
				'applied'      => 1,
				'removed'      => 0,
			)
		);
		return 'applied';
	}

	/**
	 * Coupon of a partner that is applied automatically: the coupon linked to the code of the
	 * referral, else the partner's first linked coupon – only one that attributes the order to the
	 * same partner (link active, coupon exists, code usable), so coupon and link always agree.
	 *
	 * @param int $affiliate_id Partner.
	 * @param int $code_id      Code row of the referral (0 = none).
	 * @return string Coupon code ('' = none).
	 */
	public static function coupon_for( $affiliate_id, $code_id = 0 ) {
		global $wpdb;
		$affiliate_id = (int) $affiliate_id;
		if ( ! $affiliate_id ) {
			return '';
		}
		$candidates = array();
		$row        = $code_id ? CodeRepository::find( (int) $code_id ) : null;
		if ( $row && (int) $row->affiliate_id === $affiliate_id && '' !== (string) $row->coupon_code ) {
			$candidates[] = (string) $row->coupon_code;
		}
		$t = Db::table( 'partner_codes' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT coupon_code FROM {$t} WHERE affiliate_id = %d AND status = 'active' AND coupon_code <> '' ORDER BY id ASC LIMIT 5", $affiliate_id ) ) as $coupon ) {
			$candidates[] = (string) $coupon;
		}
		foreach ( array_unique( $candidates ) as $coupon ) {
			if ( self::partner_of( $coupon ) === $affiliate_id ) {
				return $coupon;
			}
		}
		return '';
	}

	/**
	 * Partner a coupon attributes an order to (same resolution as the attribution), 0 = none.
	 *
	 * @param string $coupon Coupon code.
	 * @return int
	 */
	private static function partner_of( $coupon ) {
		$r = apply_filters( 'wpac_coupon_affiliate', null, (string) $coupon );
		return is_array( $r ) && ! empty( $r['affiliate_id'] ) ? (int) $r['affiliate_id'] : 0;
	}

	/**
	 * Whether the visitor is the partner (logged-in account or the e-mail address of the shop
	 * customer): the partner's own purchases never get the partner discount automatically.
	 *
	 * @param int $affiliate_id Partner.
	 * @return bool
	 */
	private static function is_self( $affiliate_id ) {
		$email = '';
		if ( ! empty( WC()->customer ) && method_exists( WC()->customer, 'get_billing_email' ) ) {
			$email = (string) WC()->customer->get_billing_email();
		}
		$user = Tracker::visitor_user_id();
		if ( ! $user && '' === $email ) {
			return false;
		}
		return AttributionResolver::is_self_referral(
			(int) $affiliate_id,
			array(
				'customer' => array(
					'user_id' => $user,
					'email'   => $email,
				),
			)
		);
	}

	/**
	 * The customer removed a coupon: the automatic one is not applied again in this session.
	 *
	 * @param string $coupon Coupon code.
	 */
	public static function coupon_removed( $coupon ) {
		if ( self::$removing || ! function_exists( 'WC' ) || ! WC() || empty( WC()->session ) ) {
			return;
		}
		$state = self::state();
		if ( ! empty( $state['coupon'] ) && CodeRepository::normalize_coupon( (string) $state['coupon'] ) === CodeRepository::normalize_coupon( (string) $coupon ) ) {
			$state['applied'] = 0;
			$state['removed'] = 1;
			self::save_state( $state );
		}
	}

	/**
	 * Filter "woocommerce_coupon_is_valid": at most one partner coupon per cart (R15-S5). A
	 * second coupon that credits a partner (any partner, as the attribution resolves it) is
	 * refused while another partner coupon is in the cart – except the one the plugin applied
	 * automatically, which the customer's coupon replaces (coupon_applied()). When the cart is
	 * checked again, only a partner coupon after another one in the cart is refused, so the first
	 * one stays. Costs nothing for carts with fewer than two coupons.
	 *
	 * @param bool   $valid     Valid so far.
	 * @param object $coupon    WC_Coupon.
	 * @param object $discounts WC_Discounts.
	 * @return bool
	 * @throws \Exception Refusal with the message for the customer (WooCommerce shows it).
	 */
	public static function one_partner_coupon( $valid, $coupon = null, $discounts = null ) {
		if ( ! $valid || self::$running || ! is_object( $coupon ) || ! method_exists( $coupon, 'get_code' ) ) {
			return $valid;
		}
		$cart = is_object( $discounts ) && method_exists( $discounts, 'get_object' ) ? $discounts->get_object() : ( function_exists( 'WC' ) && WC() && ! empty( WC()->cart ) ? WC()->cart : null );
		if ( ! is_object( $cart ) || ! method_exists( $cart, 'get_applied_coupons' ) ) {
			return $valid; // An order edited in the admin: the shop manager decides.
		}
		$code    = CodeRepository::normalize_coupon( (string) $coupon->get_code() );
		$applied = array_map( array( CodeRepository::class, 'normalize_coupon' ), (array) $cart->get_applied_coupons() );
		$pos     = array_search( $code, $applied, true );
		$before  = false === $pos ? $applied : array_slice( $applied, 0, (int) $pos );
		if ( ! $before ) {
			return $valid;
		}
		/**
		 * Whether a cart may hold only one partner coupon (a coupon that credits a partner).
		 *
		 * @since 0.16.0
		 * @param bool   $single Default true.
		 * @param string $coupon Coupon code being checked.
		 */
		if ( ! apply_filters( 'wpac_single_partner_coupon', true, $code ) || ! self::partner_of( $code ) ) {
			return $valid;
		}
		$auto = '';
		if ( false === $pos && function_exists( 'WC' ) && WC() && ! empty( WC()->session ) ) {
			$state = self::state();
			$auto  = ! empty( $state['applied'] ) && ! empty( $state['coupon'] ) ? CodeRepository::normalize_coupon( (string) $state['coupon'] ) : '';
		}
		foreach ( $before as $other ) {
			if ( $other !== $code && $other !== $auto && self::partner_of( $other ) ) {
				/* translators: %s: coupon code already in the cart */
				throw new \Exception( esc_html( sprintf( __( 'Only one partner coupon can be used per order. Please remove the coupon %s first.', 'affiliate-captain' ), $other ) ), 100 );
			}
		}
		return $valid;
	}

	/**
	 * The customer applied a coupon: if it credits a partner, the partner coupon the plugin applied
	 * automatically goes (the customer's choice wins, never two partner discounts, R15-S5) and is
	 * not applied again in this session.
	 *
	 * @param string $coupon Coupon code.
	 */
	public static function coupon_applied( $coupon ) {
		if ( self::$running || ! function_exists( 'WC' ) || ! WC() || empty( WC()->session ) || empty( WC()->cart ) ) {
			return;
		}
		$state = self::state();
		if ( empty( $state['applied'] ) || empty( $state['coupon'] ) ) {
			return;
		}
		$auto   = CodeRepository::normalize_coupon( (string) $state['coupon'] );
		$coupon = CodeRepository::normalize_coupon( (string) $coupon );
		$cart   = WC()->cart;
		if ( $auto === $coupon || ! in_array( $auto, array_map( array( CodeRepository::class, 'normalize_coupon' ), (array) $cart->get_applied_coupons() ), true ) || ! self::partner_of( $coupon ) ) {
			return;
		}
		self::$removing = true;
		$cart->remove_coupon( $auto );
		self::$removing   = false;
		$state['applied'] = 0;
		$state['removed'] = 1;
		self::save_state( $state );
		if ( function_exists( 'wc_add_notice' ) && ! self::rest_request() ) {
			/* translators: %s: coupon code */
			wc_add_notice( sprintf( __( 'The partner discount applied automatically (coupon %s) was removed: only one partner coupon can be used per order.', 'affiliate-captain' ), $auto ), 'notice' );
		}
	}

	/**
	 * Remember on the order that it carries the automatically applied coupon (classic and block
	 * checkout). Only when the coupon is still part of the order and the referral of the session is
	 * still valid (consent not withdrawn).
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function mark_order( $order ) {
		if ( ! is_object( $order ) || ! function_exists( 'WC' ) || ! WC() || empty( WC()->session ) ) {
			return;
		}
		$state = self::state();
		if ( empty( $state['applied'] ) || empty( $state['coupon'] ) ) {
			return;
		}
		$codes = method_exists( $order, 'get_coupon_codes' ) ? (array) $order->get_coupon_codes() : ( ! empty( WC()->cart ) ? (array) WC()->cart->get_applied_coupons() : array() );
		$codes = array_map( array( CodeRepository::class, 'normalize_coupon' ), $codes );
		if ( ! in_array( CodeRepository::normalize_coupon( (string) $state['coupon'] ), $codes, true ) ) {
			return;
		}
		// Only while the referral of this session is still valid for the same partner (R15-P6): after
		// the marketing consent was withdrawn (or the referral expired) the order does not record
		// that the customer came through the partner's link.
		$touch    = Tracker::current_touch();
		$resolved = $touch ? wpac_resolve_partner_code( (string) $touch['code'], array( 'context' => 'touch' ) ) : null;
		if ( ! $resolved || (int) $resolved['affiliate_id'] !== (int) $state['affiliate_id'] ) {
			return;
		}
		$order->update_meta_data(
			self::META_ORDER,
			array(
				'coupon'       => CodeRepository::normalize_coupon( (string) $state['coupon'] ),
				'affiliate_id' => (int) $state['affiliate_id'],
			)
		);
	}

	/**
	 * Discount of a coupon as shown to partners and visitors ("10%", "5,00 €", "2,00 € per
	 * product"); '' when the coupon does not exist, has expired or is used up.
	 *
	 * @param string $coupon Coupon code.
	 * @return string
	 */
	public static function discount_label( $coupon ) {
		if ( '' === (string) $coupon || ! class_exists( 'WC_Coupon' ) ) {
			return '';
		}
		try {
			$wc = new \WC_Coupon( (string) $coupon );
		} catch ( \Throwable $e ) {
			return '';
		}
		if ( ! $wc->get_id() ) {
			return '';
		}
		$expires = $wc->get_date_expires();
		if ( $expires && is_object( $expires ) && method_exists( $expires, 'getTimestamp' ) && $expires->getTimestamp() < time() ) {
			return '';
		}
		if ( (int) $wc->get_usage_limit() > 0 && (int) $wc->get_usage_count() >= (int) $wc->get_usage_limit() ) {
			return '';
		}
		$amount = (float) $wc->get_amount();
		if ( $amount <= 0 ) {
			return '';
		}
		$type = (string) $wc->get_discount_type();
		if ( 'percent' === $type ) {
			$number = function_exists( 'wc_format_localized_decimal' ) ? (string) wc_format_localized_decimal( (string) ( $amount + 0 ) ) : (string) ( $amount + 0 );
			/* translators: %s: percentage number, e.g. 10 */
			return sprintf( _x( '%s%%', 'percentage discount', 'affiliate-captain' ), $number );
		}
		$money = function_exists( 'wc_price' ) ? html_entity_decode( wp_strip_all_tags( (string) wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' ) : number_format_i18n( $amount, 2 );
		if ( 'fixed_product' === $type ) {
			/* translators: %s: amount, e.g. 2,00 € */
			return sprintf( __( '%s per product', 'affiliate-captain' ), $money );
		}
		return $money;
	}

	/**
	 * Text for the partner area: what the partner's link gives customers ('' = nothing to show:
	 * setting off, no WooCommerce, no usable linked coupon).
	 *
	 * @param int $affiliate_id Partner.
	 * @param int $code_id      Code row of the link (0 = any).
	 * @return string
	 */
	public static function portal_text( $affiliate_id, $code_id = 0 ) {
		if ( ! self::enabled() || ! class_exists( 'WC_Coupon' ) ) {
			return '';
		}
		$coupon = self::coupon_for( (int) $affiliate_id, (int) $code_id );
		$label  = '' !== $coupon ? self::discount_label( $coupon ) : '';
		if ( '' === $label ) {
			return '';
		}
		/* translators: 1: discount, e.g. "10%" or "5,00 €", 2: coupon code */
		return sprintf( __( 'Your link gives customers %1$s off: the coupon %2$s is applied to their cart automatically.', 'affiliate-captain' ), $label, strtoupper( $coupon ) );
	}

	/**
	 * State of this shop session.
	 *
	 * @return array
	 */
	private static function state() {
		$state = WC()->session->get( self::SESSION_KEY );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Store the state of this shop session.
	 *
	 * @param array $state State.
	 */
	private static function save_state( array $state ) {
		WC()->session->set( self::SESSION_KEY, $state ? $state : null );
	}

	/**
	 * Cart contents and stored referral (session code, cookie) of this request: a decision without
	 * coupon is taken again only once one of them changed (minimum spend reached, new link).
	 *
	 * @param object $cart WC_Cart.
	 * @return string
	 */
	private static function signature( $cart ) {
		$hash = method_exists( $cart, 'get_cart_hash' ) ? (string) $cart->get_cart_hash() : md5( (string) wp_json_encode( method_exists( $cart, 'get_cart_for_session' ) ? $cart->get_cart_for_session() : array() ) );
		return md5( $hash . '|' . self::touch_key() );
	}

	/**
	 * Key of the stored referral of this request (session code with its time, cookie, user).
	 *
	 * @return string
	 */
	private static function touch_key() {
		$touch = WC()->session->get( Tracker::SESSION_KEY );
		$code  = is_array( $touch ) && isset( $touch['code'] ) ? (string) $touch['code'] . '@' . ( isset( $touch['ts'] ) ? (int) $touch['ts'] : 0 ) : '';
		$name  = Tracker::cookie_name();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- only hashed.
		$cookie = isset( $_COOKIE[ $name ] ) ? md5( (string) $_COOKIE[ $name ] ) : '';
		return md5( $code . '|' . $cookie . '|' . Tracker::visitor_user_id() );
	}

	/**
	 * Whether this is a REST request (Store API of the Cart/Checkout blocks).
	 *
	 * @return bool
	 */
	private static function rest_request() {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}
		return function_exists( 'WC' ) && WC() && method_exists( WC(), 'is_rest_api_request' ) && WC()->is_rest_api_request();
	}
}
