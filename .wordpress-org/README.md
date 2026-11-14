# WordPress.org-Assets

Dieser Ordner wird vom Workflow „Deploy to WordPress.org“ (`.github/workflows/wordpress-org-deploy.yml`) in den SVN-Ordner `assets/` des Plugins kopiert. Er ist nicht Teil des Plugin-ZIPs (`.distignore`). Diese README und `banner.svg` werden vor dem Deploy entfernt – im SVN-Ordner `assets/` sind nur die Bilder der Plugin-Seite erlaubt.

| Datei | Größe | Zweck |
|---|---|---|
| `banner-772x250.png` | 772 × 250 px | Kopfbild der Plugin-Seite |
| `banner-1544x500.png` | 1544 × 500 px | Kopfbild für hochauflösende Bildschirme |
| `banner.svg` | Vektor | nur Quelle der beiden Banner-PNGs (wird nicht hochgeladen) |
| `icon-128x128.png` | 128 × 128 px | Icon in Suche und Plugin-Liste |
| `icon-256x256.png` | 256 × 256 px | Icon für hochauflösende Bildschirme |
| `icon.svg` | Vektor | Quelle der Icons; wird von WordPress.org bevorzugt |
| `screenshot-1.png` … `screenshot-8.png` | 1280 px breit | Screenshots; die Nummer gehört zur Zeile im Abschnitt `== Screenshots ==` von `readme.txt` |

Regeln von WordPress.org:

- Banner: wichtige Inhalte nicht in die unteren 150 px links (dort liegt der Plugin-Name), keine fremden Marken oder Logos (kein WordPress-/WooCommerce-Logo), kein „WordPress“ als größter Schriftzug.
- Screenshots zeigen das echte Plugin (keine Mockups) in englischer Oberfläche, ohne echte Kundendaten; Beschriftung kommt aus `readme.txt`, nicht aus dem Bild.
- Lokalisierte Screenshots sind möglich: `screenshot-1-de_DE.png` usw.
- Dateien klein halten (PNG mit `pngquant`/`oxipng` oder auf 256 Farben reduziert, JPG Qualität ~80).

## Icon und Banner neu erzeugen

Icon und Banner sind eigene Grafiken (Steuerrad mit Kettenglied, Farben Marineblau `#14406b`/`#0a2340`, Gold `#f2b134`, Türkis `#1fb5a8`, Schrift Inter). Nach einer Änderung an `icon.svg`/`banner.svg` die PNGs in exakter Größe mit Playwright/Chromium rendern (SVG-Breite/-Höhe auf die Zielgröße setzen, `page.setContent()`, `page.screenshot({ clip })` mit `deviceScaleFactor: 1`).

## Screenshots

Aufgenommen mit Playwright (Chromium, 1280 px breit) auf der CI-Testseite (`tests/ci/install-wp.sh`, englische Oberfläche) mit erfundenen Demodaten (Partner wie „Demo Partner GmbH“, Domains `*.example`); Website-Adresse `http://demo-sailing.example` per `--host-resolver-rules` auf den lokalen PHP-Server gelegt.

1. Dashboard mit Kennzahlen und Ergebnissen je Partner
2. Partnerliste mit Status, Code, Auszahlungsdaten, Bedingungen und Login
3. Partnerdetails, Reiter Auszahlungsdaten
4. Provisionen (nach Datum sortiert) mit Haltefrist und Status
5. Auszahlungen mit Auszahlungslauf und Status
6. Einstellungen, Reiter Programm
7. Partnerbereich: Übersicht
8. Partnerbereich: Links & Codes mit Linkgenerator
