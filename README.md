# Affiliate Captain

Eigenständiges WordPress-Plugin für ein eigenes Partnerprogramm: Partnergewinnung, Partnercodes und Referral-Links, Attribution, Provisionsledger mit Refund-Korrekturen, Auszahlungsläufe mit SEPA-Export und ein Partnerportal.

Läuft ohne WooCommerce, Voucher Captain oder Pass Captain. Integrationen sprechen ausschließlich über einen versionierten öffentlichen Vertrag (Hooks/Funktionen) – nie über Tabellen anderer Plugins. Siehe [docs/CONTRACT.md](docs/CONTRACT.md).

> Status: **0.11.0** (0.10.0 live auf WordPress 7.1.4 und WooCommerce 11.3.0 geprüft). Ledger und Auszahlungen sind per Integrationstests gegen MariaDB 10.11 und MySQL 8.4 geprüft; Unit-, Integrations-, Browser-, Pluginverbund-, Multisite-, Upgrade- und Übersetzungsprüfungen sowie PHPCS, PHPStan und composer audit laufen in CI. Rechts-, Steuer- und Datenschutzfragen (Cookie-Consent, Belegpflichten, Umsatzsteuer) sind weiterhin fachlich zu prüfen.

## Anforderungen

- WordPress 6.3+ (Blöcke mit `apiVersion: 3`), PHP 7.4+ (mit `sodium`, `dom`; `zip` für den Programm-Export)
- Optional: WooCommerce 8.0+ (HPOS und Classic-/Blocks-Checkout; Partnercode-Feld im Blocks-Checkout ab 8.9), geprüft bis 11.3
- Die Mindestversionen (WordPress 6.3, WooCommerce 8.0, PHP 7.4) prüft der CI-Job „Minimum versions“ (Aktivierung, Bestellung + Erstattung, REST, alle Admin-Seiten).

Betreiber-Handbuch (englisch): [docs/HANDBOOK.md](docs/HANDBOOK.md). Import/Export: [docs/IMPORT-EXPORT.md](docs/IMPORT-EXPORT.md). Release und WordPress.org: [docs/RELEASE.md](docs/RELEASE.md); Free/Premium-Entscheidung: [docs/FREE-PREMIUM.md](docs/FREE-PREMIUM.md).

Kostenlos und vollständig: Das Plugin enthält keine Lizenzprüfung und keine gesperrten Funktionen (für WordPress.org). Premiumfunktionen kommen als **eigenes Add-on**, das sich über die Erweiterungspunkte in [docs/CONTRACT.md](docs/CONTRACT.md) einklinkt; Hintergrund in [docs/FREE-PREMIUM.md](docs/FREE-PREMIUM.md).

## Installation

1. Ordner nach `wp-content/plugins/affiliate-captain/` kopieren und aktivieren.
2. Seiten anlegen: `[wpac_affiliate_registration]` (Bewerbung), `[wpac_affiliate_portal]` (Partnerbereich), optional eine Landingpage mit `[wpac_partner_welcome]`.
3. Im **Dashboard** führt eine Einrichtungs-Checkliste durch die Schritte; „Seiten automatisch anlegen“ erstellt Bewerbung, Partnerbereich und Landingpage mit den passenden Blöcken. Unter **Affiliate Captain → Einstellungen** Seiten, Teilnahmebedingungen (Seite + Version), Standardprovision, Haltefrist, Mindestbetrag und das Firmenkonto für SEPA setzen.
4. Für Gutschriften unter **Einstellungen → Auszahlungen** Anschrift, USt-IdNr./Steuernummer und USt-Satz hinterlegen. Das Gutschriftverfahren in den Partnerbedingungen vereinbaren.
5. Empfohlen in `wp-config.php` – Belege außerhalb des Webroots (auf nginx Pflicht, dort wirkt `.htaccess` nicht):
   ```php
   define( 'WPAC_STATEMENT_DIR', '/var/www/private/wpac-statements' );
   ```
6. Hinter Cloudflare oder einem Reverse-Proxy dessen IP-Bereiche unter **Einstellungen → Missbrauchsschutz** eintragen.
7. Empfohlen in `wp-config.php`: eigener Schlüssel für die IBAN-Verschlüsselung (64 Hex-Zeichen, z. B. `php -r 'echo bin2hex(random_bytes(32));'`):
   ```php
   define( 'WPAC_ENCRYPTION_KEY', '…' );
   ```
   Ohne diesen Schlüssel wird er aus den WordPress-Salts abgeleitet – ein Salt-Wechsel macht gespeicherte Bankdaten dann unlesbar.
8. Seiten-Caches: Seiten mit Bewerbungsformular oder Partner-Begrüßung (Shortcode oder Block im Seiteninhalt) und Links mit Partnerparameter werden vor der Ausgabe als nicht cachebar markiert (`DONOTCACHEPAGE` + `Cache-Control: no-cache`; mit „Partnerlinks per JavaScript erfassen“ bleiben Partnerlink-Seiten für Gäste cachebar, der Beacon erfasst sie). Steht ein Formular oder die Begrüßung nur in einem Widget/Theme-Template oder ignoriert der Cache/CDN diese Header, die Seiten im Cache-Plugin bzw. CDN ausschließen. „JavaScript verzögern/kombinieren“: das Skript-Handle `wpac-beacon` (Partnerlinks per JavaScript) vom Verzögern ausnehmen; das Skript ist auf allen Seiten gleich, seitenbezogene Werte stehen in `<script type="application/json" id="wpac-beacon-data">`.
9. Mehrsprachig (WPML/Polylang): Landingpage-, Partnerbereich- und Bewerbungslinks werden auf die Übersetzung der Seite umgestellt; E-Mails an Partner gehen in der Sprache ihres WordPress-Kontos, sonst in der Website-Sprache (mit WPML/Polylang die Standardsprache, nicht die Sprache der gerade aufgerufenen Seite); Gutschriften ebenso in der Standardsprache.

## Ablauf

**Partner → Link/Code → Auftrag → Provision → Refund-Korrektur → Auszahlung**

| Schritt | Umsetzung |
|---|---|
| Bewerbung | Formular mit Website/Kanal, Double-Opt-in per E-Mail (keine Auskunft, ob eine Adresse schon existiert), Honeypot + Rate-Limit, Annahme der Bedingungen mit Version + SHA-256 der Bedingungsseite. Freigabe/Ablehnung/Pause/Beendigung mit Grund im Admin. Bei Freigabe wird ein WP-Konto verknüpft (nie Konten mit Redakteurs-/Admin-Rechten) oder angelegt und ein zufälliger Partnercode erzeugt (Wunschcode optional). |
| Partnergruppen | z. B. „Ausbildungspartner“ / „Content-Partner“ mit eigenen Provisionsregeln. |
| Partnercode (cookie-frei) | Feld an der Kasse (Classic + Blocks), Übergabe durch Apps/Integrationen, optional mit WooCommerce-/Voucher-Coupon verknüpft (Rabatt bleibt Sache des Coupons). Limits, Ablaufdatum, Sperre, Massencode-Batches mit CSV-Export. |
| Referral-Link | `?partner=CODE` (Parameter konfigurierbar, Vanity-Slug möglich). Besuch wird minimiert erfasst (keine IP, Referrer-Host nur mit Einwilligung, UTM-Kampagne, Content-ID; Stichprobe/aus per Einstellung). Code bleibt in einer bestehenden WooCommerce-Shop-Session (Warenkorb); ein Affiliate-Cookie nur nach Marketing-Einwilligung (WP Consent API oder Filter `wpac_has_marketing_consent`) – auch wenn die Einwilligung erst nach der Landung erteilt wird (Consent-Listener), erst auf einer späteren Seite (bis zur Einwilligung, höchstens 30 Minuten, tragen die shopeigenen Links einen signierten URL-Parameter weiter – ohne Speicherung im Browser) oder beim ersten Warenkorb. Cookie-Domain für Landing und Shop auf verschiedenen Subdomains einstellbar. |
| Attribution | Reihenfolge: manuell (mit Grund) → eingegebener Code → Affiliate-Coupon (falls priorisiert) → Link/Cookie (First/Last Touch) → Coupon. Self-Referral (WP-Konto, Bestellkonto, normalisierte E-Mail) wird gesperrt oder zur Prüfung markiert. |
| Provision | Je Bestellposition auf Netto nach Rabatt ohne Steuer/Versand. Regeln: global / Gruppe / Partner × alle / Produkt / Kategorie / SKU-Kurscode (mit `*`), Prozent, Fixbetrag pro Stück oder Ausschluss. Ausschluss gewinnt immer, sonst die spezifischste Regel. Rate, Basis und Regel-Snapshot werden gespeichert – spätere Regeländerungen ändern keine Historie. Regelvorschau im Admin. |
| Haltefrist | `hold_until` je Auftrag = Zahlung + Haltetage, verlängerbar durch Integrationen (Garantie-/Widerrufsende). Danach automatische Freigabe (stündlicher Cron). |
| Refunds | Teilrefunds positionsgenau anteilig, Storno/Chargeback als Restumkehr. Korrekturen sind eigene negative Ledger-Zeilen; wiederholte Ereignisse buchen nie doppelt (Idempotency-Key, Obergrenze = ursprüngliche Provision). Nach Auszahlung wird die Korrektur im nächsten Lauf verrechnet. |
| Auszahlung | Lauf mit Periodenende + Mindestbetrag; negative Salden/Beträge unter Minimum werden vorgetragen. Jede Provision kann nur einmal in einer Auszahlung stehen (UNIQUE). Abrechnungsbeleg (druckbar) mit Steuerstatus-Snapshot, SEPA-XML (pain.001.001.03), „bezahlt“ markieren, Stornieren gibt Provisionen frei. |
| Partnerportal | Kennzahlen, Link-Generator mit Kampagne, Codes, Provisionen (nur Bestellreferenz, Datum, Produkt, Betrag, Status – keine Kundendaten), Auszahlungen, Steuer-/Bankdaten (IBAN verschlüsselt, maskiert angezeigt), erneute Annahme geänderter Bedingungen. |
| Monatsstufen | Zusatzbonus in % des Monats-Nettoumsatzes (nach Refunds), Basis Umsatz oder Anzahl Bestellungen, **marginal** (nur Anteil über der Schwelle) oder **rückwirkend** (ganzer Monat). Spezifischster Plan gilt (Partner > Gruppe > global). Abschluss automatisch nach Ablauf der Haltefrist, Plan wird im Bonus eingefroren; manueller Abschluss möglich. Spätere Rückerstattungen, Ablehnungen oder Freigaben werden täglich (letzte 3 Monate) als Bonus-Korrektur verbucht; gezählt werden nur freigegebene/bezahlte Provisionen. |
| Lifetime | Optional: Folgekäufe eines geworbenen Kunden gehen an denselben Partner (Dauer und eigener Satz einstellbar), nur wenn kein neuer Link/Code/Coupon genutzt wird. Bindung nur durch Bestellungen mit Kundenkonto und Provision (nie durch Gastbestellungen), endet mit Kündigung des Partners oder Abschalten der Funktion; im Bestell-Feld bzw. per `wp wpac customer-link` einseh- und lösbar. Kunden nur als HMAC-Hash gespeichert, Löschung über den WordPress-Datenschutz-Eraser. |
| Tags | Provisionsregeln auch nach Produkt-Tags. |
| Partnergruppen | Eigene Bedingungsseite + Version je Gruppe, Gruppe im Antrag wählbar oder per `[wpac_affiliate_registration group="ID"]` fest (private Gruppen nur aus dem Inhalt einer veröffentlichten Seite, die ein Partnermanager gespeichert hat; inaktive/gelöschte Gruppen zeigen einen Hinweis statt des Formulars), zusätzliche Antragsfelder je Gruppe (`key|Label|Typ|required`). |
| Benachrichtigungen | E-Mails bei Antragseingang, Freigabe, Ablehnung, Pause, Beendigung, Reaktivierung, Auszahlungsantrag. |
| Werbemittel | Texte/Bilder versioniert: jede Änderung = neuer Entwurf, nur freigegebene Versionen sichtbar (je Gruppe), Kennzeichnungshinweis Pflicht, Platzhalter `{link}`, `{code}`, `{partner}`, Werberichtlinien im Portal. |
| QR & Landingpages | QR-Code (SVG, eigener Encoder ohne externe Dienste) im Portal. Landing-URL `/partner/{slug}/` leitet mit Partnercode auf die Partner- oder Standard-Landingpage; `[wpac_partner_welcome]` zeigt „Empfohlen von …“ und die vom Betreiber gepflegte Begrüßung. |
| Auszahlungsanträge | Optional: Partner beantragen die Auszahlung ihres freigegebenen Saldos (Mindestbetrag, vollständiges Profil); Admin erzeugt daraus die Auszahlung oder lehnt mit Grund ab. |
| PDF-Beleg | Bei jeder Auszahlung wird einmalig ein PDF-Abrechnungsbeleg erzeugt, geschützt abgelegt (`uploads/wpac-statements`), per SHA-256 gegen Veränderung geprüft; Download im Admin (`wpac_view_tax_data`) und für den Partner im Portal. |
| Gutschrift | Beleg als Gutschrift (§ 14 Abs. 2 UStG) mit allen Pflichtangaben: lückenlose Nummer pro Jahr (`GS-2026-000001`), Leistung und Zeitraum, Netto, USt-Satz und -Betrag. Provisionssätze sind netto; regelbesteuerte Partner im Inland erhalten USt obendrauf (Auszahlung = brutto), ausländische Unternehmen Reverse-Charge, Kleinunternehmer/Privatpersonen keine USt. Storno einer Auszahlung erzeugt eine Storno-Gutschrift (`ST-…`) mit Verweis. Steuerliche Behandlung per Filter `wpac_vat_treatment` anpassbar – mit der Steuerberatung abstimmen. |
| Partnerkonto | Unveränderliches Saldo-Ledger je Partner und Währung: jede Freigabe, Ablehnung, Auszahlung und Auszahlungs-Storno ist eine Buchung mit laufendem Saldo, hash-verkettet (nachträgliche Änderungen werden erkannt), pro Konto serialisiert. Kontoauszug mit Integritätsprüfung auf der Partnerseite; Saldo = freigegebene, nicht ausgezahlte Provisionen. |
| Aufbewahrung | Belege werden nach der eingestellten Frist (Standard 10 Jahre ab Jahresende) samt Partner-Snapshot gelöscht, Beträge bleiben erhalten. Frist fachlich prüfen (z. B. §147 AO). |
| Betrieb | Retry-Warteschlange für fehlgeschlagene Integrationsereignisse, Integrationsstatus/Vertragsprüfung, Audit-Log, CSV-Exporte mit Formel-Injection-Schutz, WordPress-Datenexport/-löschung, Aufbewahrungsfrist für Besuche, eigene Capabilities (`wpac_manage_program`, `wpac_manage_affiliates`, `wpac_manage_payouts`, `wpac_view_tax_data`). |
| Berichte | Dashboard nach Partner, nach Inhalt (Beitrag/Seite/CPT, auf dem der Partnerlink landete) und nach Kampagne (je Partner getrennt, gleichnamige Kampagnen verschiedener Partner werden nicht zusammengezählt), jeweils mit CSV. Monatliche Zusammenfassung per E-Mail an Betreiber und optional an Partner. |
| Editor | Blocks „Partner-Bewerbungsformular“, „Partnerbereich“, „Partner-Begrüßung“, „Empfohlene Partnerziele“ (ohne Build-Schritt). Panel „Affiliate Captain“ an Beiträgen/Seiten/CPTs: Partner-Traffic der Seite, Deeplink-Format, „Als Partner-Ziel empfehlen“ mit Hinweis für Partner (nur Partnermanager; per REST als `_wpac_promote`/`_wpac_promote_note` setzbar). Partnerziele sehen nur angemeldete Partner. |
| Mehrwährung | Ledger, Auszahlungen und SEPA bleiben immer in der Bestellwährung. Manuell gepflegte Referenzkurse (`USD=0,92`) dienen nur Berichten und dem Mindestbetrag in Fremdwährungen; Währungen ohne Kurs werden in Berichten ausgewiesen statt still verrechnet. Festbetragsregeln gelten nur in der Programmwährung. |
| Betrugsregeln | Self-Referral, zu viele Bestellungen in 24 h, hoher Bestellwert, Bestellung direkt nach dem Klick, hohe Refund-Quote des Partners, Wegwerf-/gesperrte E-Mail-Domains, Gutschein eines anderen Partners, eigene Regeln per Filter. Treffer → Status „Prüfung nötig“ mit Grund; Zählblase am Menü „Provisionen“, Hinweis im Dashboard und optional stündliche Sammel-E-Mail an den Betreiber. |
| REST-API | `wpac/v1`, siehe [docs/REST.md](docs/REST.md). |
| Import/Export | CSV-Import von Partnern (Prüflauf mit Fehlerliste, Aliasse für AffiliateWP/SliceWP) unter **Import & Export** oder `wp wpac import partners <datei.csv> [--dry-run]`; Programm-Export als ZIP mit CSV je Liste (`wp wpac export`), Bankdaten nur mit `wpac_view_tax_data` und ausdrücklicher Auswahl. |
| Externe Dienste | Nur VIES (EU-Kommission, USt-IdNr.-Prüfung, standardmäßig aus – Opt-in in den Einstellungen) und eine Loopback-Prüfung des Belegordners – offengelegt in `readme.txt` → External services. |
| Sprache | Deutsch (`de_DE`) vollständig, inkl. Editor-Blocks. Quelltexte englisch. |

## Entwicklung

```bash
php tests/run.php
```

Integrationstests für Ledger und Auszahlungen gegen eine echte MySQL/MariaDB (die Testdatenbank wird bei jedem Lauf geleert):

```bash
WPAC_TEST_DB_NAME=wpac_test WPAC_TEST_DB_USER=wpac WPAC_TEST_DB_PASS=wpac php tests/integration/run.php
```

Release-ZIP bauen (prüft Versionen und Tests):

```bash
bin/build-zip.sh
```

Übersetzungen: Texte in `languages/de_DE.json` pflegen, dann:

```bash
python3 bin/i18n.py pot && python3 bin/i18n.py build de_DE
```

CI (GitHub Actions) prüft PHP 7.4–8.4, Unit- und Integrationstests (inkl. REST-`permission_callback`s), Coverage-Schwelle (`WPAC_COVERAGE_MIN`, Standard 70 %), QR-Dekodierung, Übersetzung, Browser-/Pluginverbund-/Smoke-Tests, Mindestversionen (WordPress 6.3, WooCommerce 8.0, PHP 7.4) und das offizielle Plugin Check auf dem gebauten ZIP (bekannte Fehlalarme mit Begründung in `tests/ci/plugin-check-ignore.txt`); ein Tag `v*` erzeugt ein GitHub-Release mit ZIP. Was ins ZIP und nach WordPress.org kommt, steuert `.distignore`; der SVN-Deploy ist ein manueller Workflow (`docs/RELEASE.md`).

Optional (braucht `zbarimg` und Pillow): QR-Codes wirklich dekodieren:

```bash
python3 tests/qr_decode_check.py
```

```bash
composer lint
```

Struktur: `src/Core` (inkl. `Paged` für Listenabfragen) (Lifecycle, Schema, Geld, Krypto, Jobs, Audit), `src/Affiliates`, `src/Codes`, `src/Tracking`, `src/Commissions`, `src/Sales` (öffentlicher Vertrag), `src/Integrations/WooCommerce.php` + `src/Integrations/WooCommerce/` (CheckoutField, OrderSync, OrderMetaBox, CouponLink), `src/Payouts`, `src/Portal`, `src/Admin` (+ `Tables/` mit WP_List_Table), `src/Privacy`.

## Noch offen (nächste Schritte)

- Live-Test mit HPOS aus und Classic-Checkout (HPOS an und Blocks-Checkout sind auf WordPress 7.1 / WooCommerce 11.3 getestet).
- SEPA-Format und Beleg-/Steuerlogik mit Bank bzw. Steuerberatung abstimmen.
