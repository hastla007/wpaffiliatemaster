# Form Captain: Partnerbewerbung über ein Formular

Stand 16.11.2026. Optionaler Connector in Affiliate Captain für [Form Captain](https://github.com/hastla007/wp-formmaster) (Vertrag v1, ab 1.0; geprüft mit 1.1.0). Entscheidung des Inhabers vom 16.11.2026: bauen (`TODO.md`, AFF-SU-01; Form Captain P8-07). Die Bewertung davor steht in [`CONTRACT.md`](CONTRACT.md), „Form Captain: Bewertung des eigenen Bewerbungsablaufs“.

## Was der Connector tut

Ein Formular in Form Captain kann eine Partnerbewerbung an Affiliate Captain übergeben. Die Übergabe nutzt den eingebauten Bewerbungsablauf von Affiliate Captain und keinen zweiten:

1. Form Captain speichert die Einsendung und legt für die Aktion einen Outbox-Job an. Der Job läuft im Worker von Form Captain (WP-Cron oder `wp form-captain jobs run`), nie in der Anfrage der Einsendung.
2. Affiliate Captain prüft die Werte wie beim eigenen Formular, legt die Bewerbung für die Gültigkeit des Bestätigungslinks in `Core\Pending` ab (Einstellung `application_confirm_days`, 1 bis 30 Tage, Standard 2) und schickt die eigene Bestätigungsmail („Bewerbung bestätigen“, Vorlage `confirm_application`).
3. Erst der Klick auf den Link in der Mail legt den Partner an, über denselben Code wie beim eigenen Formular (`Registration::handle_confirm()` → `finalize()` → `AffiliateRepository::create()`), **immer mit Status `pending`**, auch wenn „Bewerbungen automatisch freigeben“ eingeschaltet ist. Die Admin-Mail „Neue Partnerbewerbung“ und die Eingangsbestätigung an den Bewerber kommen wie gewohnt.
4. Freigabe, Ablehnung, Partnercode, Gutschein, Provisionsregeln und Provisionen bleiben in Affiliate Captain. Der Connector gibt nichts davon frei und legt nichts davon an.

Affiliate Captain schreibt dabei keine Tabelle selbst. Es entsteht keine zweite Registrierung und keine zweite Kopie der Bewerbung: Die Daten liegen bis zur Bestätigung dort, wo auch das eigene Formular sie ablegt, danach im Partnerdatensatz.

## Einrichtung

1. Affiliate Captain → Einstellungen: eine veröffentlichte **Bewerbungsseite** (`[wpac_affiliate_registration]`) auswählen. Auf ihr bestätigen Bewerber ihre Adresse. Ohne sie meldet der Connector „Voraussetzungen nicht erfüllt“, und Form Captain wiederholt die Jobs später.
2. Form Captain → Integrationen: den Connector **Affiliate Captain** aktivieren. Nach der Installation ist er nur „Erkannt“ (Opt-in); bis zur Aktivierung parkt Form Captain die Jobs.
3. Im Formular-Editor die Aktion **„Partnerbewerbung an Affiliate Captain“** hinzufügen und die Felder zuordnen:

| Zuordnung | Feldtypen in Form Captain | Pflicht | Ziel in Affiliate Captain |
|---|---|---|---|
| `name` – Name oder Firma | Text | ja | Name (höchstens 100 Zeichen) |
| `email` – E-Mail | E-Mail | ja | E-Mail-Adresse (Bestätigung per Double-Opt-in) |
| `website` – Website | Text | nein | Website (ohne `http(s)://` wird `https://` ergänzt) |
| `channel` – Kanal | Text, Auswahl, Radio | nein | Kanal |
| `message` – Nachricht | Textbereich, Text | nein | Bewerbungsnotiz |
| `terms` – Zustimmung zu den Teilnahmebedingungen | Einwilligung | ja | angenommene Bedingungen (Version und SHA-256) |
| `referrer` – Partnercode des Werbers | Text, verstecktes Feld | nein | werbender Partner |

Andere Felder des Formulars erreichen Affiliate Captain nicht: Form Captain übergibt nur zugeordnete Felder, und der Connector liest nur die sieben Schlüssel oben (Allowlist `Handover::FIELDS`). Datei-, Passwort-, Zahlungs- und Signaturfelder übergibt Form Captain nie.

Einstellungen der Aktion:

- **Partnergruppe (ID, 0 = keine).** Nur aktive, **öffentliche** Gruppen ohne Pflicht-Zusatzfelder. Die Beschriftung nennt die öffentlichen Gruppen mit ID. Private Gruppen brauchen beim eigenen Formular die Freigabe eines Partner-Managers; sie sind für Übergaben gesperrt (`group_invalid`). Gruppen mit Pflicht-Zusatzfeldern ergeben `group_fields_unsupported`, weil die Zusatzfelder der Gruppe nicht Teil der Zuordnung sind.
- **Version der Teilnahmebedingungen.** Ist in Affiliate Captain eine Bedingungsseite gesetzt (Programm oder Gruppe), muss hier genau deren aktuelle Version stehen. Das Einwilligungsfeld im Formular soll auf diese Seite verlinken. Ändert sich die Version in Affiliate Captain, schlagen weitere Übergaben mit `terms_version_mismatch` fehl, bis Formular und Einstellung angepasst sind. So wird nie eine Zustimmung zu einem Text gespeichert, den das Formular nicht gezeigt hat.

## Ergebnisse (Form Captain → Integrations-Jobs)

| Status | Code | Bedeutung |
|---|---|---|
| success | `confirm_sent` | Bestätigungsmail verschickt; der Partner entsteht beim Klick (dann `pending`). Auch jede Wiederholung derselben Einsendung meldet `confirm_sent`. |
| success | `already_exists` | Zu dieser Adresse gibt es schon einen Partner (gleich welcher Status). Es wird kein zweiter angelegt. Die Adresse bekommt wie beim eigenen Formular den Hinweis „bereits registriert“. Das Ergebnis nennt weder Partner-ID noch Status. |
| success | `test_ok` | Testlauf: Konfiguration geprüft, nichts gespeichert, keine Mail. |
| retry | `affiliate_captain_unavailable` | Wartungsmodus oder Datenbank-Update von Affiliate Captain nicht fertig. |
| retry | `registration_page_missing` | Keine veröffentlichte Bewerbungsseite. |
| retry | `rate_limited` | Mehr als zwei Bestätigungsmails an eine Adresse am Tag (Grenze des eigenen Formulars) oder mehr als 30 Übergaben je Formular und Stunde (Filter `wpac_rate_limit`, Bucket `form_captain_handover`). |
| retry | `locked`, `storage_failed` | Dieselbe Einsendung wird gerade übergeben bzw. Speichern fehlgeschlagen. |
| fail | `terms_required` | Einwilligung zu den Teilnahmebedingungen fehlt. |
| fail | `invalid_field` | Name fehlt oder E-Mail-Adresse ungültig. |
| fail | `group_invalid`, `group_fields_unsupported`, `terms_version_mismatch` | Einstellung der Aktion passt nicht (siehe oben); nach der Korrektur den Job wiederholen. |
| fail | `invalid_job` | Formular- oder Eintrags-ID fehlt. |

Ist Affiliate Captain deaktiviert, ist sein Connector nicht registriert: Form Captain meldet `action_not_found` bzw. `connector_not_found` und wiederholt die Jobs (bis zu 8 Versuche, danach Dead-letter). Pausierte Jobs bleiben pausiert, bis das Plugin zurück ist.

Die `remote_id` ist eine undurchsichtige Kennung der Übergabe (`wpac-fc-` und 16 Hex-Zeichen), nie die Partner-ID. Meldungen enthalten keine personenbezogenen Daten.

## Idempotenz

Je Einsendung entsteht höchstens eine Bewerbung. Schlüssel ist Website + Formular + Eintrag (`Handover::ref()`). Er ist bewusst gröber als der Idempotenzschlüssel von Form Captain (der zusätzlich Aktion und Instanz enthält): Zwei Aktionen im selben Formular ergeben trotzdem nur eine Bewerbung. Für den Schlüssel speichert Affiliate Captain einen kleinen Vermerk in `Core\Pending` (Option `wpac_pending_fc_<sha256>`; läuft nach 400 Tagen ab und wird dann von der täglichen Bereinigung entfernt, bei der Deinstallation mit „Alle Daten löschen“ sofort): Zustand, Hash des Bestätigungstokens, später die Partner-ID, Formular- und Eintrags-ID, Gruppe. Name, E-Mail-Adresse und Feldwerte stehen nicht darin. Eine Wiederholung (Zeitüberschreitung nach Erfolg, Wiederholen im Admin) liefert dasselbe Ergebnis mit derselben `remote_id` und verschickt keine zweite Mail.

## Werbercode, keine Trackingdaten

Der optionale Partnercode aus dem Formular wird auf dem Server geprüft (`wpac_resolve_partner_code()` als eingegebener Code, kein Vanity-Slug). Übernommen wird er nur für einen aktiven Partner, der nicht der Bewerber selbst ist (`Registration::recruiter_valid()`). Unbekannte Codes werden still ignoriert. Cookies, Sitzungen und Besuchsdaten liest die Übergabe nicht: Der Worker kennt keinen Besucher.

## Löschung und Widerruf

Form Captain bleibt Eigentümer der Einsendung. Der Connector reagiert auf `form_captain_entry_deleted_v1` (alle Gründe, auch `anonymized`, `retention`, `erasure`) und auf `form_captain_consent_withdrawn_v1`. Beides wird gleich behandelt, weil die Bewerbung auf dieser Einsendung beruht. Es gelten die Datenschutzregeln von Affiliate Captain:

- **Noch nicht bestätigt:** Die wartende Bewerbung wird gelöscht; der Bestätigungslink ist danach ungültig.
- **Bestätigt, Partner noch `pending`:** Die Bewerbung wird wie eine nie entschiedene Bewerbung anonymisiert (`Privacy::erase_applicant()`: Name, Adresse und Bewerbungsdaten weg, Status „abgelehnt“, Grund `privacy_erasure`). Ist die Verarbeitung nach Art. 18 eingeschränkt, bleibt sie stehen.
- **Freigegeben, abgelehnt oder beendet:** Der Partner bleibt unberührt. Das Audit-Log vermerkt es (`form_captain.entry_deleted` bzw. `form_captain.consent_withdrawn`, Ergebnis `kept`). Über Auskunft und Löschung entscheidet der Partner-Manager mit den Datenschutz-Werkzeugen von WordPress (Art. 15/17).

Läuft die Bestätigung gerade, wenn der Eintrag gelöscht wird, wartet die Löschung auf sie. Ist der Partner trotzdem entstanden, wird er sofort anonymisiert.

Audit-Einträge (ohne E-Mail-Adresse): `form_captain.application_handed_over` (Formular, Gruppe, Ergebnis), `form_captain.application_confirmed` (Partner, Formular, Eintrag), `form_captain.entry_deleted`, `form_captain.consent_withdrawn`.

**Für die Datenschutzerklärung:** Bewerbungen über ein Form-Captain-Formular werden wie Bewerbungen über das eigene Formular verarbeitet (gleicher Zweck, gleiche Fristen, gleiche Bestätigungsmail). Form Captain speichert die Einsendung zusätzlich nach seinen eigenen Aufbewahrungsregeln. Wer keine zweite Kopie behalten will, stellt in Form Captain für dieses Formular eine kurze Aufbewahrung ein. Die Löschung dort erreicht eine noch offene Bewerbung (siehe oben).

## Vertrag

Der Connector nutzt nur die öffentliche API von Form Captain v1: `form_captain_loaded_v1`, `form_captain_connectors_v1`, `form_captain_register_action_v1()`, `FormCaptain\Integrations\ConnectorInterface`, `form_captain_entry_deleted_v1` und `form_captain_consent_withdrawn_v1`. Er registriert sich nur bei Vertrags-Major 1. Am öffentlichen Vertrag von Affiliate Captain (`WPAC_CONTRACT_VERSION`, Stand 0.18.0: 1.6.0) ändert er nichts: keine neue Action, kein neuer Filter, keine neue Funktion. Die Schlüssel `pending_only` und `handover` in der wartenden Bewerbung sind intern. Die allgemeine Formularübergabe für fremde Formular-Plugins (`wpac_submit_application()`, REST `POST /wpac/v1/applications`, AFF-SU-02) ist damit nicht gebaut; sie bleibt ein Vorschlag in `CONTRACT.md`.

Code: `src/Integrations/FormCaptain/` (`FormCaptain` registriert, `Connector` beschreibt die Aktion, `Handover` übergibt und räumt auf). Geändert im Kern: `Registration::finalize()` (Status `pending` bei `pending_only`, Rückmeldung an `Handover::confirmed()`), `Registration::success_notice()` (Meldung „wird geprüft“ auf der Bestätigungsseite einer Übergabe) und eine Zeile in `Plugin::boot()`.

## Tests

- `tests/integration/r16-fc.php` (in `php tests/integration/run.php`, Prüfungen FC-1 bis FC-14): gegen einen Nachbau der öffentlichen API von Form Captain. Geprüft werden Registrierung nur bei Major 1, Zuordnung und Allowlist, Testlauf ohne Wirkung, fehlende Einwilligung, Double-Opt-in, nur `pending` trotz automatischer Freigabe, kein Code und keine Provision, doppelte Zustellung, bestehende Adresse, Wartungsmodus, Mail-Grenze, Gruppen, Bedingungsversion, Werbercode, Löschung, Widerruf, Wettlauf zwischen Bestätigung und Löschung und das unveränderte eigene Formular.
- `tests/form-captain/` (PHPUnit, Zwei-Plugin-Test mit dem echten Form Captain und seinem Contract-Testkit): siehe [`tests/form-captain/README.md`](../tests/form-captain/README.md).
- `tests/form-captain/site-check.php`: Ende-zu-Ende auf einer Test-Website mit beiden Plugins (`wp eval-file`). Der Ablauf geht über die öffentliche Einsendung `form_captain_submit_v1()`.
