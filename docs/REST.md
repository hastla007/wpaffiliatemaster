# REST-API `wpac/v1`

Authentifizierung über WordPress (Cookie + `X-WP-Nonce` oder Application Passwords). Beträge immer in Minor Units (`*_minor`) plus Währung; Steuer- und Bankdaten werden nie ausgeliefert.

| Methode & Pfad | Berechtigung | Zweck |
|---|---|---|
| `GET /wp-json/wpac/v1/contract` | öffentlich | Vertrags- und Plugin-Version |
| `GET /wpac/v1/codes/{code}` | öffentlich, max. 20/min je Client | `{ "valid": true|false }` – keine Partnerdaten (Schutz vor Enumeration) |
| `POST /wpac/v1/sales` | `wpac_manage_program` | Verkauf melden (Payload wie `wpac_record_sale`, siehe CONTRACT.md); `202` = zur Wiederholung eingeplant |
| `POST /wpac/v1/refunds` | `wpac_manage_program` | Refund/Storno/Chargeback melden |
| `GET /wpac/v1/affiliates?status=&search=&page=&per_page=` | `wpac_manage_affiliates` | Partnerliste (`X-WP-Total`) |
| `GET /wpac/v1/affiliates/{id}` | `wpac_manage_affiliates` | Partner mit Codes |
| `GET /wpac/v1/commissions?affiliate_id=&status=&source=&order_id=&from=&to=` | `wpac_manage_affiliates` | Ledger |
| `GET /wpac/v1/reports/{partners|content|campaign}?from=&to=` | `wpac_manage_affiliates` | Berichte (in Programmwährung umgerechnet, `unconverted` listet Währungen ohne Kurs) |
| `GET /wpac/v1/me` | angemeldeter Partner | Code, Link, Landing-URL, Summen, verfügbarer Saldo |
| `GET /wpac/v1/me/commissions` | angemeldeter Partner | eigene Provisionen (ohne Kundendaten) |
| `GET /wpac/v1/me/payouts` | angemeldeter Partner | eigene Auszahlungen |

Beispiel (Application Password):

```bash
curl -u admin:APP_PASSWORD -H 'Content-Type: application/json' \
  -d '{"contract":"1.0.0","source":"pass-captain","order_id":"4711","currency":"EUR","items":[{"item_id":"1","net_minor":49900}],"partner_code":"SEGELSCHULE-NORD"}' \
  https://example.com/wp-json/wpac/v1/sales
```
