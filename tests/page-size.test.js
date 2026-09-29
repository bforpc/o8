import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const view=readFileSync(new URL('../app/views/live-workspace.php',import.meta.url),'utf8');
const script=readFileSync(new URL('../public/assets/js/live-workspace.js',import.meta.url),'utf8');
const backend=readFileSync(new URL('../app/Documents/Documents.php',import.meta.url),'utf8');
const de=JSON.parse(readFileSync(new URL('../lang/de.json',import.meta.url),'utf8')).messages;
const en=JSON.parse(readFileSync(new URL('../lang/en.json',import.meta.url),'utf8')).messages;

test('page-size selector is available in the list footer and shares advanced-search choices',()=>{
    assert.match(view,/id="pageSizeSelect"/);
    for(const size of [10,25,50,100,250,500,1000]) {
        assert.match(view,new RegExp(`<option value="${size}"`));
    }
    assert.match(script,/\$\('pageSizeSelect'\)\.addEventListener\('change'/);
    assert.match(script,/elements\.size\.addEventListener\('change'/);
});

test('page size is restored and saved per user, with confirmation above 100',()=>{
    assert.match(script,/preferences\.pageSize=Number\(preferences\.pageSize\)\|\|50/);
    assert.match(script,/clientTr\('largePageSizeMessage',\{count:next\}\)/);
    assert.match(script,/if \(next>100 && !\(await confirmDialog/);
    assert.match(script,/preferences\.pageSize=next/);
    assert.match(script,/api\('preferences',\{preferences:snapshot\},true\)/);
    assert.match(backend,/\['pageSize'\]\?\?\$storedPreferences\['pageSize'\]/);
    assert.match(backend,/\[10,25,50,100,250,500,1000\]/);
    assert.match(backend,/pageSize'=>\$pageSize/);
    for(const key of ['pageSize']) {
        assert.equal(typeof de.search?.[key],'string');
        assert.equal(typeof en.search?.[key],'string');
    }
    for(const key of ['largePageSizeTitle','largePageSizeMessage','largePageSizeAction']) {
        assert.equal(typeof de.client?.[key],'string');
        assert.equal(typeof en.client?.[key],'string');
    }
});
