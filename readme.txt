=== Affiliate Captain ===
Contributors: wpaffiliatecaptain
Tags: affiliate, partner program, referral, commission, woocommerce
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.10.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Your own partner program: applications, partner codes and links, attribution, commission ledger with refunds, credit notes and SEPA payouts.

== Description ==

Affiliate Captain runs a complete affiliate/partner program inside WordPress – with or without WooCommerce.

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
5. Moving from another affiliate plugin? Import your partners under Affiliate Captain → Import & export.

Requirements: WordPress 6.3+, PHP 7.4+ with the extensions sodium and dom (zip for the program export). WooCommerce is optional (8.0+).

== External services ==

The plugin contacts two services. Nothing else is sent anywhere, and no tracking or telemetry data leaves your site.

**EU VIES VAT number validation (European Commission)**

Used to confirm the EU VAT ID of a partner before reverse-charge credit notes are issued; a VAT ID that VIES reports as invalid blocks reverse-charge payouts.

* What is sent: the partner's VAT ID (country code and number) and – for a qualified confirmation with a consultation number – your own VAT ID from Settings → Payouts & credit notes. No names, addresses or bank details.
* When: in the background after a partner or an administrator saves payout details with an EU VAT ID, when an administrator clicks "Check with VIES" on the partner page, and once a day for results that are older than 30 days or were unavailable (at most 20 per day).
* Endpoint: `https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number`
* It can be switched off in Settings → Payouts (tab "Payouts & credit notes"); then no VAT ID is sent to VIES.
* Service provided by the European Commission: [VIES](https://ec.europa.eu/taxation_customs/vies/), [legal notice and terms of use](https://commission.europa.eu/legal-notice_en), [privacy policy](https://commission.europa.eu/privacy-policy-websites-managed-european-commission_en). The suggested privacy policy text of the plugin names the European Commission as recipient.

**Loopback request to your own site**

To warn you when credit notes stored in the uploads folder could be downloaded by anyone, the plugin writes a random probe file into its statement folder and requests it over HTTP from your own site (`wp-content/uploads/wpac-statements/…`) – at most once a day, when Site Health, the monitoring check or the Integrations page needs the result. The request goes to your own domain only, contains no personal data, and the probe file is deleted immediately. It is skipped when the statement folder is outside the web root (`WPAC_STATEMENT_DIR`, recommended).

== Frequently Asked Questions ==

= Does it work without cookies? =

Yes. Partner codes entered at checkout work without any cookie. The optional affiliate cookie is only set with marketing consent (WP Consent API or the `wpac_has_marketing_consent` filter).

= Does it work with page caches? =

Pages with the application form or the partner welcome (shortcode or block in the content) and pages opened with a referral parameter are marked as not cacheable before any output (DONOTCACHEPAGE and no-cache headers). If the form or the welcome box sits in a widget or theme template, or your cache/CDN ignores these headers, exclude those pages there. For "delay/combine JavaScript" features, exclude the script handle `wpac-beacon` (optional JavaScript tracking).

= Is WooCommerce required? =

No. WooCommerce (8.0 or newer, HPOS and classic or block checkout) is supported out of the box; the partner code field in the block checkout needs WooCommerce 8.9. Other sales sources – your own checkout, an app, a booking system – report sales and refunds through the public contract (`wpac_record_sale()`, `wpac_record_refund()` or REST `wpac/v1/sales`), see docs/CONTRACT.md.

= How are partners paid? Which payout methods are supported? =

Payout runs collect the approved commissions per partner and create a credit note or statement for each payout. You then pay by bank transfer: for EUR as a SEPA XML file (pain.001) for your online banking, for any currency as a transfer list (CSV) that you pay manually. After the transfer you mark the payouts as paid; partners see the date in their partner area. Partners enter their bank details (IBAN, stored encrypted) in the partner area. PayPal, Stripe or Wise payouts are not built in.

= Can I use the credit notes outside Germany, Austria and Switzerland? =

The credit notes (self-billing) follow the VAT rules of the operator country set under Settings → Payouts & credit notes, with legal references for DE, AT and CH; other countries get the German wording. If self-billing is not common or not agreed in your country, switch "Issue statements as credit notes" off: the plugin then creates plain payout statements and your partners invoice you themselves. The VAT treatment can be changed with the `wpac_vat_treatment` filter. Please have the setup checked by your tax advisor.

= My site uses a page cache or CDN. Are partner links still counted? =

Pages opened with a partner link (`?partner=CODE`, parameter configurable) are sent with `DONOTCACHEPAGE` and no-cache headers, which most cache plugins respect. If your cache or CDN still serves such pages from the cache, enable Settings → Tracking → "Also record partner links via JavaScript": a small inline script (handle `wpac-beacon`) then reports the link to `wpac/v1/touch`. Exclude the handle `wpac-beacon` from "delay JavaScript" and JavaScript combining in your optimisation plugin, and do not strip the link parameter at the CDN. Partner codes typed in at checkout always work, with or without cache.

= Does it work on WordPress multisite? =

Yes. Network activation sets up every site (in batches for large networks), new sites are set up automatically and deleted sites drop their tables. Every site runs its own partner program with its own tables, settings, partners and payouts. If you set `WPAC_STATEMENT_DIR`, use a folder that is not shared with other sites' data you might delete.

= Is it GDPR compliant? =

The plugin is built for data minimisation: no IP addresses for attribution, cookie only with consent, customers are never shown to partners, lifetime links store only a keyed hash, bank details are encrypted, retention periods delete old data automatically, and personal data export and erasure (including restriction of processing for records that must be kept) are connected to the WordPress privacy tools. It adds a suggested text to the privacy policy guide and ships a record of processing activities template (Settings → Reports & data). Whether your setup is compliant depends on your situation – have it reviewed by your data protection advisor.

= Does it work with Pass Captain and Voucher Captain? =

Yes. WP Pass Captain (ticket orders, refunds, cancelled events) and Voucher Captain (partner per voucher campaign) report their sales through the versioned public contract; when both are used together, commissions are only paid on the part that was paid with money, not on the voucher. Other plugins can integrate the same way (see docs/CONTRACT.md and the connector starter in the repository).

= How do I import my existing partners, for example from AffiliateWP or SliceWP? =

Under Affiliate Captain → Import & export, upload a CSV file with the columns name, email, website, status, codes, rate, rate_type, group, user_id and channel (only email is required). The file is checked first and every row with an error is listed; nothing is saved until you confirm. Column names of AffiliateWP and SliceWP exports are recognised. Large files: `wp wpac import partners partners.csv --dry-run`. The same page exports the whole program (partners, codes, rules, commissions, payouts) as a ZIP of CSV files.

= Where do I find the documentation? =

The operator handbook (setup, tracking, commissions, payouts, credit notes, privacy, multisite, WP-CLI, monitoring, troubleshooting) is in `docs/HANDBOOK.md`, the REST API in `docs/REST.md`, the integration contract in `docs/CONTRACT.md`.

== Screenshots ==

1. Dashboard with the setup checklist and the key figures of the partner program.
2. Partner list with status, partner codes and balances.
3. Partner details: codes, payout details, account statement and status history.
4. Commission rules and partner groups.
5. Payout run with preview, credit notes and SEPA export.
6. Partner area: partner link with copy button, statistics and commissions.
7. Partner application form block.
8. CSV import of partners with the check result before the import.

== Changelog ==

= 0.10.0 =
Second five-expert review (money, legal, operations, business flows, CI); database schema 8:
* Money: VAT mode and rate are stored per payout item; corrections keep the treatment of the original credit note, are printed in their own section with a reference to it, and VAT is shown per rate; operator data is frozen per payout; payouts are blocked with a clear reason for reverse charge without the operator's VAT ID, self-billing without a terms page, or a VAT ID that VIES reports as invalid
* Monthly mail: failures are stored per month, the retry is locked against parallel runs, a daily watchdog restarts a stalled batch chain; reports and the monthly mail show write-offs
* Privacy: an erasure request restricts processing (Art. 18 GDPR) instead of changing booking texts; free texts, terms records and objections are deleted once the retention period has ended; retention minimum by operator country (DE 8, AT 7, CH 10 years); partners can request restriction in the portal
* Privacy: extended policy text (beacon, retention periods, legal bases, recipients, rights) and a note in the application form; the beacon only sends the host of the previous page and only with marketing consent; the data export now covers commissions, attributions, visits, payouts and notes; double opt-in for all applications; name/website changes of approved partners are reviewed
* New: objections to credit notes (portal + admin), VIES check of VAT IDs, annual overview PDF for partners, record of processing activities template, tools/purge-retained.php for deleting retained data after uninstall
* Operations: schema check case-insensitive and limited to own tables, alarm for a stuck migration, every cron step isolated (queue first), job time budget with continuation, credit note generation with claim and back-off, own rate-limit table, notice for partners who cannot be paid
* Operations: Site Health test and debug info, monitoring endpoint wpac/v1/health with token and daily alarm mail, WP-CLI (wp wpac status|health|upgrade|jobs|cron|verify|maintenance), Action Scheduler when available, WooCommerce logger, maintenance mode, multisite network activation in batches
* Business flows: subscription renewals inherit the partner; commissions are reinstated after a won chargeback or a reactivated order; orders can be re-attributed to another partner; clawbacks before payment are taken out of the open payout; reminder flow and repayment booking for negative balances of ended partners; daily balance reconciliation with alarm; transfer list (CSV) for payouts in any currency
* CI: browser and cross-plugin tests (also with Redis object cache), MySQL 8.4, lower_case_table_names, multisite, upgrade from the previous release, PHPCS/WPCS security sniffs, PHPCompatibilityWP, PHPStan, composer audit, coverage; the release publishes exactly the tested ZIP

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

== Upgrade Notice ==

= 0.11.0 =
The plugin is now called "Affiliate Captain" and lives in the folder "affiliate-captain" (WordPress.org does not allow "WP" in plugin names). Install it next to the old "WP Affiliate Captain" and activate it: the old copy is switched off automatically and all data stays. Afterwards you can delete the old copy; its uninstall routine leaves the data alone while the new copy is active. Requires WordPress 6.3 or newer. Database update to schema 9 runs automatically after the update – make a backup first. New: CSV import of partners and program export; the VIES check of VAT IDs can be switched off.

= 0.10.0 =
Database update to schema 8 (new columns on payouts and payout items) – make a backup before updating. Payouts are now blocked with a clear reason when your own VAT ID, the terms page or a valid partner VAT ID is missing; check the payout preview after the update.

