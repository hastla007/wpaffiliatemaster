<?php
/**
 * Register of all settings (SET-AC-01): the one source for key, type, default, tab, limits, label,
 * help text and flags. Settings::defaults(), Settings::sanitize() (generic parts), the settings
 * page (tabs, labels, help texts), the audit log and later export/import, reset and WP-CLI read it.
 *
 * Flags: legal = value with legal weight (check with your adviser; country profiles mark it as
 * "unchecked"), secret = never exported or shown, private = personal/company data, only exported on
 * request, hidden = stored but not offered on the settings page.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Core;

defined( 'ABSPATH' ) || exit;

final class SettingsSchema {

	/** @var array|null Core fields (translated once per request after "init"). */
	private static $core = null;

	/** @var array|null Add-on fields from the filter wpac_settings_schema. */
	private static $addon = null;

	/**
	 * Core fields, key => definition. Order = order of Settings::defaults().
	 *
	 * @return array
	 */
	public static function core() {
		if ( null !== self::$core ) {
			return self::$core;
		}
		$translated = function_exists( 'did_action' ) && did_action( 'init' );
		// Translated once translations can load (before "init" WordPress warns about early loading).
		$disclosure = $translated ? __( 'Advertisement', 'affiliate-captain' ) : 'Advertisement';
		$fields     = array(
			'referral_param' => array( 'type' => 'text', 'tab' => 'tracking', 'default' => 'partner', 'label' => __( 'Link parameter', 'affiliate-captain' ), 'help' => __( 'Name of the URL parameter in partner links (e.g. ?partner=CODE). Changing it breaks links that partners already shared.', 'affiliate-captain' ) ),
			'touch_model' => array( 'type' => 'choice', 'tab' => 'tracking', 'default' => 'last', 'options' => array( 'last', 'first' ), 'label' => __( 'Link attribution', 'affiliate-captain' ), 'help' => __( 'Which partner gets the sale when a customer used several partner links: the last one before the order or the first one.', 'affiliate-captain' ) ),
			'cookie_mode' => array( 'type' => 'choice', 'tab' => 'tracking', 'default' => 'consent', 'options' => array( 'off', 'consent', 'always' ), 'label' => __( 'Affiliate cookie', 'affiliate-captain' ), 'help' => __( 'Whether the referral is also kept in a browser cookie. In the EU a cookie needs marketing consent; without a cookie the referral lasts only for the visit and the shop session.', 'affiliate-captain' ), 'legal' => true ),
			'cookie_days' => array( 'type' => 'int', 'tab' => 'tracking', 'default' => 30, 'max' => 3650, 'label' => __( 'Cookie lifetime (days)', 'affiliate-captain' ), 'help' => __( 'Named in the privacy policy text and in the cookie details for your consent banner. Data protection authorities consider up to 13 months (395 days) proportionate for consent-based cookies.', 'affiliate-captain' ), 'legal' => true ),
			'use_wc_session' => array( 'type' => 'bool', 'tab' => 'tracking', 'default' => 1, 'label' => __( 'Keep the code from a partner link in the WooCommerce shop session', 'affiliate-captain' ), 'help' => __( 'Only when a shop session exists (a cart) or a product is put into the cart – a click alone never creates a session or sets a cookie.', 'affiliate-captain' ) ),
			'checkout_field' => array( 'type' => 'bool', 'tab' => 'tracking', 'default' => 1, 'label' => __( 'Show partner code field at checkout (classic + blocks)', 'affiliate-captain' ), 'help' => __( 'Customers can enter a partner code at checkout; the code overrides an earlier partner link.', 'affiliate-captain' ) ),
			'renewal_commission' => array( 'type' => 'bool', 'tab' => 'commissions', 'default' => 1, 'label' => __( 'Commission on subscription renewals (WooCommerce Subscriptions)', 'affiliate-captain' ), 'help' => __( 'Off: only the first order of a subscription earns commission; renewals are not attributed. Renewals never earn commission when the commission of the first order was rejected, refunded or cancelled.', 'affiliate-captain' ) ),
			'js_tracking' => array( 'type' => 'bool', 'tab' => 'tracking', 'default' => 0, 'label' => __( 'Also record partner links via JavaScript (for full-page caches/CDNs)', 'affiliate-captain' ), 'help' => __( 'Only needed if your cache or CDN serves pages with ?partner=… from the cache (e.g. Cloudflare "Cache Everything"). Pages with partner links then stay cacheable: a tiny script reports the parameter for guests; a visit is never counted twice.', 'affiliate-captain' ) ),
			'cookie_domain' => array( 'type' => 'text', 'tab' => 'tracking', 'default' => '', 'label' => __( 'Cookie domain', 'affiliate-captain' ), 'help' => __( 'Leave empty normally. Only if landing pages and shop run on different subdomains (e.g. www.example.com and shop.example.com): enter the common domain .example.com, so the referral cookie and the cart fallback work across both.', 'affiliate-captain' ) ),
			'visit_log_percent' => array( 'type' => 'text', 'tab' => 'tracking', 'default' => 100, 'label' => __( 'Record link visits (%)', 'affiliate-captain' ), 'help' => __( '100 = every visit, e.g. 10 = a sample of every tenth visit (high-traffic sites), 0 = no visit log. Attribution of orders is not affected.', 'affiliate-captain' ) ),
			'coupon_priority' => array( 'type' => 'bool', 'tab' => 'tracking', 'default' => 1, 'label' => __( 'Affiliate coupon has priority over partner links', 'affiliate-captain' ), 'help' => __( 'When a customer uses the coupon linked to a partner, that partner gets the sale even if another partner\'s link was used before.', 'affiliate-captain' ) ),
			'self_referral' => array( 'type' => 'choice', 'tab' => 'tracking', 'default' => 'block', 'options' => array( 'block', 'review' ), 'label' => __( 'Self-referrals', 'affiliate-captain' ), 'help' => __( 'Orders a partner places with their own link, code or e-mail address: block the commission or create it with the status "needs review".', 'affiliate-captain' ) ),
			'rate_limit_per_minute' => array( 'type' => 'int', 'tab' => 'security', 'default' => 30, 'max' => 1000000, 'label' => __( 'Max. counted link visits per client and minute', 'affiliate-captain' ), 'help' => __( 'Protection against bots: visits above this number per client and minute are not counted (attribution of orders is not affected).', 'affiliate-captain' ) ),
			'trusted_proxies' => array( 'type' => 'textarea', 'tab' => 'security', 'default' => '', 'label' => __( 'Trusted proxies / CDN', 'affiliate-captain' ), 'help' => __( 'IP addresses or ranges (CIDR) of your reverse proxy or CDN (e.g. Cloudflare). Only for these, the visitor IP is read from X-Forwarded-For (CF-Connecting-IP only with the filter wpac_client_ip_headers). Leave empty without a proxy.', 'affiliate-captain' ) ),
			'visit_retention_days' => array( 'type' => 'int', 'tab' => 'data', 'default' => 90, 'max' => 36500, 'label' => __( 'Keep visit data (days)', 'affiliate-captain' ), 'help' => __( 'Individual link visits are deleted after this many days; the daily totals for reports are kept.', 'affiliate-captain' ), 'legal' => true ),
			'audit_retention_days' => array( 'type' => 'int', 'tab' => 'data', 'default' => 1095, 'max' => 36500, 'label' => __( 'Keep audit log (days, 0 = forever)', 'affiliate-captain' ), 'help' => __( 'Terms acceptance records and booking-relevant entries are kept as long as the credit notes.', 'affiliate-captain' ), 'legal' => true ),
			'application_retention_days' => array( 'type' => 'int', 'tab' => 'data', 'default' => 180, 'max' => 36500, 'label' => __( 'Keep rejected applications (days, 0 = forever)', 'affiliate-captain' ), 'help' => __( 'Afterwards name, e-mail and application data of rejected applicants without commissions are anonymised.', 'affiliate-captain' ), 'legal' => true ),
			'pending_application_days' => array( 'type' => 'int', 'tab' => 'data', 'default' => 365, 'max' => 36500, 'label' => __( 'Keep undecided applications (days, 0 = forever)', 'affiliate-captain' ), 'help' => __( 'Applications that were confirmed but never approved or rejected are anonymised after this period (personal data removed, status rejected). Unconfirmed applications (e-mail not confirmed) are deleted when their confirmation link expires (setting on the Program tab).', 'affiliate-captain' ), 'legal' => true ),
			'currency' => array( 'type' => 'text', 'tab' => 'commissions', 'default' => 'EUR', 'label' => __( 'Program currency', 'affiliate-captain' ), 'help' => __( 'Currency of commission rates, the minimum payout and the reports. Changing it converts amounts in the settings only.', 'affiliate-captain' ) ),
			'exchange_rates' => array( 'type' => 'textarea', 'tab' => 'commissions', 'default' => '', 'label' => __( 'Reference exchange rates', 'affiliate-captain' ), 'help' => __( 'One per line, e.g. USD=0.92: 1 unit of the currency = x program currency. Only used for reports and minimum payouts of other currencies; commissions are never converted.', 'affiliate-captain' ) ),
			'default_rate_type' => array( 'type' => 'choice', 'tab' => 'commissions', 'default' => 'percent', 'options' => array( 'percent', 'fixed' ), 'label' => __( 'Default commission type', 'affiliate-captain' ), 'help' => __( 'Commission for partners without a matching rule: a percentage of the net order value or a fixed amount per unit.', 'affiliate-captain' ) ),
			'default_rate' => array( 'type' => 'text', 'tab' => 'commissions', 'default' => '10', 'label' => __( 'Default rate', 'affiliate-captain' ), 'help' => __( 'Percent (e.g. 10 or 12,5 %), or amount in program currency for fixed commissions (e.g. 25 €). Use rules for partners, groups, products and course codes.', 'affiliate-captain' ) ),
			'hold_days' => array( 'type' => 'int', 'tab' => 'commissions', 'default' => 30, 'max' => 3650, 'label' => __( 'Hold period (days after payment)', 'affiliate-captain' ), 'help' => __( 'Commissions are released after this period. Integrations can extend it per order (e.g. guarantee end) via hold_until / wpac_hold_until. WooCommerce orders that are on hold are not released until they are processing or completed again.', 'affiliate-captain' ) ),
			'lifetime_enabled' => array( 'type' => 'bool', 'tab' => 'commissions', 'default' => 0, 'label' => __( 'Lifetime commissions: repeat purchases of referred customers go to the same partner', 'affiliate-captain' ), 'help' => __( 'Only applies when no new partner link, code or coupon is used. Customers are stored as keyed hashes only.', 'affiliate-captain' ) ),
			'lifetime_days' => array( 'type' => 'int', 'tab' => 'commissions', 'default' => 365, 'max' => 36500, 'label' => __( 'Lifetime link duration (days, 0 = unlimited)', 'affiliate-captain' ), 'help' => __( 'How long a referred customer stays linked to the partner for lifetime commissions.', 'affiliate-captain' ) ),
			'lifetime_rate' => array( 'type' => 'text', 'tab' => 'commissions', 'default' => '', 'label' => __( 'Lifetime rate in % (empty = normal rules)', 'affiliate-captain' ), 'help' => __( 'Own rate for repeat purchases of linked customers; empty = the normal rules apply.', 'affiliate-captain' ) ),
			'approval_mode' => array( 'type' => 'choice', 'tab' => 'commissions', 'default' => 'auto', 'options' => array( 'auto', 'manual' ), 'label' => __( 'Release of commissions', 'affiliate-captain' ), 'help' => __( 'Automatic: commissions are approved when the hold period ended. Manual: nothing is approved automatically; approve them on the Commissions page (single or several at once).', 'affiliate-captain' ) ),
			'hold_start' => array( 'type' => 'choice', 'tab' => 'commissions', 'default' => 'paid', 'options' => array( 'paid', 'completed' ), 'label' => __( 'Hold period starts', 'affiliate-captain' ), 'help' => __( 'When the hold period starts: at payment, or when the WooCommerce order is completed (shipped).', 'affiliate-captain' ) ),
			'renewal_limit_months' => array( 'type' => 'int', 'tab' => 'commissions', 'default' => 0, 'max' => 2147483647, 'label' => __( 'Renewal commission for (months, 0 = unlimited)', 'affiliate-captain' ), 'help' => __( 'Renewals earn commission only within this many months after the first order of the subscription.', 'affiliate-captain' ) ),
			'rule_precedence' => array( 'type' => 'choice', 'tab' => 'commissions', 'default' => 'scope', 'options' => array( 'scope', 'target' ), 'label' => __( 'Rule precedence', 'affiliate-captain' ), 'help' => __( 'Which rule wins when several rules match a sale: partner rules first, or the most specific product first.', 'affiliate-captain' ) ),
			'auto_approve' => array( 'type' => 'bool', 'tab' => 'program', 'default' => 0, 'label' => __( 'Approve applications automatically', 'affiliate-captain' ), 'help' => __( 'New applications become active partners right after the e-mail confirmation, without review.', 'affiliate-captain' ) ),
			'terms_page_id' => array( 'type' => 'page', 'tab' => 'program', 'default' => 0, 'label' => __( 'Partner program terms', 'affiliate-captain' ), 'help' => __( 'Version, text and SHA-256 hash of this page are stored with every acceptance as evidence.', 'affiliate-captain' ) ),
			'terms_version' => array( 'type' => 'text', 'tab' => 'program', 'default' => '1.0', 'label' => __( 'Terms version', 'affiliate-captain' ), 'help' => __( 'Increase the version only for material changes: partners are then asked to accept again on their next login (credit note payouts wait until they did). Corrections of the page without a new version do not require a new acceptance. Partners can be reminded by e-mail from the partner list.', 'affiliate-captain' ), 'legal' => true ),
			'portal_page_id' => array( 'type' => 'page', 'tab' => 'program', 'default' => 0, 'label' => __( 'Partner area page', 'affiliate-captain' ), 'help' => __( 'Page with the shortcode [wpac_affiliate_portal]: partners see links, commissions and payouts there.', 'affiliate-captain' ) ),
			'registration_page_id' => array( 'type' => 'page', 'tab' => 'program', 'default' => 0, 'label' => __( 'Application page', 'affiliate-captain' ), 'help' => __( 'Page with the shortcode [wpac_affiliate_registration] where new partners apply.', 'affiliate-captain' ) ),
			'terminated_open' => array( 'type' => 'choice', 'tab' => 'program', 'default' => 'pay', 'options' => array( 'pay', 'reject' ), 'label' => __( 'Open commissions on termination', 'affiliate-captain' ), 'help' => __( 'What happens to commissions that are not yet paid when a partner is terminated.', 'affiliate-captain' ) ),
			'code_requests' => array( 'type' => 'bool', 'tab' => 'program', 'default' => 1, 'label' => __( 'Partners can request an own partner code (e.g. MARIA10) in the partner area', 'affiliate-captain' ), 'help' => __( 'Requests are checked like codes you create (characters, length, reserved words, not used as a code, coupon or partner address) and only work after you approve them on the Partners page.', 'affiliate-captain' ) ),
			'admin_email' => array( 'type' => 'email', 'tab' => 'emails', 'default' => '', 'label' => __( 'Notification e-mail', 'affiliate-captain' ), 'help' => __( 'Defaults to the site admin e-mail.', 'affiliate-captain' ), 'private' => true ),
			'mail_from_name' => array( 'type' => 'text', 'tab' => 'emails', 'default' => '', 'label' => __( 'Sender name', 'affiliate-captain' ), 'help' => __( 'Empty: WordPress default (or the setting of your SMTP plugin).', 'affiliate-captain' ) ),
			'mail_from_email' => array( 'type' => 'email', 'tab' => 'emails', 'default' => '', 'label' => __( 'Sender address', 'affiliate-captain' ), 'help' => __( 'Empty: WordPress default. Use an address of your own domain, otherwise mails may land in spam. Replies from partners always go to the notification e-mail (Reply-To).', 'affiliate-captain' ), 'private' => true ),
			'notify_payout_paid' => array( 'type' => 'bool', 'tab' => 'emails', 'default' => 1, 'label' => __( 'E-mail to the partner when a payout was paid', 'affiliate-captain' ), 'help' => __( 'Partners get an e-mail with the amount when you mark their payout as paid.', 'affiliate-captain' ) ),
			'notify_commissions' => array( 'type' => 'bool', 'tab' => 'emails', 'default' => 0, 'label' => __( 'E-mail to partners about new, approved and rejected commissions', 'affiliate-captain' ), 'help' => __( 'At most one e-mail per partner and run (e.g. all approvals of the hourly run together). Partners can unsubscribe with a link in the e-mail, like from the monthly summary.', 'affiliate-captain' ) ),
			'notify_admin_review' => array( 'type' => 'bool', 'tab' => 'emails', 'default' => 0, 'label' => __( 'E-mail to the operator when commissions need review', 'affiliate-captain' ), 'help' => __( 'Sent to the notification e-mail at most once per hour with the number of commissions waiting (e.g. self-referrals or orders flagged by the fraud check). Flagged commissions are never approved automatically.', 'affiliate-captain' ) ),
			'maintenance_mode' => array( 'type' => 'bool', 'tab' => 'data', 'default' => 0, 'label' => __( 'Maintenance mode', 'affiliate-captain' ), 'help' => __( 'For migrations or the accounting close: sales are still recorded, but approvals, payouts, report mails and changes in the partner area wait until you switch it off.', 'affiliate-captain' ) ),
			'monitor_token' => array( 'type' => 'secret', 'tab' => 'data', 'default' => '', 'label' => __( 'Monitoring token', 'affiliate-captain' ), 'help' => __( 'Secret for the health check of an external monitor (REST endpoint wpac/v1/health). Only a hash is stored.', 'affiliate-captain' ), 'secret' => true ),
			'landing_prefix' => array( 'type' => 'text', 'tab' => 'promotion', 'default' => 'partner', 'label' => __( 'Landing page path', 'affiliate-captain' ), 'help' => __( 'Path of partner landing pages, e.g. /partner/partner-name/.', 'affiliate-captain' ) ),
			'landing_page_id' => array( 'type' => 'page', 'tab' => 'promotion', 'default' => 0, 'label' => __( 'Default landing page', 'affiliate-captain' ), 'help' => __( 'Add [wpac_partner_welcome] to show "Recommended by …".', 'affiliate-captain' ) ),
			'default_disclosure' => array( 'type' => 'text', 'tab' => 'promotion', 'default' => $disclosure, 'label' => __( 'Default disclosure note', 'affiliate-captain' ), 'help' => __( 'Label shown with creatives so readers recognise advertising (e.g. "Advertisement"). Check the wording required in your country.', 'affiliate-captain' ), 'legal' => true ),
			'promotion_guidelines' => array( 'type' => 'textarea', 'tab' => 'promotion', 'default' => '', 'label' => __( 'Promotion guidelines for partners', 'affiliate-captain' ), 'help' => __( 'Allowed claims, labelling duty, prohibited channels, how to report problems. Shown in the partner area.', 'affiliate-captain' ) ),
			'embed_disclosure_label' => array( 'type' => 'bool', 'tab' => 'promotion', 'default' => 1, 'label' => __( 'Show the disclosure note visibly above the banner embed code', 'affiliate-captain' ), 'help' => __( 'The HTML code partners copy for a banner then starts with the disclosure note of the creative (e.g. "Advertisement"), so the label stays with the banner on the partner\'s website.', 'affiliate-captain' ) ),
			'auto_apply_coupon' => array( 'type' => 'bool', 'tab' => 'promotion', 'default' => 0, 'label' => __( 'Apply the partner\'s coupon automatically after a partner link', 'affiliate-captain' ), 'help' => __( 'WooCommerce: when a visitor came through a partner link or short link and the partner has a linked coupon, the coupon is put into the cart once (when the first product is added, also later in the same shop session). WooCommerce checks the coupon as usual (expiry, usage limits, minimum spend); a coupon the customer entered is never removed, and an "individual use" coupon is not combined. Not for the partner\'s own purchases, not for paused or blocked partners, and only with a referral that was recorded under your tracking and consent settings. Partners see the discount next to their link. The order is attributed as before.', 'affiliate-captain' ) ),
			'min_payout_minor' => array( 'type' => 'money', 'tab' => 'payouts', 'default' => 5000, 'label' => __( 'Minimum payout', 'affiliate-captain' ), 'help' => __( 'Partners are only included in a payout run when their payable balance reaches this amount.', 'affiliate-captain' ) ),
			'payout_requests' => array( 'type' => 'bool', 'tab' => 'payouts', 'default' => 0, 'label' => __( 'Partners can request payouts in the partner area', 'affiliate-captain' ), 'help' => __( 'Partners can ask for a payout in the partner area once their balance reaches the minimum; you still start the payout.', 'affiliate-captain' ) ),
			'statement_retention_years' => array( 'type' => 'int', 'tab' => 'data', 'default' => 10, 'max' => 100, 'label' => __( 'Keep statements (years, 0 = forever)', 'affiliate-captain' ), 'help' => __( 'At least the legal minimum of your country (Germany 8, Austria 7, Switzerland 10 years; otherwise 10). Counted from the end of the calendar year (cancellations from their own date). After that, the PDF and the partner data snapshot are deleted; amounts remain.', 'affiliate-captain' ), 'legal' => true ),
			'retention_hold' => array( 'type' => 'bool', 'tab' => 'data', 'default' => 0, 'label' => __( 'Tax audit or legal hold: do not delete any statements', 'affiliate-captain' ), 'help' => __( 'The retention period is suspended while a tax audit is running (§147 Abs. 3 AO).', 'affiliate-captain' ), 'legal' => true ),
			'payout_period' => array( 'type' => 'choice', 'tab' => '', 'default' => 'monthly', 'options' => array( 'monthly', 'quarterly', 'manual' ), 'label' => __( 'Payout period (no effect)', 'affiliate-captain' ), 'help' => __( 'Former setting without effect; kept only for stored settings.', 'affiliate-captain' ), 'hidden' => true ),
			'payout_method_manual' => array( 'type' => 'bool', 'tab' => 'payouts', 'default' => 1, 'label' => __( 'Partners may choose another payment method (e.g. PayPal, Wise), paid manually', 'affiliate-captain' ), 'help' => __( 'Payment details are stored encrypted like the IBAN. You pay these payouts yourself (transfer list or PayPal payouts file) and then mark them as paid; the payment provider is a further recipient of the partner\'s data (privacy policy). Off: new partners can only enter an IBAN; partners who already use another method keep it until they change it.', 'affiliate-captain' ) ),
			'debtor_name' => array( 'type' => 'text', 'tab' => 'payouts', 'default' => '', 'label' => __( 'Company account holder (SEPA)', 'affiliate-captain' ), 'help' => __( 'Account holder of your company account; appears in the SEPA payment file.', 'affiliate-captain' ), 'private' => true ),
			'debtor_iban' => array( 'type' => 'text', 'tab' => 'payouts', 'default' => '', 'label' => __( 'Company IBAN (SEPA)', 'affiliate-captain' ), 'help' => __( 'IBAN of the account the payouts are paid from (SEPA payment file).', 'affiliate-captain' ), 'private' => true ),
			'debtor_bic' => array( 'type' => 'text', 'tab' => 'payouts', 'default' => '', 'label' => __( 'Company BIC (optional)', 'affiliate-captain' ), 'help' => __( 'BIC of your bank; only needed by some banks.', 'affiliate-captain' ), 'private' => true ),
			'remittance_prefix' => array( 'type' => 'text', 'tab' => 'payouts', 'default' => 'Provision', 'label' => __( 'Remittance text prefix', 'affiliate-captain' ), 'help' => __( 'Start of the remittance text on the partner\'s bank statement, followed by the payout number.', 'affiliate-captain' ) ),
			'self_billing' => array( 'type' => 'bool', 'tab' => 'payouts', 'default' => 1, 'label' => __( 'Issue statements as credit notes (self-billing, Gutschrift §14 Abs. 2 UStG)', 'affiliate-captain' ), 'help' => __( 'Commission rates are net; for domestic VAT-registered partners VAT is added and shown. Foreign business partners: reverse charge. Agree self-billing with your partners in the terms and confirm the treatment with your tax advisor.', 'affiliate-captain' ), 'legal' => true ),
			'vat_rate' => array( 'type' => 'text', 'tab' => 'payouts', 'default' => '19', 'label' => __( 'VAT rate in %', 'affiliate-captain' ), 'help' => __( 'E.g. 19 or 7,7 – "%" may be typed.', 'affiliate-captain' ), 'legal' => true ),
			'operator_country' => array( 'type' => 'text', 'tab' => 'payouts', 'default' => 'DE', 'label' => __( 'Your country', 'affiliate-captain' ), 'help' => __( 'Country of your company: decides domestic VAT, reverse charge and the notes on credit notes.', 'affiliate-captain' ), 'legal' => true ),
			'operator_address' => array( 'type' => 'textarea', 'tab' => 'payouts', 'default' => '', 'label' => __( 'Your address (for credit notes)', 'affiliate-captain' ), 'help' => __( 'Your company name and address as printed on credit notes.', 'affiliate-captain' ), 'private' => true ),
			'operator_vat_id' => array( 'type' => 'text', 'tab' => 'payouts', 'default' => '', 'label' => __( 'Your VAT ID', 'affiliate-captain' ), 'help' => __( 'Your VAT ID, printed on credit notes; required for reverse-charge credit notes.', 'affiliate-captain' ), 'private' => true ),
			'operator_tax_number' => array( 'type' => 'text', 'tab' => 'payouts', 'default' => '', 'label' => __( 'Your tax number (if no VAT ID)', 'affiliate-captain' ), 'help' => __( 'Your tax number; printed on credit notes when you have no VAT ID.', 'affiliate-captain' ), 'private' => true ),
			'credit_prefix' => array( 'type' => 'text', 'tab' => 'payouts', 'default' => 'GS', 'label' => __( 'Prefix credit note numbers', 'affiliate-captain' ), 'help' => __( 'Numbers are gapless per year, e.g. GS-2026-000001.', 'affiliate-captain' ), 'legal' => true ),
			'storno_prefix' => array( 'type' => 'text', 'tab' => 'payouts', 'default' => 'ST', 'label' => __( 'Prefix cancellation numbers', 'affiliate-captain' ), 'help' => __( 'Prefix of cancellation documents, e.g. ST-2026-000001.', 'affiliate-captain' ), 'legal' => true ),
			'correction_prefix' => array( 'type' => 'text', 'tab' => 'payouts', 'default' => 'KG', 'label' => __( 'Prefix correction credit note numbers', 'affiliate-captain' ), 'help' => __( 'Correction credit notes of paid payouts, e.g. KG-2026-000001.', 'affiliate-captain' ), 'legal' => true ),
			'einvoice_mail' => array( 'type' => 'choice', 'tab' => 'payouts', 'default' => 'auto', 'options' => array( 'auto', 'on', 'off' ), 'label' => __( 'Send e-invoice (XML) with the credit note e-mail', 'affiliate-captain' ), 'help' => __( 'Whether the electronic invoice (XML) is attached to the credit note e-mail.', 'affiliate-captain' ), 'legal' => true ),
			'einvoice_buyer_reference' => array( 'type' => 'text', 'tab' => 'payouts', 'default' => '', 'label' => __( 'Buyer reference on e-invoices (e.g. Leitweg-ID)', 'affiliate-captain' ), 'help' => __( 'Optional. Empty: the document number is used.', 'affiliate-captain' ) ),
			'vies_check' => array( 'type' => 'bool', 'tab' => 'payouts', 'default' => 0, 'label' => __( 'VIES check: verify partner VAT IDs with the EU Commission', 'affiliate-captain' ), 'help' => __( 'Partner VAT IDs (and your own VAT ID) are sent to the VIES service of the European Commission (ec.europa.eu) when payout details are saved and again after the period set below; the confirmation number is kept as proof for reverse-charge credit notes. A VAT ID reported as invalid blocks reverse-charge payouts. Off: nothing is sent and earlier results no longer block payouts.', 'affiliate-captain' ), 'legal' => true ),
			'fraud_max_orders_day' => array( 'type' => 'int', 'tab' => 'security', 'default' => 0, 'max' => 1000000, 'label' => __( 'Max. orders per partner in 24 hours', 'affiliate-captain' ), 'help' => __( 'More orders of one partner within 24 hours are flagged for review. 0 = rule off.', 'affiliate-captain' ) ),
			'fraud_max_order_minor' => array( 'type' => 'money', 'tab' => 'security', 'default' => 0, 'label' => __( 'Review orders above (net)', 'affiliate-captain' ), 'help' => __( 'Orders above this net value are flagged for review. 0 = rule off.', 'affiliate-captain' ) ),
			'fraud_min_click_seconds' => array( 'type' => 'int', 'tab' => 'security', 'default' => 0, 'max' => 86400, 'label' => __( 'Min. seconds between click and order', 'affiliate-captain' ), 'help' => __( 'Orders placed sooner after the partner link click are flagged for review. 0 = rule off.', 'affiliate-captain' ) ),
			'fraud_max_refund_rate' => array( 'type' => 'int', 'tab' => 'security', 'default' => 0, 'max' => 2147483647, 'label' => __( 'Max. refund rate of a partner in %', 'affiliate-captain' ), 'help' => __( 'Partners whose orders are refunded more often than this (period and minimum number of orders below) get new commissions flagged for review. 0 = rule off.', 'affiliate-captain' ) ),
			'fraud_check_domains' => array( 'type' => 'bool', 'tab' => 'security', 'default' => 1, 'label' => __( 'Review orders with disposable or blocked e-mail domains', 'affiliate-captain' ), 'help' => __( 'Orders with throwaway e-mail addresses or a blocked domain are flagged for review.', 'affiliate-captain' ) ),
			'fraud_blocked_domains' => array( 'type' => 'textarea', 'tab' => 'security', 'default' => '', 'label' => __( 'Additional blocked domains', 'affiliate-captain' ), 'help' => __( 'One e-mail domain per line, in addition to the built-in list of throwaway domains.', 'affiliate-captain' ) ),
			'reauth_required' => array( 'type' => 'bool', 'tab' => 'security', 'default' => 1, 'label' => __( 'Ask for the password again before payout files, marking payouts as paid, bank details and account links', 'affiliate-captain' ), 'help' => __( 'Approving bank details, linking or unlinking a partner account, changing payout details, downloading SEPA, transfer or PayPal files and marking payouts as paid need a password confirmation that stays valid in the login session for the time set below (with the Two-Factor plugin also its second factor). WP-CLI and the REST API are not affected. Switching this off needs a confirmation too.', 'affiliate-captain' ) ),
			'report_admin' => array( 'type' => 'bool', 'tab' => 'emails', 'default' => 1, 'label' => __( 'Monthly summary e-mail to the operator', 'affiliate-captain' ), 'help' => __( 'On the first days of the month you get a summary of the previous month (clicks, sales, commissions, payouts).', 'affiliate-captain' ) ),
			'report_partners' => array( 'type' => 'bool', 'tab' => 'emails', 'default' => 0, 'label' => __( 'Monthly summary e-mail to active partners', 'affiliate-captain' ), 'help' => __( 'Sent on the first days of the month for the previous month.', 'affiliate-captain' ) ),
			// Business rules (S2, SET-AC-12/13/14): safe default = the former fixed value; the old filters still win.
			'code_requests_per_month' => array( 'type' => 'int', 'tab' => 'program', 'default' => 3, 'max' => 100, 'label' => __( 'Code requests per partner in 30 days', 'affiliate-captain' ), 'help' => __( 'How many own codes a partner can request within 30 days (at least 1). Filter wpac_code_requests_per_month wins.', 'affiliate-captain' ) ),
			'code_request_max_length' => array( 'type' => 'int', 'tab' => 'program', 'default' => 20, 'max' => 64, 'label' => __( 'Maximum length of a requested code', 'affiliate-captain' ), 'help' => __( 'Characters, 3 to 64. Filter wpac_code_request_max_length wins.', 'affiliate-captain' ) ),
			'application_confirm_days' => array( 'type' => 'int', 'tab' => 'program', 'default' => 2, 'max' => 30, 'label' => __( 'Confirmation link of an application valid for (days)', 'affiliate-captain' ), 'help' => __( 'An application whose e-mail address is not confirmed within this time is deleted (at least 1 day). Also for applications handed over by Form Captain.', 'affiliate-captain' ) ),
			'bank_change_wait_days' => array( 'type' => 'int', 'tab' => 'security', 'default' => 30, 'max' => 365, 'label' => __( 'New bank details wait for approval after an account change (days)', 'affiliate-captain' ), 'help' => __( 'After a partner\'s e-mail address or linked user changed, new payout details from the partner area wait this many days for approval by someone with the payout permission. 0 = no waiting. Filter wpac_access_change_days wins.', 'affiliate-captain' ) ),
			'reauth_minutes' => array( 'type' => 'int', 'tab' => 'security', 'default' => 15, 'max' => 1440, 'label' => __( 'Password confirmation valid for (minutes)', 'affiliate-captain' ), 'help' => __( 'How long a password confirmation before money-relevant actions stays valid in the login session. 0 = ask every time. Filter wpac_reauth_ttl wins.', 'affiliate-captain' ) ),
			'fraud_refund_window_days' => array( 'type' => 'int', 'tab' => 'security', 'default' => 90, 'max' => 730, 'label' => __( 'Refund rate: period (days)', 'affiliate-captain' ), 'help' => __( 'Period over which the refund rate of a partner is calculated (at least 1 day).', 'affiliate-captain' ) ),
			'fraud_refund_min_orders' => array( 'type' => 'int', 'tab' => 'security', 'default' => 5, 'max' => 100000, 'label' => __( 'Refund rate: minimum number of orders', 'affiliate-captain' ), 'help' => __( 'The refund rule only applies to partners with at least this many orders in the period (at least 1).', 'affiliate-captain' ) ),
			'privacy_requests_per_day' => array( 'type' => 'int', 'tab' => 'data', 'default' => 5, 'max' => 100, 'label' => __( 'Privacy requests per partner and day', 'affiliate-captain' ), 'help' => __( 'Limit for requests from the partner area (data access, erasure, objection) per partner and day (at least 1).', 'affiliate-captain' ) ),
			'terms_reminder_days' => array( 'type' => 'int', 'tab' => 'program', 'default' => 7, 'max' => 365, 'label' => __( 'Remind partners of new terms at most every (days)', 'affiliate-captain' ), 'help' => __( 'Minimum time between two reminder e-mails about new partner program terms to the same partner (at least 1 day).', 'affiliate-captain' ) ),
			'claim_cancel_days' => array( 'type' => 'int', 'tab' => 'payouts', 'default' => 45, 'max' => 365, 'label' => __( 'Payouts claimed by a payment add-on can be cancelled after (days)', 'affiliate-captain' ), 'help' => __( 'Payment providers can settle late; cancelling earlier could pay commissions twice (at least 1 day). Filter wpac_claim_cancel_after wins.', 'affiliate-captain' ) ),
			'payout_request_note_days' => array( 'type' => 'int', 'tab' => 'payouts', 'default' => 60, 'max' => 3650, 'label' => __( 'Show the reason of a rejected payout request for (days)', 'affiliate-captain' ), 'help' => __( 'How long partners see your reason for rejecting their payout request in the partner area.', 'affiliate-captain' ) ),
			'vies_recheck_days' => array( 'type' => 'int', 'tab' => 'payouts', 'default' => 30, 'max' => 365, 'label' => __( 'Check VAT IDs again after (days)', 'affiliate-captain' ), 'help' => __( 'With the VIES check on, valid VAT IDs are checked again after this many days (at least 1).', 'affiliate-captain' ), 'legal' => true ),
			'addon_hints' => array( 'type' => 'bool', 'tab' => 'data', 'default' => 1, 'label' => __( 'Show notes about add-on features', 'affiliate-captain' ), 'help' => __( 'Grey text lines at the end of a tab name features of Affiliate Captain Pro. They contain no form fields and change nothing; switch them off here.', 'affiliate-captain' ) ),
			'delete_on_uninstall' => array( 'type' => 'bool', 'tab' => 'data', 'default' => 0, 'label' => __( 'Delete all data when the plugin is deleted', 'affiliate-captain' ), 'help' => __( 'Commission and payout records, credit notes and the audit log are kept as long as a credit note is within the retention period; everything else is deleted.', 'affiliate-captain' ) ),
		);
		if ( $translated ) {
			self::$core = $fields; // Before "init" the labels are untranslated: do not keep them.
		}
		return $fields;
	}

	/**
	 * Fields that add-ons declare with the filter wpac_settings_schema (contract 1.8.0). An add-on
	 * entry needs "tab", "label" and "option" (its own option name; add-on values are never stored in
	 * the core option); core keys cannot be changed or removed through the filter.
	 *
	 * @return array key => definition
	 */
	public static function addon_fields() {
		if ( null !== self::$addon ) {
			return self::$addon;
		}
		$core     = self::core();
		$filtered = function_exists( 'apply_filters' ) ? apply_filters( 'wpac_settings_schema', array(), $core ) : array();
		$out      = array();
		foreach ( (array) $filtered as $key => $def ) {
			$key = is_string( $key ) ? sanitize_key( $key ) : '';
			if ( '' === $key || isset( $core[ $key ] ) || ! is_array( $def ) || empty( $def['option'] ) || empty( $def['label'] ) || ! isset( $def['tab'] ) ) {
				continue;
			}
			$out[ $key ] = array(
				'type'    => isset( $def['type'] ) ? sanitize_key( (string) $def['type'] ) : 'text',
				'tab'     => sanitize_key( (string) $def['tab'] ),
				'default' => isset( $def['default'] ) ? $def['default'] : '',
				'label'   => (string) $def['label'],
				'help'    => isset( $def['help'] ) ? (string) $def['help'] : '',
				'option'  => sanitize_key( (string) $def['option'] ),
				'secret'  => ! empty( $def['secret'] ),
				'private' => ! empty( $def['private'] ),
				'legal'   => ! empty( $def['legal'] ),
			);
		}
		self::$addon = $out;
		return $out;
	}

	/**
	 * Default values of the core settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array_map(
			static function ( $f ) {
				return $f['default'];
			},
			self::core()
		);
	}

	/**
	 * Keys of one type ("bool", "int", "page", "choice", …).
	 *
	 * @param string|string[] $types Type or types.
	 * @return string[]
	 */
	public static function keys_of( $types ) {
		$types = (array) $types;
		return array_keys(
			array_filter(
				self::core(),
				static function ( $f ) use ( $types ) {
					return in_array( $f['type'], $types, true );
				}
			)
		);
	}

	/**
	 * Setting keys per tab, in register order (hidden keys are not on a tab).
	 *
	 * @return array tab => keys
	 */
	public static function tabs() {
		$out = array();
		foreach ( self::core() as $key => $f ) {
			if ( empty( $f['hidden'] ) && '' !== $f['tab'] ) {
				$out[ $f['tab'] ][] = $key;
			}
		}
		return $out;
	}

	/**
	 * One field definition (core or add-on), or null.
	 *
	 * @param string $key Key.
	 * @return array|null
	 */
	public static function field( $key ) {
		$core = self::core();
		if ( isset( $core[ $key ] ) ) {
			return $core[ $key ];
		}
		$addon = self::addon_fields();
		return isset( $addon[ $key ] ) ? $addon[ $key ] : null;
	}

	/**
	 * Label of a key ('' if unknown).
	 *
	 * @param string $key Key.
	 * @return string
	 */
	public static function label( $key ) {
		$f = self::field( $key );
		return $f ? (string) $f['label'] : '';
	}

	/**
	 * Help text of a key ('' if unknown).
	 *
	 * @param string $key Key.
	 * @return string
	 */
	public static function help( $key ) {
		$f = self::field( $key );
		return $f ? (string) $f['help'] : '';
	}

	/**
	 * Upper limits of the whole-number settings.
	 *
	 * @return array key => max
	 */
	public static function int_max() {
		$out = array();
		foreach ( self::core() as $key => $f ) {
			if ( isset( $f['max'] ) ) {
				$out[ $key ] = (int) $f['max'];
			}
		}
		return $out;
	}

	/** Forget the cached definitions (tests, language switch). */
	public static function flush() {
		self::$core  = null;
		self::$addon = null;
	}
}
