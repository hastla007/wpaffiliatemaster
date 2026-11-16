# Einreichung bei WordPress.org (A9) – Anleitung

> Entwicklerdokument, nicht Teil des Plugin-ZIPs (`.distignore`). Stand 16.11.2026, Plugin 0.16.0.

## 1. Was schon bereit ist (geprüft)

- **Plugin Check** (offizielles WordPress.org-Werkzeug) läuft in jedem CI-Lauf und ist grün (Job „Plugin Check (WordPress.org)“).
- **Name und Slug:** „Affiliate Captain“, Ordner/Slug `affiliate-captain` (kein „WP“ im Namen, Richtlinie 17; `docs/FREE-PREMIUM.md` §6).
- **Lizenz:** GPLv2 or later; keine Lizenzprüfung, keine gesperrten Funktionen, kein eigener Updater im Kern (Richtlinien 5 und 8).
- **readme.txt:** Kopf, Beschreibung, FAQ, Screenshots, Changelog, Upgrade Notice; Abschnitt „External services“ (VIES nur nach Einschalten, Loopback-Anfrage an die eigene Website, optional S3-Speicher).
- **Bilder:** Icon, Banner, acht Screenshots in `.wordpress-org/` (werden beim Deploy nach `assets/` gespiegelt).
- **ZIP:** `bin/build-zip.sh` baut `dist/affiliate-captain-<version>.zip` ohne Entwicklerdateien; die CI hängt jedes Release mit SHA-256 an GitHub an.
- **Deploy-Workflow:** `.github/workflows/wordpress-org-deploy.yml` (nur manuell, mit Probelauf), siehe `docs/RELEASE.md` §3.

## 2. Was nur die Inhaberin tun kann

1. **WordPress.org-Konto** festlegen (Entscheidung E5). Der Benutzername muss in `readme.txt` unter `Contributors:` stehen – heute steht dort `wpaffiliatecaptain`. Gibt es dieses Konto nicht, den echten Benutzernamen eintragen (kleiner PR).
2. **Zwei-Faktor-Anmeldung** im WordPress.org-Konto einschalten (Voraussetzung für SVN-Commits).
3. **Einreichen:** <https://wordpress.org/plugins/developers/add/> – das ZIP des aktuellen GitHub-Releases hochladen (`affiliate-captain-0.16.0.zip`). Beim Formular den Slug `affiliate-captain` prüfen (er ist nach der Freigabe nicht mehr änderbar).
4. **Rückfragen des Review-Teams** beantworten (per E-Mail; meist innerhalb weniger Tage bis Wochen). Rückfragen hier im Repository als Aufgabe eintragen – die Entwicklung arbeitet sie ab.
5. **Nach der Freigabe:** im WordPress.org-Profil das SVN-Passwort erzeugen und in GitHub (Repository → Settings → Secrets → Actions, oder Umgebung `wordpress-org`) `SVN_USERNAME` und `SVN_PASSWORD` anlegen. Empfohlen: für die Umgebung `wordpress-org` „Required reviewers“ setzen.
6. **Erster Deploy:** Actions → „Deploy to WordPress.org“ → `tag` = aktuelles Release, `slug` = vergebener Slug, zuerst mit `dry_run` an, dann aus.

## 3. Hinweise für das Review

- **PHP 8.5 als Mindestversion:** zulässig; WordPress.org zeigt die Angabe an, und WordPress verhindert die Installation auf älterem PHP. Die Reichweite ist dadurch anfangs kleiner (Hosts mit PHP 8.5).
- **Premium-Add-on:** liegt außerhalb von WordPress.org; im Kern gibt es keinen Kauf-Hinweis außerhalb der eigenen Seiten (Richtlinie 11).
- **Externe Dienste:** VIES (EU-USt-IdNr.-Prüfung, standardmäßig aus), die Loopback-Prüfung der eigenen Website und optional S3-kompatibler Speicher (nur mit `WPAC_S3_*`-Konstanten) sind in `readme.txt` offengelegt.
