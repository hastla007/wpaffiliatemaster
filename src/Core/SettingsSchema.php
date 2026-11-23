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

		/** @var bool Whether core() may translate (only after "init"). */
	private static $translate = false;

	/**
	 * Translates a register text once translations may load; returns it unchanged before "init".
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain.
	 * @return string
	 */
	private static function l__( $text, $domain ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- name keeps the gettext literals extractable.
		return self::$translate ? __( $text, $domain ) : $text; // phpcs:ignore WordPress.WP.I18n -- literals are passed by core().
	}

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
		self::$translate = $translated;
		$disclosure     = self::l__( 'Advertisement', 'affiliate-captain' );
		$fields     = array(
			'referral_param' => array( 'type' => 'text', 'tab' => 'tracking', 'default' => 'partner', 'label' => self::l__( 'Link parameter', 'affiliate-captain' ), 'help' => self::l__( 'Name of the URL parameter in partner links (e.g. ?partner=CODE). Changing it breaks links that partners already shared.', 'affiliate-captain' ) ),
			'touch_model' => array( 'type' => 'choice', 'tab' => 'tracking', 'default' => 'last', 'options' => array( 'last', 'first' ), 'label' => self::l__( 'Link attribution', 'affiliate-captain' ), 'help' => self::l__( 'Which partner gets the sale when a customer used several partner links: the last one before the order or the first one.', 'affiliate-captain' ) ),
			'cookie_mode' => array( 'type' => 'choice', 'tab' => 'tracking', 'default' => 'consent', 'options' => array( 'off', 'consent', 'always' ), 'label' => self::l__( 'Affiliate cookie', 'affiliate-captain' ), 'help' => self::l__( 'Whether the referral is also kept in a browser cookie. In the EU a cookie needs marketing consent; without a cookie the referral lasts only for the visit and the shop session.', 'affiliate-captain' ), 'legal' => true ),
			'cookie_days' => array( 'type' => 'int', 'tab' => 'tracking', 'default' => 30, 'max' => 3650, 'label' => self::l__( 'Cookie lifetime (days)', 'affiliate-captain' ), 'help' => self::l__( 'Named in the privacy policy text and in the cookie details for your consent banner. Data protection authorities consider up to 13 months (395 days) proportionate for consent-based cookies.', 'affiliate-captain' ), 'legal' => true ),
			'use_wc_session' => array( 'type' => 'bool', 'tab' => 'tracking', 'default' => 1, 'label' => self::l__( 'Keep the code from a partner link in the WooCommerce shop session', 'affiliate-captain' ), 'help' => self::l__( 'Only when a shop session exists (a cart) or a product is put into the cart – a click alone never creates a session or sets a cookie.', 'affiliate-captain' ) ),
			'checkout_field' => array( 'type' => 'bool', 'tab' => 'tracking', 'default' => 1, 'label' => self::l__( 'Show partner code field at checkout (classic + blocks)', 'affiliate-captain' ), 'help' => self::l__( 'Customers can enter a partner code at checkout; the code overrides an earlier partner link.', 'affiliate-captain' ) ),
			'renewal_commission' => array( 'type' => 'bool', 'tab' => 'commissions', 'default' => 1, 'label' => self::l__( 'Commission on subscription renewals (WooCommerce Subscriptions)', 'affiliate-captain' ), 'help' => self::l__( 'Off: only the first order of a subscription earns commission; renewals are not attributed. Renewals never earn commission when the commission of the first order was rejected, refunded or cancelled.', 'affiliate-captain' ) ),
			'js_tracking' => array( 'type' => 'bool', 'tab' => 'tracking', 'default' => 0, 'label' => self::l__( 'Also record partner links via JavaScript (for full-page caches/CDNs)', 'affiliate-captain' ), 'help' => self::l__( 'Only needed if your cache or CDN serves pages with ?partner=… from the cache (e.g. Cloudflare "Cache Everything"). Pages with partner links then stay cacheable: a tiny script reports the parameter for guests; a visit is never counted twice.', 'affiliate-captain' ) ),
			'cookie_domain' => array( 'type' => 'text', 'tab' => 'tracking', 'default' => '', 'label' => self::l__( 'Cookie domain', 'affiliate-captain' ), 'help' => self::l__( 'Leave empty normally. Only if landing pages and shop run on different subdomains (e.g. www.example.com and shop.example.com): enter the common domain .example.com, so the referral cookie and the cart fallback work across both.', 'affiliate-captain' ) ),
			'visit_log_percent' => array( 'type' => 'text', 'tab' => 'tracking', 'default' => 100, 'label' => self::l__( 'Record link visits (%)', 'affiliate-captain' ), 'help' => self::l__( '100 = every visit, e.g. 10 = a sample of every tenth visit (high-traffic sites), 0 = no visit log. Attribution of orders is not affected.', 'affiliate-captain' ) ),
			'coupon_priority' => array( 'type' => 'bool', 'tab' => 'tracking', 'default' => 1, 'label' => self::l__( 'Affiliate coupon has priority over partner links', 'affiliate-captain' ), 'help' => self::l__( 'When a customer uses the coupon linked to a partner, that partner gets the sale even if another partner\'s link was used before.', 'affiliate-captain' ) ),
			'self_referral' => array( 'type' => 'choice', 'tab' => 'tracking', 'default' => 'block', 'options' => array( 'block', 'review' ), 'label' => self::l__( 'Self-referrals', 'affiliate-captain' ), 'help' => self::l__( 'Orders a partner places with their own link, code or e-mail address: block the commission or create it with the status "needs review".', 'affiliate-captain' ) ),
			'rate_limit_per_minute' => array( 'type' => 'int', 'tab' => 'security', 'default' => 30, 'max' => 1000000, 'label' => self::l__( 'Max. counted link visits per client and minute', 'affiliate-captain' ), 'help' => self::l__( 'Protection against bots: visits above this number per client and minute are not counted (attribution of orders is not affected).', 'affiliate-captain' ) ),
			'trusted_proxies' => array( 'type' => 'textarea', 'tab' => 'security', 'default' => '', 'label' => self::l__( 'Trusted proxies / CDN', 'affiliate-captain' ), 'help' => self::l__( 'IP addresses or ranges (CIDR) of your reverse proxy or CDN (e.g. Cloudflare). Only for these, the visitor IP is read from X-Forwarded-For (CF-Connecting-IP only with the filter wpac_client_ip_headers). Leave empty without a proxy.', 'affiliate-captain' ) ),
			'visit_retention_days' => array( 'type' => 'int', 'tab' => 'data', 'default' => 90, 'max' => 36500, 'label' => self::l__( 'Keep visit data (days)', 'affiliate-captain' ), 'help' => self::l__( 'Individual link visits are deleted after this many days; the daily totals for reports are kept.', 'affiliate-captain' ), 'legal' => true ),
			'audit_retention_days' => array( 'type' => 'int', 'tab' => 'data', 'default' => 1095, 'max' => 36500, 'label' => self::l__( 'Keep audit log (days, 0 = forever)', 'affiliate-captain' ), 'help' => self::l__( 'Terms acceptance records and booking-relevant entries are kept as long as the credit notes.', 'affiliate-captain' ), 'legal' => true ),
			'application_retention_days' => array( 'type' => 'int', 'tab' => 'data', 'default' => 180, 'max' => 36500, 'label' => self::l__( 'Keep rejected applications (days, 0 = forever)', 'affiliate-captain' ), 'help' => self::l__( 'Afterwards name, e-mail and application data of rejected applicants without commissions are anonymised.', 'affiliate-captain' ), 'legal' => true ),
			'pending_application_days' => array( 'type' => 'int', 'tab' => 'data', 'default' => 365, 'max' => 36500, 'label' => self::l__( 'Keep undecided applications (days, 0 = forever)', 'affiliate-captain' ), 'help' => self::l__( 'Applications that were confirmed but never approved or rejected are anonymised after this period (personal data removed, status rejected). Unconfirmed applications (e-mail not confirmed) are deleted when their confirmation link expires (setting on the Program tab).', 'affiliate-captain' ), 'legal' => true ),
			'currency' => array( 'type' => 'text', 'tab' => 'commissions', 'default' => 'EUR', 'label' => self::l__( 'Program currency', 'affiliate-captain' ), 'help' => self::l__( 'Currency of commission rates, the minimum payout and the reports. Changing it converts amounts in the settings only.', 'affiliate-captain' ) ),
			'exchange_rates' => array( 'type' => 'textarea', 'tab' => 'commissions', 'default' => '', 'label' => self::l__( 'Reference exchange rates', 'affiliate-captain' ), 'help' => self::l__( 'One per line, e.g. USD=0.92: 1 unit of the currency = x program currency. Only used for reports and minimum payouts of other currencies; commissions are never converted.', 'affiliate-captain' ) ),
			'default_rate_type' => array( 'type' => 'choice', 'tab' => 'commissions', 'default' => 'percent', 'options' => array( 'percent', 'fixed' ), 'label' => self::l__( 'Default commission type', 'affiliate-captain' ), 'help' => self::l__( 'Commission for partners without a matching rule: a percentage of the net order value or a fixed amount per unit.', 'affiliate-captain' ) ),
			'default_rate' => array( 'type' => 'text', 'tab' => 'commissions', 'default' => '10', 'label' => self::l__( 'Default rate', 'affiliate-captain' ), 'help' => self::l__( 'Percent (e.g. 10 or 12,5 %), or amount in program currency for fixed commissions (e.g. 25 €). Use rules for partners, groups, products and course codes.', 'affiliate-captain' ) ),
			'hold_days' => array( 'type' => 'int', 'tab' => 'commissions', 'default' => 30, 'max' => 3650, 'label' => self::l__( 'Hold period (days after payment)', 'affiliate-captain' ), 'help' => self::l__( 'Commissions are released after this period. Integrations can extend it per order (e.g. guarantee end) via hold_until / wpac_hold_until. WooCommerce orders that are on hold are not released until they are processing or completed again.', 'affiliate-captain' ) ),
			'lifetime_enabled' => array( 'type' => 'bool', 'tab' => 'commissions', 'default' => 0, 'label' => self::l__( 'Lifetime commissions: repeat purchases of referred customers go to the same partner', 'affiliate-captain' ), 'help' => self::l__( 'Only applies when no new partner link, code or coupon is used. Customers are stored as keyed hashes only.', 'affiliate-captain' ) ),
			'lifetime_days' => array( 'type' => 'int', 'tab' => 'commissions', 'default' => 365, 'max' => 36500, 'label' => self::l__( 'Lifetime link duration (days, 0 = unlimited)', 'affiliate-captain' ), 'help' => self::l__( 'How long a referred customer stays linked to the partner for lifetime commissions.', 'affiliate-captain' ) ),
			'lifetime_rate' => array( 'type' => 'text', 'tab' => 'commissions', 'default' => '', 'label' => self::l__( 'Lifetime rate in % (empty = normal rules)', 'affiliate-captain' ), 'help' => self::l__( 'Own rate for repeat purchases of linked customers; empty = the normal rules apply.', 'affiliate-captain' ) ),
			'approval_mode' => array( 'type' => 'choice', 'tab' => 'commissions', 'default' => 'auto', 'options' => array( 'auto', 'manual' ), 'label' => self::l__( 'Release of commissions', 'affiliate-captain' ), 'help' => self::l__( 'Automatic: commissions are approved when the hold period ended. Manual: nothing is approved automatically; approve them on the Commissions page (single or several at once).', 'affiliate-captain' ) ),
			'hold_start' => array( 'type' => 'choice', 'tab' => 'commissions', 'default' => 'paid', 'options' => array( 'paid', 'completed' ), 'label' => self::l__( 'Hold period starts', 'affiliate-captain' ), 'help' => self::l__( 'When the hold period starts: at payment, or when the WooCommerce order is completed (shipped).', 'affiliate-captain' ) ),
			'renewal_limit_months' => array( 'type' => 'int', 'tab' => 'commissions', 'default' => 0, 'max' => 2147483647, 'label' => self::l__( 'Renewal commission for (months, 0 = unlimited)', 'affiliate-captain' ), 'help' => self::l__( 'Renewals earn commission only within this many months after the first order of the subscription.', 'affiliate-captain' ) ),
			'rule_precedence' => array( 'type' => 'choice', 'tab' => 'commissions', 'default' => 'scope', 'options' => array( 'scope', 'target' ), 'label' => self::l__( 'Rule precedence', 'affiliate-captain' ), 'help' => self::l__( 'Which rule wins when several rules match a sale: partner rules first, or the most specific product first.', 'affiliate-captain' ) ),
			'auto_approve' => array( 'type' => 'bool', 'tab' => 'program', 'default' => 0, 'label' => self::l__( 'Approve applications automatically', 'affiliate-captain' ), 'help' => self::l__( 'New applications become active partners right after the e-mail confirmation, without review.', 'affiliate-captain' ) ),
			'terms_page_id' => array( 'type' => 'page', 'tab' => 'program', 'default' => 0, 'label' => self::l__( 'Partner program terms', 'affiliate-captain' ), 'help' => self::l__( 'Version, text and SHA-256 hash of this page are stored with every acceptance as evidence.', 'affiliate-captain' ) ),
			'terms_version' => array( 'type' => 'text', 'tab' => 'program', 'default' => '1.0', 'label' => self::l__( 'Terms version', 'affiliate-captain' ), 'help' => self::l__( 'Increase the version only for material changes: partners are then asked to accept again on their next login (credit note payouts wait until they did). Corrections of the page without a new version do not require a new acceptance. Partners can be reminded by e-mail from the partner list.', 'affiliate-captain' ), 'legal' => true ),
			'portal_page_id' => array( 'type' => 'page', 'tab' => 'program', 'default' => 0, 'label' => self::l__( 'Partner area page', 'affiliate-captain' ), 'help' => self::l__( 'Page with the shortcode [wpac_affiliate_portal]: partners see links, commissions and payouts there.', 'affiliate-captain' ) ),
			'registration_page_id' => array( 'type' => 'page', 'tab' => 'program', 'default' => 0, 'label' => self::l__( 'Application page', 'affiliate-captain' ), 'help' => self::l__( 'Page with the shortcode [wpac_affiliate_registration] where new partners apply.', 'affiliate-captain' ) ),
			'terminated_open' => array( 'type' => 'choice', 'tab' => 'program', 'default' => 'pay', 'options' => array( 'pay', 'reject' ), 'label' => self::l__( 'Open commissions on termination', 'affiliate-captain' ), 'help' => self::l__( 'What happens to commissions that are not yet paid when a partner is terminated.', 'affiliate-captain' ) ),
			'code_requests' => array( 'type' => 'bool', 'tab' => 'program', 'default' => 1, 'label' => self::l__( 'Partners can request an own partner code (e.g. MARIA10) in the partner area', 'affiliate-captain' ), 'help' => self::l__( 'Requests are checked like codes you create (characters, length, reserved words, not used as a code, coupon or partner address) and only work after you approve them on the Partners page.', 'affiliate-captain' ) ),
			'admin_email' => array( 'type' => 'email', 'tab' => 'emails', 'default' => '', 'label' => self::l__( 'Notification e-mail', 'affiliate-captain' ), 'help' => self::l__( 'Defaults to the site admin e-mail.', 'affiliate-captain' ), 'private' => true ),
			'mail_from_name' => array( 'type' => 'text', 'tab' => 'emails', 'default' => '', 'label' => self::l__( 'Sender name', 'affiliate-captain' ), 'help' => self::l__( 'Empty: WordPress default (or the setting of your SMTP plugin).', 'affiliate-captain' ) ),
			'mail_from_email' => array( 'type' => 'email', 'tab' => 'emails', 'default' => '', 'label' => self::l__( 'Sender address', 'affiliate-captain' ), 'help' => self::l__( 'Empty: WordPress default. Use an address of your own domain, otherwise mails may land in spam. Replies from partners always go to the notification e-mail (Reply-To).', 'affiliate-captain' ), 'private' => true ),
			'notify_payout_paid' => array( 'type' => 'bool', 'tab' => 'emails', 'default' => 1, 'label' => self::l__( 'E-mail to the partner when a payout was paid', 'affiliate-captain' ), 'help' => self::l__( 'Partners get an e-mail with the amount when you mark their payout as paid.', 'affiliate-captain' ) ),
			'notify_commissions' => array( 'type' => 'bool', 'tab' => 'emails', 'default' => 0, 'label' => self::l__( 'E-mail to partners about new, approved and rejected commissions', 'affiliate-captain' ), 'help' => self::l__( 'At most one e-mail per partner and run (e.g. all approvals of the hourly run together). Partners can unsubscribe with a link in the e-mail, like from the monthly summary.', 'affiliate-captain' ) ),
			'notify_admin_review' => array( 'type' => 'bool', 'tab' => 'emails', 'default' => 0, 'label' => self::l__( 'E-mail to the operator when commissions need review', 'affiliate-captain' ), 'help' => self::l__( 'Sent to the notification e-mail at most once per hour with the number of commissions waiting (e.g. self-referrals or orders flagged by the fraud check). Flagged commissions are never approved automatically.', 'affiliate-captain' ) ),
			'maintenance_mode' => array( 'type' => 'bool', 'tab' => 'data', 'default' => 0, 'label' => self::l__( 'Maintenance mode', 'affiliate-captain' ), 'help' => self::l__( 'For migrations or the accounting close: sales are still recorded, but approvals, payouts, report mails and changes in the partner area wait until you switch it off.', 'affiliate-captain' ) ),
			'monitor_token' => array( 'type' => 'secret', 'tab' => 'data', 'default' => '', 'label' => self::l__( 'Monitoring token', 'affiliate-captain' ), 'help' => self::l__( 'Secret for the health check of an external monitor (REST endpoint wpac/v1/health). Only a hash is stored.', 'affiliate-captain' ), 'secret' => true ),
			'landing_prefix' => array( 'type' => 'text', 'tab' => 'promotion', 'default' => 'partner', 'label' => self::l__( 'Landing page path', 'affiliate-captain' ), 'help' => self::l__( 'Path of partner landing pages, e.g. /partner/partner-name/.', 'affiliate-captain' ) ),
			'landing_page_id' => array( 'type' => 'page', 'tab' => 'promotion', 'default' => 0, 'label' => self::l__( 'Default landing page', 'affiliate-captain' ), 'help' => self::l__( 'Add [wpac_partner_welcome] to show "Recommended by …".', 'affiliate-captain' ) ),
			'default_disclosure' => array( 'type' => 'text', 'tab' => 'promotion', 'default' => $disclosure, 'label' => self::l__( 'Default disclosure note', 'affiliate-captain' ), 'help' => self::l__( 'Label shown with creatives so readers recognise advertising (e.g. "Advertisement"). Check the wording required in your country.', 'affiliate-captain' ), 'legal' => true ),
			'promotion_guidelines' => array( 'type' => 'textarea', 'tab' => 'promotion', 'default' => '', 'label' => self::l__( 'Promotion guidelines for partners', 'affiliate-captain' ), 'help' => self::l__( 'Allowed claims, labelling duty, prohibited channels, how to report problems. Shown in the partner area.', 'affiliate-captain' ) ),
			'embed_disclosure_label' => array( 'type' => 'bool', 'tab' => 'promotion', 'default' => 1, 'label' => self::l__( 'Show the disclosure note visibly above the banner embed code', 'affiliate-captain' ), 'help' => self::l__( 'The HTML code partners copy for a banner then starts with the disclosure note of the creative (e.g. "Advertisement"), so the label stays with the banner on the partner\'s website.', 'affiliate-captain' ) ),
			'auto_apply_coupon' => array( 'type' => 'bool', 'tab' => 'promotion', 'default' => 0, 'label' => self::l__( 'Apply the partner\'s coupon automatically after a partner link', 'affiliate-captain' ), 'help' => self::l__( 'WooCommerce: when a visitor came through a partner link or short link and the partner has a linked coupon, the coupon is put into the cart once (when the first product is added, also later in the same shop session). WooCommerce checks the coupon as usual (expiry, usage limits, minimum spend); a coupon the customer entered is never removed, and an "individual use" coupon is not combined. Not for the partner\'s own purchases, not for paused or blocked partners, and only with a referral that was recorded under your tracking and consent settings. Partners see the discount next to their link. The order is attributed as before.', 'affiliate-captain' ) ),
			'min_payout_minor' => array( 'type' => 'money', 'tab' => 'payouts', 'default' => 5000, 'label' => self::l__( 'Minimum payout', 'affiliate-captain' ), 'help' => self::l__( 'Partners are only included in a payout run when their payable balance reaches this amount.', 'affiliate-captain' ) ),
			'payout_requests' => array( 'type' => 'bool', 'tab' => 'payouts', 'default' => 0, 'label' => self::l__( 'Partners can request payouts in the partner area', 'affiliate-captain' ), 'help' => self::l__( 'Partners can ask for a payout in the partner area once their balance reaches the minimum; you still start the payout.', 'affiliate-captain' ) ),
			'statement_retention_years' => array( 'type' => 'int', 'tab' => 'data', 'default' => 10, 'max' => 100, 'label' => self::l__( 'Keep statements (years, 0 = forever)', 'affiliate-captain' ), 'help' => self::l__( 'At least the legal minimum of your country (Germany 8, Austria 7, Switzerland 10 years; otherwise 10). Counted from the end of the calendar year (cancellations from their own date). After that, the PDF and the partner data snapshot are deleted; amounts remain.', 'affiliate-captain' ), 'legal' => true ),
			'retention_hold' => array( 'type' => 'bool', 'tab' => 'data', 'default' => 0, 'label' => self::l__( 'Tax audit or legal hold: do not delete any statements', 'affiliate-captain' ), 'help' => self::l__( 'The retention period is suspended while a tax audit is running (§147 Abs. 3 AO).', 'affiliate-captain' ), 'legal' => true ),
			'payout_period' => array( 'type' => 'choice', 'tab' => '', 'default' => 'monthly', 'options' => array( 'monthly', 'quarterly', 'manual' ), 'label' => self::l__( 'Payout period (no effect)', 'affiliate-captain' ), 'help' => self::l__( 'Former setting without effect; kept only for stored settings.', 'affiliate-captain' ), 'hidden' => true ),
			'payout_method_manual' => array( 'type' => 'bool', 'tab' => 'payouts', 'default' => 1, 'label' => self::l__( 'Partners may choose another payment method (e.g. PayPal, Wise), paid manually', 'affiliate-captain' ), 'help' => self::l__( 'Payment details are stored encrypted like the IBAN. You pay these payouts yourself (transfer list or PayPal payouts file) and then mark them as paid; the payment provider is a further recipient of the partner\'s data (privacy policy). Off: new partners can only enter an IBAN; partners who already use another method keep it until they change it.', 'affiliate-captain' ) ),
			'debtor_name' => array( 'type' => 'text', 'tab' => 'payouts', 'default' => '', 'label' => self::l__( 'Company account holder (SEPA)', 'affiliate-captain' ), 'help' => self::l__( 'Account holder of your company account; appears in the SEPA payment file.', 'affiliate-captain' ), 'private' => true ),
			'debtor_iban' => array( 'type' => 'text', 'tab' => 'payouts', 'default' => '', 'label' => self::l__( 'Company IBAN (SEPA)', 'affiliate-captain' ), 'help' => self::l__( 'IBAN of the account the payouts are paid from (SEPA payment file).', 'affiliate-captain' ), 'private' => true ),
			'debtor_bic' => array( 'type' => 'text', 'tab' => 'payouts', 'default' => '', 'label' => self::l__( 'Company BIC (optional)', 'affiliate-captain' ), 'help' => self::l__( 'BIC of your bank; only needed by some banks.', 'affiliate-captain' ), 'private' => true ),
			'remittance_prefix' => array( 'type' => 'text', 'tab' => 'payouts', 'default' => 'Provision', 'label' => self::l__( 'Remittance text prefix', 'affiliate-captain' ), 'help' => self::l__( 'Start of the remittance text on the partner\'s bank statement, followed by the payout number.', 'affiliate-captain' ) ),
			'self_billing' => array( 'type' => 'bool', 'tab' => 'payouts', 'default' => 1, 'label' => self::l__( 'Issue statements as credit notes (self-billing, Gutschrift §14 Abs. 2 UStG)', 'affiliate-captain' ), 'help' => self::l__( 'Commission rates are net; for domestic VAT-registered partners VAT is added and shown. Foreign business partners: reverse charge. Agree self-billing with your partners in the terms and confirm the treatment with your tax advisor.', 'affiliate-captain' ), 'legal' => true ),
			'vat_rate' => array( 'type' => 'text', 'tab' => 'payouts', 'default' => '19', 'label' => self::l__( 'VAT rate in %', 'affiliate-captain' ), 'help' => self::l__( 'E.g. 19 or 7,7 – "%" may be typed.', 'affiliate-captain' ), 'legal' => true ),
			'operator_country' => array( 'type' => 'text', 'tab' => 'payouts', 'default' => 'DE', 'label' => self::l__( 'Your country', 'affiliate-captain' ), 'help' => self::l__( 'Country of your company: decides domestic VAT, reverse charge and the notes on credit notes.', 'affiliate-captain' ), 'legal' => true ),
			'operator_address' => array( 'type' => 'textarea', 'tab' => 'payouts', 'default' => '', 'label' => self::l__( 'Your address (for credit notes)', 'affiliate-captain' ), 'help' => self::l__( 'Your company name and address as printed on credit notes.', 'affiliate-captain' ), 'private' => true ),
			'operator_vat_id' => array( 'type' => 'text', 'tab' => 'payouts', 'default' => '', 'label' => self::l__( 'Your VAT ID', 'affiliate-captain' ), 'help' => self::l__( 'Your VAT ID, printed on credit notes; required for reverse-charge credit notes.', 'affiliate-captain' ), 'private' => true ),
			'operator_tax_number' => array( 'type' => 'text', 'tab' => 'payouts', 'default' => '', 'label' => self::l__( 'Your tax number (if no VAT ID)', 'affiliate-captain' ), 'help' => self::l__( 'Your tax number; printed on credit notes when you have no VAT ID.', 'affiliate-captain' ), 'private' => true ),
			'credit_prefix' => array( 'type' => 'text', 'tab' => 'payouts', 'default' => 'GS', 'label' => self::l__( 'Prefix credit note numbers', 'affiliate-captain' ), 'help' => self::l__( 'Numbers are gapless per year, e.g. GS-2026-000001.', 'affiliate-captain' ), 'legal' => true ),
			'storno_prefix' => array( 'type' => 'text', 'tab' => 'payouts', 'default' => 'ST', 'label' => self::l__( 'Prefix cancellation numbers', 'affiliate-captain' ), 'help' => self::l__( 'Prefix of cancellation documents, e.g. ST-2026-000001.', 'affiliate-captain' ), 'legal' => true ),
			'correction_prefix' => array( 'type' => 'text', 'tab' => 'payouts', 'default' => 'KG', 'label' => self::l__( 'Prefix correction credit note numbers', 'affiliate-captain' ), 'help' => self::l__( 'Correction credit notes of paid payouts, e.g. KG-2026-000001.', 'affiliate-captain' ), 'legal' => true ),
			'einvoice_mail' => array( 'type' => 'choice', 'tab' => 'payouts', 'default' => 'auto', 'options' => array( 'auto', 'on', 'off' ), 'label' => self::l__( 'Send e-invoice (XML) with the credit note e-mail', 'affiliate-captain' ), 'help' => self::l__( 'Whether the electronic invoice (XML) is attached to the credit note e-mail.', 'affiliate-captain' ), 'legal' => true ),
			'einvoice_buyer_reference' => array( 'type' => 'text', 'tab' => 'payouts', 'default' => '', 'label' => self::l__( 'Buyer reference on e-invoices (e.g. Leitweg-ID)', 'affiliate-captain' ), 'help' => self::l__( 'Optional. Empty: the document number is used.', 'affiliate-captain' ) ),
			'vies_check' => array( 'type' => 'bool', 'tab' => 'payouts', 'default' => 0, 'label' => self::l__( 'VIES check: verify partner VAT IDs with the EU Commission', 'affiliate-captain' ), 'help' => self::l__( 'Partner VAT IDs (and your own VAT ID) are sent to the VIES service of the European Commission (ec.europa.eu) when payout details are saved and again after the period set below; the confirmation number is kept as proof for reverse-charge credit notes. A VAT ID reported as invalid blocks reverse-charge payouts. Off: nothing is sent and earlier results no longer block payouts.', 'affiliate-captain' ), 'legal' => true ),
			'fraud_max_orders_day' => array( 'type' => 'int', 'tab' => 'security', 'default' => 0, 'max' => 1000000, 'label' => self::l__( 'Max. orders per partner in 24 hours', 'affiliate-captain' ), 'help' => self::l__( 'More orders of one partner within 24 hours are flagged for review. 0 = rule off.', 'affiliate-captain' ) ),
			'fraud_max_order_minor' => array( 'type' => 'money', 'tab' => 'security', 'default' => 0, 'label' => self::l__( 'Review orders above (net)', 'affiliate-captain' ), 'help' => self::l__( 'Orders above this net value are flagged for review. 0 = rule off.', 'affiliate-captain' ) ),
			'fraud_min_click_seconds' => array( 'type' => 'int', 'tab' => 'security', 'default' => 0, 'max' => 86400, 'label' => self::l__( 'Min. seconds between click and order', 'affiliate-captain' ), 'help' => self::l__( 'Orders placed sooner after the partner link click are flagged for review. 0 = rule off.', 'affiliate-captain' ) ),
			'fraud_max_refund_rate' => array( 'type' => 'int', 'tab' => 'security', 'default' => 0, 'max' => 2147483647, 'label' => self::l__( 'Max. refund rate of a partner in %', 'affiliate-captain' ), 'help' => self::l__( 'Partners whose orders are refunded more often than this (period and minimum number of orders below) get new commissions flagged for review. 0 = rule off.', 'affiliate-captain' ) ),
			'fraud_check_domains' => array( 'type' => 'bool', 'tab' => 'security', 'default' => 1, 'label' => self::l__( 'Review orders with disposable or blocked e-mail domains', 'affiliate-captain' ), 'help' => self::l__( 'Orders with throwaway e-mail addresses or a blocked domain are flagged for review.', 'affiliate-captain' ) ),
			'fraud_blocked_domains' => array( 'type' => 'textarea', 'tab' => 'security', 'default' => '', 'label' => self::l__( 'Additional blocked domains', 'affiliate-captain' ), 'help' => self::l__( 'One e-mail domain per line, in addition to the built-in list of throwaway domains.', 'affiliate-captain' ) ),
			'reauth_required' => array( 'type' => 'bool', 'tab' => 'security', 'default' => 1, 'label' => self::l__( 'Ask for the password again before payout files, marking payouts as paid, bank details and account links', 'affiliate-captain' ), 'help' => self::l__( 'Approving bank details, linking or unlinking a partner account, changing payout details, downloading SEPA, transfer or PayPal files and marking payouts as paid need a password confirmation that stays valid in the login session for the time set below (with the Two-Factor plugin also its second factor). WP-CLI and the REST API are not affected. Switching this off needs a confirmation too.', 'affiliate-captain' ) ),
			'report_admin' => array( 'type' => 'bool', 'tab' => 'emails', 'default' => 1, 'label' => self::l__( 'Monthly summary e-mail to the operator', 'affiliate-captain' ), 'help' => self::l__( 'On the first days of the month you get a summary of the previous month (clicks, sales, commissions, payouts).', 'affiliate-captain' ) ),
			'report_partners' => array( 'type' => 'bool', 'tab' => 'emails', 'default' => 0, 'label' => self::l__( 'Monthly summary e-mail to active partners', 'affiliate-captain' ), 'help' => self::l__( 'Sent on the first days of the month for the previous month.', 'affiliate-captain' ) ),
			// Business rules (S2, SET-AC-12/13/14): safe default = the former fixed value; the old filters still win.
			'code_requests_per_month' => array( 'type' => 'int', 'tab' => 'program', 'default' => 3, 'max' => 100, 'label' => self::l__( 'Code requests per partner in 30 days', 'affiliate-captain' ), 'help' => self::l__( 'How many own codes a partner can request within 30 days (at least 1). Filter wpac_code_requests_per_month wins.', 'affiliate-captain' ) ),
			'code_request_max_length' => array( 'type' => 'int', 'tab' => 'program', 'default' => 20, 'max' => 64, 'label' => self::l__( 'Maximum length of a requested code', 'affiliate-captain' ), 'help' => self::l__( 'Characters, 3 to 64. Filter wpac_code_request_max_length wins.', 'affiliate-captain' ) ),
			'application_confirm_days' => array( 'type' => 'int', 'tab' => 'program', 'default' => 2, 'max' => 30, 'label' => self::l__( 'Confirmation link of an application valid for (days)', 'affiliate-captain' ), 'help' => self::l__( 'An application whose e-mail address is not confirmed within this time is deleted (at least 1 day). Also for applications handed over by Form Captain.', 'affiliate-captain' ) ),
			'bank_change_wait_days' => array( 'type' => 'int', 'tab' => 'security', 'default' => 30, 'max' => 365, 'label' => self::l__( 'New bank details wait for approval after an account change (days)', 'affiliate-captain' ), 'help' => self::l__( 'After a partner\'s e-mail address or linked user changed, new payout details from the partner area wait this many days for approval by someone with the payout permission. 0 = no waiting. Filter wpac_access_change_days wins.', 'affiliate-captain' ) ),
			'reauth_minutes' => array( 'type' => 'int', 'tab' => 'security', 'default' => 15, 'max' => 1440, 'label' => self::l__( 'Password confirmation valid for (minutes)', 'affiliate-captain' ), 'help' => self::l__( 'How long a password confirmation before money-relevant actions stays valid in the login session. 0 = ask every time. Filter wpac_reauth_ttl wins.', 'affiliate-captain' ) ),
			'fraud_refund_window_days' => array( 'type' => 'int', 'tab' => 'security', 'default' => 90, 'max' => 730, 'label' => self::l__( 'Refund rate: period (days)', 'affiliate-captain' ), 'help' => self::l__( 'Period over which the refund rate of a partner is calculated (at least 1 day).', 'affiliate-captain' ) ),
			'fraud_refund_min_orders' => array( 'type' => 'int', 'tab' => 'security', 'default' => 5, 'max' => 100000, 'label' => self::l__( 'Refund rate: minimum number of orders', 'affiliate-captain' ), 'help' => self::l__( 'The refund rule only applies to partners with at least this many orders in the period (at least 1).', 'affiliate-captain' ) ),
			'privacy_requests_per_day' => array( 'type' => 'int', 'tab' => 'data', 'default' => 5, 'max' => 100, 'label' => self::l__( 'Privacy requests per partner and day', 'affiliate-captain' ), 'help' => self::l__( 'Limit for requests from the partner area (data access, erasure, objection) per partner and day (at least 1).', 'affiliate-captain' ) ),
			'terms_reminder_days' => array( 'type' => 'int', 'tab' => 'program', 'default' => 7, 'max' => 365, 'label' => self::l__( 'Remind partners of new terms at most every (days)', 'affiliate-captain' ), 'help' => self::l__( 'Minimum time between two reminder e-mails about new partner program terms to the same partner (at least 1 day).', 'affiliate-captain' ) ),
			'claim_cancel_days' => array( 'type' => 'int', 'tab' => 'payouts', 'default' => 45, 'max' => 365, 'label' => self::l__( 'Payouts claimed by a payment add-on can be cancelled after (days)', 'affiliate-captain' ), 'help' => self::l__( 'Payment providers can settle late; cancelling earlier could pay commissions twice (at least 1 day). Filter wpac_claim_cancel_after wins.', 'affiliate-captain' ) ),
			'payout_request_note_days' => array( 'type' => 'int', 'tab' => 'payouts', 'default' => 60, 'max' => 3650, 'label' => self::l__( 'Show the reason of a rejected payout request for (days)', 'affiliate-captain' ), 'help' => self::l__( 'How long partners see your reason for rejecting their payout request in the partner area.', 'affiliate-captain' ) ),
			'vies_recheck_days' => array( 'type' => 'int', 'tab' => 'payouts', 'default' => 30, 'max' => 365, 'label' => self::l__( 'Check VAT IDs again after (days)', 'affiliate-captain' ), 'help' => self::l__( 'With the VIES check on, valid VAT IDs are checked again after this many days (at least 1).', 'affiliate-captain' ), 'legal' => true ),
			'addon_hints' => array( 'type' => 'bool', 'tab' => 'data', 'default' => 1, 'label' => self::l__( 'Show notes about add-on features', 'affiliate-captain' ), 'help' => self::l__( 'Grey text lines at the end of a tab name features of Affiliate Captain Pro. They contain no form fields and change nothing; switch them off here.', 'affiliate-captain' ) ),
			'delete_on_uninstall' => array( 'type' => 'bool', 'tab' => 'data', 'default' => 0, 'label' => self::l__( 'Delete all data when the plugin is deleted', 'affiliate-captain' ), 'help' => self::l__( 'Commission and payout records, credit notes and the audit log are kept as long as a credit note is within the retention period; everything else is deleted.', 'affiliate-captain' ) ),
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
