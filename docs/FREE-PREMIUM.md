# Free/Premium und WordPress.org – Entscheidungsvorlage

> **Entscheidung des Inhabers erforderlich.** Dieses Dokument beschreibt Rahmenbedingungen, Optionen und eine Empfehlung. Im Code ist bewusst **keine** Lizenzprüfung umgesetzt (Befund K5, Runde 4). Bis zur Entscheidung bleiben die Lizenz-PRs #9–#12 ungemergt.
>
> Entwicklerdokument, nicht Teil des Plugin-ZIPs (`.distignore`).

## 1. Ausgangslage

- WP Affiliate Captain 0.10.0 ist vollständig funktionsfähig und GPL-2.0-or-later lizenziert; alle Funktionen sind ohne Lizenz nutzbar.
- Offene PRs bauen eine Lizenzierung direkt **in dieses Plugin** ein:
  - #9 „Add WP License Captain activation for Affiliate Captain“ (Aktivierung/Lizenzschlüssel),
  - #10 „Phase 17: Licensed premium updates with checksum verification“ (eigener Updater),
  - #11 „Add fail-closed entitlement gate helper“ (Funktionen sperren, wenn keine Berechtigung vorliegt),
  - #12 „Add signed offline entitlement cache“ (zwischengespeicherte Berechtigungen).
- Für ein Plugin im WordPress.org-Verzeichnis ist diese Kombination unzulässig (siehe 2.).

## 2. Regeln von WordPress.org (Plugin-Richtlinien)

| Richtlinie | Inhalt | Folge für die PRs |
|---|---|---|
| **5 – Trialware ist nicht erlaubt** | Ein Plugin auf WordPress.org darf keine Funktionen enthalten, die nach Ablauf einer Frist, ohne Bezahlung oder ohne Lizenzschlüssel gesperrt oder eingeschränkt werden. Code für Bezahlfunktionen darf nicht „versteckt“ im kostenlosen Plugin liegen. | #11 (Entitlement-Gate, „fail-closed“) und #12 sperren Funktionen im selben Plugin → Ablehnung bzw. Entfernung. |
| **8 – Keine ausführbaren Inhalte/Updates von Dritten** | Das Plugin darf keine Updates, Installationen oder Code von anderen Servern als WordPress.org laden. | #10 (eigener Updater) ist unzulässig. |
| 6 – SaaS ist erlaubt | Ein externer Dienst mit echter Funktion (nicht nur Lizenzprüfung) ist erlaubt, wenn er dokumentiert ist. | Reine Lizenzprüfung zählt nicht als Dienst. |
| 7 – Keine Datenübertragung ohne Einwilligung/Offenlegung | Externe Aufrufe müssen in der readme (`== External services ==`) offengelegt werden. | Eine Lizenz-Aktivierung (#9) müsste offengelegt werden – ist aber wegen 5 ohnehin falsch platziert. |
| 11 – Admin-Bereich nicht kapern | Upgrade-Hinweise sparsam, wegklickbar, nur auf eigenen Seiten. | Gilt auch für „Jetzt Premium kaufen“-Hinweise. |
| 17 – Marken | Siehe 6. (Name/Slug). | |

Erlaubt ist dagegen: ein **kostenloses, voll funktionsfähiges Plugin auf WordPress.org** plus ein **separates Premium-Add-on**, das außerhalb von WordPress.org verkauft und aktualisiert wird und sich über Hooks einklinkt. Das Add-on darf seinen eigenen Updater und seine Lizenzprüfung haben.

## 3. Optionen

### Option A – Alles Bestehende kostenlos auf WordPress.org, Premium als eigenes Add-on

- Das Plugin bleibt wie es ist (ohne #9–#12) und wird bei WordPress.org eingereicht.
- Premium = **eigenes Plugin** (z. B. „Affiliate Captain Pro“) mit eigenem Slug, eigener Textdomain, eigenem Header `Update URI: https://…` (damit WordPress es nie mit einem WordPress.org-Plugin verwechselt) und eigenem Updater/Lizenzclient (der Code aus #9, #10, #12 wandert dorthin).
- Anbindung nur über **dokumentierte Hooks und den öffentlichen Vertrag** (`docs/CONTRACT.md`, `examples/connector-addon/` als Muster): Filter für Provisionsberechnung, Betrugsregeln, Steuerbehandlung (`wpac_vat_treatment`), zusätzliche Integrationen, Berichte, Auszahlungsarten usw. Fehlende Erweiterungspunkte werden im kostenlosen Plugin als neutrale Hooks ergänzt (nützen auch Dritten).
- Kandidaten für Premium (neu zu bauen, nicht aus dem Bestand herausschneiden): weitere Auszahlungswege (PayPal/Stripe/Wise, W37), Mehrstufen-Provisionen (MLM), erweiterte Berichte/Exporte, Integrationen zu Fremdsystemen, White-Label-Portal, Priorisierter Support.
- **Bereits ausgelieferte Funktionen werden nicht nachträglich gesperrt** (sonst Vertrauensverlust und Verstoß gegen Richtlinie 5).

Vorteile: Reichweite und Vertrauen über WordPress.org, automatische Übersetzungen (translate.wordpress.org), Updates über die Infrastruktur von WordPress.org; klare Trennung, Lizenzfehler legen nie den Partnerbetrieb (Buchungen, Auszahlungen) lahm.
Nachteile: zwei Codebasen/Releases; Premium muss echten Mehrwert über Hooks liefern.

### Option B – Kein WordPress.org, Direktvertrieb mit eingebauter Lizenz

- PRs #9–#12 können (nach Review) in dieses Plugin; Vertrieb nur über die eigene Website.
- Vorteile: eine Codebasis, volle Kontrolle über Freischaltung und Updates.
- Nachteile: keine Sichtbarkeit im Verzeichnis, Updates/Übersetzungen selbst betreiben; „fail-closed“-Sperren treffen Buchhaltungsfunktionen (Gutschriften, Aufbewahrung, Datenschutz-Löschungen) – das ist rechtlich heikel: Belege und Löschpflichten dürfen nicht an einer abgelaufenen Lizenz hängen. Mindestens diese Teile müssten immer weiterlaufen.
- Ein späterer Wechsel zu WordPress.org erfordert den Rückbau.

### Option C – Hybrid

- Kostenloses Kernplugin auf WordPress.org (wie A), zusätzlich ein Premium-Paket im Direktvertrieb, das Kern + Add-on bündelt (z. B. Agentur-/Mehrseitenlizenzen, Support-Verträge).
- Lizenzclient und Updater existieren **nur im Add-on**; das Kernplugin enthält höchstens einen dezenten, wegklickbaren Hinweis auf der eigenen Einstellungsseite.

## 4. Folgen für die offenen PRs #9–#12

| PR | Option A / C | Option B |
|---|---|---|
| #9 Lizenz-Aktivierung | in das Add-on verschieben | übernehmen, Aufruf in `== External services ==` offenlegen |
| #10 Premium-Updater | in das Add-on verschieben (mit `Update URI`) | übernehmen |
| #11 Entitlement-Gate | im Kernplugin schließen; im Add-on nur Add-on-Funktionen sperren | übernehmen, aber nie Buchungen, Belege, Aufbewahrung, Datenschutz sperren |
| #12 Offline-Cache | in das Add-on verschieben | übernehmen |

Bei A/C werden die PRs gegen dieses Repository geschlossen und der Code in ein neues Repository für das Add-on übernommen.

## 5. Empfehlung

**Option A** (bzw. C, falls Bündel/Support-Verträge gewünscht sind). Das Plugin ist heute ein vollständiges Produkt; der größte Hebel ist die Sichtbarkeit auf WordPress.org. Premium sollte neue Funktionen liefern (zuerst weitere Auszahlungswege, die mit W37 ohnehin gefragt sind), nicht bestehende einschränken. Lizenzlogik gehört in ein Add-on, damit ein Lizenzproblem nie Provisionen, Gutschriften oder Löschfristen blockiert.

Nächste Schritte nach der Entscheidung für A/C:

1. Name und Slug festlegen (siehe 6.) und bei WordPress.org einreichen (Deploy-Workflow: `docs/RELEASE.md`).
2. PRs #9–#12 schließen, Code in das Add-on-Repository übernehmen.
3. Fehlende Hooks für das Add-on im Kernplugin ergänzen und in `docs/CONTRACT.md` dokumentieren.

## 6. Name und Slug („WP“-Präfix, Befund O21)

- Die Marken-Richtlinie von WordPress.org (Richtlinie 17 und die Liste geschützter Begriffe) erlaubt „WordPress“ gar nicht und **„WP“ nicht im Plugin-Namen bzw. am Anfang des Slugs**; das offizielle Plugin Check meldet für „WP Affiliate Captain“ / `wp-affiliate-captain` die Warnung `trademarked_term` (siehe CI-Job „Plugin Check“). Das Review-Team weist solche Einreichungen zurück oder vergibt einen anderen Slug.
- Vorschlag: Anzeigename **„Affiliate Captain – Partner Program & Commissions“**, Slug **`affiliate-captain`** (vor der Einreichung auf Verfügbarkeit prüfen; der Slug ist nach der Freigabe nicht mehr änderbar).
- Ein neuer Slug betrifft: Plugin-Ordner und Hauptdatei, Textdomain (`wp-affiliate-captain` → neuer Slug; alle `__()`-Aufrufe, `bin/i18n.py`, Dateinamen in `languages/`), `phpcs.xml.dist` (`text_domain`), Plugin-Pfad in `uninstall.php`/`tools/purge-retained.php`/Tests, Pass/Voucher-Captain-Abhängigkeiten, die den Pfad `wp-affiliate-captain/wp-affiliate-captain.php` prüfen. **Interne Präfixe (`wpac_`, Tabellen, Optionen, Hooks, REST-Namespace `wpac/v1`) bleiben unverändert** – keine Datenmigration nötig.
- Bestehende Installationen (GitHub-ZIP) wechseln nicht automatisch auf den neuen Ordner: Hinweis im Changelog „Plugin löschen (Daten bleiben, wenn ‚Alle Daten löschen‘ aus ist) und aus dem Verzeichnis neu installieren“.
- Entscheidung des Inhabers: Name/Slug festlegen, **bevor** die Textdomain umgestellt wird (ein Commit, nach den laufenden Übersetzungsänderungen).
