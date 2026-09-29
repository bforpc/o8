import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const view = readFileSync(new URL('../app/views/live-workspace.php', import.meta.url), 'utf8');
const script = readFileSync(new URL('../public/assets/js/live-workspace.js', import.meta.url), 'utf8');
const batchScript = readFileSync(new URL('../public/assets/js/live-inbound-batch.js', import.meta.url), 'utf8');
const settings = readFileSync(new URL('../app/views/admin-sources.php', import.meta.url), 'utf8');
const acceptance = readFileSync(new URL('../public/assets/js/live-inbound-acceptance.js', import.meta.url), 'utf8');
const documentApi = readFileSync(new URL('../app/document-api.php', import.meta.url), 'utf8');
const german = JSON.parse(readFileSync(new URL('../lang/de.json', import.meta.url), 'utf8')).messages;
const english = JSON.parse(readFileSync(new URL('../lang/en.json', import.meta.url), 'utf8')).messages;

test('inbound AI start, progress, history and external-processing consent stay reachable', () => {
    for (const id of ['inboundActionModal','inboundAiStartSelected','inboundAiProgressModal','inboundAiProgress','inboundAiProgressItems']) assert.match(view,new RegExp(`id="${id}"`));
    assert.match(script,/inboundAiStart/);
    assert.match(script,/inboundAiJob/);
    assert.match(script,/KI-VERLAUF/);
    assert.match(script,/Ignoriert \(nicht im aktiven Katalog\)/);
    assert.match(script,/inboundAiRun/);
    assert.match(script,/aiProcessingDocument/);
    assert.match(script,/aiProgressCounts/);
    assert.match(script,/replaceActive:'1'/);
    assert.match(script,/problem\.code!=='active_ai_job'/);
    assert.match(settings,/Aktuelle IONOS-Modelle laden/);
    assert.match(settings,/form="aiModelsRefreshForm"/);
    assert.match(settings,/id="aiPrimaryModel"/);
    assert.match(settings,/external_processing_confirmed/);
    assert.match(settings,/O8_AI_WORKER=1/);
});

test('AI replacement confirmation and progress use available client translations', () => {
    assert.match(script,/clientTr\('cancelAiRunTitle'\)/);
    assert.match(script,/clientTr\('cancelAiRunConfirm'/);
    assert.match(script,/clientTr\('cancelAiRunAction'\)/);
    const keys = [...script.matchAll(/clientTr\('([^']+)'/g)].map(match => match[1]);
    for (const key of new Set(keys)) {
        assert.equal(typeof german.client?.[key], 'string', `German client translation missing: ${key}`);
        assert.equal(typeof english.client?.[key], 'string', `English client translation missing: ${key}`);
    }
    assert.match(view,/id="inboundAiBackgroundHint"/);
    assert.match(view,/tr\('client\.aiProgressBackgroundHint'\)/);
    assert.match(script,/return labels\[value\] \|\| \(httpStatus \? clientTr\('aiHttpErrorStatus'/);
    assert.match(script,/json!==null&&item\.ai_history\?\.\[0\]\?\.status==='failed'/);
    assert.match(script,/clientTr\('aiSidecarWithFailedRun'\)/);
    for (const key of ['aiProgressBackgroundHint','aiErrorUnknown','aiSidecarWithFailedRun','extractTooLarge','curlUnavailable','aiRequestFailed','aiHttpErrorStatus','aiAuthRejected','aiModelNotFound','aiRateLimited','storageMissing','storageUnavailable','storageWriteFailed','storageVerifyFailed']) {
        assert.equal(typeof german.client?.[key], 'string', `German client translation missing: ${key}`);
        assert.equal(typeof english.client?.[key], 'string', `English client translation missing: ${key}`);
    }
});

test('IONOS errors expose safe HTTP diagnostics for inference and model-list requests',()=>{
    const processor=readFileSync(new URL('../app/Inbound/AiProcessor.php',import.meta.url),'utf8');
    const configuration=readFileSync(new URL('../app/Inbound/AiConfiguration.php',import.meta.url),'utf8');
    assert.match(processor,/ai_auth_rejected/);
    assert.match(processor,/ai_model_not_found/);
    assert.match(processor,/ai_rate_limited/);
    assert.match(processor,/ai_http_error_'\.\$status/);
    assert.match(configuration,/IONOS-Modellabruf abgelehnt \(HTTP '\.\$status/);
    assert.match(configuration,/IONOS-Modellabruf nicht gefunden \(HTTP 404\)/);
    assert.match(script,/ai_http_error_\(\\d\{3\}\)/);
});

test('mixed DMS and inbox selections still permit inbox AI actions and their warning is dismissible', () => {
    assert.match(view,/id="inboundActionWarning"/);
    assert.match(view,/id="closeInboundActionWarning"/);
    assert.match(view,/id="closeLiveError"/);
    assert.match(script,/state\.inboundSelected\.size\)\s*\{/);
    assert.match(script,/inboundActionWarningText'\)\.textContent=state\.selected\.size\?/);
    assert.doesNotMatch(script,/DMS-Dokumente und noch nicht übernommene Eingangselemente bitte getrennt auswählen/);
    assert.match(script,/closeInboundActionWarning'\)\.addEventListener\('click'/);
    assert.match(script,/state\.meta\.aiReady\?'':'Externe KI ist noch nicht durch einen Mandanten-Admin eingerichtet\.'/);
    assert.match(script,/inboundNotAccepted/);
    assert.match(script,/documentId'\)\.textContent='E'\+item\.id/);
    assert.match(script,/<span>E'\+item\.id\+?'/);
    assert.match(script,/inboundHead\.append\(detailRoot\.querySelector\('\.live-detail-intro'\),detailRoot\.querySelector\('\.live-detail-actions'\),detailRoot\.querySelector\('\.live-detail-facts'\)\)/);
    assert.match(script,/selectedInboundCount/);
    assert.match(script,/total:state\.selected\.size\+state\.inboundSelected\.size,inbound:state\.inboundSelected\.size,documents:state\.selected\.size/);
    assert.match(script,/dmsInboxDraft/);
    assert.match(script,/id="returnUploadToInbox"/);
    assert.match(script,/api\('inboundRestore'/);
    assert.match(documentApi,/new InboundUploads\(\$db,\$root\)\)->upload/);
});

test('inbound JSON is collapsed and folder drop keeps the acceptance review', () => {
    assert.match(script,/document\.createElement\('details'\)/);
    assert.match(script,/summary\.textContent='KI-\/JSON-Daten anzeigen'/);
    assert.match(script,/data-drag-inbound/);
    assert.match(script,/acceptanceDialog\.open\(ids\[0\],targetFolderId\)/);
    assert.match(script,/Aus Eingang verschieben/);
    assert.match(acceptance,/\.modal-footer \[type="submit"\]'\)\.textContent=t\('accept'\)/);
    assert.doesNotMatch(acceptance,/t\('moveAction'\)/);
    assert.match(acceptance,/async open\(id, targetFolderId=null\)/);
    assert.match(acceptance,/if \(folder\) folder\.checked=true/);
});

test('folder parent choices preserve the hierarchy and successful batch acceptance closes', () => {
    assert.match(script,/function folderParentOptions\(items,currentFolder=null,emptyLabel='Hauptordner'\)/);
    assert.match(script,/parent\.innerHTML = folderParentOptions\(state\.meta\.folders,folder,tr\('mainFolder'\)\)/);
    assert.match(script,/id="linkFolder" class="form-select">' \+ folderParentOptions\(state\.meta\.folders,null,tr\('chooseFolder'\)\)/);
    assert.match(script,/repeat\(depth\).*└─/s);
    assert.match(batchScript,/closeAfterSuccess=done===rows\.length&&failed===0&&!this\.stopping/);
    assert.match(batchScript,/this\.running=false; stop\.hidden=true[\s\S]*?if\(closeAfterSuccess\)bootstrap\.Modal\.getOrCreateInstance\(this\.modal\)\.hide\(\)/);
});

test('manual acceptance persists only the remaining selected catalogue tags', () => {
    assert.match(acceptance, /<details class="accept-tag-info">/);
    assert.doesNotMatch(acceptance, /name="tagMode"/);
    assert.match(acceptance, /p\.matchedTags\.map\(t=>t\.name\)/);
    assert.match(acceptance, /const chosen=this\.picker\.values\(\); input\.tags=/);
    assert.match(acceptance, /confirmNewTagSelection\(this\.picker,confirmDialog\)/);
    assert.match(acceptance, /input\.newTags=newTags/);
    assert.match(acceptance, /input\.partialInvoice=this\.partialInvoice/);
    assert.doesNotMatch(acceptance, /if\(this\.invoiceWarning\)throw new Error/);
    assert.match(acceptance, /input\.tagMode='replace'/);
    assert.match(documentApi, /\$input\['tagMode'\]='replace'/);
});
