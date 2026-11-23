# Free/Premium und WordPress.org – Entscheidungsvorlage

> **Entschieden (2026-11-13): Option A** – das Kernplugin bleibt vollständig kostenlos und kommt auf WordPress.org; Premium wird ein eigenes Add-on-Plugin außerhalb von WordPress.org. Name und Slug: **„Affiliate Captain“ / `affiliate-captain`** (umgesetzt in 0.11.0, siehe 6.). Die Erweiterungspunkte für Add-ons stehen in `docs/CONTRACT.md` („Add-ons“).
>
> Umgesetzt im Kernplugin: keine Lizenzprüfung, keine gesperrten Funktionen (Wächtertest A2 in `tests/run.php`), Erweiterungspunkte für Add-ons im Vertrag (`docs/CONTRACT.md`, Abschnitt „Add-ons“). **Stand 16.11.2026:** Die PRs #9–#12 sind geschlossen. Das Add-on „Affiliate Captain Pro“ (Produktschlüssel `affiliate-captain-pro`, Rechtepräfix `affiliate.`) wird im Repository `hastla007/captain-pro` gebaut; was dort gebaut und was nur geplant ist, steht in Abschnitt 7. Die Abschnitte 1 bis 5 sind die Entscheidungsvorlage vom 13.11.2026 und beschreiben den Stand von damals.
>
> Entwicklerdokument, nicht Teil des Plugin-ZIPs (`.distignore`).

## 0. Hinweise auf Add-on-Funktionen im Kern (Entscheidung S1 vom 23.11.2026)

Die Regel vom 13.11.2026 („keine ausgegrauten Funktionen, keine Upgrade-Hinweise im Kern“) ist am 23.11.2026 vom Inhaber neu gefasst worden (S1):

- Einstellungen, die nur das Add-on hat, erscheinen im kostenlosen Kern als **graue Hinweiszeile am Ende des jeweiligen Reiters**: Kennzeichen „Pro“, Titel und ein Satz, was die Funktion kann, optional ein Verweis „Mehr erfahren“ (Filter `wpac_addon_hint_url`). Umgesetzt in `src/Admin/AddonHints.php` (SET-AC-05).
- **Kein** deaktiviertes Eingabefeld, **kein** Formularfeld, **kein** gespeicherter Wert, **kein** Code der beworbenen Funktion im Kern, keine Prüfung, ob etwas gekauft ist. Alles, was der Kern zeigt und speichert, funktioniert vollständig (Richtlinie 5).
- Nur auf den eigenen Einstellungsseiten, dezent, vom Betreiber abschaltbar: Einstellung „Hinweise auf Add-on-Funktionen anzeigen“ (Reiter Daten & Wartung, Schlüssel `addon_hints`) und Filter `wpac_show_addon_hints` (Richtlinie 11).
- Das Add-on meldet über `wpac_settings_rows` eine Zeile mit derselben Kennung an und ersetzt damit den Hinweis (echtes Feld oder Verweis auf seine Seite). Ob eine Freischaltung vorliegt, weiß nur das Add-on.
- Unverändert: kein Lizenzcode, keine Rechteprüfung, keine Namen von Rechteschlüsseln im Kern (Wächtertest A2); nichts, was heute frei ist, wandert hinter Pro. Hinweise gibt es nur für veröffentlichte Add-on-Funktionen; zurückgehaltene Funktionen und solche mit noch nicht freigegebenem Rechteschlüssel bekommen erst nach der Freigabe eine Zeile (`TODO.md`, SET-AC-05).

## 1. Ausgangslage

- Affiliate Captain 0.10.0 ist vollständig funktionsfähig und GPL-2.0-or-later lizenziert; alle Funktionen sind ohne Lizenz nutzbar.
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
| 11 – Admin-Bereich nicht kapern | Upgrade-Hinweise sparsam, wegklickbar, nur auf eigenen Seiten. | Gilt auch für „Jetzt Premium kaufen“-Hinweise. Umsetzung seit 0.20.0: graue Textzeilen nur auf der Einstellungsseite, abschaltbar (Abschnitt 0). |
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

- Die Marken-Richtlinie von WordPress.org (Richtlinie 17 und die Liste geschützter Begriffe) erlaubt „WordPress“ gar nicht und **„WP“ nicht im Plugin-Namen bzw. am Anfang des Slugs**; das offizielle Plugin Check meldet für „Affiliate Captain“ / `wp-affiliate-captain` die Warnung `trademarked_term` (siehe CI-Job „Plugin Check“). Das Review-Team weist solche Einreichungen zurück oder vergibt einen anderen Slug.
- Vorschlag: Anzeigename **„Affiliate Captain – Partner Program & Commissions“**, Slug **`affiliate-captain`** (vor der Einreichung auf Verfügbarkeit prüfen; der Slug ist nach der Freigabe nicht mehr änderbar).
- Ein neuer Slug betrifft: Plugin-Ordner und Hauptdatei, Textdomain (`wp-affiliate-captain` → neuer Slug; alle `__()`-Aufrufe, `bin/i18n.py`, Dateinamen in `languages/`), `phpcs.xml.dist` (`text_domain`), Plugin-Pfad in `uninstall.php`/`tools/purge-retained.php`/Tests, Pass/Voucher-Captain-Abhängigkeiten, die den Pfad `wp-affiliate-captain/wp-affiliate-captain.php` prüfen. **Interne Präfixe (`wpac_`, Tabellen, Optionen, Hooks, REST-Namespace `wpac/v1`) bleiben unverändert** – keine Datenmigration nötig.
- Bestehende Installationen (GitHub-ZIP) wechseln nicht automatisch auf den neuen Ordner: Hinweis im Changelog „Plugin löschen (Daten bleiben, wenn ‚Alle Daten löschen‘ aus ist) und aus dem Verzeichnis neu installieren“.
- **Umgesetzt (0.11.0):** Name „Affiliate Captain“, Ordner/Hauptdatei `affiliate-captain/affiliate-captain.php`, Textdomain `affiliate-captain`. Eine noch aktive alte Kopie (`wp-affiliate-captain`) wird beim ersten Aufruf der neuen deaktiviert; ihr Löschen entfernt keine Daten (die neue Kopie schützt die gemeinsamen Daten). Interne Kennungen (Präfix `wpac_`, Tabellen, Optionen, Hooks, Blocknamen, REST `wpac/v1`, Action-Scheduler-Gruppe) bleiben gleich. Verfügbarkeit des Slugs bei WordPress.org vor der Einreichung prüfen.

## Geplante Captain-Suite-Integrationen

Stand 16.11.2026: Die gemeinsame Spezifikation für Form Captain, Review Captain und Workflow Captain liegt als Entwurf vor und ist noch nicht abgenommen (Repository `wp-workflowmaster`, `docs/SPEC-suite-events.md`). Entschieden ist (Inhaber, 16.11.2026; `TODO.md`, „Offene Entscheidungen“): Jeder Kern führt eine eigene versionierte Vertragskonstante (hier `WPAC_CONTRACT_VERSION`, `docs/CONTRACT.md`); der Transport sind die vorhandenen PHP-Hooks; ein Workflow bucht in der ersten Version keine Provision und löst keine Auszahlung aus. Eine eigene Schnittstelle für Workflow Captain ist nicht Teil von 0.15.0; ein Entwurf dafür wurde vor dem Release herausgenommen. Seit 0.19.0 (23.11.2026, AFF-WF-08) ist entschieden, dass er nicht zurückkommt: Abnehmer nutzen die Actions und die Lese-API (`TODO.md`, AFF-WF-07 bis AFF-WF-09). Der Adapter für Workflow Captain liegt im Add-on. Auch für diese Anbindungen gilt: Der Kern prüft keine Rechte; ob eine Anbindung bezahlt ist, entscheidet das jeweilige Add-on. Eine produktübergreifende Automatisierung braucht Workflow Pro und die Pro-Lizenz des Produkt-Add-ons; geprüft wird das nur in den Add-ons.

Die bestehenden Integrationsverträge für Verkauf, Attribution und Provision bleiben für WooCommerce, Pass Captain und Voucher Captain zuständig. Form Captain ersetzt den Affiliate-Bewerbungsablauf nicht; eine optionale Formularübergabe kann nur einen wartenden Antrag einreichen, während Prüfung und Freigabe in Affiliate Captain verbleiben. Review Captain erhält keinen Zugriff auf Provisionsdetails: die spätere Verbindung ist auf freigegebene, aggregierte Qualitätswerte begrenzt und darf keine Einladung oder Provision auslösen. Dafür sind neue, ausdrücklich vereinbarte Schnittstellen nötig; der bestehende Vertrag (1.5.0) stellt für Form Captain und Review Captain keine Funktionen bereit (Vorschläge dazu stehen in `docs/CONTRACT.md`).

## 7. Aufteilung der Funktionen: Kern und Add-on (entschieden 2026-11-15, Stand 16.11.2026)

Grundlage: Audit-Runde 14 (R14-P2), Abgleich mit AffiliateWP (Pro/Plus-Tarife), Solid Affiliate und SliceWP. Die Inhaberin hat die Aufteilung dem Entwicklungsteam überlassen.

Regeln:

- Alles, was der Kern ausliefert, ist und bleibt frei. Der Kern enthält keine Lizenzprüfung und kennt keinen Rechteschlüssel.
- Bezahlte Funktionen liegen im Add-on „Affiliate Captain Pro“ (Repository `hastla007/captain-pro`, Ordner `addons/affiliate-captain-pro`). Nur das Add-on prüft Rechte, je Funktion genau einen Schlüssel mit Präfix `affiliate.` aus dem Katalog des Lizenzservers (`hastla007/wp-license`, `src/Catalog/EntitlementCatalog.php`).
- Die Tarife des Add-ons (`single`, `business`, `agency` mit 1, 5 und 25 Live-Websites) enthalten alle denselben Funktionsumfang.

### 7.1 Im Kern (frei, in diesem Repository)

| Funktion | Seit | Begründung |
|---|---|---|
| Gutschein automatisch per Partnerlink einlösen | 0.15.0 | Gutscheine sind Kern; bei Solid Affiliate Grundumfang; größter Conversion-Hebel. |
| Wunschcode auf Antrag des Partners | 0.15.0 | Wunschcodes gibt es bereits im Admin; nur der Antragsweg kommt dazu. |
| Erneute Anmeldung / 2FA vor Geld-Aktionen | 0.15.0 | Sicherheit gehört nicht hinter eine Bezahlschranke. |
| „Geworben von“ (Daten + Hook `wpac_affiliate_recruited`) | 0.15.0 | Grundlage für Sub-Affiliate-Add-ons, nützt auch Dritten. |

Ebenfalls Kern und dauerhaft frei sind alle früher ausgelieferten Funktionen, darunter Partnerportal, Zuordnung über Gutscheine, Erstattungen und Stornos, Provisionen mit Regeln je Produkt und Kategorie, Partnergruppen, Provisionen auf Abo-Verlängerungen, Monatsstufen (Boni), Auszahlungsläufe mit SEPA-, Überweisungs- und CSV-Export, Gutschriften und E-Rechnung. Der Katalog des Lizenzservers führte am 16.11.2026 noch acht Schlüssel, die solche Kernfunktionen benennen (`affiliate.partner_portal`, `affiliate.coupon_attribution`, `affiliate.order_refund_handling`, `affiliate.basic_commissions`, `affiliate.manual_payout_csv`, `affiliate.partner_groups`, `affiliate.product_category_rates`, `affiliate.recurring_commissions`). Sie werden dort entfernt; weder der Kern noch das Add-on prüft sie, und keine dieser Funktionen wird je gesperrt.

### 7.2 Im Add-on gebaut (captain-pro `main`, Stand 16.11.2026)

| Funktion | Rechteschlüssel | Beschreibung dort | Nutzt im Kern |
|---|---|---|---|
| REST-API für Partner, Provisionen und Auszahlungen | `affiliate.extended_api` | `docs/EXTENDED-API.md` | Lese- und Schreib-API des Vertrags (seit 1.3.0) |
| Automatische Auszahlung über PayPal Payouts | `affiliate.automated_payouts` | `docs/PAYPAL-PAYOUTS.md` | `wpac_claim_payout()`, `wpac_complete_payout()`, `wpac_release_payout()` |
| Ausgehende, signierte Webhooks | `affiliate.webhooks` | `docs/WEBHOOKS.md` | Actions des Vertrags (`wpac_commission_created`, `wpac_payout_paid` …) |
| White-Label-Partnerportal | `affiliate.white_label_portal` | `docs/WHITE-LABEL.md` | `wpac_portal_before`/`_after`, `wpac_portal_classes`, `wpac_portal_labels`, `wpac_mail_from_name` |

Einschränkung: PayPal Payouts ist bisher nur gegen einen nachgebauten Zahlungsdienst geprüft, nicht gegen die PayPal-Sandbox, und gilt deshalb als teilweise erledigt. Das Add-on ist noch nicht veröffentlicht (Version 0.1.0; Adresse und Signaturschlüssel des Lizenzservers fehlen dort).

### 7.3 Weitere Add-on-Funktionen (Stand 16.11.2026 nachmittags)

Bestenliste, Stripe Connect, Shop-Guthaben und mehrstufige Provision sind in captain-pro gebaut, aber noch nicht in `main` (PR hastla007/captain-pro#7; 616 PHPUnit-Tests grün, nicht gegen echtes Stripe/WooCommerce geprüft). „Mehrere Programme“ ist entschieden (Variante A); die Erweiterungspunkte im Kern gibt es seit 0.16.0, im Add-on ist die Funktion noch nicht gebaut.

| Funktion | Rechteschlüssel im Katalog | Grundlage im Kern | Anmerkung |
|---|---|---|---|
| Bestenliste und Meilenstein-Mails | `affiliate.leaderboard` | `wpac_creative_created`, `wpac_portal_url()`, `wpac_mail_sender()` (seit 0.15.0) | AffiliateWP: Plus-Tarif. |
| Auszahlung über Stripe Connect | `affiliate.stripe_connect` | Auszahlungs-API des Vertrags (seit 1.3.0) | Weitere Auszahlungswege sind Add-on (siehe 3., Option A). |
| Auszahlung als Shop-Guthaben | `affiliate.store_credit` | Auszahlungs-API des Vertrags (seit 1.3.0) | wie oben |
| Sub-Affiliates / mehrstufige Provision | `affiliate.multi_tier` | `referred_by`, `wpac_affiliate_recruited`, `wpac_create_adjustment()` mit `parent_id` (seit 0.15.0) | Der Kern speichert nur den Werber; die Buchung weiterer Stufen ist Sache des Add-ons. |
| Mehrere Programme | `affiliate.multiple_programs` | Erweiterungspunkte im Kern seit Vertrag 1.5.0 (Variante A „Programm = Partnergruppe“: `wpac_setting` mit Programmkontext, `wpac_affiliate_program()`, Feld und Filter `program`, Programmberichte, `wpac_affiliate_program_changed`; `docs/CONTRACT.md`, Abschnitt „Partnerprogramme“). Das Add-on selbst ist noch nicht gebaut. | Bleibt als geplant im Katalog und wird gebaut (Entscheidung des Inhabers vom 16.11.2026). Was der Kern dafür braucht: `TODO.md`, Aufgabe MP-1. |

`affiliate.priority_support` beschreibt eine Dienstleistung, keine Funktion; dafür gibt es im Add-on nichts zu sperren.
