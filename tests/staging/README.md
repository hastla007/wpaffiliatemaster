# Staging-Umgebung für den Live-Test PHP-2

Diese Umgebung bereitet die Aufgabe **PHP-2** aus `TODO.md` vor (Live-Test auf PHP 8.5/8.6,
MySQL 9.7, MariaDB 12.3). Sie ersetzt den Test auf dem echten Host nicht (dort mit einer Kopie
der Produktivdaten), macht ihn aber wiederholbar: WordPress mit PHP 8.5 (Apache), wahlweise
MariaDB 12.3 oder MySQL 9.7, WooCommerce und Affiliate Captain aus diesem Checkout.

Der Ordner gehört nicht zum Plugin-ZIP (`/tests` steht in `.distignore`).

## Inhalt

| Datei | Zweck |
|---|---|
| `compose.yml` | Dienste `db` (MariaDB/MySQL), `wordpress` (offizielles Image `wordpress:php8.5-apache`), `wpcli` (Einrichtung und WP-CLI, `wordpress:cli-php8.5`), `mail` (Mailpit, fängt alle E-Mails ab) |
| `setup.sh` | Einrichtung im `wpcli`-Container: WordPress, WooCommerce mit Testprodukt, Plugin; Umschalten Release ↔ Checkout |
| `env/*.env` | Datenbankauswahl: `mariadb-12.3`, `mysql-9.7` und zwei ältere Server für die Warnung in Website-Zustand (`mariadb-10.11`, `mysql-8.4`) |
| `mu-plugins/wpac-staging-mail.php` | leitet `wp_mail()` an Mailpit um |

Das offizielle Image `wordpress:php8.5-apache` gibt es (WordPress 7.1, PHP 8.5, geprüft am
16.11.2026); es bringt `mysqli`, `zip`, `intl`, `gd`, `exif`, `bcmath`, `sodium`, `dom` und
`mbstring` mit. Ein eigenes Image auf Basis von `php:8.5-apache` ist deshalb nicht nötig. Ein
Image `wordpress:php8.6-apache` gibt es noch nicht (PHP 8.6 erscheint am 19.11.2026); sobald es
da ist: `WPAC_WP_IMAGE=wordpress:php8.6-apache WPAC_CLI_IMAGE=wordpress:cli-php8.6`.

## Start (ein Befehl)

Im Ordner `tests/staging`:

```sh
docker compose up -d                                   # MariaDB 12.3 (Standard)
docker compose --env-file env/mysql-9.7.env up -d      # MySQL 9.7
```

Der Dienst `wpcli` richtet beim ersten Start alles ein und beendet sich danach. Fortschritt und
Ergebnis: `docker compose logs -f wpcli` (am Ende steht „Ready: …“).

| | MariaDB 12.3 | MySQL 9.7 | MariaDB 10.11 | MySQL 8.4 |
|---|---|---|---|---|
| WordPress | http://localhost:8085/wp-admin/ | :8086 | :8087 | :8088 |
| Mailpit (E-Mails) | http://localhost:8025 | :8026 | :8027 | :8028 |

**Anmeldung:** Benutzer `admin`, Passwort `admin` (nur Staging).

Jede `env`-Datei setzt einen eigenen Projektnamen: Jede Datenbank hat eigene Volumes, die Stapel
können gleichzeitig laufen. Bei jedem `docker compose`-Befehl dieselbe `--env-file` angeben.

Weitere Befehle:

```sh
docker compose run --rm wpcli wp wpac health          # Zustandsprüfungen (wie Website-Zustand)
docker compose run --rm wpcli wp wpac status          # Plugin-/Schemaversion, Cron, Warteschlange
docker compose exec wordpress tail -f wp-content/debug.log
docker compose down -v                                 # alles löschen (Datenbank, WordPress)
```

`Deprecated: … phar:///usr/local/bin/wp/…` in der Ausgabe von WP-CLI stammt von WP-CLI selbst
unter PHP 8.5, nicht vom Plugin.

**Konfiguration** (`wp-config.php` über `WORDPRESS_CONFIG_EXTRA`): `WP_DEBUG` und
`WP_DEBUG_LOG` an, Anzeige aus; feste Test-Schlüssel `WPAC_ENCRYPTION_KEY` und
`WPAC_CUSTOMER_KEY` (nie live verwenden); `WPAC_STATEMENT_DIR=/var/www/private/wpac-statements`
(außerhalb des Web-Roots, eigenes Volume).

**Variablen** (in der Shell oder in einer eigenen `env`-Datei):

| Variable | Standard | Bedeutung |
|---|---|---|
| `WPAC_PLUGIN` | `checkout` | `checkout`: Plugin = dieser Checkout (Symlink auf den schreibgeschützten Bind-Mount `/opt/affiliate-captain`); `release`: Plugin aus `WPAC_RELEASE_URL` installieren |
| `WPAC_RELEASE_URL` | Release-ZIP v0.15.0 auf GitHub | vorige Version für den Update-Test |
| `WPAC_WOO_VERSION` / `WPAC_WOO_SOURCE` | neueste | feste WooCommerce-Version bzw. ZIP (Pfad oder URL; eine ZIP in `tests/staging/` liegt im Container unter `/opt/wpac-staging/`) |
| `WPAC_DB_IMAGE`, `WPAC_WP_IMAGE`, `WPAC_CLI_IMAGE` | siehe `compose.yml` | Images |
| `WPAC_HTTP_PORT`, `WPAC_MAIL_PORT` | 8085, 8025 | Ports |

Die Container brauchen Internet (wordpress.org für WooCommerce und Sprachpakete, github.com für
das Release-ZIP). Ohne Verbindung richtet `setup.sh` WordPress und das Plugin trotzdem ein und
meldet, dass WooCommerce fehlt.

## Checkliste PHP-2

Ergebnis (Host, Versionen, Datum, Abweichungen) am Ende in `TODO.md` bei PHP-2 eintragen;
Abweichungen als neue Aufgaben. Die Punkte (1) bis (5) entsprechen der Aufgabe.

### (1) PHP 8.5 mit MariaDB 12.3 und mit MySQL 9.7

Alles einmal mit der Standard-Datenbank und einmal mit `--env-file env/mysql-9.7.env`.

**a) Aktivierung (frische Installation)**

1. `docker compose up -d`, warten bis `docker compose logs wpcli` „Ready“ zeigt.
2. `docker compose run --rm wpcli wp wpac status`: Plugin-Version 0.16.0, Schema `16 / 16`,
   „schema problems: none“.
3. Im Admin: keine Meldung „Datenbank-Update unvollständig“, Menü „Affiliate Captain“ vorhanden.

**b) Update von der vorigen Version (Release-ZIP v0.15.0)**

1. Neuer Stapel mit der Release-Version:
   ```sh
   docker compose down -v
   WPAC_PLUGIN=release docker compose up -d
   ```
   `setup.sh` installiert
   `https://github.com/hastla007/wpaffiliatemaster/releases/download/v0.15.0/affiliate-captain-0.15.0.zip`
   (Prüfsumme: dieselbe URL mit `.sha256`). `wp plugin list` zeigt 0.15.0. Ohne Internet im
   Container: ZIP nach `tests/staging/` laden (`*.zip` ist in `.gitignore`) und
   `WPAC_RELEASE_URL=/opt/wpac-staging/affiliate-captain-0.15.0.zip` setzen.
2. Mit 0.15.0 Testdaten anlegen: Partner, Partnerlink, eine Bestellung (Schritte c bis e), damit
   das Update echte Daten vorfindet.
3. Update auf diesen Checkout (Dateien ersetzen, Plugin bleibt aktiv, wie ein Update):
   ```sh
   docker compose run --rm wpcli update-to-checkout
   ```
   Alternativ realistischer: mit `bin/build-zip.sh` (im Repo-Hauptordner) die ZIP bauen und im
   Admin unter Plugins → Installieren → Plugin hochladen → „Aktuelle Version durch hochgeladene
   ersetzen“ einspielen (dafür vorher `WPAC_PLUGIN=release` beibehalten).
4. Eine Admin-Seite laden, dann `wp wpac status`: 0.16.0, Schema `16 / 16`, keine Meldung
   „Datenbank-Update unvollständig“; die Daten aus Schritt 2 sind da, `wp wpac verify` meldet
   keine Abweichung.
5. Optional der in PHP-2 genannte Weg 0.14.0 → 0.15.0 (Schema 15 → 16):
   `WPAC_RELEASE_URL=https://github.com/hastla007/wpaffiliatemaster/releases/download/v0.14.0/affiliate-captain-0.14.0.zip WPAC_PLUGIN=release docker compose up -d`,
   dann mit `WPAC_RELEASE_URL` auf 0.15.0 `docker compose run --rm wpcli use-release`.

**c) Partner-Bewerbung und Partnerlink**

1. Seite mit dem Block „Partner-Bewerbungsformular“ (oder `[wpac_affiliate_registration]`) und
   eine mit „Partnerbereich“ (`[wpac_affiliate_portal]`) anlegen, AGB-Seite in den Einstellungen
   wählen.
2. In einem privaten Fenster bewerben; die Bestätigungs-E-Mail kommt in Mailpit an (Link
   anklicken). Im Admin unter Partner freigeben.
3. Partnerbereich als Partner öffnen: Partnerlink kopieren, Steuerdaten (z. B. Unternehmen in
   DE mit USt-IdNr.) und Bankdaten (Test-IBAN `DE89370400440532013000`) eintragen.

**d) Testbestellung mit Partnerlink und automatischem Gutschein**

1. Einstellungen → Werbung → „Gutschein des Partners nach einem Partnerlink automatisch
   einlösen“ einschalten.
2. Beim Partner (Tab Codes) „Gutschein für Partner anlegen“, z. B. 10 %.
3. Neues privates Fenster: Partnerlink öffnen, Cookie-Hinweis bestätigen (je nach Cookie-Modus),
   „Testprodukt“ in den Warenkorb. Prüfen: Der Gutschein ist **automatisch** im Warenkorb.
4. Bestellung mit „Nachnahme“ abschließen – einmal im Classic-Checkout (Shortcode
   `[woocommerce_checkout]`) und einmal im Blocks-Checkout (Standardseite).
5. Im Admin unter Provisionen: je Bestellung eine Provision für den Partner, Status „ausstehend“.
   Bestellnotiz in WooCommerce nennt den Partner.

**e) Erstattung**

1. In WooCommerce eine der Bestellungen teilweise erstatten (z. B. 50 %): unter der Provision
   erscheint eine negative Korrekturzeile mit dem anteiligen Betrag.
2. Optional die andere Bestellung stornieren: die Provision wird vollständig storniert.

**f) Freigabe, Auszahlungslauf, Gutschrift-PDF und E-Rechnung (XML)**

1. Einstellungen → Auszahlungen & Gutschriften: Firmenkonto (IBAN, BIC, Name), Absender,
   eigene USt-IdNr. eintragen; Gutschriftverfahren an; „E-Rechnung (XML) mit der
   Gutschrift-E-Mail senden“ auf Automatisch.
2. Provisionen freigeben (Haltefrist abwarten nicht nötig: „Freigeben“ an der Provision).
3. Auszahlungen → Auszahlungslauf: Vorschau prüfen, Lauf starten. Die Gutschrift wird im
   Hintergrund geschrieben (`docker compose run --rm wpcli wp wpac jobs`, falls sie nicht
   erscheint).
4. Prüfen: Gutschrift-PDF (`GS-<Jahr>-000001`) lässt sich herunterladen, Beträge und USt
   stimmen; „E-Rechnung (XML)“ lässt sich herunterladen (Dateiname wie das PDF) und besteht den
   KoSIT-Validator (Stichprobe); die E-Mail in Mailpit hat PDF und – bei einem Partner mit
   deutscher USt – das XML im Anhang; die Datei liegt unter
   `/var/www/private/wpac-statements` (`docker compose exec wordpress ls -R /var/www/private`).
5. SEPA-XML herunterladen (pain.001.001.03, Summe = Bruttobetrag), dann „Als bezahlt markieren“.
6. Optional: Korrekturgutschrift zur bezahlten Auszahlung ausstellen (PDF `KG-…` und XML Typ
   384), Programm-Export als ZIP (Werkzeug-Seite oder `wp wpac export /tmp/x.zip`).

**g) Weitere Punkte aus PHP-2**

- Partnerbereich: alle Tabs öffnen, Gutschrift als Partner herunterladen.
- REST `wpac/v1`: z. B. mit Anwendungspasswort `curl -u admin:<app-passwort> http://localhost:8085/wp-json/wpac/v1/…` (Endpunkte in `docs/REST.md`).
- WP-CLI: `docker compose run --rm wpcli wp wpac health` ohne „critical“.

### (2) PHP 8.6 (nach dem 19.11.2026)

Wenn die Images erscheinen: `WPAC_WP_IMAGE=wordpress:php8.6-apache WPAC_CLI_IMAGE=wordpress:cli-php8.6 docker compose up -d`
und die Punkte a, b, d, f in Kurzform.

### (3) Website-Zustand und Datenbankversion

1. Mit MariaDB 12.3 und MySQL 9.7: Werkzeuge → Website-Zustand zeigt **keine** Empfehlung
   „Der Datenbankserver ist älter als die unterstützte Mindestversion“.
2. Älterer Server:
   ```sh
   docker compose --env-file env/mariadb-10.11.env up -d   # oder env/mysql-8.4.env
   ```
   Website-Zustand (http://localhost:8087/wp-admin/site-health.php) zeigt die Empfehlung mit der
   erkannten Version. Aktivierung, Testbestellung und Provision (Schritte a, d) funktionieren
   trotzdem – nichts wird blockiert.

### (4) PHP 8.4 oder älter

`WPAC_WP_IMAGE=wordpress:php8.4-apache WPAC_CLI_IMAGE=wordpress:cli-php8.4 docker compose up -d`:
Das Plugin lädt nicht, im Admin steht „Affiliate Captain benötigt PHP 8.5 oder neuer.“, die
Website funktioniert weiter. (Dass WordPress.org das Update dort nicht anbietet, lässt sich nur
nach der Veröffentlichung auf WordPress.org prüfen.)

### (5) debug.log

Nach allen Schritten:

```sh
docker compose exec wordpress grep -n "plugins/affiliate-captain/" wp-content/debug.log
```

Erwartet: keine Treffer (kein Deprecated, Notice oder Warning aus dem Plugin). Einträge aus
`phar:///usr/local/bin/wp` sind WP-CLI selbst. Da das Plugin per Symlink eingebunden ist, steht
im Log ggf. der echte Pfad `/opt/affiliate-captain/` – deshalb zusätzlich:
`docker compose exec wordpress grep -n "/opt/affiliate-captain/" wp-content/debug.log`.
