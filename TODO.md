# Audit-To-do — WP Affiliate Captain

Prüfstand: `main`, Commit `c418aba15c5e18afb29feebd7a597f286a8ca787` (2026-11-12).  
Diese Datei enthält nur neue Auditpunkte. Plugin-Code wurde nicht geändert.

## Bestätigte Befunde

- [x] **P2 — Release-Workflow: Shell-Injection über Tag-Namen.** Datei: `.github/workflows/ci.yml:85–86`. `github.ref_name` wird direkt in den Shell-Befehl interpoliert. Ein Tagname mit Shell-Metazeichen kann zusätzliche Befehle ausführen; der Job hat `contents: write`. **Auswirkung:** Bei einem Akteur mit Tag-Push-Rechten kann der Release-Runner samt Release-Credentials kompromittiert werden. **Maßnahme:** Ref als Umgebungsvariable übergeben, sicher quoten und Tag-Namen gegen ein enges Versionsmuster validieren. **Erledigt:** Ref-Namen kommen als Umgebungsvariable und werden gegen ein Versionsmuster geprüft; Schreibrechte nur noch im eigenen Job `publish` für Tags und main.

- [ ] **P2 — End-to-End- und Pluginverbund-Tests sind kein CI-Gate.** Dateien: `.github/workflows/ci.yml:27–71`, `tests/e2e/README.md:3`, `tests/cross-plugin/README.md:37–39`. Browser-/Blocks-Checkout- und pluginübergreifende Tests sind vorhanden, werden im Standard-CI aber nicht ausgeführt. **Auswirkung:** UI-, Checkout- und Integrationsregressionen können trotz grüner CI veröffentlicht werden. **Maßnahme:** Automatisierte Tests gegen eine wegwerfbare WP/WooCommerce-Testinstanz als CI-/Release-Gate einrichten oder eine dokumentierte manuelle Abnahme verpflichtend machen.

- [x] **P2 — Rate-Limit ohne externen Object Cache ist nicht atomar.** Datei: `src/Tracking/RateLimiter.php:46–50`. Transient lesen, prüfen und zurückschreiben sind getrennte Operationen. Parallele Anfragen können das Limit überschreiten; betroffen ist auch der öffentliche Code-Prüfpfad in `src/Rest/RestApi.php`. **Auswirkung:** Code-Enumeration und Missbrauchsschutz lassen sich unter Parallelität schwächen. **Maßnahme:** Atomaren Zähler verwenden oder die Schutzwirkung ausdrücklich als best-effort behandeln und Burst-Tests ergänzen. **Erledigt:** Atomarer Zähler per `INSERT … ON DUPLICATE KEY UPDATE`.

- [x] **P4 — Upgrade-Lock wird bei vollständiger Deinstallation nicht gelöscht.** Datei: `uninstall.php:54–56`. `wpac_upgrade_lock` fehlt in der Options-Löschliste. **Auswirkung:** Verwaister Optionsdatensatz bleibt nach Pluginentfernung zurück. **Maßnahme:** Lock-Option bei vollständiger Datenlöschung entfernen und Deinstallationsfall testen. **Erledigt:** Lock und alle neuen Optionen stehen in der Löschliste.

- [ ] **P2 — Statische und Abhängigkeitsprüfungen fehlen in CI.** Dateien: `.github/workflows/ci.yml:26,53,84`, `composer.json`. CI führt PHP-Syntaxlint und projektinterne Tests aus; Coverage ist deaktiviert und es gibt kein PHPCS/WPCS-, PHPCompatibility-, statisches Sicherheits- oder Composer-Abhängigkeits-Audit. **Auswirkung:** Stil-/Kompatibilitätsfehler und bekannte Schwachstellen in Abhängigkeiten können unentdeckt bleiben. **Maßnahme:** passende automatisierte Prüfschritte wählen und für Release verpflichtend machen.

## Verdachtsfälle — Laufzeitprüfung erforderlich

- [x] **P2 — Sale/Refund-Race kann Refund verlieren.** Datei: `src/Integrations/WooCommerce/OrderSync.php:145–153`. Der Refund-Pfad beendet die Verarbeitung, wenn die Sale-Provision noch nicht im Ledger steht. Bei gleichzeitig laufender Sale-Verarbeitung könnte der Refund vor deren Commit ankommen und verworfen werden; danach könnte die volle Provision gebucht werden. **Mögliche Auswirkung:** Überhöhte Partnerprovision nach zeitgleich verarbeitetem Refund. **Maßnahme:** Race-Condition-Test ergänzen und Refund bis zur Sale-Buchung idempotent vormerken oder erneut einreihen. **Erledigt:** In-Flight-Marker mit atomarem Lock; Erstattungen warten in der Queue, bis der Verkauf gebucht ist, und werden danach automatisch geweckt.

- [x] **P2 — Fehler beim Start/Commit einer Datenbanktransaktion werden ignoriert.** Datei: `src/Core/Db.php:66–80`. Rückgabewerte von `START TRANSACTION` und `COMMIT` werden nicht geprüft. Bei DB-/Tabellenproblemen kann der Callback weiterlaufen oder Erfolg zurückgeben, obwohl die Transaktion nicht aktiv bzw. nicht bestätigt ist. **Mögliche Auswirkung:** Teilweise geschriebene oder inkonsistente Ledger-/Auszahlungsdaten. **Maßnahme:** Query-Fehler prüfen, Fehler vor Erfolg zurückmelden, Rollback-/Engine-Verhalten testen und relevante Tabellen auf InnoDB verifizieren. **Erledigt:** START/COMMIT werden geprüft und werfen `db_transaction`/`db_commit`.

- [x] **P2 — Parallele/aktive Upgrade-Sperre schützt den laufenden Pluginbetrieb nicht vollständig.** Datei: `src/Core/Installer.php:148–166`. Bei aktivem Lock kehrt der Request aus `maybe_upgrade()` zurück, während der normale Plugin-Boot weiterlaufen kann; außerdem wird ein veralteter Lock nicht atomar übernommen. **Mögliche Auswirkung:** Neue Pluginlogik trifft während eines Upgrades auf ein altes Schema oder zwei Requests führen Migrationen parallel aus. **Maßnahme:** Pluginbetrieb bis zum abgeschlossenen Upgrade sicher begrenzen, Lock-Übernahme atomar machen und parallele/deploy-nahe Upgrade-Tests ergänzen. **Erledigt:** Atomarer `Db::lock()` (INSERT IGNORE + Compare-and-set), Migration nur in Admin/Cron/WP-CLI, Cron wartet bis das Schema aktuell ist.

- [x] **P2 — Datenbankschema-Version wird trotz möglicher Migrationsfehler hochgesetzt.** Datei: `src/Core/Installer.php:559–568`. Rückgaben/Fehler von `dbDelta()` und Migrationsschritten werden nicht erkennbar validiert, bevor `wpac_db_version` aktualisiert wird. **Mögliche Auswirkung:** Nach einer fehlgeschlagenen Teilmigration kann der nächste Request das Schema als aktuell ansehen und nicht erneut migrieren. **Maßnahme:** Kritische Tabellen/Spalten und Migrationsschritte verifizieren; Versionsoption erst nach bestätigtem Erfolg setzen und simulierten DB-Fehler testen. **Erledigt:** `verify_schema()` prüft Tabellen/Spalten/InnoDB; Version erst nach Erfolg, sonst Admin-Hinweis und Wiederholung nach 5 Minuten.

## Prüfgrenzen

- Geprüft wurden WP Affiliate Captain main (Plugin-Code unverändert seit Commit c418aba) sowie die ungemergten Lizenzclient-PRs #9–#12 ausschließlich per Code-/Diff-Review. Die Lizenzclient-Befunde beziehen sich auf ihre PR-Branches, nicht auf das aktuell veröffentlichte Plugin.
- Kein bestätigter direkter Authentifizierungs-, SQL-Injection- oder XSS-Befund in den geprüften Hauptpfaden oder den ergänzend geprüften Clientdateien festgestellt. Fehlerfälle, Race Conditions und Lizenzabläufe brauchen gezielte Laufzeit-/Staging-Tests.

## Ergänzende Befunde aus der zweiten Prüfrunde (2026-11-12)

### Bestätigte Befunde

- [x] **P2 — Retry wird als eingereiht gemeldet, obwohl der Queue-Schreibvorgang fehlschlagen kann.** Dateien: `src/Sales/ContractApi.php:69–78,109–123`; `src/Core/Jobs.php:27–64`. Contract API gibt `wpac_queued` zurück, ohne den Queue-Schreibvorgang zu prüfen; `Jobs::enqueue()` ignoriert Insert-/Update-Ergebnisse. **Auswirkung:** Bei DB-Ausfall oder fehlender Jobtabelle wird ein Sale/Refund nicht gebucht und auch nicht erneut versucht, obwohl der Aufrufer „queued“ erhält. **Maßnahme:** Queue-Erfolg zurückmelden, Schreibfehler behandeln und nur bei persistiertem Retryjob „queued“ bestätigen. **Erledigt:** `Jobs::enqueue()` meldet Erfolg; sonst `wpac_not_queued` + Action `wpac_contract_not_queued`.

- [x] **P2 — Auszahlungsantrag kann trotz fehlgeschlagenem Insert als angelegt erscheinen.** Datei: `src/Payouts/PayoutRequests.php:79–106`. Das Ergebnis von `$wpdb->insert()` wird nicht geprüft; ID, Audit-Eintrag und Admin-Mail folgen trotzdem. **Auswirkung:** Partner oder Admin sehen eine Anfrage, die nicht gespeichert wurde (möglicherweise mit veralteter/ungültiger ID). **Maßnahme:** Insert-Ergebnis/ID validieren; Audit und Mail nur nach bestätigtem Insert ausführen und Fehler sichtbar zurückgeben. **Erledigt:** Insert wird geprüft, Antrag in einer Transaktion mit Zeilensperre.

- [x] **P2 — Ablehnung eines Auszahlungsantrags meldet Erfolg trotz fehlgeschlagenem Statusupdate.** Datei: `src/Payouts/PayoutRequests.php:185–211`. Update-Ergebnis und betroffene Zeilen werden nicht geprüft; danach folgen Audit, Partner-Mail und `true`. **Auswirkung:** Antrag bleibt offen, während Admin und Partner von einer Ablehnung ausgehen. **Maßnahme:** Statusübergang atomar prüfen und Folgeaktionen nur nach bestätigtem Statuswechsel ausführen. **Erledigt:** Update nur mit `status='requested'`, Zeilenzahl wird geprüft.

- [x] **P3 — Monatsberichte gehen bei Mailfehlern still verloren.** Datei: `src/Reports/MonthlyMail.php:29–45,71–83,107–111,127–151,217–219`. Monat wird vor dem Versand als gesendet markiert; `wp_mail()`-Ergebnisse werden ignoriert und Batch-Offsets laufen weiter. **Auswirkung:** Temporäre SMTP-Fehler können Admin- oder Partnerberichte endgültig verlieren, ohne sichtbare Fehlermeldung. **Maßnahme:** Versandstatus pro Empfänger speichern, Fehler protokollieren und fehlgeschlagene Mails idempotent erneut versuchen oder im Admin zur Nachbearbeitung anzeigen. **Erledigt:** Fehlgeschlagene Mails werden gemerkt und bis zu 3× wiederholt, danach Audit-Eintrag.

### Verdachtsfälle — Datenbank-/Konkurrenztests erforderlich

- [x] **P2 — Saldo-Zusammenfassung kann von Buchungseinträgen abweichen.** Datei: `src/Commissions/Balance.php:69–86`. Ein Ledger-Eintrag wird geprüft, danach wird das Update der Balance-Tabelle nicht geprüft. **Mögliche Auswirkung:** Bei Update-Fehler kann der Eintrag committen, während der zusammengefasste Saldo/Hash-Zustand unverändert bleibt. **Maßnahme:** Update-Ergebnis validieren und bei Fehler Exception/Rollback auslösen; DB-Fehlertest ergänzen. **Erledigt:** `Balance::post()` prüft, dass genau eine Zeile aktualisiert wurde.

- [x] **P3 — Paralleles Absenden kann mehrere offene Auszahlungsanträge erzeugen.** Dateien: `src/Payouts/PayoutRequests.php:49–52,79–95`; Schema: `src/Core/Installer.php:544–557`. Die Prüfung auf offenen Antrag und das Insert sind getrennte Schritte; ein eindeutiger/atomarer Schutz pro Partner fehlt. **Mögliche Auswirkung:** Doppelte Anträge und Benachrichtigungen, möglicherweise mit konkurrierender Auszahlungssumme. **Maßnahme:** Atomare Absicherung gegen mehrere offene Anträge und Konkurrenztest ergänzen. **Erledigt:** `SELECT … FOR UPDATE` auf die Partnerzeile in der Transaktion.

- [x] **P3 — TLS-Prüfung beim Schutztest für Belegdateien ist deaktiviert.** Datei: `src/Payouts/Statement.php:71–86`. Der Loopback-Probe-Request setzt `sslverify => false` und cached sein Ergebnis bis zu einem Tag. **Mögliche Auswirkung:** Ein Netzwerk-MITM könnte den Schutztest verfälschen und die Admin-Warnung über öffentlich erreichbare PDF-Belege unterdrücken; kein direkter Download-Bypass wurde nachgewiesen. **Maßnahme:** TLS-Verifikation aktiv lassen oder unverifizierbare Antworten als „unknown“ behandeln und nicht als geschützt cachen. **Erledigt:** `sslverify` entfernt.

## UX/UI-Prüfung

- Statische Prüfung von Admin, Partnerportal, Registrierung, CSS und JavaScript: keine zusätzlichen bestätigten UX-/Accessibility-Befunde aufgenommen. Live-/visuelle Playwright-/axe-Prüfung war mangels Staging-Zugang nicht möglich; fehlender CI-Gate steht bereits oben.

## Ergänzende Prüfung der offenen Lizenzclient-PRs #9–#12 (2026-11-12)

**Geltungsbereich:** Alle Punkte unten betreffen ungemergte Branches. Sie sind keine Aussage, dass diese Funktionen bereits in main enthalten sind.

### Bestätigte Befunde und Anforderungslücken

- [ ] **P2 — Lizenzrechte werden noch nicht an Premium-Funktionen angeschlossen.** Branch codex/wplc-entitlement-helper, Datei: src/Licensing/LicenseClient.php:101–119; Feature-Gates werden in den Client-PRs nicht an den tatsächlichen Produkt-Einstiegspunkten aufgerufen. Die PRs enthalten Aktivierung/Helper, aber laut Beschreibung keine tatsächlichen Gates. **Auswirkung:** Bezahlte Funktionen bleiben ungeschützt, bis Rechteprüfungen an allen relevanten Admin-, Portal-, API- und Hintergrundpfaden verdrahtet sind. **Maßnahme:** Jede bezahlte Funktion mit Entitlement-Prüfung absichern und fehlende/abgelaufene/widerrufene Rechte testen.

- [ ] **P2 — Admin zeigt bei Serverfehler den Aktivierungsdialog trotz gespeicherter Aktivierung.** Branch codex/wplc-client, Datei: src/Licensing/AdminPage.php:42–47,60–75. Bei einem Fehler von check_entitlements() wird der lokale Status auf null gesetzt und danach die Aktivierungsform angezeigt. **Auswirkung:** Betreiber können eine aktive Lizenz für nicht aktiviert halten und eine erneute Aktivierung versuchen. **Maßnahme:** Lokale Aktivierung sichtbar lassen, Verbindungsfehler separat anzeigen und Retry/Deaktivierung anbieten.

- [ ] **P2 — CI blockiert die Lizenzclient-PRs wegen unvollständiger deutscher Übersetzungen.** Dateien: .github/workflows/ci.yml:68–71 und languages/wp-affiliate-captain-de_DE.po (fehlende Einträge); die letzten geprüften Runs für #9–#12 scheiterten am Job „German translation complete and up to date“, während PHP-Matrix und MariaDB-Job erfolgreich waren. **Auswirkung:** Release-ZIP wird übersprungen und die Client-PRs können nicht vollständig durch CI abgenommen werden. **Maßnahme:** Neue Lizenzstrings übersetzen, Übersetzungskataloge synchronisieren und CI der vier PR-Branches erneut ausführen.

- [ ] **P3 — Offline-Cache-Fehlertexte verwenden den falschen Textdomain.** Branch codex/wplc-offline-cache, Datei: src/Licensing/LicenseClient.php:150,155,160. Cachefehler werden mit voucher-captain statt wp-affiliate-captain übersetzt. **Auswirkung:** Diese Meldungen fehlen im Affiliate-Captain-Übersetzungskatalog. **Maßnahme:** Textdomain korrigieren und alle Cachemeldungen in der Übersetzungsvollständigkeitsprüfung abdecken.

- [ ] **P2 — Entitlement-Helper bindet die Rechte nicht an das Produkt der Antwort.** Branch codex/wplc-entitlement-helper, Datei: src/Licensing/LicenseClient.php:101–119. allows_entitlement() prüft den Präfix des abgefragten Schlüssels, aber nicht product_key in der signierten Antwort. **Auswirkung:** Wird versehentlich eine gültig signierte Antwort eines anderen Produkts übergeben und enthält sie einen passenden Affiliate-Schlüssel, kann das Helper den Zugriff erlauben. **Maßnahme:** Produkt-ID exakt vergleichen und Cross-Product-Antworten testen.

- [ ] **P2 — Aktivierung meldet Erfolg, ohne das lokale Speichern zu prüfen.** Branch codex/wplc-client, Dateien: src/Licensing/LicenseClient.php:69–79 und src/Licensing/AdminPage.php:87–92. update_option() wird nicht geprüft; nach signierter Serveraktivierung wird Erfolg zurückgegeben. **Auswirkung:** Bei einem Datenbank-Schreibfehler kann der Server einen Slot reservieren, während das Plugin kein lokales Geheimnis zum Prüfen oder Deaktivieren speichert. **Maßnahme:** Speichern validieren, erst danach Erfolg anzeigen und bei Fehlschlag Remote-Aktivierung kompensieren oder nachvollziehbar abgleichen.

### Verdachtsfälle — Laufzeit-/Fehler-Injektion erforderlich

- [ ] **P2 — Wiederholte Aktivierungsversuche können zusätzliche Lizenzslots erzeugen.** Branch codex/wplc-client, Datei: src/Licensing/LicenseClient.php:51–77. Jeder Versuch erzeugt eine neue Installations-ID und ein neues Geheimnis; bei serverseitig angenommener Aktivierung mit verlorener Antwort werden die Zugangsdaten lokal nicht gespeichert und der nächste Versuch ist eine neue Aktivierung. **Mögliche Auswirkung:** Verwaiste/duplizierte Aktivierungen oder ausgeschöpfte Site-Limits. **Maßnahme:** Idempotenzschlüssel bzw. stabile Pending-ID ergänzen und den Lost-Response-Retry testen.

- [ ] **P2 — Signierte, aber unvollständige Aktivierungsantwort kann als Erfolg gelten.** Branch codex/wplc-client, Datei: src/Licensing/LicenseClient.php:73–79,219. Ein signiertes data-Feld ohne gültige Aktivierungs-ID wird als leeres Array akzeptiert; activation_id wird dann als 0 gespeichert und die Adminseite zeigt Erfolg. **Mögliche Auswirkung:** Nicht nutzbare lokale Aktivierung und eventuell reservierter Server-Slot. **Maßnahme:** Pflichtfelder, Status und positive ID vor Speicherung/Erfolg validieren; fehlerhafte signierte Antworten testen.

- [ ] **P2 — Widerruf könnte bei fehlgeschlagener Cachelöschung durch Offline-Rechte überlebt werden.** Branch codex/wplc-offline-cache, Datei: src/Licensing/LicenseClient.php:98–114. Nach einer Onlineantwort mit inaktivem Status wird delete_option() für den Cache nicht geprüft. **Mögliche Auswirkung:** Bei Datenbankfehler und anschließendem Serverausfall könnte ein alter, gültig signierter aktiver Cache noch Rechte liefern. **Maßnahme:** Cache-Invalidierung fehlersicher machen und Widerruf + DB-Fehler + Serverausfall testen.

- [ ] **P2/P3 — Deaktivierung kann Erfolg melden, obwohl lokale Zugangsdaten nicht gelöscht wurden.** Branch codex/wplc-client, Datei: src/Licensing/LicenseClient.php:119–127. Nach bestätigter Serverdeaktivierung wird delete_option() nicht geprüft. **Mögliche Auswirkung:** Veraltete Credentials bleiben lokal und UI/erneute Aktivierung können einen inkonsistenten Zustand zeigen. **Maßnahme:** Lokale Löschung prüfen und einen wiederherstellbaren Fehlerzustand anzeigen.

- [ ] **P3 — Lizenzseite blockiert beim Rendern bis zum Netzwerktimeout.** Branch codex/wplc-client, Dateien: src/Licensing/AdminPage.php:42–43 und src/Licensing/LicenseClient.php:180. Statusabfrage läuft synchron mit bis zu 20 Sekunden Timeout. **Auswirkung:** Die Einstellungsseite kann bei langsamem/unerreichbarem Lizenzserver lange hängen. **Maßnahme:** Zuletzt verifizierten Status aus Cache anzeigen und Aktualisierung separat bzw. mit kürzerem Timeout durchführen.

- [ ] **P2/P3 — Update-Erkennung ist möglicherweise von einem angemeldeten Admin abhängig.** Branch codex/wplc-updater, Datei: src/Licensing/PremiumUpdater.php:39–42. Der Update-Transient wird nur befüllt, wenn current_user_can('update_plugins') wahr ist. **Mögliche Auswirkung:** WP-Cron-/Background-Updatechecks ohne angemeldeten Nutzer könnten Premium-Updates nicht zuverlässig entdecken. **Maßnahme:** Admin-, Cron- und Auto-Update-Checks in einer echten WP-Instanz testen und Update-Metadaten nicht unnötig an UI-Rechte koppeln.

- [ ] **P3 — Update-ZIP-Download hat kein Größenlimit.** Branch codex/wplc-updater, Datei: src/Licensing/PremiumUpdater.php:130–147. Der erlaubte HTTPS-Download wird in eine temporäre Datei gestreamt, bevor die Prüfsumme kontrolliert wird; es gibt keine maximale Bytezahl. **Mögliche Auswirkung:** Ein fehlerhafter oder kompromittierter erlaubter Paketserver kann den verfügbaren Speicher erschöpfen. **Maßnahme:** Maximale Paketgröße/Content-Length validieren, bei Überschreitung abbrechen und temporäre Datei sicher entfernen.

- [ ] **P3 — Lizenzzugangsdaten und Cache werden bei vollständiger Deinstallation nicht entfernt.** Branch codex/wplc-client / codex/wplc-offline-cache, Dateien: src/Licensing/AdminPage.php:87–88; src/Licensing/LicenseClient.php:77,104; uninstall.php:54–56. Die Lizenzoptionen wpac_wplc_server_url, wpac_wplc_credentials und wpac_wplc_credentials_entitlements_cache fehlen in der Uninstall-Löschliste. **Auswirkung:** Lizenzgeheimnis und Cache bleiben nach vollständiger Pluginentfernung in der WordPress-Datenbank. **Maßnahme:** Optionen in die vollständige Uninstall-Bereinigung aufnehmen und Retain-Data-Einstellung respektieren.

## Teststatus der offenen PRs

- Die geprüften letzten CI-Läufe auf codex/wplc-client, codex/wplc-updater, codex/wplc-entitlement-helper und codex/wplc-offline-cache scheiterten jeweils am deutschen Übersetzungsjob. PHP-Matrix und MariaDB-Integration waren in diesen Läufen erfolgreich; Release-ZIP wurde übersprungen.
- Die Client-PRs wurden nur statisch geprüft; sie wurden nicht in einer laufenden WordPress-/WooCommerce-Staging-Instanz ausgeführt.

## Zugehörige Launch-Checkliste
Die übergreifende Lizenzmanager-, Verkaufs- und Launch-Checkliste steht in [WP License Captain – Issue #36](https://github.com/hastla007/wp-license/issues/36). Diese TODO.md enthält die konkreten WP Affiliate Captain-Codeauditbefunde; Issue #36 bündelt die gemeinsamen Integrations- und Release-Aufgaben.

## Fünf-Experten-Prüfung (2026-11-12, Runde 3)

Fünf unabhängige Prüfungen (Sicherheit, Geld/Geschäftslogik, Recht/Steuern, Betrieb, QA) auf Stand `077dd80`. Erledigt heißt: im Branch `claude/trusting-edison-bb7r9s` behoben und mit Integrationstests (Abschnitt V) abgesichert.

### Erledigt

- [x] **P1 — Kontoübernahme über E-Mail-Verknüpfung.** Beim Freischalten wurde ein Partner automatisch mit dem WordPress-Konto mit gleicher E-Mail verbunden; WordPress/WooCommerce prüfen Konto-E-Mails nicht. Jetzt nur noch nach Bestätigung per Einmal-Link an die Partner-E-Mail, eingeloggt im betreffenden Konto (`AffiliateRepository::confirm_user_link`).
- [x] **P2 — E-Mail-Wechsel ohne Warnung an die alte Adresse.** Alte Adresse wird informiert und erhält 30 Tage lang auch die Warnung „Bankdaten geändert“; höchstens 3 Wechsel pro Tag; Partnername nicht mehr in der Bestätigungsmail.
- [x] **P1 — WooCommerce-Retry zog Erstattungen doppelt ab.** Erstattungen, die schon im neu aufgebauten Verkauf stecken, schließen ihre wartenden Refund-Jobs (`Jobs::supersede_refunds`).
- [x] **P2 — Wartende Erstattung ging nach 8 Versuchen verloren.** Warten auf den Verkauf zählt nicht als Versuch; ein gebuchter Verkauf weckt seine Refund-Jobs, auch fehlgeschlagene (`Jobs::wake_refunds`).
- [x] **P2 — Race zwischen Refund-Prüfung und Verkaufs-Commit.** Refund wird erneut angewendet, wenn der Verkauf inzwischen gebucht ist.
- [x] **P2 — Parallele Erstattungen konnten doppelt umkehren (REPEATABLE READ).** Summen werden mit sperrendem Lesen ermittelt.
- [x] **P1 — Abgelehnter Staffelbonus wurde täglich neu gebucht.** Eine Ablehnung ist endgültig.
- [x] **P1 — Rückwirkende Boni für nie abgeschlossene Monate.** Neue Einstellung `tier_month_first`; ältere Monate bekommen keinen Bonus.
- [x] **P3 — Bonusbuchung nicht transaktional.** Zeile, Ereignis und Saldo in einer Transaktion.
- [x] **P2 — Fixe Regel ohne Wechselkurs wurde zu „exclude“** und schlug spezifischere Regeln und den Lifetime-Satz. Bleibt jetzt eine fixe Regel mit 0.
- [x] **P2 — Auszahlungsantrag durch Auszahlung in anderer Währung erledigt.** `fulfil()` prüft die Währung.
- [x] **P2 — Gutschrift für stornierte Auszahlung.** Der Cron erzeugt keine Belege mehr für stornierte Auszahlungen.
- [x] **P2 — Einzelauszahlung umging Prüfungen.** `PayoutService::blocked_reason()` (Profil, Self-Billing-Zustimmung) gilt jetzt in `create_for_affiliate()` für jeden Weg.
- [x] **P3 — Ablehnung ließ Korrektur in anderer Auszahlung stehen.** Ablehnung wird verweigert, solange eine Korrektur in einer Auszahlung ist.
- [x] **P2 — Ausländischer Kleinunternehmer.** Erhält Reverse Charge statt „Kleinunternehmer ohne USt“ (§13b UStG; EU-KU-Regelung mit Steuerberatung klären).
- [x] **P2 — Abschreibung blockierte die Löschung (DSGVO).** Offene Posten nach Nettosumme je Währung.
- [x] **P3 — Interner Abschreibungsgrund über REST sichtbar.** Partner sehen nur noch das Label; Portal zeigt „Abschreibung“.
- [x] **P3 — Recht „Steuerdaten ansehen“ durfte Bankdaten ändern.** Ändern braucht jetzt `wpac_manage_payouts`.
- [x] **P3 — Landing-Weiterleitung mit Slug konnte falschen Partner treffen.** Es wird wieder der Hauptcode übergeben.
- [x] **P3 — Private Gruppen: Autoren konnten Anmeldung öffnen; Widgets/Patterns scheiterten.** Nur noch Seiten von Partner-Managern oder die eingestellte Anmeldeseite; keine Inhaltsprüfung mehr.
- [x] **P3 — Fenster für unsignierte Alt-Cookies konnte von Angreifern gestartet werden.** Wird nicht mehr beim Lesen gestartet; `ts <= 0` abgelehnt.
- [x] **P3 — Code-Prüfung im Checkout ohne Rate-Limit.** 20/min wie REST.
- [x] **P3 — Release-Job mit Schreibrechten auf jedem Branch.** Eigener Job `publish` nur für Tags/main, `persist-credentials: false`.

### Offen — Geld/Logik

- [ ] **P2 — Pass Captain: Mehrzweckgutschein-Basis bei zusätzlichem Kampagnenrabatt zu hoch** (`wp-passmaster/src/Integrations/AffiliateCaptain/Adapter.php:339`): Steuer mit dem rabattierten Brutto skalieren. *Erst nach Abschluss der Arbeit des anderen Agenten in Pass Captain.*
- [ ] **P3 — Korrekturen werden in die aktuelle Gutschrift mit aktueller USt-Behandlung verrechnet** (§17 UStG). USt-Modus/-Satz je Provision speichern, Korrektur mit Bezug auf die ursprüngliche Gutschrift und eigenem Korrekturbeleg.
- [ ] **P3 — Betreiberdaten je Auszahlung einfrieren** (Storno-PDF soll die damaligen Betreiberdaten zeigen).
- [ ] **P3 — Reverse Charge verlangt `operator_vat_id`** (USt-IdNr. des Betreibers als Pflicht bei Reverse Charge).
- [ ] **P3 — Self-Billing ohne AGB-Seite:** Auszahlungen sollten blockiert oder deutlich gewarnt werden, wenn keine Partner-AGB mit Gutschriftklausel existieren.
- [ ] **P3 — MonthlyMail-Fehler pro Monat speichern** und den Retry-Lauf gegen Parallelität absichern; Watchdog für die Batch-Kette.
- [ ] **P3 — Reports zählen Abschreibungen nicht.**

### Offen — Recht/Datenschutz

- [ ] **P2 — Löschung verändert Ledger-Texte** (`commission_events.reason`, `attributions.note`): stattdessen Verarbeitung einschränken und nach Aufbewahrung löschen; `payouts.note`, Audit `payout.cancelled` und `commissions.description` werden dagegen gar nicht bereinigt.
- [ ] **P2 — Abgelehnte Bewerber:** Bereinigung ruft das volle `erase()` auf (löscht auch Kundenverknüpfungen); eigener Modus nur für Bewerbungsdaten.
- [ ] **P2 — Datenschutztext ergänzen:** Beacon, Speicherfristen, Rechtsgrundlagen, Empfänger; Hinweis im Anmeldeformular.
- [ ] **P2 — Beacon:** nur Referrer-Host senden; Einwilligung prüfen.
- [ ] **P2 — Aufbewahrung nach Land** (DE 8 J. Buchungsbelege, AT 7, CH 10); Audit-Bereinigung soll `retention_hold` beachten und `commission.%` ausnehmen; Aufbewahrung der Zustimmungen festlegen.
- [ ] **P3 — Gast-AGB-Nachweis:** Text zum Zeitpunkt der Zustimmung sichern.
- [ ] **P3 — Export-Lücken** in der Datenauskunft; Transient `wpac_email_change_` bei Löschung entfernen.
- [ ] **P3 — Angemeldete Bewerber:** Konto-E-Mail gilt als bestätigt; Bestätigungsmail wie bei Gästen erwägen (Identitätsvortäuschung).
- [ ] **P3 — Partner kann Name/Website nach Freigabe ohne Prüfung ändern** (öffentlich in der Empfehlungsbox).
- [ ] Fehlende Funktionen: Widerspruchsablauf (PDF-Text „14 Tage“ falsch), VIES-Prüfung der USt-IdNr., Einschränkung nach Art. 18, Verarbeitungsverzeichnis-Vorlage, Datenschutzseite für Partner, Jahresübersicht, Datenlöschung nach Deinstallation per WP-CLI.

### Offen — Betrieb

- [ ] **P2 — `verify_schema()`**: Tabellennamen ohne Groß/Kleinschreibung vergleichen (`lower_case_table_names`), nur eigene Tabellen, falsche Engine nur als Warnung, Datenschutz/Aufbewahrung nie blockieren, Alarm wenn hängend.
- [ ] **P2 — Stündlicher Cron:** try/catch pro Schritt, Jobs zuerst, `approve_due` mit Keyset-Paginierung, Backoff für Belegerzeugung.
- [ ] **P2 — Job-Abarbeitung** mit Zeitbudget/5-Minuten-Event; Versuchszähler beim erneuten Einreihen behalten.
- [ ] **P2 — Belegerzeugung:** Auszahlung vorher per `statement_file='~gen:token'` beanspruchen (Race).
- [ ] **P3 — Täglicher Purge alter In-Flight-Marker; eigene Tabelle für Rate-Limit-Zeilen.**
- [ ] **P3 — Beacon:** bei frischem HTML überspringen, Rate-Limit auf `/touch`, WooCommerce-Session laden.
- [ ] **P3 — Admin-Hinweis:** welche Partner nach dem Upgrade wegen fehlender Betreiberdaten/Profil/AGB nicht ausgezahlt werden.
- [ ] CI-Matrix: MySQL 8, `lower_case_table_names`, Multisite, Redis, echtes dbDelta, Upgrade vom letzten Tag, Pluginverbund-Workflow, genau das getestete ZIP veröffentlichen, Concurrency-Group; Actions auf Commit-SHAs pinnen.
- [ ] Fehlende Funktionen: Site Health, WP-CLI-Befehle, Monitoring/Alarm, Action Scheduler, `wc_get_logger`, Wartungsmodus, Multisite-Aktivierung.

### Offen — Fehlende Geschäftsabläufe

- [ ] Verlängerungen erben den Partner des Abos (heute nur über Gutschein/Lifetime-Link).
- [ ] Wiederherstellen nach gewonnener Rückbuchung oder reaktivierter Bestellung.
- [ ] Bestellung nachträglich einem anderen Partner zuordnen (A storniert, B gebucht, mit Audit).
- [ ] „Alle fehlgeschlagenen Jobs wiederholen“-Button.
- [ ] Rückforderung vor Zahlung (Korrektur aus noch nicht exportierter Auszahlung nehmen/warnen); Mahnablauf für negative Salden beendeter Partner.
- [ ] Geplanter Abgleich `Balance::verify_all()` mit Alarm; Auszahlung in Nicht-EUR-Währungen (CSV / „extern bezahlt“).
- [x] Beispiel-Connector: Touch serverseitig (`server_touch`), kein `hold_until` aus Auftragsdaten, feste eigene Vertragsversion.
