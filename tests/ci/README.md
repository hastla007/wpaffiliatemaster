# CI-Testseite

`install-wp.sh` baut eine Wegwerf-Installation für die Browser-Tests (`tests/e2e`) und den Pluginverbund-Test (`tests/cross-plugin/run.php`):

- lädt WordPress (`WP_VERSION`, Standard `latest`) und WooCommerce (`WC_VERSION`, Standard `latest-stable`),
- verlinkt dieses Plugin und mit `--siblings <dir>` auch `wp-couponmaster` und `wp-passmaster`,
- schreibt `wp-config.php` aus `WPAC_DB_NAME`, `WPAC_DB_USER`, `WPAC_DB_PASSWORD`, `WPAC_DB_HOST`, `WP_URL`,
- `setup.php` installiert WordPress und aktiviert die Plugins, `data.php` legt Seiten (Blocks-Checkout, „Partner werden“, „Partnerbereich“), Produkt, Partner `perf2`, Regel und eine Provision an.

Ausgabe: `PRODUCT_ID=…` und `PARTNER_CODE=…` für `tests/e2e/run.js`.

```bash
WPAC_DB_USER=root WPAC_DB_PASSWORD=root tests/ci/install-wp.sh /tmp/wp --siblings ..
```

```bash
cd /tmp/wp && php -S 127.0.0.1:8088 -t . /pfad/zu/affiliate-captain/tests/ci/router.php
```

## Smoke-Test und Mindestversionen

`smoke.php` prüft eine laufende Testseite ohne Browser: Aktivierung/Schema/Cron, eine WooCommerce-Bestellung mit Partnercode und ihre Vollerstattung, Bewerbungs- und Partnerseite, öffentliche REST-Routen (und Ablehnung ohne Login), alle Admin-Seiten des Plugins per HTTP-Login sowie neue PHP-Fehler/-Warnungen des Plugins in `debug.log`.

```bash
WP_VERSION=6.3 WC_VERSION=8.0.0 tests/ci/install-wp.sh /tmp/wp-min
php tests/ci/smoke.php /tmp/wp-min http://127.0.0.1:8088
```

Der CI-Job „Minimum versions“ führt das mit WordPress 6.3, WooCommerce 8.0.0 und PHP 7.4 aus (die im Plugin-Header erklärten Mindestversionen); der Browser-Job zusätzlich mit den neuesten Versionen.

## Plugin Check

Der CI-Job „Plugin Check“ installiert das gebaute ZIP und das offizielle Plugin `plugin-check` und wertet `wp plugin check affiliate-captain --format=json` mit `plugin-check.php` aus: Fehler (`ERROR`) brechen ab, außer sie stehen mit Begründung in `plugin-check-ignore.txt`; Warnungen werden nur gezählt.
