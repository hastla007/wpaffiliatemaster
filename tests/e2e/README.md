# Browser-Tests (Playwright + axe-core)

Prüfen Affiliate Captain in einem echten Chromium gegen eine laufende WordPress-Installation mit WooCommerce (Blocks-Checkout). Laufen in der CI (Job „Browser tests“) gegen eine frisch aufgebaute Testseite (`tests/ci/install-wp.sh`); ein Fehler blockiert das Release-ZIP.

| Bereich | Geprüft |
|---|---|
| Bewerbungsformular | Serverseitige Prüfung: `aria-invalid`, sichtbare Fehlermeldung, Eingaben bleiben erhalten; erfolgreiche Bewerbung; Ratenbegrenzung wird erkannt; axe |
| Partnerbereich | Kopier-Schaltfläche legt den Link in die Zwischenablage und sagt es per `aria-live` an; Bereichswechsel per Tastatur; `aria-current`; axe auf vier Bereichen |
| Verwaltung | abhängige Felder im Regel-Formular, Rückfrage beim Löschen, „Alle auswählen“; axe auf zehn Admin-Seiten |
| Block-Editor | Blöcke registriert, Vorschau „Partner-Willkommen“, Blöcke gültig |
| Blocks-Checkout | Feld „Partnercode“, ungültiger Code wird abgelehnt, Bestellung mit gültigem Code |

Alle Seiten werden auf JavaScript-Fehler der Plugin-Skripte und auf ungefangene Fehler überwacht; axe meldet nur WCAG 2.1 A/AA-Verstöße der Schwere „serious“ oder „critical“.

```bash
cd tests/e2e && npm install && npm run browsers
```

```bash
WP_URL=http://127.0.0.1:8088 ADMIN_USER=admin ADMIN_PASS=geheim PARTNER_USER=partner PARTNER_PASS=geheim PRODUCT_ID=14 PARTNER_CODE=ABCD-1234 node run.js
```

Optional: `REGISTER_PATH`, `PORTAL_PATH`, `CHECKOUT_PATH`. Playwright ist als Abhängigkeit eingetragen (`npm run browsers` lädt Chromium); ein bereits installiertes Playwright der Umgebung funktioniert ebenso. Plugins mit eigenen Pflichtfeldern im Checkout (z. B. Zustimmungs-Häkchen) für den Checkout-Test deaktivieren oder das Produkt so wählen, dass sie nicht greifen.
