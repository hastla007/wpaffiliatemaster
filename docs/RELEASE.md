# Release und WordPress.org-Deploy

Entwicklerdokument, nicht Teil des Plugin-ZIPs (`.distignore`).

## 1. Version vorbereiten

1. Version an drei Stellen gleich setzen: Header `Version:` und `WPAC_VERSION` in `affiliate-captain.php`, `Stable tag:` in `readme.txt`.
2. `readme.txt`: Abschnitt `== Changelog ==` (neueste Version oben) und `== Upgrade Notice ==` (bei Schemaänderung „Backup vorher“) ergänzen; `Tested up to` und im Plugin-Header `WC tested up to` auf die in CI geprüften Versionen setzen.
3. Übersetzungen: `python3 bin/i18n.py missing de_DE` muss leer sein, dann `python3 bin/i18n.py pot && python3 bin/i18n.py build de_DE`.
4. Mindestversionen ändern sich nur zusammen: `Requires at least` (Header + readme), `minimum_wp_version` in `phpcs.xml.dist`, `WP_VERSION`/`WC_VERSION`/PHP im CI-Job „Minimum versions“; für PHP zusätzlich `Requires PHP` (Header + readme) und die Laufzeitprüfung in `affiliate-captain.php`, die Anforderungszeile in `readme.txt`, `README.md`, `docs/HANDBOOK.md`, `composer.json` (`php`) samt `content-hash` und `platform` in `composer.lock`, `testVersion` in `phpcs.xml.dist`, `phpVersion` in `phpstan.neon.dist` (Prüfung „PHP-1“ in `tests/run.php`); für die Datenbank `Requirements::MYSQL`/`Requirements::MARIADB` (`src/Core/Requirements.php`) und die Images der CI-Jobs. Aktuell WordPress 7.0, WooCommerce 11.2, PHP 8.5, MySQL 9.7 (LTS), MariaDB 12.3 (LTS) (Entscheidung E10, 16.11.2026; WordPress 7.0 und WooCommerce 11.2 seit der Entscheidung MINVER-1 vom 17.11.2026). Ein älteres WordPress lädt das Plugin nicht (Admin-Hinweis), ein älteres WooCommerce wird nicht angebunden (Admin-Hinweis), der Rest läuft.
5. `WPAC_CONTRACT_VERSION` nach der Versionsregel in `docs/CONTRACT.md` erhöhen, wenn der Vertrag neue Funktionen, Hooks oder Felder bekommt (Minor) oder bricht (Major); Titel und „Änderungen“ in `docs/CONTRACT.md`, die Statuszeile der README und `tests/integration/bootstrap.php` nennen dieselben Werte. `php tests/run.php` (Prüfungen „R16-1“) schlägt fehl, wenn Header, `WPAC_VERSION`, `Stable tag`, der neueste Changelog-Eintrag, der neueste Eintrag unter `== Upgrade Notice ==`, README oder Vertragsdokument auseinanderlaufen oder ein „since X“ im Code über der Version liegt.

## 2. GitHub-Release

Tag `vX.Y.Z` pushen (oder Merge nach `main` mit neuer Version). Die CI baut das ZIP mit `bin/build-zip.sh` (Dateiauswahl = alles außer `.distignore`; das Skript bricht ab, wenn Laufzeitdateien fehlen oder Entwicklerdateien wie `README.md`, `tests/`, `languages/de_DE.json` enthalten sind, und wenn `readme.txt` keinen Changelog-Eintrag `= X.Y.Z =` für die Version hat), lässt alle Jobs laufen (inkl. „Minimum versions“ und „Plugin Check“) und veröffentlicht genau das getestete ZIP mit seiner SHA-256-Prüfsumme (Datei `affiliate-captain-X.Y.Z.zip.sha256` als Asset und in den Release-Notizen).

- Ein Tag-Release wird nur veröffentlicht, wenn der getaggte Commit in `main` enthalten ist (`git merge-base --is-ancestor`); Tags auf nicht gemergten Branches bauen und testen, veröffentlichen aber nichts.
- Der Upgrade-Job testet das Update von der **vorigen** Release-Version (neuester `vX.Y.Z`-Tag ohne Vorabversionen wie `-rc1` und ohne den Tag des laufenden Builds).
- Tag pushen **oder** nach `main` mergen – nicht beides für dieselbe Version: der zweite Lauf scheitert an `gh release create` (das Release existiert schon).

## 3. Deploy nach WordPress.org (SVN)

Workflow `.github/workflows/wordpress-org-deploy.yml` – **nur manuell** (Actions → „Deploy to WordPress.org“ → „Run workflow“):

| Eingabe | Bedeutung |
|---|---|
| `tag` | vorhandener Release-Tag, z. B. `v0.11.0` (die CI dieses Tags muss grün sein, sonst bricht der Workflow ab) |
| `slug` | der von WordPress.org vergebene Slug (siehe `docs/FREE-PREMIUM.md`, Abschnitt Name/Slug) |
| `dry_run` | Standard **an**: bereitet den SVN-Commit vor, sendet ihn aber nicht. Erst nach einem erfolgreichen Probelauf mit `false` wiederholen. |

Voraussetzungen:

- Repository-Secrets (oder Secrets der Umgebung `wordpress-org`) **`SVN_USERNAME`** und **`SVN_PASSWORD`** (WordPress.org-Benutzer mit Commit-Recht auf das Plugin; das SVN-Passwort aus dem WordPress.org-Profil, nicht das Login-Passwort). Ohne beide Secrets tut der Workflow nichts außer einer Warnung.
- Empfohlen: in den Repository-Einstellungen für die Umgebung `wordpress-org` „Required reviewers“ setzen, damit jeder Deploy freigegeben werden muss.

Der Workflow bricht ab, wenn es für den Commit des Tags keinen erfolgreichen CI-Lauf (`ci.yml`, Tag-Push oder Merge nach `main`) oder kein GitHub-Release des Tags gibt (Job-Token mit `actions: read`, kein zusätzliches Secret). Er baut das ZIP aus demselben Tag mit demselben Skript neu (gleiche Dateiauswahl, nicht byte-identisch wegen der Zeitstempel) – nicht das getestete Release-ZIP selbst; die Prüfsumme des veröffentlichten ZIPs steht im GitHub-Release (`.sha256`).

Ablauf: Tag auschecken → `bin/build-zip.sh <tag>` (Versionen, Changelog-Eintrag, Unit-Tests, Dateiauswahl) → `10up/action-wordpress-plugin-deploy` (auf Commit-SHA gepinnt, Version 2.3.0) kopiert alle Dateien außer `.distignore` nach `trunk/`, legt `tags/<version>/` an und spiegelt `.wordpress-org/` (ohne die README) nach `assets/`.

Bilder für `assets/` (Banner, Icons, Screenshots): siehe `.wordpress-org/README.md`. Die Reihenfolge der Screenshots muss zum Abschnitt `== Screenshots ==` in `readme.txt` passen.

## 3a. Rollback eines Releases

- GitHub: das Release auf „Pre-release“ setzen oder löschen; den Tag nicht neu vergeben (Nutzer und der Upgrade-Job orientieren sich an den Tags). Eine Korrektur erscheint als neue Patch-Version.
- WordPress.org: `Stable tag` in `trunk/readme.txt` auf die vorige Version zurücksetzen (SVN), damit neue Installationen sie erhalten; bereits aktualisierte Seiten bekommen erst die nächste Version.
- Hat das Release das Schema geändert, gilt für Betreiber HANDBOOK §15 (Downgrade nur zusammen mit dem Datenbank-Backup; die alte Version migriert nicht zurück). In den Release-Notizen der Korrekturversion darauf hinweisen.

## 4. Vor der ersten Einreichung

- Name/Slug ohne „WP“-Präfix: erledigt in 0.11.0 („Affiliate Captain“, `affiliate-captain`); Verfügbarkeit des Slugs vor der Einreichung prüfen.
- Kern frei, Premium als eigenes Add-on (K5, `docs/FREE-PREMIUM.md`): keine Lizenzprüfung, kein eigener Updater im eingereichten Plugin – abgesichert durch den Wächtertest in `tests/run.php` (A2) und den Plugin-Check-Job in CI.
- CI-Job „Plugin Check“ muss ohne Fehler laufen; Ausnahmen nur mit Begründung in `tests/ci/plugin-check-ignore.txt`.
- `readme.txt` mit `== External services ==` (VIES, Loopback) – bei jedem neuen externen Aufruf ergänzen.
