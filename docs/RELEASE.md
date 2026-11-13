# Release und WordPress.org-Deploy

Entwicklerdokument, nicht Teil des Plugin-ZIPs (`.distignore`).

## 1. Version vorbereiten

1. Version an drei Stellen gleich setzen: Header `Version:` und `WPAC_VERSION` in `affiliate-captain.php`, `Stable tag:` in `readme.txt`.
2. `readme.txt`: Abschnitt `== Changelog ==` (neueste Version oben) und `== Upgrade Notice ==` (bei Schemaänderung „Backup vorher“) ergänzen; `Tested up to` und im Plugin-Header `WC tested up to` auf die in CI geprüften Versionen setzen.
3. Übersetzungen: `python3 bin/i18n.py missing de_DE` muss leer sein, dann `python3 bin/i18n.py pot && python3 bin/i18n.py build de_DE`.
4. Mindestversionen ändern sich nur zusammen: `Requires at least` (Header + readme), `minimum_wp_version` in `phpcs.xml.dist`, `WP_VERSION`/`WC_VERSION`/PHP im CI-Job „Minimum versions“. Aktuell WordPress 6.3 (Blöcke mit `apiVersion: 3`), WooCommerce 8.0, PHP 7.4.

## 2. GitHub-Release

Tag `vX.Y.Z` pushen (oder Merge nach `main` mit neuer Version). Die CI baut das ZIP mit `bin/build-zip.sh` (Dateiauswahl = alles außer `.distignore`; das Skript bricht ab, wenn Laufzeitdateien fehlen oder Entwicklerdateien wie `README.md`, `tests/`, `languages/de_DE.json` enthalten sind), lässt alle Jobs laufen (inkl. „Minimum versions“ und „Plugin Check“) und veröffentlicht genau das getestete ZIP.

## 3. Deploy nach WordPress.org (SVN)

Workflow `.github/workflows/wordpress-org-deploy.yml` – **nur manuell** (Actions → „Deploy to WordPress.org“ → „Run workflow“):

| Eingabe | Bedeutung |
|---|---|
| `tag` | vorhandener Release-Tag, z. B. `v0.11.0` (die CI dieses Tags muss grün sein) |
| `slug` | der von WordPress.org vergebene Slug (siehe `docs/FREE-PREMIUM.md`, Abschnitt Name/Slug) |
| `dry_run` | Standard **an**: bereitet den SVN-Commit vor, sendet ihn aber nicht. Erst nach einem erfolgreichen Probelauf mit `false` wiederholen. |

Voraussetzungen:

- Repository-Secrets (oder Secrets der Umgebung `wordpress-org`) **`SVN_USERNAME`** und **`SVN_PASSWORD`** (WordPress.org-Benutzer mit Commit-Recht auf das Plugin; das SVN-Passwort aus dem WordPress.org-Profil, nicht das Login-Passwort). Ohne beide Secrets tut der Workflow nichts außer einer Warnung.
- Empfohlen: in den Repository-Einstellungen für die Umgebung `wordpress-org` „Required reviewers“ setzen, damit jeder Deploy freigegeben werden muss.

Ablauf: Tag auschecken → `bin/build-zip.sh <tag>` (Versionen, Unit-Tests, Dateiauswahl) → `10up/action-wordpress-plugin-deploy` (auf Commit-SHA gepinnt, Version 2.3.0) kopiert alle Dateien außer `.distignore` nach `trunk/`, legt `tags/<version>/` an und spiegelt `.wordpress-org/` (ohne die README) nach `assets/`.

Bilder für `assets/` (Banner, Icons, Screenshots): siehe `.wordpress-org/README.md`. Die Reihenfolge der Screenshots muss zum Abschnitt `== Screenshots ==` in `readme.txt` passen.

## 4. Vor der ersten Einreichung

- Name/Slug ohne „WP“-Präfix festlegen (Befund O21, `docs/FREE-PREMIUM.md`).
- Free/Premium-Entscheidung (Befund K5): keine Lizenzprüfung, kein eigener Updater im eingereichten Plugin.
- CI-Job „Plugin Check“ muss ohne Fehler laufen; Ausnahmen nur mit Begründung in `tests/ci/plugin-check-ignore.txt`.
- `readme.txt` mit `== External services ==` (VIES, Loopback) – bei jedem neuen externen Aufruf ergänzen.
