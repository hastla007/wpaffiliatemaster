=== WP Affiliate Captain ===
Contributors: wpaffiliatecaptain
Tags: affiliate, partner program, referral, commission, woocommerce
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.9.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Your own partner program: applications, partner codes and links, attribution, commission ledger with refund corrections, credit notes and SEPA payouts.

== Description ==

WP Affiliate Captain runs a complete affiliate/partner program inside WordPress – with or without WooCommerce.

* Partner applications with double opt-in, versioned terms acceptance, partner groups with own terms and form fields
* Cookie-free partner codes at checkout (classic and block checkout), referral links, landing pages, QR codes
* Attribution with priorities, self-referral protection and configurable fraud rules
* Commission rules per partner, group, product, category, tag and SKU/course code; monthly tiers; lifetime commissions
* Commission ledger in minor units with immutable snapshots, idempotent refunds, cancellations and chargebacks
* Payout runs and payout requests, credit notes (self-billing) with gapless numbering and VAT handling, SEPA XML export
* Partner area with statistics, links, creatives, commissions and statements
* Reports by partner, content and campaign; monthly summary e-mails; CSV exports
* Privacy: data export/erasure, retention periods, consent-aware tracking; REST API; German translation

Integrations (Pass Captain, Voucher Captain, connector add-ons) use a versioned public contract – see docs/CONTRACT.md.

Legal note: cookie consent, VAT treatment of credit notes and retention periods depend on your situation. Please have them reviewed by your data protection and tax advisors.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` and activate it.
2. Create pages with the blocks (or shortcodes) "Partner application form" `[wpac_affiliate_registration]` and "Partner area" `[wpac_affiliate_portal]`.
3. Configure pages, terms, default commission, hold period, payout and credit note details under Affiliate Captain → Settings.
4. Recommended in wp-config.php: `define( 'WPAC_ENCRYPTION_KEY', '<64 hex characters>' );` and `define( 'WPAC_STATEMENT_DIR', '/path/outside/webroot' );`

== Frequently Asked Questions ==

= Does it work without cookies? =

Yes. Partner codes entered at checkout work without any cookie. The optional affiliate cookie is only set with marketing consent (WP Consent API or the `wpac_has_marketing_consent` filter).

= Is WooCommerce required? =

No. Other sales sources report sales and refunds through the public contract (`wpac_record_sale()`, REST `wpac/v1/sales`).

== Changelog ==

= 0.9.0 =
* Partner area: new tab "My account" – partners change name, website and channel themselves; a new e-mail address is used after confirmation via link
* Operators can change a partner's e-mail; warning when no WordPress user is linked (the partner cannot log in)
* Negative balance (clawbacks without new earnings): can be written off with a reason (audited, account stays consistent)
* WooCommerce Subscriptions: new setting "Commission on subscription renewals" (default on, as before)
* Optional JavaScript tracking for full-page caches/CDNs that serve pages with the referral parameter from the cache; a visit is never counted twice (REST wpac/v1/touch, off by default)

= 0.8.0 =
Review of all modules (security, money, legal, operations, usability):
* Money: a refund that arrives while the sale is still in the retry queue is no longer lost; corrections are only paid out after their commission is approved; balances are booked from the locked current status; refunds with a filtered commission base, several refund lines per item and WooCommerce refunds with 0-total lines are handled correctly
* Foreign currencies: fixed-amount rules are converted with the exchange rates (never replaced by a broader percent rule)
* WooCommerce: "failed" after payment counts as chargeback; the checkout code field is no longer prefilled from the link/cookie
* Tiers: no bonuses for paused or terminated partners, late approvals are re-checked, months cannot be closed before their hold period ended
* Payout requests are settled by regular payout runs
* Lifetime customer links: removed when the first order is fully reversed; Pass Captain no longer maps a typed guest e-mail to a user
* Tracking: signed referral cookie with a server-side attribution window, first touch also across shop sessions, withdrawing consent also ignores the consent-based session referral, referral landings bypass full-page caches
* Security: registration stores nothing before nonce/honeypot/rate-limit checks; private group signatures are bound to the published page; vanity slugs only work in links (not at checkout or via REST); unknown codes no longer cause full table scans
* Credit notes: name, address and (for small businesses) tax number or VAT ID are required, also the operator's address and VAT ID/tax number; with self-billing a payout needs accepted terms; VAT notes follow the operator country (DE/AT/CH); every payout needs its PDF before export or payment
* Retention: statements at least 10 years, cancellations from their own date, new "tax audit / legal hold" switch, financial audit log kept as long as the statements, uninstall keeps records that must still be retained
* Privacy: bank details of erased partners are deleted once everything is settled; free-text reasons are scrubbed; rejected applications are anonymised after 180 days; terms acceptance stores text, page, revision and time
* Operations: schema upgrades with a lock, warning when scheduled tasks do not run, isolated daily steps, jobs with lease (a crashed run no longer retries forever), time budget for the hourly approval, stable batches for the monthly mail, new report indexes (database schema 7)
* Partner area: reasons for rejected commissions, payout requests and account status; read-only access for terminated partners; "transferred on" dates; the payout form keeps entries after an error; mobile-friendly tables
* E-mails: welcome mail for auto-approved partners, "payout transferred" mail
* Admin: confirmations for destructive actions, labelled reason fields, readable statuses
* Note for the update: partners whose payout details lack name or address (or, as small businesses, a tax number or VAT ID) are held back from payouts until they complete them; the payout preview shows the reason. Enter your own address and VAT ID or tax number under Settings → Payouts.

= 0.7.0 =
* Faster campaign and content reports with many visits (new indexes, database schema 6)
* Old visits and audit entries are deleted in small batches
* Accessibility: labels for all admin form fields, better contrast and link marking (checked with axe)
* Browser test suite (Playwright + axe) in tests/e2e
* WordPress multisite: network activation sets up every site, new sites are set up automatically, deleted sites drop their tables, uninstall per site
* Privacy: with the WP Consent API but no consent banner, the affiliate cookie is no longer set (the API alone is not a consent)
* The monthly report is sent only once when cron runs overlap
* Cookie-free referrals keep campaign and content for the reports
* No database errors in the log when the same order is reported concurrently

= 0.6.0 =
* Integration contract 1.1.0 (compatible with 1.0.0): new `wpac_current_touch()` for sources with their own checkout
* Adapters for WP Pass Captain (native ticket orders, refunds, cancellations, event cancellations) and Voucher Captain (partner per code campaign)
* Forms return to the page they were sent from (fix for sites where the referer equals the form URL)

= 0.5.0 =
* Partner account ledger: append-only, hash-chained bookings with running balance and integrity check (account statement per partner)
* Monthly tier bonuses are recalculated after closing; differences are booked as corrections
* Partner and commission lists use the WordPress list table (sorting, views, search, bulk actions)
* Internal restructuring: WooCommerce integration split into focused classes, shared paging helper

= 0.4.0 =
* Setup checklist with automatic creation of the application, partner area and landing pages
* Settings, rules and partner details organised in tabs; dependent fields in the rule form
* Partner area: start section with partner link, copy buttons and next steps; helpful empty states; status badges
* Application form keeps entries and marks the faulty field; required fields explained
* Admin messages no longer passed in the URL; readable source/type labels; confirmations for destructive actions
* Accessibility: labels, focus styles, aria-current, live announcements; improved German wording

= 0.3.0 =
* Credit notes (self-billing) with VAT handling, gapless numbering and cancellation documents
* Double opt-in for applications, random partner codes, proxy-aware rate limiting
* Consent-aware shop session tracking, complete privacy export/erasure, retention periods
* Atomic ledger and payout state changes, refund locking, payout cut-off handling
* Performance: indexes, request cache, targeted editor statistics, batched monthly e-mails
* Blocks use block.json (apiVersion 3)

= 0.2.0 =
* Reporting, editor blocks, REST API, German translation, fraud rules, multi-currency rules

= 0.1.0 =
* Initial version
