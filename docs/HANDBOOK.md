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

Set both **before** the first partner enters bank details and the first credit note is written. Without `WPAC_ENCRYPTION_KEY` the key is derived from the WordPress salts – changing the salts later makes stored IBANs unreadable. Keep a copy of the key in your password manager: without it, bank details cannot be decrypted after a server move.

**Setup checklist.** After activation, Affiliate Captain → Dashboard shows a checklist. "Create pages automatically" adds the application form, the partner area and a landing page with the matching blocks. Then go through Settings:

| Tab | What to set |
|---|---|
| Program | application and partner area pages, terms page and version, automatic approval, notification e-mail |
| Tracking | link parameter (default `partner`), checkout field, shop session, cookie mode, attribution (first/last touch), JavaScript tracking for caches |
| Fraud & abuse | fraud rules, rate limit, trusted proxies (Cloudflare/reverse proxy IP ranges), retention of visits and rejected applications |
| Commissions | program currency, exchange rates for reports, default rate, hold period, lifetime commissions, subscription renewals |
| Promotion | landing page path, disclosure note, promotion guidelines |
| Payouts & credit notes | minimum payout, period, payout requests, statement retention, self-billing, VAT rate, your country, address, VAT ID/tax number, number prefixes, company bank account for SEPA, VIES check |
| Reports & data | monthly summary mails, audit log retention, maintenance mode, monitoring token, data deletion on uninstall, record of processing activities |

**Server cron.** Commissions are released, retries run and old data is deleted by scheduled tasks (hourly and daily). On sites with few visits or with `DISABLE_WP_CRON`, set up a system cron:

```
*/5 * * * * cd /path/to/wordpress && wp cron event run --due-now --quiet
```

## 2. Partners

**Applications.** The application form (block "Partner application form" or `[wpac_affiliate_registration]`) asks for name, e-mail, website and channel, plus the fields of the chosen partner group. Applicants confirm their e-mail address (double opt-in) and accept the terms; the version, time and accepted text are stored. Applications appear under Partners with the status "Application pending" unless automatic approval is on.

**Approval.** On approval the partner gets a WordPress account (or is linked to the existing one after confirming a link sent by e-mail – accounts with editing rights are never linked automatically) and a random partner code. Pause, termination and rejection need a reason, which is shown to the partner.

**Partner groups** (Rules & groups) have their own terms page and version, their own application fields and their own commission rules – for example "Influencer" and "Schools".

**Partner codes.** Every partner has at least one code (random by default, a custom code is possible). Codes can have a usage limit and an expiry date, be linked to a WooCommerce coupon, or be created in batches (with CSV export) for print campaigns.

**Partner area** (block "Partner area" or `[wpac_affiliate_portal]`): partner link with campaign builder, codes, QR code, creatives, statistics, commissions (without customer data), payouts and credit notes, payout details (bank and tax data), account and privacy requests.

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

An exclusion always wins; otherwise the most specific rule (partner > group > global, then product/SKU > category/tag > all, then priority) applies. The rate, base and rule are stored with every commission: changing a rule never changes past commissions. The rule preview shows which rule applies to a product.

**Lifecycle.** A commission starts as *pending* and is released (*approved*) after the hold period (Settings → Commissions; integrations can extend it, e.g. until a course has taken place). Refunds create negative correction lines, proportional per item; a cancellation or chargeback reverses the rest. Each event is booked once only, even if it is reported several times. Corrections after a payout are deducted from the next payout. *Review* marks commissions held by a fraud rule.

**Monthly tiers** add a bonus in percent of the monthly net revenue (or order count), either marginal (only above the threshold) or retroactive. **Lifetime commissions** give repeat purchases of a referred customer to the same partner. **Subscription renewals** (WooCommerce Subscriptions) are commissioned by default.

Every partner has an append-only, hash-chained account per currency (partner page → account statement). `wp wpac verify` and a daily check compare the accounts with the bookings.

## 5. Payouts: SEPA and manual transfers

1. **Payout details.** Partners enter name, address, country, tax status (business, small business, private), VAT ID or tax number and bank details in the partner area (administrators with `wpac_manage_payouts` can enter them on the partner page). IBANs are stored encrypted and shown masked.
2. **Payout run** (Payouts): choose the period end; the preview lists every partner with an approved balance and why someone cannot be paid (profile incomplete, no accepted terms, missing operator VAT ID for reverse charge, VAT ID invalid according to VIES, below the minimum, maintenance mode). Balances below the minimum and negative balances are carried forward.
3. **Credit note / statement.** Each payout gets a PDF with a gapless number per year (`GS-2026-000001`), stored in the statement folder and protected by a hash.
4. **Transfer.**
   - **SEPA (EUR):** select the payouts and download the SEPA XML file (pain.001.001.03) for your online banking. Needs the company account under Settings → Payouts & credit notes and the PHP extension `dom`.
   - **Manual transfer (any currency):** download the transfer list (CSV) and pay the amounts manually or by importing the list into your banking software.
   A payout can only be exported once; "export again" is only for files the bank did not process.
5. **Mark as paid** after the bank executed the transfer. Partners get an e-mail and see the date.

**Cancelling** a payout (before or after export, with a reason) releases its commissions for the next run and, for credit notes, creates a cancellation document (`ST-…`). **Payout requests:** if enabled, partners request the payout of their approved balance; you create the payout from the request or reject it with a reason.

**Negative balances** of ended partners (clawbacks without new earnings) can be written off with a reason or claimed back with up to three payment requests; repayments are booked on the partner page.

## 6. Credit notes and VAT

With **self-billing** (Settings → Payouts & credit notes, on by default) the plugin issues credit notes in the name of the partner (Gutschrift, §14 Abs. 2 UStG). This needs a prior agreement – put it into your partner terms; payouts are blocked for partners who have not accepted terms.

| Partner | VAT on the credit note |
|---|---|
| business in your country | your VAT rate is added (payout = gross) |
| business in another EU country | reverse charge, no VAT; partner VAT ID required and checked with VIES |
| small business (e.g. §19 UStG) | no VAT, note on the credit note |
| private person | no VAT |

Commission rates are net amounts. Legal references on the documents follow your country (DE, AT, CH; other countries get the German wording). For reverse charge your own VAT ID is required. Outside DACH or if self-billing is not agreed, switch self-billing off – the plugin then creates payout statements and partners send you invoices. The treatment can be adjusted with the filter `wpac_vat_treatment`.

**VIES.** EU VAT IDs of partners are checked with the VIES service of the European Commission (in the background after saving, daily for older results; your VAT ID is sent along for a qualified confirmation with consultation number). An invalid result blocks reverse-charge payouts; "unavailable" blocks nothing and is retried. The check can be switched off in Settings → Payouts.

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
- Settings → Privacy → Policy Guide contains a suggested text based on your settings (including the VIES recipient); Settings → Reports & data offers a record of processing activities.
- External services: only the VIES check (European Commission) and a loopback request to your own site, see readme.txt → External services.

## 9. Multisite

- **Network activation** sets up every site in batches (large networks finish in the background); new sites are set up automatically.
- Every site has its own tables, settings, partners, payouts and credit note numbers. Partners of one site are not partners of another.
- Deleting a site drops its tables. Deactivating or deleting the plugin works per site.
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
| `wp wpac status` | schema version, queue, next cron runs, retention |
| `wp wpac health` | the health checks (same as Site Health) |
| `wp wpac upgrade` | run the database migration now |
| `wp wpac jobs [--retry-failed] [--limit=<n>]` | work through the retry queue |
| `wp wpac cron hourly\|daily` | run the maintenance now |
| `wp wpac verify` | check every partner account against its bookings |
| `wp wpac maintenance on\|off` | maintenance mode |
| `wp wpac import partners <file.csv> [--dry-run] [--notify]` | import partners |
| `wp wpac export <file.zip> [--include-tax-data --user=<login>]` | export the program |

## 12. Monitoring

- **Site Health** (Tools → Site Health) shows the checks: schema, scheduled tasks, failed sales/refunds, account consistency, unwritten credit notes, publicly reachable statement folder, own encryption key, maintenance mode.
- **Alarm mail:** a daily check e-mails the notification address when a critical check fails.
- **HTTP endpoint** for uptime monitors: set a monitoring token (at least 20 characters) under Settings → Reports & data, then call

  ```
  curl -fsS -H 'X-WPAC-Monitor-Token: <token>' https://example.com/wp-json/wpac/v1/health
  ```

  `200` = OK, `503` = a critical check failed (the body names the failing checks, no details).
- **Logs:** errors go to the WooCommerce logger (WooCommerce → Status → Logs, source `affiliate-captain`) or the PHP error log; every administrative change is in the audit log.

## 13. Troubleshooting

| Symptom | Cause and solution |
|---|---|
| Commissions stay "pending" forever | Scheduled tasks do not run. Set up a system cron (section 1); `wp wpac cron hourly` releases due commissions immediately. |
| A sale has no commission | Check the order box "Affiliate Captain" on the order: attribution and reason. Failed bookings are in Integrations → Retry queue (retry there or `wp wpac jobs --retry-failed`). Codes of paused/terminated partners or expired codes are not accepted. |
| Partner links are not counted | Full-page cache or CDN serves the page: see section 3 (JavaScript tracking, exclude `wpac-beacon`, do not strip the link parameter). |
| Application form says "nonce" or "expired" | The page was served from a cache for more than a day: exclude the application page from the cache. |
| A partner cannot be paid | The payout preview and the notice on the plugin pages name the reason (profile incomplete, no accepted terms, your VAT ID missing, VIES invalid, below minimum). |
| SEPA export fails with "dom" | The PHP extension `dom` is missing: ask your host, or use the transfer list (CSV). |
| "Bank details cannot be decrypted" | The encryption key changed (WordPress salts changed or `WPAC_ENCRYPTION_KEY` set/changed later). Restore the old key; otherwise partners must enter their IBAN again. |
| "Credit notes are publicly reachable" | The statement folder in uploads can be downloaded (typical on nginx). Define `WPAC_STATEMENT_DIR` outside the web root or deny access to `/wp-content/uploads/wpac-statements/`. |
| "Database update incomplete" | Run `wp wpac upgrade` and check the database user's ALTER privileges; scheduled tasks pause until the schema is complete. |
| "Some tables do not use InnoDB" | Convert them: `ALTER TABLE wp_wpac_commissions ENGINE=InnoDB;` (for each listed table). |
| "Partner accounts differ from their bookings" | Run `wp wpac verify`; do not edit plugin tables by hand. Contact support with the output before the next payout. |
| VIES shows "unavailable" | The VIES service of the member state is down; the check is repeated daily. Nothing is blocked. |
| Import: "code already in use" | Partner codes are unique across the program; choose another code or leave the column empty. |

Before contacting support, collect: `wp wpac status`, `wp wpac health`, the Site Health info (Tools → Site Health → Info → "Affiliate Captain") and the relevant audit log entries.

## 14. Uninstalling

Deactivating keeps everything. Deleting the plugin removes settings, partners and visits only if "Delete all data when the plugin is deleted" is on – and even then keeps commission, payout and credit note records that are still within the statutory retention period. To delete them once the period has ended, upload the plugin again (do not activate it) and run `wp eval-file wp-content/plugins/wp-affiliate-captain/tools/purge-retained.php`. Export the program (section 10) before you delete the plugin.
