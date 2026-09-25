import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const de=JSON.parse(fs.readFileSync(new URL('../lang/de.json',import.meta.url),'utf8'));
const en=JSON.parse(fs.readFileSync(new URL('../lang/en.json',import.meta.url),'utf8'));
const flatten=(tree,prefix='')=>Object.entries(tree).flatMap(([key,value])=>value&&typeof value==='object'?flatten(value,prefix?`${prefix}.${key}`:key):[[prefix?`${prefix}.${key}`:key,value]]);

test('base language catalogues have the same keys and named placeholders',()=>{
    const german=Object.fromEntries(flatten(de.messages)); const english=Object.fromEntries(flatten(en.messages));
    assert.deepEqual(Object.keys(english).sort(),Object.keys(german).sort());
    for(const key of Object.keys(german)) {
        const vars=value=>[...new Set([...value.matchAll(/\{([a-zA-Z][a-zA-Z0-9_]*)\}/g)].map(match=>match[1]))].sort();
        assert.deepEqual(vars(german[key]),vars(english[key]),key);
    }
});

test('JavaScript uses the shared catalogue for source-pattern and plural translation',async()=>{
    globalThis.window=globalThis;
    globalThis.document={getElementById:()=>({textContent:JSON.stringify({language:'en',messages:en.messages,de:de.messages})})};
    await import(`../public/assets/js/i18n.js?test=${Date.now()}`);
    assert.equal(globalThis.o8Translate('common.tagRemove',{tag:'Invoice'}),'Remove tag Invoice');
    assert.match(globalThis.o8TranslateSource('Tag „Neu“ existiert noch nicht. Im Tag-Katalog anlegen und diesem Dokument zuordnen?'),/^Tag “Neu” does not exist yet/);
    assert.match(globalThis.o8Translate('dialogs.deleteTagUsed',{name:'Steuer',count:2}),/is assigned to 2 documents/);
    assert.equal(globalThis.o8Translate('navigation.documents'),'Documents');
});

test('DMS search actions and advanced-search captions use shared translations',()=>{
    const view=fs.readFileSync(new URL('../app/views/live-workspace.php',import.meta.url),'utf8');
    for(const key of ['search','details','reset','resetColumns','dateFrom','dateTo','amountFrom','amountTo','invoiceNumbers','accountCode','documentType','resultView','latestCount','tagInclude','tagExclude','sort','recordCount','includeStatus']) assert.match(view,new RegExp(`tr\\('search\\.${key}'\\)`));
    assert.equal(globalThis.o8Translate('search.search'),'Search');
    assert.equal(globalThis.o8Translate('search.resetColumns'),'Reset columns');
});

test('login language picker is placed between credentials and the login action',()=>{
    const view=fs.readFileSync(new URL('../app/views/foundation.php',import.meta.url),'utf8');
    assert.match(view,/<input class="form-control" type="password" name="password"[\s\S]*?<\/form>[\s\S]*?\$languagePickerInline=true; require __DIR__\.'\/language-picker.php'[\s\S]*?form="foundationLoginForm"/);
    assert.match(fs.readFileSync(new URL('../app/views/language-picker.php',import.meta.url),'utf8'),/d-flex align-items-end gap-2/);
});
