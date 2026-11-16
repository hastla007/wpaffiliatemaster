# Browser-Tests (Playwright + axe-core)

Prüfen Affiliate Captain in einem echten Chromium gegen eine laufende WordPress-Installation mit WooCommerce (Blocks-Checkout). Laufen in der CI (Job „Browser tests“) gegen eine frisch aufgebaute Testseite (`tests/ci/install-wp.sh`); ein Fehler blockiert das Release-ZIP.

| Bereich | Geprüft |
|---|---|
| Bewerbungsformular | Serverseitige Prüfung: `aria-invalid`, sichtbare Fehlermeldung, Eingaben bleiben erhalten; erfolgreiche Bewerbung; Ratenbegrenzung wird erkannt; axe |
| Partnerbereich | Kopier-Schaltfläche legt den Link in die Zwischenablage und sagt es per `aria-live` an; Bereichswechsel per Tastatur; `aria-current`; axe auf vier Bereichen |
| Verwaltung | abhängige Felder im Regel-Formular, Rückfrage beim Löschen, „Alle auswählen“; axe auf zehn Admin-Seiten |
| Block-Editor | Blöcke registriert, Vorschau „Partner-Willkommen“, Blöcke gültig |
| Blocks-Checkout | Feld „Partnercode“, ungültiger Code wird abgelehnt, Bestellung mit gültigem Code |
| Automatischer Partner-Gutschein | Einstellung über die Oberfläche ein/aus; Partnerlink → Produkt in den Warenkorb (klassisch und über die Store API wie die Blöcke) → Gutschein genau einmal im klassischen Warenkorb, Warenkorb- und Checkout-Block; Kunden-Gutschein „nur einzeln verwendbar“ bleibt; entfernter Gutschein kommt nicht wieder; Einstellung aus → kein Gutschein; Bestellung im Checkout-Block, Vermerk in der Bestellung, Zuordnung und Provision für den Partner |
| Passwortbestätigung | Überweisungsliste herunterladen und „Als bezahlt markieren“ führen zur Bestätigungsseite; falsches Passwort → Fehler, nichts ausgeführt; richtiges Passwort → Download startet bzw. Auszahlung bezahlt; innerhalb von 15 Minuten keine zweite Abfrage, neue Anmeldung fragt erneut; axe |

Alle Seiten werden auf JavaScript-Fehler der Plugin-Skripte und auf ungefangene Fehler überwacht; axe meldet nur WCAG 2.1 A/AA-Verstöße der Schwere „serious“ oder „critical“.

```bash
cd tests/e2e && npm install && npm run browsers
```

```bash
WP_URL=http://127.0.0.1:8088 ADMIN_USER=admin ADMIN_PASS=geheim PARTNER_USER=partner PARTNER_PASS=geheim PRODUCT_ID=14 PARTNER_CODE=ABCD-1234 node run.js
```

Die Daten für Partner-Gutschein und Auszahlungen legt `tests/ci/e2e-data.php <wp-dir> <url>` an (gibt `E2E_*`-Variablen aus, die `run.js` liest); ohne sie werden diese beiden Bereiche übersprungen. `SUITES=autoCoupon,reauth` führt nur die genannten Bereiche aus. Die Passwortbestätigung zählt Versuche (5 je Konto in 15 Minuten): bei wiederholten lokalen Läufen dazwischen warten.

Optional: `REGISTER_PATH`, `PORTAL_PATH`, `CHECKOUT_PATH`, `CART_PATH` (/cart/), `CLASSIC_CART_PATH` (/e2e-classic-cart/), `REF_PARAM` (partner). Playwright ist als Abhängigkeit eingetragen (`npm run browsers` lädt Chromium); ein bereits installiertes Playwright der Umgebung funktioniert ebenso. Plugins mit eigenen Pflichtfeldern im Checkout (z. B. Zustimmungs-Häkchen) für den Checkout-Test deaktivieren oder das Produkt so wählen, dass sie nicht greifen.
