import { ColumnLayout } from './layout.js';
import { TagPicker, confirmNewTagSelection } from './tag-picker.js';
import { applyTheme, DEFAULT_THEME, normalizeTheme, rememberTheme } from './theme.js';
import { LiveInvoiceEditor } from './live-invoice.js';
import { InboundAcceptanceDialog } from './live-inbound-acceptance.js';
import { InboundBatchDialog } from './live-inbound-batch.js';
import { bookingSummary, savedBookingSummary } from './booking-summary.js';
import { choiceDialog, confirmDialog } from './dialog.js';

const $ = id => document.getElementById(id);
const root = $('liveApp');
const tr = (key,values={}) => window.o8Translate ? window.o8Translate(`workspace.${key}`,values) : '';
const searchTr = (key,values={}) => window.o8Translate ? window.o8Translate(`search.${key}`,values) : '';
const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const dateLabel = value => /^\d{4}-\d{2}-\d{2}$/.test(String(value ?? '')) ? `${value.slice(8,10)}.${value.slice(5,7)}.${value.slice(0,4)}` : (value || tr('noDate'));
const grossLabel = doc => doc.gross_amount === null || doc.gross_amount === undefined ? '' : new Intl.NumberFormat('de-DE',{minimumFractionDigits:2,maximumFractionDigits:2}).format(Number(doc.gross_amount)) + ' ' + doc.currency;
const state = { scope: 'inbox', localScope: 'inbox', searchMode: 'global', page: 1, document: null, meta: null, filteredFolderCounts: null, filteredInboxCount: null, filteredAllCount: null, filteredTrashCount: null, dirty: false, querySequence: 0, documentSequence: 0, selected: new Set(), inboundSelected: new Set(), rows: [] };
let picker, bulkPicker, invoiceEditor, layout, preferences = { widths: [18,27,29,26], mode: 'system', theme: structuredClone(DEFAULT_THEME) }, pending = 0, waitTimer, writing = false, suppressWaiting = false;
let collapsedFolderIds=new Set(), preferenceQueue=Promise.resolve();
let noticeTimer, autoSearchTimer, sourcePollTimer;
let inboundActionItems=[];
const aiStatusLabel = value => ({not_requested:tr('aiNotRequested'),queued:tr('aiQueued'),running:tr('aiRunning'),ready:tr('aiReady'),failed:tr('aiFailed'),completed:tr('completed')})[value] || value;
const aiErrorLabel = value => ({text_too_short:t('textTooShort'),extract_tool_missing:t('extractToolMissing'),extract_failed:t('extractFailed'),extract_timeout:t('extractTimeout'),ai_timeout:t('aiTimeout'),ai_dns_failed:t('aiDnsFailed'),ai_tls_failed:t('aiTlsFailed'),ai_connection_failed:t('aiConnectionFailed'),ai_http_error:t('aiHttpError'),ai_response_too_large:t('aiResponseTooLarge'),invalid_ai_response:t('invalidAiResponse'),invalid_ai_json:t('invalidAiJson'),inbound_changed:t('inboundChanged'),ai_item_failed:t('aiItemFailed')})[value] || value || '';
function error(problem) { $('liveError').textContent = problem.message || String(problem); $('liveError').hidden = false; }
function notice(message) {
    clearTimeout(noticeTimer);
    $('liveNotice').textContent = message;
    $('liveNotice').hidden = false;
    noticeTimer = setTimeout(() => { $('liveNotice').hidden = true; }, 2000);
}
if (!$('liveNotice').hidden) notice($('liveNotice').textContent);
function busy(start) {
    pending += start ? 1 : -1;
    if (start && pending === 1 && !suppressWaiting) waitTimer = setTimeout(() => { $('searchWaiting').hidden = false; }, 180);
    if (!pending) { clearTimeout(waitTimer); $('searchWaiting').hidden = true; }
}
function url(action, input = {}) {
    const result = new URL(location.pathname, location.origin);
    result.search = new URLSearchParams({ api: action, context: root.dataset.context, ...input });
    return result;
}
function data(input) {
    const form = new FormData();
    for (const [key, value] of Object.entries(input)) form.set(key, value);
    form.set('csrf', root.dataset.csrf); form.set('context_token', root.dataset.context);
    return form;
}
async function api(action, input = {}, write = false) {
    busy(true);
    try {
        const response = await fetch(url(action, write ? {} : input), { method: write ? 'POST' : 'GET', body: write ? data(input) : undefined, credentials: 'same-origin', cache: 'no-store' });
        let json;
        try { json = await response.json(); } catch { throw new Error(window.o8TranslateSource?.('Keine gültige Serverantwort. Bitte neu laden und PHP-/Uploadlimits prüfen.') || 'Keine gültige Serverantwort. Bitte neu laden und PHP-/Uploadlimits prüfen.'); }
        if (!response.ok || !json.success) throw new Error(json.error || window.o8TranslateSource?.('Aktion fehlgeschlagen.') || 'Aktion fehlgeschlagen.');
        return json.data;
    } finally { busy(false); }
}
async function write(action, input, message) {
    if (writing) return false;
    writing = true; $('liveError').hidden = true;
    $('documentDetails').querySelectorAll('input,select,textarea,button').forEach(x => { x.disabled = true; });
    try { await api(action, input, true); if (message) notice(message); return true; }
    catch (problem) { error(problem); return false; }
    finally { writing = false; $('documentDetails').querySelectorAll('input,select,textarea,button').forEach(x => { x.disabled = false; }); }
}
async function discard() { return !writing && (!state.dirty || await confirmDialog('Änderungen verwerfen?', 'Ungespeicherte Änderungen gehen verloren.', 'Verwerfen', true)); }
function syncSelection() {
    const visible = new Set(state.rows.filter(row => !row._inbound).map(row => Number(row.id))); const inboundVisible=new Set(state.rows.filter(row=>row._inbound).map(row=>Number(row.id)));
    state.selected = new Set([...state.selected].filter(id => visible.has(Number(id)))); state.inboundSelected=new Set([...state.inboundSelected].filter(id=>inboundVisible.has(Number(id))));
    const selectedCount=state.selected.size+state.inboundSelected.size;
    const all = $('selectAll');
    all.checked = state.rows.length > 0 && selectedCount === state.rows.length;
    all.indeterminate = selectedCount > 0 && selectedCount < state.rows.length;
    all.disabled = !state.rows.length;
    $('openBulkEditor').disabled = !selectedCount;
    $('bulkEditorTrigger').title = window.o8TranslateSource?.(selectedCount ? `${selectedCount} ausgewählte Eingangselemente/Dokumente bearbeiten` : 'Massenänderung: Bitte mindestens ein Dokument per Checkbox auswählen.') || (selectedCount ? `${selectedCount} ausgewählte Eingangselemente/Dokumente bearbeiten` : 'Massenänderung: Bitte mindestens ein Dokument per Checkbox auswählen.');
    $('documentList').querySelectorAll('[data-select]').forEach(input => { input.checked = state.selected.has(Number(input.dataset.select)); });
    $('documentList').querySelectorAll('[data-inbound-select]').forEach(input => { input.checked = state.inboundSelected.has(Number(input.dataset.inboundSelect)); });
}
function options(items, empty) { return '<option value="">' + esc(empty) + '</option>' + items.map(x => '<option value="' + x.id + '">' + esc(x.name ?? x.display_name) + '</option>').join(''); }
async function metadata(initial = false) {
    state.meta = await api('meta');
    $('uploadOpen').disabled = !state.meta.storageReady;
    $('uploadAction').title = state.meta.storageReady ? '' : state.meta.storageMessage;
    $('uploadOpen').setAttribute('aria-label', state.meta.storageReady ? window.o8Translate?.('navigation.upload') : tr('uploadUnavailable',{reason:state.meta.storageMessage}));
    $('sourceOpen').title = !state.meta.storageReady ? tr('sourcesUnavailable') : state.meta.sources?.some(source => Number(source.enabled)) ? window.o8Translate?.('navigation.sourceFetchTitle') : tr('noEnabledSource');
    for (const id of ['tagFilter','notTagFilter']) {
        const selected = $(id).value; $(id).innerHTML = options(state.meta.tags, searchTr('noRestriction')); $(id).value = selected;
    }
    if ($('ownerFilter')) { const selected = $('ownerFilter').value; $('ownerFilter').innerHTML = options(state.meta.users, searchTr('allUsers')); $('ownerFilter').value = selected; }
    if (initial) {
        preferences = state.meta.preferences; preferences.theme = normalizeTheme(preferences.theme || {mode:preferences.mode}); preferences.mode = preferences.theme.mode;
        const validFolderIds=new Set(state.meta.folders.map(folder=>String(folder.id)));
        collapsedFolderIds=new Set((preferences.collapsedFolders||[]).map(String).filter(id=>validFolderIds.has(id)));
        preferences.collapsedFolders=[...collapsedFolderIds].map(Number);
        const storage = { getItem: () => JSON.stringify(preferences.widths), setItem: (_, value) => { preferences.widths = JSON.parse(value); savePreferences(); } };
        layout = new ColumnLayout($('documentWorkspace'), storage, 'authenticated');
        theme();
    }
    folders();
    if ($('linkFolder')) { const selected = $('linkFolder').value; $('linkFolder').innerHTML = options(state.meta.folders,tr('chooseFolder')); $('linkFolder').value = selected; }
}
function theme() { preferences.theme = normalizeTheme(preferences.theme || {mode:preferences.mode}); preferences.mode = preferences.theme.mode; applyTheme(preferences.theme); rememberTheme(preferences.theme); }
async function savePreferences() {
    preferences.collapsedFolders=[...collapsedFolderIds].map(Number);
    const snapshot=JSON.stringify(preferences);
    preferenceQueue=preferenceQueue.catch(()=>{}).then(()=>api('preferences',{preferences:snapshot},true));
    try { await preferenceQueue; }
    catch (problem) { error(new Error('Anzeigeeinstellungen nicht gespeichert: ' + problem.message)); }
}
function folders() {
    const linked = new Set((state.document?.folders || []).map(String));
    const button = (scope, name, real = false, depth = 0, hasChildren = false, isCollapsed = false, hidden = false) => {
        const count = scope === 'inbox' ? state.filteredInboxCount ?? state.meta.inboxCount ?? 0 : scope === 'unfiled' ? state.filteredUnfiledCount ?? state.meta.unfiledCount ?? 0 : scope === 'all' ? state.filteredAllCount ?? state.meta.allCount ?? 0 : scope === 'trash' ? state.filteredTrashCount ?? state.meta.trashCount ?? 0 : state.filteredFolderCounts?.[scope] ?? state.meta.folderCounts?.[scope] ?? 0;
        const rowHidden=hidden?' hidden':'';
        const toggle=hasChildren?'<button type="button" class="folder-toggle" data-folder-toggle="'+scope+'" aria-expanded="'+(!isCollapsed)+'" aria-label="'+esc(tr(isCollapsed?'expandFolder':'collapseFolder',{name}))+'" title="'+esc(tr(isCollapsed?'expandFolder':'collapseFolder',{name}))+'">'+(isCollapsed?'›':'⌄')+'</button>':'<span class="folder-toggle-placeholder" aria-hidden="true"></span>';
        return '<div class="folder-nav-row" style="--folder-depth:' + depth + '"'+rowHidden+'>'+toggle+'<button class="folder-nav-item ' + (String(state.scope) === String(scope) ? 'active ' : '') + (linked.has(String(scope)) ? 'has-selected-document' : '') + '" data-scope="' + scope + '"' + (real ? ' data-drop-folder="' + scope + '"' : '') + '><svg class="icon small-icon" aria-hidden="true"><use href="#i-' + (scope === 'inbox' ? 'inbox' : scope === 'trash' ? 'trash' : scope === 'all' ? 'document' : 'folder') + '"/></svg><span>' + esc(name) + '</span>' + (real || scope === 'unfiled' || scope === 'inbox' || scope === 'trash' || scope === 'all' ? '<span class="count" aria-label="' + esc(tr('folderCount',{count})) + '">' + count + '</span>' : '') + '</button>' + (real ? '<button class="btn btn-sm icon-btn folder-manage" data-edit-folder="' + scope + '" aria-label="' + esc(tr('editFolder',{name})) + '" title="' + esc(tr('editFolderTitle')) + '"><svg class="icon small-icon" aria-hidden="true"><use href="#i-settings"/></svg></button>' : scope === 'trash' ? '<button class="btn btn-sm icon-btn folder-manage" data-empty-trash aria-label="' + esc(tr('emptyTrash')) + '" title="' + esc(tr('emptyTrash')) + '"><svg class="icon small-icon" aria-hidden="true"><use href="#i-settings"/></svg></button>' : '') + '</div>';
    };
    const ordered = []; const byParent = new Map();
    for (const folder of state.meta.folders) { const key = folder.parent_id == null ? 'root' : String(folder.parent_id); if (!byParent.has(key)) byParent.set(key, []); byParent.get(key).push(folder); }
    const visit = (parent, depth, seen = new Set()) => { for (const folder of (byParent.get(parent) || [])) { if (seen.has(String(folder.id))) continue; seen.add(String(folder.id)); ordered.push({folder,depth}); visit(String(folder.id), depth + 1, seen); } };
    visit('root', 0);
    for (const folder of state.meta.folders) if (!ordered.some(x => Number(x.folder.id) === Number(folder.id))) ordered.push({folder,depth:0});
    const hiddenById=new Map();
    const folderRows=ordered.map(({folder,depth})=>{
        const id=String(folder.id), parent=folder.parent_id===null?null:String(folder.parent_id);
        const hidden=parent!==null && (hiddenById.get(parent)===true || collapsedFolderIds.has(parent));
        hiddenById.set(id,hidden);
        return button(id,folder.name,true,depth,(byParent.get(id)||[]).length>0,collapsedFolderIds.has(id),hidden);
    }).join('');
    $('folderNavigationDesktop').innerHTML = button('inbox',tr('scopeInbox')) + button('all',tr('scopeAll')) + button('unfiled',tr('scopeUnfiled')) + '<div class="folder-nav-label">' + tr('foldersHeading') + '</div>' + folderRows + '<div class="folder-nav-label">' + tr('manageHeading') + '</div>' + button('trash',tr('scopeTrash'));
}
function clearDocument() {
    state.document = null; state.dirty = false; ++state.documentSequence;
    $('documentId').textContent = ''; $('documentDetails').textContent = tr('selectDocument');
    $('documentPreview').textContent = tr('selectDocument'); $('downloadFile').hidden = true; folders();
}
function hasActiveSearch(input = Object.fromEntries(new FormData($('searchForm')))) {
    return Boolean(input.query?.trim() || ['dateFrom','dateTo','amountFrom','amountTo','invoiceNumbers','accountCode','documentType','tag','notTag','owner','includeExpired','includeNotSearchable'].some(key => input[key]) || input.resultView === 'latest');
}
function syncSearchScopeToggle() {
    const local = state.searchMode === 'local';
    const button = $('searchScopeToggle');
    button.textContent = local ? searchTr('localShort') : searchTr('global');
    button.setAttribute('aria-label', searchTr('scope',{scope:local?searchTr('local'):searchTr('global')}));
    button.setAttribute('aria-pressed', local ? 'true' : 'false');
}
async function search() {
    hideTagsTooltip();
    const sequence = ++state.querySequence;
    const preferred = state.document?.id; const preferredInbound=Boolean(state.document?._inbound);
    state.selected.clear(); state.inboundSelected.clear(); state.rows=[]; syncSelection(); clearDocument(); $('documentList').textContent = tr('searching');
    const input = Object.fromEntries(new FormData($('searchForm')));
    const filtered = hasActiveSearch(input);
    try {
        const showInbound=state.scope==='inbox' && !['dateFrom','dateTo','amountFrom','amountTo','invoiceNumbers','accountCode','documentType','tag','notTag','owner'].some(key=>input[key]) && input.resultView!=='latest';
        const [result,inbound]=await Promise.all([api('list', {...input, scope: state.scope, page: state.page, folderCounts: filtered ? '1' : ''}),showInbound?api('inboundItems',{query:input.query || ''}):Promise.resolve([])]);
        if (sequence !== state.querySequence) return;
        state.page = result.page;
        state.filteredFolderCounts = null;
        state.filteredInboxCount = null;
        state.filteredAllCount = null;
        state.filteredTrashCount = null;
        state.filteredUnfiledCount = null;
        if (filtered && state.scope === 'all') {
            state.filteredFolderCounts = result.folderCounts;
            state.filteredInboxCount = result.inboxCount;
            state.filteredTrashCount = result.trashCount;
            state.filteredUnfiledCount = result.unfiledCount;
            state.filteredAllCount = Math.max(0, Number(result.total) - Number(result.trashCount || 0));
        } else if (filtered && state.searchMode === 'local') {
            if (/^\d+$/.test(String(state.scope))) state.filteredFolderCounts = {[state.scope]: Number(result.total)};
            else if (state.scope === 'inbox') state.filteredInboxCount = Number(result.total)+inbound.length;
            else if (state.scope === 'trash') state.filteredTrashCount = Number(result.total);
            else if (state.scope === 'unfiled') state.filteredUnfiledCount = Number(result.total);
        }
        const displayedTotal=Number(result.total)+inbound.length;
        $('resultCount').textContent = result.latestLimit === null
            ? window.o8Translate?.(`workspace.${state.scope==='inbox'?'inboxFound':'documentsFound'}`,{count:displayedTotal})
            : tr('latestResults',{count:result.total,total:result.matchingTotal});
        $('resultsPage').textContent = tr('page',{page:result.page,pages:result.pages});
        $('previousResults').disabled = result.page <= 1; $('nextResults').disabled = result.page >= result.pages;
        const inboundRows=(state.page===1?inbound:[]).map(row=>({...row,_inbound:true})); state.rows=[...inboundRows,...result.rows];
        const inboundHtml=inboundRows.map(item=>'<article class="doc-row inbound-row" data-inbound-row="'+item.id+'" data-drag-inbound="'+item.id+'"><label class="doc-check"><input class="form-check-input" type="checkbox" data-inbound-select="'+item.id+'" aria-label="'+esc(tr('selectItem',{name:item.original_name}))+'"></label><button class="doc-open" data-inbound="'+item.id+'"><span class="doc-title">'+esc(item.original_name)+'</span><span class="doc-second-line"><span class="doc-sender">'+esc(item.source_name)+' · '+esc(item.owner_name)+'</span></span><span class="doc-meta"><time datetime="'+esc(item.discovered_at)+'">'+esc(dateLabel(String(item.discovered_at).slice(0,10)))+'</time><span>E'+item.id+'</span><span class="tag">'+esc(aiStatusLabel(item.ai_status))+'</span></span></button></article>').join('');
        const documentHtml=result.rows.map(doc => '<article class="doc-row" data-drag-document="' + doc.id + '"><label class="doc-check"><input class="form-check-input" type="checkbox" data-select="' + doc.id + '" aria-label="' + esc(tr('selectItem',{name:doc.title})) + '"></label><button class="doc-open" data-document="' + doc.id + '"><span class="doc-title">' + esc(doc.title) + '</span><span class="doc-second-line"><span class="doc-sender">' + esc(doc.sender || tr('noSender')) + '</span>' + (doc.gross_amount !== null && doc.gross_amount !== undefined ? '<span class="doc-gross">' + esc(grossLabel(doc)) + '</span>' : '') + '</span><span class="doc-meta"><time datetime="' + esc(doc.document_date || '') + '">' + esc(dateLabel(doc.document_date)) + '</time><span>D' + doc.id + '</span><span class="doc-meta-count doc-folder-count" data-folder-ids="' + esc(JSON.stringify(doc.folder_ids || [])) + '" aria-describedby="docTagsTooltip" aria-label="' + esc(tr('folderCount',{count:Number(doc.folder_count || 0)})) + '"><svg class="icon small-icon" aria-hidden="true"><use href="#i-link"/></svg> ' + Number(doc.folder_count || 0) + '</span>' + (doc.tag_names?.length ? '<span class="doc-meta-count doc-tag-count" data-tag-names="' + esc(JSON.stringify(doc.tag_names)) + '" aria-label="' + doc.tag_names.length + ' Tags" aria-describedby="docTagsTooltip"><svg class="icon small-icon" aria-hidden="true"><use href="#i-tag"/></svg> ' + doc.tag_names.length + '</span>' : '') + '</span></button></article>').join('');
        $('documentList').innerHTML = inboundHtml+documentHtml || '<div class="empty-state">'+tr('emptyResults')+'</div>';
        syncSelection();
        if (preferred && preferredInbound && inboundRows.some(x=>Number(x.id)===Number(preferred))) await selectInbound(preferred,true);
        else if (preferred && !preferredInbound && result.rows.some(x => Number(x.id) === Number(preferred))) await select(preferred, true);
        else { clearDocument(); if (inboundRows.length) await selectInbound(inboundRows[0].id,true); else if (result.rows.length) await select(result.rows[0].id, true); }
        if (sequence !== state.querySequence) return;
        folders(); $('liveError').hidden = true;
    } catch (problem) { if (sequence === state.querySequence) error(problem); }
}
async function select(id, force = false) {
    if (!force && !(await discard())) return;
    clearDocument(); $('documentDetails').textContent = tr('documentLoading');
    const sequence = ++state.documentSequence;
    try {
        const doc = await api('get', {id});
        if (sequence !== state.documentSequence) return;
        state.document = doc; state.dirty = false;
        $('documentId').textContent = 'D' + doc.id;
        for (const row of $('documentList').querySelectorAll('article')) row.classList.toggle('is-active', Number(row.dataset.dragDocument) === Number(id));
        const preview = $('documentPreview'); preview.replaceChildren();
        const fileUrl = url('file', {id});
        if (['application/pdf','image/jpeg','image/png'].includes(doc.mime_type)) {
            const element = document.createElement(doc.mime_type === 'application/pdf' ? 'iframe' : 'img');
            if (element.tagName === 'IFRAME') { element.title = window.o8TranslateSource?.('PDF-Originalvorschau') || 'PDF-Originalvorschau'; }
            else element.alt = doc.title;
            element.src = fileUrl; preview.append(element);
        } else preview.textContent = tr('previewUnsupported');
        const hint = document.createElement('p'); hint.className = 'small'; hint.textContent = tr('previewHint'); preview.append(hint);
        $('downloadFile').href = url('file', {id,download:1}); $('downloadFile').hidden = false;
        details(); folders();
    } catch (problem) { error(problem); }
}
async function selectInbound(id,force=false) {
    if (!force && !(await discard())) return; clearDocument(); $('documentDetails').textContent=tr('inboundLoading'); const sequence=++state.documentSequence;
    try {
        const item=await api('inboundGet',{id}); if (sequence!==state.documentSequence) return; item._inbound=true; state.document=item; state.dirty=false; $('documentId').textContent='E'+item.id;
        for (const row of $('documentList').querySelectorAll('article')) row.classList.toggle('is-active',Number(row.dataset.inboundRow)===Number(id));
        const preview=$('documentPreview'); preview.replaceChildren(); const fileUrl=url('inboundFile',{id});
        if (['application/pdf','image/jpeg','image/png'].includes(item.mime_type)) { const element=document.createElement(item.mime_type==='application/pdf'?'iframe':'img'); if (element.tagName==='IFRAME') element.title=window.o8TranslateSource?.('Eingangsdatei-Vorschau') || 'Eingangsdatei-Vorschau'; else element.alt=item.original_name; element.src=fileUrl; preview.append(element); }
        else preview.textContent=tr('previewUnsupported');
        $('downloadFile').href=url('inboundFile',{id,download:1}); $('downloadFile').hidden=false;
        let json=item.json_text; if (json) try { json=JSON.stringify(JSON.parse(json),null,2); } catch { /* Ungültiges JSON bleibt als Rohtext sichtbar. */ }
        const clipped=(value,limit=100000)=>value&&value.length>limit?value.slice(0,limit)+'\n… '+tr('previewClipped')+' …':value;
        const history=(item.acceptance_status==='blocked'?'<div class="alert alert-warning small" role="status"><strong>Automatische Übernahme: Prüfung nötig</strong><br>'+esc(item.acceptance_message)+'</div>':'')+(Array.isArray(item.ai_history)&&item.ai_history.length?'<section class="inbound-ai-history o8-info-group o8-info-group--soft"><span class="detail-label">KI-VERLAUF</span><ol>'+item.ai_history.map(run=>'<li><strong>'+esc(aiStatusLabel(run.status))+'</strong><span>'+esc(dateLabel(String(run.created_at).slice(0,10)))+' · '+esc(run.requested_by_name)+(run.error_code?' · '+esc(aiErrorLabel(run.error_code)):'')+'</span></li>').join('')+'</ol></section>':'<section class="o8-info-group o8-info-group--soft"><span class="detail-label">KI-VERLAUF</span><p class="live-detail-empty">Noch kein KI-Lauf vorhanden.</p></section>');
        const aiTags=json!==null?'<section class="live-detail-tags o8-info-group o8-info-group--soft"><span class="detail-label">KI-TAGVORSCHLÄGE</span><div class="tag-list">'+(item.ai_tag_matches.length?item.ai_tag_matches.map(tag=>'<span class="tag">'+esc(tag.name)+'</span>').join(''):'<span class="live-detail-empty">Keine vorhandenen Tags erkannt</span>')+'</div>'+(item.ai_tag_ignored.length?'<p class="small text-body-secondary mt-1 mb-0">Ignoriert (nicht im aktiven Katalog): '+esc(item.ai_tag_ignored.join(', '))+'</p>':'')+'</section>':'';
        $('documentDetails').innerHTML='<section class="live-detail-intro"><div class="live-detail-status"><span class="tag">'+esc(item.source_kind.toUpperCase())+'</span><span class="tag">'+esc(({pending:'Bereit',duplicate:'Duplikat',invalid:'Ungültig'})[item.inventory_status]||item.inventory_status)+'</span><span class="tag">'+esc(aiStatusLabel(item.ai_status))+'</span></div><h2>'+esc(item.original_name)+'</h2><p>'+esc(item.source_name)+'</p></section><div class="live-detail-facts"><div><span class="detail-label">BESITZER</span><span class="detail-value">'+esc(item.owner_name)+'</span></div><div><span class="detail-label">GEFUNDEN</span><span class="detail-value">'+esc(dateLabel(String(item.discovered_at).slice(0,10)))+'</span></div><div><span class="detail-label">DATEITYP</span><span class="detail-value">'+esc(item.mime_type)+'</span></div><div><span class="detail-label">GRÖSSE</span><span class="detail-value">'+Math.ceil(Number(item.size_bytes)/1024)+' KiB</span></div></div>'+(json!==null?'<section class="inbound-sidecar"><span class="detail-label">KI-/JSON-DATEN</span><pre>'+esc(clipped(json))+'</pre></section>':'<p class="live-detail-empty">Keine JSON-Daten vorhanden.</p>')+aiTags+(item.text_content!==null?'<section class="inbound-sidecar"><span class="detail-label">OCR-/TEXTDATEN</span><pre>'+esc(clipped(item.text_content))+'</pre></section>':'')+history+'<div class="live-detail-actions"><button class="btn btn-primary" type="button" id="runInboundAi" '+(!state.meta.aiReady?'disabled':'')+'>'+(item.active_ai_job_id?'KI-Verarbeitung fortsetzen …':item.ai_status==='ready'||item.ai_status==='failed'?'KI erneut ausführen …':'Mit KI analysieren …')+'</button><button class="btn btn-outline-danger" type="button" id="deleteInboundItem">Aus Eingang löschen …</button></div>';
        const detailRoot=$('documentDetails');
        if (json!==null) {
            const section=detailRoot.querySelector('.inbound-sidecar');
            const disclosure=document.createElement('details'); disclosure.className='inbound-sidecar';
            const summary=document.createElement('summary'); summary.textContent='KI-/JSON-Daten anzeigen';
            disclosure.append(summary,section.querySelector('pre')); section.replaceWith(disclosure);
        }
        const inboundHead=document.createElement('section'); inboundHead.className='o8-info-group o8-info-group--head inbound-detail-head';
        detailRoot.querySelector('.live-detail-intro').before(inboundHead);
        inboundHead.append(detailRoot.querySelector('.live-detail-intro'),detailRoot.querySelector('.live-detail-facts'));
        detailRoot.querySelectorAll('.inbound-sidecar').forEach((section,index)=>section.classList.add('o8-info-group',index?'o8-info-group--soft':'o8-info-group--strong'));
        detailRoot.querySelector('.live-detail-actions').classList.add('o8-info-group','o8-info-group--soft');
        const accept=document.createElement('button'); accept.type='button'; accept.className='btn btn-primary'; accept.id='acceptInboundItem'; accept.textContent='In das DMS übernehmen …'; accept.disabled=Boolean(item.active_ai_job_id)||['queued','running'].includes(item.ai_status);
        accept.addEventListener('click',()=>acceptanceDialog.open(item.id).catch(error)); $('documentDetails').querySelector('.live-detail-actions').prepend(accept);
        $('deleteInboundItem').addEventListener('click',()=>deleteInbound([{id:Number(item.id),revision:Number(item.revision)}]));
        $('runInboundAi').addEventListener('click',()=>item.active_ai_job_id?runInboundAiJob(Number(item.active_ai_job_id)):startInboundAi([{id:Number(item.id),revision:Number(item.revision)}])); folders();
        const booking=document.createElement('div'); booking.className='inbound-booking-overview o8-info-group o8-info-group--strong'; booking.textContent='Buchungsübersicht wird geladen …';
        inboundHead.after(booking);
        try {
            const proposal=await api('inboundProposal',{id});
            if(sequence!==state.documentSequence)return;
            if(Number(proposal.revision)!==Number(item.revision)) {booking.textContent='Eingangselement wurde geändert. Bitte erneut auswählen.';return;}
            booking.innerHTML=bookingSummary(proposal,{accounts:state.meta.accounting.accounts});
        } catch(problem) {if(sequence===state.documentSequence)booking.textContent='Buchungsübersicht nicht verfügbar: '+problem.message;}
    } catch (problem) { error(problem); }
}
function details() {
    const d = state.document;
    const field = (name, label, value, type = 'text') => '<label>' + label + '<input class="form-control" name="' + name + '" type="' + type + '" value="' + esc(value) + '" ' + (name === 'title' ? 'required ' : '') + 'maxlength="255"></label>';
    const owner = state.meta.users.find(user => Number(user.id) === Number(d.owner_id))?.display_name || (root.dataset.admin === '1' ? 'Nicht verfügbar' : root.dataset.userName);
    const source = ({upload:'Upload',demo:'Demo'})[d.source_type] || d.source_type || 'Unbekannt';
    const type = ({document:'Dokument',invoice:'Rechnung',credit_note:'Gutschrift',contract:'Vertrag',certificate:'Bescheinigung'})[d.document_type] || d.document_type || 'Dokument';
    const documentTags = state.meta.tags.filter(tag => d.tags.includes(Number(tag.id))).map(tag => tag.name);
    const fact = (label, value) => '<div><span class="detail-label">' + label + '</span><span class="detail-value">' + esc(value || '–') + '</span></div>';
    const tagView = '<section class="live-detail-tags o8-info-group o8-info-group--soft"><span class="detail-label">TAGS</span><div class="tag-list">' + (documentTags.length ? documentTags.map(name => '<span class="tag">' + esc(name) + '</span>').join('') : '<span class="live-detail-empty">Keine Tags</span>') + '</div></section>';
    const invoice = '<section class="live-invoice-summary"><div><span class="detail-label">BUCHUNGSDATEN</span><strong>' + (d.invoice ? (d.invoice.gross!==null ? esc(new Intl.NumberFormat('de-DE',{style:'currency',currency:d.invoice.currency,currencyDisplay:'code'}).format(Number(d.invoice.gross))) : 'Betrag noch unvollständig') + (d.invoice.mode==='partial'?' · unvollständig':'') : 'Noch keine Buchungsdaten') + '</strong></div><button class="btn btn-sm btn-surface" type="button" data-invoice>Buchungsdaten ' + (d.invoice ? 'bearbeiten' : 'erfassen') + '</button></section>';
    const actions = d.deleted_at ? '<button class="btn btn-primary btn-sm" data-status="restore">Wiederherstellen</button>' :
        '<button class="btn btn-surface btn-sm" type="button" id="documentEditToggle" aria-controls="documentEditor" aria-expanded="false"><svg class="icon small-icon" aria-hidden="true"><use href="#i-settings"/></svg> Bearbeiten</button>' + (Number(d.in_inbox) ? '<button class="btn btn-surface btn-sm" data-status="accept">Übernehmen</button>' : '') + '<button class="btn btn-outline-danger btn-sm" data-status="trash">Papierkorb</button>';
    const editor = d.deleted_at ? '' :
        '<section class="live-detail-disclosure live-detail-editor" id="documentEditor" hidden><form id="documentForm"><div class="live-detail-edit-grid">' + field('title','Titel',d.title) + field('sender','Absender',d.sender) + field('reference','Referenz',d.reference) + field('date','Dokumentdatum',d.document_date,'date') + '</div>' +
        '<label>Notiz<textarea class="form-control" name="memo" maxlength="10000" rows="3">' + esc(d.memo) + '</textarea></label><div class="live-detail-flags"><label><input type="checkbox" name="expired" value="1" ' + (Number(d.expired) ? 'checked' : '') + '> Abgelaufen</label><label><input type="checkbox" name="notSearchable" value="1" ' + (!Number(d.searchable) ? 'checked' : '') + '> Nicht suchbar</label></div><p>Tags</p><div id="liveTagPicker"></div><div><button class="btn btn-primary btn-sm mt-2">Speichern' + (Number(d.in_inbox) ? ' & übernehmen' : '') + '</button></div></form><div class="live-detail-link-editor"><label class="d-block">In Ordner verlinken<select id="linkFolder" class="form-select">' + options(state.meta.folders,'Ordner wählen') + '</select></label><button class="btn btn-surface btn-sm mt-2" id="linkButton">Verlinken …</button></div></section>';
    const foldersView = '<section class="live-detail-folders o8-info-group"><h3><svg class="icon small-icon" aria-hidden="true"><use href="#i-folder"/></svg> Ordnerverknüpfungen <span class="count">' + d.folders.length + '</span></h3><div class="live-detail-folder-list">' + d.folders.map(id => { const folder = state.meta.folders.find(x => Number(x.id) === id); return folder ? '<div class="folder-chip"><span>' + esc(folderPath(folder,'/')) + '</span>' + (!d.deleted_at ? '<button type="button" data-unlink="' + id + '" aria-label="Verknüpfung entfernen">×</button>' : '') + '</div>' : ''; }).join('') + '</div></section>';
    $('documentDetails').innerHTML = '<section class="live-detail-head o8-info-group o8-info-group--head"><div class="live-detail-intro"><div class="live-detail-top"><div class="live-detail-status"><span class="tag">' + esc(type) + '</span>' + (Number(d.expired) ? '<span class="tag">Abgelaufen</span>' : '') + (!Number(d.searchable) ? '<span class="tag">Nicht suchbar</span>' : '') + '</div><div class="live-detail-actions">' + actions + '</div></div><h2>' + esc(d.title) + '</h2><p>' + esc(d.sender || 'Ohne Absender') + '</p><span class="detail-label">' + esc(d.original_name) + ' · ' + Math.ceil(Number(d.size_bytes)/1024) + ' KiB</span></div>' +
        '<div class="live-detail-facts">' + fact('Dokumentdatum',dateLabel(d.document_date)) + fact('Quelle',source) + fact('Referenz',d.reference) + fact('Besitzer',owner) + '</div></section>' +
        editor + tagView + foldersView + '<section class="live-detail-booking o8-info-group o8-info-group--strong">' + invoice + savedBookingSummary(d,state.meta.accounting.accounts) + '</section>';
    if (!d.deleted_at) {
        const selected = state.meta.tags.filter(x => d.tags.includes(Number(x.id))).map(x => x.name);
        picker = new TagPicker($('liveTagPicker'),state.meta.tags.map(x => x.name),selected,() => { state.dirty = true; },'Tags suchen und auswählen',true);
        $('documentForm').addEventListener('input',() => { state.dirty = true; });
        $('documentForm').addEventListener('submit',async event => {
            event.preventDefault();
            const newTags=await confirmNewTagSelection(picker,confirmDialog);
            const values = Object.fromEntries(new FormData(event.target));
            values.tags = JSON.stringify(state.meta.tags.filter(x => picker.values().includes(x.name)).map(x => Number(x.id)));
            values.newTags=JSON.stringify(newTags);
            if (await write('save',{...values,id:d.id,revision:d.revision},'Dokument gespeichert und in die Ablage übernommen.')) { state.dirty = false; await metadata(); await search(); }
        });
    }
}
async function link(folder, remove = false, id = state.document?.id, confirmed = false) {
    if (!id || !folder || (confirmed && (writing || state.dirty)) || (!confirmed && !(await discard()))) return;
    if (!confirmed && !(await confirmDialog(remove ? 'Verknüpfung entfernen?' : 'Dokument verknüpfen?', remove ? 'Diese Ordnerverknüpfung entfernen? Das Dokument bleibt erhalten.' : 'Dokument in diesem Ordner verlinken? Bestehende Verknüpfungen bleiben erhalten; ein Eingangsdokument wird übernommen.', remove ? 'Entfernen' : 'Verknüpfen', remove))) return;
    try {
        const doc = Number(id) === Number(state.document?.id) ? state.document : await api('get',{id});
        if (await write('link',{id,revision:doc.revision,folder,remove:remove?1:0},remove?'Verknüpfung entfernt.':'Dokument verlinkt.')) { state.dirty = false; await metadata(); await search(); }
    } catch (problem) { error(problem); }
}
$('folderNavigationDesktop').addEventListener('click',async event => {
    const toggle=event.target.closest('[data-folder-toggle]');
    if (toggle) {
        event.preventDefault(); event.stopPropagation();
        const id=String(toggle.dataset.folderToggle);
        if (collapsedFolderIds.has(id)) collapsedFolderIds.delete(id); else collapsedFolderIds.add(id);
        folders(); void savePreferences(); return;
    }
    const button = event.target.closest('[data-scope]');
    if (button && await discard()) {
        state.dirty = false; state.localScope = button.dataset.scope; state.page = 1;
        state.scope = hasActiveSearch() && state.searchMode === 'global' ? 'all' : state.localScope;
        clearDocument(); await search();
    }
    const edit = event.target.closest('[data-edit-folder]');
    if (edit) openFolder(state.meta.folders.find(x => Number(x.id) === Number(edit.dataset.editFolder)));
    if (event.target.closest('[data-empty-trash]')) emptyTrash();
});
async function emptyTrash() {
    if (!(await discard())) return;
    try {
        const snapshot=await api('trashPurgeInfo');
        const total=Number(snapshot.count);
        if (!total) { notice('Der Papierkorb ist leer.'); return; }
        const scope=root.dataset.admin==='1'?'alle Dokumente dieses Mandanten':'alle Ihre Dokumente';
        if (!(await confirmDialog('Papierkorb vollständig leeren?',`${total} Dokument(e) im Papierkorb endgültig löschen? Betroffen sind ${scope}. Dateien und Buchungsdaten können danach nicht wiederhergestellt werden.`,'Papierkorb endgültig leeren',true))) return;
        const modal=bootstrap.Modal.getOrCreateInstance($('trashPurgeModal'));
        const progress=$('trashPurgeProgress'), status=$('trashPurgeStatus'), failure=$('trashPurgeError'), close=$('trashPurgeClose');
        progress.max=total; progress.value=0; status.textContent=`0 von ${total} Dokumenten gelöscht …`; failure.hidden=true; close.disabled=true;
        modal.show(); writing=true; suppressWaiting=true; clearTimeout(waitTimer); $('searchWaiting').hidden=true; let removed=0, processed=0;
        try {
            for (const item of snapshot.items) {
                const step=await api('trashPurge',{confirm:'yes',id:String(item.id),revision:String(item.revision)},true);
                removed+=Number(step.removed); ++processed; progress.value=processed;
                status.textContent=`${processed} von ${total} Dokumenten verarbeitet · ${removed} gelöscht …`;
            }
            status.textContent=`${removed} Dokument(e) endgültig gelöscht.`;
            notice(`${removed} Dokument(e) aus dem Papierkorb endgültig gelöscht.`);
        } catch (problem) {
            failure.textContent=`Nach ${removed} Dokument(en) angehalten: ${problem.message}`; failure.hidden=false;
        } finally {
            writing=false; close.disabled=false; state.dirty=false;
            try { await metadata(); await search(); } catch (problem) { error(problem); }
            suppressWaiting=false;
        }
    } catch (problem) { error(problem); }
}
let suppressDocumentClick=false;
$('documentList').addEventListener('click',event => {
    if (suppressDocumentClick) { event.preventDefault(); event.stopPropagation(); return; }
    const inboundChoice=event.target.closest('[data-inbound-select]');
    if (inboundChoice) { event.stopPropagation(); const id=Number(inboundChoice.dataset.inboundSelect); inboundChoice.checked?state.inboundSelected.add(id):state.inboundSelected.delete(id); syncSelection(); return; }
    const choice=event.target.closest('[data-select]');
    if (choice) { event.stopPropagation(); const id=Number(choice.dataset.select); choice.checked ? state.selected.add(id) : state.selected.delete(id); syncSelection(); return; }
    const inbound=event.target.closest('[data-inbound]'); if (inbound) { selectInbound(inbound.dataset.inbound); return; }
    const button = event.target.closest('[data-document]'); if (button) select(button.dataset.document);
});
const tagsTooltip=$('docTagsTooltip');
function showTagsTooltip(target) {
    if (!target) return;
    const isFolder=target.classList.contains('doc-folder-count');
    const names=isFolder
        ? JSON.parse(target.dataset.folderIds || '[]').map(id => state.meta.folders.find(folder => Number(folder.id)===Number(id))).filter(Boolean).map(folder => folderPath(folder))
        : JSON.parse(target.dataset.tagNames || '[]');
    if (!names.length) { hideTagsTooltip(); return; }
    tagsTooltip.replaceChildren();
    const caption=document.createElement('strong'); caption.textContent=isFolder?'Ordnerverknüpfungen':'Tags'; tagsTooltip.append(caption);
    const list=document.createElement('div'); list.className='doc-tags-tooltip-list';
    for (const name of names) { const tag=document.createElement('span'); tag.textContent=name; list.append(tag); }
    tagsTooltip.append(list); tagsTooltip.hidden=false;
    const rect=target.getBoundingClientRect(); const width=tagsTooltip.getBoundingClientRect().width;
    tagsTooltip.style.left=`${Math.max(8,Math.min(rect.left,innerWidth-width-8))}px`;
    tagsTooltip.style.top=`${Math.min(rect.bottom+6,innerHeight-tagsTooltip.getBoundingClientRect().height-8)}px`;
}
function hideTagsTooltip() { tagsTooltip.hidden=true; }
$('documentList').addEventListener('mouseover',event => { const target=event.target.closest('.doc-tag-count, .doc-folder-count'); if (target && !target.contains(event.relatedTarget)) showTagsTooltip(target); });
$('documentList').addEventListener('mouseout',event => { const target=event.target.closest('.doc-tag-count, .doc-folder-count'); if (target && !target.contains(event.relatedTarget)) hideTagsTooltip(); });
$('documentList').addEventListener('scroll',hideTagsTooltip);
$('selectAll').addEventListener('change',event => { state.selected = event.target.checked ? new Set(state.rows.filter(row=>!row._inbound).map(row => Number(row.id))) : new Set(); state.inboundSelected=event.target.checked?new Set(state.rows.filter(row=>row._inbound).map(row=>Number(row.id))):new Set(); syncSelection(); });
function folderPath(folder, separator = ' / ') {
    const parts=[folder.name], seen=new Set([Number(folder.id)]); let parent=folder.parent_id;
    while (parent!==null && !seen.has(Number(parent))) { const row=state.meta.folders.find(item => Number(item.id)===Number(parent)); if (!row) break; parts.unshift(row.name); seen.add(Number(row.id)); parent=row.parent_id; }
    return parts.join(separator);
}
function bulkFields() {
    const needsFolder=['add','remove'].includes($('bulkFolderAction').value);
    $('bulkFolderTargetWrap').hidden=!needsFolder; $('bulkFolderTarget').disabled=!needsFolder;
    const needsTags=$('bulkTagAction').value!=='keep';
    $('bulkTagPickerWrap').hidden=!needsTags;
    $('bulkTagReplaceHint').hidden=$('bulkTagAction').value!=='set';
}
async function openBulkEditor() {
    if ((!state.selected.size && !state.inboundSelected.size) || !(await discard())) return;
    if (state.inboundSelected.size) {
        if (state.selected.size) { error(new Error('DMS-Dokumente und noch nicht übernommene Eingangselemente bitte getrennt auswählen.')); return; }
        const revisions=new Map(state.rows.filter(row=>row._inbound).map(row=>[Number(row.id),Number(row.revision)]));
        inboundActionItems=[...state.inboundSelected].map(id=>({id,revision:revisions.get(id)}));
        $('inboundActionCount').textContent=`${inboundActionItems.length} Eingangselement(e) ausgewählt.`; $('inboundAiStartSelected').disabled=!state.meta.aiReady; $('inboundActionError').textContent=state.meta.aiReady?'':'Externe KI ist noch nicht durch einen Mandanten-Admin eingerichtet.'; bootstrap.Modal.getOrCreateInstance($('inboundActionModal')).show(); return;
    }
    state.dirty=false; const ids=[...state.selected]; const inTrash=state.rows.filter(row => state.selected.has(Number(row.id))).every(row => state.scope==='trash');
    $('bulkEditCount').textContent=`${ids.length} ausgewählte Dokumente dieser Ergebnisseite`;
    $('bulkEditIds').textContent=ids.map(id => `D${id}`).join(', ');
    $('bulkEditError').textContent=''; $('bulkFolderAction').value='keep'; $('bulkTagAction').value='keep';
    $('bulkFolderTarget').innerHTML='<option value="">Bitte Ordner wählen …</option>'+state.meta.folders.map(folder => '<option value="'+folder.id+'">'+esc(folderPath(folder))+'</option>').join('');
    const currentFolder=/^\d+$/.test(String(state.scope)) ? state.meta.folders.find(folder => Number(folder.id)===Number(state.scope)) : null;
    $('bulkRemoveCurrentFolder').hidden=!currentFolder; $('bulkRemoveCurrentFolder').dataset.folderId=currentFolder?.id || ''; $('bulkRemoveCurrentFolder').dataset.folderName=currentFolder?folderPath(currentFolder):'';
    if ($('bulkOwner')) $('bulkOwner').innerHTML='<option value="">Besitzer unverändert lassen</option>'+state.meta.users.map(user => '<option value="'+user.id+'">'+esc(user.display_name)+'</option>').join('');
    bulkPicker=new TagPicker($('bulkTagPicker'),state.meta.tags.map(tag => tag.name),[],()=>{},'Tags für Massenänderung suchen');
    $('bulkActiveFields').hidden=inTrash; $('bulkTrashActions').hidden=inTrash; $('bulkRestoreActions').hidden=!inTrash; $('bulkApplyChanges').hidden=inTrash;
    bulkFields(); bootstrap.Modal.getOrCreateInstance($('bulkEditModal')).show();
}
function renderAiJob(job) {
    const done=Number(job.completed_count)+Number(job.failed_count), total=Math.max(1,Number(job.total_count)); $('inboundAiProgress').max=total; $('inboundAiProgress').value=done;
    const queuedSince=Date.parse(String(job.created_at).replace(' ','T')+'Z'), workerLate=job.status==='queued'&&Number.isFinite(queuedSince)&&Date.now()-queuedSince>120000;
    $('inboundAiProgressText').textContent=job.status==='completed'?`${done} von ${job.total_count} erfolgreich analysiert.`:job.status==='failed'?`${job.completed_count} erfolgreich, ${job.failed_count} fehlgeschlagen.`:job.status==='queued'?(workerLate?'KI-Auftrag wartet noch. Bitte den KI-Hintergrundworker prüfen.':`KI-Auftrag wartet auf Verarbeitung · ${done} von ${job.total_count} erledigt …`):`${done} von ${job.total_count} verarbeitet …`;
    $('inboundAiProgressItems').innerHTML=(job.items||[]).map(item=>'<li><span>'+esc(item.original_name)+'</span><strong>'+esc(item.status==='failed'?aiErrorLabel(item.error_code):aiStatusLabel(item.status))+'</strong></li>').join(''); return job.status==='queued'||job.status==='running';
}
async function startInboundAi(items) {
    if (!items.length || !state.meta.aiReady) { error(new Error('Externe KI ist für diesen Mandanten nicht eingerichtet.')); return; }
    if (!(await confirmDialog('Externe KI-Verarbeitung starten?',`${items.length} Eingangselement(e) werden zur Analyse an den konfigurierten externen Dienst übertragen. Vorhandene KI-Vorschläge werden durch den neuen Lauf ersetzt.`,'KI starten'))) return;
    bootstrap.Modal.getInstance($('inboundActionModal'))?.hide(); $('inboundAiProgressText').textContent='KI-Auftrag wird angelegt …'; $('inboundAiProgressItems').textContent=''; $('inboundAiProgress').value=0; $('inboundAiProgress').max=Math.max(1,items.length); bootstrap.Modal.getOrCreateInstance($('inboundAiProgressModal')).show();
    try { const job=await api('inboundAiStart',{items:JSON.stringify(items)},true); await runInboundAiJob(job.id,job); }
    catch (problem) { $('inboundAiProgressText').textContent=problem.message||String(problem); }
}
async function runInboundAiJob(id,initial=null) {
    $('inboundAiProgressText').textContent='KI-Auftrag wird vorbereitet …'; $('inboundAiProgressItems').textContent=''; bootstrap.Modal.getOrCreateInstance($('inboundAiProgressModal')).show();
    try { let job=initial||await api('inboundAiJob',{id}); for (let step=0; renderAiJob(job)&&step<51; step++) job=await api('inboundAiRun',{id},true); renderAiJob(job); if (job.status==='queued'||job.status==='running') throw new Error('Manueller KI-Auftrag konnte nicht vollständig abgeschlossen werden.'); state.inboundSelected.clear(); await metadata(); await search(); if (job.status==='completed') notice(`${job.completed_count} Eingangselement(e) mit KI analysiert.`); }
    catch (problem) { $('inboundAiProgressText').textContent=problem.message||String(problem); }
}
$('inboundAiStartSelected').addEventListener('click',()=>startInboundAi(inboundActionItems));
const inboundBatchButton=document.createElement('button'); inboundBatchButton.type='button'; inboundBatchButton.className='btn btn-primary'; inboundBatchButton.id='inboundAcceptSelected'; inboundBatchButton.textContent='In das DMS übernehmen …';
$('inboundAiStartSelected').parentElement.prepend(inboundBatchButton);
inboundBatchButton.addEventListener('click',()=>{
    $('inboundActionModal').addEventListener('hidden.bs.modal',()=>batchAcceptanceDialog.open(inboundActionItems),{once:true});
    bootstrap.Modal.getInstance($('inboundActionModal')).hide();
});
$('inboundDeleteSelected').addEventListener('click',async()=>{ bootstrap.Modal.getInstance($('inboundActionModal')).hide(); await deleteInbound(inboundActionItems); });
async function deleteInbound(items) {
    if (!items.length || items.some(item=>!Number.isInteger(item.id)||!Number.isInteger(item.revision))) { error(new Error('Eingangsauswahl ist nicht mehr aktuell.')); return; }
    if (!(await confirmDialog('Aus Eingang löschen?',`${items.length} Eingangselement(e) samt verwalteten Nebendateien löschen? Es wird kein DMS-Dokument angelegt.`,'Endgültig löschen',true))) return;
    try { const result=await api('inboundDelete',{items:JSON.stringify(items)},true); state.inboundSelected.clear(); clearDocument(); await metadata(); await search(); notice(`${result.count} Eingangselement(e) gelöscht.`); }
    catch (problem) { error(problem); }
}
async function applyBulk(status='keep',directFolderRemoval=null) {
    const ids=[...state.selected]; const revisions=new Map(state.rows.filter(row=>!row._inbound).map(row => [Number(row.id),Number(row.revision)]));
    if (!ids.length || ids.some(id => !revisions.has(id))) { $('bulkEditError').textContent='Auswahl ist nicht mehr aktuell. Bitte Suche neu laden.'; return; }
    const folderAction=directFolderRemoval?'remove':status==='keep'?$('bulkFolderAction').value:'keep'; const tagAction=directFolderRemoval?'keep':status==='keep'?$('bulkTagAction').value:'keep';
    const folderId=directFolderRemoval?.id || (status==='keep'?$('bulkFolderTarget').value:'');
    const tags=!directFolderRemoval&&status==='keep'?state.meta.tags.filter(tag => bulkPicker.values().includes(tag.name)).map(tag => Number(tag.id)):[];
    const ownerId=!directFolderRemoval&&status==='keep'&&$('bulkOwner')?$('bulkOwner').value:'';
    const description=directFolderRemoval ? `Direkte Verknüpfungen der ${ids.length} ausgewählten Dokumente zu „${directFolderRemoval.name}“ entfernen? Verknüpfungen zu anderen Ordnern bleiben erhalten.` : status==='trash' ? `${ids.length} Dokumente in den Papierkorb legen?` : status==='restore' ? `${ids.length} Dokumente wiederherstellen?` : `${ids.length} Dokumente wie eingestellt ändern?`;
    const confirmLabel=directFolderRemoval?'Verknüpfung entfernen':status==='trash'?'In den Papierkorb':status==='restore'?'Wiederherstellen':'Änderungen übernehmen';
    if (!(await confirmDialog(directFolderRemoval?'Aus aktuellem Ordner entfernen?':'Massenänderung bestätigen',description,confirmLabel,status==='trash'))) return;
    const apply=$('bulkApplyChanges'); apply.disabled=true; $('bulkDeleteSelected').disabled=true; $('bulkRestoreSelected').disabled=true; $('bulkRemoveCurrentFolder').disabled=true; $('bulkEditError').textContent='';
    try {
        const result=await api('bulk',{documents:JSON.stringify(ids.map(id => ({id,revision:revisions.get(id)}))),folderAction,folderId,tagAction,tags:JSON.stringify(tags),ownerId,status},true);
        bootstrap.Modal.getInstance($('bulkEditModal')).hide(); state.selected.clear(); state.dirty=false; await metadata(); await search(); notice(directFolderRemoval ? `${result.count} direkte Ordnerverknüpfung(en) entfernt. Verknüpfungen zu Unterordnern bleiben erhalten.` : `${result.count} Dokument(e) geändert.`);
    } catch (problem) { $('bulkEditError').textContent=problem.message || String(problem); }
    finally { apply.disabled=false; $('bulkDeleteSelected').disabled=false; $('bulkRestoreSelected').disabled=false; $('bulkRemoveCurrentFolder').disabled=false; }
}
$('openBulkEditor').addEventListener('click',openBulkEditor);
$('bulkFolderAction').addEventListener('change',bulkFields); $('bulkTagAction').addEventListener('change',bulkFields);
$('bulkEditForm').addEventListener('submit',event => { event.preventDefault(); applyBulk(); });
$('bulkRemoveCurrentFolder').addEventListener('click',event => { const button=event.currentTarget; if (button.dataset.folderId) applyBulk('keep',{id:button.dataset.folderId,name:button.dataset.folderName}); });
$('bulkDeleteSelected').addEventListener('click',() => applyBulk('trash'));
$('bulkRestoreSelected').addEventListener('click',() => applyBulk('restore'));
const folderNav = $('folderNavigationDesktop');
const clearDropTarget = () => { folderNav.querySelectorAll('.drop-target').forEach(item => item.classList.remove('drop-target')); document.body.classList.remove('dragging-documents'); };
let pointerDrag=null;
let dragCollapsedSnapshot=null, dragExpandedFolder=null;
const folderTreeIds = folderId => {
    const descendants=new Set(), visit=parent=>state.meta.folders.filter(folder=>String(folder.parent_id)===String(parent)).forEach(folder=>{const id=String(folder.id);if(descendants.has(id))return;descendants.add(id);visit(id);});
    visit(folderId); return descendants;
};
const restoreDragFolderExpansion = () => {
    if (!dragCollapsedSnapshot) return;
    collapsedFolderIds=new Set(dragCollapsedSnapshot); dragCollapsedSnapshot=null; dragExpandedFolder=null; folders();
};
const syncDragFolderExpansion = folder => {
    const id=folder?String(folder.dataset.dropFolder):null;
    if (!id || !state.meta.folders.some(item=>String(item.parent_id)===id)) {
        if (dragCollapsedSnapshot) restoreDragFolderExpansion();
        return id?folderNav.querySelector(`[data-drop-folder="${id}"]`):null;
    }
    if (dragExpandedFolder===id) return folderNav.querySelector(`[data-drop-folder="${id}"]`);
    if (!dragCollapsedSnapshot) dragCollapsedSnapshot=new Set(collapsedFolderIds);
    const expanded=new Set(dragCollapsedSnapshot);
    expanded.delete(id);
    for (const descendant of folderTreeIds(id)) expanded.delete(descendant);
    const changed=expanded.size!==collapsedFolderIds.size || [...expanded].some(value=>!collapsedFolderIds.has(value));
    collapsedFolderIds=expanded; dragExpandedFolder=id;
    if (changed) folders();
    return folderNav.querySelector(`[data-drop-folder="${id}"]`);
};
const folderAt = (x,y) => {
    for (const candidate of folderNav.querySelectorAll('[data-drop-folder]')) {
        const rect=candidate.getBoundingClientRect();
        if (x>=rect.left && x<=rect.right && y>=rect.top && y<=rect.bottom) return candidate;
    }
    return null;
};
const highlightDropTarget = folder => {
    folder=syncDragFolderExpansion(folder);
    document.body.classList.add('dragging-documents');
    folderNav.querySelectorAll('.drop-target').forEach(item => item.classList.toggle('drop-target',item===folder));
    const preview=pointerDrag?.preview;
    if (preview) {
        preview.classList.toggle('has-target',Boolean(folder));
        preview.querySelector('.document-drag-preview-copy > span').textContent=folder ? `Ziel: ${folder.querySelector('span')?.textContent || 'Ordner'}` : 'In Ordner ablegen';
    }
};
const createDragPreview = drag => {
    const preview=document.createElement('div'); preview.className='document-drag-preview'; preview.setAttribute('aria-hidden','true');
    const badge=document.createElement('span'); badge.className='document-drag-preview-badge'; badge.textContent=drag.ids.length>1?String(drag.ids.length):'D';
    const copy=document.createElement('span'); copy.className='document-drag-preview-copy';
    const title=document.createElement('strong'); title.textContent=drag.ids.length>1?`${drag.ids.length} Dokumente`:drag.source.querySelector('.doc-title')?.textContent || 'Dokument';
    const caption=document.createElement('span'); caption.textContent='In Ordner ablegen';
    copy.append(title,caption); preview.append(badge,copy); document.body.append(preview); return preview;
};
const positionDragPreview = (preview,x,y) => {
    const rect=preview.getBoundingClientRect();
    const left=Math.max(8,Math.min(x+16,innerWidth-rect.width-8));
    const top=Math.max(8,Math.min(y+14,innerHeight-rect.height-8));
    preview.style.transform=`translate3d(${left}px,${top}px,0)`;
};
const releasePointerDrag = drag => {
    try { drag.source.releasePointerCapture(drag.pointerId); } catch { /* Bereits freigegeben. */ }
    drag.source.classList.remove('is-dragging'); drag.preview?.remove(); restoreDragFolderExpansion(); clearDropTarget();
};
async function handleFolderDrop(ids,targetFolderId,kind='document') {
    ids=[...new Set(ids.map(Number))].filter(id => Number.isInteger(id) && id>0);
    targetFolderId=Number(targetFolderId);
    const targetFolder=state.meta.folders.find(item=>Number(item.id)===targetFolderId);
    if (!targetFolder || !ids.length) return;
    if (writing || state.dirty) { error(new Error('Ungespeicherte Änderungen zuerst speichern oder verwerfen.')); return; }
    if (kind==='inbound') {
        try { await acceptanceDialog.open(ids[0],targetFolderId); }
        catch (problem) { error(problem); }
        return;
    }
    const folderName=targetFolder.name || 'Ordner';
    const sourceFolderId=/^\d+$/.test(String(state.scope)) ? Number(state.scope) : null;
    const sourceFolder=sourceFolderId ? state.meta.folders.find(item => Number(item.id)===sourceFolderId) : null;
    const count=ids.length===1 ? 'Dokument' : `${ids.length} ausgewählte Dokumente`;
    let mode='copy';
    if (sourceFolder && sourceFolderId!==targetFolderId) {
        mode=await choiceDialog('Dokumente ablegen',`${count} nach „${folderName}“ kopieren oder aus „${sourceFolder.name}“ dorthin verschieben? Beim Verschieben bleiben weitere Ordnerverknüpfungen erhalten.`,'Kopieren','copy','Verschieben','move');
    } else if (state.scope==='inbox') {
        mode=(await confirmDialog('Aus Eingang verschieben',`${count} aus dem Eingang in „${folderName}“ verschieben? Danach erscheinen die Dokumente unter „Alle Dokumente“.`,'Verschieben')) ? 'copy' : false;
    } else {
        mode=(await confirmDialog('Dokumente verknüpfen',`${count} mit Ordner „${folderName}“ verknüpfen? Bestehende Verknüpfungen bleiben erhalten.`,'Verknüpfen')) ? 'copy' : false;
    }
    if (!mode) return;
    const revisions=new Map(state.rows.filter(row=>!row._inbound).map(row => [Number(row.id),Number(row.revision)]));
    if (ids.some(id => !revisions.has(id))) { error(new Error('Auswahl ist nicht mehr aktuell. Bitte Suche neu laden.')); return; }
    try {
        const result=await api('bulk',{documents:JSON.stringify(ids.map(id => ({id,revision:revisions.get(id)}))),folderAction:mode==='move'?'move':'add',folderId:targetFolderId,sourceFolderId:mode==='move'?String(sourceFolderId):'',tagAction:'keep',tags:'[]',ownerId:'',status:'keep'},true);
        state.selected.clear(); state.dirty=false; await metadata(); await search(); notice(`${result.count} Dokument(e) verlinkt.`);
    } catch (problem) { error(problem); }
}
$('documentList').addEventListener('pointerdown',event => {
    if (event.button!==0 || event.pointerType==='touch' || state.scope==='trash') return;
    const source=event.target.closest('.doc-open[data-document], .doc-open[data-inbound]');
    const row=source?.closest('[data-drag-document], [data-drag-inbound]');
    if (!source || !row) return;
    const inbound=Boolean(row.dataset.dragInbound);
    const dragged=Number(inbound?row.dataset.dragInbound:row.dataset.dragDocument);
    pointerDrag={pointerId:event.pointerId,startX:event.clientX,startY:event.clientY,active:false,source,kind:inbound?'inbound':'document',ids:inbound?[dragged]:(state.selected.has(dragged)?[...state.selected]:[dragged])};
    try { source.setPointerCapture(event.pointerId); } catch { /* Dokumentweit weiterverfolgen. */ }
});
document.addEventListener('pointermove',event => {
    if (!pointerDrag || pointerDrag.pointerId!==event.pointerId) return;
    if (!pointerDrag.active && Math.hypot(event.clientX-pointerDrag.startX,event.clientY-pointerDrag.startY)<6) return;
    if (!pointerDrag.active) { pointerDrag.active=true; pointerDrag.source.classList.add('is-dragging'); pointerDrag.preview=createDragPreview(pointerDrag); }
    event.preventDefault(); positionDragPreview(pointerDrag.preview,event.clientX,event.clientY);
    highlightDropTarget(folderAt(event.clientX,event.clientY));
},{passive:false});
document.addEventListener('pointerup',event => {
    if (!pointerDrag || pointerDrag.pointerId!==event.pointerId) return;
    const drag=pointerDrag; const folder=drag.active?folderAt(event.clientX,event.clientY):null; const targetFolderId=folder?Number(folder.dataset.dropFolder):null;
    pointerDrag=null; releasePointerDrag(drag);
    if (!drag.active) return;
    event.preventDefault(); suppressDocumentClick=true; setTimeout(() => { suppressDocumentClick=false; },0);
    if (targetFolderId) void handleFolderDrop(drag.ids,targetFolderId,drag.kind);
});
document.addEventListener('pointercancel',event => {
    if (!pointerDrag || pointerDrag.pointerId!==event.pointerId) return;
    const drag=pointerDrag; pointerDrag=null; releasePointerDrag(drag);
});
window.addEventListener('blur',() => { if (pointerDrag) { const drag=pointerDrag; pointerDrag=null; releasePointerDrag(drag); } });
$('documentDetails').addEventListener('click',async event => {
    if (event.target.closest('#documentEditToggle')) { const editor=$('documentEditor'); editor.hidden=!editor.hidden; $('documentEditToggle').setAttribute('aria-expanded',String(!editor.hidden)); return; }
    const action = event.target.closest('[data-status]'), unlink = event.target.closest('[data-unlink]');
    if (action && await discard()) {
        const op = action.dataset.status;
        if (op === 'trash' && !(await confirmDialog('Dokument löschen?', 'Dieses Dokument in den Papierkorb legen?', 'In den Papierkorb', true))) return;
        if (await write('status',{id:state.document.id,revision:state.document.revision,operation:op},'Dokumentstatus gespeichert.')) { state.dirty = false; await metadata(); await search(); }
    } else if (unlink) link(unlink.dataset.unlink,true);
    else if (event.target.closest('#linkButton')) link($('linkFolder').value);
    else if (event.target.closest('[data-invoice]')) {
        invoiceEditor.open(state.document,state.meta.accounting);
    }
});
async function reviewPreview(reference) {
    const doc=await api(reference.inbound?'inboundGet':'get',{id:reference.id});
    return {url:url(reference.inbound?'inboundFile':'file',{id:reference.id}),mime:doc.mime_type,name:doc.original_name||doc.title};
}
invoiceEditor = new LiveInvoiceEditor(api, async () => { notice('Buchungsdaten gespeichert.'); state.dirty=false; await metadata(); await search(); },reviewPreview);
const acceptanceDialog=new InboundAcceptanceDialog(api,()=>state.meta,invoiceEditor,async id=>{ notice(`Dokument D${id} übernommen.`); state.dirty=false; try { await metadata(); await search(); } catch(problem) { error(problem); } },reviewPreview);
const batchAcceptanceDialog=new InboundBatchDialog(api,()=>state.meta,async()=>{ state.dirty=false; await metadata(); await search(); },reviewPreview);
function openFolder(folder = null, parentId = null) {
    $('folderForm').elements.id.value = folder?.id || ''; $('folderForm').elements.name.value = folder?.name || '';
    const parent = $('folderParent'); parent.innerHTML = '<option value="">Hauptordner</option>' + state.meta.folders.filter(x => !folder || Number(x.id) !== Number(folder.id)).map(x => '<option value="' + x.id + '">' + esc(x.name) + '</option>').join('');
    parent.value = folder ? (folder.parent_id == null ? '' : String(folder.parent_id)) : (parentId ? String(parentId) : ''); parent.disabled = false; parent.closest('label').hidden = false;
    $('folderTitle').textContent = folder ? 'Ordner bearbeiten' : 'Neuer Ordner'; $('folderDelete').hidden = !folder; $('folderCreateChild').hidden = !folder;
    bootstrap.Modal.getOrCreateInstance($('folderModal')).show();
}
$('folderCreate').addEventListener('click',() => openFolder());
$('folderCreateChild').addEventListener('click',() => { const parentId=Number($('folderForm').elements.id.value); $('folderModal').addEventListener('hidden.bs.modal',() => openFolder(null,parentId),{once:true}); bootstrap.Modal.getInstance($('folderModal')).hide(); });
$('folderForm').addEventListener('submit',async event => {
    event.preventDefault(); const values = Object.fromEntries(new FormData(event.target));
    if (await write('folder',{...values,operation:values.id?'move':'create'},'Ordner gespeichert.')) { bootstrap.Modal.getInstance($('folderModal')).hide(); await metadata(); if (state.filteredFolderCounts !== null) await search(); }
});
$('folderDelete').addEventListener('click',async () => {
    if (writing) return;
    try {
        const id=$('folderForm').elements.id.value;
        const preview=await api('folderDeletePreview',{id});
        const affected=preview.documents===1?'1 Dokument':`${preview.documents} Dokumente`;
        const message=`Ordner „${preview.name}“ und ${preview.folders} Unterordner endgültig löschen? ${affected} verlieren dadurch ihre Verknüpfung zu diesen Ordnern (Papierkorb-Dokumente mitgezählt). Die Dokumente selbst werden nicht gelöscht; Verknüpfungen zu anderen Ordnern bleiben erhalten.`;
        if (!(await confirmDialog('Ordner und Unterordner löschen?',message,'Ordner löschen',true))) return;
        if (await write('folder',{id,operation:'delete',confirm:'yes',fingerprint:preview.fingerprint},'Ordner gelöscht.')) {
            bootstrap.Modal.getInstance($('folderModal')).hide(); await metadata();
            if (!['all','inbox','unfiled','trash'].includes(String(state.localScope)) && !state.meta.folders.some(x => String(x.id) === String(state.localScope))) state.localScope='all';
            if (!['all','inbox','unfiled','trash'].includes(String(state.scope)) && !state.meta.folders.some(x => String(x.id) === String(state.scope))) state.scope='all';
            await search();
        }
    } catch (problem) { error(problem); }
});
function cancelAutoSearch() {
    clearTimeout(autoSearchTimer);
    autoSearchTimer = null;
}
async function runSearchFromForm() {
    cancelAutoSearch();
    if (!(await discard())) return;
    state.dirty = false;
    state.page = 1;
    if (hasActiveSearch()) state.scope = state.searchMode === 'global' ? 'all' : state.localScope;
    await search();
}
const queryInput = $('searchForm').elements.query;
syncSearchScopeToggle();
$('searchScopeToggle').addEventListener('click',async () => {
    if (!(await discard())) return;
    state.dirty = false;
    state.searchMode = state.searchMode === 'global' ? 'local' : 'global';
    syncSearchScopeToggle();
    if (hasActiveSearch()) { state.page = 1; state.scope = state.searchMode === 'global' ? 'all' : state.localScope; await search(); }
});
queryInput.addEventListener('input',event => {
    cancelAutoSearch();
    const value = event.currentTarget.value.trim();
    if (event.isComposing || [...value].length < 3) return;
    autoSearchTimer = setTimeout(async () => {
        autoSearchTimer = null;
        if (queryInput.value.trim() !== value) return;
        await runSearchFromForm();
    }, 300);
});
$('searchForm').addEventListener('submit',async event => { event.preventDefault(); await runSearchFromForm(); });
$('resetSearch').addEventListener('click',async () => { cancelAutoSearch(); if (await discard()) { $('searchForm').reset(); state.dirty = false; state.page = 1; state.scope = state.localScope; search(); } });
$('previousResults').addEventListener('click',async () => { if (await discard()) { state.dirty = false; --state.page; search(); } });
$('nextResults').addEventListener('click',async () => { if (await discard()) { state.dirty = false; ++state.page; search(); } });
$('resetColumns').addEventListener('click',() => layout?.reset());
$('themeToggle').addEventListener('click',() => { document.querySelector('.workspace-menu').open = false; preferences.theme = normalizeTheme(preferences.theme); preferences.theme.mode = document.documentElement.dataset.bsTheme === 'dark' ? 'light' : 'dark'; preferences.mode = preferences.theme.mode; theme(); if (adminOverlay.classList.contains('show')) syncOverlayTheme(); savePreferences(); });
const adminOverlay = $('adminOverlay');
const adminFrame = $('adminOverlayFrame');
const adminTitles = {choose:'Mandant auswählen',users:'Benutzer',account:'Mein Konto',accounting:'Buchhaltung',evaluation:'Auswertung',settings:'Einstellungen'};
function syncOverlayTheme() {
    const frameRoot = adminFrame.contentDocument?.documentElement;
    if (!frameRoot) return;
    const source = getComputedStyle(document.documentElement);
    for (const name of ['--o8-accent','--o8-on-accent','--o8-background','--o8-surface','--o8-text','--o8-font-scale','--bs-body-font-family']) frameRoot.style.setProperty(name,source.getPropertyValue(name));
    frameRoot.dataset.bsTheme = document.documentElement.dataset.bsTheme;
    frameRoot.dataset.density = document.documentElement.dataset.density;
}
document.querySelectorAll('[data-admin-overlay]').forEach(button => button.addEventListener('click',async () => {
    if (!(await discard())) return;
    state.dirty = false;
    const section = button.dataset.adminOverlay;
    if (!Object.hasOwn(adminTitles,section)) return;
    $('adminOverlayTitle').textContent = adminTitles[section];
    adminFrame.title = adminTitles[section];
    document.querySelector('.workspace-menu').open = false;
    bootstrap.Modal.getOrCreateInstance(adminOverlay).show();
    adminFrame.src = `${location.pathname}?${new URLSearchParams({section,overlay:'1'})}`;
}));
adminFrame.addEventListener('load',() => {
    if (!adminOverlay.classList.contains('show') || !adminFrame.hasAttribute('src')) return;
    try {
        const body = adminFrame.contentDocument?.body;
        if (!body?.classList.contains('overlay-page')) { location.reload(); return; }
        if (body.dataset.overlayComplete === '1' || (body.dataset.overlayContext && body.dataset.overlayContext !== root.dataset.context)) { location.reload(); return; }
        if (body.dataset.overlayCsrf) {
            root.dataset.csrf = body.dataset.overlayCsrf;
            document.querySelectorAll('input[name="csrf"]').forEach(input => { input.value = body.dataset.overlayCsrf; });
        }
        syncOverlayTheme();
    } catch { location.reload(); }
});
adminOverlay.addEventListener('hidden.bs.modal',() => { adminFrame.removeAttribute('src'); });
function fillAppearance() {
    const t=normalizeTheme(preferences.theme);
    $('appearanceForm').elements.appearanceMode.value=t.mode; $('appearanceForm').elements.fontFamily.value=t.fontFamily; $('appearanceForm').elements.fontSize.value=String(t.fontSize); $('appearanceForm').elements.density.value=t.density;
    for (const [name,value] of [['lightAccent',t.light.accent],['lightBackground',t.light.background],['lightSurface',t.light.surface],['darkAccent',t.dark.accent],['darkBackground',t.dark.background],['darkSurface',t.dark.surface]]) $('appearanceForm').elements[name].value=value;
}
$('appearanceModal').addEventListener('show.bs.modal',() => { document.querySelector('.workspace-menu').open = false; fillAppearance(); });
$('appearanceForm').addEventListener('submit',async event => {
    event.preventDefault(); const values=Object.fromEntries(new FormData(event.target)); const t=normalizeTheme({mode:values.appearanceMode,density:values.density,fontFamily:values.fontFamily,fontSize:Number(values.fontSize),light:{accent:values.lightAccent,background:values.lightBackground,surface:values.lightSurface},dark:{accent:values.darkAccent,background:values.darkBackground,surface:values.darkSurface}});
    preferences.theme=t; preferences.mode=t.mode; theme(); await savePreferences(); $('appearanceStatus').textContent='Darstellung und persönliche Ansicht gespeichert.';
});
$('resetAppearance').addEventListener('click',() => { preferences.theme=structuredClone(DEFAULT_THEME); preferences.mode=preferences.theme.mode; theme(); fillAppearance(); savePreferences(); $('appearanceStatus').textContent='Standarddarstellung wiederhergestellt.'; });
matchMedia('(prefers-color-scheme: dark)').addEventListener('change',() => theme());
document.querySelectorAll('[data-live-pane]').forEach(button => button.addEventListener('click',() => {
    $('documentWorkspace').dataset.pane = button.dataset.livePane;
    document.querySelectorAll('[data-live-pane]').forEach(x => { x.classList.toggle('active',x === button); x.setAttribute('aria-current',x === button ? 'true' : 'false'); });
}));
document.querySelector('.app-header .brand')?.addEventListener('click',async event => {
    if (!state.dirty && !writing) return;
    event.preventDefault();
    const href=event.currentTarget.href;
    if (await discard()) { state.dirty = false; location.href = href; }
});
document.querySelector('[data-logout-form]')?.addEventListener('submit',async event => {
    if (!state.dirty && !writing) return;
    event.preventDefault();
    const form=event.currentTarget;
    if (await discard()) { state.dirty = false; form.requestSubmit(); }
});
function upload(file,index,total) {
    return new Promise((resolve,reject) => {
        const xhr = new XMLHttpRequest(); xhr.open('POST',url('upload')); xhr.responseType = 'json';
        xhr.upload.addEventListener('progress',event => {
            const percent = event.lengthComputable ? Math.round(event.loaded/event.total*100) : 0;
            $('uploadProgress').value = percent; $('uploadStatus').textContent = 'Datei ' + index + '/' + total + ': ' + file.name + ' · ' + percent + '% (danach Ablageprüfung)';
        });
        xhr.addEventListener('load',() => xhr.status < 300 && xhr.response?.success ? resolve() : reject(new Error(xhr.response?.error || 'Upload fehlgeschlagen. PHP-/Webserverlimits prüfen.')));
        xhr.addEventListener('error',() => reject(new Error('Upload-Verbindung unterbrochen. Bitte Eingang prüfen, bevor erneut hochgeladen wird.')));
        const form = data({}); form.set('document',file); xhr.send(form);
    });
}
$('uploadModal').addEventListener('hide.bs.modal',event => { if (writing) event.preventDefault(); });
$('uploadForm').addEventListener('submit',async event => {
    event.preventDefault(); if (writing || !(await discard())) return;
    const files = [...$('uploadFiles').files]; if (!files.length) return;
    if (files.some(x => x.size > 1073741824 || !x.size)) { $('uploadStatus').textContent = 'Jede Datei muss 1 Byte bis 1 GiB groß sein.'; return; }
    writing = true; $('uploadSubmit').disabled = true; $('uploadFiles').disabled = true; state.dirty = false;
    let completed = 0;
    try {
        for (const file of files) { await upload(file,completed+1,files.length); ++completed; }
        $('uploadForm').reset(); notice(completed + ' Dokument(e) in den Eingang geladen.');
    } catch (problem) { $('uploadStatus').textContent = completed + ' gespeichert. ' + problem.message + ' Nicht automatisch erneut senden.'; error(problem); }
    finally {
        writing = false; $('uploadSubmit').disabled = false; $('uploadFiles').disabled = false;
        if (completed === files.length) bootstrap.Modal.getInstance($('uploadModal')).hide();
        if (completed) { state.scope = 'inbox'; state.localScope = 'inbox'; state.page = 1; await metadata(); await search(); }
    }
});

function sourceOptions() {
    const sources=(state.meta?.sources || []).filter(source => Number(source.enabled));
    const storageReady=Boolean(state.meta?.storageReady);
    $('sourceFetchSource').innerHTML='<option value="">Quelle wählen</option>'+sources.map(source=>'<option value="'+source.id+'">'+esc(source.name)+' · '+esc(source.kind==='imap'?'IMAP':'WebDAV')+'</option>').join('');
    if (sources.length) $('sourceFetchSource').value=String(sources[0].id);
    $('sourceFetchSource').disabled=!storageReady || !sources.length;
    $('sourceBrowse').disabled=!storageReady || !sources.length;
    $('sourceSelectAll').hidden=true; $('sourceSelectAll').disabled=true; $('sourceSelectAll').setAttribute('aria-pressed','false'); $('sourceSelectAllLabel').textContent='Alle markieren';
    $('sourceRetentionHint').hidden=true; $('sourceRetentionHint').textContent='';
    $('sourceInventory').textContent=!storageReady
        ? (state.meta?.storageMessage || 'Die geprüfte Dokumentablage ist noch nicht verfügbar.')
        : !sources.length
            ? 'Keine aktive persönliche IMAP- oder WebDAV-Quelle vorhanden. Quellen werden unter Menü → Mein Konto eingerichtet.'
            : 'Dateien der ausgewählten Quelle anzeigen.';
    $('sourceFetchSubmit').disabled=true; $('sourceFetchStatus').textContent=''; $('sourceFetchProgress').hidden=true;
    sourceRetention();
}
function sourceDocument(name) { return /\.(?:pdf|jpe?g|png)$/i.test(String(name)); }
function sourceRetention() {
    const id=Number($('sourceFetchSource').value); const source=(state.meta?.sources || []).find(item=>Number(item.id)===id); const hint=$('sourceRetentionHint');
    if (!source) { hint.hidden=true; hint.textContent=''; return; }
    const remove=Boolean(source.config?.delete_after_fetch);
    hint.textContent=remove
        ? source.kind==='imap'
            ? 'Nach vollständig erfolgreichem Abruf wird die Nachricht an der Quelle gelöscht, sofern alle unterstützten Dokumentanhänge der Nachricht übernommen wurden.'
            : 'Nach vollständig erfolgreichem Abruf werden die abgerufenen Dateien an der WebDAV-Quelle gelöscht.'
        : 'Die abgerufenen Dateien beziehungsweise Nachrichten bleiben nach erfolgreichem Abruf an der Quelle erhalten.';
    hint.classList.toggle('is-destructive',remove); hint.hidden=false;
}
function syncSourceSelection() {
    const boxes=[...$('sourceInventory').querySelectorAll('input[type="checkbox"]')]; const marked=boxes.filter(box=>box.checked).length; const all=boxes.length>0 && marked===boxes.length;
    $('sourceFetchSubmit').disabled=marked===0; $('sourceSelectAll').hidden=boxes.length===0; $('sourceSelectAll').disabled=boxes.length===0; $('sourceSelectAll').setAttribute('aria-pressed',all?'true':'false'); $('sourceSelectAllLabel').textContent=all?'Alle demarkieren':'Alle markieren';
}
function renderSourceInventory(result) {
    const entries=[];
    if (result.kind==='imap') for (const message of result.rows) for (const attachment of message.attachments) if (sourceDocument(attachment.filename)) entries.push({key:attachment.remoteKey,name:attachment.filename,meta:(message.from||'Unbekannter Absender')+' · '+(message.subject||'Kein Betreff')});
    if (result.kind==='webdav') for (const file of result.rows) if (sourceDocument(file.filename)) entries.push({key:file.remoteKey,name:file.filename,meta:file.modified||'WebDAV'});
    $('sourceInventory').innerHTML=entries.length?entries.map(entry=>'<label class="source-inventory-item"><input class="form-check-input" type="checkbox" value="'+entry.key+'"><span><strong>'+esc(entry.name)+'</strong><small>'+esc(entry.meta)+'</small></span></label>').join(''):'<div class="empty-state">Keine unterstützten Dokumentdateien gefunden.</div>';
    syncSourceSelection();
}
function renderSourceJob(job,manual=false) {
    const done=Number(job.completed_count)+Number(job.failed_count); const total=Math.max(1,Number(job.total_count));
    $('sourceFetchProgress').hidden=false; $('sourceFetchProgress').max=total; $('sourceFetchProgress').value=done;
    const queuedSince=Date.parse(String(job.created_at).replace(' ','T')+'Z'); const workerLate=!manual && job.status==='queued' && Number.isFinite(queuedSince) && Date.now()-queuedSince>120000;
    $('sourceFetchStatus').textContent=job.status==='queued'?(manual?`Manueller Abruf: ${done} von ${job.total_count} Dateien verarbeitet …`:workerLate?'Abruf ist eingereiht, aber der serverseitige Hintergrundworker wurde noch nicht ausgeführt.':'Abruf wartet auf den Hintergrundworker …'):job.status==='running'?`${done} von ${job.total_count} Dateien verarbeitet …`:job.status==='completed'?(job.error_code==='remote_cleanup_failed'?'Abruf abgeschlossen; das Löschen an der Quelle muss geprüft werden.':'Abruf abgeschlossen.'): `${job.failed_count} Datei(en) konnten nicht abgerufen werden.`;
    return job.status==='queued' || job.status==='running';
}
async function finishSourceJob(job) {
    const successful=job.status==='completed' && !job.error_code;
    if (!successful) { await metadata(); return; }
    state.scope='inbox'; state.localScope='inbox'; state.page=1;
    bootstrap.Modal.getOrCreateInstance($('sourceFetchModal')).hide();
    await metadata(); await search(); notice(job.total_count+' Dokument(e) in den Eingang übernommen.');
}
async function pollSourceJob(id) {
    clearTimeout(sourcePollTimer);
    try { const job=await api('sourceJob',{id}); if (renderSourceJob(job)) sourcePollTimer=setTimeout(()=>pollSourceJob(id),2000); else await finishSourceJob(job); }
    catch (problem) { $('sourceFetchStatus').textContent=problem.message || String(problem); }
}
async function runManualSourceJob(job) {
    for (let step=0; renderSourceJob(job,true) && step<101; step++) job=await api('sourceRun',{id:job.id},true);
    renderSourceJob(job,true); if (job.status==='queued' || job.status==='running') throw new Error('Manueller Abruf konnte nicht vollständig abgeschlossen werden.'); await finishSourceJob(job);
}
$('sourceFetchModal').addEventListener('show.bs.modal',sourceOptions);
$('sourceFetchModal').addEventListener('hidden.bs.modal',()=>clearTimeout(sourcePollTimer));
$('sourceBrowse').addEventListener('click',async () => {
    const source=$('sourceFetchSource').value; if (!source) { $('sourceFetchStatus').textContent='Bitte zuerst eine Quelle auswählen.'; return; }
    $('sourceInventory').textContent='Quelleninhalt wird geprüft …'; $('sourceFetchSubmit').disabled=true; $('sourceSelectAll').hidden=true; $('sourceSelectAll').disabled=true; $('sourceFetchStatus').textContent='';
    try { renderSourceInventory(await api('sourceBrowse',{id:source})); } catch (problem) { $('sourceInventory').textContent=''; $('sourceFetchStatus').textContent=problem.message || String(problem); }
});
$('sourceInventory').addEventListener('change',syncSourceSelection);
$('sourceSelectAll').addEventListener('click',()=>{ const boxes=[...$('sourceInventory').querySelectorAll('input[type="checkbox"]')]; const mark=!boxes.length?false:!boxes.every(box=>box.checked); boxes.forEach(box=>{ box.checked=mark; }); syncSourceSelection(); });
$('sourceFetchSource').addEventListener('change',()=>{ $('sourceInventory').textContent='Dateien dieser Quelle anzeigen.'; $('sourceFetchSubmit').disabled=true; $('sourceSelectAll').hidden=true; $('sourceSelectAll').disabled=true; $('sourceSelectAll').setAttribute('aria-pressed','false'); $('sourceSelectAllLabel').textContent='Alle markieren'; $('sourceFetchStatus').textContent=''; sourceRetention(); });
$('sourceFetchForm').addEventListener('submit',async event => {
    event.preventDefault(); const keys=[...$('sourceInventory').querySelectorAll('input:checked')].map(input=>input.value); if (!keys.length) return;
    $('sourceFetchSubmit').disabled=true; $('sourceBrowse').disabled=true;
    try { const job=await api('sourceFetch',{source:$('sourceFetchSource').value,keys:JSON.stringify(keys)},true); if (Number(job.interval_minutes)===0) await runManualSourceJob(job); else await pollSourceJob(job.id); }
    catch (problem) { $('sourceFetchStatus').textContent=problem.message || String(problem); }
    finally { $('sourceBrowse').disabled=false; }
});
try { await metadata(true); await search(); } catch (problem) { error(problem); }
