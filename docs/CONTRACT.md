# Integrationsvertrag v1 (`WPAC_CONTRACT_VERSION = 1.3.0`)

Öffentliche Schnittstelle von Affiliate Captain für Verkaufsquellen (Pass Captain, Voucher Captain, WooCommerce-Adapter, Connector-Add-ons wie LearnBoating). Kompatibel ist jede Version mit gleicher Major-Version; andere Versionen werden abgelehnt (`incompatible_contract`) und im Integrationsstatus angezeigt.

Änderungen: **1.3.0** – Auszahlung über Zahlungsdienste für Add-ons: `wpac_get_payout()`, `wpac_get_payouts()`, `wpac_claim_payout()`, `wpac_complete_payout()`, `wpac_release_payout()` (Abschnitt „Auszahlung über Zahlungsdienste“; abwärtskompatibel); dazu Lese- und Schreib-API für Partner und Provisionen (`wpac_get_affiliate()`, `wpac_get_affiliates()`, `wpac_get_commission()`, `wpac_get_commissions()`, `wpac_update_affiliate()`, `wpac_set_affiliate_status()`, `wpac_set_commission_status()`, `wpac_create_adjustment()`; Abschnitt „Lese- und Schreib-API (1.3.0)“), Portal-Hooks für eigenes Branding (`wpac_portal_before`/`_after`, `wpac_portal_classes`, `wpac_portal_labels`), Filter `wpac_mail_from_name` und Ereignisse: `wpac_commission_created` feuert jetzt für jede Buchung im Provisionskonto (auch Korrekturgutschrift, Abschreibung, Rückzahlung, Wiederherstellung, Bonuskorrektur; immer nach dem Commit), neu `wpac_payout_cancelled`. **Ergänzungen ohne Versionswechsel (abwärtskompatibel, Plugin 0.11.0)** – `wpac_record_reinstatement()` und `POST /reinstatements`; optionale Felder `netted_refund_ids`, `review`, `category_ancestor_ids`; Erstattungen zu unbekannten Bestellungen werden geparkt (`parked`) statt verworfen; strengere Prüfung der Payloads (siehe „Grenzen und Prüfungen“). **1.2.0** – Erweiterungspunkte für Add-ons (Abschnitt „Add-ons“: `wpac_admin_menu`, `wpac_portal_tabs`/`wpac_portal_tab_{key}`, `wpac_commission_amount`, `wpac_payout_created`, `wpac_transfer_rows`/`wpac_transfer_header`, `wpac_report_partners`, `wpac_program_export_files`; abwärtskompatibel). **1.1.0** – neue Funktion `wpac_current_touch()` (abwärtskompatibel; Payloads mit `contract => '1.0.0'` bleiben gültig).

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

Alternativ ohne harte Abhängigkeit: `do_action( 'wpac_v1_record_sale', $payload );` (ohne Rückgabewert). Abgelehnte Payloads (Prüfungsfehler wie `invalid_currency`) werden ins Log geschrieben und unter **Integrationen → Systemstatus** sowie im Website-Zustand angezeigt; wiederholt werden sie nicht. Wer das Ergebnis braucht, ruft die Funktion auf.

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
- `currency`: ISO-4217-Code oder ein Währungscode, den WooCommerce anbietet und dessen Nachkommastellen bekannt sind (z. B. IRT mit 0, BTC mit 8, GGP mit 2; `Money::DECIMALS`); Liste per Filter `wpac_contract_currencies` änderbar – Codes ohne bekannte Nachkommastellen werden auch dann abgelehnt. Die Programmwährung in den Einstellungen lässt nur diese Codes zu.
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
| `wpac_contract_currencies` | `string[]` | Akzeptierte Währungscodes (nur ISO 4217 oder mit bekannten Nachkommastellen). |
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
| `wpac_commission_created` | `int $commission_id` – jede neue Buchung im Provisionskonto außer Erstattungs-Korrekturen: Verkauf, Bestellerhöhung, manuelle Buchung, Monatsbonus und Bonuskorrektur, Abschreibung (`writeoff`), Rückzahlung (`repayment`), Wiederherstellung (`reinstatement`), Korrekturgutschrift (`correction`; seit 1.3.0 für alle). Art über `wpac_get_commission( $id )['kind']`. |
| `wpac_commission_adjusted` | `array $refund, int[] $adjustment_ids` – Erstattung, Storno, Chargeback (Buchungsarten `refund`, `cancellation`, `chargeback`; dafür kein `wpac_commission_created`). Wird eine WooCommerce-Erstattung gelöscht, kommen die zurückgenommenen, noch nicht freigegebenen Erstattungszeilen mit `$refund['deleted'] = true`; bereits freigegebene bekommen eine Wiederherstellung (`reinstatement`, `wpac_commission_created`). |
| `wpac_commission_approved` / `_rejected` / `_paid` / `_pending` / `_review` | `int $commission_id` – `_review` feuert, wenn eine abgelehnte Provision zur Prüfung wieder geöffnet wird. |
| `wpac_affiliate_erased` | `int $affiliate_id, bool $open` – nach der Löschung der personenbezogenen Daten eines Partners (Art. 17 DSGVO); Add-ons löschen ihre eigenen Daten des Partners. `$open`: offene Provisionen/Auszahlungen haben einen Teil der Löschung aufgeschoben. |
| `wpac_payout_created` | `int $payout_id, int $affiliate_id` (siehe „Add-ons“) |
| `wpac_payout_paid` | `int $payout_id` (auch nach `wpac_complete_payout()`) |
| `wpac_payout_cancelled` | `int $payout_id` – nicht bezahlte Auszahlung storniert (auch eine reservierte und die alte Auszahlung beim Neuerstellen; seit 1.3.0). |
| `wpac_correction_generated` | `int $correction_id, string $path` – Korrekturgutschrift erzeugt (siehe „Add-ons“). |
| `wpac_refund_after_correction` | `array $refund, string $credit_note, string[] $corrections` – Erstattung/Storno einer Bestellung abgezogen, deren Gutschrift bereits eine Korrekturgutschrift hat (die die Erstattung schon enthalten kann); die WooCommerce-Integration schreibt eine Bestellnotiz. Buchung unverändert. |
| `wpac_affiliate_created` | `int $affiliate_id, string $status` |
| `wpac_affiliate_status_changed` | `int $affiliate_id, string $new, string $old` |

| `wpac_contract_not_queued` | `string $what` (`sale`/`refund`), `array $data` – Verkauf/Erstattung konnte weder verbucht noch für die Wiederholung gespeichert werden (z. B. Datenbank weg); die Quelle muss selbst erneut senden. Steht zusätzlich im Plugin-Log. |

Ereignisse sind Benachrichtigungen ohne Kundendaten, kein Ersatz für die idempotenten Funktionen oben. Alle Buchungs- und Auszahlungsereignisse feuern erst nach dem Commit der Datenbanktransaktion; wird eine Buchung zurückgerollt, feuert nichts.

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
| `wpac_mail` ( `$mail`, `$type`, `$context` ) | Filter | Jede E-Mail des Plugins vor dem Versand: `to`, `subject`, `message`, `headers`, `attachments` ändern (HTML: Header `Content-Type: text/html; charset=UTF-8` ergänzen) oder `false` zurückgeben, um sie nicht zu senden. `$type` z. B. `payout_paid`, `application_received`, `application_confirmed`, `monthly_partner`, `commission`, `dunning`, `credit_note`, `credit_note_cancelled`, `credit_note_corrected`, `tax_data_changed`, `tax_data_pending`; `$context`: `type`, `admin` (Mail an den Betreiber), `affiliate_id`, `template` (Schlüssel der E-Mail-Vorlage oder leer), `html` (Layout „HTML“: `message` ist schon HTML), `test` (Testversand aus dem Admin). Läuft nach der im Admin bearbeiteten Vorlage. |
| `wpac_mail_html` ( `$html`, `$text`, `$subject`, `$context` ) | Filter | HTML-Fassung einer Vorlagen-Mail beim Layout „HTML“ (eigenes Layout); `$text` ist der Klartext, der als Alternativteil mitgeht. |
| `wpac_mail_from_name` ( `$name`, `$type`, `$context` ) | Filter | Absendername der E-Mails (Standard: Einstellung „Absendername“, leer = Seitenname bzw. WordPress-/SMTP-Standard); z. B. für die eigene Marke eines White-Label-Add-ons. Zeilenumbrüche und spitze Klammern werden entfernt. Seit 1.3.0. |
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
| `wpac_portal_before` / `wpac_portal_after` ( `$aff`, `$tab` ) | Action | Anfang bzw. Ende des Partnerbereichs innerhalb des Wrappers `div.wpac-portal` (vor den Hinweisen und Tabs bzw. nach dem Tab-Inhalt), z. B. Logo, Kopf- oder Fußzeile eines White-Label-Add-ons. Nur für eingeloggte Partner mit Zugang (nicht für die Anmeldung, Bewerbung in Prüfung oder abgelehnte Bewerbung). Ausgabe vollständig escapen. Seit 1.3.0. |
| `wpac_portal_classes` ( `$classes`, `$aff`, `$tab` ) | Filter | CSS-Klassen des Wrappers (z. B. eigenes Farbschema); `wpac-portal` bleibt immer, jede Klasse läuft durch `sanitize_html_class()`. Eigene Styles registriert das Add-on mit Abhängigkeit vom Stylesheet-Handle `wpac-portal` (`wp_enqueue_style( 'mein-brand', $url, array( 'wpac-portal' ) )`, z. B. in `wpac_portal_before`). Seit 1.3.0. |
| `wpac_portal_labels` ( `$labels`, `$aff` ) | Filter | Beschriftungen ersetzen: `tab_{key}` für die Kern-Tabs (`tab_overview`, `tab_statistics`, `tab_links`, `tab_promotion`, `tab_commissions`, `tab_payouts`, `tab_profile`, `tab_account`, `tab_privacy`) und `nav` für den zugänglichen Namen der Tab-Navigation. Nur Text (HTML wird entfernt), leere Werte zählen nicht. Seit 1.3.0. |
| `wpac_commission_amount` ( `$amount`, `$item`, `$affiliate_id`, `$rule` ) | Filter | Provision einer Position nach Anwendung der Regel (Cent, nie negativ; ausgeschlossene Positionen werden nicht gefiltert) – z. B. zusätzliche Provisionsregeln eines Add-ons. |
| `wpac_payout_created` ( `$payout_id`, `$affiliate_id` ) | Action | Auszahlung angelegt (nach dem Commit, Gutschrift erzeugt) – z. B. Auszahlung über einen anderen Zahlungsdienst und danach `PayoutService::mark_paid()`. Bei Auszahlungsläufen werden die Gutschriften in der Job-Warteschlange geschrieben; die Action feuert dann erst, wenn die Gutschrift vorliegt (Minuten später, aus dem Cron). |
| `wpac_statement_generated` ( `$payout_id`, `$path` ) / `wpac_storno_generated` ( `$payout_id`, `$path` ) | Action | Gutschrift bzw. Korrekturbeleg / Stornobeleg erzeugt und gespeichert (auch beim späteren Nachholen) – der Kern schickt den Beleg damit an den Partner (abschaltbar mit `wpac_notify_credit_note`). |
| `wpac_correction_generated` ( `$correction_id`, `$path` ) | Action | Korrekturgutschrift (`KG-…`) zu einer bezahlten Auszahlung erzeugt und gespeichert – der Kern schickt sie damit an den Partner (abschaltbar mit `wpac_notify_credit_note`). |
| `wpac_einvoice_xml` ( `$xml`, `$document`, `$row`, `$data` ) | Filter | Fertige E-Rechnung (UN/CEFACT CII, EN 16931; XRechnung 3.0, wenn deren Zusatzangaben vorliegen) einer Gutschrift, Korrekturgutschrift oder Stornierung, bevor sie heruntergeladen oder an die Partner-Mail angehängt wird. `$document`: `credit_note`, `cancellation` oder `correction`; `$row`: Auszahlung bzw. Korrekturgutschrift; `$data`: die Belegdaten (siehe `wpac_einvoice_data`). Rückgabe: XML-String. Das XML wird bei jedem Abruf aus denselben eingefrorenen Daten wie das PDF gebaut (nicht gespeichert); ein Filter, der Beträge ändert, macht XML und PDF widersprüchlich. Seit 0.14.0. |
| `wpac_einvoice_data` ( `$data`, `$document`, `$row` ) | Filter | Belegdaten der E-Rechnung vor dem Bau des XML (`EInvoice::xml()`): `number`, `type` (389/384/381), `issue_date`, `currency`, `notes`, `buyer_reference`, `seller` (u. a. `phone`, `email`), `buyer`, `period`, `lines` (`name`, `note`, `net` in Cent, `category`, `rate`), `taxes`, `exemptions`, `prepaid`, `payment` (`code`, `info`, `iban`, `holder`, `bic`), `terms`, `preceding`. Typische Nutzung: Telefonnummer des Partners (dann XRechnung-Profil) oder IBAN (dann Zahlungsart 58) ergänzen. Seit 0.14.0. |
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

**White-Label:** Der Partnerbereich und die Partner-E-Mails zeigen den Plugin-Namen nicht; die Mails tragen Seitenname bzw. Absendername (`wpac_mail_from_name`, `wpac_mail`). Die Belege (Gutschrift, Korrektur, Storno) tragen im Kopf die Betreiberdaten aus der Einstellung (beim Erstellen eingefroren); einen Branding-Filter für Belege gibt es bewusst nicht, weil Belege aus den gespeicherten Daten byteweise gleich neu erzeugt werden müssen (Prüfsumme). In den PDF-Metadaten steht als „Producer“ weiterhin `Affiliate Captain`.

**Hinweise auf das Add-on im Kernplugin** (WordPress.org-Richtlinie 11): derzeit keine. Falls künftig gewünscht, nur dezent, wegklickbar und nur auf den eigenen Plugin-Seiten; keine Menüpunkte, Schalter oder Felder, die sichtbar sind, aber erst nach einem Kauf funktionieren.

## Auszahlung über Zahlungsdienste (seit 1.3.0)

Für Add-ons, die Auszahlungen über einen Zahlungsdienst ausführen (z. B. PayPal Payouts, Stripe, Wise). Sie lesen die Auszahlung, **reservieren** sie, zahlen sie beim Dienst aus und melden das Ergebnis. Die Reservierung hat dieselben Schutzregeln wie die Exportdateien, damit eine Auszahlung nie doppelt ausgezahlt wird.

| Funktion | Zweck |
|---|---|
| `wpac_get_payout( $payout_id )` | Auszahlung als Array oder `null`: `id`, `number`, `affiliate_id`, `status` (`pending`, `exported`, `paid`, `cancelled`), `currency`, `amount_minor`, `method` (`sepa`/`manual`), `paypal_email`, `claim`, `created_at`, `exported_at`, `paid_at`. `paypal_email` ist nur bei der Auszahlungsart „andere“ gefüllt, wenn die Zahlungsangaben eine E-Mail-Adresse enthalten (dieselbe Regel wie die PayPal-Datei), und nur solange die Auszahlung offen ist. Bankdaten werden nie geliefert. |
| `wpac_get_payouts( $args )` | Liste, älteste zuerst. `status`, `method`, `affiliate_id`, `after_id`, `limit` (1–200, Standard 50). Blättern mit `after_id` (ID der letzten Auszahlung der vorigen Seite; liefert nur Auszahlungen mit größerer ID), bis die Liste leer ist. Der Filter `method` wirkt vor dem Limit: eine Seite enthält bis zu `limit` passende Auszahlungen. Ein unbekannter `status` oder `method` liefert eine leere Liste (kein `WP_Error`). |
| `wpac_claim_payout( $payout_id, $provider )` | Reserviert eine **erstellte** Auszahlung für den Dienst (`$provider`: `a–z`, `0–9`, `_`, `-`, 2–20 Zeichen). Ergebnis: die Auszahlung mit `claim` (`API-<DIENST>-<ID>-<Zufall>`), Status `exported`. Abgelehnt (`WP_Error`): `wpac_payout_state` (nicht mehr „erstellt“, z. B. schon exportiert oder reserviert), `wpac_profile_changed` (Partner hat die Auszahlungsdaten nach dem Erstellen geändert), `wpac_statement` (Gutschrift fehlt), `wpac_provider`. |
| `wpac_complete_payout( $payout_id, $claim, $reference )` | Nach erfolgreicher Zahlung: Auszahlung und Provisionen werden „bezahlt“, `wpac_payout_paid` und `wpac_commission_paid` feuern wie beim Markieren im Admin. Die Reservierung wird unter Zeilensperre geprüft. `$reference` (Transaktions- oder Batch-ID des Dienstes) steht im Audit-Log. Fehler: `wpac_claim`, `wpac_state`. |
| `wpac_release_payout( $payout_id, $claim, $reason )` | Wenn der Dienst nicht gezahlt hat: die Auszahlung ist wieder „erstellt“ und kann exportiert oder neu reserviert werden. Fehler: `wpac_claim`. |

Regeln:

- Erst reservieren, dann zahlen. Eine reservierte Auszahlung nehmen weder die Exportdateien (SEPA, Überweisungsliste, PayPal-Datei) – auch nicht mit „erneut exportieren“ (Fehler `wpac_claimed`) – noch eine zweite Reservierung.
- Die Reservierungsnummer beim Dienst als Idempotenz-Schlüssel verwenden (z. B. `sender_batch_id` bei PayPal), damit eine Wiederholung nach einem Abbruch nicht doppelt zahlt.
- Im Admin lässt sich eine reservierte Auszahlung in den ersten 45 Tagen nach der Reservierung nicht stornieren (Filter `wpac_claim_cancel_after`, Sekunden; Zahlungsdienste wie PayPal halten offene Auszahlungen bis zu 30–40 Tage); danach nur mit der Bestätigung, dass der Dienst nicht gezahlt hat – vorher im Konto des Zahlungsdienstes prüfen. Das Add-on gibt Auszahlungen, die der Dienst ablehnt, selbst mit `wpac_release_payout()` frei.
- Jeder Schritt steht im Audit-Log (`payout.claimed`, `payout.provider_paid`, `payout.claim_released`).

## Lese- und Schreib-API (1.3.0)

Öffentliche PHP-Funktionen für Add-ons (z. B. erweiterte REST-API, Webhooks, Dashboards), damit niemand die Tabellen lesen muss. Dünne Hüllen über die vorhandenen Repositories und Dienste – dieselben Regeln, E-Mails und Audit-Einträge wie im Admin. Rückgabe: Arrays mit den unten genannten Feldern (stabil innerhalb von Version 1, neue Felder kommen höchstens hinzu) oder `WP_Error`. Nie IBAN, Steuer-, Bewerbungs- oder Kundendaten; vom Partner nur die E-Mail-Adresse. Zeiten in UTC (`Y-m-d H:i:s`), Beträge in Cent.

**Rechte:** Die Funktionen prüfen den aktuellen Benutzer **nicht** (PHP-API für vertrauenswürdigen Code). REST-, AJAX- oder Admin-Hüllen eines Add-ons müssen selbst prüfen: `wpac_manage_affiliates` für Partner und Provisionen; `wpac_manage_payouts`, wenn eine Provision vor Ende der Sperrfrist freigegeben wird (wie im Admin). Jede Schreibfunktion steht im Audit-Log mit dem aktuellen Benutzer als Akteur.

| Funktion | Rückgabe / Zweck |
|---|---|
| `wpac_get_affiliate( $id )` | Partner: `id`, `user_id`, `group_id`, `status` (`pending`, `active`, `paused`, `terminated`, `rejected`), `status_reason`, `slug`, `name`, `email`, `website`, `channel`, `created_at`, `updated_at`, `approved_at` (oder `null`). Unbekannt: `null` (wie `wpac_get_payout()`). |
| `wpac_get_affiliates( $args )` | `{ items, total, page, per_page }`, `items` wie oben. Argumente: `status`, `group` (Gruppen-ID, `0` = ohne Gruppe), `search` (Name, E-Mail, Slug, Website), `per_page` (1–100, Standard 20), `page` (ab 1), `orderby` (`id`, `name`, `status`, `created_at`), `order` (`asc`/`desc`, Standard `desc`). |
| `wpac_get_commission( $id )` | Buchung im Provisionskonto: `id`, `affiliate_id`, `kind` (`sale`, `refund`, `cancellation`, `chargeback`, `bonus`, `writeoff`, `reinstatement`, `increase`, `adjustment`, `repayment`, `correction`), `parent_id`, `status` (`pending`, `review`, `approved`, `paid`, `rejected`), `currency`, `amount_minor`, `base_minor`, `rate_type`, `rate`, `rule_id`, `source`, `order_id`, `item_id`, `product_ref`, `description`, `hold_until`, `payout_id`, `created_at`, `updated_at`. Unbekannt: `null` (wie `wpac_get_payout()`). |
| `wpac_get_commissions( $args )` | `{ items, total, page, per_page }`. Argumente: `affiliate_id`, `status`, `kind`, `from`/`to` (`Y-m-d`, Tage der Website-Zeitzone), `per_page`, `page`, `orderby` (`id`, `created_at`, `amount_minor`, `status`), `order`. |
| `wpac_update_affiliate( $id, $data )` | Ändert `name`, `website`, `channel`, `group` (Gruppen-ID, `0` = keine) mit der Prüfung des Admin-Formulars (Längen, URL, Gruppe vorhanden); andere Schlüssel → `wpac_invalid_field`. Rückgabe: der Partner. Audit `affiliate.updated`. |
| `wpac_set_affiliate_status( $id, $status, $reason )` | Statuswechsel wie im Admin (Freischalten inkl. Benutzerkonto und Code, Pausieren, Beenden inkl. Regel für offene Provisionen, Ablehnen; E-Mails; `wpac_affiliate_status_changed`). Grund Pflicht für `paused`, `terminated`, `rejected`. Rückgabe: der Partner. Audit `affiliate.status`. |
| `wpac_set_commission_status( $id, $status, $reason )` | `approved` oder `rejected` über die Regeln des Kontos (Sperrfrist, Prüfung, Korrekturen, Auszahlungen; Ablehnen nur mit Grund). Eine Provision „Prüfung nötig“ in der Sperrfrist wird – wie im Admin – erst „ausstehend“. Rückgabe: die Provision. Audit `commission.status` (`from`, `to`, `reason`, `via: api`). |
| `wpac_create_adjustment( $affiliate_id, $amount_minor, $currency, $reason )` | Manuelle Buchung (Cent als Ganzzahl, ≠ 0, negativ = Abzug), sofort freigegeben und mit der nächsten Auszahlung fällig; `wpac_commission_created` feuert nach dem Commit. Rückgabe: die Buchung. Audit `commission.manual`. |

Die Listen prüfen streng (seit 0.13.0): IDs, `page` und `per_page` sind ganze Zahlen ≥ 0 bzw. ≥ 1 (Integer oder reine Ziffernfolge); `-5`, `"abc"`, `1.5` oder Arrays ergeben `wpac_invalid_<argument>` statt stillschweigend „alle“. Ein Array als Wert eines Arguments (z. B. `status`) ergibt ebenfalls `wpac_invalid_<argument>`. Eine Seite hinter dem Ende liefert leere `items` mit der richtigen `total` (auch bei sehr großen Werten wie `PHP_INT_MAX`).

Fehlercodes der Listen- und Schreibfunktionen: `wpac_not_found`, `wpac_invalid_<argument>` (z. B. `wpac_invalid_status`, `wpac_invalid_per_page`, `wpac_invalid_orderby`, `wpac_invalid_group`, `wpac_invalid_affiliate_id`, `wpac_invalid_website`), `wpac_invalid_field`, `wpac_invalid_name`, `wpac_too_long_<feld>`, `wpac_reason_required`, `wpac_amount`, `wpac_state`, `wpac_parent_open`, `wpac_conflict`, `wpac_restricted`, `wpac_db_error`, `wpac_affiliate_db` (`wpac_update_affiliate()`: Speichern in der Datenbank fehlgeschlagen, nichts geändert), `wpac_status_not_saved` (`wpac_set_affiliate_status()`: Status nicht gespeichert, nichts geändert). Die beiden letzten und `wpac_db_error` sind vorübergehend: erneut versuchen. Auszahlungen liest `wpac_get_payout()`/`wpac_get_payouts()` (Abschnitt „Auszahlung über Zahlungsdienste“).

## Mitgelieferte Adapter

| Plugin | Ort | Verhalten |
|---|---|---|
| WP Pass Captain | `src/Integrations/AffiliateCaptain/Adapter.php` (ab Pass Captain 0.9.0, nur über vorhandene Pass-Captain-Hooks) | Meldet bezahlte Bestellungen (Online-Checkout, Kasse, Abendkasse, Warteliste) als `source = pass-captain`. Bestellungen über WooCommerce werden übersprungen – die erfasst Affiliate Captain selbst. Optionales Feld „Partnercode“ im Checkout (geprüft, Fehlversuchs-Limit), Referral-Link/Cookie wird mit der Bestellung gespeichert; Kasse/Abendkasse übernehmen nie das Referral der Mitarbeitenden. Basis je Position: bezahlter Anteil (nach Kampagnenrabatt und eingelöstem Gutschein) ohne gespeicherte USt; SKU `PASS-E{Event}-T{Ticketart}` für Regeln (z. B. `PASS-E12-*`); Provision bis Terminende gesperrt (`hold_until`). Erstattungen (auch Teil- und Terminabsage-Erstattungen) → anteilige Umkehr; Vollerstattung/Storno → Umkehr des Rests. |
| Voucher Captain | `src/Compat/AffiliateCaptain.php` (ab Voucher Captain 1.2.0) | Ordnet ganze Code-Kampagnen (Gutscheine → Codes erzeugen → Kampagnen → Partner) einem Partnercode zu und beantwortet `wpac_coupon_affiliate` (Priorität 20 – direkte Gutschein-Verknüpfung in Affiliate Captain gewinnt). Gekaufte Gutscheine/Geschenkkarten werden nie zugeordnet. Optional keine Provision auf Gutscheinverkäufe: `add_filter( 'vcap_affiliate_commission_on_voucher_sales', '__return_false' );`. |


## Geplante Review-Captain-Anbindung (read-only, noch nicht Teil von Vertrag v1.3)

Review Captain darf keine Provisionen verändern und keine Einladungen aus Affiliate-Ereignissen auslösen. Eine spätere Verbindung darf ausschließlich aggregierte, freigegebene Review-Kennzahlen einer Partner-/Kampagnenreferenz liefern. Der bestehende Vertrag veröffentlicht keine Review-Aggregatfunktion und keine Review-zu-Partner-Zuordnung. Vor einer Umsetzung sind Mindeststichprobe, Zeitraum, erlaubte Kennzahlen, Rollen-/Capability-Prüfung, Datenschutz-/Löschfolgen und Vermeidung von Rückschlüssen auf einzelne Käufer festzulegen. Einzelne Reviews, Freitext, Empfänger- oder Kundendaten werden nicht offengelegt.
