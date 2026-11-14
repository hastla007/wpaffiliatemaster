<?php
/**
 * Decides which affiliate (if any) a sale is attributed to.
 *
 * Priority:
 *  1. explicit affiliate (manual admin assignment / integration)
 *  2. partner code entered at checkout or passed by an app/integration
 *  3. affiliate coupon (if "coupon has priority" is enabled)
 *  4. referral link (shop session) or consented cookie – first/last touch per setting
 *  5. affiliate coupon (if coupon priority is disabled)
 *  6. lifetime customer link (if enabled)
 *
 * Self-referrals are blocked or flagged for review.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Tracking;

use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class AttributionResolver {

	/**
	 * Resolve.
	 *
	 * @param array $sale Normalized sale payload.
	 * @return array { attribution: array|null, reason: string }
	 */
	public static function resolve( array $sale ) {
		$candidates = array();

		if ( ! empty( $sale['affiliate_id'] ) ) {
			$aff = AffiliateRepository::find( (int) $sale['affiliate_id'] );
			if ( self::eligible( $aff ) ) {
				$candidates[] = array(
					'affiliate_id' => (int) $aff->id,
					'code_id'      => 0,
					'method'       => ! empty( $sale['attribution_method'] ) ? $sale['attribution_method'] : 'manual',
				);
			}
		}

		if ( ! empty( $sale['partner_code'] ) ) {
			$r = wpac_resolve_partner_code( $sale['partner_code'], array( 'context' => 'sale', 'source' => $sale['source'] ) );
			if ( $r ) {
				$candidates[] = $r + array( 'method' => ! empty( $sale['partner_code_method'] ) ? $sale['partner_code_method'] : 'code' );
			}
		}

		$coupon = null;
		foreach ( $sale['coupon_codes'] as $coupon_code ) {
			$r = apply_filters( 'wpac_coupon_affiliate', null, $coupon_code );
			if ( $r ) {
				$coupon = $r + array( 'method' => 'coupon' );
				break;
			}
		}
		$coupon_first = (bool) Settings::get( 'coupon_priority' );
		if ( $coupon && $coupon_first ) {
			$candidates[] = $coupon;
		}

		if ( ! empty( $sale['touch']['code'] ) ) {
			$r = wpac_resolve_partner_code( $sale['touch']['code'], array( 'context' => 'touch', 'source' => $sale['source'] ) );
			if ( $r ) {
				$candidates[] = $r + array(
					'method'     => in_array( $sale['touch']['method'], array( 'link', 'cookie', 'app' ), true ) ? $sale['touch']['method'] : 'link',
					'campaign'   => isset( $sale['touch']['campaign'] ) ? (string) $sale['touch']['campaign'] : '',
					'content_id' => isset( $sale['touch']['content_id'] ) ? (int) $sale['touch']['content_id'] : 0,
				);
			}
		}

		if ( $coupon && ! $coupon_first ) {
			$candidates[] = $coupon;
		}

		// Lifetime link: lowest priority, only when no explicit/new referral exists.
		if ( ! $candidates && Settings::get( 'lifetime_enabled' ) ) {
			$link = CustomerLinks::find( CustomerLinks::hashes( $sale['customer']['user_id'], $sale['customer']['email'] ) );
			if ( $link ) {
				$aff = AffiliateRepository::find( (int) $link->affiliate_id );
				if ( self::eligible( $aff ) ) {
					$candidates[] = array(
						'affiliate_id' => (int) $aff->id,
						'code_id'      => 0,
						'method'       => 'lifetime',
					);
				}
			}
		}

		/**
		 * Filter attribution candidates in priority order (first wins).
		 *
		 * @param array $candidates Candidates.
		 * @param array $sale       Sale.
		 */
		$candidates = apply_filters( 'wpac_attribution_candidates', $candidates, $sale );
		$offered    = is_array( $candidates ) && (bool) $candidates;
		// Whatever added a candidate (coupon links, integrations, filters): only active partners
		// whose processing is not restricted (Art. 18 GDPR) can earn new commissions.
		$candidates = array_values(
			array_filter(
				(array) $candidates,
				static function ( $c ) {
					return is_array( $c ) && ! empty( $c['affiliate_id'] ) && self::eligible( AffiliateRepository::find( (int) $c['affiliate_id'] ) );
				}
			)
		);
		if ( ! $candidates ) {
			// Why (shown on the order): a partner was found but may not earn, a code/link/partner
			// was given but is unknown or not usable, or there was no referral at all.
			$reason = 'no_candidate';
			if ( $offered || ! empty( $sale['affiliate_id'] ) ) {
				$reason = 'partner_not_eligible';
			} elseif ( ! empty( $sale['partner_code'] ) || ! empty( $sale['touch']['code'] ) ) {
				$reason = 'code_not_found';
			}
			return array(
				'attribution' => null,
				'reason'      => $reason,
			);
		}

		$winner = array_merge(
			array(
				'code_id'     => 0,
				'campaign'    => '',
				'content_id'  => 0,
				'consent'     => 'cookie' === $candidates[0]['method'] ? 1 : 0,
				'touch_model' => (string) Settings::get( 'touch_model' ),
				'review'      => false,
				'note'        => '',
			),
			$candidates[0]
		);

		if ( self::is_self_referral( (int) $winner['affiliate_id'], $sale ) ) {
			if ( 'review' === Settings::get( 'self_referral' ) ) {
				$winner['review'] = true;
				$winner['note']   = 'self_referral';
			} else {
				return array(
					'attribution' => null,
					'reason'      => 'self_referral',
				);
			}
		}

		return array(
			'attribution' => $winner,
			'reason'      => '',
		);
	}

	/**
	 * Whether a partner may earn new commissions: active and processing not restricted.
	 *
	 * @param object|null $aff Partner row.
	 * @return bool
	 */
	public static function eligible( $aff ) {
		return $aff && 'active' === $aff->status && ! \WPAffiliateCaptain\Privacy\Restriction::is_restricted( (int) $aff->id );
	}

	/**
	 * Compare affiliate account with the buyer (WP user, order account, billing e-mail).
	 *
	 * @param int   $affiliate_id Affiliate.
	 * @param array $sale         Sale.
	 * @return bool
	 */
	public static function is_self_referral( $affiliate_id, array $sale ) {
		$aff = AffiliateRepository::find( $affiliate_id );
		if ( ! $aff ) {
			return false;
		}
		$customer_user = isset( $sale['customer']['user_id'] ) ? (int) $sale['customer']['user_id'] : 0;
		if ( $customer_user && (int) $aff->user_id === $customer_user ) {
			return true;
		}
		$email = isset( $sale['customer']['email'] ) ? AffiliateRepository::comparable_email( $sale['customer']['email'] ) : '';
		if ( '' !== $email ) {
			if ( $email === AffiliateRepository::comparable_email( $aff->email ) ) {
				return true;
			}
			if ( $aff->user_id ) {
				$user = get_userdata( (int) $aff->user_id );
				if ( $user && AffiliateRepository::comparable_email( $user->user_email ) === $email ) {
					return true;
				}
			}
		}
		return (bool) apply_filters( 'wpac_is_self_referral', false, $affiliate_id, $sale );
	}
}
