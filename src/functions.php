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
	 * One payout (contract 1.3.0): number, partner, status, currency, amount, method (sepa, bank –
	 * bank transfer outside SEPA, since 0.22.0 – or manual) and – for the
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
	 * @param array $args status (pending|exported|paid|cancelled), method (sepa|bank|manual; bank since 0.22.0), affiliate_id, after_id (cursor: only payouts with a greater ID), limit (1–200, default 50). Unknown status or method: empty list.
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

/*
 * ---------------------------------------------------------------------------
 * Read and write API for add-ons (contract 1.3.0). Plain arrays with stable fields (see
 * docs/CONTRACT.md, "Lese- und Schreib-API (1.3.0)") or WP_Error. These functions do NOT check
 * the current user (PHP API for trusted code): REST/AJAX wrappers must check the capability
 * wpac_manage_affiliates (partners, commissions) or wpac_manage_payouts (payouts, approving
 * before the end of the hold period) themselves. Writes are audited with the current user.
 * ---------------------------------------------------------------------------
 */

if ( ! function_exists( 'wpac_get_affiliate' ) ) {
	/**
	 * One partner.
	 *
	 * @since contract 1.3.0
	 * @param int $id Partner ID.
	 * @return array|null { id, user_id, group_id, status, status_reason, slug, name, email, website,
	 *                        channel, created_at, updated_at, approved_at, referred_by (recruiting
	 *                        partner, 0 = none; since 0.15.0), program (program key, '' = default
	 *                        program; since contract 1.5.0) }, or null when unknown.
	 */
	function wpac_get_affiliate( $id ) {
		return \WPAffiliateCaptain\Core\PublicApi::get_affiliate( $id );
	}
}

if ( ! function_exists( 'wpac_get_order_attribution' ) ) {
	/**
	 * Partner and campaign an order is attributed to, e.g. for a review plugin that fills the
	 * filter wpac_review_aggregates (docs/CONTRACT.md, "Review-Qualität").
	 *
	 * @since plugin 0.17.0 (provisional until the shared suite specification is accepted)
	 * @param string $source   Source of the order (e.g. "woocommerce").
	 * @param string $order_id Order ID of the source.
	 * @return array|null|WP_Error { source, order_id, affiliate_id, campaign, method, status
	 *                             (attributed|review), created_at }; null without attribution or
	 *                             when the partner was erased; WP_Error wpac_invalid_source /
	 *                             wpac_invalid_order_id.
	 */
	function wpac_get_order_attribution( $source, $order_id ) {
		return \WPAffiliateCaptain\Core\PublicApi::get_order_attribution( $source, $order_id );
	}
}

if ( ! function_exists( 'wpac_get_affiliates' ) ) {
	/**
	 * List partners.
	 *
	 * @since contract 1.3.0
	 * @param array $args status, group, referred_by (since 0.15.0), program (program key, '' = default
	 *                    program; since contract 1.5.0), search, per_page (1–100, default 20), page, orderby
	 *                    (id|name|status|created_at), order (asc|desc).
	 * @return array|WP_Error { items (as wpac_get_affiliate()), total, page, per_page }
	 */
	function wpac_get_affiliates( array $args = array() ) {
		return \WPAffiliateCaptain\Core\PublicApi::get_affiliates( $args );
	}
}

if ( ! function_exists( 'wpac_get_commission' ) ) {
	/**
	 * One ledger row (commission, correction, bonus, manual booking …).
	 *
	 * @since contract 1.3.0
	 * @param int $id Commission ID.
	 * @return array|null See docs/CONTRACT.md, or null when unknown.
	 */
	function wpac_get_commission( $id ) {
		return \WPAffiliateCaptain\Core\PublicApi::get_commission( $id );
	}
}

if ( ! function_exists( 'wpac_get_commissions' ) ) {
	/**
	 * List ledger rows.
	 *
	 * @since contract 1.3.0
	 * @param array $args affiliate_id, status, kind, from, to (Y-m-d), program (program key of the
	 *                    partner, '' = default program; since contract 1.5.0), per_page (1–100), page,
	 *                    orderby, order.
	 * @return array|WP_Error { items (as wpac_get_commission()), total, page, per_page }
	 */
	function wpac_get_commissions( array $args = array() ) {
		return \WPAffiliateCaptain\Core\PublicApi::get_commissions( $args );
	}
}

if ( ! function_exists( 'wpac_update_affiliate' ) ) {
	/**
	 * Change name, website, channel, group or recruiting partner of a partner (validated like the
	 * admin form, audited).
	 *
	 * @since contract 1.3.0
	 * @param int   $id   Partner ID.
	 * @param array $data name, website, channel, group (ID, 0 = none), referred_by (partner ID,
	 *                    0 = none; existing partner, not the partner itself, no cycle; since 0.15.0);
	 *                    other keys → WP_Error.
	 * @return array|WP_Error The partner (as wpac_get_affiliate()).
	 */
	function wpac_update_affiliate( $id, array $data ) {
		return \WPAffiliateCaptain\Core\PublicApi::update_affiliate( $id, $data );
	}
}

if ( ! function_exists( 'wpac_set_affiliate_status' ) ) {
	/**
	 * Change the status of a partner with the admin rules (e-mails, termination steps, audit).
	 *
	 * @since contract 1.3.0
	 * @param int    $id     Partner ID.
	 * @param string $status pending|active|paused|terminated|rejected.
	 * @param string $reason Reason (required for paused, terminated, rejected).
	 * @return array|WP_Error The partner (as wpac_get_affiliate()).
	 */
	function wpac_set_affiliate_status( $id, $status, $reason = '' ) {
		return \WPAffiliateCaptain\Core\PublicApi::set_affiliate_status( $id, $status, $reason );
	}
}

if ( ! function_exists( 'wpac_set_commission_status' ) ) {
	/**
	 * Approve or reject a commission with the ledger rules (audited).
	 *
	 * @since contract 1.3.0
	 * @param int    $id     Commission ID.
	 * @param string $status approved|rejected.
	 * @param string $reason Reason (required for rejected).
	 * @return array|WP_Error The commission (as wpac_get_commission()).
	 */
	function wpac_set_commission_status( $id, $status, $reason = '' ) {
		return \WPAffiliateCaptain\Core\PublicApi::set_commission_status( $id, $status, $reason );
	}
}

if ( ! function_exists( 'wpac_create_adjustment' ) ) {
	/**
	 * Manual booking for a partner, approved at once (audited; fires wpac_commission_created).
	 *
	 * @since contract 1.3.0
	 * @param int    $affiliate_id Partner ID.
	 * @param int    $amount_minor Amount in minor units (not 0; negative = deduction).
	 * @param string $currency     ISO 4217 code.
	 * @param string $reason       Reason (required, shown to the partner).
	 * @param array  $args         Optional (since 0.15.0): parent_id (ID of an existing commission the
	 *                             booking refers to, e.g. the downline sale of a team commission;
	 *                             information only: refunds of that commission leave the booking
	 *                             untouched), reference (text, at most 64 characters). Other keys →
	 *                             WP_Error.
	 * @return array|WP_Error The booking (as wpac_get_commission(), with parent_id and reference).
	 */
	function wpac_create_adjustment( $affiliate_id, $amount_minor, $currency, $reason, $args = array() ) {
		return \WPAffiliateCaptain\Core\PublicApi::create_adjustment( $affiliate_id, $amount_minor, $currency, $reason, $args );
	}
}

if ( ! function_exists( 'wpac_affiliate_program' ) ) {
	/**
	 * Program of a partner (variant A of docs/MULTI-PROGRAM.md: a program is a partner group).
	 * Default "group:<group ID>", '' for partners without a group (default program) and unknown
	 * partners; filter wpac_affiliate_program.
	 *
	 * @since contract 1.5.0
	 * @param int $affiliate_id Partner ID.
	 * @return string Program key.
	 */
	function wpac_affiliate_program( $affiliate_id ) {
		return \WPAffiliateCaptain\Affiliates\Programs::of_affiliate( (int) $affiliate_id );
	}
}

if ( ! function_exists( 'wpac_portal_url' ) ) {
	/**
	 * URL of the partner area (settings: portal page), for links in add-on e-mails or pages. With a
	 * partner: in the partner's language (language of the linked account, otherwise the site
	 * language; translated page with WPML/Polylang), as in the plugin's own e-mails. Without a
	 * partner (0 or unknown ID): in the language of the current request. Home page when no portal
	 * page is published.
	 *
	 * @since 0.15.0
	 * @param int $affiliate_id Partner ID (0 = current language).
	 * @return string
	 */
	function wpac_portal_url( $affiliate_id = 0 ) {
		$aff = (int) $affiliate_id > 0 ? \WPAffiliateCaptain\Affiliates\AffiliateRepository::find( (int) $affiliate_id ) : null;
		return \WPAffiliateCaptain\Affiliates\Notifications::portal_url( $aff ? $aff : null );
	}
}

if ( ! function_exists( 'wpac_mail_sender' ) ) {
	/**
	 * Sender of the plugin's e-mails, for add-ons that send their own: the same rules as the
	 * plugin's mails. Name: setting "Sender name", filtered by wpac_mail_from_name, otherwise the
	 * site name. Address: setting "Sender address", otherwise the program notification address
	 * (settings) or the site admin e-mail.
	 *
	 * @since 0.15.0
	 * @param string $type         Optional mail type of the add-on (passed to wpac_mail_from_name).
	 * @param int    $affiliate_id Optional recipient partner (passed to wpac_mail_from_name).
	 * @return array { name, email, custom } custom = true when the sender address is set in the
	 *               settings (only then do the plugin's mails set a From header).
	 */
	function wpac_mail_sender( $type = '', $affiliate_id = 0 ) {
		return \WPAffiliateCaptain\Affiliates\Notifications::sender( (string) $type, (int) $affiliate_id );
	}
}
