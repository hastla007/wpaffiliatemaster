# Zwei-Plugin-Test: Affiliate Captain + Form Captain

Prüft den Connector `affiliate_captain` (`src/Integrations/FormCaptain/`, `docs/FORM-CAPTAIN.md`) im echten Form Captain. Grundlage ist die WordPress-Testumgebung von Form Captain samt Contract-Testkit (`ConnectorContractAssertions`), wie beim Referenz-Connector von Form Captain.

## PHPUnit (`AffiliateCaptainConnectorTest.php`)

Voraussetzung: ein Checkout von `wp-formmaster` mit `composer install` (bringt PHPUnit, wp-phpunit und WordPress mit) und eine leere MariaDB-/MySQL-Datenbank für die Testsuite.

```bash
mysql -e "CREATE DATABASE IF NOT EXISTS wpac_fc_tests"
cd /pfad/zu/wp-formmaster
FC_DIR=$PWD WP_TESTS_DB_USER=wp WP_TESTS_DB_PASSWORD=wp \
  vendor/bin/phpunit -c /pfad/zu/wpaffiliatemaster/tests/form-captain/phpunit.xml.dist
```

`FC_DIR` ist ohne Angabe `../wp-formmaster` neben diesem Repository. Die Datenbank heißt standardmäßig `wpac_fc_tests` (`WP_TESTS_DB_NAME`), damit die Tests von Form Captain selbst nicht gestört werden.

| Test | Geprüft |
|---|---|
| `test_connector_follows_the_contract_and_is_opt_in` | `assertConnectorShape()`, Zustand „Erkannt“ nach der Installation, Aktion und Zuordnung, nichts Sensibles; ohne Aktivierung wird der Job geparkt |
| `test_test_run_has_no_side_effects` | Testlauf aus Integrationen: `test_ok`, keine Mail, kein Partner |
| `test_hand_over_waits_for_double_opt_in_and_creates_only_a_pending_partner` | Worker → `confirm_sent`, Bestätigungsmail mit Link, kein Partner vor dem Klick; danach `pending` trotz automatischer Freigabe, nicht zugeordnetes Feld (Telefon) kommt nicht an, kein Code, keine Provision, keine Regel |
| `test_duplicate_delivery_is_idempotent` | gleicher Idempotenzschlüssel, erneuter Lauf, `assertIdempotentCallback()`: gleiche `remote_id`, eine Mail, ein Partner |
| `test_existing_partner_gets_no_second_account` | `already_exists`, keine Partner-ID im Ergebnis, weiter ein Partner |
| `test_missing_consent_is_a_final_failure` | Dead-letter mit `terms_required` |
| `test_inactive_affiliate_captain_means_retry` | Connector nicht registriert (Plugin deaktiviert) → `action_not_found`, Wiederholung; wieder aktiv → zugestellt |
| `test_maintenance_mode_means_retry` | `affiliate_captain_unavailable` |
| `test_entry_deletion_removes_the_pending_application` | Löschung über `EntryDeletionService` (feuert `form_captain_entry_deleted_v1`): offene Bewerbung gelöscht, `pending`-Partner anonymisiert |
| `test_consent_withdrawal_erases_pending_and_keeps_approved_partners` | Widerruf über `ConsentService`: `pending` anonymisiert, freigegebener Partner unberührt |

Die Bestätigung per Klick ruft `Registration::finalize()` direkt auf, weil `handle_confirm()` mit einer Weiterleitung endet.

## Test-Website (`site-check.php`)

Ende-zu-Ende auf einer WordPress-Test-Installation, in der Form Captain und Affiliate Captain aktiv sind. Die Einsendung läuft über die öffentliche Funktion `form_captain_submit_v1()`. Danach folgen Worker, Mail, Bestätigung, Wiederholung und Löschung. Seite, Formular und Opt-in räumt das Skript am Ende wieder ab.

```bash
wp eval-file tests/form-captain/site-check.php
```

Zuletzt am 16.11.2026 mit WordPress 7.1.4, WooCommerce 11.3.0 und Form Captain 1.1.0 (Vertrag) gelaufen: alle 10 Prüfungen bestanden.
