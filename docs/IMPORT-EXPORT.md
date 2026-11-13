# Import and export

Affiliate Captain → Import & export (capability `wpac_manage_affiliates`; the export needs `wpac_manage_program`), or WP-CLI.

## Partner import (CSV)

```bash
wp wpac import partners partners.csv --dry-run   # check only
wp wpac import partners partners.csv             # import
wp wpac import partners partners.csv --notify    # also send the usual e-mails
```

On the admin page the file is always checked first (dry run): every row is listed with the result or the reasons why it would be skipped. Nothing is saved until you click "Import N partners". Rows with errors are skipped, all others are imported. The admin page accepts up to 2,000 partners and 5 MB per file; WP-CLI has no limit.

### File format

- First line: column names (any order, upper/lower case and spaces/underscores/hyphens do not matter).
- Separator `;`, `,` or tab (detected from the first line). Cells with the separator or line breaks in double quotes.
- UTF-8 (with or without BOM). Files saved by Excel as "CSV" (Windows-1252) are converted on the admin page; with WP-CLI save as "CSV UTF-8".
- Unknown columns are ignored and listed.

| Column | Required | Values | Notes |
|---|---|---|---|
| `email` | yes | e-mail address | Must not belong to an existing partner or appear twice in the file. |
| `name` | no | text | Default: the part of the e-mail address before `@`. `first_name` + `last_name` are combined if there is no `name`. |
| `website` | no | URL | `https://` is added when missing. |
| `status` | no | `active` (default), `pending`, `paused`, `terminated`, `rejected`; also `approved` → active, `inactive` → paused | Status changes are logged with the reason "CSV import". |
| `codes` | no | one or more partner codes, separated by spaces, `,`, `;` or `\|` | At least 3 characters; letters, digits, `-` and `_`; must be unused. The first code is the main code. Active partners without codes get a random code. |
| `rate` | no | `12.5`, `12,5 %` or an amount such as `5,00 €` | Creates a partner rule for all products. Percent 0–100. An amount (currency sign, currency code or `rate_type` `fixed`) is a fixed commission per unit in the program currency. Needs `wpac_manage_program`. |
| `rate_type` | no | `percent` (default), `percentage`, `fixed`, `flat` | |
| `group` | no | name or ID of a partner group | An unknown name creates the group (needs `wpac_manage_program`); an unknown ID is an error. |
| `user_id` | no | ID of an existing WordPress user | Links the partner to this account. The account must have the same e-mail address, no editing rights and no other partner. Without `user_id`, approval links or creates the account as usual. |
| `channel` | no | text | e.g. "Instagram", "Blog". |

Example:

```csv
name;email;website;status;codes;rate;rate_type;group;user_id;channel
Anna Beispiel;anna@example.com;https://anna.example.com;active;ANNA10 ANNA-SUMMER;12.5;percent;Influencer;;Instagram
Segelblog GmbH;info@segelblog.example;segelblog.example;pending;;5.00;fixed;;;Blog
```

### E-mails

By default no e-mail is sent during the import (approval mails, new account mails and account link requests are suppressed). Partners therefore do not receive a password link: tell them to use "Lost your password?" with their e-mail address, or import with "Send the usual e-mails". Partners whose e-mail address belongs to an existing WordPress account are only linked to it when you give its `user_id` (or after the account-link e-mail, which is only sent with e-mails on).

### Moving from AffiliateWP

Export the affiliates under AffiliateWP → Tools → Export → "Export Affiliates" (CSV). The plugin recognises these columns:

| AffiliateWP column | Imported as |
|---|---|
| `email` (or `payment_email` when `email` is empty) | `email` |
| `name` or `first_name` + `last_name` | `name` |
| `status` (`active`, `inactive`, `pending`, `rejected`) | `status` (`inactive` → paused) |
| `rate` + `rate_type` (`percentage`, `flat`) | partner rule (percent / fixed per unit) |
| `user_id` | `user_id` (same e-mail address required) |
| `username`, `earnings`, `referrals`, `visits`, `date_registered`, … | ignored |

AffiliateWP links use `?ref=<affiliate ID or username>`. To keep old links working, set Settings → Tracking → Link parameter to `ref` and add a `codes` column with the username (codes need at least 3 characters, so numeric IDs below 100 cannot be kept). Earnings, referrals and payouts are not imported: settle open AffiliateWP payouts before switching and keep the AffiliateWP records for your bookkeeping.

### Moving from SliceWP

Export the affiliates under SliceWP → Affiliates (export/CSV). Recognised: `email`/`payment_email`, `first_name`/`last_name`, `website`, `status` (`active`, `inactive`, `pending`, `rejected`), `user_id`. SliceWP commission rates per affiliate are stored in affiliate meta; add them as `rate`/`rate_type` columns if you used individual rates. SliceWP links use `?aff=<ID or slug>`: set the link parameter to `aff` and import the slugs as `codes` to keep them working.

### Solid Affiliate, spreadsheets, other plugins

Any CSV with an e-mail column works. Rename the columns to the names above (or one of the aliases: `e-mail`, `email address`, `partner`, `full name`, `url`, `partner code`, `referral code`, `commission rate`, `commission type`, `partner group`, `wp user id`).

## Program export

Affiliate Captain → Import & export → Export program, or

```bash
wp wpac export /secure/backup/affiliate-program.zip
wp wpac export /secure/backup/with-bank-data.zip --include-tax-data --user=admin
```

The ZIP contains one CSV file per list (UTF-8 with BOM, separator `;`, all database columns, amounts in minor units in the `*_minor` columns, dates in UTC) and a README:

| File | Content |
|---|---|
| `partners.csv` | partners incl. status, group, application data |
| `groups.csv` | partner groups |
| `codes.csv` | partner codes, coupons, limits, usage |
| `rules.csv`, `tiers.csv` | commission rules and monthly tiers |
| `agreements.csv` | accepted terms (version, hash, time) |
| `commissions.csv` | the commission ledger incl. corrections |
| `payouts.csv`, `payout_items.csv` | payouts, credit note numbers, VAT, status; which commission is in which payout |
| `payout_requests.csv` | payout requests of partners |
| `payout_details.csv` | **only with tax data:** payout details of partners with the **decrypted IBAN** |

Without "include tax and bank data" the tax snapshot of payouts (`profile_snapshot`: legal name, address, VAT ID) is emptied and `payout_details.csv` is missing. Tax and bank data can only be exported by users with `wpac_view_tax_data`, and only when explicitly requested; every export is written to the audit log. Cells starting with `=`, `+`, `-` or `@` are prefixed with `'` so spreadsheets do not run them as formulas.

The export contains personal data of your partners: store it encrypted, do not send it by e-mail, delete it when you no longer need it. Credit note PDFs are not part of the ZIP – back up the statement folder (`WPAC_STATEMENT_DIR`) separately.
