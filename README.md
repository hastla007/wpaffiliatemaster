# WP Affiliate Captain

Eigenständiges WordPress-Plugin für ein eigenes Partnerprogramm: Partnergewinnung, Partnercodes und Referral-Links, Attribution, Provisionsledger mit Refund-Korrekturen, Auszahlungsläufe mit SEPA-Export und ein Partnerportal.

Läuft ohne WooCommerce, Voucher Captain oder Pass Captain. Integrationen sprechen ausschließlich über einen versionierten öffentlichen Vertrag (Hooks/Funktionen) – nie über Tabellen anderer Plugins. Siehe [docs/CONTRACT.md](docs/CONTRACT.md).

> Status: **0.1.0 – V1-Kern, noch nicht in einer echten WordPress-Installation getestet.** Die reine Rechenlogik ist per `php tests/run.php` abgedeckt. Rechts-, Steuer- und Datenschutzfragen (Cookie-Consent, Belegpflichten, Umsatzsteuer) sind fachlich zu prüfen.

## Anforderungen

- WordPress 6.2+, PHP 7.4+ (mit `sodium`, `dom`)
- Optional: WooCommerce 8.0+ (HPOS und Classic-/Blocks-Checkout; Partnercode-Feld im Blocks-Checkout ab 8.9)

## Installation

1. Ordner nach `wp-content/plugins/wp-affiliate-captain/` kopieren und aktivieren.
2. Zwei Seiten anlegen: eine mit `[wpac_affiliate_registration]` (Bewerbung), eine mit `[wpac_affiliate_portal]` (Partnerbereich).
3. Unter **Affiliate Captain → Einstellungen** Seiten, Teilnahmebedingungen (Seite + Version), Standardprovision, Haltefrist, Mindestbetrag und das Firmenkonto für SEPA setzen.
4. Empfohlen in `wp-config.php`: eigener Schlüssel für die IBAN-Verschlüsselung (64 Hex-Zeichen, z. B. `php -r 'echo bin2hex(random_bytes(32));'`):
   ```php
   define( 'WPAC_ENCRYPTION_KEY', '…' );
   ```
   Ohne diesen Schlüssel wird er aus den WordPress-Salts abgeleitet – ein Salt-Wechsel macht gespeicherte Bankdaten dann unlesbar.

## Ablauf

**Partner → Link/Code → Auftrag → Provision → Refund-Korrektur → Auszahlung**

| Schritt | Umsetzung |
|---|---|
| Bewerbung | Formular mit Website/Kanal, Honeypot + Rate-Limit, Annahme der Bedingungen mit Version + SHA-256 der Bedingungsseite. Freigabe/Ablehnung/Pause/Beendigung mit Grund im Admin. Bei Freigabe wird ein WP-Konto verknüpft/angelegt und ein Partnercode erzeugt. |
| Partnergruppen | z. B. „Ausbildungspartner“ / „Content-Partner“ mit eigenen Provisionsregeln. |
| Partnercode (cookie-frei) | Feld an der Kasse (Classic + Blocks), Übergabe durch Apps/Integrationen, optional mit WooCommerce-/Voucher-Coupon verknüpft (Rabatt bleibt Sache des Coupons). Limits, Ablaufdatum, Sperre, Massencode-Batches mit CSV-Export. |
| Referral-Link | `?partner=CODE` (Parameter konfigurierbar, Vanity-Slug möglich). Besuch wird minimiert erfasst (keine IP, nur Referrer-Host, UTM-Kampagne, Content-ID). Code bleibt in der WooCommerce-Shop-Session; ein Affiliate-Cookie nur nach Marketing-Einwilligung (WP Consent API oder Filter `wpac_has_marketing_consent`). |
| Attribution | Reihenfolge: manuell (mit Grund) → eingegebener Code → Affiliate-Coupon (falls priorisiert) → Link/Cookie (First/Last Touch) → Coupon. Self-Referral (WP-Konto, Bestellkonto, normalisierte E-Mail) wird gesperrt oder zur Prüfung markiert. |
| Provision | Je Bestellposition auf Netto nach Rabatt ohne Steuer/Versand. Regeln: global / Gruppe / Partner × alle / Produkt / Kategorie / SKU-Kurscode (mit `*`), Prozent, Fixbetrag pro Stück oder Ausschluss. Ausschluss gewinnt immer, sonst die spezifischste Regel. Rate, Basis und Regel-Snapshot werden gespeichert – spätere Regeländerungen ändern keine Historie. Regelvorschau im Admin. |
| Haltefrist | `hold_until` je Auftrag = Zahlung + Haltetage, verlängerbar durch Integrationen (Garantie-/Widerrufsende). Danach automatische Freigabe (stündlicher Cron). |
| Refunds | Teilrefunds positionsgenau anteilig, Storno/Chargeback als Restumkehr. Korrekturen sind eigene negative Ledger-Zeilen; wiederholte Ereignisse buchen nie doppelt (Idempotency-Key, Obergrenze = ursprüngliche Provision). Nach Auszahlung wird die Korrektur im nächsten Lauf verrechnet. |
| Auszahlung | Lauf mit Periodenende + Mindestbetrag; negative Salden/Beträge unter Minimum werden vorgetragen. Jede Provision kann nur einmal in einer Auszahlung stehen (UNIQUE). Abrechnungsbeleg (druckbar) mit Steuerstatus-Snapshot, SEPA-XML (pain.001.001.03), „bezahlt“ markieren, Stornieren gibt Provisionen frei. |
| Partnerportal | Kennzahlen, Link-Generator mit Kampagne, Codes, Provisionen (nur Bestellreferenz, Datum, Produkt, Betrag, Status – keine Kundendaten), Auszahlungen, Steuer-/Bankdaten (IBAN verschlüsselt, maskiert angezeigt), erneute Annahme geänderter Bedingungen. |
| Betrieb | Retry-Warteschlange für fehlgeschlagene Integrationsereignisse, Integrationsstatus/Vertragsprüfung, Audit-Log, CSV-Exporte mit Formel-Injection-Schutz, WordPress-Datenexport/-löschung, Aufbewahrungsfrist für Besuche, eigene Capabilities (`wpac_manage_program`, `wpac_manage_affiliates`, `wpac_manage_payouts`, `wpac_view_tax_data`). |

## Entwicklung

```bash
php tests/run.php
```

```bash
composer lint
```

Struktur: `src/Core` (Lifecycle, Schema, Geld, Krypto, Jobs, Audit), `src/Affiliates`, `src/Codes`, `src/Tracking`, `src/Commissions`, `src/Sales` (öffentlicher Vertrag), `src/Integrations/WooCommerce.php`, `src/Payouts`, `src/Portal`, `src/Admin`, `src/Privacy`.

## Noch offen (nächste Schritte)

- Test in echter WordPress-/WooCommerce-Umgebung (HPOS an/aus, Classic- und Blocks-Checkout), Integrationstests für Ledger/Payouts.
- Adapter in Pass Captain und Voucher Captain gegen den Vertrag v1 (siehe `docs/CONTRACT.md`).
- Monatsstufen, Creatives/Werbemittel-Freigabe, QR-Codes, REST-API, Übersetzung `de_DE`.
- SEPA-Format und Beleg-/Steuerlogik mit Bank bzw. Steuerberatung abstimmen.
