# REST-API `wpac/v1`

Authentifizierung über WordPress (Cookie + `X-WP-Nonce` oder Application Passwords). Beträge immer in Minor Units (`*_minor`) plus Währung; Steuer- und Bankdaten werden nie ausgeliefert.

| Methode & Pfad | Berechtigung | Zweck |
|---|---|---|
| `GET /wp-json/wpac/v1/health` | `wpac_manage_program` **oder** Header `X-WPAC-Monitor-Token` mit dem Token aus **Einstellungen → Berichte & Daten** (20–64 Buchstaben/Ziffern; gespeichert wird nur ein Hash); sonst `403` | Zustand für externes Monitoring: `200` = alles in Ordnung, `503` = kritischer Befund. Antwort `{ "ok": true|false, "failed": ["cron", …] }`; mit Capability zusätzlich `checks` (alle Prüfungen mit Status und Text, wie Site Health). Nie gecacht (`Cache-Control: no-store`). |
| `GET /wp-json/wpac/v1/contract` | öffentlich | Vertragsversion (Plugin-Version nur für angemeldete Nutzer mit `wpac_manage_program`) |
| `GET /wpac/v1/codes/{code}` | öffentlich, max. 20/min je Client | `{ "valid": true|false }` – keine Partnerdaten (Schutz vor Enumeration) |
| `POST /wpac/v1/touch` | öffentlich, nur mit Einstellung „Partnerlinks zusätzlich per JavaScript erfassen“ (sonst 404) | Meldet einen Partnerlink von einer gecachten Seite: `{ code, campaign?, utm_source?, utm_medium?, content_id?, path?, referrer? }` → immer `{ "ok": true }` (auch bei unbekanntem Code); Besuch zählt je Client und Code höchstens einmal pro Minute. |
| `POST /wpac/v1/sales` | `wpac_manage_program` | Verkauf melden (Payload wie `wpac_record_sale`, siehe CONTRACT.md); `200` = verarbeitet, `202` = zur Wiederholung eingeplant, `400` = ungültige Daten (nicht wiederholen), `503` + `Retry-After` = nicht gespeichert (später erneut senden) |
| `POST /wpac/v1/refunds` | `wpac_manage_program` | Refund/Storno/Chargeback melden; Statuscodes wie `/sales` (`202` auch, wenn die Erstattung auf ihren Verkauf wartet: `queued`/`parked`) |
| `POST /wpac/v1/reinstatements` | `wpac_manage_program` | Storno/Chargeback zurücknehmen (Payload wie `wpac_record_reinstatement`, siehe CONTRACT.md) |
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
