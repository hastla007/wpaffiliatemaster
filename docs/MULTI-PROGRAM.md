# Mehrere Partnerprogramme (MP-1) – Entwurf zur Abstimmung

> Stand 16.11.2026. **Entwurf, noch nicht gebaut.** Der Inhaber hat entschieden, dass ein Add-on (`affiliate.multiple_programs` in captain-pro) mehrere Partnerprogramme anbietet. Gebaut wird die Funktion im Add-on; der Kern bekommt nur neutrale Erweiterungspunkte und kennt weder Lizenz noch Tarif. Offen ist die Wahl der Variante (Entscheidung E4). Die Bestandsaufnahme, wo der Kern heute von genau einem Programm ausgeht, steht in `TODO.md` unter MP-1.
>
> Entwicklerdokument, nicht Teil des Plugin-ZIPs (`.distignore`).

## 1. Die Frage an den Inhaber

**Darf ein Partner an mehreren Programmen gleichzeitig teilnehmen?**

- **Nein → Variante A „Programm = Partnergruppe“** (empfohlen für den Start): Jedes Programm ist eine Partnergruppe mit eigenen Bedingungen, Sätzen, Sperrfrist, Cookie-Laufzeit und Mindestauszahlung. Ein Partner gehört zu genau einem Programm. Kein neues Datenbankschema.
- **Ja → Variante B „Partner in mehreren Programmen“:** Ein Partner hat je Programm eine eigene Teilnahme, eigene Links, eigene Statistik und eigene Abrechnung. Neues Schema in den großen Tabellen, deutlich größer.

Variante A lässt sich später zu B ausbauen, ohne die Hooks aus A zu brechen.

## 2. Variante A – Erweiterungspunkte im Kern

Programm-Schlüssel = `group:<group_id>` (leer = Standardprogramm). Vertrag 1.5.0, kein Schemawechsel.

| Nr. | Erweiterungspunkt | Zweck |
|---|---|---|
| A1 | Filter `wpac_setting( $value, $key, array $context )` in `Settings::get()`; Kontext `affiliate_id`, `group_id`, `program` | Werte je Programm: `default_rate`, `default_rate_type`, `hold_days`, `cookie_days`, `min_payout_minor`, `attribution_model`, Bedingungsseite. Ohne Kontext unverändert. Aufrufer an den Stellen ergänzen, die einen Partner kennen (Calculator, Ledger, Tracker beim Setzen des Cookies, PayoutService, Portal). |
| A2 | Funktion `wpac_affiliate_program( $affiliate_id ): string` + Filter `wpac_affiliate_program` | Ein Ort, der das Programm eines Partners liefert (Standard: `group:<id>` oder `''`). |
| A3 | Feld `program` in `wpac_get_affiliate(s)()`, `wpac_get_commission(s)()` (abgeleitet aus dem Partner), Filter `program` in den Listenfunktionen | Add-on liest ohne Tabellenzugriff. |
| A4 | Berichte: Argument `program` in `Reports::partners()`, `by_dimension()` und den Partnerberichten; Filter `wpac_report_programs` für die Auswahl im Admin | Statistik je Programm. |
| A5 | Portal: Filter `wpac_portal_program_label` und Kontext `program` in `wpac_portal_tabs` / `wpac_portal_labels` | Programmname und eigene Texte im Partnerbereich. |
| A6 | Registrierung: vorhandene Gruppenwahl je Bewerbungsseite (Formulargruppen) als Programmwahl dokumentieren; Action `wpac_affiliate_program_changed( $affiliate_id, $from, $to )` beim Gruppenwechsel | Add-on reagiert auf Wechsel. |

Unverändert in A: ein Cookie (`wpac_ref`), eine Zuordnung je Bestellung, Auszahlungen/Guthaben/Belegnummern je Partner und Währung.

**Aufwand A:** mittel (etwa `Settings`, `Calculator`, `Ledger`, `Tracker`, `PayoutService`, `Portal`, `Reports`, `PublicApi`, `functions.php`, Vertrag, Tests).

## 3. Variante B – zusätzlich zu A

| Nr. | Änderung | Hinweis |
|---|---|---|
| B1 | Tabelle `affiliate_programs` (`affiliate_id`, `program`, `status`, `group_id`, `joined_at`) statt einer `group_id` je Partner | Partner–Programm-Zuordnung; `affiliates.group_id` bleibt als Standardprogramm. |
| B2 | Spalte `program VARCHAR(40) NOT NULL DEFAULT ''` mit Index in `attributions`, `commissions`, `visits`, `visits_daily` | Online-Migration wie Schema 14/15 (große Tabellen). |
| B3 | `wpac_record_sale()` / `record_refund()` nehmen optional `program`; Filter `wpac_sale_program( $program, $sale )` | Verkaufsquelle oder Add-on entscheidet das Programm. |
| B4 | Tracking: Programm im Link (`?partner=CODE&program=x` oder je Programm eigener Code-Präfix) und im Cookie-Wert | Ein Besucher kann Partner verschiedener Programme tragen; die Zuordnung je Bestellung bleibt eindeutig (Programm der Bestellung). |
| B5 | Portal: Programmwechsler, Statistik/Provisionen je Programm | |
| B6 | Auszahlung: optional Lauf je Programm (`create_run( $period_end, $currency, $program )`) | Belege und Nummernkreise können je Partner bleiben. |

**Aufwand B:** groß; eigener Detailentwurf vor dem Bau, Datenbankschema +1, Vertrag 1.5.0 oder 2.0.0 (falls ein Aufrufer brechen würde – Ziel: vermeiden).

## 4. Empfehlung

Mit **Variante A** starten: Sie liefert dem Add-on alles für „mehrere Programme mit eigenen Konditionen“ ohne Migration der großen Tabellen und ohne Risiko für Buchungen. B nur bauen, wenn Kunden ausdrücklich dieselbe Person in mehreren Programmen brauchen.

## 5. Fertig heißt (je Variante)

Erweiterungspunkte gebaut, in `docs/CONTRACT.md` beschrieben (Vertrag 1.5.0), mit Integrationstests (ein zweites Programm mit eigenem Satz, eigener Sperrfrist und eigener Statistik über die Hooks), und captain-pro kann darauf ein zweites Programm anbieten, ohne Tabellen des Kerns zu lesen.
