<?php
/**
 * Fraud rules. A hit does not block the sale: its commissions are created with
 * status "review" and the reasons are stored on the attribution, so an admin
 * decides. All thresholds are configurable (0 = rule off).
 *
 * Rules:
 *  - velocity:        more than N attributed orders of the partner within 24 hours
 *  - high_value:      order net value above a threshold
 *  - fast_conversion: order completed less than N seconds after the partner link click
 *  - refund_rate:     partner's corrections exceed X % of commissions (last 90 days, min. 5 orders)
 *  - email_domain:    buyer e-mail from a blocked / disposable domain
 *  - coupon_mismatch: an affiliate coupon of another partner was used
 *  - touch_overridden: the partner's coupon took the order away from another partner's referral link
 *  - shared_iban:     the partner's payout IBAN is also used by another partner
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Tracking;

use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Fx;
use WPAffiliateCaptain\Core\Money;
use WPAffiliateCaptain\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class FraudCheck {

	/** Common disposable e-mail domains (extend via setting or filter). */
	const DISPOSABLE = array( 'mailinator.com', 'guerrillamail.com', '10minutemail.com', 'tempmail.com', 'temp-mail.org', 'yopmail.com', 'trashmail.com', 'sharklasers.com', 'getnada.com', 'dispostable.com', 'maildrop.cc', 'throwawaymail.com', 'wegwerfmail.de', 'trash-mail.com', 'spambog.com' );

	/**
	 * Labels.
	 *
	 * @return array
	 */
	public static function labels() {
		return array(
			'self_referral'    => __( 'Self-referral', 'affiliate-captain' ),
			'velocity'         => __( 'Unusually many orders in 24 hours', 'affiliate-captain' ),
			'high_value'       => __( 'Unusually high order value', 'affiliate-captain' ),
			'fast_conversion'  => __( 'Order immediately after the click', 'affiliate-captain' ),
			'refund_rate'      => __( 'High refund rate of the partner', 'affiliate-captain' ),
			'email_domain'     => __( 'Blocked or disposable e-mail domain', 'affiliate-captain' ),
			'coupon_mismatch'  => __( 'Coupon of another partner used', 'affiliate-captain' ),
			'touch_overridden' => __( 'Coupon replaced the referral of another partner', 'affiliate-captain' ),
			'shared_iban'      => __( 'Payout IBAN also used by another partner', 'affiliate-captain' ),
			'source_review'    => __( 'Review requested by the sales source (e.g. first order of the subscription under review)', 'affiliate-captain' ),
		);
	}

	/**
	 * Domain of an e-mail is blocked? (pure)
	 *
	 * @param string $email   E-mail.
	 * @param array  $blocked Additional blocked domains.
	 * @return bool
	 */
	public static function domain_blocked( $email, array $blocked = array() ) {
		$email = strtolower( trim( (string) $email ) );
		$at    = strrpos( $email, '@' );
		if ( false === $at ) {
			return false;
		}
		$domain = substr( $email, $at + 1 );
		foreach ( array_merge( self::DISPOSABLE, $blocked ) as $d ) {
			$d = strtolower( trim( $d ) );
			if ( '' !== $d && ( $domain === $d || substr( $domain, -strlen( '.' . $d ) ) === '.' . $d ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Evaluate rules for a resolved attribution.
	 *
	 * @param array $sale Normalized sale.
	 * @param array $attr Attribution (affiliate_id, method, ...).
	 * @return string[] Flag codes.
	 */
	public static function flags( array $sale, array $attr ) {
		global $wpdb;
		$flags = array();
		$aff   = (int) $attr['affiliate_id'];

		$max_day = (int) Settings::get( 'fraud_max_orders_day' );
		if ( $max_day > 0 ) {
			$t = Db::table( 'attributions' );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE affiliate_id = %d AND created_at >= %s", $aff, Db::now( time() - DAY_IN_SECONDS ) ) );
			if ( $n + 1 > $max_day ) {
				$flags[] = 'velocity';
			}
		}

		$max_value = (int) Settings::get( 'fraud_max_order_minor' );
		if ( $max_value > 0 ) {
			$net = 0;
			foreach ( $sale['items'] as $item ) {
				$net += (int) $item['net_minor'];
			}
			$conv = Fx::convert( $net, $sale['currency'] );
			if ( null !== $conv && $conv > $max_value ) {
				$flags[] = 'high_value';
			} elseif ( null === $conv ) {
				self::warn_missing_rate( (string) $sale['currency'] );
			}
		}

		$min_seconds = (int) Settings::get( 'fraud_min_click_seconds' );
		if ( $min_seconds > 0 && ! empty( $sale['touch']['ts'] ) && in_array( $attr['method'], array( 'link', 'cookie' ), true ) ) {
			$ordered = $sale['paid_at'] ? strtotime( $sale['paid_at'] . ' UTC' ) : time();
			if ( $ordered - (int) $sale['touch']['ts'] < $min_seconds ) {
				$flags[] = 'fast_conversion';
			}
		}

		$max_rate = (float) Settings::get( 'fraud_max_refund_rate' );
		if ( $max_rate > 0 ) {
			$row = self::refund_stats( $aff );
			if ( (int) $row['orders'] >= 5 && (int) $row['sales'] > 0 && abs( (int) $row['corr'] ) * 100 / (int) $row['sales'] > $max_rate ) {
				$flags[] = 'refund_rate';
			}
		}

		if ( Settings::get( 'fraud_check_domains' ) && ! empty( $sale['customer']['email'] ) ) {
			$blocked = array_filter( array_map( 'trim', preg_split( '/[\s,]+/', (string) Settings::get( 'fraud_blocked_domains' ) ) ) );
			if ( self::domain_blocked( $sale['customer']['email'], $blocked ) ) {
				$flags[] = 'email_domain';
			}
		}

		foreach ( $sale['coupon_codes'] as $coupon ) {
			$r = apply_filters( 'wpac_coupon_affiliate', null, $coupon );
			if ( $r && (int) $r['affiliate_id'] !== $aff ) {
				$flags[] = 'coupon_mismatch';
				break;
			}
		}

		// "Coupon has priority": the partner's coupon beat another partner's referral link/cookie.
		if ( 'coupon' === $attr['method'] && ! empty( $sale['touch']['code'] ) ) {
			$touch = wpac_resolve_partner_code( $sale['touch']['code'], array( 'context' => 'touch', 'source' => isset( $sale['source'] ) ? $sale['source'] : '' ) );
			if ( $touch && (int) $touch['affiliate_id'] !== $aff ) {
				$flags[] = 'touch_overridden';
			}
		}

		if ( \WPAffiliateCaptain\Payouts\ProfileRepository::shared_iban( $aff ) ) {
			$flags[] = 'shared_iban';
		}

		/**
		 * Filter fraud flags (add own rules).
		 *
		 * @param string[] $flags Flags.
		 * @param array    $sale  Sale.
		 * @param array    $attr  Attribution.
		 */
		return array_values( array_unique( (array) apply_filters( 'wpac_fraud_flags', $flags, $sale, $attr ) ) );
	}

	/**
	 * Refund figures of a partner over the last 90 days (orders, sales, corrections). Cached for
	 * a few hours: the rule is a trend indicator, and reading 90 days of commissions on every
	 * checkout does not scale.
	 *
	 * @param int $affiliate_id Partner.
	 * @return array { orders, sales, corr }
	 */
	public static function refund_stats( $affiliate_id ) {
		global $wpdb;
		$key    = 'wpac_refund_stats_' . (int) $affiliate_id;
		$cached = get_transient( $key );
		if ( is_array( $cached ) && isset( $cached['orders'], $cached['sales'], $cached['corr'] ) ) {
			return $cached;
		}
		$c = Db::table( 'commissions' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(DISTINCT CASE WHEN kind = 'sale' THEN source_order_id END) orders, SUM(CASE WHEN kind = 'sale' THEN amount_minor ELSE 0 END) sales, SUM(CASE WHEN kind IN ('refund','cancellation','chargeback') THEN amount_minor ELSE 0 END) corr FROM {$c} WHERE affiliate_id = %d AND status <> 'rejected' AND created_at >= %s", $affiliate_id, Db::now( time() - 90 * DAY_IN_SECONDS ) ) );
		$stats = array(
			'orders' => $row ? (int) $row->orders : 0,
			'sales'  => $row ? (int) $row->sales : 0,
			'corr'   => $row ? (int) $row->corr : 0,
		);
		set_transient( $key, $stats, 6 * HOUR_IN_SECONDS );
		return $stats;
	}

	/**
	 * The high-value rule cannot check an order in a currency without exchange rate: say so in
	 * the log (once a day per currency) instead of skipping it silently.
	 *
	 * @param string $currency Currency.
	 */
	private static function warn_missing_rate( $currency ) {
		$key = 'wpac_fx_missing_' . strtolower( substr( preg_replace( '/[^A-Za-z]/', '', $currency ), 0, 3 ) );
		if ( get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, DAY_IN_SECONDS );
		\WPAffiliateCaptain\Core\Log::warning(
			'Fraud rule "high value" skipped: no exchange rate for the order currency',
			array( 'currency' => $currency )
		);
	}
}
