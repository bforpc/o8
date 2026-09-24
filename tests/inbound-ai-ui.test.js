import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const view = readFileSync(new URL('../app/views/live-workspace.php', import.meta.url), 'utf8');
const script = readFileSync(new URL('../public/assets/js/live-workspace.js', import.meta.url), 'utf8');
const settings = readFileSync(new URL('../app/views/admin-sources.php', import.meta.url), 'utf8');
const acceptance = readFileSync(new URL('../public/assets/js/live-inbound-acceptance.js', import.meta.url), 'utf8');
const documentApi = readFileSync(new URL('../app/document-api.php', import.meta.url), 'utf8');

test('inbound AI start, progress, history and external-processing consent stay reachable', () => {
    for (const id of ['inboundActionModal','inboundAiStartSelected','inboundAiProgressModal','inboundAiProgress','inboundAiProgressItems']) assert.match(view,new RegExp(`id="${id}"`));
    assert.match(script,/inboundAiStart/);
    assert.match(script,/inboundAiJob/);
    assert.match(script,/KI-VERLAUF/);
    assert.match(script,/Ignoriert \(nicht im aktiven Katalog\)/);
    assert.match(script,/inboundAiRun/);
    assert.match(settings,/Aktuelle IONOS-Modelle laden/);
    assert.match(settings,/form="aiModelsRefreshForm"/);
    assert.match(settings,/id="aiPrimaryModel"/);
    assert.match(settings,/external_processing_confirmed/);
    assert.match(settings,/O8_AI_WORKER=1/);
});

test('inbound JSON is collapsed and folder drop keeps the acceptance review', () => {
    assert.match(script,/document\.createElement\('details'\)/);
    assert.match(script,/summary\.textContent='KI-\/JSON-Daten anzeigen'/);
    assert.match(script,/data-drag-inbound/);
    assert.match(script,/acceptanceDialog\.open\(ids\[0\],Number\(folder\.dataset\.dropFolder\)\)/);
    assert.match(script,/Aus Eingang verschieben/);
    assert.match(acceptance,/async open\(id, targetFolderId=null\)/);
    assert.match(acceptance,/if \(folder\) folder\.checked=true/);
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
