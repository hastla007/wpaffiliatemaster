# Workflow-Provider (Workflow Captain)

Affiliate Captain stellt für Workflow Captain (und andere Automatisierungen) eine **Provider-Seite** bereit: normalisierte Ereignisse nach dem Commit, Lese-Aktionen für Status und Kennzahlen und klare Regeln, was ein Workflow **nicht** darf. Workflow Captain ist ein eigenes Plugin; dieses Dokument beschreibt nur, was Affiliate Captain liefert. Grundlage ist der Integrationsvertrag [CONTRACT.md](CONTRACT.md) v1.3 – dies ist eine **Ergänzung ohne Versionswechsel** (Plugin 0.15.x, `WPAC_CONTRACT_VERSION` bleibt `1.3.0`; abwärtskompatibel).

Grundregeln:

- Nur die hier und in CONTRACT.md genannten Funktionen und Hooks verwenden – nie `wpac_*`-Tabellen und nie interne Klassen (`WPAffiliateCaptain\…`).
- Erst nach `wpac_loaded` aufrufen und jede Funktion mit `function_exists()` absichern (`wpac_workflow_read`, `wpac_workflow_event_types`).
- Ereignisse sind **Benachrichtigungen**. Sie lösen nie eine Buchung, Freigabe, Erstattung oder Auszahlung aus, und ein Workflow darf aus ihnen keine erzeugen (siehe „Was ein Workflow nicht darf“).
- Fehlt Workflow Captain, ist er deaktiviert, sein Recht abgelaufen oder wirft sein Listener einen Fehler: Buchungen, Ledger, Auszahlungen, Gutschriften und Datenschutz-Löschungen laufen unverändert (Abschnitt „Ausfallsicherheit“).

## Versionierung (SemVer)

| Was | Version | Regel |
|---|---|---|
| Vertrag | `wpac_contract_version()` (derzeit `1.3.0`) | Gleiche Major-Version = kompatibel. Neue Funktionen/Hooks erhöhen höchstens die Minor-Version oder kommen als „Ergänzung ohne Versionswechsel“; Entfernen oder Umdeuten nur mit neuer Major-Version. |
| Umschlag (Envelope) | Feld `version`, derzeit `'1'` | Innerhalb von `'1'` kommen höchstens neue Ereignistypen und neue Felder in `data` hinzu. Kein Feld ändert Bedeutung oder Typ, keins wird entfernt (außer über den Redaktionsfilter des Betreibers, s. u.). Eine inkompatible Änderung bekommt `version` `'2'`. |
| Ereignistypen | `wpac_workflow_event_types()` | Liefert `typ => [Felder von data]`. Der Workflow soll unbekannte Typen und unbekannte Felder ignorieren. |

Erkennen: `function_exists( 'wpac_workflow_event_types' )` bzw. `function_exists( 'wpac_workflow_read' )` (beide seit Plugin 0.15.x).

## Ereignisse: `wpac_workflow_event`

```php
add_action( 'wpac_loaded', function () {
    add_action( 'wpac_workflow_event', function ( array $envelope ) {
        // Idempotent verarbeiten: $envelope['id'] als Schlüssel speichern, Wiederholungen ignorieren.
    } );
} );
```

Für jede vorhandene Post-Commit-Action des Kerns feuert **genau eine** zusätzliche Action `wpac_workflow_event( array $envelope )`:

```json
{
  "id": "b81bb38e-5237-5cc6-9883-f1d39783f237",
  "type": "commission.created",
  "version": "1",
  "occurred_at": "2026-11-16 05:44:03",
  "site_id": 1,
  "source": "affiliate-captain",
  "object": { "type": "commission", "id": "433" },
  "data": { "commission_id": 433, "affiliate_id": 310, "kind": "sale", "status": "review", "currency": "EUR", "amount_minor": 5000, "source": "woocommerce", "order_id": "4711", "parent_id": 0 }
}
```

| Feld | Bedeutung |
|---|---|
| `id` | Stabile Ereignis-ID, UUID Version 5 (RFC 4122) aus Website-ID, Typ, Objekt und Revision. **Dasselbe Ereignis ergibt immer dieselbe ID** – auch wenn die Quell-Action erneut feuert (Wiederholung aus der Warteschlange, Replay). Der Workflow dedupliziert über diese ID. Eindeutig je Installation und Website. |
| `type` | Ereignistyp, siehe Tabelle. |
| `version` | Version des Umschlags (`'1'`). |
| `occurred_at` | Zeitpunkt der Auslieferung, UTC, `Y-m-d H:i:s`. Bei einem Replay der neue Zeitpunkt (die `id` bleibt gleich). |
| `site_id` | ID der Website (Multisite: Ereignisse kommen je Website; dieselbe Provision auf zwei Websites hat zwei verschiedene IDs). |
| `source` | Immer `affiliate-captain`. |
| `object` | `type` und `id` (als Text) des betroffenen Objekts. |
| `data` | Redigierte Nutzdaten: nur die Felder der Tabelle unten (Whitelist je Typ). |

**Zeitpunkt:** Jedes Ereignis feuert erst **nach dem Commit** der Datenbanktransaktion. Wird eine Buchung zurückgerollt, feuert weder die Quell-Action noch `wpac_workflow_event`. Läuft eine Quell-Action ausnahmsweise innerhalb einer äußeren Transaktion (z. B. `Ledger::transition()` oder `mark_paid()` innerhalb einer Neuberechnung), wartet sie seit Plugin 0.15.x auf deren Commit (`Db::after_commit()`).

**Reihenfolge:** In der Reihenfolge der Buchungen einer Anfrage. Eine Erstattung, die vor ihrem Verkauf gemeldet wird, wartet (`parked`/`queued`) und erzeugt erst nach dem Verkauf ein Ereignis: `sale.attributed` → `commission.created` → `commission.adjusted`. Über Anfragen hinweg (parallele Prozesse) gilt nur diese Kausalität, keine globale Ordnung; der Workflow ordnet bei Bedarf über `occurred_at` und die Objekt-IDs.

### Ereignistypen

| `type` | Quell-Action | `object` | Revision (Teil der ID) | `data` |
|---|---|---|---|---|
| `affiliate.created` | `wpac_affiliate_created` | `affiliate` | einmal je Partner | `affiliate_id`, `status`, `group_id`, `referred_by` |
| `affiliate.status_changed` | `wpac_affiliate_status_changed` | `affiliate` | alter > neuer Status @ Änderungszeit | `affiliate_id`, `status`, `previous_status`, `group_id` |
| `affiliate.recruited` | `wpac_affiliate_recruited` | `affiliate` | einmal je Partner | `affiliate_id`, `recruiter_id` |
| `affiliate.erased` | `wpac_affiliate_erased` | `affiliate` | `erased` bzw. `erased-deferred` | `affiliate_id`, `deferred` (Teil der Löschung aufgeschoben, weil Provisionen/Auszahlungen offen sind) |
| `sale.attributed` | `wpac_affiliate_attributed` | `order` (`quelle:bestellung`) | Partner-ID (Umbuchung = neues Ereignis) | `source`, `order_id`, `affiliate_id`, `method` |
| `commission.created` | `wpac_commission_created` | `commission` | einmal je Buchung | `commission_id`, `affiliate_id`, `kind`, `status`, `currency`, `amount_minor`, `source`, `order_id`, `parent_id` |
| `commission.adjusted` | `wpac_commission_adjusted` | `refund` (`quelle:bestellung:refund`) | gebucht/zurückgenommen + Buchungs-IDs | `source`, `order_id`, `refund_id`, `refund_type`, `deleted`, `commission_ids`, `affiliate_ids` |
| `commission.status_changed` | `wpac_commission_approved` / `_rejected` / `_paid` / `_pending` / `_review` | `commission` | Status @ Änderungszeit | `commission_id`, `affiliate_id`, `kind`, `status`, `currency`, `amount_minor`, `payout_id` |
| `payout.created` | `wpac_payout_created` | `payout` | einmal | `payout_id`, `affiliate_id`, `number`, `status`, `currency`, `amount_minor`, `method` |
| `payout.paid` | `wpac_payout_paid` | `payout` | einmal | wie `payout.created` |
| `payout.cancelled` | `wpac_payout_cancelled` | `payout` | einmal | wie `payout.created` |
| `correction.generated` | `wpac_correction_generated` | `correction` | einmal | `correction_id`, `payout_id`, `affiliate_id`, `number`, `currency` |
| `creative.created` | `wpac_creative_created` | `creative` | einmal | `creative_id`, `family_id`, `version`, `status`, `type`, `title`, `group_id` |
| `creative.updated` | `wpac_creative_updated` | `creative` | Status | wie `creative.created` |
| `code_request.created` | `wpac_code_requested` | `code_request` | `created` | `code_request_id`, `affiliate_id`, `code` |
| `code_request.approved` | `wpac_code_request_approved` | `code_request` | `approved` | `code_request_id`, `affiliate_id`, `code`, `main` |
| `code_request.rejected` | `wpac_code_request_rejected` (seit 0.15.x mit viertem Parameter `$row_id`) | `code_request` | `rejected` | `code_request_id`, `affiliate_id`, `code` |

Beträge in Cent (Integer) mit ISO-4217-Währung, IDs als Integer. Weitere Daten liest der Workflow bei Bedarf mit den Lesefunktionen aus CONTRACT.md (`wpac_get_commission()`, `wpac_get_payout()` …) – unter den dortigen Regeln.

Beispiele:

```json
{ "type": "sale.attributed", "object": { "type": "order", "id": "woocommerce:4711" },
  "data": { "source": "woocommerce", "order_id": "4711", "affiliate_id": 310, "method": "code" } }
{ "type": "commission.adjusted", "object": { "type": "refund", "id": "woocommerce:4711:88" },
  "data": { "source": "woocommerce", "order_id": "4711", "refund_id": "88", "refund_type": "refund", "deleted": false, "commission_ids": [439], "affiliate_ids": [310] } }
{ "type": "affiliate.status_changed", "object": { "type": "affiliate", "id": "310" },
  "data": { "affiliate_id": 310, "status": "paused", "previous_status": "active", "group_id": 0 } }
{ "type": "payout.paid", "object": { "type": "payout", "id": "112" },
  "data": { "payout_id": 112, "affiliate_id": 310, "number": "GS-2026-000105", "status": "paid", "currency": "EUR", "amount_minor": 8330, "method": "sepa" } }
{ "type": "affiliate.erased", "object": { "type": "affiliate", "id": "312" },
  "data": { "affiliate_id": 312, "deferred": false } }
```

(Gekürzt: jedes Ereignis trägt zusätzlich `id`, `version`, `occurred_at`, `site_id` und `source`.)

### Redaktion (Datenschutz)

- `data` enthält **nur** die Felder der Tabelle (Whitelist je Typ). Nie: Käufer-E-Mail, Käufername, Anschrift, Partnername und -E-Mail, IBAN/BIC, Kontoinhaber, Steuer- und USt-Daten, Zahlungs- bzw. PayPal-Angaben, Reservierungsnummern (`claim`), Tokens, Passwörter, Reviewtexte, Freitexte (Grund, Beschreibung, Referenz, Notiz) und Dateipfade. Zusätzlich entfernt der Kern diese Schlüssel in jeder Tiefe (`WorkflowEvents::FORBIDDEN`), falls eine künftige Änderung sie versehentlich aufnimmt.
- `creative.*` enthält den Titel des Werbemittels (Inhalt des Betreibers, keine Personendaten); `code_request.*` den Partnercode (öffentlicher Code, kein Geheimnis).
- Filter `wpac_workflow_event_data( $data, $type )`: Der Betreiber kann Felder **entfernen** (z. B. `code`). Neue Schlüssel und geänderte Werte werden ignoriert – der Filter kann nur weniger, nie mehr oder anderes liefern.
- **Löschung (Art. 17 DSGVO):** `affiliate.erased` meldet nur die Partner-ID. Der Workflow löscht daraufhin seine eigenen Daten dieses Partners (Verläufe, gespeicherte Ereignisse mit dieser `affiliate_id`). Frühere Ereignisse enthielten ohnehin keine Personendaten.

### Aus- und Abschalten, Kosten

- Ohne Listener auf `wpac_workflow_event` baut der Kern nichts: jede Quell-Action prüft zuerst `has_action( 'wpac_workflow_event' )` und kehrt sonst sofort zurück (keine Abfrage, kein Filter, keine Warteschlange).
- Filter `wpac_workflow_events_enabled` (Standard `true`): `false` schaltet alle Workflow-Ereignisse ab. Die bisherigen Actions (`wpac_commission_created` …) feuern unverändert weiter.

## Lese-Aktionen: `wpac_workflow_read( $action, $args )`

```php
$r = wpac_workflow_read( 'metrics.summary', array( 'affiliate_id' => 310, 'from' => '2026-11-01', 'to' => '2026-11-30' ) );
if ( is_wp_error( $r ) ) { /* $r->get_error_code() */ }
```

Nur lesend: keine Buchung, keine Statusänderung, kein Audit-Eintrag. Zeiträume sind Tage der Website-Zeitzone (`Y-m-d`), Standard die letzten 30 Tage bis heute, höchstens 366 Tage. Beträge in Cent je Währung (`{ "EUR": 12345 }`, ohne Umrechnung).

| `action` | Argumente | Rückgabe |
|---|---|---|
| `partner.status` | `affiliate_id` (Pflicht) | `affiliate_id`, `status` (`pending`, `active`, `paused`, `terminated`, `rejected`), `group_id`, `referred_by`, `restricted` (Verarbeitung eingeschränkt, Art. 18), `approved_at` (oder `null`), `updated_at`. Kein Name, keine E-Mail. |
| `campaign.status` | `affiliate_id` (Pflicht), `campaign` (utm_campaign, `''` = ohne Kampagne), `from`, `to` | `affiliate_id`, `campaign`, `label`, `from`, `to`, `partner_status`, `active` (Partner aktiv und Klicks oder Bestellungen im Zeitraum), `clicks`, `conversions`, `commission`, `sub_ids` (je Sub-ID: `sub_id`, `visits`, `orders`, `conversion`, `commission`). Kampagnen gehören immer einem Partner (wie im Kampagnenbericht). |
| `metrics.summary` | `affiliate_id` (optional, leer/0 = ganzes Programm), `from`, `to` | `affiliate_id`, `from`, `to`, `clicks`, `conversions`, `click_conversions`, `conversion_rate` (Prozent, `null` ohne Klicks), `commission`. Partner: wie der Partnerbereich (Verkäufe nicht abgelehnt und nicht vollständig storniert; Provision = alle nicht abgelehnten Buchungen). Programm: zugeordnete Bestellungen, Provision je Währung. Klicks aus dem Tagesaggregat (`visits_daily`), sonst aus den Besuchen. |
| `metrics.monthly` | `affiliate_id` (Pflicht), `months` (1–36, Standard 12) | `affiliate_id`, `months`: Liste (neuester Monat zuerst) mit `month` (`Y-m`, lokale Monate), `sales`, `commission`. |

### Rechte

- Mit angemeldetem Benutzer: Capability `wpac_manage_affiliates` nötig, sonst `wpac_forbidden`.
- Ohne Benutzer nur in WP-Cron und WP-CLI (Hintergrundläufe der Workflow-Engine). Jeder andere Kontext ohne Login (z. B. REST oder AJAX) bekommt `wpac_forbidden`; ein Job-Runner mit eigener Absicherung kann das mit dem Filter `wpac_workflow_read_allowed( false, $action )` freigeben.
- Die übrigen Vertragsfunktionen (`wpac_get_affiliate()` …) prüfen den Benutzer **nicht** (siehe CONTRACT.md); REST- oder AJAX-Hüllen des Workflows prüfen selbst.

### Fehlercodes

| Code | Bedeutung |
|---|---|
| `wpac_unknown_action` | Unbekannte Lese-Aktion. |
| `wpac_forbidden` | Recht fehlt (siehe oben). |
| `wpac_invalid_field` | Unbekanntes Argument. |
| `wpac_invalid_<argument>` | Ungültiger Wert, z. B. `wpac_invalid_affiliate_id` (auch: Pflichtangabe fehlt), `wpac_invalid_from`, `wpac_invalid_to`, `wpac_invalid_months`; Arrays als Wert sind immer ungültig. |
| `wpac_invalid_range` | `from` nach `to` oder mehr als 366 Tage. |
| `wpac_not_found` | Partner unbekannt. |

Fehler sind endgültig (keine Wiederholung nötig); es gibt keine vorübergehenden Fehler, weil nichts geschrieben wird.

## Was ein Workflow nicht darf (AFF-WF-03 bis 05)

- **Keine automatische Freigabe, Ablehnung, Provision, Erstattung oder Auszahlung.** Es gibt dafür keine Workflow-Aktion. Freigaben, Auszahlungsläufe, Exporte und Bankdateien bleiben im Admin von Affiliate Captain.
- **Manuelle Anpassung** nur über das bestehende `wpac_create_adjustment()` (CONTRACT.md: Grund Pflicht, Audit, Capability-Prüfung durch den Aufrufer). Die Passwortbestätigung gibt es nur in der Admin-Oberfläche, nicht in der PHP-Funktion – Workflow Captain darf `wpac_create_adjustment()` deshalb **nie automatisch** (aus einem Ereignis, Zeitplan oder einer Regel) aufrufen, sondern nur nach einer ausdrücklichen menschlichen Aktion mit eigener Bestätigung (Recht `wpac_manage_affiliates`, Grund, eigener Idempotenzschlüssel z. B. als `reference`).
- **Native Prozesse bleiben in Affiliate Captain:** Bewerbung und Freischaltung, Codes und Code-Anträge, Zuordnung von Verkäufen, Sperrfristen, Betrugsprüfung, Gutschriften/Korrekturgutschriften, Auszahlungen, Mahnungen, Monatsberichte und die Pflicht-E-Mails des Kerns. Workflow Captain darf nur **ausdrücklich freigegebene Folgekommunikation** übernehmen (z. B. eine zusätzliche Mail „neue Werbemittel“ nach `creative.updated` mit Status `approved`, eine interne Benachrichtigung nach `commission.status_changed`, ein Bericht aus `metrics.summary`). Pflichtmails des Kerns (Gutschrift, Auszahlung, Statusänderung) lassen sich nur über die vorhandenen Kern-Einstellungen bzw. den Filter `wpac_mail` abschalten – nicht durch den Workflow.

## Kanonische Umsatzquellen (AFF-WF-04)

| Quelle | Meldet | Idempotenz |
|---|---|---|
| WooCommerce | Bestellungen, Positionen, Erstattungen, Stornos/Chargebacks über den eingebauten Adapter (`source = woocommerce`). | `source` + `order_id` (Verkauf), `item_id` (Position), `refund_id` (Erstattung). |
| Pass Captain | Eigene Bestellungen, die nicht über WooCommerce laufen (`source = pass-captain`); WooCommerce-Bestellungen überspringt der Adapter. | wie oben |
| Voucher Captain | **Keine Verkäufe.** Ordnet nur Gutschein-Kampagnen über `wpac_coupon_affiliate` einem Partner zu; gebucht wird über die WooCommerce-Bestellung. | – |

Workflow-Ereignisse erzeugen **nie** eine Buchung. Meldet ein Workflow aus einem Ereignis denselben Verkauf erneut (`wpac_record_sale()`), ist das Ergebnis `duplicate` – die bestehende Order-/Item-/Refund-Idempotenz verhindert eine zweite Provision. Ein Workflow darf keine eigene Verkaufsquelle für Bestellungen sein, die eine der obigen Quellen schon meldet.

## Ausfallsicherheit (AFF-WF-05)

- Der Kern hängt an keiner Stelle von Workflow Captain ab; ohne Listener wird nichts gebaut.
- Ein Listener, der einen Fehler wirft, wird abgefangen und ins Plugin-Log geschrieben; die Buchung ist bereits committed und bleibt gültig, weitere Listener und Folgeschritte laufen weiter.
- Ereignisse werden nicht zwischengespeichert und nicht nachgeliefert: Wer Ereignisse verpasst (Workflow deaktiviert, Recht abgelaufen), gleicht über die Lese-Aktionen und die Lesefunktionen aus CONTRACT.md ab. Der Ledger ist die Quelle der Wahrheit, nicht der Ereignisstrom.
- Das Kernplugin enthält keine Lizenzprüfung: ein fehlendes oder abgelaufenes Pro-/Workflow-Recht kann nur die Listener des Add-ons abschalten, nie Buchungen, Gutschriften, Aufbewahrung oder Löschungen des Kerns.

## Tests (AFF-WF-06)

`tests/integration/r15-l3.php` sichert den Vertrag ab: Umschlag und Felder, Post-Commit-Garantie (auch für Quell-Actions in einer äußeren Transaktion), Rollback ohne Ereignis, Replay mit gleicher ID, parallele Verkaufs-/Erstattungsmeldung in Buchungsreihenfolge, Multisite (Website-ID in Umschlag und ID), Löschung (`affiliate.erased` ohne Personendaten), fehlender Listener (nichts gebaut, Buchung unverändert), Abschalter, fehlerhafter Listener, Redaktion für jeden Ereignistyp (keine verbotenen Schlüssel, keine Personen-, Bank- oder Steuerdaten, nur Whitelist-Felder), Redaktionsfilter (nur Entfernen), erneute Verkaufsmeldung aus einem Ereignis (`duplicate`) sowie Rechte, Validierung und Nur-Lesen der Lese-Aktionen.
