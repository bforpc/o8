import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = name => fs.readFileSync(new URL(`../public/assets/js/${name}`, import.meta.url), 'utf8');
const readProject = name => fs.readFileSync(new URL(`../${name}`, import.meta.url), 'utf8');

test('live and account flows do not call native alert, confirm, or prompt', () => {
    for (const name of ['live-workspace.js', 'account-context.js']) {
        const source = read(name);
        assert.doesNotMatch(source, /\b(?:window\.)?(?:alert|confirm|prompt)\s*\(/, name);
        assert.match(source, /\.\/dialog\.js/, name);
    }
});

test('trash management confirms permanent deletion and shows bounded progress', () => {
    const source = read('live-workspace.js');
    const view = readProject('app/views/live-workspace.php');
    const api = readProject('app/document-api.php');
    assert.match(source, /data-empty-trash/);
    assert.match(source, /confirmDialog\('Papierkorb vollständig leeren\?'/);
    assert.match(source, /api\('trashPurgeInfo'\)/);
    assert.match(source, /for \(const item of snapshot\.items\)/);
    assert.match(source, /api\('trashPurge'/);
    assert.match(view, /id="trashPurgeProgress"/);
    assert.match(api, /field\('confirm'\)!=='yes'/);
});

test('folder deletion previews subtree and document impact before confirmation', () => {
    const source = read('live-workspace.js');
    const view = readProject('app/views/live-workspace.php');
    const api = readProject('app/document-api.php');
    assert.match(view, /id="folderDelete"[^>]*>Ordner löschen …/);
    assert.match(source, /api\('folderDeletePreview',\{id\}\)/);
    assert.match(source, /preview\.folders.*Unterordner/);
    assert.match(source, /preview\.documents.*Dokument/);
    assert.match(source, /Die Dokumente selbst werden nicht gelöscht/);
    assert.match(source, /confirmDialog\('Ordner und Unterordner löschen\?'/);
    assert.match(source, /fingerprint:preview\.fingerprint/);
    assert.match(api, /field\('confirm'\)!=='yes'/);
});

test('folder delete action keeps documents and rejects changed confirmation', () => {
    const backend = readProject('app/Documents/Documents.php');
    assert.match(backend, /function folderDeletePreview\(/);
    assert.match(backend, /count\(\$documents\)/);
    assert.match(backend, /hash_equals\(\$plan\['fingerprint'\],\$expectedFingerprint\)/);
    assert.match(backend, /UPDATE documents SET revision=revision\+1/);
    assert.match(backend, /DELETE FROM folder_documents WHERE tenant_id=\? AND folder_id=\?/);
    assert.match(backend, /array_reverse\(\$plan\['ids'\]\)/);
});

test('live drag and drop keeps checkbox selection and uses atomic bulk linking', () => {
    const source = read('live-workspace.js');
    assert.match(source, /state\.selected\.has\(dragged\)\?\[\.\.\.state\.selected\]:\[dragged\]/);
    assert.match(source, /addEventListener\('pointerdown'/);
    assert.match(source, /addEventListener\('pointermove'/);
    assert.match(source, /folderAt\(event\.clientX,event\.clientY\)/);
    assert.match(source, /getBoundingClientRect\(\)/);
    assert.match(source, /createDragPreview\(pointerDrag\)/);
    assert.match(source, /document-drag-preview/);
    assert.doesNotMatch(source, /event\.dataTransfer/);
    assert.match(source, /folderAction:mode==='move'\?'move':'add'/);
    assert.match(source, /choiceDialog\('Dokumente ablegen'/);
    assert.match(source, /sourceFolderId:mode==='move'\?String\(sourceFolderId\):''/);
    assert.match(source, /handleFolderDrop\(drag\.ids,targetFolderId,drag\.kind\)/);
    assert.match(source, /data-drag-inbound/);
    assert.match(source, /acceptanceDialog\.open\(ids\[0\],targetFolderId\)/);
});

test('folder tree expansion is saved per-user and temporary drag expansion is restored',()=>{
    const source=read('live-workspace.js');
    const backend=readProject('app/Documents/Documents.php');
    const css=readProject('public/assets/css/live.css');
    assert.match(source,/preferences\.collapsedFolders=\[\.\.\.collapsedFolderIds\]\.map\(Number\)/);
    assert.match(source,/data-folder-toggle/);
    assert.match(source,/hiddenById\.get\(parent\)===true \|\| collapsedFolderIds\.has\(parent\)/);
    assert.match(source,/const subtreeFolderCount=\(id,seen=new Set\(\)\)=>/);
    assert.match(source,/countOverride=isCollapsed\?subtreeFolderCount\(id\):null/);
    assert.match(source,/dragCollapsedSnapshot=new Set\(collapsedFolderIds\)/);
    assert.match(source,/const restoreDragFolderExpansion = \(\) =>/);
    assert.match(source,/restoreDragFolderExpansion\(\); clearDropTarget\(\)/);
    assert.match(backend,/SELECT COUNT\(\*\) FROM folders WHERE tenant_id=\? AND id IN/);
    assert.match(css,/\.folder-nav-row\[hidden\] \{ display: none !important; \}/);
});

test('live search switches between global and current-folder scope and keeps view-only details compact', () => {
    const source = read('live-workspace.js');
    assert.match(source, /searchMode: 'global'/);
    assert.match(source, /localScope: 'inbox'/);
    assert.match(source, /state\.searchMode === 'global' \? 'all' : state\.localScope/);
    assert.match(source, /button\.textContent = local \? searchTr\('localShort'\) : searchTr\('global'\)/);
    assert.match(source, /state\.localScope = button\.dataset\.scope/);
    assert.match(source, /state\.scope = hasActiveSearch\(\) && state\.searchMode === 'global' \? 'all' : state\.localScope/);
    assert.doesNotMatch(source, /if \(hasActiveSearch\(\)\) \{ state\.searchMode = 'local'/);
    assert.match(source, /state\.filteredFolderCounts = \{\[state\.scope\]: Number\(result\.total\)\}/);
    assert.match(source, /queryInput\.addEventListener\('input'/);
    assert.match(source, /\[\.\.\.value\]\.length < 3/);
    assert.match(source, /}, 300\);/);
    assert.match(source, /addEventListener\('submit'.*runSearchFromForm/s);
    assert.match(source, /class=\"tag-list\"/);
    assert.match(source, /class=\"folder-chip\"/);
});

test('remote source selection queues a background fetch and polls bounded progress', () => {
    const source = read('live-workspace.js');
    const view = readProject('app/views/live-workspace.php');
    assert.doesNotMatch(view, /id="sourceOpen" disabled/);
    assert.doesNotMatch(source, /\$\('sourceOpen'\)\.disabled/);
    assert.match(source, /Keine aktive persönliche IMAP- oder WebDAV-Quelle vorhanden/);
    assert.match(source, /api\('sourceBrowse',\{id:source\}\)/);
    assert.match(source, /if \(sources\.length\) \$\('sourceFetchSource'\)\.value=String\(sources\[0\]\.id\)/);
    assert.match(source, /api\('sourceFetch',\{source:\$\('sourceFetchSource'\)\.value,keys:JSON\.stringify\(keys\)\},true\)/);
    assert.match(source, /Number\(job\.interval_minutes\)===0/);
    assert.match(source, /api\('sourceRun',\{id:job\.id\},true\)/);
    assert.match(source, /api\('sourceJob',\{id\}\)/);
    assert.match(source, /sourcePollTimer=setTimeout\(\(\)=>pollSourceJob\(id\),2000\)/);
    assert.match(source, /job\.status==='completed' && !job\.error_code/);
    assert.match(source, /state\.scope='inbox'; state\.localScope='inbox'; state\.page=1/);
    assert.match(source, /getOrCreateInstance\(\$\('sourceFetchModal'\)\)\.hide\(\)/);
    assert.match(view, /id="sourceSelectAll"/);
    assert.match(view, /id="sourceSelectAll"[^>]*hidden/);
    assert.match(source, /sourceSelectAll'\)\.hidden=boxes\.length===0/);
    assert.match(source, /sourceSelectAllLabel'\)\.textContent=all\?'Alle demarkieren':'Alle markieren'/);
    assert.match(source, /delete_after_fetch/);
    assert.match(source, /bleiben nach erfolgreichem Abruf an der Quelle erhalten/);
    assert.match(source, /serverseitige Hintergrundworker wurde noch nicht ausgeführt/);
});

test('entrance workbench previews managed items and deletes marked items through styled confirmation', () => {
    const source = read('live-workspace.js');
    assert.match(source, /api\('inboundItems'/);
    assert.match(source, /api\('inboundGet'/);
    assert.match(source, /url\('inboundFile'/);
    assert.match(source, /confirmDialog\('Aus Eingang löschen\?'/);
    assert.match(source, /api\('inboundDelete'/);
});
