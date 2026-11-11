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

if ( ! function_exists( 'wpac_current_touch' ) ) {
	/**
	 * Referral of the current visitor (contract 1.1.0): the partner link of this shop session
	 * (method "link") or the consented referral cookie (method "cookie"). Integrations with their
	 * own checkout (e.g. Pass Captain) store it with their order and pass it back as `touch`
	 * when the sale is reported. Contains no personal data.
	 *
	 * @since 0.6.0
	 * @return array|null { code, method, campaign, content_id } or null.
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
