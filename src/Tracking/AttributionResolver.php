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
		$lifetime_tried = false;
		if ( ! $candidates ) {
			$lifetime_tried = true;
			$lifetime       = self::lifetime_candidate( $sale );
			if ( $lifetime ) {
				$candidates[] = $lifetime;
			}
		}

		/**
		 * Filter attribution candidates in priority order (first wins).
		 *
		 * @param array $candidates Candidates.
		 * @param array $sale       Sale.
		 */
		$candidates = apply_filters( 'wpac_attribution_candidates', $candidates, $sale );
		// Whatever added a candidate (coupon links, integrations, filters): only active partners
		// whose processing is not restricted (Art. 18 GDPR) can earn new commissions.
		$candidates = self::eligible_candidates( $candidates );

		// A partner who is the buyer never takes the order away from another partner: self-referral
		// candidates are skipped while a valid one (or the lifetime partner) exists.
		$self = null;
		foreach ( $candidates as $i => $c ) {
			if ( self::is_self_referral( (int) $c['affiliate_id'], $sale ) ) {
				if ( null === $self ) {
					$self = $c;
				}
				unset( $candidates[ $i ] );
			}
		}
		$candidates = array_values( $candidates );
		if ( ! $candidates && null !== $self && ! $lifetime_tried ) {
			$lifetime = self::lifetime_candidate( $sale );
			if ( $lifetime && ! self::is_self_referral( (int) $lifetime['affiliate_id'], $sale ) ) {
				$candidates[] = $lifetime;
			}
		}
		$self_winner = false;
		if ( ! $candidates && null !== $self ) {
			if ( 'review' !== Settings::get( 'self_referral' ) ) {
				return array(
					'attribution' => null,
					'reason'      => 'self_referral',
				);
			}
			$candidates[] = $self;
			$self_winner  = true;
		}
		if ( ! $candidates ) {
			return array(
				'attribution' => null,
				'reason'      => 'no_candidate',
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
		if ( $self_winner ) {
			$winner['review'] = true;
			$winner['note']   = 'self_referral';
		}

		return array(
			'attribution' => $winner,
			'reason'      => '',
		);
	}

	/**
	 * Keep only well-formed candidates of eligible partners.
	 *
	 * @param mixed $candidates Candidates.
	 * @return array
	 */
	private static function eligible_candidates( $candidates ) {
		return array_values(
			array_filter(
				(array) $candidates,
				static function ( $c ) {
					return is_array( $c ) && ! empty( $c['affiliate_id'] ) && self::eligible( AffiliateRepository::find( (int) $c['affiliate_id'] ) );
				}
			)
		);
	}

	/**
	 * Lifetime candidate of the sale's customer: the oldest active link whose partner may earn.
	 * A link to a paused/restricted partner does not hide a link of another partner.
	 *
	 * @param array $sale Sale.
	 * @return array|null
	 */
	private static function lifetime_candidate( array $sale ) {
		if ( ! Settings::get( 'lifetime_enabled' ) ) {
			return null;
		}
		$user_id = isset( $sale['customer']['user_id'] ) ? (int) $sale['customer']['user_id'] : 0;
		$email   = isset( $sale['customer']['email'] ) ? (string) $sale['customer']['email'] : '';
		foreach ( CustomerLinks::find_all( CustomerLinks::hashes( $user_id, $email ) ) as $link ) {
			$aff = AffiliateRepository::find( (int) $link->affiliate_id );
			if ( self::eligible( $aff ) ) {
				return array(
					'affiliate_id' => (int) $aff->id,
					'code_id'      => 0,
					'method'       => 'lifetime',
				);
			}
		}
		return null;
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
		// Guest order with another address or a second partner account: compare the billing name
		// and address with the partner's payout profile (name + postcode, or street + postcode).
		if ( ! empty( $sale['customer'] ) && is_array( $sale['customer'] ) ) {
			$profile = \WPAffiliateCaptain\Payouts\ProfileRepository::get( (int) $aff->id );
			if ( $profile && self::matches_profile( $sale['customer'], array( (string) $profile->legal_name, (string) $profile->account_holder, (string) $aff->name ), (string) $profile->address ) ) {
				return true;
			}
		}
		return (bool) apply_filters( 'wpac_is_self_referral', false, $affiliate_id, $sale );
	}

	/**
	 * Whether a buyer (billing name, street, postcode) matches a partner's payout data (pure).
	 * A match needs the postcode plus the name or the street, so a common name alone never
	 * marks an order.
	 *
	 * @param array    $customer Customer: name, address (street line), postcode.
	 * @param string[] $names    Partner names (legal name, account holder, partner name).
	 * @param string   $address  Partner address (free text).
	 * @return bool
	 */
	public static function matches_profile( array $customer, array $names, $address ) {
		$postcode = self::comparable( isset( $customer['postcode'] ) ? (string) $customer['postcode'] : '', true );
		$words    = ' ' . self::comparable( (string) $address, true ) . ' ';
		if ( '' === $postcode || false === strpos( $words, ' ' . $postcode . ' ' ) ) {
			return false;
		}
		$name = self::comparable( isset( $customer['name'] ) ? (string) $customer['name'] : '' );
		if ( strlen( $name ) >= 4 ) {
			foreach ( $names as $n ) {
				if ( self::comparable( (string) $n ) === $name ) {
					return true;
				}
			}
		}
		$street = self::comparable( isset( $customer['address'] ) ? (string) $customer['address'] : '' );
		return strlen( $street ) >= 4 && false !== strpos( str_replace( ' ', '', $words ), $street );
	}

	/**
	 * Lower-case ASCII form for comparisons: umlauts transliterated, "straße"/"str." unified,
	 * everything but letters and digits removed (spaces kept on request).
	 *
	 * @param string $text        Text.
	 * @param bool   $keep_spaces Keep word boundaries.
	 * @return string
	 */
	private static function comparable( $text, $keep_spaces = false ) {
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $text, 'UTF-8' ) : strtolower( (string) $text );
		$text = strtr(
			$text,
			array(
				'ä' => 'ae',
				'ö' => 'oe',
				'ü' => 'ue',
				'ß' => 'ss',
				'é' => 'e',
				'è' => 'e',
				'á' => 'a',
				'à' => 'a',
				'ó' => 'o',
				'ò' => 'o',
				'ç' => 'c',
				'ñ' => 'n',
			)
		);
		$text = preg_replace( '/stra(ss)?e\b|str\./', 'str', $text );
		return trim( (string) preg_replace( $keep_spaces ? '/[^a-z0-9]+/' : '/[^a-z0-9]/', $keep_spaces ? ' ' : '', $text ) );
	}
}
