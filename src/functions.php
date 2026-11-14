<?php
/**
 * Public, versioned helper functions (part of the integration contract v1).
 *
 * Integrations (Pass Captain, Voucher Captain, connector add-ons) should use
 * these functions or the documented hooks – never the plugin tables.
 *
 * @package WPAffiliateCaptain
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'wpac_contract_version' ) ) {
	/**
	 * Version of the public contract.
	 *
	 * @return string
	 */
	function wpac_contract_version() {
		return WPAC_CONTRACT_VERSION;
	}
}

if ( ! function_exists( 'wpac_return_url' ) ) {
	/**
	 * URL to return to after a frontend form post. wp_get_referer() returns false
	 * when the form posts to the same URL, so the raw referer is used as fallback;
	 * only local URLs are accepted.
	 *
	 * @return string
	 */
	function wpac_return_url() {
		$ref = wp_get_referer();
		if ( ! $ref ) {
			$ref = wp_get_raw_referer();
		}
		return wp_validate_redirect( $ref ? $ref : '', home_url( '/' ) );
	}
}

if ( ! function_exists( 'wpac_record_sale' ) ) {
	/**
	 * Report a paid sale. Idempotent per source + order ID.
	 *
	 * @param array $payload Sale payload, see docs/CONTRACT.md.
	 * @return array|WP_Error Result summary.
	 */
	function wpac_record_sale( array $payload ) {
		return \WPAffiliateCaptain\Sales\ContractApi::record_sale( $payload );
	}
}

if ( ! function_exists( 'wpac_record_refund' ) ) {
	/**
	 * Report a (partial) refund, cancellation or chargeback. Idempotent per refund ID.
	 *
	 * @param array $payload Refund payload, see docs/CONTRACT.md.
	 * @return array|WP_Error Result summary.
	 */
	function wpac_record_refund( array $payload ) {
		return \WPAffiliateCaptain\Sales\ContractApi::record_refund( $payload );
	}
}

if ( ! function_exists( 'wpac_record_reinstatement' ) ) {
	/**
	 * Withdraw a cancellation or chargeback (chargeback won, cancellation reversed): the reversed
	 * commission is reinstated. Partial refunds stay deducted. Idempotent.
	 *
	 * @since 0.11.0
	 * @param array $payload { contract, source, order_id, refund_id (optional), reason }, see docs/CONTRACT.md.
	 * @return array|WP_Error { status: reinstated|unchanged, count }
	 */
	function wpac_record_reinstatement( array $payload ) {
		return \WPAffiliateCaptain\Sales\ContractApi::record_reinstatement( $payload );
	}
}

if ( ! function_exists( 'wpac_current_touch' ) ) {
	/**
	 * Referral of the current visitor (contract 1.1.0): the partner link of this shop session
	 * (method "link") or the consented referral cookie (method "cookie"). Integrations with their
	 * own checkout (e.g. Pass Captain) store it with their order and pass it back as `touch`
	 * when the sale is reported. Contains no personal data.
	 *
	 * @since 0.6.0
	 * @return array|null { code, method, campaign, content_id, ts, sub_id, visit_id } or null (ts = click time,
	 *                    since 0.8.0; sub_id = the partner's sub-ID, visit_id = the recorded visit, since 0.9.0).
	 */
	function wpac_current_touch() {
		$touch = \WPAffiliateCaptain\Tracking\Tracker::current_touch();
		if ( ! is_array( $touch ) || empty( $touch['code'] ) ) {
			return null;
		}
		return array(
			'code'       => (string) $touch['code'],
			'method'     => isset( $touch['method'] ) ? (string) $touch['method'] : 'link',
			'campaign'   => isset( $touch['campaign'] ) ? (string) $touch['campaign'] : '',
			'content_id' => isset( $touch['content_id'] ) ? (int) $touch['content_id'] : 0,
			'ts'         => isset( $touch['ts'] ) ? (int) $touch['ts'] : 0,
			'sub_id'     => isset( $touch['sub_id'] ) ? (string) $touch['sub_id'] : '',
			'visit_id'   => isset( $touch['visit_id'] ) ? (int) $touch['visit_id'] : 0,
		);
	}
}

if ( ! function_exists( 'wpac_resolve_partner_code' ) ) {
	/**
	 * Resolve a partner code to an active affiliate.
	 *
	 * @param string $code    Partner code.
	 * @param array  $context Optional context (source, coupon, ...).
	 * @return array|null { affiliate_id, code_id, code } or null.
	 */
	function wpac_resolve_partner_code( $code, array $context = array() ) {
		return apply_filters( 'wpac_resolve_partner_code', null, (string) $code, $context );
	}
}

if ( ! function_exists( 'wpac_get_payout' ) ) {
	/**
	 * One payout (contract 1.3.0): number, partner, status, currency, amount, method and – for the
	 * manual method with an e-mail address in the payment details – the PayPal address. Bank
	 * details are never returned.
	 *
	 * @since 0.12.0
	 * @param int $payout_id Payout ID.
	 * @return array|null { id, number, affiliate_id, status, currency, amount_minor, method, paypal_email, claim, created_at, exported_at, paid_at }
	 */
	function wpac_get_payout( $payout_id ) {
		$p = \WPAffiliateCaptain\Payouts\PayoutService::find( (int) $payout_id );
		return $p ? \WPAffiliateCaptain\Payouts\PayoutService::contract_view( $p ) : null;
	}
}

if ( ! function_exists( 'wpac_get_payouts' ) ) {
	/**
	 * Payouts, oldest first (contract 1.3.0).
	 *
	 * @since 0.12.0
	 * @param array $args status (pending|exported|paid|cancelled), method (sepa|manual), affiliate_id, limit (1–200, default 50).
	 * @return array[] As wpac_get_payout().
	 */
	function wpac_get_payouts( array $args = array() ) {
		return \WPAffiliateCaptain\Payouts\PayoutService::contract_list( $args );
	}
}

if ( ! function_exists( 'wpac_claim_payout' ) ) {
	/**
	 * Claim a created payout before paying it through a payment provider (contract 1.3.0). The
	 * payout becomes "exported" under the returned claim, so no export file and no second claim
	 * can pay it again. Refused when the payout is not "created" any more or the partner changed
	 * the payout details after it was created.
	 *
	 * @since 0.12.0
	 * @param int    $payout_id Payout ID.
	 * @param string $provider  Provider key, e.g. "paypal".
	 * @return array|WP_Error The payout as wpac_get_payout(), with `claim`.
	 */
	function wpac_claim_payout( $payout_id, $provider ) {
		return \WPAffiliateCaptain\Payouts\PayoutService::claim( (int) $payout_id, (string) $provider );
	}
}

if ( ! function_exists( 'wpac_complete_payout' ) ) {
	/**
	 * Mark a claimed payout as paid after the provider executed it (contract 1.3.0): commissions
	 * become paid and wpac_payout_paid fires, as with "mark as paid" in the admin.
	 *
	 * @since 0.12.0
	 * @param int    $payout_id Payout ID.
	 * @param string $claim     Claim from wpac_claim_payout().
	 * @param string $reference Transaction or batch ID of the provider (audit log).
	 * @return true|WP_Error
	 */
	function wpac_complete_payout( $payout_id, $claim, $reference = '' ) {
		return \WPAffiliateCaptain\Payouts\PayoutService::complete_claim( (int) $payout_id, (string) $claim, (string) $reference );
	}
}

if ( ! function_exists( 'wpac_release_payout' ) ) {
	/**
	 * Give a claimed payout back when the provider did not pay it (contract 1.3.0); it is
	 * "created" again and can be exported or claimed anew.
	 *
	 * @since 0.12.0
	 * @param int    $payout_id Payout ID.
	 * @param string $claim     Claim from wpac_claim_payout().
	 * @param string $reason    Reason (audit log).
	 * @return true|WP_Error
	 */
	function wpac_release_payout( $payout_id, $claim, $reason = '' ) {
		return \WPAffiliateCaptain\Payouts\PayoutService::release_claim( (int) $payout_id, (string) $claim, (string) $reason );
	}
}
