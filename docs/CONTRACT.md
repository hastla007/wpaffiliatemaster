# Integrationsvertrag v1 (`WPAC_CONTRACT_VERSION = 1.5.0`)

Öffentliche Schnittstelle von Affiliate Captain für Verkaufsquellen (Pass Captain, Voucher Captain, WooCommerce-Adapter, Connector-Add-ons wie LearnBoating). Ein Projekt-Connector (z. B. für BoatingSchool oder LearnBoating, Entscheidung 17) ist ein eigenes Plugin und bringt seine Lizenz und deren Prüfung selbst mit; der Kern prüft keine Lizenz. Kompatibel ist jede Version mit gleicher Major-Version; andere Versionen werden abgelehnt (`incompatible_contract`) und im Integrationsstatus angezeigt.

Versionsregel: Die Vertragsversion folgt SemVer und ist unabhängig von der Plugin-Version. Neue abwärtskompatible Funktionen, Actions, Filter, Argumente oder Felder erhöhen die Minor-Version; eine Änderung, die bestehende Aufrufer bricht, erhöht die Major-Version; reine Korrekturen die Patch-Version. Angaben wie „seit 1.3.0“ meinen die Vertragsversion, „seit Plugin 0.15.0“ die Plugin-Version. Ein Add-on, das eine bestimmte Funktion braucht, prüft sie mit `function_exists()` oder vergleicht `wpac_contract_version()` mit `version_compare()`.

Änderungen: **1.5.0** (Plugin 0.16.0, abwärtskompatibel) – Erweiterungspunkte für mehrere Partnerprogramme, Variante A „Programm = Partnergruppe“ (Abschnitt „Partnerprogramme (seit Vertrag 1.5.0)“): Filter `wpac_setting` mit Kontext (Partner, Gruppe, Programm) für Standardsatz, Sperrfrist, Cookie-Laufzeit, Zuordnungsmodell, Mindestauszahlung und Bedingungsseite; Funktion `wpac_affiliate_program()` mit Filter `wpac_affiliate_program`; Feld `program` in `wpac_get_affiliate(s)()` und `wpac_get_commission(s)()` und Argument `program` in beiden Listenfunktionen; Filter `wpac_program_groups`; Argument `$program` in `Reports::partners()`/`Reports::by_dimension()` und fünfter Parameter von `wpac_report_partners`; Filter `wpac_report_programs` (Programmauswahl der Admin-Berichte) und `wpac_portal_program_label`; dritter Parameter `$program` in `wpac_portal_tabs` und `wpac_portal_labels`, dritter Parameter `$affiliate_id` in `wpac_hold_until`; Action `wpac_affiliate_program_changed`; Filter `wpac_single_partner_coupon` und `wpac_is_self_recruitment`, weitere Aktionsschlüssel in `wpac_reauth_required` (Audit-Runde 15). Kein neues Datenbankschema. **1.4.0** (Plugin 0.15.0, abwärtskompatibel) – `wpac_create_adjustment()` mit optionalem fünftem Argument (`parent_id`, `reference`), Feld `reference` in `wpac_get_commission(s)`, neue Funktionen `wpac_portal_url()` und `wpac_mail_sender()`, Actions `wpac_creative_created`/`wpac_creative_updated` und `wpac_affiliate_recruited`, Feld und Argument `referred_by` in `wpac_get_affiliate(s)()`/`wpac_update_affiliate()`, Actions und Filter der Code-Anträge (`wpac_code_requested`, `wpac_code_request_approved`, `wpac_code_request_rejected`, `wpac_code_requests_per_month`, `wpac_code_request_max_length`, `wpac_reserved_partner_codes`), Filter `wpac_auto_apply_coupon` und die Erweiterungspunkte der Passwortbestätigung (`wpac_reauth_required`, `wpac_reauth_ttl`, `wpac_reauth_verify`, `wpac_reauth_form`); `wpac_code_request_rejected` mit viertem Parameter `$row_id`; alle Ereignisse der Tabelle unten feuern jetzt nachweislich erst nach dem Commit, auch innerhalb einer äußeren Transaktion; siehe die jeweiligen Abschnitte. Eine eigene Schnittstelle für Workflow Captain ist **nicht** Teil von 1.4.0 und von Plugin 0.15.0 (Abschnitt „Workflow Captain: keine eigene Schnittstelle in 0.15.0“). **1.3.0** – Auszahlung über Zahlungsdienste für Add-ons: `wpac_get_payout()`, `wpac_get_payouts()`, `wpac_claim_payout()`, `wpac_complete_payout()`, `wpac_release_payout()` (Abschnitt „Auszahlung über Zahlungsdienste“; abwärtskompatibel); dazu Lese- und Schreib-API für Partner und Provisionen (`wpac_get_affiliate()`, `wpac_get_affiliates()`, `wpac_get_commission()`, `wpac_get_commissions()`, `wpac_update_affiliate()`, `wpac_set_affiliate_status()`, `wpac_set_commission_status()`, `wpac_create_adjustment()`; Abschnitt „Lese- und Schreib-API (1.3.0)“), Portal-Hooks für eigenes Branding (`wpac_portal_before`/`_after`, `wpac_portal_classes`, `wpac_portal_labels`), Filter `wpac_mail_from_name` und Ereignisse: `wpac_commission_created` feuert jetzt für jede Buchung im Provisionskonto (auch Korrekturgutschrift, Abschreibung, Rückzahlung, Wiederherstellung, Bonuskorrektur; immer nach dem Commit), neu `wpac_payout_cancelled`. **Ergänzungen ohne Versionswechsel (abwärtskompatibel, Plugin 0.11.0)** – `wpac_record_reinstatement()` und `POST /reinstatements`; optionale Felder `netted_refund_ids`, `review`, `category_ancestor_ids`; Erstattungen zu unbekannten Bestellungen werden geparkt (`parked`) statt verworfen; strengere Prüfung der Payloads (siehe „Grenzen und Prüfungen“). **1.2.0** – Erweiterungspunkte für Add-ons (Abschnitt „Add-ons“: `wpac_admin_menu`, `wpac_portal_tabs`/`wpac_portal_tab_{key}`, `wpac_commission_amount`, `wpac_payout_created`, `wpac_transfer_rows`/`wpac_transfer_header`, `wpac_report_partners`, `wpac_program_export_files`; abwärtskompatibel). **1.1.0** – neue Funktion `wpac_current_touch()` (abwärtskompatibel; Payloads mit `contract => '1.0.0'` bleiben gültig).

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
| `wpac_hold_until` | `int $timestamp, array $sale, int $affiliate_id` | Spätestes Garantie-/Widerrufsende; nur spätere Daten verlängern. `$affiliate_id` (Partner der Bestellung, `0` = unbekannt) seit Vertrag 1.5.0. |
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
| `wpac_affiliate_recruited` | `int $affiliate_id, int $recruiter_id` – ein über den Empfehlungslink eines anderen aktiven Partners registrierter Partner ist aktiv: Registrierung mit automatischer Freischaltung oder Freischaltung der Bewerbung (erstmals aus `pending`/`rejected`). Einmal je Partner, nach dem Commit. Spätere Änderungen des Werbers (Admin, `wpac_update_affiliate()`) lösen es nicht aus. Für Sub-Affiliate-Add-ons (Mehrstufigkeit ist nicht Teil des Kerns; siehe „Werber (Sub-Affiliates)“). Seit Plugin 0.15.0. |
| `wpac_code_requested` | `int $affiliate_id, string $code, int $row_id` – ein Partner hat im Partnerbereich einen eigenen Code beantragt (Zeile in `partner_codes` mit Status `requested`, wird nie aufgelöst). Seit Plugin 0.15.0. |
| `wpac_code_request_approved` | `int $affiliate_id, string $code, int $row_id, bool $make_main` – Antrag freigegeben, der Code ist aktiv. Seit Plugin 0.15.0. |
| `wpac_code_request_rejected` | `int $affiliate_id, string $code, string $reason, int $row_id` – Antrag abgelehnt, die Zeile ist gelöscht, der Code wieder frei. Seit Plugin 0.15.0; `$row_id` (die gelöschte Zeile) seit 0.15.0. |
| `wpac_creative_created` | `int $creative_id, array $info` – ein neues Werbemittel wurde gespeichert (erste Version, Status `draft`; Partner sehen es erst nach der Freigabe). `$info`: `id`, `family_id`, `version`, `status` (`draft`, `approved`, `archived`), `type` (`text`, `image`), `title`, `group_id` (`0` = alle Partner). Seit Plugin 0.15.0. |
| `wpac_creative_updated` | `int $creative_id, array $info` (wie oben) – eine neue Version eines vorhandenen Werbemittels wurde gespeichert (`draft`), eine Version freigegeben (`approved`: ab jetzt für die Partner der Gruppe sichtbar – der richtige Zeitpunkt z. B. für eine Mail „neue Werbemittel“) oder archiviert (`archived`). Feuert nur nach erfolgreichem Speichern und nur bei einer echten Änderung; die beim Freigeben automatisch archivierte Vorversion meldet kein eigenes Ereignis. Seit Plugin 0.15.0. |

| `wpac_contract_not_queued` | `string $what` (`sale`/`refund`), `array $data` – Verkauf/Erstattung konnte weder verbucht noch für die Wiederholung gespeichert werden (z. B. Datenbank weg); die Quelle muss selbst erneut senden. Steht zusätzlich im Plugin-Log. |

Ereignisse sind Benachrichtigungen ohne Kundendaten (Ausnahmen: `wpac_contract_not_queued`, `wpac_mail_sent`/`_failed` und der Freitext `reason` bei Erstattungen, siehe „Review Captain: keine Einladungen, keine Käuferdaten“), kein Ersatz für die idempotenten Funktionen oben. Alle Ereignisse dieser Tabelle feuern erst nach dem Commit der Datenbanktransaktion – seit Plugin 0.15.0 auch, wenn die auslösende Funktion innerhalb einer äußeren Transaktion läuft (sie warten auf deren Commit); wird eine Buchung zurückgerollt, feuert nichts.

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
| `wpac_auto_apply_coupon` ( `$apply`, `$coupon_code`, `$affiliate_id` ) | Filter | Ob der verknüpfte WooCommerce-Gutschein des Partners nach einem Partnerlink automatisch in den Warenkorb gelegt wird (Einstellung „Gutschein des Partners nach einem Partnerlink automatisch einlösen“, ab Werk aus). Läuft erst, wenn alle Prüfungen bestanden sind (gültige Empfehlung dieser Sitzung, kein Eigenkauf, Partner aktiv, Gutschein gehört zum selben Partner); `false` verhindert das Einlösen. Die Gültigkeitsprüfung von WooCommerce folgt danach. Seit Plugin 0.15.0 (Vertrag bleibt 1.3.0). |
| `wpac_single_partner_coupon` ( `$single`, `$coupon_code` ) | Filter | Ob ein Warenkorb nur einen Partner-Gutschein (einen Gutschein, der einem Partner gutschreibt) enthalten darf. Standard `true`: Ein zweiter Partner-Gutschein wird über `woocommerce_coupon_is_valid` abgelehnt; der automatisch eingelöste Gutschein weicht einem vom Kunden eingegebenen Partner-Gutschein. Gilt auch ohne die Einstellung zum automatischen Einlösen. Seit Plugin 0.16.0. |
| `wpac_is_self_recruitment` ( `$self`, `$recruiter_id`, `$email`, `$user_id` ) | Filter | Ob ein Bewerber der werbende Partner selbst ist (zweites Partnerkonto); dann wird kein Werber gespeichert. Standard: Die Bewerbung kommt von Adresse und Browser einer aktuellen Anmeldung des WordPress-Kontos des Werbers (nur zum Vergleich gelesen, nichts gespeichert; private Adressen und vertrauenswürdige Proxys zählen nie). Läuft nur bei der Bewerbung, nachdem Benutzer und E-Mail-Adresse (auch die des WordPress-Kontos des Werbers) geprüft sind. Seit Plugin 0.16.0. |
| `wpac_code_requests_per_month` ( `$limit`, `$affiliate_id` ) | Filter | Code-Anträge eines Partners in 30 Tagen (Standard 3; abgelehnte und zurückgezogene zählen mit; immer nur ein offener Antrag). Seit Plugin 0.15.0. |
| `wpac_code_request_max_length` ( `$length` ) | Filter | Höchstlänge eines beantragten Codes (Standard 20, 3–64). Seit Plugin 0.15.0. |
| `wpac_reserved_partner_codes` ( `$words` ) | Filter | Codes, die Partner nicht beantragen können (zusätzlich zu den reservierten Namen des Referral-Parameters `wpac_reserved_referral_params`, `DEL-…` und `WPAC…`). Seit Plugin 0.15.0. |
| `wpac_portal_tabs` ( `$tabs`, `$aff`, `$program` ) | Filter | Wie oben; zusätzlich lassen sich die Kern-Tabs `overview`, `links`, `promotion`, `commissions`, `payouts` ausblenden, indem man sie entfernt (Auszahlungsdaten, Konto und Datenschutz bleiben immer). |
| `wpac_reauth_required` ( `$required`, `$action` ) | Filter | Ob eine Admin-Aktion eine frische Passwortbestätigung braucht (seit Plugin 0.15.0). Läuft für jede Aktion über `admin-post.php?action=wpac`; `$action` z. B. `bank_approve`, `user_link_manual`, `user_unlink`, `user_link_resend`, `profile_update`, `payout_bulk_sepa`, `payout_bulk_csv`, `payout_bulk_paypal`, `payout_bulk_paid`, `settings_reauth_off`, seit Plugin 0.16.0 auch `commission_manual`, `commission_status_approved` (Freigabe über `commission_status` mit `to=approved`), `payout_run`, `payout_request_process`, `balance_writeoff`, `repayment` und `affiliate_email_change` (`affiliate_update` mit geänderter E-Mail-Adresse), sonst der `do`-Wert. Standard: `true` für diese Aktionen, solange die Einstellung „Passwort erneut abfragen“ an ist. WP-CLI und REST-API sind nicht betroffen. |
| `wpac_reauth_ttl` ( `$seconds`, `$user_id` ) | Filter | Gültigkeit einer Passwortbestätigung in der Login-Sitzung (Standard 900 s, 0 = jede Aktion fragt). Seit Plugin 0.15.0. |
| `wpac_reauth_verify` ( `$result`, `$user` ) | Filter | Zweiter Faktor der Passwortbestätigung für 2FA-Plugins ohne Two-Factor-API (Wordfence, miniOrange …): `true` annehmen, `WP_Error` (Meldung wird gezeigt) oder `false` ablehnen. Läuft nach korrektem Passwort; `$result` ist das Ergebnis des Two-Factor-Plugins, falls aktiv; die Formularfelder stehen in `$_POST`. Seit Plugin 0.15.0. |
| `wpac_reauth_form` ( `$user` ) | Action | Zusätzliche Felder im Bestätigungsformular ausgeben (z. B. Codefeld, das in `wpac_reauth_verify` geprüft wird). Seit Plugin 0.15.0. |
| `wpac_minimum_db_versions` ( `$versions` ) | Filter | Mindestversionen des Datenbankservers, ab denen Website-Zustand nicht warnt: `array( 'mysql' => '9.7', 'mariadb' => '12.3' )` (Konstanten `WPAffiliateCaptain\Core\Requirements::MYSQL`/`MARIADB`). Ungültige Werte (keine Versionsnummer) werden ignoriert. Ein älterer Server wird nie blockiert, nur gemeldet (Prüfung `db_server`, Status „empfohlen“, keine Alarm-Mail). Seit Plugin 0.16.0. |

**Plugin-Log:** WooCommerce → Status → Protokolle, Quelle `affiliate-captain` (bis 0.10 `wp-affiliate-captain`); ohne WooCommerce das PHP-Fehlerprotokoll (`Affiliate Captain error: …`). Einträge zu Exceptions enthalten Klasse, Fehlercode, Datei und Zeile, nie personenbezogene Daten.

## Add-ons (z. B. Premium-Add-on)

Erweiterungen sind **eigene Plugins** (eigener Slug, eigene Textdomain, bei Vertrieb außerhalb von WordPress.org eigener Header `Update URI`, eigener Updater und ggf. Lizenzprüfung). Das Kernplugin enthält keine Lizenzprüfung und sperrt keine Funktionen (siehe `docs/FREE-PREMIUM.md`). Ein Add-on startet auf `wpac_loaded` und nutzt diese Erweiterungspunkte:

| Hook | Art | Zweck |
|---|---|---|
| `wpac_loaded` | Action | Kernplugin ist geladen – hier Hooks des Add-ons registrieren. |
| `wpac_admin_menu` ( `$parent` ) | Action | Eigene Verwaltungsseiten unter dem Plugin-Menü (`add_submenu_page( $parent, … )`, Parent-Slug `wpac`). Eigene Einstellungen speichert das Add-on in eigenen Optionen. |
| `wpac_portal_tabs` ( `$tabs`, `$aff`, `$program` ) | Filter | `$program` (Programm des Partners) seit Vertrag 1.5.0. Weitere Tabs im Partnerbereich (`key => Label`); bestehende Tabs lassen sich nicht ersetzen. Ein Tab erscheint nur, wenn `wpac_portal_tab_{key}` belegt ist. |
| `wpac_portal_tab_{key}` ( `$aff` ) | Action | Ausgabe des Add-on-Tabs (vollständig escapen). |
| `wpac_portal_before` / `wpac_portal_after` ( `$aff`, `$tab` ) | Action | Anfang bzw. Ende des Partnerbereichs innerhalb des Wrappers `div.wpac-portal` (vor den Hinweisen und Tabs bzw. nach dem Tab-Inhalt), z. B. Logo, Kopf- oder Fußzeile eines White-Label-Add-ons. Nur für eingeloggte Partner mit Zugang (nicht für die Anmeldung, Bewerbung in Prüfung oder abgelehnte Bewerbung). Ausgabe vollständig escapen. Seit 1.3.0. |
| `wpac_portal_classes` ( `$classes`, `$aff`, `$tab` ) | Filter | CSS-Klassen des Wrappers (z. B. eigenes Farbschema); `wpac-portal` bleibt immer, jede Klasse läuft durch `sanitize_html_class()`. Eigene Styles registriert das Add-on mit Abhängigkeit vom Stylesheet-Handle `wpac-portal` (`wp_enqueue_style( 'mein-brand', $url, array( 'wpac-portal' ) )`, z. B. in `wpac_portal_before`). Seit 1.3.0. |
| `wpac_portal_labels` ( `$labels`, `$aff`, `$program` ) | Filter | `$program` (Programm des Partners) seit Vertrag 1.5.0. Beschriftungen ersetzen: `tab_{key}` für die Kern-Tabs (`tab_overview`, `tab_statistics`, `tab_links`, `tab_promotion`, `tab_commissions`, `tab_payouts`, `tab_profile`, `tab_account`, `tab_privacy`) und `nav` für den zugänglichen Namen der Tab-Navigation. Nur Text (HTML wird entfernt), leere Werte zählen nicht. Seit 1.3.0. |
| `wpac_commission_amount` ( `$amount`, `$item`, `$affiliate_id`, `$rule` ) | Filter | Provision einer Position nach Anwendung der Regel (Cent, nie negativ; ausgeschlossene Positionen werden nicht gefiltert) – z. B. zusätzliche Provisionsregeln eines Add-ons. |
| `wpac_payout_created` ( `$payout_id`, `$affiliate_id` ) | Action | Auszahlung angelegt (nach dem Commit, Gutschrift erzeugt) – z. B. Auszahlung über einen anderen Zahlungsdienst und danach `PayoutService::mark_paid()`. Bei Auszahlungsläufen werden die Gutschriften in der Job-Warteschlange geschrieben; die Action feuert dann erst, wenn die Gutschrift vorliegt (Minuten später, aus dem Cron). |
| `wpac_statement_generated` ( `$payout_id`, `$path` ) / `wpac_storno_generated` ( `$payout_id`, `$path` ) | Action | Gutschrift bzw. Korrekturbeleg / Stornobeleg erzeugt und gespeichert (auch beim späteren Nachholen) – der Kern schickt den Beleg damit an den Partner (abschaltbar mit `wpac_notify_credit_note`). |
| `wpac_correction_generated` ( `$correction_id`, `$path` ) | Action | Korrekturgutschrift (`KG-…`) zu einer bezahlten Auszahlung erzeugt und gespeichert – der Kern schickt sie damit an den Partner (abschaltbar mit `wpac_notify_credit_note`). |
| `wpac_einvoice_xml` ( `$xml`, `$document`, `$row`, `$data` ) | Filter | Fertige E-Rechnung (UN/CEFACT CII, EN 16931; XRechnung 3.0, wenn deren Zusatzangaben vorliegen) einer Gutschrift, Korrekturgutschrift oder Stornierung, bevor sie heruntergeladen oder an die Partner-Mail angehängt wird. `$document`: `credit_note`, `cancellation` oder `correction`; `$row`: Auszahlung bzw. Korrekturgutschrift; `$data`: die Belegdaten (siehe `wpac_einvoice_data`). Rückgabe: XML-String. Das XML wird bei jedem Abruf aus denselben eingefrorenen Daten wie das PDF gebaut (nicht gespeichert); ein Filter, der Beträge ändert, macht XML und PDF widersprüchlich. Seit 0.14.0. |
| `wpac_einvoice_data` ( `$data`, `$document`, `$row` ) | Filter | Belegdaten der E-Rechnung vor dem Bau des XML (`EInvoice::xml()`): `number`, `type` (389/384/381), `issue_date`, `currency`, `notes`, `buyer_reference`, `seller` (u. a. `phone`, `email`), `buyer`, `period`, `lines` (`name`, `note`, `net` in Cent, `category`, `rate`), `taxes`, `exemptions`, `prepaid`, `payment` (`code`, `info`, `iban`, `holder`, `bic`), `terms`, `preceding`. Typische Nutzung: Telefonnummer des Partners (dann XRechnung-Profil) oder IBAN (dann Zahlungsart 58) ergänzen. Seit 0.14.0. |
| `wpac_statement_dir` ( `$dir` ) / `wpac_statement_locale` ( `$locale`, `$site_locale` ) | Filter | Belegordner (z. B. gemeinsamer Speicher mehrerer App-Server) bzw. Belegsprache. |
| `wpac_statement_store` ( `$default` ) | Filter | Speicher der Beleg-PDFs (Gutschrift, Korrektur-, Stornobeleg): Objekt, das `WPAffiliateCaptain\Payouts\Storage\StatementStore` implementiert (`put`, `get`, `exists`, `delete`, `list_older_than`, `last_error`, `id`, `label`). Standard: S3-kompatibler Objektspeicher bei vollständigen `WPAC_S3_*`-Konstanten, sonst der lokale Ordner (`LocalStore`). Muss auf allen App-Servern gleich sein. Seit Plugin 0.16.0 läuft der Filter beim Löschen der aufbewahrten Belege einer gelöschten Site (nach Ablauf der Frist, täglicher Lauf und `tools/purge-retained.php`) mit `switch_to_blog()` auf diese Site; die zurückgegebene `id()` muss der beim Löschen der Site gemerkten entsprechen, sonst bleibt der Eintrag stehen und wird erneut versucht. Mit Objektspeicher erhalten `wpac_statement_generated`, `wpac_storno_generated` und `wpac_correction_generated` als `$path` eine private temporäre Kopie, die am Ende des Requests gelöscht wird. |
| `wpac_small_business_limits` ( `$limits`, `$operator_country` ) / `wpac_vies_timeout` / `wpac_vies_daily_cap` | Filter | Kleinunternehmergrenzen je Währung (Cent: Vorjahr, laufendes Jahr), VIES-Timeout und Tageslimit der Nachprüfungen. |
| `wpac_transfer_rows` ( `$rows`, `$batch`, `$payout_ids` ) / `wpac_transfer_header` ( `$header` ) | Filter | Zusätzliche Spalten der Überweisungsliste (Werte am Zeilenende, Spaltennamen am Ende des Kopfs). Der Standardkopf ist übersetzt; der Betrag (Spalte 5) wird beim Download mit Dezimalkomma ausgegeben, die Methode (Spalte 8) als lesbare Bezeichnung – die Zeilen im Filter enthalten weiterhin `1234.50` und `sepa`/`manual`. |
| `wpac_report_partners` ( `$rows`, `$from`, `$to`, `$affiliate_id`, `$program` ) | Filter | Zusätzliche Kennzahlen im Partnerbericht. `$program` (Programm des Berichts, `null` = alle) seit Vertrag 1.5.0. |
| `wpac_program_export_files` ( `$files`, `$include_tax_data` ) | Filter | Zusätzliche Dateien im Programm-Export (`Dateiname => Inhalt`); Dateien des Kerns lassen sich nicht ersetzen. |
| `wpac_uninstall_store_budget` ( `$seconds` ) | Filter | Zeitbudget der Deinstallation pro Site für das Löschen der Beleg-PDFs im Objektspeicher bzw. im eigenen Speicher (`wpac_statement_store`), Sekunden, 0 = unbegrenzt; Standard 20, höchstens 30 % von `max_execution_time`. Gelöscht wird vor dem Entfernen der Tabellen, im Objektspeicher gebündelt (S3 DeleteObjects, bis 1000 je Anfrage, 5 s Timeout). Was nicht gelöscht wurde, steht in der Option `wpac_uninstall_store_left` und wird von `tools/purge-retained.php`, einer erneuten Deinstallation oder dem täglichen Lauf einer neuen Installation nachgeholt. Seit Plugin 0.16.0. |
| `wpac_retained_purge_budget` ( `$seconds` ) | Filter | Zeitbudget des täglichen Laufs für das Löschen aufbewahrter Belege gelöschter Sites aus dem Speicher (Sekunden, Standard 20, 0 = unbegrenzt). Der Eintrag in `wpac_retained_sites` wird erst nach vollständigem Löschen entfernt; Fehlversuche werden gezählt und in Website-Zustand gemeldet. Seit Plugin 0.16.0. |
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
| `wpac_get_affiliate( $id )` | Partner: `id`, `user_id`, `group_id`, `status` (`pending`, `active`, `paused`, `terminated`, `rejected`), `status_reason`, `slug`, `name`, `email`, `website`, `channel`, `created_at`, `updated_at`, `approved_at` (oder `null`), `referred_by` (ID des werbenden Partners, `0` = keiner; seit Plugin 0.15.0), `program` (Programmschlüssel, `''` = Standardprogramm; seit Vertrag 1.5.0). Unbekannt: `null` (wie `wpac_get_payout()`). |
| `wpac_get_affiliates( $args )` | `{ items, total, page, per_page }`, `items` wie oben. Argumente: `status`, `group` (Gruppen-ID, `0` = ohne Gruppe), `referred_by` (Partner-ID des Werbers: dessen geworbene Partner; `0` = ohne Werber; seit Plugin 0.15.0), `program` (Programmschlüssel; `''` = Standardprogramm, weglassen = alle; seit Vertrag 1.5.0), `search` (Name, E-Mail, Slug, Website), `per_page` (1–100, Standard 20), `page` (ab 1), `orderby` (`id`, `name`, `status`, `created_at`), `order` (`asc`/`desc`, Standard `desc`). |
| `wpac_get_commission( $id )` | Buchung im Provisionskonto: `id`, `affiliate_id`, `kind` (`sale`, `refund`, `cancellation`, `chargeback`, `bonus`, `writeoff`, `reinstatement`, `increase`, `adjustment`, `repayment`, `correction`), `parent_id`, `status` (`pending`, `review`, `approved`, `paid`, `rejected`), `currency`, `amount_minor`, `base_minor`, `rate_type`, `rate`, `rule_id`, `source`, `order_id`, `item_id`, `product_ref`, `description`, `hold_until`, `payout_id`, `created_at`, `updated_at`, `reference` (seit Plugin 0.15.0; Bezug einer Buchung von `wpac_create_adjustment()`, sonst leer). `parent_id` ist die korrigierte Provision bzw. – bei einer Buchung von `wpac_create_adjustment()` mit `parent_id` – die verknüpfte Provision (nur zur Information, siehe unten). Unbekannt: `null` (wie `wpac_get_payout()`). |
| `wpac_get_commissions( $args )` | `{ items, total, page, per_page }`. Argumente: `affiliate_id`, `status`, `kind`, `from`/`to` (`Y-m-d`, Tage der Website-Zeitzone), `program` (Programmschlüssel des Partners; `''` = Standardprogramm, weglassen = alle; seit Vertrag 1.5.0), `per_page`, `page`, `orderby` (`id`, `created_at`, `amount_minor`, `status`), `order`. |
| `wpac_update_affiliate( $id, $data )` | Ändert `name`, `website`, `channel`, `group` (Gruppen-ID, `0` = keine) und seit Plugin 0.15.0 `referred_by` (Partner-ID des Werbers, `0` = keiner) mit der Prüfung des Admin-Formulars (Längen, URL, Gruppe vorhanden; Werber: vorhandener Partner, nicht der Partner selbst, kein Kreis A→B→A, auch nicht über mehrere Stufen – sonst `wpac_invalid_recruiter` bzw. `wpac_recruiter_cycle`, dann wird nichts gespeichert); andere Schlüssel → `wpac_invalid_field`. Rückgabe: der Partner. Audit `affiliate.updated`, für den Werber `affiliate.referred_by` (`from`, `to`). |
| `wpac_set_affiliate_status( $id, $status, $reason )` | Statuswechsel wie im Admin (Freischalten inkl. Benutzerkonto und Code, Pausieren, Beenden inkl. Regel für offene Provisionen, Ablehnen; E-Mails; `wpac_affiliate_status_changed`). Grund Pflicht für `paused`, `terminated`, `rejected`. Rückgabe: der Partner. Audit `affiliate.status`. |
| `wpac_set_commission_status( $id, $status, $reason )` | `approved` oder `rejected` über die Regeln des Kontos (Sperrfrist, Prüfung, Korrekturen, Auszahlungen; Ablehnen nur mit Grund). Eine Provision „Prüfung nötig“ in der Sperrfrist wird – wie im Admin – erst „ausstehend“. Rückgabe: die Provision. Audit `commission.status` (`from`, `to`, `reason`, `via: api`). |
| `wpac_create_adjustment( $affiliate_id, $amount_minor, $currency, $reason, $args = array() )` | Manuelle Buchung (Cent als Ganzzahl, ≠ 0, negativ = Abzug), sofort freigegeben und mit der nächsten Auszahlung fällig; `wpac_commission_created` feuert nach dem Commit. Seit Plugin 0.15.0 optional `$args`: `parent_id` (ID einer vorhandenen Provision, auf die sich die Buchung bezieht, z. B. der Verkauf eines geworbenen Partners bei einer Team-Provision; auch eines anderen Partners) und `reference` (Text, höchstens 64 Zeichen, wird wie ein Textfeld bereinigt). Fehler: `wpac_invalid_parent_id` (unbekannt, keine ganze Zahl > 0), `wpac_invalid_reference`, `wpac_too_long_reference`, `wpac_invalid_field` (anderer Schlüssel), `wpac_invalid_args` (kein Array) – dann wird nichts gebucht. Rückgabe: die Buchung (mit `parent_id` und `reference`). Audit `commission.manual` (mit `linked` und `reference`). |

Die Listen prüfen streng (seit 0.13.0): IDs, `page` und `per_page` sind ganze Zahlen ≥ 0 bzw. ≥ 1 (Integer oder reine Ziffernfolge); `-5`, `"abc"`, `1.5` oder Arrays ergeben `wpac_invalid_<argument>` statt stillschweigend „alle“. Ein Array als Wert eines Arguments (z. B. `status`) ergibt ebenfalls `wpac_invalid_<argument>`. Eine Seite hinter dem Ende liefert leere `items` mit der richtigen `total` (auch bei sehr großen Werten wie `PHP_INT_MAX`).

Fehlercodes der Listen- und Schreibfunktionen: `wpac_not_found`, `wpac_invalid_<argument>` (z. B. `wpac_invalid_status`, `wpac_invalid_per_page`, `wpac_invalid_orderby`, `wpac_invalid_group`, `wpac_invalid_affiliate_id`, `wpac_invalid_website`, `wpac_invalid_referred_by`, `wpac_invalid_program`), `wpac_invalid_recruiter`, `wpac_recruiter_cycle` (seit Plugin 0.15.0), `wpac_invalid_field`, `wpac_invalid_name`, `wpac_too_long_<feld>`, `wpac_reason_required`, `wpac_amount`, `wpac_state`, `wpac_parent_open`, `wpac_conflict`, `wpac_restricted`, `wpac_db_error`, `wpac_affiliate_db` (`wpac_update_affiliate()`: Speichern in der Datenbank fehlgeschlagen, nichts geändert), `wpac_status_not_saved` (`wpac_set_affiliate_status()`: Status nicht gespeichert, nichts geändert). Die beiden letzten und `wpac_db_error` sind vorübergehend: erneut versuchen. Auszahlungen liest `wpac_get_payout()`/`wpac_get_payouts()` (Abschnitt „Auszahlung über Zahlungsdienste“).

### Bezug einer manuellen Buchung (seit Plugin 0.15.0)

`wpac_create_adjustment( $partner, 1500, 'EUR', 'Team-Provision', array( 'parent_id' => $provision_id, 'reference' => 'team:7' ) )` verknüpft die Buchung **nur zur Information** mit der Provision. Die Verknüpfung steht nicht in der Buchungsspalte `parent_id` (die bedeutet „Korrektur dieser Provision“ und steuert Erstattungen, Auszahlungsreife, Steuerbehandlung und Korrekturgutschriften), sondern als Ereignis `linked` der Buchung (`source_event_id` = `parent:<ID>`, `reason` = `reference`; in der Admin-Historie „Bezieht sich auf Provision #…“). Folgen:

- Partner, Währung und Status bestimmt allein der Aufruf: die Buchung gehört dem übergebenen Partner, ist sofort freigegeben und wartet nicht auf die verknüpfte Provision.
- Erstattungen, Stornos, Chargebacks und deren Rücknahme der verknüpften Provision lassen die Buchung unverändert (wie andere manuelle Buchungen, R12-N3) und werden nicht mit ihr verrechnet. Soll eine Team-Provision zurückgenommen werden, bucht das Add-on selbst einen Abzug (z. B. nach `wpac_commission_adjusted`).
- Auszahlung und Gutschrift sind dieselben wie bei einer Buchung ohne Bezug (kein Verweis auf eine frühere Gutschrift, Steuerbehandlung zum Buchungszeitpunkt).
- Lesen: `wpac_get_commission()`/`wpac_get_commissions()` liefern `parent_id` (die verknüpfte Provision) und `reference`. Die Löschung bzw. Pseudonymisierung (Art. 17, Aufbewahrungsfristen) leert `reference` wie andere Freitexte; die ID bleibt.

### Portal-URL und Absender für Add-on-Mails (seit Plugin 0.15.0)

| Funktion | Rückgabe / Zweck |
|---|---|
| `wpac_portal_url( $affiliate_id = 0 )` | URL des Partnerbereichs (Einstellung „Partnerbereich“). Mit Partner in dessen Sprache (Sprache des verknüpften Benutzerkontos, sonst Seitensprache; mit WPML/Polylang die übersetzte Seite) – derselbe Link wie in den Mails des Kerns. Ohne Partner (`0` oder unbekannte ID): Sprache der aktuellen Anfrage. Ist die Seite nicht veröffentlicht: Startseite. |
| `wpac_mail_sender( $type = '', $affiliate_id = 0 )` | `array( 'name' => …, 'email' => …, 'custom' => bool )` nach den Regeln der Kern-Mails: Name = Einstellung „Absendername“ nach dem Filter `wpac_mail_from_name` (erhält `$type` und `array( 'type', 'admin' => false, 'affiliate_id' )`), leer = Seitenname; Adresse = Einstellung „Absenderadresse“, sonst die Benachrichtigungsadresse des Programms bzw. die Admin-E-Mail der Website. `custom` = die Absenderadresse ist eingestellt – nur dann setzen die Kern-Mails einen `From`-Header (sonst entscheidet WordPress bzw. das SMTP-Plugin); ein Add-on, das sich genauso verhalten will, setzt `From` nur bei `custom`. Name und Adresse sind headersicher (keine Zeilenumbrüche, spitzen Klammern, Anführungszeichen). |

Beide Funktionen sind wie alle Vertragsfunktionen mit `function_exists()` geschützt und erst nach `wpac_loaded` aufzurufen.

### Werber (Sub-Affiliates, seit Plugin 0.15.0)

Der Kern speichert, welcher Partner einen neuen Partner geworben hat (`referred_by`), und meldet es mit `wpac_affiliate_recruited`; Provisionen auf mehreren Stufen (MLM) bucht er nicht – das ist Sache eines Add-ons (z. B. über `wpac_commission_created` und `wpac_create_adjustment()`).

- **Erfassung bei der Registrierung:** Ein Partnercode bzw. Vanity-Slug im Empfehlungsparameter der Registrierungsseite (Einstellung „Referral-Parameter“, Standard `?partner=CODE`) oder – ohne Parameter – das erfasste Referral des Besuchers (Sitzung oder Cookie mit Einwilligung, wie bei Bestellungen; `wpac_current_touch()`). Nur aktive Partner ohne Verarbeitungseinschränkung; nie der Bewerber selbst (gleicher WordPress-Benutzer oder vergleichbare E-Mail-Adresse). Bei der Bestätigung der E-Mail wird der Werber erneut geprüft (inzwischen pausiert → kein Werber).
- **Ändern:** im Admin auf der Partnerseite (Profil, Feld „Geworben von“; Recht `wpac_manage_affiliates`) oder mit `wpac_update_affiliate( $id, array( 'referred_by' => $id ) )`; geprüft und protokolliert (`affiliate.referred_by`).
- **Lesen:** `wpac_get_affiliate()['referred_by']`, geworbene Partner mit `wpac_get_affiliates( array( 'referred_by' => $werber_id ) )`.
- **Datenschutz:** Die Auskunft (Art. 15) nennt die Partner-ID des Werbers (keine Daten des Werbers). Die Löschung (Art. 17) behält die ID als pseudonyme Verknüpfung – bei dem geworbenen wie bei dem werbenden Partner; Name, E-Mail usw. werden wie bisher gelöscht.

## Partnerprogramme (seit Vertrag 1.5.0)

Variante A aus `docs/MULTI-PROGRAM.md`: **Ein Programm ist eine Partnergruppe**, jeder Partner gehört zu genau einem Programm. Der Kern kennt keine Programmnamen und keine Lizenz; ein Add-on (z. B. `affiliate.multiple_programs` in captain-pro) legt fest, welche Werte je Programm abweichen. Ohne Callback auf den neuen Filtern verhält sich der Kern wie bisher. Kein neues Datenbankschema; es gibt weiter einen Cookie (`wpac_ref`), eine Zuordnung je Bestellung und Auszahlungen, Guthaben und Belegnummern je Partner und Währung.

**Programmschlüssel:** `group:<Gruppen-ID>` für eine Partnergruppe, `''` für das Standardprogramm (Partner ohne Gruppe). Höchstens 40 Zeichen aus `a–z`, `A–Z`, `0–9`, `:`, `_`, `.`, `-`.

| Hook / Funktion | Typ | Zweck |
|---|---|---|
| `wpac_affiliate_program( $affiliate_id )` | Funktion | Programm eines Partners (`group:<ID>` bzw. `''`; unbekannter Partner: `''`). |
| `wpac_affiliate_program` ( `$program`, `$affiliate_id`, `$group_id` ) | Filter | Programm eines Partners anders zuordnen. Ungültige Schlüssel werden verworfen (dann gilt der Standard). Wer Partner anderen Schlüsseln als `group:<ID>` zuordnet, muss mit `wpac_program_groups` angeben, welche Gruppen dazugehören. |
| `wpac_setting` ( `$value`, `$key`, `$context` ) | Filter | Wert einer Einstellung für einen Partner, eine Gruppe oder ein Programm. `$context` = `array( 'affiliate_id' => int, 'group_id' => int, 'program' => string )` (vom Kern ergänzt: Gruppe und Programm des Partners, Programm der Gruppe). Unbekannte Schlüssel und Programme unverändert zurückgeben. Typ wie in den Einstellungen (Zahlen als `int`, Sätze als Zeichenkette). |
| `wpac_program_groups` ( `$group_ids`, `$program` ) | Filter | Gruppen eines Programms (für die Listenfilter `program` und die Programmberichte). Standard: `group:<ID>` → `array( ID )`, `''` → `array( 0 )`, sonst leer. |
| `wpac_report_programs` ( `$programs` ) | Filter | Auswahl „Programm“ in den Admin-Berichten (Dashboard und CSV-Export): `array( Schlüssel => Name )`. Leer (Standard) = keine Auswahl. |
| `wpac_portal_program_label` ( `$label`, `$program`, `$aff` ) | Filter | Name des Programms im Partnerbereich (Übersicht „Ihre Konditionen“, Zeile „Partnerprogramm“). Leer (Standard) = keine Zeile. Nur Text. |
| `wpac_affiliate_program_changed` ( `$affiliate_id`, `$from`, `$to` ) | Action | Das Programm eines Partners hat sich geändert: Gruppenwechsel im Admin oder mit `wpac_update_affiliate( $id, array( 'group' => … ) )`, oder die Gruppe wurde gelöscht (Partner fallen ins Standardprogramm). Feuert nach dem Speichern, nur wenn sich der Programmschlüssel ändert; nicht beim Anlegen eines Partners. |

**Wo der Kern `wpac_setting` mit Kontext aufruft** (überall sonst gilt der Wert der Einstellungen):

| Schlüssel | Stelle | Kontext |
|---|---|---|
| `default_rate`, `default_rate_type` | Provisionsberechnung (Standardsatz, wenn keine Regel greift), Konditionen im Partnerbereich, Regeltest im Admin | Partner der Bestellung. Ein fester Betrag gilt in Minor Units der Programmwährung. Unbrauchbare Werte (Typ nicht `percent`/`fixed`, Satz nicht numerisch oder negativ) → Wert der Einstellungen. |
| `hold_days` | Sperrfrist neuer Provisionen (`hold_until`), Neustart der Sperrfrist bei Abschluss der WooCommerce-Bestellung, Konditionen im Partnerbereich; Monatsbonus (`TierService::is_due( $month, $affiliate_id )`): ein Partner mit **längerer** Sperrfrist seines Programms erhält seinen Bonus erst nach dieser Frist (seit Plugin 0.16.0) | Partner der Bestellung bzw. des Bonus |
| `cookie_days` | Laufzeit des Cookies beim Setzen, serverseitiges Zuordnungsfenster von Cookie und Sitzung, Konditionen im Partnerbereich; Datenschutztexte (seit Plugin 0.16.0): weicht die Laufzeit eines Programms ab, nennen Datenschutzerklärung und Verarbeitungsverzeichnis die Spanne (kürzeste bis längste Laufzeit) | Partner des Links bzw. des gespeicherten Referrals; Datenschutztexte: Standardprogramm, Programm jeder Partnergruppe und die Programme aus `wpac_report_programs` (ohne Partner) |
| `touch_model` | Erstes/letztes Referral: entscheidet das Modell des Programms des **früheren** Referrals, ob es erhalten bleibt; gespeichert in der Zuordnung | Partner des früheren Referrals bzw. Gewinner der Zuordnung |
| `min_payout_minor` | Auszahlungslauf (Vorschau und Erstellen), Auszahlungsantrag, Hinweis „gesperrte Auszahlungen“, Konditionen im Partnerbereich, REST `GET /me`; seit Plugin 0.16.0 auch die Anzeige im Admin: Partnerseite (Konto, nur wenn der Wert abweicht) und Hinweis „Mindestauszahlung“ beim Auszahlungslauf (abweichende Werte der Programme aus `wpac_report_programs` in Klammern) | Partner; Anzeige beim Auszahlungslauf: Programm (ohne Partner) |
| `terms_page_id`, `terms_version` | Aktuelle Teilnahmebedingungen (`Agreements::current()`): Bewerbung, Partnerbereich, Admin; eine eigene Bedingungsseite der Gruppe geht weiter vor | Gruppe (`group_id`, Programm der Gruppe; ohne Partner) |

**Programm-Filter in Listen und Berichten:** `wpac_get_affiliates( array( 'program' => 'group:3' ) )` und `wpac_get_commissions( array( 'program' => '' ) )` (Standardprogramm); ungültiger Schlüssel → `wpac_invalid_program`. Das Feld `program` einer Provision ist das **aktuelle** Programm des Partners (Variante A speichert kein Programm an der Buchung); nach einem Gruppenwechsel zählen frühere Buchungen zum neuen Programm. Dasselbe gilt für `Reports::partners( $from, $to, 0, $program )`, `Reports::by_dimension( $dimension, $from, $to, $program )` und `Reports::cached( $kind, $from, $to, $program )` (`null` = alle Programme). Seit Plugin 0.16.0 nimmt auch die REST-Route `GET /wpac/v1/reports/{partners|content|campaign}` das Argument `program` (geprüft wie die Listenfunktionen, ungültig → `wpac_invalid_program`, HTTP 400; ohne Argument alle Programme, Antwort unverändert; mit Argument enthält die Antwort zusätzlich `program`), und die Kennzahl „Aktive Partner“ im Dashboard zählt die Partner des gewählten Programms.

**Grenzen von Variante A:** Die Monatsbonus-Abrechnung schließt einen Monat weiter nach der Sperrfrist der Einstellungen; Partner, deren Programm eine **längere** Sperrfrist hat, werden dabei übersprungen und von der täglichen Nachberechnung gebucht, sobald ihre Frist abgelaufen ist (Option `wpac_tier_deferred`); eine **kürzere** Sperrfrist eines Programms schließt den Monat nicht früher. Die Datenschutztexte nennen bei abweichenden Cookie-Laufzeiten nur die Spanne, nicht die Laufzeit je Programm; Programme, die weder eine Partnergruppe sind noch in `wpac_report_programs` stehen, kennt der Kern dafür nicht. Die Anzeige der Mindestauszahlung beim Auszahlungslauf nennt nur Programme aus `wpac_report_programs`. Die Bewerbung wählt das Programm über die vorhandene Gruppenwahl je Formular (Block bzw. Shortcode mit `group`, Formulargruppen; siehe Handbuch).

```php
add_filter( 'wpac_setting', function ( $value, $key, $context ) {
    if ( 'group:3' === $context['program'] ) {
        $own = array( 'default_rate' => '15', 'hold_days' => 45, 'cookie_days' => 60, 'min_payout_minor' => 2500 );
        if ( isset( $own[ $key ] ) ) {
            return $own[ $key ];
        }
    }
    return $value;
}, 10, 3 );
```

## Mitgelieferte Adapter

| Plugin | Ort | Verhalten |
|---|---|---|
| WP Pass Captain | `src/Integrations/AffiliateCaptain/Adapter.php` (ab Pass Captain 0.9.0, nur über vorhandene Pass-Captain-Hooks) | Meldet bezahlte Bestellungen (Online-Checkout, Kasse, Abendkasse, Warteliste) als `source = pass-captain`. Bestellungen über WooCommerce werden übersprungen – die erfasst Affiliate Captain selbst. Optionales Feld „Partnercode“ im Checkout (geprüft, Fehlversuchs-Limit), Referral-Link/Cookie wird mit der Bestellung gespeichert; Kasse/Abendkasse übernehmen nie das Referral der Mitarbeitenden. Basis je Position: bezahlter Anteil (nach Kampagnenrabatt und eingelöstem Gutschein) ohne gespeicherte USt; SKU `PASS-E{Event}-T{Ticketart}` für Regeln (z. B. `PASS-E12-*`); Provision bis Terminende gesperrt (`hold_until`). Erstattungen (auch Teil- und Terminabsage-Erstattungen) → anteilige Umkehr; Vollerstattung/Storno → Umkehr des Rests. |
| Voucher Captain | `src/Compat/AffiliateCaptain.php` (im Code von Voucher Captain `main` seit dem 11.11.2026 vorhanden, Plugin-Header dort 1.1.0; der Changelog führt den Adapter noch unter „Unreleased“, er erscheint also mit dem ersten Release nach 1.1.0) | Ordnet ganze Code-Kampagnen (Gutscheine → Codes erzeugen → Kampagnen → Partner) einem Partnercode zu und beantwortet `wpac_coupon_affiliate` (Priorität 20 – direkte Gutschein-Verknüpfung in Affiliate Captain gewinnt). Gekaufte Gutscheine/Geschenkkarten werden nie zugeordnet. Optional keine Provision auf Gutscheinverkäufe: `add_filter( 'vcap_affiliate_commission_on_voucher_sales', '__return_false' );`. |

### Eine Provision je Bestellung über alle Quellen

Gebucht wird höchstens einmal je `source` + `order_id` (eindeutiger Schlüssel der Zuordnung) und je Position (`idempotency_key` der Provisionszeile) – gleich, ob der Verkauf über `wpac_record_sale()`, `do_action( 'wpac_v1_record_sale' )` oder `POST /sales` kommt. `source` wird klein geschrieben (`sanitize_key`), `order_id` ohne umgebende Leerzeichen verglichen. Jede weitere Meldung liefert `duplicate` und ändert weder Partner noch Betrag (auch mit anderem `partner_code` oder anderem `idempotency_key`).

Über **verschiedene** Quellen dedupliziert der Ledger nicht: Dieselbe Kaufhandlung darf nur eine Quelle melden. Deshalb überspringt Pass Captain WooCommerce-Bestellungen (`wpcap_affiliate_skip_channels`, Standard `woocommerce`; Kanal oder Zahlungsanbieter), und Voucher Captain meldet keine Verkäufe, sondern beantwortet nur `wpac_coupon_affiliate`. Eine WooCommerce-Bestellung mit Pass-Ticket und Partner-Gutschein wird so genau einmal (als `woocommerce`) provisioniert.

Nachgewiesen in `tests/integration/r15-l2.php` (`L2-DEDUP-a` bis `-d`): Partner-Gutschein über Voucher Captain (Priorität 20) und Vorrang der direkten Gutschein-Verknüpfung, mehrfacher Statuswechsel derselben WooCommerce-Bestellung, Wiederholung einer Pass-Captain-Meldung (gleiche Nutzlast, Zahl statt Text, anderer `idempotency_key`, Hook-Variante, anderer Partnercode), WooCommerce-Bestellung mit Pass-Ticket, dieselbe externe Bestellung über REST und PHP in beiden Reihenfolgen.

## Anbindungen der Suite: Stand und Entscheidungen

Stand 16.11.2026: Die gemeinsame Spezifikation der Suite für Form Captain, Review Captain und Workflow Captain liegt als Entwurf vor und ist noch nicht abgenommen (Repository `wp-workflowmaster`, `docs/SPEC-suite-events.md`). Entschieden ist (Inhaber, 16.11.2026; `TODO.md`, „Offene Entscheidungen“): Jeder Kern führt eine eigene versionierte Vertragskonstante; dieser Vertrag mit `WPAC_CONTRACT_VERSION` und der Versionsregel am Anfang ist das Muster dafür. Der Transport sind die vorhandenen fachlichen PHP-Actions derselben Installation (Abschnitt „Ereignisse“ oben); der Abnehmer in Workflow Captain baut daraus seinen Umschlag, speichert und wiederholt, die Kerne bleiben unverändert. Nutzdaten dürfen öffentliche Kennungen, die Benutzer-ID, den öffentlichen Partnercode und Geldbeträge enthalten, aber keine geheimen Codes und keine E-Mail-Adressen. Die Kerne behalten ihre eigenen Mails. Ein Workflow darf in der ersten Version keine Provision und keine Korrekturbuchung buchen und keine Auszahlung auslösen. Der Kern prüft keine Rechte; ob ein Abnehmer der Hooks bezahlt ist, entscheidet allein dessen Add-on.

Die Abschnitte unten sagen, was für Workflow Captain gilt, und nennen Leitplanken und Vorschläge für Review Captain und Form Captain.

## Workflow Captain: keine eigene Schnittstelle in 0.15.0

Plugin 0.15.0 und die Verträge 1.4.0 und 1.5.0 enthalten **keine** eigene Schnittstelle für Workflow Captain: keinen Umschlag, den der Kern baut, und keine eigenen Lesefunktionen für Workflows. Ein Entwurf dafür entstand vor den Entscheidungen des Inhabers und wurde vor dem Release wieder herausgenommen (Entscheidung vom 16.11.2026; `TODO.md`, Abschnitt „Entscheidung D13“). Er war nie Teil eines veröffentlichten Vertrags; eine Abkündigung ist deshalb nicht nötig.

Was ein Abnehmer heute nutzen kann, ist der Vertrag in diesem Dokument: die fachlichen Actions der Tabelle „Ereignisse“ (alle nach dem Commit) und die Lese-API (`wpac_get_affiliate(s)()`, `wpac_get_commission(s)()`, `wpac_get_payout(s)()`). Ein Adapter für Workflow Captain liegt im Add-on, nicht im Kern. Eine eigene Schnittstelle kommt erst wieder in den Kern, wenn die gemeinsame Spezifikation abgenommen ist (`TODO.md`, Aufgabe AFF-WF-08). Unabhängig davon gilt: Ereignisse sind Benachrichtigungen, buchen nie und starten keine Auszahlung; ein fehlender Abnehmer beeinflusst das Provisionskonto nicht.

## Review Captain: keine Einladungen, keine Käuferdaten

Review Captain darf keine Provisionen verändern und keine Einladungen aus Affiliate-Ereignissen auslösen – weder aus `wpac_commission_created`, `wpac_affiliate_attributed` noch aus einem Provisionsstatuswechsel (`wpac_commission_{status}`). Bewertungen ändern niemals Provisionen. Review Captain bleibt Eigentümer von Einladungen und Rezensionen.

**Ereignisse ohne Kundendaten.** Diese Actions übergeben nur IDs, Status, Codes, Beträge und Dateipfade – nie Käufer-E-Mail, Name, Anschrift oder Telefonnummer:

- Verkauf und Provision: `wpac_affiliate_attributed` (Bestell-ID, Partner-ID, Methode, Quelle), `wpac_commission_created`, `wpac_commission_approved`/`_rejected`/`_paid`/`_pending`/`_review` (Provisions-ID), `wpac_referral_captured` (Code, Kampagne, Inhalt, Zeitpunkt, Sub-ID, Besuchs-ID).
- Auszahlung und Belege: `wpac_payout_created`/`_paid`/`_cancelled`, `wpac_correction_generated`, `wpac_statement_generated`, `wpac_storno_generated`.
- Partner, Codes, Werbemittel: `wpac_affiliate_created`/`_status_changed`/`_recruited`/`_erased`, `wpac_code_requested`/`wpac_code_request_approved`/`_rejected`, `wpac_creative_created`/`_updated`. Daten des Partners (nicht des Käufers) liest ein Empfänger bei Bedarf über die Lese-API.

**Ausnahmen – nicht an Partner oder Review Captain weitergeben:** `wpac_contract_not_queued` übergibt den normalisierten Verkauf einschließlich `customer` (für die eigene Wiederholung der Quelle, die diese Daten ohnehin hat). `wpac_commission_adjusted` und `wpac_refund_after_correction` enthalten den Freitext `reason` der Quelle (z. B. den Erstattungsgrund des Shops). `wpac_mail_sent`/`wpac_mail_failed` enthalten die ganze Mail samt Empfänger.

**Partner-Ausgaben ohne Käuferdaten.** Partnerbereich (alle Reiter), Partner-REST (`/me`, `/me/commissions`, `/me/payouts`), Partner-CSV (Provisionen, Auszahlungen), Partner-Mails (Provisionshinweis, Monatsübersicht) und die Lese-API (`wpac_get_commission(s)`, `wpac_get_affiliate()`) zeigen zu einer WooCommerce-Bestellung nur Bestellnummer, Produktname, Basis, Satz, Betrag und Status. Käufer-E-Mail, Name und Anschrift dienen nur dem Self-Referral-Abgleich und werden nicht gespeichert; Zuordnungs- und Provisionszeilen enthalten sie nicht. Regressionstest: `L2-PII` in `tests/integration/r15-l2.php` und `tests/integration/r15-l2-render.php`.

### Review-Qualität: nur lesendes Aggregatmodell (Plugin 0.17.0, vorläufig)

**Stand:** gebaut für Plugin 0.17.0 (Entscheidung des Inhabers vom 16.11.2026, `TODO.md`, AFF-SU-03), **vorläufig, bis die gemeinsame Spezifikation der Suite abgenommen ist**; Namen, Felder und Schwellen können sich bis dahin ändern. Vorgeschlagene Vertragsversion: **1.6.0** (abwärtskompatibel, nur neue Funktion und neue Filter; `WPAC_CONTRACT_VERSION` wird mit dem Release erhöht). Review Captain ist noch nicht veröffentlicht; solange kein Plugin den Filter füllt, ändert sich nichts.

**Richtung.** Ein Review-Plugin füllt einen Filter, Affiliate Captain fragt nur ab, wendet Mindeststichprobe und Zeitraum an und zeigt das Ergebnis in der Verwaltung. Kein Schreibzugriff, keine Rückwirkung auf Provisionen, Regeln, Stufen, Status oder Auszahlungen. Der Kern prüft keine Lizenz und weiß über das Review-Plugin nur, dass der Filter einen Callback hat (`has_filter()`).

**Filter `wpac_review_aggregates( array $aggregates, array $query ): array`** (Review-Plugin → Affiliate Captain)

- `$aggregates`: leeres Array.
- `$query`: `version` (`1`), `refs` (Schlüssel ⇒ `array( 'affiliate_id' => int, 'campaign' => string|null )`; Schlüssel `a:<Partner-ID>` = alle Bewertungen des Partners, `a:<Partner-ID>|c:<Kampagne>` = Bewertungen der Bestellungen dieses Partners mit dieser Kampagne, `c:` mit leerer Kampagne = ohne Kampagne), `days` (30, 90 oder 365), `from`, `to` (UTC, `Y-m-d H:i:s`, rollierend: `to` = jetzt), `verified_only` (`true`), `published_only` (`true`). Alle Referenzen einer Seite kommen in **einem** Aufruf.
- Rückgabe je Schlüssel: `array( 'reviews' => int, 'orders' => int, 'stars' => array( 1 => int, 2 => int, 3 => int, 4 => int, 5 => int ) )`: Anzahl der Bewertungen, Anzahl verschiedener Bestellungen, Anzahl je Sternzahl. Gezählt werden nur verifizierte (mit Bestellbezug `source` + `order_id`) und veröffentlichte Bewertungen mit Datum zwischen `from` und `to`; keine importierten, anonymen, nicht freigegebenen oder fremden Plattformbewertungen. Keine Texte, keine Einzelwerte, keine Zeitpunkte, keine Kundendaten. Ein fehlender Schlüssel heißt „keine Bewertungen“. Werte sind ganze Zahlen ≥ 0 (auch als Ziffernfolge); die Summe der Sterne muss `reviews` ergeben, `orders` ≤ `reviews` und bei Bewertungen ≥ 1 – sonst Status `invalid`.
- Wirft der Callback eine Ausnahme, wird sie abgefangen (Status `error`; ins Protokoll kommt nur der Klassenname, nicht der Text); die Seite bleibt bedienbar.

**Zuordnung Bewertung → Partner** nur über die Bestellung, die das Review-Plugin selbst kennt: `wpac_get_order_attribution( string $source, string $order_id ): array|null|WP_Error` liefert `source`, `order_id`, `affiliate_id`, `campaign`, `method`, `status` (`attributed` oder `review`) und `created_at` (UTC); `null`, wenn die Bestellung keine Zuordnung hat oder ihr Partner gelöscht ist (Art. 17); `wpac_invalid_source` bzw. `wpac_invalid_order_id` bei ungültigen Argumenten (leer, kein String, länger als 40 bzw. 64 Zeichen; die Bestell-ID darf eine ganze Zahl sein). Wie die übrige Lese-API ohne Prüfung des Benutzers (PHP-API für vertrauenswürdigen Code). Affiliate Captain speichert keine Review-IDs.

**Kennzahlen (berechnet vom Kern):** Anzahl Bewertungen, Anzahl Bestellungen, Durchschnitt (eine Nachkommastelle), Anteil 4–5 Sterne und Anteil 1–2 Sterne (ganze Prozent).

**Mindeststichprobe:** mindestens **10** Bewertungen aus mindestens **5** verschiedenen Bestellungen im Zeitraum; darunter nur `insufficient_sample` ohne Zahlen (kein Rückschluss auf einzelne Käufer). Der Filter `wpac_review_aggregate_minimum( array( 'reviews' => 10, 'orders' => 5 ) )` kann die Werte nur erhöhen (ganze Zahlen), nie senken.

**Zeitraum:** rollierend **90 Tage** nach Datum der Bewertung, wählbar 30/90/365 (Parameter `review_days` der Partnerseite und des Berichts nach Kampagne), unabhängig vom Datumsfilter des Berichts. Jedes Ergebnis nennt `days`, `from`, `to`, `computed_at`.

**Status je Referenz:** `ok` (mit Zahlen), `insufficient_sample`, `no_provider` (kein Callback), `forbidden` (Benutzer ohne `wpac_manage_affiliates`), `not_found` (unbekannter oder nach Art. 17 gelöschter Partner), `restricted` (Verarbeitung nach Art. 18 eingeschränkt), `invalid`, `error`. Bei `forbidden`, `not_found` und `restricted` wird das Review-Plugin für diese Referenz gar nicht gefragt.

**Lösch- und Widerrufsfolgen:** Affiliate Captain speichert weder Bewertungen noch Aggregate (kein Cache, keine Option, kein Transient, keine Tabellenzeile, kein Protokolleintrag mit Bewertungsdaten); jede Anzeige rechnet neu. Gelöschte, widerrufene oder zurückgezogene Bewertungen und Bewertungen gelöschter Kunden fallen deshalb beim nächsten Aufruf heraus, sobald das Review-Plugin sie nicht mehr zählt (seine Pflicht); fällt eine Kennzahl dadurch unter die Mindeststichprobe, verschwinden die Zahlen. Nach Art. 17 gelöschte Partner ergeben `not_found`, und `wpac_get_order_attribution()` liefert für ihre Bestellungen `null`; bei Einschränkung nach Art. 18 `restricted`. Ist eine Zuordnung nach Ablauf der Aufbewahrung gelöscht (täglicher Lauf), kann das Review-Plugin die Bestellung keinem Partner mehr zuordnen; ihre Bewertungen zählen dann nicht mehr. Auskunft (Art. 15): Es ist nichts gespeichert, also nichts im Export.

**Sichtbarkeit:** nur für `wpac_manage_affiliates` in der Verwaltung: Partnerseite, Reiter „Übersicht“, Kasten „Review-Qualität“ (das Review-Plugin wird nur auf diesem Reiter gefragt); Bericht „Nach Kampagne“, Spalte „Bewertungen (90 Tage)“ mit „Ø / 5 (Anzahl)“ oder „–“. Nie im Partnerbereich, in Partner-Mails, in der Partner-REST-API, in Partner-CSV-Dateien, im CSV-Export der Berichte oder in der REST-Route `/reports`. Ohne Review-Plugin erscheinen weder Kasten noch Spalte.

**Datenschutz:** Verzeichnis von Verarbeitungstätigkeiten, Eintrag 5 (`docs/VERARBEITUNGSVERZEICHNIS.md`, `docs/RECORD-OF-PROCESSING.md`); er erscheint im Download nur, solange ein Plugin den Filter füllt.

**Tests:** `tests/integration/su3.php` (Logik, Schwellen, Zeitraum, Rechte, Speicherfreiheit, Löschfolgen, fehlerhafte Antworten) und `tests/integration/su3-render.php` (Admin-Seiten, Partnerbereich).

## Form Captain: Bewertung des eigenen Bewerbungsablaufs

**Bewertung (Stand 0.15.0).** Der eingebaute Ablauf (`[wpac_affiliate_registration]` bzw. Block „Partner-Bewerbungsformular“) deckt die Partnerbewerbung vollständig ab:

- Name, E-Mail (bei angemeldeten Benutzern die Kontoadresse), Website/Kanal, Notiz;
- Partnergruppen, öffentlich wählbar oder per signiertem Shortcode/Block fest vorgegeben, mit eigenen Zusatzfeldern je Gruppe (`text`, `textarea`, `url`, `number`, `checkbox`, Pflichtfelder);
- Teilnahmebedingungen je Gruppe mit Version und SHA-256 des angenommenen Textes;
- Double-Opt-in per E-Mail (die Daten warten höchstens zwei Tage, ohne Auskunft, ob die Adresse schon existiert); Honeypot, Nonce, Rate-Limit;
- Status `pending` (oder `active` mit „Automatisch freigeben“), Werber-Erfassung, Admin-Mail, Freigabe/Ablehnung mit Grund, Auskunft und Löschung der Bewerbungsdaten (Art. 15/17).

Ein Formular-Builder brächte zusätzlich: weitere Feldtypen (Auswahl, Radio, Datum, Datei-Upload), bedingte Logik und mehrstufige Formulare, freie Gestaltung, CAPTCHA-/Antispam-Dienste, Weitergabe an andere Systeme (CRM, Newsletter) und Formularstatistik.

**Empfehlung:** keine Form-Captain-Anbindung für die Partnerbewerbung, solange keine dieser Zusatzfunktionen konkret gebraucht wird. Eine zweite Registrierung würde Double-Opt-in, Bedingungsnachweis, Gruppenprüfung und Datenschutzpflichten doppeln und Bewerbungsdaten parallel speichern. Fehlen nur einzelne Feldtypen (Auswahl, Datum), ist ihre Ergänzung in `Groups::parse_fields()` kleiner als eine Anbindung. Erst bei Bedarf an Datei-Uploads, bedingter Logik oder CRM-Weitergabe die Schnittstelle unten umsetzen.

### Vorschlag (nicht implementiert): Formularübergabe

Noch nicht Teil des Vertrags; die Inhaberin bzw. der Inhaber entscheidet.

```php
/**
 * @param array $data    name, email (Pflicht); website, channel, note, group_id,
 *                       fields (key => Wert der Gruppenfelder), terms_accepted (bool),
 *                       terms_version, user_id (0 = Gast).
 * @param array $context source (z. B. 'form-captain', a-z0-9_-), submission_id (stabile ID
 *                       der Einsendung, max. 64), confirmed (bool: E-Mail im Formular per
 *                       Double-Opt-in bestätigt).
 * @return array|WP_Error array( status => created|duplicate|confirm_sent, affiliate_id )
 */
wpac_submit_application( array $data, array $context );
// Versioniertes REST-Gegenstück: POST /wpac/v1/applications
```

- **Ergebnis:** immer Status `pending`, auch wenn „Automatisch freigeben“ aktiv ist; nie Freigabe, Partnercode, Gutschein, Provisionsregel oder Provision. Freigabe und Ablehnung bleiben im Admin von Affiliate Captain.
- **Prüfungen wie beim eigenen Ablauf:** Name, E-Mail, Gruppe aktiv und öffentlich (oder ausdrücklich für die Quelle freigegeben), Pflichtfelder der Gruppe, aktuelle Bedingungen angenommen, Rate-Limit je E-Mail-Adresse. Fehler als `WP_Error` (`wpac_invalid_field`, `wpac_terms_required`, `wpac_group_invalid`, `wpac_rate_limited`).
- **Double-Opt-in:** ohne `confirmed = true` schickt Affiliate Captain die eigene Bestätigungsmail (`confirm_sent`); angelegt wird erst nach dem Klick.
- **Berechtigung:** PHP-Aufruf nur aus dem serverseitigen Formular-Handler; Nonce/CSRF prüft das aufrufende Formular. Die REST-Route verlangt `wpac_manage_affiliates` (Server-zu-Server, z. B. Application Password) und ist nie öffentlich.
- **Idempotenz:** höchstens ein Partner je `source` + `submission_id`; eine erneute Übergabe liefert `duplicate` mit derselben `affiliate_id`. Gibt es die E-Mail schon als Partner, wird nichts angelegt (`duplicate`, ohne dem Formular Details zu verraten).
- **Audit:** `affiliate.application_submitted` mit `source`, `submission_id`, `group_id`, ohne E-Mail oder Anschrift; danach wie bisher `wpac_affiliate_created( $id, 'pending' )`.
- **Daten:** Zusatzfelder landen wie beim eigenen Formular in `application_data` (Auskunft und Löschung inklusive). Form Captain löscht seine Kopie nach erfolgreicher Übergabe oder dokumentiert die eigene Aufbewahrung.
