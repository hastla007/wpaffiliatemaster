# Integrationsvertrag v1 (`WPAC_CONTRACT_VERSION = 1.0.0`)

Öffentliche Schnittstelle von WP Affiliate Captain für Verkaufsquellen (Pass Captain, Voucher Captain, WooCommerce-Adapter, Connector-Add-ons wie LearnBoating). Kompatibel ist jede Version mit gleicher Major-Version; andere Versionen werden abgelehnt (`incompatible_contract`) und im Integrationsstatus angezeigt.

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
        'customer'     => array( 'user_id' => 12, 'email' => 'kunde@example.com' ),
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

Rückgabe: `array( status => created|duplicate|unattributed, attribution_id, commission_ids, reason )` oder `WP_Error`. Bei technischen Fehlern wird das Ereignis in die Retry-Warteschlange gelegt (`wpac_queued`); die Kunden-E-Mail wird dabei entfernt (der Self-Referral-Vergleich nutzt beim Retry nur noch die User-ID).

Refunds, die **vor** einem erfolgreich gebuchten Verkauf passieren, sollte die Quelle bereits von `net_minor` abziehen (der WooCommerce-Adapter macht das automatisch). `wpac_record_refund()` ohne vorhandene Provision liefert `no_commission`.

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
| `wpac_wc_chargeback_statuses` | `string[]` | WooCommerce-Status, die als Chargeback gelten. |
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

Ereignisse sind Benachrichtigungen ohne Kundendaten, kein Ersatz für die idempotenten Funktionen oben.
