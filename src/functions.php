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
