# Gesamttest über alle Plugins

Prüft das Zusammenspiel von **WP Affiliate Captain**, **WP Voucher Captain**, **WP Pass Captain**, WooCommerce und (optional) **LearnBoating** in einer echten WordPress-Installation – genau an den Übergängen, die die Einzeltests der Plugins nicht abdecken.

> Nur gegen eine **Test-Installation** laufen lassen: Die Skripte legen Partner, Produkte, Events und Bestellungen an, bezahlen, erstatten und sagen einen Termin ab.

## Teil 1: Gutschein, Ticket, Scanner, Absage (`run.php`)

Voraussetzung: WooCommerce, WP Affiliate Captain, WP Voucher Captain und WP Pass Captain aktiv; Zahlart „Überweisung“ (bacs) aktiv.

```bash
php tests/cross-plugin/run.php /pfad/zu/wordpress example.test
```

| Schritt | Geprüft |
|---|---|
| 1. Geschenkkarte (50 €) über WooCommerce mit Partnercode | Provision auf den Gutscheinverkauf; Voucher Captain stellt den Gutschein aus |
| 2. Ticket in Pass Captain (119 € inkl. 19 % USt), 50 € per Gutschein, gleicher Partnercode | Gutschein verbraucht (Journal 0 €); Ticketprovision nur auf das Netto des bar bezahlten Teils – keine doppelte Provision auf den Gutschein |
| 3. Check-in im gemeinsamen Scanner (`wp_pass_captain_checkin_service_v1`) | Code erkannt, Vorschau „gültig“, Check-in, derselbe Scan zählt nicht doppelt |
| 4. Termin abgesagt (automatische Erstattung) | Erstattung, Ticketprovision vollständig umgekehrt, 50 € zurück auf dem Gutschein (wieder einlösbar), Provision des Gutscheinverkaufs bleibt, Affiliate-Konten konsistent |

Die letzte Ausgabezeile ist JSON mit Partner, Partnercode und den angelegten Bestellungen.

## Teil 2: LearnBoating – Kauf über die App (`learnboating-app.sh`)

Voraussetzung: LearnBoating (Lernplattform mit Katalog) und der LearnBoating-Partnerprogramm-Connector aktiv, ein Kunden-Login für die Mobile-API, Webserver erreichbar.

```bash
WP_ROOT=/pfad/zu/wordpress WP_URL=http://example.test LB_USER=kunde LB_PASS=geheim \
  bash tests/cross-plugin/learnboating-app.sh PARTNERCODE
```

`PARTNERCODE` z. B. aus der letzten Zeile von Teil 1.

Geprüft: Die Mobile-API hängt Partnercode und `utm_campaign=app` an den Shop-Link; im Shop wird ohne Marketing-Einwilligung kein Affiliate-Cookie gesetzt; Kauf per Store API (mit Kundenkonto, Zustimmung „digitale Inhalte“); Zuordnung mit Methode `link`, Kampagne `app`; Provision bis nach Geld-zurück-Garantie und Widerrufsfrist gesperrt; Konten konsistent.

## Wann laufen lassen

Vor jedem Release eines der Plugins und nach Änderungen an den Schnittstellen (Vertrag `wpac_*`, Gutschein-Brücke, Scanner-Dienst, Mobile-API). Beide Skripte enden mit Exit-Code 0, wenn alles bestanden ist.
