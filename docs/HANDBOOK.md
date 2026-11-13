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

**Partner links** look like `https://example.com/?partner=CODE` (parameter configurable) or `https://example.com/partner/slug/` (landing page). The visit is recorded without IP address; the code is kept in the WooCommerce session and – only with marketing consent – in the cookie `wpac_ref`. Consent is read from the WP Consent API or the filter `wpac_has_marketing_consent`.

**Page caches.** Pages opened with the link parameter are sent with `DONOTCACHEPAGE` and no-cache headers, which WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache and most hosting caches respect. Check that your cache does **not** ignore query strings for the link parameter (some caches strip unknown parameters such as `partner`).

**CDNs and edge caches** (Cloudflare APO, Varnish, Fastly) may serve the cached page anyway. Then enable Settings → Tracking → "Also record partner links via JavaScript": a small inline script with the handle **`wpac-beacon`** reports the link to `POST /wp-json/wpac/v1/touch`. In optimisation plugins exclude `wpac-beacon` from "Delay JavaScript", "Combine/minify inline JavaScript" and lazy-loading of scripts, and make sure `/wp-json/wpac/v1/touch` is not cached or blocked by a firewall rule. A visit is never counted twice.

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

**Language.** Credit notes are always written in the site language (frozen with the document, whoever creates it). For languages the PDF fonts cannot show (Cyrillic, Greek, Arabic, Asian scripts) English is used (filter `wpac_statement_locale`). Names and addresses with characters outside Western European (Ł, ś, ř, ő …) are transliterated (Ł → L); the original spelling is kept in the document properties and listed in a note on the document. A Unicode font is not embedded (it would make every PDF several hundred KB larger).

**Sending.** Every credit note, correction document and cancellation is e-mailed to the partner with the PDF attached when it is issued (a credit note takes effect once the partner received it; filter `wpac_notify_credit_note`). A cancellation document that could not be written is retried hourly.

**VIES.** EU VAT IDs of partners are checked with the VIES service of the European Commission (in the background after saving, daily for older results of active partners; your VAT ID is sent along for a qualified confirmation with consultation number). An invalid result (also for a domestic VAT ID, and for a malformed number) blocks credit notes with VAT or reverse charge; "unavailable" blocks nothing and is retried. If VIES refuses your own VAT ID, the check runs without it and the partner page says so. The check can be switched off in Settings → Payouts (results then no longer block); timeout and daily limit can be changed with the filters `wpac_vies_timeout` and `wpac_vies_daily_cap`.

**Retention.** Credit notes, payout data and the accepted terms are kept for the statutory period (at least 10 years for the PDFs; DE 8, AT 7, CH 10 years for booking records, counted from the end of the year) and deleted afterwards. "Tax audit / legal hold" stops all deletion. Objections of partners against a credit note are listed under Payouts.

## 7. Fraud and abuse

Settings → Fraud & abuse: maximum orders per partner in 24 hours, maximum order value, minimum seconds between click and order, maximum refund rate, disposable or blocked e-mail domains. Hits put the commission into *review* with the reason; approve or reject it in Commissions. Self-referrals are blocked (or flagged). Partner code checks, the application form and the touch endpoint are rate limited per client. Behind Cloudflare or a reverse proxy, enter its IP ranges under "trusted proxies", otherwise all visitors share one rate limit.

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

Before contacting support, collect: `wp wpac status`, `wp wpac health`, the Site Health info (Tools → Site Health → Info → "Affiliate Captain") and the relevant audit log entries.

## 14. Uninstalling

Deactivating keeps everything. Deleting the plugin removes settings, partners and visits only if "Delete all data when the plugin is deleted" is on – and even then keeps commission, payout and credit note records that are still within the statutory retention period. To delete them once the period has ended, upload the plugin again (do not activate it) and run `wp eval-file wp-content/plugins/wp-affiliate-captain/tools/purge-retained.php`. Export the program (section 10) before you delete the plugin.
