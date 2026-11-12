=== WP Affiliate Captain ===
Contributors: wpaffiliatecaptain
Tags: affiliate, partner program, referral, commission, woocommerce
Requires at least: 6.2
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.7.0
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
