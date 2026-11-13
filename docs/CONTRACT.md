# Integrationsvertrag v1 (`WPAC_CONTRACT_VERSION = 1.2.0`)

Öffentliche Schnittstelle von Affiliate Captain für Verkaufsquellen (Pass Captain, Voucher Captain, WooCommerce-Adapter, Connector-Add-ons wie LearnBoating). Kompatibel ist jede Version mit gleicher Major-Version; andere Versionen werden abgelehnt (`incompatible_contract`) und im Integrationsstatus angezeigt.

Änderungen: **1.2.0** – Erweiterungspunkte für Add-ons (Abschnitt „Add-ons“: `wpac_admin_menu`, `wpac_portal_tabs`/`wpac_portal_tab_{key}`, `wpac_commission_amount`, `wpac_payout_created`, `wpac_transfer_rows`/`wpac_transfer_header`, `wpac_report_partners`, `wpac_program_export_files`; abwärtskompatibel). **1.1.0** – neue Funktion `wpac_current_touch()` (abwärtskompatibel; Payloads mit `contract => '1.0.0'` bleiben gültig).

Grundregeln:

- Nie direkt auf `wpac_*`-Tabellen zugreifen.
- Beträge immer als Integer in Minor Units (Cent) plus ISO-4217-Währung.
- Keine vollständigen Bestellobjekte, Zahlungsdaten oder Kundendaten übertragen. Die Kunden-E-Mail dient nur dem Self-Referral-Vergleich und wird nicht gespeichert.
- Alle Aufrufe sind idempotent: dieselbe Bestellung bzw. derselbe Refund bucht nie doppelt.
- Erst nach `wpac_loaded` aufrufen.

## Verkauf melden

```php
add_action( 'wpac_loaded', function () {
    // z. B. wenn eine Ticketbestellung bezahlt wurde:
    $result = wpac_record_sale( array(
        'contract'     => '1.0.0',
        'source'       => 'pass-captain',      // stabiler Quellname
        'order_id'     => '4711',              // stabile Quell-ID
        'currency'     => 'EUR',
        'paid_at'      => '2026-11-11 10:00:00', // UTC oder Timestamp
        'hold_until'   => '2026-12-11 00:00:00', // optional: Garantie-/Widerrufsende
        'partner_code' => 'SEGELSCHULE-NORD',  // optional
        'partner_code_method' => 'app',        // optional: code|app
        'coupon_codes' => array( 'sommer10' ), // optional
        'affiliate_id' => 0,                   // optional: explizite Zuordnung
        'touch'        => null,                // optional: [ code, method link|cookie|app, campaign, content_id ]
        'customer'     => array(
            'user_id'  => 12,                   // 0 = Gast: legt nie eine Lifetime-Bindung an
            'email'    => 'kunde@example.com',
            'name'     => 'Erika Muster',       // optional: Self-Referral-Abgleich mit dem Auszahlungsprofil
            'address'  => 'Seestraße 5',        // optional (Straße), nur Abgleich
            'postcode' => '24937',              // optional, nur Abgleich
        ),
        'items'        => array(
            array(
                'item_id'      => '1',          // stabile Positions-ID (für Refunds)
                'product_id'   => 55,
                'parent_id'    => 0,
                'sku'          => 'SBF-SEE',    // auch Kurscode
                'category_ids' => array( 3 ),
                'tag_ids'      => array( 12 ),     // optional: Produkt-Tags für Tag-Regeln
                'name'         => 'SBF See Kurs',
                'quantity'     => 1,
                'net_minor'    => 49900,        // netto nach Rabatt, ohne Steuer
            ),
        ),
    ) );
} );
```

Alternativ ohne harte Abhängigkeit: `do_action( 'wpac_v1_record_sale', $payload );` (ohne Rückgabewert).

Rückgabe: `array( status => created|duplicate|unattributed, attribution_id, commission_ids, reason )` oder `WP_Error`. Bei technischen Fehlern wird das Ereignis in die Retry-Warteschlange gelegt (`wpac_queued`); Kunden-E-Mail, Name und Anschrift werden dabei entfernt (der Self-Referral-Vergleich nutzt beim Retry nur noch die User-ID). `reason` kann u. a. `no_candidate`, `self_referral` oder `code_limit` (Nutzungslimit des Codes erreicht) sein.

Refunds, die **vor** einem erfolgreich gebuchten Verkauf passieren, sollte die Quelle bereits von `net_minor` abziehen (der WooCommerce-Adapter macht das automatisch). `wpac_record_refund()` ohne vorhandene Provision liefert `no_commission` – außer der Verkauf dieser Bestellung wartet noch in der Wiederholungsschlange: Dann wird die Erstattung ebenfalls eingereiht (`queued`) und nach dem Verkauf gebucht (seit 0.8.0).

## Refund, Storno, Chargeback

```php
wpac_record_refund( array(
    'contract'  => '1.0.0',
    'source'    => 'pass-captain',
    'order_id'  => '4711',
    'refund_id' => 'r-99',                 // stabil, für Idempotenz
    'type'      => 'refund',               // refund|cancellation|chargeback
    'full'      => false,                  // true = gesamte Restprovision umkehren
    'items'     => array( array( 'item_id' => '1', 'net_minor' => 10000 ) ),
    'reason'    => 'Teilerstattung',
) );
```

`cancellation` und `chargeback` kehren immer die gesamte verbleibende Provision um. Korrekturen nach einer Auszahlung werden im nächsten Lauf verrechnet.

## Referral des aktuellen Besuchers (seit 1.1.0)

```php
$touch = function_exists( 'wpac_current_touch' ) ? wpac_current_touch() : null;
// null oder array( code, method link|cookie, campaign, content_id ) – ohne personenbezogene Daten.
```

Für Quellen mit eigenem Checkout: beim Anlegen der Bestellung speichern und später als `touch` an `wpac_record_sale()` übergeben (die Zahlung wird oft erst später, z. B. vom Personal, bestätigt). Nicht bei Kassen-/POS-Verkäufen verwenden – dort gehört die Anfrage dem Personal, nicht dem Käufer.

## Partnercode auflösen (z. B. App → Shop)

```php
$partner = wpac_resolve_partner_code( 'SEGELSCHULE-NORD', array( 'context' => 'app' ) );
// null oder array( affiliate_id, code_id, code ) – berücksichtigt Sperre, Ablauf, Limit und Partnerstatus.
```

Links mit `?partner=CODE` werden automatisch erfasst. Ein Connector kann `partner=CODE` an eine `webshop_url` anhängen.

## Filter (Connector → Affiliate Captain)

| Filter | Parameter | Zweck |
|---|---|---|
| `wpac_commission_base` | `int $base_minor, array $item, array $sale` | Netto-Basis je Position anpassen; `0` schließt aus (Gruppenpakete, Upgrades). |
| `wpac_hold_until` | `int $timestamp, array $sale` | Spätestes Garantie-/Widerrufsende; nur spätere Daten verlängern. |
| `wpac_resolve_partner_code` | `?array $resolved, string $code, array $context` | Eigene Code-Auflösung vorschalten. |
| `wpac_coupon_affiliate` | `?array $resolved, string $coupon` | Coupon → Partner (Voucher Captain). |
| `wpac_attribution_candidates` | `array $candidates, array $sale` | Prioritätsliste anpassen. |
| `wpac_is_self_referral` | `bool, int $affiliate_id, array $sale` | Zusätzliche Self-Referral-Prüfung. |
| `wpac_has_marketing_consent` | `bool` | Consent-Banner anbinden (Cookie nur bei `true`). |
| `wpac_wc_sale_payload` | `array $payload, WC_Order $order` | WooCommerce-Payload ergänzen (z. B. `hold_until`). |
| `wpac_fraud_flags` | `string[] $flags, array $sale, array $attr` | Eigene Betrugsregeln; jeder Treffer setzt die Provision auf „Prüfung nötig“. |
| `wpac_statement_note` | `string $note, object $payout` | Hinweistext im PDF-Abrechnungsbeleg anpassen. |
| `wpac_wc_chargeback_statuses` | `string[]` | WooCommerce-Status, die als Chargeback gelten (Standard seit 0.8.0: `failed`, also eine bezahlte Bestellung, deren Zahlung zurückgebucht wurde). |
| `wpac_registered_integrations` | `array` | Integration für die Statusseite registrieren. |

Integration registrieren:

```php
add_filter( 'wpac_registered_integrations', function ( $list ) {
    $list['pass-captain'] = array( 'name' => 'Pass Captain', 'version' => PCAP_VERSION, 'contract' => '1.0.0', 'active' => true );
    return $list;
} );
```

## Ereignisse (Affiliate Captain → Integrationen)

| Action | Parameter |
|---|---|
| `wpac_loaded` | `string $contract_version` |
| `wpac_referral_captured` | `array $resolved, array $touch` |
| `wpac_affiliate_attributed` | `string $order_id, int $affiliate_id, string $method, string $source` |
| `wpac_commission_created` | `int $commission_id` |
| `wpac_commission_adjusted` | `array $refund, int[] $adjustment_ids` |
| `wpac_commission_approved` / `_rejected` / `_paid` / `_pending` | `int $commission_id` |
| `wpac_payout_paid` | `int $payout_id` |
| `wpac_affiliate_status_changed` | `int $affiliate_id, string $new, string $old` |

| `wpac_contract_not_queued` | `string $what` (`sale`/`refund`), `array $data` – Verkauf/Erstattung konnte weder verbucht noch für die Wiederholung gespeichert werden (z. B. Datenbank weg); die Quelle muss selbst erneut senden. Steht zusätzlich im Plugin-Log. |

Ereignisse sind Benachrichtigungen ohne Kundendaten, kein Ersatz für die idempotenten Funktionen oben.

## Betrieb und Anpassung (Filter/Actions)

| Hook | Art | Zweck |
|---|---|---|
| `wpac_run_job` ( `$handled`, `$type`, `$payload` ) | Filter | Handler der Wiederholungs-Warteschlange (`true` = verarbeitet, bei Fehler Exception werfen; idempotent). Ohne Handler (z. B. WooCommerce kurz deaktiviert) wird der Job um eine Stunde zurückgestellt, ohne Versuche zu verbrauchen. |
| `wpac_job_max_attempts` ( `$attempts` ) | Filter | Versuche, bevor ein Job endgültig fehlschlägt (Standard 8, mindestens 4). |
| `wpac_daily_budget` ( `$seconds` ) | Filter | Laufzeit der täglichen Wartung pro Anfrage (Standard 60 s, 0 = ohne Grenze); der Rest läuft in einem eigenen Lauf weiter. |
| `wpac_approve_due_budget` ( `$seconds` ) | Filter | Laufzeit der Freigabe fälliger Provisionen pro Lauf (Standard 20 s). |
| `wpac_use_action_scheduler` ( `$use` ) | Filter | Einmalige Hintergrundaufgaben über den Action Scheduler von WooCommerce (Standard, wenn vorhanden) oder WP-Cron. |
| `wpac_monthly_mail_batch_size` ( `$size` ) | Filter | Partner-Monatsmails pro Durchlauf (Standard 50, ein Durchlauf pro Minute) – für Mail-Dienste mit Sendelimit. |
| `wpac_csv_separator` ( `$sep`, `$filename` ) | Filter | Trennzeichen der CSV-Downloads im Admin: `;` (Standard, deutsches Excel), `,`, `\|` oder Tab. |
| `wpac_client_ip` ( `$ip` ) | Filter | Besucher-IP für Rate-Limit und Betrugsprüfung (z. B. eigener Proxy-Header). |
| `wpac_bot_user_agents` ( `$needles` ) | Filter | User-Agent-Teile, deren Besuche nicht gezählt werden. |
| `wpac_vies_enabled` ( `$enabled` ) | Filter | VIES-Prüfung der USt-IdNr. (Standard: Einstellung „VIES-Prüfung“, ab Werk aus). |
| `wpac_log_debug` ( `$enabled` ) | Filter | Debug-Einträge ins Plugin-Log schreiben (Standard: `WP_DEBUG`). |
| `wpac_mail` ( `$mail`, `$type`, `$context` ) | Filter | Jede E-Mail des Plugins vor dem Versand: `to`, `subject`, `message`, `headers`, `attachments` ändern (HTML: Header `Content-Type: text/html; charset=UTF-8` ergänzen) oder `false` zurückgeben, um sie nicht zu senden. `$type` z. B. `payout_paid`, `application_received`, `application_confirmed`, `monthly_partner`, `commission`, `dunning`; `$context`: `type`, `admin` (Mail an den Betreiber), `affiliate_id`. |
| `wpac_mail_sent` ( `$type`, `$mail`, `$sent`, `$context` ) | Action | Nach der Übergabe an `wp_mail()`. |
| `wpac_mail_failed` ( `$type`, `$mail`, `$error`, `$context` ) | Action | E-Mail konnte nicht gesendet werden (Grund aus `wp_mail_failed`); steht auch im Plugin-Log. |
| `wpac_notify_payout_paid` ( `$send` ) | Filter | Mail „Auszahlung überwiesen“ (Standard: Einstellung „E-Mail an den Partner, wenn eine Auszahlung bezahlt wurde“). |
| `wpac_portal_tabs` ( `$tabs`, `$aff` ) | Filter | Wie oben; zusätzlich lassen sich die Kern-Tabs `overview`, `links`, `promotion`, `commissions`, `payouts` ausblenden, indem man sie entfernt (Auszahlungsdaten, Konto und Datenschutz bleiben immer). |

**Plugin-Log:** WooCommerce → Status → Protokolle, Quelle `affiliate-captain` (bis 0.10 `wp-affiliate-captain`); ohne WooCommerce das PHP-Fehlerprotokoll (`Affiliate Captain error: …`). Einträge zu Exceptions enthalten Klasse, Fehlercode, Datei und Zeile, nie personenbezogene Daten.

## Add-ons (z. B. Premium-Add-on)

Erweiterungen sind **eigene Plugins** (eigener Slug, eigene Textdomain, bei Vertrieb außerhalb von WordPress.org eigener Header `Update URI`, eigener Updater und ggf. Lizenzprüfung). Das Kernplugin enthält keine Lizenzprüfung und sperrt keine Funktionen (siehe `docs/FREE-PREMIUM.md`). Ein Add-on startet auf `wpac_loaded` und nutzt diese Erweiterungspunkte:

| Hook | Art | Zweck |
|---|---|---|
| `wpac_loaded` | Action | Kernplugin ist geladen – hier Hooks des Add-ons registrieren. |
| `wpac_admin_menu` ( `$parent` ) | Action | Eigene Verwaltungsseiten unter dem Plugin-Menü (`add_submenu_page( $parent, … )`, Parent-Slug `wpac`). Eigene Einstellungen speichert das Add-on in eigenen Optionen. |
| `wpac_portal_tabs` ( `$tabs`, `$aff` ) | Filter | Weitere Tabs im Partnerbereich (`key => Label`); bestehende Tabs lassen sich nicht ersetzen. Ein Tab erscheint nur, wenn `wpac_portal_tab_{key}` belegt ist. |
| `wpac_portal_tab_{key}` ( `$aff` ) | Action | Ausgabe des Add-on-Tabs (vollständig escapen). |
| `wpac_commission_amount` ( `$amount`, `$item`, `$affiliate_id`, `$rule` ) | Filter | Provision einer Position nach Anwendung der Regel (Cent, nie negativ; ausgeschlossene Positionen werden nicht gefiltert) – z. B. zusätzliche Provisionsregeln eines Add-ons. |
| `wpac_payout_created` ( `$payout_id`, `$affiliate_id` ) | Action | Auszahlung angelegt (nach dem Commit, Gutschrift erzeugt) – z. B. Auszahlung über einen anderen Zahlungsdienst und danach `PayoutService::mark_paid()`. Bei Auszahlungsläufen werden die Gutschriften in der Job-Warteschlange geschrieben; die Action feuert dann erst, wenn die Gutschrift vorliegt (Minuten später, aus dem Cron). |
| `wpac_transfer_rows` ( `$rows`, `$batch`, `$payout_ids` ) / `wpac_transfer_header` ( `$header` ) | Filter | Zusätzliche Spalten der Überweisungsliste (Werte am Zeilenende, Spaltennamen am Ende des Kopfs). Der Standardkopf ist übersetzt; der Betrag (Spalte 5) wird beim Download mit Dezimalkomma ausgegeben, die Methode (Spalte 8) als lesbare Bezeichnung – die Zeilen im Filter enthalten weiterhin `1234.50` und `sepa`/`manual`. |
| `wpac_report_partners` ( `$rows`, `$from`, `$to`, `$affiliate_id` ) | Filter | Zusätzliche Kennzahlen im Partnerbericht. |
| `wpac_program_export_files` ( `$files`, `$include_tax_data` ) | Filter | Zusätzliche Dateien im Programm-Export (`Dateiname => Inhalt`); Dateien des Kerns lassen sich nicht ersetzen. |
| `wpac_attribution_candidates`, `wpac_commission_base`, `wpac_vat_treatment`, `wpac_fraud_flags`, `wpac_hold_until`, `wpac_statement_note` | Filter | Zuordnung, Provisionsbasis, Steuerbehandlung, Betrugsregeln, Sperrfrist, Text auf Gutschriften (siehe oben). |
| `wpac_commission_created`, `wpac_commission_adjusted`, `wpac_commission_paid`, `wpac_payout_paid`, `wpac_affiliate_created`, `wpac_affiliate_status_changed` | Action | Ereignisse für Berichte, Exporte und Integrationen. |

Eine abgelaufene oder fehlende Lizenz eines Add-ons darf nur dessen eigene Zusatzfunktionen betreffen – nie Buchungen, Gutschriften, Aufbewahrung oder Datenschutz-Löschungen des Kernplugins.

Alle Erweiterungspunkte sind öffentliche API dieses Vertrags (Änderungen nur abwärtskompatibel innerhalb von Version 1) und durch Integrationstests abgesichert (`tests/integration/run.php`, Abschnitt ZF). Ein Test in `tests/run.php` (A2) schlägt fehl, sobald Lizenzclient, Rechteprüfung oder ein eigener Updater ins Kernplugin gelangen; ein weiterer (ZF6) stellt sicher, dass Buchungen, Auszahlungen, Gutschriften, Exporte und die tägliche Wartung ohne Add-on und ohne Netzwerkzugriff laufen.

**Hinweise auf das Add-on im Kernplugin** (WordPress.org-Richtlinie 11): derzeit keine. Falls künftig gewünscht, nur dezent, wegklickbar und nur auf den eigenen Plugin-Seiten; keine Menüpunkte, Schalter oder Felder, die sichtbar sind, aber erst nach einem Kauf funktionieren.

## Mitgelieferte Adapter

| Plugin | Ort | Verhalten |
|---|---|---|
| WP Pass Captain | `src/Integrations/AffiliateCaptain/Adapter.php` (ab Pass Captain 0.9.0, nur über vorhandene Pass-Captain-Hooks) | Meldet bezahlte Bestellungen (Online-Checkout, Kasse, Abendkasse, Warteliste) als `source = pass-captain`. Bestellungen über WooCommerce werden übersprungen – die erfasst Affiliate Captain selbst. Optionales Feld „Partnercode“ im Checkout (geprüft, Fehlversuchs-Limit), Referral-Link/Cookie wird mit der Bestellung gespeichert; Kasse/Abendkasse übernehmen nie das Referral der Mitarbeitenden. Basis je Position: bezahlter Anteil (nach Kampagnenrabatt und eingelöstem Gutschein) ohne gespeicherte USt; SKU `PASS-E{Event}-T{Ticketart}` für Regeln (z. B. `PASS-E12-*`); Provision bis Terminende gesperrt (`hold_until`). Erstattungen (auch Teil- und Terminabsage-Erstattungen) → anteilige Umkehr; Vollerstattung/Storno → Umkehr des Rests. |
| Voucher Captain | `src/Compat/AffiliateCaptain.php` (ab Voucher Captain 1.2.0) | Ordnet ganze Code-Kampagnen (Gutscheine → Codes erzeugen → Kampagnen → Partner) einem Partnercode zu und beantwortet `wpac_coupon_affiliate` (Priorität 20 – direkte Gutschein-Verknüpfung in Affiliate Captain gewinnt). Gekaufte Gutscheine/Geschenkkarten werden nie zugeordnet. Optional keine Provision auf Gutscheinverkäufe: `add_filter( 'vcap_affiliate_commission_on_voucher_sales', '__return_false' );`. |
