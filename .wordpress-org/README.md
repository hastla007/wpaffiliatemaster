# WordPress.org-Assets

Dieser Ordner wird vom Workflow „Deploy to WordPress.org“ (`.github/workflows/wordpress-org-deploy.yml`) in den SVN-Ordner `assets/` des Plugins kopiert. Er ist nicht Teil des Plugin-ZIPs (`.distignore`). Diese README wird vor dem Deploy entfernt – im SVN-Ordner `assets/` sind nur Bilder erlaubt.

Noch keine Bilder vorhanden. Benötigt werden (Dateinamen exakt so, PNG oder JPG, Banner/Icons zusätzlich als SVG möglich):

| Datei | Größe | Zweck |
|---|---|---|
| `banner-772x250.png` | 772 × 250 px | Kopfbild der Plugin-Seite |
| `banner-1544x500.png` | 1544 × 500 px | Kopfbild für hochauflösende Bildschirme |
| `icon-128x128.png` | 128 × 128 px | Icon in Suche und Plugin-Liste |
| `icon-256x256.png` | 256 × 256 px | Icon für hochauflösende Bildschirme |
| `icon.svg` (optional) | Vektor | wird bevorzugt, wenn vorhanden |
| `screenshot-1.png` … `screenshot-8.png` | beliebig, empfohlen 1280 px breit, max. 10 MB | Screenshots; die Nummer gehört zur Zeile im Abschnitt `== Screenshots ==` von `readme.txt` |

Regeln von WordPress.org:

- Banner: wichtige Inhalte nicht in die unteren 150 px links (dort liegt der Plugin-Name), keine fremden Marken oder Logos (kein WordPress-/WooCommerce-Logo), kein „WordPress“ als größter Schriftzug.
- Screenshots zeigen das echte Plugin (keine Mockups) in englischer Oberfläche, ohne echte Kundendaten; Beschriftung kommt aus `readme.txt`, nicht aus dem Bild.
- Lokalisierte Screenshots sind möglich: `screenshot-1-de_DE.png` usw.
- Dateien klein halten (PNG mit `pngquant`/`oxipng`, JPG Qualität ~80).

Reihenfolge der Screenshots (muss zu `readme.txt` passen):

1. Dashboard mit Einrichtungs-Checkliste und Kennzahlen
2. Partnerliste mit Status, Codes und Guthaben
3. Partnerdetails mit Codes, Auszahlungsdaten und Kontoauszug
4. Provisionsregeln und Partnergruppen
5. Auszahlungslauf mit Vorschau, Gutschriften und SEPA-Export
6. Partnerbereich (Portal) mit Partnerlink, Statistik und Provisionen
7. Bewerbungsformular (Block)
8. Import & Export (CSV-Import mit Prüfung)
