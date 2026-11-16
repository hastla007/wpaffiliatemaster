# Record of processing activities – template (Art. 30 GDPR)

Template for the entries that concern Affiliate Captain. The download in **Affiliate Captain → Settings → Data & maintenance** fills the values in `{curly braces}` with your current settings (German sites receive the German version, `docs/VERARBEITUNGSVERZEICHNIS.md`). Review and complete the template with your data protection advisor; it is not legal advice.

**Controller:** {operator_name}, {operator_address}
**As of:** {date}

---

## 1. Attribution of orders to partners (affiliate tracking)

| | |
|---|---|
| Purpose | Attribute orders to the referring partner so that the partner receives the commission; detect abuse |
| Data subjects | Website visitors and customers who arrive through a partner link or code |
| Data | Partner code, campaign, landing page, time, host of the previous page (only with consent), order number, product name and net amount; **no IP address**; for later purchases a pseudonymised hash (HMAC with a secret key – not encryption, no plain-text address) of the customer account/e-mail; optionally the partner code in the WooCommerce session (only when a cart already exists, until checkout) and – with JavaScript tracking for page caches switched on – the same details sent by a small script (beacon) instead of the page request |
| Legal basis | Art. 6(1)(f) GDPR (attribution without cookie, customer link for later purchases); Art. 6(1)(a) GDPR, § 25(1) TDDDG / national ePrivacy law (affiliate cookie `wpac_ref`, reading the previous page – cookie mode "consent"); cookie mode "always": to be assessed by the controller (§ 25(2) no. 2 TDDDG only if strictly necessary, usually not the case); partner code in the WooCommerce shop session: Art. 6(1)(f) GDPR |
| Objection | Customers can object to the attribution and to the customer link (Art. 21 GDPR; note in the privacy policy text). How: remove the lifetime binding in the "Affiliate Captain" box of the order, or `wp wpac customer unlink` |
| Recipients | Partners see for their own orders the order number, date, product name, base amount, commission, status, campaign and sub ID (partner area, CSV, REST) – no customer names or contact details. Product names can allow conclusions (e.g. health products); check this for your range |
| Erasure periods | Visits {visit_retention_days} days; cookie {cookie_days} days; customer link {lifetime_days}; attributions (order number, partner, campaign, sub ID, visit ID) as accounting records {retention_years} years from the end of the calendar year of the last booking of the order, then deleted daily (not during a tax audit or legal hold); afterwards the commission rows of the order are pseudonymised (order number and idempotency key → hash, product, description and reasons of status changes deleted; partner, amounts and dates stay for the partner account); referral details on the order (`_wpac_touch`, entered partner code `_wpac_partner_code`, manual attribution `_wpac_manual_affiliate`) are removed on the customer's erasure request once the order is attributed or can no longer be attributed; on unpaid, unattributed orders they stay (reported as retained) |

## 2. Partner program (application, partner account, communication)

| | |
|---|---|
| Purpose | Review applications, keep partner accounts, inform partners (approval, monthly summary, security notices) |
| Data subjects | Applicants and partners |
| Data | Name/company, e-mail, website, channel, application details, partner codes, accepted terms (version, time, text) |
| Legal basis | Art. 6(1)(b) GDPR (contract / pre-contractual measures) |
| Recipients | none; processors: hosting, e-mail delivery if used |
| Erasure periods | unconfirmed applications (e-mail not confirmed) 2 days; applications never decided {pending_application_days} days; rejected applications {application_retention_days} days; partner data after the end of the contract unless a retention obligation applies; proof of accepted terms as long as the credit notes |

## 3. Commissions, credit notes and payouts

| | |
|---|---|
| Purpose | Calculate commissions, issue credit notes (self-billing), pay out, book |
| Data subjects | Partners |
| Data | legal name, address, country, tax status, VAT ID/tax number, account holder, IBAN (encrypted), BIC, for "other payment method" the payment details (e.g. PayPal address, encrypted), commissions, payouts, credit note PDFs, VIES check result |
| Legal basis | Art. 6(1)(b) GDPR; Art. 6(1)(c) GDPR (§ 147 AO, § 14b UStG / § 132 BAO / Art. 958f OR or your national bookkeeping law) |
| Recipients | Bank (SEPA transfer), {payment_providers}tax advisor, tax authorities; European Commission (VIES check of the VAT ID) |
| Erasure periods | {retention_years} years from the end of the calendar year; no deletion during a tax audit or legal hold. After an erasure request processing is restricted until then. Commission rows are then not deleted but pseudonymised once nothing is open (no order number, no product, no free texts; amounts stay for balance and reports) |

## 4. Change log (audit log)

| | |
|---|---|
| Purpose | Traceability of changes to commissions, payouts, bank details and settings |
| Data subjects | Partners, staff with access to the administration |
| Data | Action, object, user ID, time, details (e.g. reasons) |
| Legal basis | Art. 6(1)(c) and (f) GDPR |
| Erasure periods | entries relevant for bookkeeping {retention_years} years; others {audit_retention_days} days |

## Technical and organisational measures (excerpt, Art. 32 GDPR)

- IBAN stored encrypted (own key `WPAC_ENCRYPTION_KEY`), only masked in lists
- Roles and permissions: managing partners, managing payouts and viewing tax details are separate
- Signed cookies, nonces for all forms
- Rate limiting with the table `rate_limits`: only an HMAC hash of the (shortened) IP address and browser identifier, no plain-text IP; entries expire after minutes to hours and are deleted daily
- JavaScript tracking (optional, for page caches) only sends what the page request contains; the host of the previous page only with consent (checked again on the server)
- Credit note PDFs outside the public web directory or protected, download only for the partner
- Changes of bank details are reported to the partner (and for 30 days also to the previous address)
- Access and erasure requests through the WordPress privacy tools
