# WordPress.org-Assets

Dieser Ordner wird vom Workflow „Deploy to WordPress.org“ (`.github/workflows/wordpress-org-deploy.yml`) in den SVN-Ordner `assets/` des Plugins kopiert. Er ist nicht Teil des Plugin-ZIPs (`.distignore`). Diese README wird vor dem Deploy entfernt – im SVN-Ordner `assets/` sind nur Bilder erlaubt.

Vorhanden sind alle Dateien der Tabelle. Quellen und Skripte zum Neu-Erzeugen liegen in `bin/wporg-assets/` (nicht im ZIP): `icon.svg` und `banner.html` → `node bin/wporg-assets/render.js`; Screenshots einer Demo-Seite (fiktive Daten, example.com) → `bin/wporg-assets/screenshots/shots.sh` (Ablauf im Skriptkopf); danach `python3 bin/wporg-assets/optimize.py` (Palette mit 256 Farben).

Dateinamen (exakt so, PNG oder JPG, Banner/Icons zusätzlich als SVG möglich):

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

1. Dashboard mit Kennzahlen und Bericht nach Partner, Inhalt und Kampagne
2. Partnerliste mit Status, Codes, Guthaben, Auszahlungsdaten und Bedingungen
3. Partnerdetails mit Kennzahlen und Kontoauszug (Integritätsprüfung)
4. Provisionsregeln (alle Partner, Gruppen, Produkte, Kategorien, SKUs, Ausschlüsse)
5. Auszahlungslauf mit Vorschau und Liste der Auszahlungen
6. Partnerbereich (Portal) mit Partnerlink, Kennzahlen und Provisionen
7. Bewerbungsformular (Block) mit Feldern je Partnergruppe
8. Import & Export (CSV-Import mit Prüfung)
