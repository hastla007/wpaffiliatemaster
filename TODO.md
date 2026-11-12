# Audit-To-do — WP Affiliate Captain

Prüfstand: `main`, Commit `c418aba15c5e18afb29feebd7a597f286a8ca787` (2026-11-12).  
Diese Datei enthält nur neue Auditpunkte. Plugin-Code wurde nicht geändert.

## Bestätigte Befunde

- [ ] **P2 — Release-Workflow: Shell-Injection über Tag-Namen.** Datei: `.github/workflows/ci.yml:85–86`. `github.ref_name` wird direkt in den Shell-Befehl interpoliert. Ein Tagname mit Shell-Metazeichen kann zusätzliche Befehle ausführen; der Job hat `contents: write`. **Auswirkung:** Bei einem Akteur mit Tag-Push-Rechten kann der Release-Runner samt Release-Credentials kompromittiert werden. **Maßnahme:** Ref als Umgebungsvariable übergeben, sicher quoten und Tag-Namen gegen ein enges Versionsmuster validieren.

- [ ] **P2 — End-to-End- und Pluginverbund-Tests sind kein CI-Gate.** Dateien: `.github/workflows/ci.yml:27–71`, `tests/e2e/README.md:3`, `tests/cross-plugin/README.md:37–39`. Browser-/Blocks-Checkout- und pluginübergreifende Tests sind vorhanden, werden im Standard-CI aber nicht ausgeführt. **Auswirkung:** UI-, Checkout- und Integrationsregressionen können trotz grüner CI veröffentlicht werden. **Maßnahme:** Automatisierte Tests gegen eine wegwerfbare WP/WooCommerce-Testinstanz als CI-/Release-Gate einrichten oder eine dokumentierte manuelle Abnahme verpflichtend machen.

- [ ] **P2 — Rate-Limit ohne externen Object Cache ist nicht atomar.** Datei: `src/Tracking/RateLimiter.php:46–50`. Transient lesen, prüfen und zurückschreiben sind getrennte Operationen. Parallele Anfragen können das Limit überschreiten; betroffen ist auch der öffentliche Code-Prüfpfad in `src/Rest/RestApi.php`. **Auswirkung:** Code-Enumeration und Missbrauchsschutz lassen sich unter Parallelität schwächen. **Maßnahme:** Atomaren Zähler verwenden oder die Schutzwirkung ausdrücklich als best-effort behandeln und Burst-Tests ergänzen.

- [ ] **P4 — Upgrade-Lock wird bei vollständiger Deinstallation nicht gelöscht.** Datei: `uninstall.php:54–56`. `wpac_upgrade_lock` fehlt in der Options-Löschliste. **Auswirkung:** Verwaister Optionsdatensatz bleibt nach Pluginentfernung zurück. **Maßnahme:** Lock-Option bei vollständiger Datenlöschung entfernen und Deinstallationsfall testen.

- [ ] **P2 — Statische und Abhängigkeitsprüfungen fehlen in CI.** Dateien: `.github/workflows/ci.yml:26,53,84`, `composer.json`. CI führt PHP-Syntaxlint und projektinterne Tests aus; Coverage ist deaktiviert und es gibt kein PHPCS/WPCS-, PHPCompatibility-, statisches Sicherheits- oder Composer-Abhängigkeits-Audit. **Auswirkung:** Stil-/Kompatibilitätsfehler und bekannte Schwachstellen in Abhängigkeiten können unentdeckt bleiben. **Maßnahme:** passende automatisierte Prüfschritte wählen und für Release verpflichtend machen.

## Verdachtsfälle — Laufzeitprüfung erforderlich

- [ ] **P2 — Sale/Refund-Race kann Refund verlieren.** Datei: `src/Integrations/WooCommerce/OrderSync.php:145–153`. Der Refund-Pfad beendet die Verarbeitung, wenn die Sale-Provision noch nicht im Ledger steht. Bei gleichzeitig laufender Sale-Verarbeitung könnte der Refund vor deren Commit ankommen und verworfen werden; danach könnte die volle Provision gebucht werden. **Mögliche Auswirkung:** Überhöhte Partnerprovision nach zeitgleich verarbeitetem Refund. **Maßnahme:** Race-Condition-Test ergänzen und Refund bis zur Sale-Buchung idempotent vormerken oder erneut einreihen.

- [ ] **P2 — Fehler beim Start/Commit einer Datenbanktransaktion werden ignoriert.** Datei: `src/Core/Db.php:66–80`. Rückgabewerte von `START TRANSACTION` und `COMMIT` werden nicht geprüft. Bei DB-/Tabellenproblemen kann der Callback weiterlaufen oder Erfolg zurückgeben, obwohl die Transaktion nicht aktiv bzw. nicht bestätigt ist. **Mögliche Auswirkung:** Teilweise geschriebene oder inkonsistente Ledger-/Auszahlungsdaten. **Maßnahme:** Query-Fehler prüfen, Fehler vor Erfolg zurückmelden, Rollback-/Engine-Verhalten testen und relevante Tabellen auf InnoDB verifizieren.

- [ ] **P2 — Parallele/aktive Upgrade-Sperre schützt den laufenden Pluginbetrieb nicht vollständig.** Datei: `src/Core/Installer.php:148–166`. Bei aktivem Lock kehrt der Request aus `maybe_upgrade()` zurück, während der normale Plugin-Boot weiterlaufen kann; außerdem wird ein veralteter Lock nicht atomar übernommen. **Mögliche Auswirkung:** Neue Pluginlogik trifft während eines Upgrades auf ein altes Schema oder zwei Requests führen Migrationen parallel aus. **Maßnahme:** Pluginbetrieb bis zum abgeschlossenen Upgrade sicher begrenzen, Lock-Übernahme atomar machen und parallele/deploy-nahe Upgrade-Tests ergänzen.

- [ ] **P2 — Datenbankschema-Version wird trotz möglicher Migrationsfehler hochgesetzt.** Datei: `src/Core/Installer.php:559–568`. Rückgaben/Fehler von `dbDelta()` und Migrationsschritten werden nicht erkennbar validiert, bevor `wpac_db_version` aktualisiert wird. **Mögliche Auswirkung:** Nach einer fehlgeschlagenen Teilmigration kann der nächste Request das Schema als aktuell ansehen und nicht erneut migrieren. **Maßnahme:** Kritische Tabellen/Spalten und Migrationsschritte verifizieren; Versionsoption erst nach bestätigtem Erfolg setzen und simulierten DB-Fehler testen.

## Prüfgrenzen

- Geprüft wurde der veröffentlichte Hauptbranch oben. Die offenen Lizenzclient-PRs #9–#12 und andere nicht gemergte Branches sind nicht Bestandteil dieser Codeprüfung.
- Kein bestätigter direkter Authentifizierungs-, SQL-Injection- oder XSS-Befund in den geprüften Hauptpfaden festgestellt. Die Verdachtsfälle oben brauchen gezielte Laufzeit-/Concurrency-Tests.
