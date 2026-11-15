# Verzeichnis von Verarbeitungstätigkeiten – Vorlage (Art. 30 DSGVO)

Vorlage für die Einträge, die Affiliate Captain betreffen. Die Werte in `{geschweiften Klammern}` füllt der Download in **Affiliate Captain → Einstellungen → Daten & Wartung** mit Ihren aktuellen Einstellungen aus. Bei einer anderen Sprache als Deutsch liefert der Download die englische Fassung (`docs/RECORD-OF-PROCESSING.md`). Prüfen und ergänzen Sie die Vorlage mit Ihrer Datenschutzberatung; sie ersetzt keine Rechtsberatung.

**Verantwortlicher:** {operator_name}, {operator_address}
**Stand:** {date}

---

## 1. Zuordnung von Bestellungen zu Partnern (Affiliate-Tracking)

| | |
|---|---|
| Zweck | Bestellungen dem empfehlenden Partner zuordnen, damit er seine Provision erhält; Missbrauch erkennen |
| Betroffene | Website-Besucher und Kunden, die über einen Partnerlink oder -code kommen |
| Daten | Partnercode, Kampagne, Zielseite, Zeitpunkt, Host der vorherigen Seite (nur mit Einwilligung), Bestellnummer, Produktname und Nettobetrag; **keine IP-Adresse**; bei Folgekäufen ein pseudonymisierter Hashwert (HMAC mit geheimem Schlüssel, keine Verschlüsselung, keine Klartext-Adresse) von Kundenkonto/E-Mail; optional der Partnercode in der WooCommerce-Sitzung (nur wenn bereits ein Warenkorb besteht, bis zum Kaufabschluss) und – bei aktivem JavaScript-Tracking für Seiten-Caches – dieselben Angaben über ein kleines Skript (Beacon) statt über den Seitenaufruf |
| Rechtsgrundlage | Art. 6 Abs. 1 lit. f DSGVO (Zuordnung ohne Cookie, Kundenverknüpfung für Folgekäufe); Art. 6 Abs. 1 lit. a DSGVO, § 25 TDDDG (Affiliate-Cookie `wpac_ref`, Lesen der vorherigen Seite) |
| Widerspruch | Kunden können der Zuordnung und der Kundenverknüpfung widersprechen (Art. 21 DSGVO; Hinweis im Datenschutztext). Umsetzung: an der Bestellung im Kasten „Affiliate Captain“ die Lifetime-Bindung entfernen oder `wp wpac customer unlink` |
| Empfänger | Partner sehen zu ihren eigenen Bestellungen Bestellnummer, Datum, Produktname, Basisbetrag, Provision, Status, Kampagne und Sub-ID (Partnerbereich, CSV, REST) – keine Namen oder Kontaktdaten der Kunden. Produktnamen können Rückschlüsse zulassen (z. B. Gesundheitsprodukte); prüfen Sie das für Ihr Sortiment |
| Löschfristen | Besuche {visit_retention_days} Tage; Cookie {cookie_days} Tage; Kundenverknüpfung {lifetime_days}; Zuordnungen (Bestellnummer, Partner, Kampagne, Sub-ID, Besuchs-ID) als Buchungsunterlage {retention_years} Jahre ab Ende des Kalenderjahres der letzten Buchung der Bestellung, danach tägliche Löschung (nicht während einer Betriebsprüfung oder Sperre); anschließend werden die Provisionszeilen der Bestellung pseudonymisiert (Bestellnummer und Idempotenzschlüssel → Hash, Produkt, Beschreibung und Gründe der Statusänderungen gelöscht; Partner, Beträge und Daten bleiben für das Partnerkonto); Referral-Angaben an der Bestellung (`_wpac_touch`, eingegebener Partnercode `_wpac_partner_code`, manuelle Zuordnung `_wpac_manual_affiliate`) werden beim Löschantrag des Kunden entfernt, sobald die Bestellung zugeordnet ist oder nicht mehr zugeordnet werden kann; an noch unbezahlten, nicht zugeordneten Bestellungen bleiben sie (als aufbewahrt gemeldet) |

## 2. Partnerprogramm (Bewerbung, Partnerkonto, Kommunikation)

| | |
|---|---|
| Zweck | Bewerbungen prüfen, Partnerkonten führen, Partner informieren (Freigabe, Monatsübersicht, Sicherheitshinweise) |
| Betroffene | Bewerber und Partner |
| Daten | Name/Firma, E-Mail, Website, Kanal, Bewerbungsangaben, Partnercodes, akzeptierte AGB (Version, Zeitpunkt, Text) |
| Rechtsgrundlage | Art. 6 Abs. 1 lit. b DSGVO (Vertrag / vorvertragliche Maßnahmen) |
| Empfänger | keine; Auftragsverarbeiter: Hosting, ggf. E-Mail-Versand |
| Löschfristen | unbestätigte Bewerbungen (E-Mail nicht bestätigt) 2 Tage; nie entschiedene Bewerbungen {pending_application_days} Tage; abgelehnte Bewerbungen {application_retention_days} Tage; Partnerdaten nach Vertragsende, soweit keine Aufbewahrungspflicht besteht; AGB-Nachweise so lange wie die Gutschriften |

## 3. Provisionen, Gutschriften und Auszahlungen

| | |
|---|---|
| Zweck | Provisionen berechnen, Gutschriften (§ 14 Abs. 2 UStG) erstellen, auszahlen, buchen |
| Betroffene | Partner |
| Daten | rechtlicher Name, Anschrift, Land, Steuerstatus, USt-IdNr./Steuernummer, Kontoinhaber, IBAN (verschlüsselt), BIC, bei „anderer Zahlungsweg“ die Zahlungsangaben (z. B. PayPal-Adresse, verschlüsselt), Provisionen, Auszahlungen, Gutschrift-PDFs, VIES-Prüfergebnis |
| Rechtsgrundlage | Art. 6 Abs. 1 lit. b DSGVO; Art. 6 Abs. 1 lit. c DSGVO (§ 147 AO, § 14b UStG / § 132 BAO / Art. 958f OR) |
| Empfänger | Hausbank (SEPA-Überweisung), {payment_providers}Steuerberatung, Finanzbehörden; EU-Kommission (VIES-Abfrage der USt-IdNr.) |
| Löschfristen | {retention_years} Jahre ab Ende des Kalenderjahres; während einer Betriebsprüfung oder Sperre keine Löschung. Nach einem Löschantrag wird die Verarbeitung bis dahin eingeschränkt. Provisionszeilen werden danach nicht gelöscht, sondern pseudonymisiert, sobald nichts mehr offen ist (keine Bestellnummer, kein Produkt, keine Freitexte; Beträge bleiben für Saldo und Berichte) |

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
- Signierte Cookies, Nonces für alle Formulare
- Ratenbegrenzung über die Tabelle `rate_limits`: nur ein HMAC-Hashwert aus (gekürzter) IP-Adresse und Browserkennung, keine IP im Klartext; Einträge laufen nach Minuten bis Stunden ab und werden täglich gelöscht
- JavaScript-Tracking (optional, für Seiten-Caches) sendet nur, was auch der Seitenaufruf enthält; der Host der vorherigen Seite nur mit Einwilligung (serverseitig erneut geprüft)
- Gutschrift-PDFs außerhalb des öffentlichen Webverzeichnisses bzw. geschützt, Download nur für den Partner selbst
- Änderungen an Bankdaten werden dem Partner (und 30 Tage auch der vorherigen Adresse) gemeldet
- Datenauskunft und Löschung über die WordPress-Datenschutzwerkzeuge
