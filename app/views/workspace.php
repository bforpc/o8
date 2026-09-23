<!doctype html>
<html lang="de" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#326d62">
    <title>o8 · Dokumente, gut sortiert.</title>
    <link rel="icon" href="<?= asset('favicon.svg') ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= asset('vendor/bootstrap/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('css/app.css') ?>">
    <link rel="stylesheet" href="<?= asset('css/components.css') ?>">
    <script defer src="<?= asset('vendor/bootstrap/bootstrap.bundle.min.js') ?>"></script>
    <script type="module" src="<?= asset('js/app.js') ?>"></script>
</head>
<body>
<a href="#documentList" class="skip-link">Zur Dokumentliste springen</a>
<?php require __DIR__ . '/icons.php'; ?>
<div class="app-shell">
    <header class="app-header">
        <a class="brand" href="#documents" aria-label="o8 Dokumente"><span class="brand-mark">o<span>8</span></span><span class="brand-caption">Ein guter Platz<br>für deine Dokumente.</span></a>
        <nav class="main-nav" aria-label="Hauptnavigation">
            <button class="nav-item active" data-page="documents" aria-current="page"><svg class="icon"><use href="#i-document"/></svg><span>Dokumente</span></button>
            <button class="nav-item" data-page="inbound"><svg class="icon"><use href="#i-inbox"/></svg><span>Eingang</span></button>
            <button class="nav-item" data-page="reports"><svg class="icon"><use href="#i-chart"/></svg><span>Auswertung</span></button>
        </nav>
        <div class="header-tools">
            <button class="milestone-badge" data-bs-toggle="modal" data-bs-target="#milestoneModal"><span class="status-dot"></span><span>M1 · abgenommen</span></button>
            <button class="btn icon-btn" id="quickTheme" aria-label="Zwischen Hell und Dunkel wechseln" title="Hell / Dunkel"><svg class="icon"><use href="#i-moon"/></svg></button>
            <button class="btn icon-btn" data-page="settings" aria-label="Einstellungen" title="Einstellungen"><svg class="icon"><use href="#i-settings"/></svg></button>
        </div>
    </header>
    <div class="demo-strip"><span>VORSCHAU</span> Beispieldaten · Änderungen werden nur in diesem Browser gespeichert.<button data-bs-toggle="modal" data-bs-target="#milestoneModal">Was kann ich testen? <span aria-hidden="true">↗</span></button></div>
    <div class="tenant-context"><span>Mandant: <strong id="activeTenantName"></strong></span><label for="tenantSelect">Vorschau wechseln</label><select id="tenantSelect" class="form-select form-select-sm" aria-label="Mandant wechseln"></select></div>

    <main id="page-documents" class="page workspace-page">
        <div class="workspace-heading">
            <div><div class="eyebrow">DEIN DOKUMENTENRAUM</div><h1 id="scopeTitle">Alle Dokumente</h1><p id="scopeDescription">Ein Dokument. Überall richtig abgelegt.</p></div>
            <button class="btn btn-primary" data-page="inbound"><svg class="icon"><use href="#i-plus"/></svg> Dokument hinzufügen</button>
        </div>
        <div class="workspace-tools">
            <button class="btn btn-surface folder-toggle" data-bs-toggle="offcanvas" data-bs-target="#folderDrawer"><svg class="icon"><use href="#i-folder"/></svg> Ordner <svg class="icon small-icon"><use href="#i-arrow"/></svg></button>
            <div class="search-field"><svg class="icon"><use href="#i-search"/></svg><label for="searchInput" class="visually-hidden">Dokumente durchsuchen</label><input id="searchInput" type="search" placeholder="Beschreibung, Absender, Tags oder D-ID …" autocomplete="off"><kbd aria-hidden="true">/</kbd></div>
            <button class="btn btn-surface" data-bs-toggle="collapse" data-bs-target="#filterBar" aria-expanded="false" aria-controls="filterBar"><svg class="icon"><use href="#i-filter"/></svg> Detailsuche <span id="filterCount" class="filter-count" hidden></span></button>
            <button class="btn btn-surface" id="resetSearch" title="Suchtext und Details auf Standard zurücksetzen; der Ordner bleibt ausgewählt">Suche zurücksetzen</button>
            <button class="btn btn-surface reset-columns" id="resetColumns" title="Standardbreiten für dieses Benutzerprofil wiederherstellen"><svg class="icon"><use href="#i-restore"/></svg> Spalten zurücksetzen</button>
        </div>
        <div class="collapse" id="filterBar"><section class="advanced-search" aria-label="Detailsuche">
            <p class="small text-body-secondary">Alle Bedingungen gelten zusätzlich zur Metasuche und zum ausgewählten Ordner. Leere Grenzen bleiben offen; Von/Bis sind einschließlich.</p>
            <div class="advanced-search-grid">
                <label>Dokumentdatum von<input id="dateFromFilter" class="form-control" type="date"></label>
                <label>Dokumentdatum bis<input id="dateToFilter" class="form-control" type="date"></label>
                <label>Buchungssumme brutto von<input id="amountFromFilter" class="form-control" inputmode="decimal" placeholder="z. B. 100,00"></label>
                <label>Buchungssumme brutto bis<input id="amountToFilter" class="form-control" inputmode="decimal" placeholder="z. B. 500,00"></label>
                <label>Währung der Betragsgrenzen<select id="amountCurrencyFilter" class="form-select"><option>EUR</option></select><span class="small text-body-secondary">Wirkt nur mit einer Betragsgrenze. Betragssortierung gruppiert nach Währung; keine Umrechnung.</span></label>
                <fieldset><legend>Dokumentstatus einschließen</legend><label class="d-block"><input id="includeExpiredFilter" type="checkbox" class="form-check-input"> Auch abgelaufene Dokumente</label><label class="d-block mt-2"><input id="includeNotSearchableFilter" type="checkbox" class="form-check-input"> Auch nicht suchbare Dokumente</label><p class="small text-body-secondary">Standardmäßig ausgeschlossen, auch bei direkter D-ID-Suche. Dokumente mit beiden Flags benötigen beide Optionen.</p></fieldset>
                <fieldset><legend>Tags enthalten</legend><div id="includeTagsFilter"></div><p class="small text-body-secondary">Alle ausgewählten Tags müssen vorhanden sein.</p></fieldset>
                <fieldset><legend>Tags nicht enthalten</legend><div id="excludeTagsFilter"></div><p class="small text-body-secondary">Keiner dieser Tags darf vorhanden sein.</p></fieldset>
                <label>Beleg-/Rechnungsnummern<input id="invoiceNumbersFilter" class="form-control" placeholder="z. B. RE-2026; 0842" aria-describedby="invoiceNumbersHelp"><span id="invoiceNumbersHelp" class="small text-body-secondary">Teiltreffer; mehrere Nummern mit Semikolon oder Komma trennen (ODER).</span></label>
                <div><span class="search-field-label">Buchungskonto</span><div id="accountFilter"></div><p class="small text-body-secondary">Belegkonto oder Konto einer aktiven Position.</p></div>
                <label>Dokumentart<select id="typeFilter" class="form-select"><option value="">Alle Arten</option><option>Rechnung</option><option>Gutschrift</option><option>Vertrag</option><option>Bescheinigung</option><option>Dokument</option></select></label>
                <label>Sortierung<select id="sortSelect" class="form-select"><option value="date-desc">Datum · neueste zuerst</option><option value="date-asc">Datum · älteste zuerst</option><option value="title">Beschreibung A–Z</option><option value="title-desc">Beschreibung Z–A</option><option value="amount-asc">Buchungssumme · aufsteigend</option><option value="amount-desc">Buchungssumme · absteigend</option><option value="invoice-asc">Belegnummer · aufsteigend</option><option value="invoice-desc">Belegnummer · absteigend</option></select></label>
                <label id="ownerFilterField" hidden>Besitzer (Admin)<select id="ownerFilter" class="form-select"><option value="">Alle Benutzer</option></select></label>
                <label>Ergebnisansicht<select id="resultViewFilter" class="form-select"><option value="all">Alle passenden Dokumente</option><option value="latest">Zuletzt hinzugefügt</option></select><span class="small text-body-secondary">Aufnahme ins DMS, nicht Belegdatum. Andere Suchbedingungen gelten weiterhin.</span></label>
                <label id="latestCountField" hidden>Die letzten … Dokumente<input id="latestCountFilter" type="number" class="form-control" min="1" max="10000" step="1" value="25"></label>
                <label>Datensätze pro Seite<select id="pageSizeSelect" class="form-select"><option value="10">10</option><option value="25">25</option><option value="50" selected>50 (Standard)</option><option value="100">100</option><option value="250">250</option></select></label>
                <div class="search-reset"><button class="btn btn-surface" id="clearFilters">Auf Standard zurücksetzen</button></div>
            </div>
        </section></div>
        <p id="searchError" class="text-danger search-error" role="alert" hidden></p>
        <nav class="mobile-panes" aria-label="Dokumentansichten"><button class="active" data-pane="list" aria-current="true">Liste</button><button data-pane="preview">Vorschau</button><button data-pane="details">Details</button></nav>
        <div class="document-workspace" data-pane="list" id="documentWorkspace">
            <aside class="workspace-panel folder-panel" aria-label="Ordnerliste">
                <div class="panel-header"><span>Deine Ablage</span><button class="btn btn-sm icon-btn" data-new-folder aria-label="Neuer Ordner"><svg class="icon"><use href="#i-plus"/></svg></button></div>
                <nav id="folderNavigationDesktop" class="folder-navigation" aria-label="Ordner"></nav>
                <div class="list-footer"><span id="layoutProfileLabel">Profil: Standard</span></div>
            </aside>
            <div class="column-divider" role="separator" tabindex="0" aria-label="Breite der Ordnerliste" aria-orientation="vertical" aria-valuemin="8" aria-valuemax="65" aria-valuenow="18" data-divider="0"></div>
            <section class="workspace-panel list-panel" aria-label="Dokumentliste">
                <div class="panel-header document-list-header"><div class="d-flex align-items-center gap-2"><input id="selectAll" class="form-check-input m-0" type="checkbox" aria-label="Alle Dokumente dieser Seite auswählen"><span id="resultCount" role="status">Dokumente</span></div><span id="bulkEditorTrigger" class="bulk-editor-trigger" title="Massenänderung: Bitte mindestens ein Dokument per Checkbox auswählen."><button type="button" class="btn btn-sm icon-btn" id="openBulkEditor" aria-label="Massenänderung" aria-haspopup="dialog" aria-controls="bulkEditModal" disabled><svg class="icon" aria-hidden="true"><use href="#i-settings"/></svg></button></span><span class="quiet-label">Datum ↓</span></div>
                <div id="bulkActions" class="bulk-actions" hidden><span id="selectionCount"></span><div><button class="btn btn-sm btn-primary" id="bulkLink" title="Ausgewählte Dokumente in Ordner verlinken"><svg class="icon"><use href="#i-folder"/></svg> Ablegen</button><button class="btn btn-sm icon-btn" id="bulkTrash" aria-label="Ausgewählte Dokumente in den Papierkorb"><svg class="icon"><use href="#i-trash"/></svg></button><button class="btn btn-sm" id="bulkRestore" hidden>Wiederherstellen</button><button class="btn btn-sm icon-btn" id="clearSelection" aria-label="Auswahl aufheben"><svg class="icon"><use href="#i-close"/></svg></button></div></div>
                <div class="document-list" id="documentList" tabindex="-1"></div>
                <nav class="search-pagination" aria-label="Ergebnisseiten"><button class="btn btn-sm btn-surface" id="previousResults" aria-label="Vorherige Ergebnisseite">← Zurück</button><span id="resultsPage" role="status"></span><button class="btn btn-sm btn-surface" id="nextResults" aria-label="Nächste Ergebnisseite">Weiter →</button></nav>
                <div class="list-footer"><span id="listFooter">Die Auswahl gilt nur für die aktuelle Seite.</span></div>
            </section>
            <div class="column-divider" role="separator" tabindex="0" aria-label="Breite der Dokumentliste" aria-orientation="vertical" aria-valuemin="8" aria-valuemax="65" aria-valuenow="27" data-divider="1"></div>
            <section class="workspace-panel preview-panel" aria-label="Dokumentvorschau">
                <div class="panel-header"><span><svg class="icon small-icon"><use href="#i-eye"/></svg> Vorschau</span><span class="quiet-label">Beispieldokument</span></div>
                <div class="preview-canvas" id="documentPreview"></div>
            </section>
            <div class="column-divider" role="separator" tabindex="0" aria-label="Breite der Vorschau" aria-orientation="vertical" aria-valuemin="8" aria-valuemax="65" aria-valuenow="29" data-divider="2"></div>
            <aside class="workspace-panel detail-panel" aria-label="Dokumentdetails">
                <div class="panel-header"><span>Dokumentdetails</span><span id="documentId" class="document-id"></span></div>
                <div id="documentDetails" class="details-content"></div>
            </aside>
        </div>
    </main>

    <main id="page-inbound" class="page secondary-page" hidden>
        <div class="eyebrow">ALLES BEGINNT IM EINGANG</div><h1>Ein Eingang. Alle Quellen.</h1><p class="page-lead">Erfassen, prüfen und ablegen – mit einem gemeinsamen Ablauf für alle Dokumente.</p>
        <div class="planned-notice"><svg class="icon"><use href="#i-info"/></svg><div><strong>Ausblick auf Meilenstein 3</strong><p>Die Quellen werden nach Abnahme des Grundgerüsts angeschlossen. Hier werden noch keine Dateien geladen oder übernommen.</p></div></div>
        <div class="source-grid">
            <article class="source-card"><span class="source-icon"><svg class="icon"><use href="#i-upload"/></svg></span><h2>Dateien & Scans</h2><p>Dateien hochladen oder aus deinem bestehenden Inbound-Verzeichnis übernehmen.</p><span class="planned-label">Anbindung folgt</span></article>
            <article class="source-card"><span class="source-icon"><svg class="icon"><use href="#i-mail"/></svg></span><h2>E-Mail</h2><p>Postfächer lesen, Anhänge auswählen und gemeinsam im Eingang prüfen.</p><span class="planned-label">Anbindung folgt</span></article>
            <article class="source-card"><span class="source-icon"><svg class="icon"><use href="#i-cloud"/></svg></span><h2>WebDAV</h2><p>Dokumente aus den konfigurierten WebDAV-Quellen in den Eingang holen.</p><span class="planned-label">Anbindung folgt</span></article>
        </div>
        <div class="flow-card"><span>01 <strong>Erfassen</strong></span><span aria-hidden="true">→</span><span>02 <strong>AI & Metadaten prüfen</strong></span><span aria-hidden="true">→</span><span>03 <strong>Ordner verlinken</strong></span></div>
        <p class="text-body-secondary mt-3">Vorgesehen: automatische und manuelle Übernahme, Duplikatprüfung und manuelle Tags mit der Option, AI-Tags zu ignorieren.</p>
    </main>

    <main id="page-reports" class="page secondary-page" hidden>
        <div class="eyebrow">DEN ÜBERBLICK BEHALTEN</div><h1>Auswertung</h1><p class="page-lead">Deine Buchungen bleiben am Dokument. Ordner helfen beim Filtern.</p>
        <div class="planned-notice"><svg class="icon"><use href="#i-chart"/></svg><div><strong>Ausblick auf Meilenstein 4</strong><p>Konten, Monatsübersichten und Steuerzusammenfassungen werden aus o7 übernommen und an die neue Ordnerstruktur angepasst.</p></div></div>
        <div class="report-principle"><div class="report-number">1×</div><div><h2>Ein Beleg wird einmal gezählt.</h2><p>Auch wenn derselbe Beleg in „Rechnungen“ und „Steuerunterlagen“ liegt, fließt seine Buchung bei gemeinsamer Auswahl nur einmal in die Auswertung ein.</p></div></div>
    </main>

    <?php require __DIR__ . '/settings.php'; ?>
    <footer class="app-footer"><span>o8 <span class="footer-separator">/</span> Dokumente, gut sortiert.</span><button data-bs-toggle="modal" data-bs-target="#milestoneModal">Meilenstein 1 · abgenommen <span aria-hidden="true">↗</span></button></footer>
</div>

<div class="modal fade" tabindex="-1" id="tenantModal" aria-labelledby="tenantTitle"><div class="modal-dialog modal-dialog-centered"><form id="tenantForm" class="modal-content"><div class="modal-header"><h2 id="tenantTitle" class="modal-title h5">Mandant anlegen</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Abbrechen"></button></div><div class="modal-body"><label for="tenantName" class="form-label">Name</label><input id="tenantName" class="form-control" required maxlength="190"><label for="tenantEmail" class="form-label mt-3">Kontakt-E-Mail</label><input id="tenantEmail" type="email" class="form-control" maxlength="254"><p id="tenantFormError" class="text-danger" role="alert"></p></div><div class="modal-footer"><button type="button" class="btn btn-surface" data-bs-dismiss="modal">Abbrechen</button><button type="submit" class="btn btn-primary">Speichern</button></div></form></div></div>
<div class="modal fade" tabindex="-1" id="tagAdminModal" aria-labelledby="tagAdminTitle"><div class="modal-dialog modal-dialog-centered"><form id="tagAdminForm" class="modal-content"><div class="modal-header"><h2 id="tagAdminTitle" class="modal-title h5">Tag hinzufügen</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Abbrechen"></button></div><div class="modal-body"><label for="tagAdminName" class="form-label">Tagname</label><input id="tagAdminName" class="form-control" required maxlength="190"><p id="tagAdminUsage" class="small mt-3"></p><p id="tagAdminError" class="text-danger" role="alert"></p></div><div class="modal-footer"><button type="button" class="btn btn-surface" data-bs-dismiss="modal">Abbrechen</button><button type="submit" class="btn btn-primary">Speichern</button></div></form></div></div>

<div class="modal fade" tabindex="-1" id="bulkEditModal" aria-labelledby="bulkEditTitle" data-bs-backdrop="static"><div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><form class="modal-content" id="bulkEditForm">
    <div class="modal-header"><h2 id="bulkEditTitle" class="modal-title h5">Massenänderung</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Abbrechen"></button></div>
    <div class="modal-body">
        <p id="bulkEditCount" class="fw-semibold"></p><details class="small mb-3"><summary>Ausgewählte Dokument-IDs anzeigen</summary><p id="bulkEditIds" class="mt-2"></p></details>
        <div id="bulkEditContent"><p id="bulkUnavailable" class="text-warning" hidden>Dokumente im Papierkorb bitte zuerst wiederherstellen. Eine endgültige Löschung ist hier nicht verfügbar.</p>
            <fieldset id="bulkEditFields"><legend class="visually-hidden">Änderungen für ausgewählte Dokumente</legend>
                <section class="bulk-edit-section"><h3>Ordnerverknüpfungen</h3><label class="form-label" for="bulkFolderAction">Aktion</label><select id="bulkFolderAction" class="form-select"><option value="keep">Unverändert lassen</option><option value="remove-all">Aus allen Ordnern entfernen</option><option value="remove">Aus einem bestimmten Ordner entfernen</option><option value="add">Zu einem bestimmten Ordner hinzufügen</option></select>
                    <div id="bulkFolderTargetWrap" class="mt-3" hidden><label class="form-label" for="bulkFolderTarget">Ordner</label><select id="bulkFolderTarget" class="form-select"></select></div><p class="small text-body-secondary mt-2">Es werden nur Verknüpfungen geändert, keine Dateien verschoben. Beim Speichern werden Eingangsdokumente in „Alle Dokumente“ übernommen. Löschen verschiebt sie stattdessen in den Papierkorb.</p>
                </section>
                <section class="bulk-edit-section"><h3>Tags</h3><label class="form-label" for="bulkTagAction">Aktion</label><select id="bulkTagAction" class="form-select"><option value="keep">Unverändert lassen</option><option value="add">Tags hinzufügen</option><option value="remove">Tags löschen (vom Dokument entfernen)</option><option value="set">Tags setzen auf (vollständig ersetzen)</option></select>
                    <div id="bulkTagPickerWrap" class="mt-3" hidden><div id="bulkTagPicker"></div></div><p id="bulkTagReplaceHint" class="small text-warning mt-2" hidden>Alle bisherigen Tags werden ersetzt. Eine leere Auswahl entfernt sämtliche Tags von den gewählten Dokumenten.</p><p class="small text-body-secondary mt-2">Es können nur vorhandene Tags gewählt werden. Die Tag-Liste selbst wird nicht verändert.</p>
                </section>
                <section class="bulk-edit-section"><h3>Besitzer</h3><label class="form-label" for="bulkOwner">Anwender</label><select id="bulkOwner" class="form-select"></select><p class="small text-body-secondary mt-2">Demo-Anwenderliste. Echte Benutzer und die Berechtigungsprüfung folgen mit der Datenbankanbindung.</p></section>
            </fieldset>
            <section class="bulk-edit-section"><h3>Ausgewählte Dokumente löschen</h3><p class="small text-body-secondary">Die Dokumente werden erst nach Rückfrage in den Papierkorb gelegt. Andere Eingaben in dieser Maske werden dabei nicht übernommen.</p><button class="btn btn-outline-danger" type="button" id="bulkDeleteSelected">Ausgewählte löschen …</button></section>
        </div>
        <section id="bulkReview" hidden><h3 id="bulkReviewTitle" tabindex="-1"></h3><ul id="bulkReviewList"></ul><p class="small text-body-secondary">Es werden ausschließlich die oben genannten, per Checkbox ausgewählten Dokumente geändert.</p></section>
        <p id="bulkEditError" class="text-danger mt-3" role="alert"></p>
    </div>
    <div class="modal-footer" id="bulkEditButtons"><button type="button" class="btn btn-surface" data-bs-dismiss="modal">Abbrechen</button><button type="submit" class="btn btn-primary" id="bulkReviewChanges">Änderungen prüfen</button></div>
    <div class="modal-footer" id="bulkReviewButtons" hidden><button type="button" class="btn btn-surface" id="bulkReviewBack">Zurück</button><button type="button" class="btn btn-surface" data-bs-dismiss="modal">Abbrechen</button><button type="button" class="btn btn-primary" id="bulkApplyChanges">Änderungen übernehmen</button></div>
</form></div></div>

<div class="offcanvas offcanvas-start" tabindex="-1" id="folderDrawer" aria-labelledby="folderDrawerTitle">
    <div class="offcanvas-header"><h2 class="offcanvas-title h5" id="folderDrawerTitle">Deine Ablage</h2><button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Schließen"></button></div>
    <div class="offcanvas-body"><div id="folderNavigation"></div><button class="btn btn-surface w-100 mt-3" id="newFolder" data-new-folder><svg class="icon"><use href="#i-plus"/></svg> Neuer Ordner</button><p class="small text-body-secondary mt-4">Ordner enthalten Verknüpfungen. Dasselbe Dokument kann in mehreren Ordnern liegen.</p></div>
</div>

<div class="modal fade" tabindex="-1" id="invoiceModal" aria-labelledby="invoiceTitle" data-bs-backdrop="static"><div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable"><form class="modal-content" id="invoiceForm"><div class="modal-header"><div><span class="section-kicker">BUCHUNGSDATEN</span><h2 class="modal-title h5" id="invoiceTitle">Mini-Buchhaltung</h2></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Änderungen verwerfen und schließen"></button></div><div class="modal-body" id="invoiceEditor"></div><div class="modal-footer invoice-footer"><div><span class="detail-label">Gesamtsumme brutto</span><strong id="invoiceFooterGross">–</strong></div><div class="d-flex gap-2"><button type="button" class="btn btn-surface" data-bs-dismiss="modal">Abbrechen</button><button type="submit" class="btn btn-primary">Buchungsdaten speichern</button></div></div></form></div></div>

<div class="modal fade" tabindex="-1" id="linkModal" aria-labelledby="linkTitle"><div class="modal-dialog modal-dialog-centered"><form class="modal-content" id="linkForm"><div class="modal-header"><h2 class="modal-title h5" id="linkTitle">In Ordner ablegen</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button></div><div class="modal-body"><p id="linkDescription" class="text-body-secondary"></p><div id="linkFolderOptions" class="folder-options"></div><p class="small text-body-secondary mt-3 mb-0">Weitere Verknüpfungen bleiben erhalten. Es entstehen keine Dateikopien.</p></div><div class="modal-footer"><button type="button" class="btn btn-surface" data-bs-dismiss="modal">Abbrechen</button><button type="submit" class="btn btn-primary">Verknüpfungen hinzufügen</button></div></form></div></div>
<div class="modal fade" tabindex="-1" id="folderModal" aria-labelledby="folderTitle"><div class="modal-dialog modal-dialog-centered"><form class="modal-content" id="folderForm"><div class="modal-header"><h2 class="modal-title h5" id="folderTitle">Neuer Ordner</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button></div><div class="modal-body"><label for="folderName" class="form-label">Name</label><input id="folderName" class="form-control" required maxlength="100" autocomplete="off"><label for="folderParent" class="form-label mt-3">Übergeordneter Ordner</label><select id="folderParent" class="form-select"></select><p id="folderError" class="text-danger mt-2" role="alert"></p></div><div class="modal-footer"><button type="button" class="btn btn-surface" data-bs-dismiss="modal">Abbrechen</button><button type="submit" class="btn btn-primary">Ordner anlegen</button></div></form></div></div>
<div class="modal fade" tabindex="-1" id="folderEditModal" aria-labelledby="folderEditTitle"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><form class="modal-content" id="folderEditForm"><div class="modal-header"><h2 class="modal-title h5" id="folderEditTitle">Ordner bearbeiten</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button></div><div class="modal-body"><label for="folderEditName" class="form-label">Ordnername</label><input id="folderEditName" class="form-control" required maxlength="100"><p id="folderEditError" class="text-danger mt-2" role="alert"></p><p id="folderEditUsage" class="small mt-3"></p><p id="folderDeleteHint" class="small text-body-secondary"></p><button type="button" class="btn btn-outline-danger" id="folderDelete" aria-describedby="folderDeleteHint">Ordner löschen …</button></div><div class="modal-footer"><button type="button" class="btn btn-surface" data-bs-dismiss="modal">Abbrechen</button><button type="submit" class="btn btn-primary">Namen speichern</button></div></form></div></div>
<div class="modal fade" tabindex="-1" id="confirmModal" aria-labelledby="confirmTitle" data-bs-backdrop="static"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h2 class="modal-title h5" id="confirmTitle"></h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Abbrechen"></button></div><div class="modal-body" id="confirmMessage"></div><div class="modal-footer"><button type="button" class="btn btn-surface" data-bs-dismiss="modal">Abbrechen</button><button type="button" class="btn btn-danger" id="confirmAction">In den Papierkorb</button></div></div></div></div>
<div class="modal fade" tabindex="-1" id="milestoneModal" aria-labelledby="milestoneTitle"><div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h2 class="modal-title h5" id="milestoneTitle">Meilenstein 1 · Das Grundgefühl</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button></div><div class="modal-body milestone-content"><span class="section-kicker">BEREIT ZUM AUSPROBIEREN</span><h3>Passt dieser Arbeitsplatz zu deinem Alltag?</h3><p>Dieser erste Stand legt die Bedienung fest. Die echte Datenbank, Benutzeranmeldung, Dateiimporte und Migration folgen nach deiner Rückmeldung.</p><ol><li><strong>Orientierung:</strong> Vier Spalten mit Ordnerliste links; Trennlinien ziehen, Profil wechseln und Breiten zurücksetzen. Mobil zwischen Liste, Vorschau und Details wechseln.</li><li><strong>Ablage:</strong> Dokument auswählen und seine hervorgehobenen Ordner prüfen. Per Drag-and-drop nach Bestätigung zusätzlich verlinken.</li><li><strong>Eingang:</strong> Neue Importe sind von Alle Dokumente getrennt. Übernehmen, Speichern oder bestätigtes Verlinken schließt den Eingang ab; bloßes Öffnen nicht.</li><li><strong>Bearbeitung:</strong> Beschreibung, Datum, Notiz und Tags über die durchsuchbare Auswahl ändern.</li><li><strong>Mini-Buchhaltung:</strong> Bruttosumme einer Rechnung anklicken; Summen oder Positionen mit verschiedenen Steuersätzen erfassen.</li><li><strong>Papierkorb:</strong> Mehrere Dokumente auswählen, entfernen und wiederherstellen.</li><li><strong>Darstellung:</strong> Hell, Dunkel und eigene Farben in den Einstellungen testen.</li></ol><div class="milestone-next"><strong>Danach: Meilenstein 2</strong><p class="mb-0">Echte Dokumentverwaltung mit eigener Datenbank, Anmeldung, Berechtigungen, Dateivorschau und serverseitiger Suche.</p></div></div><div class="modal-footer"><button type="button" class="btn btn-primary" data-bs-dismiss="modal">Ausprobieren</button></div></div></div></div>
<div class="toast-container position-fixed bottom-0 end-0 p-3"><div class="toast" id="feedbackToast" role="status" aria-live="polite" aria-atomic="true"><div class="d-flex"><div class="toast-body" id="feedbackMessage"></div><button type="button" class="btn-close me-3 m-auto" data-bs-dismiss="toast" aria-label="Schließen"></button></div></div></div>
<noscript><div class="alert alert-warning m-3">Für diesen Arbeitsplatz muss JavaScript aktiviert sein.</div></noscript>
</body>
</html>
