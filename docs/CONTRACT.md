# Integrationsvertrag v1 (`WPAC_CONTRACT_VERSION = 1.2.0`)

Öffentliche Schnittstelle von Affiliate Captain für Verkaufsquellen (Pass Captain, Voucher Captain, WooCommerce-Adapter, Connector-Add-ons wie LearnBoating). Kompatibel ist jede Version mit gleicher Major-Version; andere Versionen werden abgelehnt (`incompatible_contract`) und im Integrationsstatus angezeigt.

Änderungen: **Ergänzungen ohne Versionswechsel (abwärtskompatibel, Plugin 0.11.0)** – `wpac_record_reinstatement()` und `POST /reinstatements`; optionale Felder `netted_refund_ids`, `review`, `category_ancestor_ids`; Erstattungen zu unbekannten Bestellungen werden geparkt (`parked`) statt verworfen; strengere Prüfung der Payloads (siehe „Grenzen und Prüfungen“). **1.2.0** – Erweiterungspunkte für Add-ons (Abschnitt „Add-ons“: `wpac_admin_menu`, `wpac_portal_tabs`/`wpac_portal_tab_{key}`, `wpac_commission_amount`, `wpac_payout_created`, `wpac_transfer_rows`/`wpac_transfer_header`, `wpac_report_partners`, `wpac_program_export_files`; abwärtskompatibel). **1.1.0** – neue Funktion `wpac_current_touch()` (abwärtskompatibel; Payloads mit `contract => '1.0.0'` bleiben gültig).

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
        'touch'        => null,                // optional: [ code, method link|cookie|app, campaign, content_id, ts, sub_id, visit_id ]
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

Weitere optionale Felder:

| Feld | Bedeutung |
|---|---|
| `affiliate_id` + `attribution_method` | Explizite Zuordnung (z. B. Kasse, manuelle Zuordnung, Abo-Verlängerung). `attribution_method` ist nur die Anzeige der Zuordnungsart (`manual` Standard, `renewal`, `app` …; max. 20 Zeichen `a-z0-9_-`). |
| `idempotency_key` | Eigene Ereignis-ID der Quelle (max. 191 Zeichen); steht in der Historie der Provision. Gebucht wird trotzdem höchstens einmal je `source` + `order_id`. |
| `netted_refund_ids` | Liste der Refund-IDs, die bereits von `net_minor` abgezogen sind (siehe unten). |
| `review` | `true`: Provision auf „Prüfung nötig“ setzen (z. B. Verlängerung eines Abos, dessen Erstbestellung geprüft wird). |
| `items[].category_ancestor_ids` | Übergeordnete Kategorien der Position: Regeln auf eine Oberkategorie gelten auch für Unterkategorien (eigene Kategorie vor Oberkategorie). |
| `items[].meta` | Freie Zusatzdaten für Filter (max. 4 KB JSON, wird nicht gespeichert). |

Rückgabe: `array( status => created|duplicate|unattributed, attribution_id, commission_ids, reason )` oder `WP_Error`. `reason` bei `unattributed`: `no_candidate` (kein Partnerlink, -code, Gutschein, Lifetime-Kunde), `code_not_found` (Code/Link unbekannt, abgelaufen, gesperrt, aufgebraucht), `partner_not_eligible` (Partner nicht aktiv oder eingeschränkt), `self_referral`, `code_limit` (Nutzungslimit des Codes erreicht). Bei technischen Fehlern wird das Ereignis in die Retry-Warteschlange gelegt (`wpac_queued`); Kunden-E-Mail, Name und Anschrift werden dabei entfernt (der Self-Referral-Vergleich nutzt beim Retry nur noch die User-ID). Kann es weder verarbeitet noch gespeichert werden (Datenbank nicht erreichbar), kommt `wpac_not_queued` (REST: `503` mit `Retry-After`) – dann muss die Quelle selbst später erneut senden.

**Erstattungen vor dem Verkauf.** Erstattungen, die vor der Buchung des Verkaufs passieren, zieht die Quelle von `net_minor` ab **und** nennt ihre IDs in `netted_refund_ids` (der WooCommerce-Adapter macht beides automatisch). Wurde eine solche Erstattung schon gemeldet, wartet sie (`queued`, wenn der Verkauf in der Wiederholungsschlange steht; `parked`, wenn die Bestellung noch unbekannt ist) und wird mit der Buchung des Verkaufs geschlossen; eine spätere erneute Meldung liefert `superseded`. Nicht genannte wartende Erstattungen werden nach der Buchung abgezogen. Fehlt `netted_refund_ids` ganz (Quellen für Vertrag 1.0–1.2), gelten geparkte Erstattungen als bereits abgezogen – nie doppelt.

## Grenzen und Prüfungen

- `source`, `order_id`, `item_id`, `refund_id`: Text oder Zahl (keine Arrays), `order_id`/`item_id`/`refund_id` max. 64 Zeichen, `source` max. 40 (`a-z0-9_-`). Mehrbyte-Zeichen werden nie mitten im Zeichen gekürzt.
- `item_id` muss innerhalb eines Verkaufs eindeutig sein (`duplicate_item`), höchstens 500 Positionen (`too_many_items`).
- `net_minor`: Ganzzahl (auch als Ziffernstring oder ganzzahliger Float), höchstens 1 000 000 000 je Position; `"1e3"`, `12.5` oder größere Werte → `invalid_amount`.
- `currency`: ISO-4217-Code (Liste per Filter `wpac_contract_currencies` erweiterbar).
- `paid_at`/`hold_until`: `Y-m-d H:i:s` (UTC), ISO 8601, `Ymd`, `YmdHis`, Unix-Zeit in Sekunden **oder Millisekunden**; Daten vor 2000 oder mehr als zehn Jahre in der Zukunft → `invalid_date`.
- Fehler der Prüfung kommen als `WP_Error` (`wpac_<code>`, REST `400`) und werden nicht wiederholt.

## Anforderungen an Quellen

- **Stabile IDs:** `order_id` je Bestellung und `item_id` je Position ändern sich nie; Refunds verweisen auf dieselbe `item_id`. `refund_id` ist je Erstattung eindeutig (für Idempotenz).
- **Netto:** `net_minor` = bezahlter Positionsbetrag nach Rabatten, ohne Steuer und Versand; Gutscheine/Geschenkkarten, die als Zahlung eingelöst werden, und Rabatte auf Bestellebene anteilig abziehen. Geschenkkarten-Käufe nicht als Position melden (oder `net_minor` 0).
- **Bundles/Sets:** als eine Position melden (Summe der Teile), damit Regeln und feste Beträge je Set greifen.
- **Kategorien/Tags:** IDs der Shop-Taxonomie; Oberkategorien in `category_ancestor_ids`.
- **Kunde:** `customer.user_id` und/oder `email` nur für Self-Referral und Lifetime-Zuordnung; nichts davon wird im Klartext gespeichert.
- **Referral:** `touch` aus `wpac_current_touch()` beim Anlegen der Bestellung speichern und beim Verkauf mitsenden; `partner_code` nur, wenn der Käufer ihn eingegeben hat.
- **Erstattungen:** Teilerstattungen positionsgenau (`items`), Storno/Chargeback mit `type` `cancellation`/`chargeback`; gewonnene Chargebacks mit `wpac_record_reinstatement()` zurücknehmen.
- **Abo-Verlängerungen:** Der WooCommerce-Adapter ordnet Verlängerungen (WooCommerce Subscriptions) dem Partner der Erstbestellung zu, solange deren Provision besteht (nicht abgelehnt/storniert), optional nur für eine Anzahl Monate (Einstellung) und mit „Prüfung nötig“, wenn die Erstbestellung geprüft wird. Andere Quellen melden Verlängerungen als eigene Bestellung mit `affiliate_id` des ursprünglichen Partners, `attribution_method => 'renewal'` und ggf. `review => true`; die Prüfung, ob die Erstprovision noch besteht, macht die Quelle.

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

Rückgabe: `array( status, adjustment_ids )` mit `status` = `adjusted` (gebucht), `unchanged` (nichts mehr abzuziehen oder schon gebucht), `unknown_items` (keine der `item_id` hat eine Provision; zusätzlich `unknown_items => [ … ]`, Eintrag im Audit-Log), `no_commission` (Bestellung ohne Provision), `queued`/`parked` (wartet auf den Verkauf, REST `202`), `superseded` (schon im gebuchten Verkauf abgezogen) – oder `WP_Error` wie beim Verkauf.

## Storno oder Chargeback zurücknehmen

```php
wpac_record_reinstatement( array(
    'contract'  => '1.0.0',
    'source'    => 'pass-captain',
    'order_id'  => '4711',
    'refund_id' => 'cb-17',          // optional: nur diese Umkehr; leer = alle Stornos/Chargebacks der Bestellung
    'reason'    => 'Chargeback gewonnen',
) );
// array( status => reinstated|unchanged, count ) oder WP_Error
```

Ohne harte Abhängigkeit: `do_action( 'wpac_v1_record_reinstatement', $payload );`, per REST `POST /wpac/v1/reinstatements`. Noch nicht ausgezahlte Umkehrbuchungen werden abgelehnt, bereits verrechnete bekommen eine positive Gegenbuchung („Wiederherstellung“). Teilerstattungen und die Umkehr einer Umbuchung auf einen anderen Partner werden nie zurückgenommen. Idempotent.

## Referral des aktuellen Besuchers (seit 1.1.0)

```php
$touch = function_exists( 'wpac_current_touch' ) ? wpac_current_touch() : null;
// null oder array( code, method link|cookie, campaign, content_id, ts, sub_id, visit_id ) – ohne personenbezogene Daten.
// sub_id: Sub-ID des Partners (Parameter `sub`, Filter `wpac_sub_id_param`), visit_id: erfasster Besuch (0 = keiner).
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
| `wpac_commission_base` | `int $base_minor, array $item, array $sale` | Netto-Basis je Position anpassen; `0` schließt aus (Gruppenpakete, Upgrades). `wpac_commission_amount` läuft danach genau einmal je Position und wird auf die Basis begrenzt. |
| `wpac_hold_release` | `bool $hold, string $source, string $order_id` | `true` = Provisionen der Bestellung jetzt noch nicht freigeben (wird bei jedem Lauf neu geprüft). |
| `wpac_wc_hold_statuses` | `string[]` | WooCommerce-Status, in denen nicht freigegeben wird (Standard: `on-hold` und die Chargeback-Status). |
| `wpac_wc_is_gift_card` | `bool, WC_Order_Item_Product $item, ?WC_Product $product` | Position ist ein Geschenkkarten-Kauf (Standard: Produkttypen `pw-gift-card`, `gift-card`, Meta `_gift_card = yes`) → keine Provision. |
| `wpac_wc_parent_line` | `int $parent_item_id, $item, WC_Order $order` | Position gehört zu einer anderen (Bundle-/Set-Plugins; Product Bundles und Composite Products werden erkannt). |
| `wpac_wc_base_cap` | `int $cap, WC_Order $order` | Obergrenze der Provisionsbasis einer Bestellung (Standard: Bestellsumme ohne Steuer, Versand, positive Gebühren und Geschenkkarten). |
| `wpac_renewal_attribution` | `bool $ok, object $attr, WC_Order $renewal, int $first_order` | Ob eine Abo-Verlängerung noch an den Partner der Erstbestellung geht. |
| `wpac_rule_precedence` | `string` | Vorrang der Regeln: `scope` (Partner vor Gruppe vor allen, Ausschlüsse gewinnen immer; Standard) oder `target` (Produkt vor Kategorie vor allen, Ausschlüsse konkurrieren wie Regeln). Einstellung „Regelvorrang“. |
| `wpac_contract_currencies` | `string[]` | Akzeptierte Währungscodes. |
| `wpac_job_retention_days` | `array` | Aufbewahrung der Warteschlange in Tagen (`failed_sales` 0 = behalten, `failed` 180, `parked` 365, `superseded` 365, `done` 30). |
| `wpac_hold_until` | `int $timestamp, array $sale` | Spätestes Garantie-/Widerrufsende; nur spätere Daten verlängern. |
| `wpac_resolve_partner_code` | `?array $resolved, string $code, array $context` | Eigene Code-Auflösung vorschalten. |
| `wpac_coupon_affiliate` | `?array $resolved, string $coupon` | Coupon → Partner (Voucher Captain). |
| `wpac_attribution_candidates` | `array $candidates, array $sale` | Prioritätsliste anpassen. |
| `wpac_is_self_referral` | `bool, int $affiliate_id, array $sale` | Zusätzliche Self-Referral-Prüfung. |
| `wpac_has_marketing_consent` | `bool` | Consent-Banner anbinden (Cookie nur bei `true`). |
| `wpac_wc_sale_payload` | `array $payload, WC_Order $order` | WooCommerce-Payload ergänzen (z. B. `hold_until`). |
| `wpac_fraud_flags` | `string[] $flags, array $sale, array $attr` | Eigene Betrugsregeln; jeder Treffer setzt die Provision auf „Prüfung nötig“. |
| `wpac_statement_note` | `string $note, object $payout` | Hinweistext im PDF-Abrechnungsbeleg anpassen. |
| `wpac_wc_chargeback_statuses` | `string[]` | WooCommerce-Status, die als Chargeback gelten (Standard seit 0.8.0: `failed`, also eine bezahlte Bestellung, deren Zahlung zurückgebucht wurde). Zurück in einen bezahlten Status (`wc_get_is_paid_statuses()`, auch eigene) stellt die Provision wieder her. |
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
| `wpac_daily_budget` ( `$seconds` ) | Filter | Laufzeit der täglichen Wartung pro Anfrage (Standard 60 s, 0 = ohne Grenze); der Rest läuft in einem eigenen Lauf weiter – auch ein Löschschritt, der mitten im Rückstand aufhören musste (er folgt nach den übrigen Schritten). |
| `wpac_visit_purge_budget` ( `$seconds` ) / `wpac_visit_purge_batch` ( `$rows` ) | Filter | Löschung alter Besuche: Laufzeit pro Lauf (Standard 20 s, 0 = bis fertig) und Zeilen pro DELETE (Standard 5000). Solange ein Rückstand bleibt, folgt alle 3 Minuten ein weiterer Lauf (`wpac_purge_visits`) – keine feste Tagesobergrenze. |
| `wpac_balance_check_budget` ( `$seconds` ) / `wpac_balance_full_check_days` ( `$days` ) | Filter | Täglicher Saldenabgleich: Laufzeit pro Lauf (Standard 30 s; der Durchgang läuft über `wpac_balance_check` weiter) und Abstand der vollständigen Neuberechnung je Konto (Standard 30 Tage; dazwischen nur die Buchungen seit dem letzten geprüften Stand). |
| `wpac_tier_budget` ( `$seconds` ) | Filter | Laufzeit von Monatsabschluss und Neuberechnung der Staffelboni pro Lauf (Standard 20 s); der Rest folgt nach etwa 2 Minuten (`wpac_tier_continue`), nicht erst am Folgetag. |
| `wpac_approve_due_budget` ( `$seconds` ) | Filter | Laufzeit der Freigabe fälliger Provisionen pro Lauf (Standard 20 s). |
| `wpac_use_action_scheduler` ( `$use` ) | Filter | Einmalige Hintergrundaufgaben über den Action Scheduler von WooCommerce (Standard, wenn vorhanden) oder WP-Cron. |
| `wpac_monthly_mail_batch_size` ( `$size` ) | Filter | Partner-Monatsmails pro Durchlauf (Standard 50, ein Durchlauf pro Minute) – für Mail-Dienste mit Sendelimit. |
| `wpac_csv_separator` ( `$sep`, `$filename` ) | Filter | Trennzeichen der CSV-Downloads im Admin: `;` (Standard, deutsches Excel), `,`, `\|` oder Tab. |
| `wpac_client_ip` ( `$ip` ) | Filter | Besucher-IP für Rate-Limit und Betrugsprüfung (z. B. eigener Proxy-Header). |
| `wpac_bot_user_agents` ( `$needles` ) | Filter | User-Agent-Teile, deren Besuche nicht gezählt werden. |
| `wpac_vies_enabled` ( `$enabled` ) | Filter | VIES-Prüfung der USt-IdNr. (Standard: Einstellung „VIES-Prüfung“, ab Werk aus). |
| `wpac_log_debug` ( `$enabled` ) | Filter | Debug-Einträge ins Plugin-Log schreiben (Standard: `WP_DEBUG`). |
| `wpac_mail` ( `$mail`, `$type`, `$context` ) | Filter | Jede E-Mail des Plugins vor dem Versand: `to`, `subject`, `message`, `headers`, `attachments` ändern (HTML: Header `Content-Type: text/html; charset=UTF-8` ergänzen) oder `false` zurückgeben, um sie nicht zu senden. `$type` z. B. `payout_paid`, `application_received`, `application_confirmed`, `monthly_partner`, `commission`, `dunning`, `credit_note`, `credit_note_cancelled`, `tax_data_changed`, `tax_data_pending`; `$context`: `type`, `admin` (Mail an den Betreiber), `affiliate_id`. |
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
| `wpac_statement_generated` ( `$payout_id`, `$path` ) / `wpac_storno_generated` ( `$payout_id`, `$path` ) | Action | Gutschrift bzw. Korrekturbeleg / Stornobeleg erzeugt und gespeichert (auch beim späteren Nachholen) – der Kern schickt den Beleg damit an den Partner (abschaltbar mit `wpac_notify_credit_note`). |
| `wpac_statement_dir` ( `$dir` ) / `wpac_statement_locale` ( `$locale`, `$site_locale` ) | Filter | Belegordner (z. B. gemeinsamer Speicher mehrerer App-Server) bzw. Belegsprache. |
| `wpac_small_business_limits` ( `$limits`, `$operator_country` ) / `wpac_vies_timeout` / `wpac_vies_daily_cap` | Filter | Kleinunternehmergrenzen je Währung (Cent: Vorjahr, laufendes Jahr), VIES-Timeout und Tageslimit der Nachprüfungen. |
| `wpac_transfer_rows` ( `$rows`, `$batch`, `$payout_ids` ) / `wpac_transfer_header` ( `$header` ) | Filter | Zusätzliche Spalten der Überweisungsliste (Werte am Zeilenende, Spaltennamen am Ende des Kopfs). Der Standardkopf ist übersetzt; der Betrag (Spalte 5) wird beim Download mit Dezimalkomma ausgegeben, die Methode (Spalte 8) als lesbare Bezeichnung – die Zeilen im Filter enthalten weiterhin `1234.50` und `sepa`/`manual`. |
| `wpac_report_partners` ( `$rows`, `$from`, `$to`, `$affiliate_id` ) | Filter | Zusätzliche Kennzahlen im Partnerbericht. |
| `wpac_program_export_files` ( `$files`, `$include_tax_data` ) | Filter | Zusätzliche Dateien im Programm-Export (`Dateiname => Inhalt`); Dateien des Kerns lassen sich nicht ersetzen. |
| `wpac_uninstall_time_budget` ( `$seconds` ) | Filter | Zeitbudget der Deinstallation im Netzwerk (Sekunden, 0 = unbegrenzt; Standard 80 % von `max_execution_time`, wenn sich das Limit nicht aufheben lässt). Nicht erreichte Sites stehen danach in der Netzwerkoption `wpac_uninstall_incomplete`. Zu setzen in einem MU-Plugin oder einem anderen aktiven Plugin (dieses Plugin ist beim Löschen deaktiviert). |
| `wpac_attribution_candidates`, `wpac_commission_base`, `wpac_vat_treatment`, `wpac_fraud_flags`, `wpac_hold_until`, `wpac_statement_note` | Filter | Zuordnung, Provisionsbasis, Steuerbehandlung, Betrugsregeln, Sperrfrist, Text auf Gutschriften (siehe oben). |

`wpac_vat_treatment` wird je Leistungszeitpunkt angewandt: mit den aktuellen Steuerdaten und – für Provisionen vor einer Änderung des Steuerstatus bzw. USt-Satzes – mit den damals gültigen (zweiter Parameter: Steuerdaten zu diesem Zeitpunkt). Rückzahlungen und Abschreibungen (`repayment`, `writeoff`) sind nicht steuerbar (`vat_mode = none`) und werden nicht gefiltert.
| `wpac_commission_created`, `wpac_commission_adjusted`, `wpac_commission_paid`, `wpac_payout_paid`, `wpac_affiliate_created`, `wpac_affiliate_status_changed` | Action | Ereignisse für Berichte, Exporte und Integrationen. |

Eine abgelaufene oder fehlende Lizenz eines Add-ons darf nur dessen eigene Zusatzfunktionen betreffen – nie Buchungen, Gutschriften, Aufbewahrung oder Datenschutz-Löschungen des Kernplugins.

Alle Erweiterungspunkte sind öffentliche API dieses Vertrags (Änderungen nur abwärtskompatibel innerhalb von Version 1) und durch Integrationstests abgesichert (`tests/integration/run.php`, Abschnitt ZF). Ein Test in `tests/run.php` (A2) schlägt fehl, sobald Lizenzclient, Rechteprüfung oder ein eigener Updater ins Kernplugin gelangen; ein weiterer (ZF6) stellt sicher, dass Buchungen, Auszahlungen, Gutschriften, Exporte und die tägliche Wartung ohne Add-on und ohne Netzwerkzugriff laufen.

**Hinweise auf das Add-on im Kernplugin** (WordPress.org-Richtlinie 11): derzeit keine. Falls künftig gewünscht, nur dezent, wegklickbar und nur auf den eigenen Plugin-Seiten; keine Menüpunkte, Schalter oder Felder, die sichtbar sind, aber erst nach einem Kauf funktionieren.

## Mitgelieferte Adapter

| Plugin | Ort | Verhalten |
|---|---|---|
| WP Pass Captain | `src/Integrations/AffiliateCaptain/Adapter.php` (ab Pass Captain 0.9.0, nur über vorhandene Pass-Captain-Hooks) | Meldet bezahlte Bestellungen (Online-Checkout, Kasse, Abendkasse, Warteliste) als `source = pass-captain`. Bestellungen über WooCommerce werden übersprungen – die erfasst Affiliate Captain selbst. Optionales Feld „Partnercode“ im Checkout (geprüft, Fehlversuchs-Limit), Referral-Link/Cookie wird mit der Bestellung gespeichert; Kasse/Abendkasse übernehmen nie das Referral der Mitarbeitenden. Basis je Position: bezahlter Anteil (nach Kampagnenrabatt und eingelöstem Gutschein) ohne gespeicherte USt; SKU `PASS-E{Event}-T{Ticketart}` für Regeln (z. B. `PASS-E12-*`); Provision bis Terminende gesperrt (`hold_until`). Erstattungen (auch Teil- und Terminabsage-Erstattungen) → anteilige Umkehr; Vollerstattung/Storno → Umkehr des Rests. |
| Voucher Captain | `src/Compat/AffiliateCaptain.php` (ab Voucher Captain 1.2.0) | Ordnet ganze Code-Kampagnen (Gutscheine → Codes erzeugen → Kampagnen → Partner) einem Partnercode zu und beantwortet `wpac_coupon_affiliate` (Priorität 20 – direkte Gutschein-Verknüpfung in Affiliate Captain gewinnt). Gekaufte Gutscheine/Geschenkkarten werden nie zugeordnet. Optional keine Provision auf Gutscheinverkäufe: `add_filter( 'vcap_affiliate_commission_on_voucher_sales', '__return_false' );`. |
