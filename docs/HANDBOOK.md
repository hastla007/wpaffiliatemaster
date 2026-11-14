# Affiliate Captain – Operator handbook

This handbook is for the people who run a partner program with Affiliate Captain: shop owners, administrators and the person who does the payouts. Developers integrating other sales sources find the public contract in [CONTRACT.md](CONTRACT.md) and the REST API in [REST.md](REST.md).

> Legal note: cookie consent, VAT treatment of credit notes and retention periods depend on your country and business. The plugin gives you the tools; have the setup reviewed by your data protection and tax advisors.

Contents

1. [Requirements and setup](#1-requirements-and-setup)
2. [Partners](#2-partners)
3. [Tracking, caching and CDNs](#3-tracking-caching-and-cdns)
4. [Commissions and rules](#4-commissions-and-rules)
5. [Payouts: SEPA and manual transfers](#5-payouts-sepa-and-manual-transfers)
6. [Credit notes and VAT](#6-credit-notes-and-vat)
7. [Fraud and abuse](#7-fraud-and-abuse)
8. [Privacy](#8-privacy)
9. [Multisite](#9-multisite)
10. [Import, export and migration](#10-import-export-and-migration)
11. [WP-CLI](#11-wp-cli)
12. [Monitoring](#12-monitoring)
13. [Troubleshooting](#13-troubleshooting)
14. [Uninstalling](#14-uninstalling)

---

## 1. Requirements and setup

**Requirements:** WordPress 6.3 or newer, PHP 7.4 or newer with the extensions `sodium` (encryption of bank details) and `dom` (SEPA XML), optionally `zip` (program export). MySQL/MariaDB with InnoDB tables (bookings run in transactions). WooCommerce is optional (8.0 or newer; the partner code field in the block checkout needs 8.9).

**Before you activate** – add to `wp-config.php`:

```php
// Own key for the encryption of partner bank details: 64 hex characters,
// e.g. from: php -r 'echo bin2hex(random_bytes(32));'
define( 'WPAC_ENCRYPTION_KEY', '…' );

// Credit notes outside the web root (required on nginx, where .htaccess has no effect).
define( 'WPAC_STATEMENT_DIR', '/var/www/private/wpac-statements' );
```

**Several app servers** (load balancer): the statement folder must be shared storage mounted at the same path on every server (NFS, EFS …), set with `WPAC_STATEMENT_DIR` or the filter `wpac_statement_dir`. Without it a download on another server finds no file – the plugin then builds the document again from the stored data (identical when nothing relevant changed, otherwise marked as *duplicate*), and the deletion after the retention period only reaches the copy on the server that runs the cron.

Set both **before** the first partner enters bank details and the first credit note is written. Without `WPAC_ENCRYPTION_KEY` the key is derived from the WordPress salts – changing the salts later makes stored IBANs unreadable. Keep a copy of the key in your password manager: without it, bank details cannot be decrypted after a server move.

**Setup checklist.** After activation, Affiliate Captain → Dashboard shows a checklist. "Create pages automatically" adds the application form, the partner area and a landing page with the matching blocks. Then go through Settings:

| Tab | What to set |
|---|---|
| Program | application and partner area pages, terms page and version, automatic approval, open commissions on termination |
| E-mails & reports | notification e-mail, e-mail sender, optional partner e-mails, monthly summary mails |
| Tracking | link parameter (default `partner`), checkout field, shop session, cookie mode, attribution (first/last touch), JavaScript tracking for caches |
| Fraud & abuse | fraud rules, rate limit, trusted proxies (Cloudflare/reverse proxy IP ranges) |
| Commissions | program currency (changing it converts the minimum payout, review limit and a fixed default rate – with an exchange rate between the two currencies if one is entered; fixed rules and bonus tiers must be adjusted by hand), exchange rates for reports, default rate, rule precedence, lifetime commissions, subscription renewals, hold period |
| Promotion | landing page path, disclosure note, promotion guidelines |
| Payouts & credit notes | minimum payout, payout requests, other payment method (on/off); your country, address, VAT ID/tax number; self-billing, VAT rate, number prefixes; VIES check; company bank account for SEPA |
| Data & maintenance | all retention periods (visits, rejected applications, audit log, statements, legal hold), maintenance mode, monitoring token, data deletion on uninstall, record of processing activities |

**Server cron.** Commissions are released, retries run and old data is deleted by scheduled tasks (hourly and daily). On sites with few visits or with `DISABLE_WP_CRON`, set up a system cron:

```
*/5 * * * * cd /path/to/wordpress && wp cron event run --due-now --quiet
```

On a multisite network `wp cron event run` only reaches the main site. Run it for every site:

```
*/5 * * * * cd /path/to/wordpress && wp site list --field=url | xargs -I{} wp cron event run --due-now --quiet --url={}
```

(or `wp wpac cron hourly --network` hourly and `wp wpac cron daily --network` daily).

**Copies of the site.** When the site address changes (staging copy, clone, backup restored on another domain), maintenance mode is switched on automatically and a notice explains it: the copy sends no report mails, alarms, VIES requests or payouts to real partners. After a real move of the site, switch maintenance mode off under Settings → Data & maintenance. The filter `wpac_clone_protection` (return `false`) turns the detection off.

**Updating by FTP/rsync.** Since 0.10 the main file is `affiliate-captain.php` (folder `affiliate-captain`). If you copy a new version over an old folder `wp-affiliate-captain/`, the old main file `wp-affiliate-captain.php` may stay active: the plugin then switches the activation to the new main file in the same folder by itself (from the next request on; the schema version is part of the code, so nothing runs with old version numbers meanwhile). An old copy in another folder is taken over the same way; "Delete all data on uninstall" is then switched off, so deleting the old copy cannot remove the shared data. A deployment that deletes the old main file (e.g. git) deactivates the plugin – activate "Affiliate Captain" again; nothing is lost.

## 2. Partners

**Applications.** The application form (block "Partner application form" or `[wpac_affiliate_registration]`) asks for name, e-mail, website and channel, plus the fields of the chosen partner group. Applicants confirm their e-mail address (double opt-in) and accept the terms; the version, time and accepted text are stored. Applications appear under Partners with the status "Application pending" unless automatic approval is on.

**Approval.** On approval the partner gets a WordPress account (or is linked to the existing one after confirming a link sent by e-mail – accounts with editing rights are never linked automatically) and a random partner code. Pause, termination and rejection need a reason, which is shown to the partner.

**Partner groups** (Rules & groups) have their own terms page and version, their own application fields and their own commission rules – for example "Influencer" and "Schools".

**Partner codes.** Every partner has at least one code (random by default, a custom code is possible). Codes can have a usage limit and an expiry date, be linked to a WooCommerce coupon, or be created in batches (with CSV export) for print campaigns.

**Partner area** (block "Partner area" or `[wpac_affiliate_portal]`): partner link with campaign builder (campaign, source and sub-ID), short link and QR code, codes, creatives (with embed code and sub-ID), statistics (period filter, conversion rate, earnings per month, report by campaign and sub-ID), commissions and payouts (filters, CSV export, without customer data), credit notes, payout details (bank and tax data), the partner's conditions and terms, e-mail preferences, account and privacy requests. The page with the partner area is never cached (`DONOTCACHEPAGE`), and partners land there after logging in (also from WooCommerce "My account", which gets a "Partner area" entry).

**Sub-IDs.** Partner links accept a sub-ID next to the referral parameter (`?partner=CODE&sub=placement-1`; parameter name via the filter `wpac_sub_id_param`). It is stored with the visit and the attribution (together with the visit ID) and shown to the partner per campaign and sub-ID.

**Broadcast mail.** Under Partners, "Write to all active partners" sends a plain-text e-mail to all active partners or one group, in batches of 50 in the background. Every mail has an unsubscribe link (type "broadcast"); partners can also switch announcements, commission notices and the monthly summary off under "My account".

## 3. Tracking, caching and CDNs

A sale is attributed to a partner by (in this order): a manual assignment by an administrator, a partner code entered at checkout, a partner coupon (if prioritised), a partner link (first or last touch), a coupon. Self-referrals (same account, same e-mail) are blocked or flagged for review.

**Partner links** look like `https://example.com/?partner=CODE` (parameter configurable) or `https://example.com/partner/slug/` (landing page). The visit is recorded without IP address (the referrer host only with marketing consent). The code is kept in an existing WooCommerce session (a cart) and – only with marketing consent – in the cookie `wpac_ref` (in a multisite network `wpac_ref_{site ID}`, filter `wpac_cookie_name`). A click alone never creates a shop session. Consent is read from the WP Consent API or the filter `wpac_has_marketing_consent`.

**Consent given after the landing.** Most visitors answer the consent banner after the page has loaded. On a landing page that was captured without consent, a small script (handle `wpac-beacon`) waits for the WP Consent API event `wp_listen_for_consent_change` and, when marketing consent is given, sends the referral again (`POST /wp-json/wpac/v1/touch` with `consent: true`), which sets the cookie without counting a second visit. Banners without WP Consent API support call `window.wpacConsentGranted()` after consent. If the visitor gives consent only on a later page, the referral is still kept when the first product is put into the cart from the landing page (the cart fallback reads the referring page and then also sets the cookie with consent).

**Page caches.** Pages opened with the link parameter are sent with `DONOTCACHEPAGE` and no-cache headers, which WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache and most hosting caches respect. Check that your cache does **not** ignore query strings for the link parameter (some caches strip unknown parameters such as `partner`). The `/partner/slug/` redirect and the public code check `GET /wpac/v1/codes/{code}` are always sent as not cacheable. Prefetch/prerender requests (Speculation Rules, `Sec-Purpose: prefetch`) for partner links are declined (503) and `/partner/*` is excluded from WordPress' speculative loading, so only real clicks count.

**CDNs and edge caches** (Cloudflare APO, Varnish, Fastly) may serve the cached page anyway. Then enable Settings → Tracking → "Also record partner links via JavaScript": a small inline script with the handle **`wpac-beacon`** reports the link to `POST /wp-json/wpac/v1/touch`. Pages with partner links then stay cacheable for guests (no `DONOTCACHEPAGE`): the script records every guest visit, logged-in users are captured on the server. In optimisation plugins exclude `wpac-beacon` from "Delay JavaScript", "Combine/minify inline JavaScript" and lazy-loading of scripts, and make sure `/wp-json/wpac/v1/touch` is not cached or blocked by a firewall rule. A visit of the same browser (address + user agent) is counted once per minute; canonical redirects and reloads do not count twice. Visits of administrators and of the partner on their own link are not counted.

**Landing page and shop on different subdomains** (e.g. `www.example.com` and `shop.example.com`): enter the common domain `.example.com` under Settings → Tracking → "Cookie domain" (filter `wpac_cookie_domain`). The cookie, the cart fallback and the beacon (`Origin` check, filter `wpac_touch_allowed_origin`) then accept both hosts. A headless frontend on another domain can report links itself with `POST /wpac/v1/touch` once its origin is allowed with that filter.

**High traffic.** Settings → Tracking → "Record link visits (%)" writes only a sample of visits to the visit log (or none with 0, filter `wpac_record_visit`); attribution is not affected. Rate-limit counters are kept in a shared object cache (Redis/Memcached; not APCu, which is local to each server) or in a small table that is cleaned every hour.

**Checkout codes** typed in by customers work without any cookie or cache configuration – recommend them to partners who promote offline, in podcasts or on social media.

## 4. Commissions and rules

Commissions are calculated per order item on the net amount after discounts, without tax and shipping. Rules (Rules & groups) combine:

- **scope:** everyone, a partner group, one partner;
- **target:** all products, product IDs, categories, tags, SKUs/course codes (with `*`);
- **rate:** percent, fixed amount per unit (program currency), or exclusion.

**Which rule applies (Settings → Commissions → Rule precedence).**

- *Partner rules first* (default): an exclusion always wins; otherwise the scope counts first (partner > group > global), then the target (product/SKU > category/tag > all). Example: "Partner X / all products 3 %" beats "everyone / product 55 20 %", and a global exclusion of a category cannot be lifted for a VIP partner.
- *Most specific product first*: the target counts first, then the scope; exclusions compete like other rules (at the same rank the exclusion wins). Here "everyone / product 55 20 %" beats "Partner X / all 3 %", and "Partner X / category 7 15 %" lifts "everyone / category 7 excluded".
- Within the same rank the finer match wins: a rule for the variation before a rule for its parent product, an exact SKU before a wildcard (longer prefix first), the product's own category before a parent category (rules on a parent category apply to its subcategories). Then the priority, then the newest rule.
- `priority` only decides between rules of the same rank.
- The **lifetime rate** (if set) replaces all rates for repeat purchases of linked customers, including fixed amounts of product rules; only exclusions still apply.
- Fixed amounts count per unit (a bundle or composite product counts as one line; its parts are added to it) and never exceed the net amount of the line. Gift card purchases are not commissioned; discounts booked as negative fees and gift cards redeemed as payment lower the base.

The rate, base and rule are stored with every commission: changing a rule never changes past commissions. The rule preview shows which rule applies to a product.

**Lifecycle.** A commission starts as *pending* and is released (*approved*) after the hold period (Settings → Commissions; integrations can extend it, e.g. until a course has taken place). The hold period starts at payment, or – setting "Hold period starts" – when the WooCommerce order is completed (pre-orders, invoice, cash on delivery). Commissions of WooCommerce orders that are *on hold* (PayPal/Stripe disputes) or in a chargeback status are not released until the order is processing or completed again (filters `wpac_wc_hold_statuses`, `wpac_hold_release`). Refunds create negative correction lines, proportional per item; a cancellation, chargeback, trashed or deleted order reverses the rest; lowering order items after payment reduces the commission. Raising them (higher quantity or price, new items) books the difference automatically (setting "Book additional commission when a paid order is increased", on by default; filter `wpac_book_order_increase`): a line that already earned commission keeps the rate stored with it, a new item gets the rules that apply now; the attribution never changes. The difference is a new commission of the same order and partner – an approved or paid commission is never changed – with its own hold period from the change (never released before the original) and under review while the order is. A partner who is no longer active or whose processing is restricted, and orders that were rejected or reversed, earn nothing more; the order gets a note with the reason. Saving the order again books nothing twice; later refunds take the additional commission first. A change back to a paid status (or restoring the order from the trash) reinstates a reversed commission; money that was refunded stays deducted. Each event is booked once only, even if it is reported several times. Corrections after a payout are deducted from the next payout. *Review* marks commissions held by a fraud rule. A rejected commission can be reopened for review (commission detail → Reopen); manual bookings (commission, bonus or deduction for a partner, or a correction of one commission) are approved at once and need a reason.

**Monthly tiers** add a bonus in percent of the monthly net revenue (or order count), either marginal (only above the threshold) or retroactive. **Lifetime commissions** give repeat purchases of a referred customer to the same partner. The binding is created only by an order with a customer account that earned a commission (never by a guest order, whose e-mail address is not verified), waits for the approval while the order is under review, and ends when the partner is terminated or lifetime commissions are switched off. The order box shows the binding of the order's customer and can remove it; `wp wpac customer-link` does the same from the command line. **Subscription renewals** (WooCommerce Subscriptions) are commissioned by default – not when the commission of the first order was rejected or fully reversed, under review when the first order is, and optionally only for a number of months (Settings → Commissions).

Every partner has an append-only, hash-chained account per currency (partner page → account statement). `wp wpac verify` and a daily check compare the accounts with the bookings.

## 5. Payouts: SEPA and manual transfers

1. **Payout details.** Partners enter name, address, country, tax status (business, small business, private), VAT ID or tax number and bank details in the partner area (administrators with `wpac_manage_payouts` can enter them on the partner page). IBANs are stored encrypted and shown masked.
2. **Payout run** (Payouts): choose the period end; the preview lists every partner with an approved balance and why someone cannot be paid (profile incomplete, no accepted terms, missing operator VAT ID for reverse charge, VAT ID invalid according to VIES, below the minimum, maintenance mode). Balances below the minimum and negative balances are carried forward.
3. **Credit note / statement.** Each payout gets a PDF with a gapless number per year (`GS-2026-000001`), stored in the statement folder and protected by a hash.
4. **Transfer.**
   - **SEPA (EUR):** select the payouts and download the SEPA XML file (pain.001.001.03) for your online banking. Needs the company account under Settings → Payouts & credit notes and the PHP extension `dom`.
   - **Manual transfer (any currency):** download the transfer list (CSV; separator ";", decimal comma and column names in the site language, readable in German Excel) and pay the amounts manually or by importing the list into your banking software.
   - **Other payment method (PayPal, Wise …):** partners can choose "other payment method" instead of an IBAN and enter free-text payment details (stored encrypted; switch it off under Settings → Payouts & credit notes – partners who already use it keep it until they change it). These payouts are left out of the SEPA file. The transfer list contains their payment details; **"Download PayPal payouts file"** creates the file for the mass payout upload in your PayPal business account (Pay & get paid → Payouts) for all selected payouts whose payment details contain an e-mail address – the others are listed and left as they are. There is no PayPal API connection: you upload the file and confirm the payout in PayPal yourself. Mention the payment provider as a recipient in your privacy policy (the suggested privacy text and the record of processing activities do so while the method is switched on).
   A payout can only be exported once; "export again" is only for files the bank did not process. After a download the page reloads and says what is in the file, what was left out and that the payouts are now "exported"; the status filter above the list shows e.g. all exported payouts that still have to be marked as paid.
5. **Mark as paid** after the bank (or PayPal) executed the transfer. Partners get an e-mail and see the date ("transferred on" for bank transfers, "paid on" for other methods).

The credit notes of a payout run are written in the background by the job queue (Integrations → queue), so runs with many partners do not time out; a payout can only be exported or marked as paid once its credit note exists (missing ones are written at that moment). There is no automatic payout rhythm: you start each run yourself (the former setting "payout period" had no effect and was removed).

**Read-only access for your tax advisor:** give the user the role "Tax advisor (Affiliate Captain, read only)" (or the capability `wpac_view_tax_data` alone). They see the payout list and the payout details and can download credit notes and cancellation documents, but cannot create, export or change payouts.

**Separation of duties:** approving commissions before the end of the hold period and assigning or re-assigning orders to partners need `wpac_manage_payouts`; partner managers (`wpac_manage_affiliates` only) cannot steer money to a partner and release it early.

**Changing bank details in the partner area** (IBAN, payment details or payout method) needs the partner's current password; the partner and the previous address are informed by e-mail. Exports refuse payouts whose partner changed the details after the payout was created.

**Terminating a partner** with "open commissions are rejected" cancels the partner's payouts that were not exported yet (cancellation document) before the commissions are rejected; already exported payouts are listed for you to check. Write-offs and repayments are never rejected.

**Objections** of partners against a credit note appear on the Payouts page. For unpaid payouts, cancel and create a corrected one; a paid payout cannot be cancelled – agree the correction with the partner (their own invoice, separate transfer or repayment) and describe what was done.

**Cancelling** a payout (before or after export, with a reason) releases its commissions for the next run and, for credit notes, creates a cancellation document (`ST-…`). **Payout requests:** if enabled, partners request the payout of their approved balance; you create the payout from the request or reject it with a reason.

**Negative balances** of ended partners (clawbacks without new earnings) can be written off with a reason or claimed back with up to three payment requests; repayments are booked on the partner page. The claim includes the VAT that was paid out with the original credit note (a refund corrects it, §17 UStG): 100 € commission paid with 19 % VAT and refunded later means a claim of 119 €. Repayments and write-offs are not taxable themselves. Once the balance is settled (fully repaid or written off), a correction document (`GS-…`, "Correction of credit notes") is issued automatically and sent to the partner; it reduces the net amount and VAT of the original credit notes, nothing is transferred.

A payout is never created with a negative or too small **gross** amount: VAT corrections of earlier credit notes count (e.g. after a change of the tax status), the balance is then carried forward.

## 6. Credit notes and VAT

With **self-billing** (Settings → Payouts & credit notes, on by default) the plugin issues credit notes in the name of the partner (Gutschrift, §14 Abs. 2 UStG). This needs a prior agreement – put it into your partner terms; payouts are blocked for partners who have not accepted terms.

| Partner | VAT on the credit note |
|---|---|
| business in your country | your VAT rate is added (payout = gross) |
| business in another EU country | reverse charge, no VAT; partner VAT ID required and checked with VIES |
| small business (e.g. §19 UStG) | no VAT, note on the credit note |
| private person | no VAT |

Commission rates are net amounts. Legal references on the documents follow your country (German, Austrian or Swiss law for DE, AT, CH; the EU VAT Directive for other countries, whose credit notes are titled "Credit note (self-billing)"). For reverse charge your own VAT ID is required. Outside DACH or if self-billing is not agreed, switch self-billing off – the plugin then creates payout statements and partners send you invoices. The treatment can be adjusted with the filter `wpac_vat_treatment`.

**VAT by the time of the service.** The VAT treatment of each commission follows the tax data and the VAT rate valid when the commission was earned (for corrections: of the original commission), not the day of the payout. Every change of a partner's tax status, country or VAT ID and every change of the VAT rate keeps the previous values with the time until which they applied. A credit note can therefore show lines with different treatments (e.g. December as small business without VAT, January with 19 %); each treatment gets its own note. Corrections of earlier credit notes keep the treatment of the original credit note.

**Tax data of partners.** Partners enter tax status, VAT ID or tax number in the partner area. A VAT-registered business may give a tax number instead of a VAT ID (§14 Abs. 4 UStG); reverse charge across borders needs the VAT ID. VAT IDs are checked for their format and – for partners in an EU country – for the country prefix. Changes that change the VAT on credit notes (a first declaration as VAT-registered business, a new tax status, a new VAT ID of a business) only take effect after you approve them on the partner page (Payout details → Approve tax data); until then the previous data apply, and the approved data only apply to commissions earned from then on. Every change is logged with the old values and reported to you by e-mail. Under Payouts, small business partners whose credit notes alone exceed the small business limit (25,000 € previous year / 100,000 € current year, filter `wpac_small_business_limits`) are listed.

**Language.** Credit notes are always written in the site language (with WPML/Polylang the default language; frozen with the document, whoever creates it). For languages the PDF fonts cannot show (Cyrillic, Greek, Arabic, Asian scripts) English is used (filter `wpac_statement_locale`). Names and addresses with characters outside Western European (Ł, ś, ř, ő …) are transliterated (Ł → L); the original spelling is kept in the document properties and listed in a note on the document. A Unicode font is not embedded (it would make every PDF several hundred KB larger).

**Sending.** Every credit note, correction document and cancellation is e-mailed to the partner with the PDF attached when it is issued (a credit note takes effect once the partner received it; filter `wpac_notify_credit_note`). A cancellation document that could not be written is retried hourly.

**VIES (opt-in).** The check is **off by default** (it is an external request); switch it on in Settings → Payouts & credit notes → "VIES check". When on, EU VAT IDs of partners are checked with the VIES service of the European Commission (in the background after saving, daily for older results of active partners; your VAT ID is sent along for a qualified confirmation with consultation number). An invalid result (also for a domestic VAT ID, and for a malformed number) blocks credit notes with VAT or reverse charge; "unavailable" blocks nothing and is retried. If VIES refuses your own VAT ID, the check runs without it and the partner page says so. When the check is switched off again, earlier results no longer block; timeout and daily limit can be changed with the filters `wpac_vies_timeout` and `wpac_vies_daily_cap`. Without the check you are responsible for verifying partner VAT IDs yourself (e.g. on the VIES website) before reverse-charge payouts.

**Retention.** Credit notes, payout data and the accepted terms are kept for the statutory period (at least 10 years for the PDFs; DE 8, AT 7, CH 10 years for booking records, counted from the end of the year) and deleted afterwards. "Tax audit / legal hold" stops all deletion. Objections of partners against a credit note are listed under Payouts.

## 7. Fraud and abuse

Settings → Fraud & abuse: maximum orders per partner in 24 hours, maximum order value, minimum seconds between click and order, maximum refund rate, disposable or blocked e-mail domains. Hits put the commission into *review* with the reason; approve or reject it in Commissions. Self-referrals are blocked (or flagged). Partner code checks, the application form and the touch endpoint are rate limited per client. Behind Cloudflare or a reverse proxy, enter its IP ranges under "trusted proxies", otherwise all visitors share one rate limit (Site Health → "Partner links" warns when a request arrives through an unconfigured proxy). The client address is then read from `X-Forwarded-For`; `CF-Connecting-IP`/`X-Real-IP` only when enabled with the filter `wpac_client_ip_headers` (only safe if every trusted proxy sets these headers). Every limit can be changed with the filter `wpac_rate_limit` (`$limit, $bucket, $window`, buckets e.g. `code_check`, `rest_code`, `register`, `visit`). The beacon itself is never limited – a referral is not lost behind a CDN.

## 8. Privacy

- No IP address is stored for attribution; visits keep the referrer host, campaign and landing content only, and are deleted after the retention period.
- The affiliate cookie is only set with marketing consent (cookie mode "consent", default).
- Partners never see customer names or contact details.
- Lifetime links store a keyed hash of the customer, not the e-mail address.
- Bank details are encrypted; tax and bank data are only visible with `wpac_view_tax_data`.
- WordPress privacy tools: personal data export and erasure for partners and customers. Records that must be retained are restricted (Art. 18 GDPR) instead of deleted and erased when the retention period ends.
- Settings → Privacy → Policy Guide contains a suggested text based on your settings (including the VIES recipient); Settings → Data & maintenance offers a record of processing activities.
- External services: only the VIES check (European Commission) and a loopback request to your own site, see readme.txt → External services.

## 9. Multisite

- **Network activation** sets up every site in batches (large networks finish in the background); new sites are set up automatically.
- Every site has its own tables, settings, partners, payouts and credit note numbers. Partners of one site are not partners of another.
- Deleting a site drops its tables – except the booking records (partners, commissions, payouts, accounts, audit log, terms acceptances) while a credit note is within the statutory retention period: they stay in the database, the site's credit notes are moved to `wp-content/uploads/wpac-retained/site-<id>/` (with `WPAC_STATEMENT_DIR` they stay in its `site-<id>` folder), and the daily maintenance deletes both once the period has ended. Deactivating or deleting the plugin works per site.
- WP-CLI commands work on one site (`--url=…`); `--network` runs `status`, `health`, `upgrade`, `jobs`, `cron` and `verify` on every site where the plugin is active.
- A deleted WordPress user is disconnected from the partner automatically (also when removed from a site); the partner page offers "Disconnect WordPress user" as well.
- With `WPAC_STATEMENT_DIR` all sites use the folder you define; use a folder per network that only holds credit notes, back it up, and never clean it manually.
- Capabilities are granted to the administrators of each site; super admins have them everywhere.

## 10. Import, export and migration

Affiliate Captain → Import & export:

- **Import partners** from a CSV file (from AffiliateWP, SliceWP, a spreadsheet …). The file is checked first; each row with an error is listed with the reason and skipped; nothing is saved until you confirm. Format and mapping: [IMPORT-EXPORT.md](IMPORT-EXPORT.md). E-mails to imported partners are off by default.
- **Export program:** ZIP with one CSV file per list (partners, groups, codes, rules, tiers, terms acceptances, commissions, payouts, payout items, payout requests). Bank and tax data only on explicit request by users with `wpac_view_tax_data`.

Commissions and payouts of the old plugin are not imported – keep the old plugin's records (or its export) for your bookkeeping, and start the new program with the import date.

## 11. WP-CLI

| Command | Purpose |
|---|---|
| `wp wpac status` | plugin and schema version, queue, next and last cron runs, retention |
| `wp wpac health` | the health checks (same as Site Health) |
| `wp wpac support [--format=table]` | anonymized support report (JSON): versions, environment, relevant settings, queue, checks – no names, e-mail addresses, bank details or keys |
| `wp wpac upgrade` | run the database migration now |
| `wp wpac jobs [--retry-failed] [--limit=<n>]` | work through the retry queue |
| `wp wpac jobs list [--status=failed] [--format=json]` | list the retry queue with attempts and last error |
| `wp wpac cron hourly\|daily` | run the maintenance now |
| `wp wpac verify [--limit=<n>]` | check every partner account against its bookings (all accounts) |
| `wp wpac customer-link <e-mail\|user-id> [--remove] [--reason=<text>]` | show or remove a customer's lifetime partner binding |
| `wp wpac maintenance on\|off` | maintenance mode |
| `… --network` | `status`, `health`, `upgrade`, `jobs`, `cron` and `verify` on every site of the network |
| `wp wpac import partners <file.csv> [--dry-run] [--notify]` | import partners |
| `wp wpac export <file.zip> [--include-tax-data --user=<login>]` | export the program |

## 12. Monitoring

- **Site Health** (Tools → Site Health) shows the checks: schema, scheduled tasks, failed sales/refunds, account consistency, unwritten credit notes, publicly reachable statement folder, own encryption key, maintenance mode.
- **Alarm mail:** a daily check e-mails the notification address when a critical check fails.
- **HTTP endpoint** for uptime monitors: set a monitoring token (at least 20 characters) under Settings → Data & maintenance, then call

  ```
  curl -fsS -H 'X-WPAC-Monitor-Token: <token>' https://example.com/wp-json/wpac/v1/health
  ```

  `200` = OK, `503` = a critical check failed (the body names the failing checks, no details).
- **Logs:** errors go to the WooCommerce logger (WooCommerce → Status → Logs, source `affiliate-captain`; entries written before 0.11 are under `wp-affiliate-captain`) or, without WooCommerce, to the PHP error log (lines start with `Affiliate Captain error:` / `warning:`). Logged are: failed jobs (the first failure and the final one, with exception class, file and line), sales/refunds that could neither be booked nor queued (hook `wpac_contract_not_queued`), e-mails that could not be sent (with the reason of the mailer), credit notes that could not be written (with the cause), aborted migrations and failed scheduled steps. With `WP_DEBUG` (or the filter `wpac_log_debug`) also repeated job failures and skipped mails. Every administrative change is in the audit log.

### E-mails (sender, Reply-To, partner e-mails)

All e-mails of the plugin go through one function (developers: filter `wpac_mail`, actions `wpac_mail_sent` / `wpac_mail_failed`, see [CONTRACT.md](CONTRACT.md)). Settings → Program → E-mails:

- **Sender name / address:** empty = WordPress default (or your SMTP plugin). Use an address of your own domain (SPF/DKIM), otherwise mails may land in spam.
- **Reply-To:** mails to partners and applicants carry the notification e-mail as Reply-To, so "please reply" (payment request, bank details changed …) reaches you and not `wordpress@…`.
- **Partner e-mails:** payout paid (on by default; the text names the bank account only for SEPA payouts), new/approved/rejected commissions (off by default; at most one mail per partner and run). The monthly summary and the commission mails contain a one-click unsubscribe link (also as `List-Unsubscribe` header).
- **Applicants** get a confirmation after the double opt-in ("we received your application"); the operator mail about a new application is written in the operator's language (user language of the account with the notification address, otherwise the site language) and has the applicant as Reply-To.
- A failed mail is logged with the reason (see Logs above).

## 13. Troubleshooting

| Symptom | Cause and solution |
|---|---|
| Commissions stay "pending" forever | Scheduled tasks do not run. Set up a system cron (section 1); `wp wpac cron hourly` releases due commissions immediately. |
| A sale has no commission | Check the order box "Affiliate Captain" on the order: attribution, the reason for "not attributed" (no referral, unknown/expired code, partner not active, self-referral, renewals), review flags and a running retry; refused sales also leave an order note. Failed bookings are in Integrations → Retry queue (retry there or `wp wpac jobs --retry-failed`; failed sales are kept until they are retried or dismissed). Codes of paused/terminated partners or expired codes are not accepted. |
| Partner links are not counted | Full-page cache or CDN serves the page: see section 3 (JavaScript tracking, exclude `wpac-beacon`, do not strip the link parameter). |
| Application form says "nonce" or "expired" | The page was served from a cache for more than a day: exclude the application page from the cache. |
| A partner cannot be paid | The payout preview and the notice on the plugin pages name the reason (profile incomplete, tax data waiting for your approval, current terms not accepted, your VAT ID missing, VIES invalid, below minimum including VAT corrections). |
| A downloaded credit note is marked "DUPLICATE" | The original file is missing on this server (several app servers without shared storage, lost file) and could not be rebuilt identically. Put the statement folder on shared storage (section 1) and restore the original from the backup. |
| SEPA export fails with "dom" | The PHP extension `dom` is missing: ask your host, or use the transfer list (CSV). |
| "Bank details cannot be decrypted" | The encryption key changed (WordPress salts changed or `WPAC_ENCRYPTION_KEY` set/changed later). Restore the old key; otherwise partners must enter their IBAN again. |
| "Credit notes are publicly reachable" | The statement folder in uploads can be downloaded (typical on nginx). Define `WPAC_STATEMENT_DIR` outside the web root or deny access to `/wp-content/uploads/wpac-statements/`. |
| "Database update incomplete" | Run `wp wpac upgrade` and check the database user's ALTER privileges; scheduled tasks pause until the schema is complete. |
| "Some tables do not use InnoDB" | Convert them: `ALTER TABLE wp_wpac_commissions ENGINE=InnoDB;` (for each listed table). |
| "Partner accounts differ from their bookings" | Run `wp wpac verify`; do not edit plugin tables by hand. Contact support with the output before the next payout. |
| VIES shows "unavailable" | The VIES service of the member state is down; the check is repeated daily. Nothing is blocked. |
| Import: "code already in use" | Partner codes are unique across the program; choose another code or leave the column empty. |
| Partners or the operator get no e-mails | Look for "E-mail could not be sent" in the log (reason of the mailer). Set a sender address of your own domain (Settings → Program → E-mails) or use an SMTP plugin; check whether a partner unsubscribed (monthly summary/commission mails only). |
| A sale is missing although the order is paid, nothing in the retry queue | Search the log for "could not be processed and could not be queued" (database was unavailable): send the order again (change the order status or `wp wpac jobs`). |
| A job stays "queued" with error `no_handler` | The integration of that job type is not active (e.g. WooCommerce deactivated). The job is retried hourly without using up attempts; reactivate the integration. |
| A job keeps failing | `wp wpac jobs list --status=failed` shows the last error; the log has the first failure with file and line. Fix the cause, then "Retry" in Integrations → Retry queue or `wp wpac jobs --retry-failed`. |
| Scheduled tasks look stuck | `wp wpac status` shows the last hourly/daily run; see "Server cron" in section 1. |

Before contacting support, collect: `wp wpac support` (anonymized), `wp wpac health`, the Site Health info (Tools → Site Health → Info → "Affiliate Captain", contains the same anonymized data) and the relevant log and audit log entries.

## 14. Uninstalling

Deactivating keeps everything. Deleting the plugin removes settings, partners and visits only if "Delete all data when the plugin is deleted" is on – and even then keeps commission, payout and credit note records that are still within the statutory retention period. The partners table is kept with them, pseudonymised ("Deleted partner", no contact data), because the records refer to its partner numbers. If you install the plugin again, these numbers stay reserved, so a new partner never gets a former partner's number, credit notes or balance; "Delete all data" is then off. The pages created by the setup assistant (application, partner area, landing page) are moved to the trash when they still contain the plugin's block. To delete the kept records once the period has ended, upload the plugin again (do not activate it) and run `wp eval-file wp-content/plugins/affiliate-captain/tools/purge-retained.php` (folder `wp-affiliate-captain` for versions up to 0.10). On large networks delete the plugin with `wp plugin uninstall affiliate-captain` (no time limit): in a request whose time limit cannot be lifted, the uninstall stops after 80 % of `max_execution_time` (filter `wpac_uninstall_time_budget`, seconds, 0 = no limit) and records the sites it did not reach in the network option `wpac_uninstall_incomplete`. Export the program (section 10) before you delete the plugin.
