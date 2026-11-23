# Steuerfragen an die Steuerberatung (R12-L1 bis R12-L3)

Stand: 16.11.2026, Affiliate Captain 0.16.0. Grundlage: Aufgaben R12-L1 bis R12-L3 in
`TODO.md` (Entscheidung E7) und der Code in `src/Payouts/` und `src/Commissions/Ledger.php`.

> **Keine Rechtsberatung.** Die genannten Normen geben wieder, wie die Entwickler die Rechtslage
> verstehen. Sie sind ausdrücklich zur Prüfung gedacht. Bis zur Antwort bleibt der Code
> unverändert.

**Kurz zum Plugin.** Der Shopbetreiber (Leistungsempfänger, Sitz DE) vergütet Partner für die
Vermittlung von Verkäufen. Er rechnet per Gutschrift ab (§ 14 Abs. 2 Satz 2 UStG,
Nummernkreis `GS-…`). Jede Provision behält die USt-Behandlung, die zum Zeitpunkt ihrer Leistung
galt (Steuerstatus, Land und Steuersatz aus einer Änderungshistorie,
`src/Payouts/TaxHistory.php:111`). Es gibt fünf Behandlungen (`src/Payouts/Vat.php:22-26`):
`standard` (inländischer Unternehmer, USt wird ausgewiesen), `reverse_charge` (§ 13b UStG),
`small_business` (§ 19 UStG), `private` (Privatperson, keine USt) und `none` (nicht steuerbar:
Rückzahlungen, Abschreibungen, ausgeglichene Korrekturgutschriften). Eine bezahlte Gutschrift
wird über eine Korrekturgutschrift berichtigt (eigener Nummernkreis `KG-…`), eine noch nicht
bezahlte über ein Storno (`ST-…`) und eine neue Gutschrift.

---

## Frage 1 (R12-L1): Nachträglich erhöhte Positionen

### Sachverhalt

Ein Kunde bestellt über einen Partnerlink; die Provision beträgt 10 % vom Nettowarenwert.
Später erhöht der Shop die Bestellung (Menge 1 → 2 oder höherer Preis). Das Plugin bucht die
Differenz als eigene Zeile „Bestellerhöhung“ (`increase`) unter der ursprünglichen Provision.

Beispiel: Der Partner ist bis 30.06.2026 Kleinunternehmer und ab 01.07.2026 regelbesteuert.

| Datum | Vorgang | Provision |
|---|---|---|
| 10.03.2026 | Bestellung 1.000 € netto | 100,00 € |
| 05.04.2026 | Gutschrift GS-2026-000010: 100,00 €, keine USt (§ 19) | |
| 20.08.2026 | Bestellung auf 2.000 € erhöht | +100,00 € |
| 05.09.2026 | nächste Gutschrift GS-2026-000031 | ? |

### Aktuelles Verhalten

- Die Erhöhung wird als Zeile `increase` mit `parent_id` = ursprüngliche Provision gebucht. Sie
  übernimmt deren Satz und Regel (`src/Commissions/Ledger.php:487-506`, `:640-686`).
- Steuerlich wird sie wie eine Korrektur nach § 17 behandelt:
  - Wurde die ursprüngliche Provision schon mit einer Gutschrift abgerechnet, übernimmt die
    Erhöhung die Behandlung der Zeile dieser Gutschrift
    (`src/Payouts/PayoutService.php:216-237`, `item_treatments()`).
  - Sonst gilt der Steuerstatus am **Datum der ursprünglichen Provision**
    (`src/Payouts/PayoutService.php:262`, `parent_created_at`).
- Auf GS-2026-000031 steht die Zeile im Block „Korrektur der Gutschrift GS-2026-000010 vom …“
  als „Korrektur (Bestellerhöhung)“ mit 100,00 €, **ohne USt (§ 19)**
  (`src/Payouts/Statement.php:642`, `:654`).
- Umgekehrter Fall: Der Partner war im März regelbesteuert und ist seit Juli Kleinunternehmer.
  Dann wird die Erhöhung mit **19 % = 19,00 €** ausgewiesen, obwohl der Partner jetzt
  Kleinunternehmer ist. Das ist ein mögliches Risiko nach § 14c UStG, falls es sich in
  Wahrheit um eine neue Leistung handelt.
- Wird der Bestellung nachträglich ein **neuer Artikel** hinzugefügt, behandelt das Plugin ihn
  dagegen als neuen Verkauf (`sale`, `src/Commissions/Ledger.php:704`): aktuelle
  Provisionsregeln und Steuerstatus am Buchungstag. Heute gilt also: höhere Menge → alte
  Behandlung, neuer Artikel → aktuelle Behandlung.

### Normen (nach unserem Verständnis)

- § 17 Abs. 1 UStG: Ändert sich die Bemessungsgrundlage eines ausgeführten Umsatzes, wird der
  Steuerbetrag berichtigt. Steuersatz und Behandlung des ursprünglichen Umsatzes bleiben.
- § 13 Abs. 1 Nr. 1 Buchst. a UStG: Für eine neue (zusätzliche) Leistung entsteht die Steuer mit
  ihrer eigenen Ausführung. Steuerstatus und Satz richten sich nach diesem Zeitpunkt.
- § 19 UStG (Kleinunternehmer) und § 14c UStG (unrichtiger oder unberechtigter Steuerausweis,
  auch in einer Gutschrift).

### Optionen

| | Bedeutung | Umsetzung |
|---|---|---|
| **A** Beibehalten: Erhöhung = Änderung der Bemessungsgrundlage (§ 17) | Behandlung der ursprünglichen Provision | Kein Code. Höchstens den neuen Artikel ebenfalls als § 17 behandeln (klein, ca. 0,5 Tage). Text im HANDBOOK. |
| **B** Erhöhung = neue Leistung am Tag der Erhöhung | aktueller Steuerstatus und Satz; Zeile erscheint als normale Position, nicht als „Korrektur der Gutschrift …“ | Klein (ca. 1 Tag plus Tests): `increase` im Referenzpfad von `item_treatments()` auslassen und beim Resolver `created_at` statt `parent_created_at` nehmen. Keine Datenmigration; bereits ausgestellte Gutschriften bleiben, wie sie sind. Noch offene Erhöhungen erhalten beim nächsten Lauf die neue Behandlung. |
| **C** Einstellung oder Regel je Fall (z. B. Mengen-/Preisänderung → § 17, neuer Artikel → neue Leistung, wie heute; oder wählbar) | je nach Fall | Mittel (ca. 2 Tage): Einstellung, Tests, HANDBOOK. Keine Migration. |

### Frage an die Steuerberatung

Ist die nachträgliche Erhöhung einer Provision wegen höherer Menge oder höheren Preises
derselben Bestellung eine **Änderung der Bemessungsgrundlage** der ursprünglichen
Vermittlungsleistung (§ 17 UStG, Behandlung zum ursprünglichen Leistungszeitpunkt) oder eine
**eigene, zusätzliche Leistung** (Behandlung am Tag der Erhöhung)? Gilt dasselbe für einen
nachträglich hinzugefügten Artikel? Welche Behandlung ist richtig, wenn sich der Steuerstatus
des Partners dazwischen geändert hat (§ 19 ↔ regelbesteuert)?

---

## Frage 2 (R12-L2): Kleinunternehmer im EU-Ausland

### Sachverhalt

Ein Partner aus Österreich ist dort Kleinunternehmer (keine USt). Er vermittelt Verkäufe für
einen deutschen Shop; die Provision für ein Quartal beträgt 2.000,00 €.

### Aktuelles Verhalten

- Ein Partner mit dem Steuerstatus „Kleinunternehmer“ in einem anderen Land als der Betreiber
  wird wie ein ausländischer Unternehmer behandelt: **Reverse Charge**, Satz 0
  (`src/Payouts/Vat.php:81-89`).
- Die Gutschrift lautet über 2.000,00 € ohne USt, mit dem Hinweis „Steuerschuldnerschaft des
  Leistungsempfängers, § 13b UStG / Art. 196 MwStSystRL“ (`src/Payouts/Statement.php:349-352`).
  Die E-Rechnung verwendet Kategorie `AE` mit `VATEX-EU-AE` (`src/Payouts/EInvoice.php:653`,
  `:744`). Der Betreiber muss 380,00 € USt nach § 13b anmelden und kann sie bei vollem
  Vorsteuerabzug wieder abziehen.
- Ohne eigene USt-IdNr. des Betreibers wird die Auszahlung blockiert
  (`src/Payouts/PayoutService.php:428`). Vom Partner verlangt das Plugin eine USt-IdNr.
  **oder** eine Steuernummer (`src/Payouts/ProfileRepository.php:286`).
- Partner außerhalb der EU (z. B. CH) mit Status Kleinunternehmer werden genauso behandelt.
- Der Code vermerkt die Frage schon als Hinweis: „EU SME scheme: confirm with a tax advisor“
  (`src/Payouts/Vat.php:84`).

### Normen (nach unserem Verständnis)

- § 3a Abs. 2 UStG / Art. 44 MwStSystRL: Eine B2B-Dienstleistung wird am Sitz des Empfängers
  ausgeführt, hier also in Deutschland.
- § 13b Abs. 1, Abs. 5 UStG: Der Leistungsempfänger schuldet die Steuer, wenn der leistende
  Unternehmer im Ausland ansässig ist. Nach unserem Verständnis gilt das **nicht**, wenn beim
  Leistenden die Steuer nach § 19 Abs. 1 nicht erhoben wird.
- Seit 01.01.2025 (Richtlinie (EU) 2020/285, Art. 284 ff. MwStSystRL, § 19a UStG): Ein im
  EU-Ausland ansässiger Kleinunternehmer kann die deutsche Kleinunternehmerregelung über das
  besondere Meldeverfahren seines Ansässigkeitsstaats nutzen. Voraussetzungen: EU-weiter
  Umsatz höchstens 100.000 € und Umsatz in Deutschland innerhalb der Grenzen des § 19. Er
  erhält dafür eine KU-Identifikationsnummer mit dem Suffix „-EX“.
- Ohne Teilnahme am Verfahren bleibt es nach unserem Verständnis bei § 13b.

### Optionen

| | Bedeutung | Umsetzung |
|---|---|---|
| **A** Beibehalten: immer Reverse Charge | richtig, solange der Partner nicht am EU-KU-Verfahren für Deutschland teilnimmt | Kein Code. Hinweis im HANDBOOK, dass Partner mit „-EX“-Nummer anders zu behandeln wären. |
| **B** Neuer Status „EU-Kleinunternehmer (§ 19a, KU-Nummer -EX)“: keine USt, kein § 13b | Gutschrift ohne USt mit Hinweis auf die Steuerbefreiung für Kleinunternehmen (§ 19 i. V. m. § 19a UStG / Art. 284 MwStSystRL) und der KU-Nummer; E-Rechnung Kategorie `E` | Mittel (ca. 3–4 Tage): neues Profilfeld KU-Nummer mit Formatprüfung (Schemaänderung, Schema 17), Steuerhistorie, `Vat::treatment`, Texte auf PDF und XML, Partnerformular, Tests. Keine Migration alter Belege. Bestehende Partner bleiben bei RC, bis sie die Nummer angeben und der Betreiber sie bestätigt. |
| **C** Alle EU-Kleinunternehmer ohne USt und ohne § 13b | einfach, aber falsch bei Partnern ohne Teilnahme am Verfahren (der Betreiber würde seine § 13b-Schuld nicht anmelden) | Klein (0,5 Tage). Nicht empfohlen. |

### Frage an die Steuerberatung

Schuldet ein deutscher Betreiber für Vermittlungsleistungen eines im EU-Ausland ansässigen
Kleinunternehmers die Umsatzsteuer nach § 13b UStG?

- Unter welchen Voraussetzungen entfällt das seit 2025? Reicht z. B. die Angabe einer gültigen
  KU-Nummer „-EX“, und muss der Betreiber sie prüfen?
- Welche Angaben braucht die Gutschrift dann (Hinweis, Nummer)?
- Ist Option B so umzusetzen, oder genügt A mit einem Hinweis?
- Für Drittlandspartner (z. B. CH) bleibt es bei § 13b – richtig?

---

## Frage 3 (R12-L3): Gemischte Behandlungen in einer Korrekturgutschrift; eigener KG-Nummernkreis

### Sachverhalt

Der Partner war bis 31.12.2026 Kleinunternehmer und ist ab 01.01.2027 regelbesteuert. Die
Gutschrift GS-2027-000003 vom 05.02.2027 rechnet Dezember und Januar ab:

| Teil | Netto | USt | Brutto |
|---|---|---|---|
| Dezember-Provisionen (§ 19) | 400,00 € | 0,00 € | 400,00 € |
| Januar-Provisionen (19 %) | 600,00 € | 114,00 € | 714,00 € |
| **Summe** | 1.000,00 € | 114,00 € | 1.114,00 € |

Die Gutschrift ist bezahlt. Der Partner widerspricht: Richtig wären im Januar 500,00 € und im
Dezember 350,00 €.

### Aktuelles Verhalten

- Eine Korrekturgutschrift (KG) berichtigt immer genau **einen Teil** (Behandlung und Satz)
  (`src/Payouts/Corrections.php:110-160` `parts()`, `:227-255` `amounts()`). Für mehrere Teile
  verlangt das Formular je Teil eine eigene KG (Fehlermeldung
  `src/Payouts/Corrections.php:367`). Im Beispiel entstehen zwei Belege:
  - KG-2027-000001 (Teil 19 %): vorher 600,00 / 114,00, nachher 500,00 / 95,00,
    Differenz −100,00 / −19,00 = **−119,00 €**;
  - KG-2027-000002 (Teil § 19): vorher 400,00 / 0, nachher 350,00 / 0, Differenz **−50,00 €**.
- Die USt einer KG wird auf den korrigierten Nettobetrag des Teils berechnet, nicht als
  Summe von Differenzen (`src/Payouts/Corrections.php:237`). Die Bruttodifferenz wird mit der
  nächsten Auszahlung verrechnet und dort als „ausgeglichene Korrekturgutschrift“ ohne
  erneuten USt-Ausweis aufgeführt (`src/Payouts/Statement.php:596-598`).
- Die E-Rechnung einer KG ist Typ 384 mit genau einer USt-Kategorie
  (`src/Payouts/EInvoice.php:483-533`).
- **Nummernkreise** (`src/Core/Sequence.php`, je Jahr lückenlos):
  - Gutschriften `GS-` (`src/Payouts/PayoutService.php:784`);
  - Korrekturgutschriften `KG-` (`src/Payouts/Corrections.php:385`);
  - Stornos `ST-` (`src/Payouts/PayoutService.php:1173`);
  - die Korrekturbelege eines Auszahlungslaufs (Saldo genau 0, z. B. Erstattung mit Rückzahlung
    ausgeglichen) laufen im `GS-`-Kreis (`src/Payouts/PayoutService.php:778-784`).
- Nebenbefund: Auf der **Gutschrift** fasst die USt-Aufstellung Zeilen nur nach Satz zusammen
  (`src/Payouts/Statement.php:684`, `:725-737`). Zeilen mit Reverse Charge (0) und § 19 (0)
  stehen daher in einer Summe „USt 0 % auf …“; die Hinweise erscheinen aber je Behandlung
  (`src/Payouts/Statement.php:389-403`). Die E-Rechnung trennt nach Kategorie und Satz
  (`src/Payouts/EInvoice.php:814`).

### Normen (nach unserem Verständnis)

- § 14 Abs. 4 Satz 1 Nr. 4 UStG: fortlaufende Nummer mit einer oder mehreren Zahlenreihen, die
  einmalig vergeben wird. Nach Abschn. 14.5 Abs. 10 UStAE sind mehrere getrennte
  Nummernkreise zulässig.
- § 14 Abs. 4 Satz 1 Nr. 8 UStG: das nach Steuersätzen und einzelnen Steuerbefreiungen
  aufgeschlüsselte Entgelt und der darauf entfallende Steuerbetrag bzw. der Hinweis auf eine
  Steuerbefreiung.
- § 14a Abs. 1 und Abs. 5 UStG: Pflichtangaben bei § 13b. § 34a UStDV: Angaben in Rechnungen
  von Kleinunternehmern (seit 2025). § 31 Abs. 5 UStDV: Berichtigung durch ein
  Dokument, das spezifisch und eindeutig auf die Rechnung bezogen ist.
- § 17 UStG (Berichtigung je Umsatz und Steuersatz).

### Optionen

| | Bedeutung | Umsetzung |
|---|---|---|
| **A** Beibehalten: eine KG je Teil, eigener `KG-`-Kreis | jeder Beleg mit genau einer Behandlung, eindeutig auf die Gutschrift bezogen | Kein Code. |
| **B** Eine KG mit mehreren Teilen (Aufstellung je Behandlung/Satz, eine Nummer) | ein Beleg je Widerspruch | Mittel bis groß (ca. 4–6 Tage): `payout_corrections` speichert heute eine Behandlung je Zeile. Nötig wären eine Teiltabelle oder JSON (Schemaänderung), Anpassungen an Formular, PDF, XML (mehrere Kategorien), Jahresübersicht und Verrechnung. Alte KG bleiben gültig und werden unverändert nachgebaut. |
| **C** Wie A, aber ein Formular für alle Teile, das in einem Schritt mehrere KG ausstellt | bequemer, rechtlich wie A | Klein (ca. 1 Tag), keine Migration. |
| Zusatz **N** Nummernkreis: KG im `GS`-Kreis oder Korrekturbelege des Laufs in einem eigenen Kreis | einheitliche Folge | Klein (0,5–1 Tag, Einstellung). Bestehende Nummern bleiben; ein Wechsel nur zum Jahresanfang sinnvoll. |
| Zusatz **G** Gutschrift: USt-Aufstellung nach Behandlung **und** Satz (RC 0 und § 19 0 getrennt) | Entgelt je Steuerbefreiung getrennt | Klein (ca. 1 Tag). Nur für neue Belege: Bestehende PDFs werden aus gespeicherten Daten byte-gleich nachgebaut, deshalb muss das neue Layout am Beleg versioniert werden. |

### Frage an die Steuerberatung

1. Darf eine bezahlte Gutschrift mit Teilen unterschiedlicher Behandlung (§ 19 und 19 %, ggf.
   § 13b) durch **mehrere Korrekturgutschriften je Teil** berichtigt werden (Option A/C)? Oder
   muss bzw. sollte eine Berichtigung alle Teile in **einem** Beleg mit getrennter Aufstellung
   enthalten (Option B)?
2. Ist ein **eigener Nummernkreis** für Korrekturgutschriften (`KG-`) neben `GS-` und `ST-`
   zulässig? Dürfen die Korrekturbelege eines Auszahlungslaufs im `GS-`-Kreis bleiben?
3. Muss die Gutschrift Entgelte mit Reverse Charge und mit § 19 getrennt aufschlüsseln, obwohl
   beide 0 % haben (Zusatz G)?
