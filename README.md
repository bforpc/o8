# o8 — One document. Many folders.

**Self-hosted document management with a clear workspace, flexible filing and document-linked bookkeeping.**

[English](#english) · [Deutsch](#deutsch) · [License](LICENSE-o8.md) · [Third-party notices](THIRD-PARTY-NOTICES.md)

> **Development status — M1 preview, not a production release.**
> The feature descriptions below explain the intended finished product. Today, the repository contains an interactive browser-local prototype with fictional documents. Real authentication, enforced permissions and tenant isolation, database persistence, file handling, imports and the web installer are not implemented yet. Do not use this preview for real documents or credentials.
>
> **Entwicklungsstand — M1-Vorschau, keine Produktivversion.**
> Die folgenden Funktionen beschreiben das Zielprodukt. Aktuell läuft ein interaktiver, browserlokaler Prototyp mit erfundenen Dokumenten. Echte Anmeldung, durchgesetzte Rechte und Mandantentrennung, Datenbank, Dateiverarbeitung, Importe und Webinstaller folgen noch. Keine echten Dokumente oder Zugangsdaten verwenden.

## English

### What is o8?

o8 is a document management system being built for people and organizations that want to run their own document archive. It brings filing, search, document previews and optional bookkeeping data into one consistent web interface.

Its central idea is simple: **a document exists once and can be linked to several folders**. A utility invoice can appear under both “Household” and “Taxes” without duplicating the document or its bookkeeping data. Removing a folder link does not delete the document. There is no master/single-master/multi-master distinction.

### Intended product

- **A focused workspace:** four resizable desktop columns for folders, document list, preview and details. Mobile devices use switchable views and a folder drawer. Column widths, appearance and typography belong to the user.
- **Flexible organization:** nested virtual folders, searchable tags, metadata, confirmed drag-and-drop linking and bulk changes. A recoverable trash separates removing a document from removing a folder link.
- **A dedicated inbox:** uploads, scanner/inbound folders, email attachments and WebDAV feed one review workflow. Incoming documents remain separate from the main archive until accepted.
- **Search that stays understandable:** metadata and exact document IDs such as `D123`, date ranges, included/excluded tags, booking amounts and currencies, reference numbers, accounts, sorting, pagination and total result counts. Expired and non-searchable documents are excluded unless explicitly included in advanced search.
- **Bookkeeping attached to any document:** sender, reference/invoice number, date, net/tax/gross totals, multiple VAT rates, accounts and optional line items. Net or gross entry can drive the calculation. Each voucher has its own currency; EUR is the default. Different currencies are not silently converted or summed together.
- **Optional AI-assisted processing:** retain configurable extraction and review workflows. Manually chosen tags can replace AI suggestions; unknown AI tags do not create new tag definitions. Any external processing depends on the configured provider.
- **Independent tenants and clear permissions:** separate documents, users, folders, tags, bookkeeping, sources and settings per tenant. A tenant admin manages that tenant; regular users manage their own documents. Operator-level administration remains separate from document access.
- **Personal and administrative settings:** light/dark themes, custom colors, local system fonts and font sizes, individual mailbox/WebDAV configuration, and grouped administration for users, storage, VAT rates and accounts.
- **Reporting and reliable operations:** document-linked evaluations without double counting across folders; long-running imports and bulk jobs with progress and recoverable failures.
- **A guided installation and migration path:** a web installer for database configuration, a `config.php.dist` template for manual setup, and further configuration after the first login. Migration from o7 is planned as a separate, verifiable process that preserves the original installation as a fallback.

o8's bookkeeping fields are a document-management aid. This project does not claim to be a certified accounting system or guarantee compliance with specific archiving regulations.

### What can I try now?

The M1 preview already demonstrates the four-column workspace, folder links, tags, advanced search, document flags, currency-aware bookkeeping forms, bulk editing, grouped settings, themes and mobile layouts.

Demo tenant switching, user profiles and support-license verification are also available. **These are not production authentication or authorization.** Demo changes are stored in browser storage, and document previews are fictional HTML, not real PDF rendering.

| Milestone | Scope |
|---|---|
| M1 — Current preview | Interface, document/folder model and interactive demo workflows |
| M2 — Planned | Web installation, database, login, enforced tenant/user permissions, real files and server-side search |
| M3 — Planned | Inbox sources, import jobs, AI-assisted processing and progress reporting |
| M4 — Planned | Reporting, bookkeeping integration and remaining administration |
| M5 — Planned | Trial migration, validation and agreed cutover from o7 |

See the [milestones and acceptance criteria](docs/MILESTONES.md).

### Run the development preview

Requirements: PHP 8.2+ and a modern browser. PHP Sodium is needed for the server-side support-license verifier. This preview does not require a database, an npm install or a frontend build. Bootstrap is included locally; no CDN or webfont service is needed.

From the project directory:

```sh
php -S 127.0.0.1:18088 router.php
```

Open [the local preview](http://127.0.0.1:18088/). Use this local development server only for testing, not production hosting.

The finished application's planned backend is PHP with MySQL/MariaDB. Supported database versions and production installation instructions will be finalized with M2. The installer and generated `config.php` / supplied `config.php.dist` are **not present yet**. No default admin login is available in M1.

### License and support

o8 uses the **o8 Community License 1.0**, a custom **source-available license, not an OSI open-source license**.

Private use and an organization's own internal use, including internal commercial use, are free under its terms. Internal modifications are permitted. Redistribution, resale and hosting for third parties generally require permission; the license contains specific exceptions for GitHub forks/contributions and commissioned service providers. Optional commercial support is separate from the right to use the software. A support-license expiry must not disable Community functionality.

This is a summary, not a substitute for the [German license text](LICENSE-o8.md). Third-party components retain their own licenses; see [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md), including the documented open SVG-provenance findings.

Project and licensing contact: [calicode.de](https://www.calicode.de) · [info@CaliCode.de](mailto:info@CaliCode.de).

### Development and contributions

Feedback on usability, reproducible bug reports and contributions through the official repository are welcome, subject to the license. Never attach real documents, passwords, database configuration, signing keys or personal data to an issue or pull request. For sensitive security reports, contact the maintainer privately.

The existing `o7/` symlink is a local development reference, not a runtime dependency. Do not dereference or include its contents when publishing a repository or distribution.

Run model tests with Node.js:

```sh
node --test tests/*.test.js
```

Browser tests additionally require Python, Playwright and Chromium. See [development notes](docs/DEVELOPMENT.md) for the current implementation, directory layout and test commands.

## Deutsch

### Was ist o8?

o8 ist ein Dokumentenmanagementsystem im Aufbau für Menschen und Organisationen, die ihr Archiv selbst betreiben möchten. Ablage, Suche, Dokumentvorschau und optionale Buchungsdaten erhalten eine gemeinsame, übersichtliche Weboberfläche.

Das Grundprinzip: **Ein Dokument existiert einmal und kann in mehreren Ordnern verlinkt sein.** Eine Stromrechnung kann unter „Haushalt“ und „Steuern“ erscheinen, ohne Dokument oder Buchungsdaten zu duplizieren. Das Entfernen einer Ordnerverknüpfung löscht nicht das Dokument. Eine Unterscheidung zwischen Master, Einzel-Master und Multi-Master entfällt.

### Das geplante fertige Produkt

- **Übersichtlicher Arbeitsplatz:** vier verstellbare Desktop-Spalten für Ordner, Dokumentliste, Vorschau und Details. Mobil gibt es umschaltbare Ansichten und eine aufklappbare Ordnerliste. Breiten, Darstellung und Schrift werden benutzerbezogen gespeichert.
- **Flexible Ablage:** virtuelle Ordner mit Unterordnern, durchsuchbare Tags, Metadaten, bestätigtes Drag-and-drop und Massenänderungen. Ein Papierkorb ermöglicht die Wiederherstellung und trennt Dokumentlöschung vom Entfernen einer Verknüpfung.
- **Gemeinsamer Eingang:** Uploads, Scanner-/Inbound-Verzeichnisse, E-Mail-Anhänge und WebDAV laufen in einer gemeinsamen Prüfung zusammen. Neue Dokumente bleiben bis zur Übernahme vom normalen Archiv getrennt.
- **Verständliche Suche:** Metadaten und exakte Dokument-IDs wie `D123`, Datumsgrenzen, enthaltene/ausgeschlossene Tags, Buchungsbeträge und Währungen, Belegnummern, Konten, Sortierung, Seitennavigation und Gesamttrefferzahl. Abgelaufene und nicht suchbare Dokumente werden nur auf ausdrücklichen Wunsch in der Detailsuche eingeschlossen.
- **Buchungsdaten für jede Dokumentart:** Absender, Beleg-/Rechnungsnummer, Datum, Netto/MWSt/Brutto, mehrere Steuersätze, Buchungskonten und optionale Positionen. Netto oder Brutto kann die Berechnungsgrundlage sein. Jeder Beleg besitzt eine Währung, standardmäßig EUR; unterschiedliche Währungen werden nicht stillschweigend umgerechnet oder addiert.
- **Optionale AI-Unterstützung:** konfigurierbare Erkennung und anschließende Prüfung. Manuelle Tags können AI-Vorschläge ersetzen; unbekannte AI-Tags erzeugen keine neuen Tags. Eine Verarbeitung durch externe Dienste hängt vom gewählten Anbieter ab.
- **Unabhängige Mandanten und klare Rechte:** eigene Dokumente, Benutzer, Ordner, Tags, Buchungen, Quellen und Einstellungen je Mandant. Ein Mandanten-Admin verwaltet seinen Mandanten, normale Benutzer ihre eigenen Dokumente. Die Betreiberverwaltung bleibt vom Dokumentzugriff getrennt.
- **Persönliche und administrative Einstellungen:** Hell/Dunkel, eigene Farben, lokale Systemschriften und Schriftgrößen, persönliche Mailbox-/WebDAV-Daten sowie gegliederte Verwaltung für Benutzer, Storage, Steuersätze und Konten.
- **Auswertung und nachvollziehbare Verarbeitung:** dokumentbezogene Auswertungen ohne Mehrfachzählung durch Ordnerlinks; längere Import- und Massenaktionen mit Fortschritt und wiederaufnehmbaren Fehlerfällen.
- **Geführte Installation und Datenübernahme:** Webinstaller für den Datenbankzugang, `config.php.dist` für die manuelle Einrichtung und weitere Einstellungen nach dem Erstlogin. Die Migration aus o7 erfolgt später als gesonderter, prüfbarer Ablauf; o7 bleibt als Rückfallbestand erhalten.

Die Buchungsmaske unterstützt die Dokumentverwaltung. Das Projekt behauptet weder eine Zertifizierung als Buchhaltungssystem noch eine garantierte Einhaltung bestimmter Archivierungsvorschriften.

### Was lässt sich heute ausprobieren?

Die M1-Vorschau zeigt bereits den Vier-Spalten-Arbeitsplatz, Ordnerverknüpfungen, Tags, Detailsuche, Dokumentstatus, Buchungsmasken mit Währungen, Massenänderungen, gegliederte Einstellungen, Designs und mobile Ansichten.

Auch Demo-Mandantenwechsel, Benutzerprofile und die Prüfung von Supportlizenzen sind vorhanden. **Das ist noch keine produktive Anmeldung oder Rechteprüfung.** Änderungen liegen im Browser; die Vorschau zeigt erfundene HTML-Dokumente statt echter PDFs.

| Meilenstein | Umfang |
|---|---|
| M1 — Aktuelle Vorschau | Oberfläche, Dokument-/Ordnermodell und bedienbare Demoabläufe |
| M2 — Geplant | Webinstallation, Datenbank, Anmeldung, wirksame Mandanten-/Benutzerrechte, echte Dateien und Serversuche |
| M3 — Geplant | Eingangsquellen, Importjobs, AI-Verarbeitung und Fortschrittsanzeigen |
| M4 — Geplant | Auswertung, Buchungsanbindung und weitere Administration |
| M5 — Geplant | Probemigration, Prüfung und abgestimmter Wechsel von o7 |

Die [Meilensteine mit Abnahmekriterien](docs/MILESTONES.md) beschreiben die nächsten Schritte.

### Entwicklungsvorschau starten

Voraussetzungen: PHP 8.2+ und ein aktueller Browser. Für die serverseitige Supportlizenz-Prüfung wird PHP Sodium benötigt. Die Vorschau braucht weder Datenbank noch npm-Installation oder Frontend-Build. Bootstrap liegt lokal bei; CDN und Webfonts sind nicht erforderlich.

Im Projektverzeichnis starten:

```sh
php -S 127.0.0.1:18088 router.php
```

Danach die [lokale Vorschau](http://127.0.0.1:18088/) öffnen. Der Entwicklungsserver ist nur für Tests gedacht, nicht für produktives Hosting.

Für das fertige Backend sind PHP und MySQL/MariaDB vorgesehen. Unterstützte Datenbankversionen und produktive Installationsanweisungen werden mit M2 festgelegt. Webinstaller, erzeugte `config.php` und mitgelieferte `config.php.dist` sind **noch nicht vorhanden**. In M1 gibt es keinen Standard-Adminlogin.

### Lizenz und Support

o8 verwendet die **o8 Community License 1.0**, eine individuelle **Source-Available-Lizenz, keine Open-Source-Lizenz nach OSI-Definition**.

Private Nutzung und eigene interne Nutzung durch Organisationen, auch innerhalb gewerblicher Tätigkeiten, sind unter ihren Bedingungen kostenlos. Interne Änderungen sind erlaubt. Weitergabe, Verkauf und Hosting für Dritte bedürfen grundsätzlich einer Genehmigung; Ausnahmen regelt die Lizenz für GitHub-Forks/-Beiträge und beauftragte Dienstleister. Optionaler kommerzieller Support ist vom Nutzungsrecht getrennt. Ein Supportablauf darf Community-Funktionen nicht sperren.

Diese Zusammenfassung ersetzt nicht den [Lizenztext](LICENSE-o8.md). Fremdkomponenten behalten ihre eigenen Lizenzbedingungen; siehe [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md), einschließlich der dokumentierten offenen Herkunftsfragen zu SVG-Symbolen.

Projekt- und Lizenzkontakt: [calicode.de](https://www.calicode.de) · [info@CaliCode.de](mailto:info@CaliCode.de).

### Entwicklung und Beiträge

Rückmeldungen zur Bedienung, nachvollziehbare Fehlerberichte und Beiträge über das offizielle Repository sind im Rahmen der Lizenz willkommen. Keine echten Dokumente, Passwörter, Datenbankkonfigurationen, Signierschlüssel oder personenbezogenen Daten in Issues oder Pull Requests aufnehmen. Sicherheitsrelevante Informationen bitte privat an den Betreuer melden.

Der vorhandene Symlink `o7/` ist nur eine lokale Entwicklungsreferenz, keine Laufzeitabhängigkeit. Seine Inhalte nicht beim Veröffentlichen eines Repositorys oder Pakets mit übernehmen.

Modelltests mit Node.js ausführen:

```sh
node --test tests/*.test.js
```

Browsertests benötigen zusätzlich Python, Playwright und Chromium. Details zum aktuellen Stand, zur Verzeichnisstruktur und zu Testbefehlen stehen in den [Entwicklungsnotizen](docs/DEVELOPMENT.md).

## Documentation / Dokumentation

The detailed design documents are currently in German. / Die ausführlichen Konzeptdokumente sind derzeit deutschsprachig.

- [Architecture / Architektur](docs/ARCHITECTURE.md)
- [Milestones / Meilensteine](docs/MILESTONES.md)
- [Installation plan / Installationskonzept](docs/INSTALLATION.md)
- [Permissions and settings / Rechte und Einstellungen](docs/SETTINGS_AND_PERMISSIONS.md)
- [Tenancy and support licensing / Mandanten und Supportlizenzen](docs/TENANCY_AND_LICENSING.md)
- [Search compatibility / Suchfunktionen](docs/SEARCH_PARITY.md)
- [Migration from o7 / Datenübernahme aus o7](docs/MIGRATION.md)
- [Current development notes / Aktueller Entwicklungsstand](docs/DEVELOPMENT.md)

Copyright © 2026 Jan Novak, calicode.de.
