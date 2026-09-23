import { CURRENCIES, money, documentCurrency } from './currency.js';
import { DocumentStore } from './document-store.js';
import { applyTheme, readTheme, DEFAULT_THEME, themeKey } from './theme.js';
import { ColumnLayout } from './layout.js';
import { TagPicker } from './tag-picker.js';
import { InvoiceEditor } from './invoice-editor.js';
import { AccountingSetup } from './accounting-settings.js';
import { AccountPicker } from './account-picker.js';
import { SEARCH_DEFAULTS, SORT_LABELS, searchWindow } from './document-search.js';
import { BulkEditor } from './bulk-editor.js';
import { SettingsEditor } from './settings-editor.js';
import { SettingsNavigation } from './settings-navigation.js';
import { browserTenantContext } from './tenancy.js';
import { TenantEditor } from './tenant-editor.js';
import { LicenseEditor } from './license-editor.js';

const $ = id => document.getElementById(id);
const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' })[char]);
const icon = name => `<svg class="icon" aria-hidden="true"><use href="#i-${name}"/></svg>`;
const dateLabel = value => new Intl.DateTimeFormat('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' }).format(new Date(`${value}T12:00:00`));
const tenantContext = browserTenantContext();
const storage = tenantContext.storage;
const store = new DocumentStore(storage);
$('amountCurrencyFilter').innerHTML = CURRENCIES.map(code => `<option>${code}</option>`).join('');
const state = { ...structuredClone(SEARCH_DEFAULTS), page: 'documents', scope: 'all', resultPage: 1, activeId: null, selected: new Set(), editing: false, linkIds: [] };
let visibleRows = [];
const searchFields = [['searchInput','query'], ['dateFromFilter','dateFrom'], ['dateToFilter','dateTo'], ['amountFromFilter','amountFrom'], ['amountToFilter','amountTo'], ['amountCurrencyFilter','amountCurrency'], ['includeExpiredFilter','includeExpired'], ['includeNotSearchableFilter','includeNotSearchable'], ['invoiceNumbersFilter','invoiceNumbers'], ['typeFilter','type'], ['ownerFilter','ownerId'], ['resultViewFilter','resultView'], ['latestCountFilter','latestCount'], ['sortSelect','sort'], ['pageSizeSelect','pageSize']];
let theme = readTheme();
let confirmCallback = null;
let tagPicker = null;
let dragIds = [];
let editingFolderId = null;
let profile = 'standard';
try { profile = storage?.getItem('o8.demo.profile') === 'second' ? 'second' : 'standard'; } catch { /* Standardprofil verwenden. */ }
const layout = new ColumnLayout($('documentWorkspace'), storage, profile, success => {
    $('layoutSaveStatus').textContent = success ? 'Spaltenbreiten für dieses Profil gespeichert.' : 'Spaltenbreiten gelten nur bis zum Neuladen.';
});
const invoiceEditor = new InvoiceEditor(store, () => { render(); saved('Buchungsdaten gespeichert.'); });
const bulkEditor = new BulkEditor(store, (count, trashed) => {
    state.selected.clear(); state.editing = false; render();
    saved(`${count} Dokument(e) ${trashed ? 'in den Papierkorb gelegt' : 'geändert'}.`);
});
new AccountingSetup(store, () => { renderAccountFilter(); saved('Buchhaltungs-Setup gespeichert.'); });
const settingsEditor = new SettingsEditor(store, storage, () => profile, confirmation, message => {
    state.includeTags = state.includeTags.filter(tag => store.snapshot().tags.includes(tag));
    state.excludeTags = state.excludeTags.filter(tag => store.snapshot().tags.includes(tag));
    syncSearchControls(); render(); saved(message);
});
new TenantEditor(tenantContext.registry, confirmation);
new LicenseEditor(storage,tenantContext,confirmation);
const settingsNavigation = new SettingsNavigation(storage,()=>profile);

function notify(message) {
    $('feedbackMessage').textContent = message;
    bootstrap.Toast.getOrCreateInstance($('feedbackToast'), { delay: 4200 }).show();
}
function saved(message) {
    notify(store.storageFailed ? `${message} Browser-Speicherung nicht verfügbar; Änderungen gelten nur bis zum Neuladen.` : message);
}
function confirmation(title, message, label, callback, destructive = true) {
    $('confirmTitle').textContent = title; $('confirmMessage').textContent = message;
    $('confirmAction').textContent = label; confirmCallback = callback;
    $('confirmAction').classList.toggle('btn-danger', destructive);
    $('confirmAction').classList.toggle('btn-primary', !destructive);
    bootstrap.Modal.getOrCreateInstance($('confirmModal')).show();
}
$('confirmAction').addEventListener('click', () => {
    const callback = confirmCallback; confirmCallback = null;
    bootstrap.Modal.getInstance($('confirmModal')).hide();
    callback?.();
});
$('confirmModal').addEventListener('hidden.bs.modal', () => { confirmCallback = null; });
function folderName(id) { return store.snapshot().folders.find(folder => folder.id === Number(id))?.name || 'Ordner'; }
function folderPath(folder) {
    const parent = store.snapshot().folders.find(item => item.id === folder.parentId);
    return parent ? `${parent.name} / ${folder.name}` : folder.name;
}
function orderedFolders() {
    const folders = store.snapshot().folders;
    const result = [];
    function visit(parentId, depth) {
        for (const folder of folders.filter(item => item.parentId === parentId)) {
            result.push({ ...folder, depth }); visit(folder.id, depth + 1);
        }
    }
    visit(null, 0);
    return result;
}
function scopeTitle() {
    return typeof state.scope === 'number' ? folderName(state.scope) : ({ all: 'Alle Dokumente', inbox: 'Eingang', unfiled: 'Nicht zugeordnet', trash: 'Papierkorb' })[state.scope];
}
function demoUserId() { return profile === 'second' ? 2 : 1; }
function searchAsProfile(options = {}) { return store.list({...options,demoUserId:demoUserId()}); }
function renderOwnerFilter() {
    const users = store.snapshot().users;
    const admin = users.find(user => user.id === demoUserId())?.role === 'admin';
    $('ownerFilterField').hidden = !admin;
    $('ownerFilter').disabled = !admin;
    if (!admin) state.ownerId = '';
    $('ownerFilter').innerHTML = '<option value="">Alle Benutzer</option>' + users.map(user => `<option value="${user.id}">${escape(user.name)}${user.active ? '' : ' (gesperrt)'}</option>`).join('');
    $('ownerFilter').value = String(state.ownerId);
}
function renderNavigation() {
    const linkedFolders = new Set(store.foldersFor(state.activeId).map(folder => folder.id));
    const item = (scope, title, glyph, count, depth = 0) => `<button class="folder-nav-item ${state.scope === scope ? 'active' : ''} ${linkedFolders.has(scope) ? 'has-selected-document' : ''}" data-scope="${scope}" ${typeof scope === 'number' ? `data-drop-folder="${scope}"` : ''} ${state.scope === scope ? 'aria-current="true"' : ''} ${linkedFolders.has(scope) ? 'title="Das ausgewählte Dokument ist hier verlinkt"' : ''} style="padding-left:${12 + depth * 16}px">${icon(glyph)}<span>${escape(title)}${linkedFolders.has(scope) ? '<span class="visually-hidden"> – ausgewähltes Dokument hier verlinkt</span>' : ''}</span><span class="count">${count}</span></button>`;
    const markup = item('inbox', 'Eingang', 'inbox', searchAsProfile({ scope: 'inbox' }).length)
        + item('all', 'Alle Dokumente', 'document', searchAsProfile().length)
        + item('unfiled', 'Nicht zugeordnet', 'inbox', searchAsProfile({ scope: 'unfiled' }).length)
        + '<div class="folder-nav-label">ORDNER</div>'
        + orderedFolders().map(folder => `<div class="folder-nav-row">${item(folder.id, folder.name, 'folder', searchAsProfile({ scope: folder.id }).length, folder.depth)}<button class="btn btn-sm icon-btn folder-manage" data-folder-actions="${folder.id}" aria-label="Ordner ${escape(folder.name)} bearbeiten" title="Ordner umbenennen oder löschen">${icon('settings')}</button></div>`).join('')
        + '<div class="folder-nav-label">VERWALTEN</div>' + item('trash', 'Papierkorb', 'trash', searchAsProfile({ scope: 'trash' }).length);
    $('folderNavigation').innerHTML = markup;
    $('folderNavigationDesktop').innerHTML = markup;
}
function render() {
    let matches = [], error = '', result = searchWindow([]);
    renderOwnerFilter();
    try {
        if (['dateFromFilter','dateToFilter'].some(id => $(id).validity.badInput)) throw new Error('Bitte ein vollständiges, gültiges Datum eingeben.');
        matches = searchAsProfile(state);
        result = searchWindow(matches, state);
    } catch (problem) { error = problem.message; }
    $('searchError').textContent = error; $('searchError').hidden = !error;

    state.resultPage = result.page;
    const rows = result.rows; visibleRows = rows;
    const visibleIds = new Set(rows.map(doc => doc.id));
    state.selected = new Set([...state.selected].filter(id => visibleIds.has(id)));
    if (!visibleIds.has(state.activeId)) { state.activeId = rows[0]?.id || null; state.editing = false; }
    $('scopeTitle').textContent = scopeTitle();
    $('scopeDescription').textContent = typeof state.scope === 'number' ? 'Dokumente in diesem Ordner und seinen Unterordnern.' : state.scope === 'trash' ? 'Entfernte Dokumente bleiben wiederherstellbar.' : state.scope === 'inbox' ? 'Neue Importe – Übernehmen, Speichern oder Verlinken schließt den Eingang ab.' : state.scope === 'unfiled' ? 'Diese Dokumente haben noch keinen Platz in einem Ordner.' : 'Ein Dokument. Überall richtig abgelegt.';
    $('resultCount').textContent = error ? 'Sucheingaben prüfen' : result.total ? `${result.from}–${result.to} von ${result.total} Treffern` : '0 Treffer';
    if (!error && state.resultView === 'latest') {
        $('resultCount').textContent = `${result.from}–${result.to} von ${result.total} neuesten · ${result.matchedTotal} Treffer insgesamt`;
        if (matches.some(doc => !doc.createdAt)) $('scopeDescription').textContent += ' Bei alten Demodaten ohne Aufnahmezeitpunkt: D-ID absteigend, nach datierten Einträgen.';
    }
    $('latestCountField').hidden = state.resultView !== 'latest';
    $('sortSelect').disabled = state.resultView === 'latest';
    $('resultsPage').textContent = `Seite ${result.page} / ${result.pages}`;
    $('previousResults').disabled = result.page === 1 || !!error;
    $('nextResults').disabled = result.page === result.pages || !!error;
    const count = [state.ownerId, state.resultView !== 'all', state.type, state.dateFrom, state.dateTo, state.amountFrom, state.amountTo, state.invoiceNumbers, state.accountCode, state.includeExpired, state.includeNotSearchable, state.amountCurrency !== SEARCH_DEFAULTS.amountCurrency, state.includeTags.length, state.excludeTags.length, state.sort !== SEARCH_DEFAULTS.sort, state.pageSize !== SEARCH_DEFAULTS.pageSize].filter(Boolean).length;
    $('filterCount').hidden = !count; $('filterCount').textContent = count;
    document.querySelector('.list-panel .quiet-label').textContent = state.resultView === 'latest' ? 'Aufnahme ↓' : SORT_LABELS[state.sort];
    $('documentList').innerHTML = rows.length ? rows.map(doc => {
        const folders = store.foldersFor(doc.id);
        return `<article class="doc-row ${doc.id === state.activeId ? 'is-active' : ''}" draggable="${!doc.deletedAt}" data-drag-document="${doc.id}">
            <label class="doc-check"><input class="form-check-input" type="checkbox" data-select="${doc.id}" ${state.selected.has(doc.id) ? 'checked' : ''} aria-label="${escape(doc.title)} auswählen"></label>
            <button class="doc-open" data-document="${doc.id}" ${doc.id === state.activeId ? 'aria-current="true"' : ''}>
                <span class="doc-icon">${icon('document')}</span><span class="doc-copy"><span class="doc-title">${escape(doc.title)}</span><span class="doc-sender">${escape(doc.sender)}</span><span class="doc-meta"><time datetime="${doc.date}">${dateLabel(doc.date)}</time><span class="tag">${escape(doc.type)}</span><span title="${folders.length} Ordner">${icon('link')} ${folders.length}</span></span></span>
            </button></article>`;
    }).join('') : `<div class="empty-state">${icon('search')}<h2>${error ? 'Bitte Sucheingaben prüfen.' : state.scope === 'trash' && !state.query && !count ? 'Der Papierkorb ist leer.' : 'Keine Dokumente gefunden.'}</h2><p>${state.query || count || error ? 'Passe die Suchbedingungen an oder setze die Suche zurück.' : 'Hier erscheinen Dokumente, sobald sie diesem Bereich zugeordnet sind.'}</p>${state.query || count || error ? '<button class="btn btn-surface" data-reset-filters>Suche zurücksetzen</button>' : ''}</div>`;
    $('selectAll').checked = rows.length > 0 && state.selected.size === rows.length;
    $('selectAll').indeterminate = state.selected.size > 0 && state.selected.size < rows.length;
    $('selectAll').disabled = !rows.length;
    $('bulkActions').hidden = !state.selected.size;
    $('openBulkEditor').disabled = !state.selected.size;
    $('bulkEditorTrigger').title = state.selected.size
        ? `Massenänderung für ${state.selected.size} ausgewählte Dokument(e)`
        : 'Massenänderung: Bitte mindestens ein Dokument per Checkbox auswählen.';
    $('selectionCount').textContent = `${state.selected.size} ausgewählt`;
    $('bulkLink').hidden = state.scope === 'trash'; $('bulkTrash').hidden = state.scope === 'trash'; $('bulkRestore').hidden = state.scope !== 'trash';
    renderNavigation(); renderDocument();
}
function renderDocument() {
    const doc = store.document(state.activeId);
    $('documentId').textContent = doc ? `D${doc.id}` : '–';
    if (!doc) {
        $('documentPreview').innerHTML = `<div class="empty-state">${icon('document')}<h2>Platz für dein Dokument.</h2><p>Wähle ein Dokument aus der Liste.</p></div>`;
        $('documentDetails').innerHTML = '<div class="empty-state"><p>Hier findest du die Informationen zum ausgewählten Dokument.</p></div>';
        return;
    }
    $('documentPreview').innerHTML = `<article class="document-paper" aria-label="Fiktive Dokumentvorschau">
        <div class="paper-brand"><span class="paper-mark" aria-hidden="true">n</span>${escape(doc.sender)}</div>
        <div class="paper-recipient">Alex Muster<br>Lindenstraße 12<br>12345 Musterstadt</div>
        <span class="paper-caption">${escape(doc.type)} · ${escape(doc.reference)}</span><h2>${escape(doc.title)}</h2>
        <div class="paper-reference"><span>Dokumentnummer<br><strong>${escape(doc.reference)}</strong></span><span>Datum<br><strong>${dateLabel(doc.date)}</strong></span></div>
        <p>Guten Tag Alex Muster,</p><p>${doc.type === 'Rechnung' ? 'vielen Dank für Ihr Vertrauen. Nachfolgend finden Sie die Übersicht zu Ihrer Abrechnung.' : 'anbei erhalten Sie die Unterlagen zu Ihrem Vorgang. Bitte bewahren Sie dieses Dokument auf.'}</p>
        ${doc.amountCents !== null ? `<table class="paper-lines"><thead><tr><th>Beschreibung</th><th>Betrag</th></tr></thead><tbody><tr><td>${escape(doc.type === 'Rechnung' ? 'Leistung laut Abrechnung' : 'Ausgewiesener Betrag')}</td><td>${money(doc.amountCents, documentCurrency(doc))}</td></tr></tbody></table><div class="paper-total"><span>Gesamtbetrag</span><strong>${money(doc.amountCents, documentCurrency(doc))}</strong></div>` : '<p class="mt-4">Die Einzelheiten und Vereinbarungen sind Bestandteil dieses Dokuments. Für Rückfragen stehen wir Ihnen gerne zur Verfügung.</p>'}
        <div class="paper-foot">FIKTIVES BEISPIELDOKUMENT · o8 Entwurf<br>Diese Vorschau dient ausschließlich zur Beurteilung der Oberfläche und enthält keine echten Daten.</div>
        </article><p class="preview-hint">Gestaltete Beispielvorschau · Die echte Datei-/PDF-Ansicht folgt in Meilenstein 2.</p>`;
    if (state.editing) { renderEdit(doc); return; }
    const folders = store.foldersFor(doc.id);
    $('documentDetails').innerHTML = `<span class="tag">${escape(doc.type)}</span>${doc.expired ? '<span class="tag">Abgelaufen</span>' : ''}${!doc.searchable ? '<span class="tag">Nicht suchbar</span>' : ''}<h2>${escape(doc.title)}</h2><p class="detail-subtitle">${escape(doc.sender)}</p>
        <div class="detail-actions">${doc.deletedAt ? '<button class="btn btn-primary" data-restore>Wiederherstellen</button>' : `<button class="btn btn-surface" data-edit>Bearbeiten</button><button class="btn btn-surface icon-btn" data-trash aria-label="Dokument in den Papierkorb">${icon('trash')}</button>`}</div>
        ${doc.inInbox && !doc.deletedAt ? `<div class="inbox-summary"><span>${icon('inbox')} Im Eingang</span><button class="btn btn-surface btn-sm" data-complete-inbox title="In Alle Dokumente übernehmen">Übernehmen</button><small>Übernehmen, Speichern oder Verlinken schließt den Eingang ab.</small></div>` : ''}
        <div class="detail-section"><div class="detail-fields"><div><span class="detail-label">Dokumentdatum</span>${dateLabel(doc.date)}</div><div><span class="detail-label">Quelle</span>${escape(doc.source)}</div><div><span class="detail-label">Referenz</span>${escape(doc.reference)}</div><div><span class="detail-label">Besitzer (Demo)</span>${escape(store.snapshot().users.find(user => user.id === doc.ownerId)?.name || 'Nicht zugeordnet')}</div></div></div>
        <section class="detail-section"><h3>Mini-Buchhaltung</h3><button class="invoice-summary" data-invoice ${doc.deletedAt ? 'disabled' : ''}><span>${doc.invoice ? 'Gesamtsumme brutto' : 'Buchungsdaten hinzufügen'}</span><strong>${money(doc.amountCents, documentCurrency(doc))}</strong><span>${doc.invoice ? 'Buchungsdaten und Positionen bearbeiten ↗' : 'Für jede Dokumentart: Absender, Summen und Positionen ↗'}</span></button></section>
        <section class="detail-section"><h3>In ${folders.length} Ordner${folders.length === 1 ? '' : 'n'} verlinkt</h3>${folders.length ? folders.map(folder => `<div class="folder-chip">${icon('folder')}<span>${escape(folder.name)}</span>${!doc.deletedAt ? `<button data-unlink="${folder.id}" title="Nur diese Verknüpfung entfernen" aria-label="Aus ${escape(folder.name)} entfernen">${icon('close')}</button>` : ''}</div>`).join('') : '<p>Noch keinem Ordner zugeordnet.</p>'}${!doc.deletedAt ? `<button class="link-action" data-link>${icon('plus')} In weiterem Ordner ablegen</button>` : '<p>Ordnerverknüpfungen werden beim Wiederherstellen wieder sichtbar.</p>'}</section>
        <section class="detail-section"><h3>Tags</h3><div class="tag-list">${doc.tags.map(tag => `<span class="tag">${escape(tag)}</span>`).join('') || '<span class="quiet-label">Keine Tags</span>'}</div></section>
        <section class="detail-section"><h3>Notiz</h3><p>${escape(doc.memo || 'Noch keine Notiz.').replace(/\n/g, '<br>')}</p></section>
        <section class="detail-section"><h3>Datei</h3><div class="file-info">${icon('document')}<span>${escape(doc.filename)}<br>Beispieldatei · D${doc.id}</span></div></section>`;
}
function renderEdit(doc) {
    $('documentDetails').innerHTML = `<form id="editDocumentForm" class="detail-edit"><h2>Dokument bearbeiten</h2><label class="form-label" for="editTitle">Beschreibung</label><input class="form-control" id="editTitle" required maxlength="255" value="${escape(doc.title)}"><label class="form-label" for="editType">Dokumentart</label><select class="form-select" id="editType">${['Rechnung','Gutschrift','Vertrag','Bescheinigung','Dokument'].map(type => `<option ${type === doc.type ? 'selected' : ''}>${type}</option>`).join('')}</select><label class="form-label" for="editDate">Dokumentdatum</label><input class="form-control" type="date" id="editDate" required value="${doc.date}"><label class="form-label" for="editMemo">Notiz</label><textarea id="editMemo" class="form-control" rows="4">${escape(doc.memo)}</textarea><fieldset class="mt-3"><legend class="detail-label">Dokumentstatus</legend><label class="d-block"><input type="checkbox" class="form-check-input" id="editExpired" ${doc.expired ? 'checked' : ''}> Abgelaufen</label><label class="d-block mt-2"><input type="checkbox" class="form-check-input" id="editNotSearchable" ${!doc.searchable ? 'checked' : ''}> Nicht suchbar</label><p class="small text-body-secondary mt-2">Standardmäßig aus Suchergebnissen ausgeschlossen. Über die Detailsuche wieder einschließbar.</p></fieldset><fieldset class="mt-3"><legend class="detail-label">Tags</legend><div id="documentTagPicker"></div></fieldset><p class="text-danger small mt-2" id="editError" role="alert"></p><div class="d-flex gap-2 mt-4"><button type="submit" class="btn btn-primary">Speichern</button><button type="button" class="btn btn-surface" data-cancel-edit>Abbrechen</button></div></form>`;
    tagPicker = new TagPicker($('documentTagPicker'), store.snapshot().tags, doc.tags);
    $('editDocumentForm').addEventListener('submit', event => {
        event.preventDefault();
        try {
            store.saveDocument(doc.id, { title: $('editTitle').value, date: $('editDate').value, memo: $('editMemo').value, type: $('editType').value, tags: tagPicker.values(), expired: $('editExpired').checked, searchable: !$('editNotSearchable').checked });
            state.editing = false; render(); saved('Dokument gespeichert.');
        } catch (error) { $('editError').textContent = error.message; }
    });
}
function openLinks(ids) {
    state.linkIds = [...ids];
    $('linkDescription').textContent = `${ids.length} Dokument${ids.length === 1 ? '' : 'e'} mit einem oder mehreren Ordnern verknüpfen. Eingangsdokumente werden dabei in Alle Dokumente übernommen.`;
    const existing = ids.length === 1 ? new Set(store.foldersFor(ids[0]).map(folder => folder.id)) : new Set();
    $('linkFolderOptions').innerHTML = orderedFolders().map(folder => `<label><input class="form-check-input" type="checkbox" name="linkFolder" value="${folder.id}" ${existing.has(folder.id) ? 'checked disabled' : ''}>${icon('folder')}<span>${escape(folderPath(folder))}${existing.has(folder.id) ? ' · bereits verlinkt' : ''}</span></label>`).join('');
    bootstrap.Modal.getOrCreateInstance($('linkModal')).show();
}
function trash(ids) {
    confirmation(`${ids.length} Dokument${ids.length === 1 ? '' : 'e'} in den Papierkorb?`, 'Die Dokumente verschwinden aus allen Ordnern. Im Papierkorb kannst du sie einschließlich ihrer Zuordnungen wiederherstellen.', 'In den Papierkorb', () => {
        store.trash(ids); state.selected.clear(); state.editing = false; render(); saved(`${ids.length} Dokument(e) im Papierkorb.`);
    });
}
function restore(ids) { store.restore(ids); state.selected.clear(); render(); saved(`${ids.length} Dokument(e) wiederhergestellt.`); }
function pane(name) {
    $('documentWorkspace').dataset.pane = name;
    document.querySelectorAll('[data-pane]').forEach(button => {
        if (button.tagName !== 'BUTTON') return;
        button.classList.toggle('active', button.dataset.pane === name);
        if (button.dataset.pane === name) button.setAttribute('aria-current', 'true'); else button.removeAttribute('aria-current');
    });
}
function clearFilters() {
    Object.assign(state, structuredClone(SEARCH_DEFAULTS));
    state.resultPage = 1; state.selected.clear(); state.editing = false;
    syncSearchControls(); render();
}
function searchChanged() { state.resultPage = 1; state.selected.clear(); state.editing = false; pane('list'); render(); }
function renderAccountFilter() {
    new AccountPicker($('accountFilter'), store.accountingSettings().accounts, state.accountCode, 'Buchungskonto filtern', 'Alle Buchungskonten', value => { state.accountCode = value; searchChanged(); });
}
function syncSearchControls() {
    for (const [id,key] of searchFields) {
        if ($(id).type === 'checkbox') $(id).checked = state[key];
        else $(id).value = String(state[key]);
    }
    renderOwnerFilter();
    const tags = store.snapshot().tags;
    new TagPicker($('includeTagsFilter'), tags, state.includeTags, values => { state.includeTags = values; searchChanged(); }, 'Enthaltene Tags suchen');
    new TagPicker($('excludeTagsFilter'), tags, state.excludeTags, values => { state.excludeTags = values; searchChanged(); }, 'Ausgeschlossene Tags suchen');
    renderAccountFilter();
}
function page(name, changeHash = true) {
    if (!['documents', 'settings', 'reports', 'inbound'].includes(name)) name = 'documents';
    state.page = name;
    document.querySelectorAll('main.page').forEach(element => { element.hidden = element.id !== `page-${name}`; });
    document.querySelectorAll('[data-page]').forEach(button => {
        button.classList.toggle('active', button.dataset.page === name);
        if (button.dataset.page === name) button.setAttribute('aria-current', 'page'); else button.removeAttribute('aria-current');
    });
    document.title = `o8 · ${{documents:'Dokumente',inbound:'Eingang',reports:'Auswertung',settings:'Einstellungen'}[name]}`;
    if (changeHash && location.hash !== `#${name}`) history.pushState(null, '', `#${name}`);
    if (name === 'settings') { renderThemeSettings(); settingsEditor.render(); }
}
document.addEventListener('click', event => {
    const nav = event.target.closest('[data-page]');
    if (nav) page(nav.dataset.page);
    const paneButton = event.target.closest('button[data-pane]');
    if (paneButton) pane(paneButton.dataset.pane);
    if (event.target.closest('[data-reset-filters]')) clearFilters();
});
window.addEventListener('hashchange', () => page(location.hash.slice(1), false));
$('documentList').addEventListener('click', event => {
    const button = event.target.closest('[data-document]');
    if (button) {
        state.activeId = Number(button.dataset.document); state.editing = false; render();
        if (matchMedia('(max-width: 991px)').matches) pane('preview');
    }
});
$('documentList').addEventListener('change', event => {
    if (!event.target.matches('[data-select]')) return;
    const id = Number(event.target.dataset.select);
    if (event.target.checked) state.selected.add(id); else state.selected.delete(id);
    render();
});
$('selectAll').addEventListener('change', event => { state.selected = new Set(event.target.checked ? visibleRows.map(doc => doc.id) : []); render(); });
$('clearSelection').addEventListener('click', () => { state.selected.clear(); render(); });
$('openBulkEditor').addEventListener('click', () => bulkEditor.open([...state.selected]));
$('bulkEditorTrigger').addEventListener('click', () => {
    if (!state.selected.size) notify('Massenänderung: Bitte mindestens ein Dokument links per Checkbox auswählen. Das Öffnen der Vorschau allein reicht nicht.');
});
$('bulkLink').addEventListener('click', () => openLinks([...state.selected]));
$('bulkTrash').addEventListener('click', () => trash([...state.selected]));
$('bulkRestore').addEventListener('click', () => restore([...state.selected]));
function navigateFolder(event) {
    const button = event.target.closest('[data-scope]');
    if (!button) return;
    state.scope = /^\d+$/.test(button.dataset.scope) ? Number(button.dataset.scope) : button.dataset.scope;
    state.resultPage = 1; state.selected.clear(); state.editing = false; pane('list'); render();
    bootstrap.Offcanvas.getInstance($('folderDrawer'))?.hide();
}
$('folderNavigation').addEventListener('click', navigateFolder);
$('folderNavigationDesktop').addEventListener('click', navigateFolder);
function openFolderEditor(id) {
    const folder = store.snapshot().folders.find(folder => folder.id === id);
    if (!folder) return;
    editingFolderId = id;
    const usage = store.folderUsage(id);
    $('folderEditName').value = folder.name; $('folderEditError').textContent = '';
    $('folderDelete').disabled = !usage.empty;
    $('folderEditUsage').textContent = `${usage.documents} aktive Dokumentverknüpfung(en), ${usage.children} Unterordner. ${usage.trashed ? `${usage.trashed} Dokument(e) im Papierkorb verhindern das Löschen nicht; ihre Verknüpfungen zu diesem Ordner entfallen dabei.` : ''}`;
    $('folderDeleteHint').textContent = usage.empty ? 'Dieser Ordner kann nach Bestätigung gelöscht werden.' : 'Löschen ist erst möglich, wenn keine aktiven Dokumente und keine Unterordner mehr enthalten sind.';
    const show = () => bootstrap.Modal.getOrCreateInstance($('folderEditModal')).show();
    if ($('folderDrawer').classList.contains('show')) {
        $('folderDrawer').addEventListener('hidden.bs.offcanvas', show, {once:true});
        bootstrap.Offcanvas.getInstance($('folderDrawer')).hide();
    } else show();
}
for (const navigation of [$('folderNavigation'), $('folderNavigationDesktop')]) navigation.addEventListener('click', event => {
    const button = event.target.closest('[data-folder-actions]');
    if (button) openFolderEditor(Number(button.dataset.folderActions));
});
$('folderEditModal').addEventListener('shown.bs.modal', () => { $('folderEditName').focus(); $('folderEditName').select(); });
$('folderEditForm').addEventListener('submit', event => {
    event.preventDefault();
    try {
        store.renameFolder(editingFolderId, $('folderEditName').value);
        bootstrap.Modal.getInstance($('folderEditModal')).hide(); render(); saved('Ordner umbenannt. Dokumentverknüpfungen bleiben erhalten.');
    } catch (error) { $('folderEditError').textContent = error.message; }
});
$('folderDelete').addEventListener('click', () => {
    const id = editingFolderId, name = folderName(id), usage = store.folderUsage(id);
    if (!usage.empty) return;
    $('folderEditModal').addEventListener('hidden.bs.modal', () => {
        confirmation('Ordner löschen?', `„${name}“ endgültig löschen?${usage.trashed ? ` Die Verknüpfungen zu ${usage.trashed} Dokument(en) im Papierkorb werden entfernt und bei einer Wiederherstellung nicht wieder angelegt.` : ''} Es werden keine Dokumente oder Dateien gelöscht.`, 'Ordner löschen', () => {
            try {
                store.deleteFolder(id);
                if (state.scope === id) { state.scope = 'all'; state.resultPage = 1; state.selected.clear(); }
                render(); saved('Ordner gelöscht. Dokumente bleiben erhalten.');
            } catch (error) { notify(error.message); }
        });
    }, {once:true});
    bootstrap.Modal.getInstance($('folderEditModal')).hide();
});
$('documentDetails').addEventListener('click', event => {
    if (event.target.closest('[data-edit]')) { state.editing = true; renderDocument(); $('editTitle').focus(); }
    if (event.target.closest('[data-cancel-edit]')) { state.editing = false; renderDocument(); }
    if (event.target.closest('[data-link]')) openLinks([state.activeId]);
    if (event.target.closest('[data-trash]')) trash([state.activeId]);
    if (event.target.closest('[data-restore]')) restore([state.activeId]);
    if (event.target.closest('[data-invoice]')) invoiceEditor.open(store.document(state.activeId));
    if (event.target.closest('[data-complete-inbox]')) { store.completeInbox(state.activeId); render(); saved('Übernommen: Das Dokument ist jetzt unter Alle Dokumente und in seinen Ordnern verfügbar.'); }
    const unlink = event.target.closest('[data-unlink]');
    if (unlink) {
        const id = state.activeId, folderId = Number(unlink.dataset.unlink);
        confirmation('Verknüpfung entfernen?', `„${store.document(id).title}“ aus „${folderName(folderId)}“ entfernen? Das Dokument und seine anderen Ordnerzuordnungen bleiben erhalten.`, 'Verknüpfung entfernen', () => {
            store.unlink([id], folderId); render(); saved('Verknüpfung entfernt. Dokument bleibt erhalten.');
        });
    }
});
$('linkForm').addEventListener('submit', event => {
    event.preventDefault();
    const folderIds = [...document.querySelectorAll('[name="linkFolder"]:checked:not(:disabled)')].map(input => Number(input.value));
    if (!folderIds.length) { notify('Bitte mindestens einen weiteren Ordner auswählen.'); return; }
    try { store.link(state.linkIds, folderIds); bootstrap.Modal.getInstance($('linkModal')).hide(); render(); saved('Ordnerverknüpfungen hinzugefügt.'); }
    catch (error) { notify(error.message); }
});
document.querySelectorAll('[data-new-folder]').forEach(button => button.addEventListener('click', () => {
    $('folderName').value = ''; $('folderError').textContent = '';
    $('folderParent').innerHTML = '<option value="">Kein übergeordneter Ordner</option>' + orderedFolders().map(folder => `<option value="${folder.id}">${escape(folderPath(folder))}</option>`).join('');
    const drawer = bootstrap.Offcanvas.getInstance($('folderDrawer'));
    if ($('folderDrawer').classList.contains('show')) {
        $('folderDrawer').addEventListener('hidden.bs.offcanvas', () => bootstrap.Modal.getOrCreateInstance($('folderModal')).show(), { once: true });
        drawer.hide();
    } else bootstrap.Modal.getOrCreateInstance($('folderModal')).show();
}));
$('folderModal').addEventListener('shown.bs.modal', () => $('folderName').focus());
$('folderForm').addEventListener('submit', event => {
    event.preventDefault();
    try {
        const folder = store.createFolder($('folderName').value, $('folderParent').value ? Number($('folderParent').value) : null);
        state.scope = folder.id; state.selected.clear(); clearFilters(); bootstrap.Modal.getInstance($('folderModal')).hide(); saved('Ordner angelegt.');
    } catch (error) { $('folderError').textContent = error.message; }
});
for (const [id,key] of searchFields) $(id).addEventListener($(id).tagName === 'SELECT' ? 'change' : 'input', event => {
    state[key] = event.target.type === 'checkbox' ? event.target.checked : ['pageSize','latestCount'].includes(key) ? Number(event.target.value) : event.target.value; searchChanged();
});
$('clearFilters').addEventListener('click', clearFilters);
$('resetSearch').addEventListener('click', clearFilters);
for (const [id, direction] of [['previousResults', -1], ['nextResults', 1]]) $(id).addEventListener('click', () => {
    state.resultPage += direction; state.selected.clear(); state.editing = false; render(); $('documentList').scrollTop = 0;
});
$('filterBar').addEventListener('show.bs.collapse', () => $('page-documents').classList.add('has-details-search'));
$('filterBar').addEventListener('hidden.bs.collapse', () => $('page-documents').classList.remove('has-details-search'));

// Drag-and-drop verlinkt nach Bestätigung; die Quelle bleibt immer erhalten.
$('documentList').addEventListener('dragstart', event => {
    const row = event.target.closest('[data-drag-document]');
    if (!row || state.scope === 'trash') { event.preventDefault(); return; }
    const id = Number(row.dataset.dragDocument);
    dragIds = state.selected.has(id) ? [...state.selected] : [id];
    event.dataTransfer.effectAllowed = 'link';
    event.dataTransfer.setData('application/x-o8-documents', JSON.stringify(dragIds));
    document.body.classList.add('dragging-documents');
});
$('documentList').addEventListener('dragend', () => {
    dragIds = []; document.body.classList.remove('dragging-documents');
    document.querySelectorAll('.drop-target').forEach(element => element.classList.remove('drop-target'));
});
for (const navigation of [$('folderNavigationDesktop'), $('folderNavigation')]) {
    navigation.addEventListener('dragover', event => {
        const folder = event.target.closest('[data-drop-folder]');
        if (!folder || !dragIds.length) return;
        event.preventDefault(); event.dataTransfer.dropEffect = 'link'; folder.classList.add('drop-target');
    });
    navigation.addEventListener('dragleave', event => {
        const folder = event.target.closest('[data-drop-folder]');
        if (folder && !folder.contains(event.relatedTarget)) folder.classList.remove('drop-target');
    });
    navigation.addEventListener('drop', event => {
        const folder = event.target.closest('[data-drop-folder]');
        if (!folder || !dragIds.length) return;
        event.preventDefault(); folder.classList.remove('drop-target');
        const ids = [...dragIds], folderId = Number(folder.dataset.dropFolder);
        confirmation('Dokumente verlinken?', `${ids.length} Dokument(e) in „${folderName(folderId)}“ verlinken? Vorhandene Ordnerzuordnungen bleiben erhalten. Eingangsdokumente werden dabei in Alle Dokumente übernommen.`, 'Verknüpfen', () => {
            store.link(ids, [folderId]); render(); saved('Dokumente verlinkt. Bestehende Zuordnungen bleiben erhalten.');
        }, false);
    });
}
function updateProfile() {
    $('layoutProfile').value = profile;
    $('layoutProfileLabel').textContent = `Profil: ${profile === 'second' ? 'Zweites Profil' : 'Standard'}`;
}
$('layoutProfile').addEventListener('change', event => {
    profile = event.target.value; layout.load(profile); updateProfile();
    try { storage?.setItem('o8.demo.profile', profile); } catch { /* Profil bleibt für diese Sitzung aktiv. */ }
    theme = readTheme(profile); $('paletteMode').value = applyTheme(theme); renderThemeSettings(); settingsEditor.render();
    settingsNavigation.restore();
    state.ownerId = ''; state.selected.clear(); state.activeId = null; state.resultPage = 1; state.editing = false;
    renderOwnerFilter(); syncSearchControls(); render();
    $('layoutSaveStatus').textContent = 'Gespeicherte Spaltenbreiten geladen.';
});
for (const id of ['resetColumns', 'resetColumnsSettings']) $(id).addEventListener('click', () => { layout.reset(); notify('Standardbreiten für dieses Profil wiederhergestellt.'); });
document.addEventListener('keydown', event => {
    if (event.key === '/' && !event.ctrlKey && !event.metaKey && !event.altKey && !event.target.closest('input, textarea, select, [contenteditable="true"]') && !document.querySelector('.modal.show, .offcanvas.show')) {
        event.preventDefault(); page('documents'); pane('list'); $('searchInput').focus();
    }
});

function persistTheme() {
    applyTheme(theme);
    try { storage.setItem(themeKey(profile), JSON.stringify(theme)); $('themeSaveStatus').textContent = 'Darstellung für dieses Vorschauprofil auf diesem Gerät gespeichert.'; }
    catch { $('themeSaveStatus').textContent = 'Speicherung nicht verfügbar. Die Darstellung gilt bis zum Neuladen.'; }
}
function renderThemeSettings() {
    document.querySelectorAll('[name="themeMode"]').forEach(input => { input.checked = input.value === theme.mode; });
    $('densitySelect').value = theme.density;
    $('fontFamilySelect').value = theme.fontFamily;
    $('fontSizeSelect').value = String(theme.fontSize);
    const palette = theme[$('paletteMode').value];
    $('accentColor').value = palette.accent; $('backgroundColor').value = palette.background; $('surfaceColor').value = palette.surface;
}
document.querySelectorAll('[name="themeMode"]').forEach(input => input.addEventListener('change', () => {
    theme.mode = input.value; $('paletteMode').value = applyTheme(theme); persistTheme(); renderThemeSettings();
}));
$('paletteMode').addEventListener('change', renderThemeSettings);
for (const [id, key] of [['accentColor', 'accent'], ['backgroundColor', 'background'], ['surfaceColor', 'surface']]) $(id).addEventListener('input', event => { theme[$('paletteMode').value][key] = event.target.value; persistTheme(); });
$('densitySelect').addEventListener('change', event => { theme.density = event.target.value; persistTheme(); });
$('fontFamilySelect').addEventListener('change', event => { theme.fontFamily = event.target.value; persistTheme(); });
$('fontSizeSelect').addEventListener('change', event => { theme.fontSize = Number(event.target.value); persistTheme(); });
$('quickTheme').addEventListener('click', () => { theme.mode = document.documentElement.dataset.bsTheme === 'dark' ? 'light' : 'dark'; $('paletteMode').value = theme.mode; persistTheme(); renderThemeSettings(); });
$('resetTheme').addEventListener('click', () => { theme = structuredClone(DEFAULT_THEME); $('paletteMode').value = applyTheme(theme); persistTheme(); renderThemeSettings(); notify('Darstellung zurückgesetzt.'); });
$('resetDemo').addEventListener('click', () => confirmation('Beispieldaten zurücksetzen?', 'Deine Änderungen an den Beispieldokumenten und Ordnern werden durch den Ausgangsbestand ersetzt. Die Darstellung bleibt erhalten.', 'Beispiele zurücksetzen', () => { store.reset(); state.scope = 'all'; state.selected.clear(); state.editing = false; clearFilters(); saved('Beispieldaten zurückgesetzt.'); }));
syncSearchControls();
$('paletteMode').value = applyTheme(theme);
updateProfile(); renderThemeSettings(); render(); page(location.hash.slice(1) || 'documents', false);
if (store.storageFailed || !storage) notify('Browser-Speicherung nicht verfügbar. Du kannst die Vorschau trotzdem ausprobieren.');
