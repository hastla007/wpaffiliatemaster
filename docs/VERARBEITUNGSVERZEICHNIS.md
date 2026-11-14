# Verzeichnis von Verarbeitungstätigkeiten – Vorlage (Art. 30 DSGVO)

Vorlage für die Einträge, die Affiliate Captain betreffen. Die Werte in `{geschweiften Klammern}` füllt der Download in **Partnerprogramm → Einstellungen → Datenschutz** mit Ihren aktuellen Einstellungen aus. Prüfen und ergänzen Sie die Vorlage mit Ihrer Datenschutzberatung; sie ersetzt keine Rechtsberatung.

**Verantwortlicher:** {operator_name}, {operator_address}
**Stand:** {date}

---

## 1. Zuordnung von Bestellungen zu Partnern (Affiliate-Tracking)

| | |
|---|---|
| Zweck | Bestellungen dem empfehlenden Partner zuordnen, damit er seine Provision erhält; Missbrauch erkennen |
| Betroffene | Website-Besucher und Kunden, die über einen Partnerlink oder -code kommen |
| Daten | Partnercode, Kampagne, Zielseite, Zeitpunkt, Host der vorherigen Seite (nur mit Einwilligung), Bestellnummer und Nettobetrag; **keine IP-Adresse**; bei Folgekäufen ein verschlüsselter Hash von Kundenkonto/E-Mail |
| Rechtsgrundlage | Art. 6 Abs. 1 lit. f DSGVO (Zuordnung ohne Cookie); Art. 6 Abs. 1 lit. a DSGVO, § 25 TDDDG (Affiliate-Cookie `wpac_ref`, Lesen der vorherigen Seite) |
| Empfänger | Partner sehen nur Bestellnummer, Betrag und Status – keine Namen oder Kontaktdaten |
| Löschfristen | Besuche {visit_retention_days} Tage; Cookie {cookie_days} Tage; Kundenverknüpfung {lifetime_days}; Zuordnungen als Buchungsunterlage {retention_years} Jahre |

## 2. Partnerprogramm (Bewerbung, Partnerkonto, Kommunikation)

| | |
|---|---|
| Zweck | Bewerbungen prüfen, Partnerkonten führen, Partner informieren (Freigabe, Monatsübersicht, Sicherheitshinweise) |
| Betroffene | Bewerber und Partner |
| Daten | Name/Firma, E-Mail, Website, Kanal, Bewerbungsangaben, Partnercodes, akzeptierte AGB (Version, Zeitpunkt, Text) |
| Rechtsgrundlage | Art. 6 Abs. 1 lit. b DSGVO (Vertrag / vorvertragliche Maßnahmen) |
| Empfänger | keine; Auftragsverarbeiter: Hosting, ggf. E-Mail-Versand |
| Löschfristen | abgelehnte Bewerbungen {application_retention_days} Tage; Partnerdaten nach Vertragsende, soweit keine Aufbewahrungspflicht besteht; AGB-Nachweise so lange wie die Gutschriften |

## 3. Provisionen, Gutschriften und Auszahlungen

| | |
|---|---|
| Zweck | Provisionen berechnen, Gutschriften (§ 14 Abs. 2 UStG) erstellen, auszahlen, buchen |
| Betroffene | Partner |
| Daten | rechtlicher Name, Anschrift, Land, Steuerstatus, USt-IdNr./Steuernummer, Kontoinhaber, IBAN (verschlüsselt), BIC, bei „anderer Zahlungsweg“ die Zahlungsangaben (z. B. PayPal-Adresse, verschlüsselt), Provisionen, Auszahlungen, Gutschrift-PDFs, VIES-Prüfergebnis |
| Rechtsgrundlage | Art. 6 Abs. 1 lit. b DSGVO; Art. 6 Abs. 1 lit. c DSGVO (§ 147 AO, § 14b UStG / § 132 BAO / Art. 958f OR) |
| Empfänger | Hausbank (SEPA-Überweisung), {payment_providers}Steuerberatung, Finanzbehörden; EU-Kommission (VIES-Abfrage der USt-IdNr.) |
| Löschfristen | {retention_years} Jahre ab Ende des Kalenderjahres; während einer Betriebsprüfung oder Sperre keine Löschung. Nach einem Löschantrag wird die Verarbeitung bis dahin eingeschränkt |

## 4. Änderungsprotokoll (Audit-Log)

| | |
|---|---|
| Zweck | Nachvollziehbarkeit von Änderungen an Provisionen, Auszahlungen, Bankdaten und Einstellungen (GoBD) |
| Betroffene | Partner, Mitarbeitende mit Zugriff auf die Verwaltung |
| Daten | Aktion, Objekt, Benutzer-ID, Zeitpunkt, Details (z. B. Gründe) |
| Rechtsgrundlage | Art. 6 Abs. 1 lit. c und f DSGVO |
| Löschfristen | buchungsrelevante Einträge {retention_years} Jahre; übrige {audit_retention_days} Tage |

## Technische und organisatorische Maßnahmen (Auszug, Art. 32 DSGVO)

- IBAN verschlüsselt gespeichert (eigener Schlüssel `WPAC_ENCRYPTION_KEY`), in Listen nur maskiert
- Rollen und Rechte: Partner verwalten, Auszahlungen verwalten, Steuerdaten ansehen getrennt
- Signierte Cookies, Ratenbegrenzung, Nonces für alle Formulare
- Gutschrift-PDFs außerhalb des öffentlichen Webverzeichnisses bzw. geschützt, Download nur für den Partner selbst
- Änderungen an Bankdaten werden dem Partner (und 30 Tage auch der vorherigen Adresse) gemeldet
- Datenauskunft und Löschung über die WordPress-Datenschutzwerkzeuge
