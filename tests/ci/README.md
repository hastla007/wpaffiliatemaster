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
cd /tmp/wp && php -S 127.0.0.1:8088 -t . /pfad/zu/wp-affiliate-captain/tests/ci/router.php
```
