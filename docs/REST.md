# REST-API `wpac/v1`

Authentifizierung über WordPress (Cookie + `X-WP-Nonce` oder Application Passwords). Beträge immer in Minor Units (`*_minor`) plus Währung; Steuer- und Bankdaten werden nie ausgeliefert.

| Methode & Pfad | Berechtigung | Zweck |
|---|---|---|
| `GET /wp-json/wpac/v1/health` | `wpac_manage_program` **oder** Header `X-WPAC-Monitor-Token` mit dem Token aus **Einstellungen → Berichte & Daten** (20–64 Buchstaben/Ziffern; gespeichert wird nur ein Hash); sonst `403` | Zustand für externes Monitoring: `200` = alles in Ordnung, `503` = kritischer Befund. Antwort `{ "ok": true|false, "failed": ["cron", …] }`; mit Capability zusätzlich `checks` (alle Prüfungen mit Status und Text, wie Site Health). Nie gecacht (`Cache-Control: no-store`). |
| `GET /wp-json/wpac/v1/contract` | öffentlich | Vertragsversion (Plugin-Version nur für angemeldete Nutzer mit `wpac_manage_program`) |
| `GET /wpac/v1/codes/{code}` | öffentlich, max. 20/min je Client (Filter `wpac_rate_limit`, Bucket `rest_code`) | `{ "valid": true|false }` – keine Partnerdaten (Schutz vor Enumeration). Nie gecacht (`Cache-Control: no-store, private`). |
| `POST /wpac/v1/touch` | öffentlich, mit Einstellung „Partnerlinks zusätzlich per JavaScript erfassen“; mit `consent: true` zusätzlich bei Cookie-Modus „nur mit Einwilligung“ (sonst 404). Nur von Seiten des Shops (`Origin` = Shop-Host oder Cookie-Domain, Filter `wpac_touch_allowed_origin`) | Meldet einen Partnerlink von einer gecachten Seite: `{ code, campaign?, utm_source?, utm_medium?, content_id?, path?, referrer?, consent? }` → immer `{ "ok": true }` (auch bei unbekanntem Code); Code wird wie beim Seitenaufruf normalisiert (z. B. `ANNA.`). Besuch zählt je Browser (Adresse + User-Agent) und Code höchstens einmal pro Minute; `consent: true` (Einwilligung nach der Landung) speichert nur das Cookie und zählt keinen Besuch. Kein IP-Limit: ein Partnerlink geht hinter einem CDN nie verloren. Angemeldete Kunden: die Shop-Session wird nicht geladen (REST ohne Nonce). |
| `POST /wpac/v1/sales` | `wpac_manage_program` | Verkauf melden (Payload wie `wpac_record_sale`, siehe CONTRACT.md); `202` = zur Wiederholung eingeplant |
| `POST /wpac/v1/refunds` | `wpac_manage_program` | Refund/Storno/Chargeback melden |
| `GET /wpac/v1/affiliates?status=&search=&page=&per_page=` | `wpac_manage_affiliates` | Partnerliste (`X-WP-Total`) |
| `GET /wpac/v1/affiliates/{id}` | `wpac_manage_affiliates` | Partner mit Codes |
| `GET /wpac/v1/commissions?affiliate_id=&status=&source=&order_id=&from=&to=` | `wpac_manage_affiliates` | Ledger |
| `GET /wpac/v1/reports/{partners|content|campaign}?from=&to=` | `wpac_manage_affiliates` | Berichte (in Programmwährung umgerechnet, `unconverted` listet Währungen ohne Kurs) |
| `GET /wpac/v1/me` | angemeldeter Partner | Code, Link, Landing-URL, Summen, verfügbarer Saldo |
| `GET /wpac/v1/me/commissions` | angemeldeter Partner | eigene Provisionen (ohne Kundendaten) |
| `GET /wpac/v1/me/payouts` | angemeldeter Partner | eigene Auszahlungen |

Jede Route hat einen `permission_callback`; die Integrationstests (Abschnitt „ZE“ in `tests/integration/run.php`) prüfen, dass genau `health`, `contract`, `touch` und `codes/{code}` öffentlich sind, Verwaltungsrouten ohne Capability und Partnerrouten für Gäste, Kunden ohne Partnerkonto und beendete Partner abgelehnt werden. Eine neue öffentliche Route muss dort bewusst eingetragen werden.

Fehlermeldungen (`message`) sind übersetzt; maßgeblich für Programme ist `code` (`wpac_forbidden`, `wpac_not_found`, `wpac_rate_limited`, `wpac_disabled`, …).

Monitoring-Beispiel:

```bash
curl -fsS -H 'X-WPAC-Monitor-Token: <Token aus den Einstellungen>' https://example.com/wp-json/wpac/v1/health
```

Beispiel (Application Password):

```bash
curl -u admin:APP_PASSWORD -H 'Content-Type: application/json' \
  -d '{"contract":"1.0.0","source":"pass-captain","order_id":"4711","currency":"EUR","items":[{"item_id":"1","net_minor":49900}],"partner_code":"SEGELSCHULE-NORD"}' \
  https://example.com/wp-json/wpac/v1/sales
```
