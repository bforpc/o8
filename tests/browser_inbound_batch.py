"""Exercise the real batch modal/components with isolated API fixtures in Chromium."""
import json, os
from pathlib import Path
from playwright.sync_api import sync_playwright, expect
from pdf_preview_fixture import preview_pdf

root=Path(__file__).resolve().parents[1]
modal=next(line for line in (root/'app/views/live-workspace.php').read_text().splitlines() if 'id="inboundBatchModal"' in line)
catalogues={code:json.loads((root/'lang'/f'{code}.json').read_text())['messages'] for code in ['de','en']}
language='de'
html='''<!doctype html><html lang="__LANGUAGE__" data-live="1"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="/assets/vendor/bootstrap/bootstrap.min.css"><link rel="stylesheet" href="/assets/css/app.css"><link rel="stylesheet" href="/assets/css/components.css"><link rel="stylesheet" href="/assets/css/live.css">
<script src="/assets/vendor/bootstrap/bootstrap.bundle.min.js"></script></head><body>'''+modal+'''<button id="open">Mehrfachübernahme</button><script id="o8-language-messages" type="application/json">__LANGUAGE_BUNDLE__</script><script type="module" src="/assets/js/i18n.js"></script><script type="module">
import {InboundBatchDialog} from '/assets/js/live-inbound-batch.js';
import {applyTheme} from '/assets/js/theme.js';
window.applyTheme=applyTheme; window.calls=[]; window.refreshes=0; window.fail=true; window.release=null; window.blockFirst=true;
const rows=[{id:1,revision:2,proposalToken:'first',title:'Berechnete Rechnung',date:'2026-09-21',originalName:'one.pdf',batchEligible:true,batchReasons:[],invoiceAvailable:true,invoiceCalculations:['Netto aus Brutto berechnet.'],matchedTags:[{id:1,name:'Rechnung'}],ignoredTags:['Unbekannt']},
{id:2,revision:2,title:'Unvollständig',batchEligible:false,batchReasons:['KI-Dokumentdatum fehlt.']},
{id:3,revision:2,proposalToken:'third',title:'Weiterer Beleg',originalName:'three.pdf',date:'2026-09-20',batchEligible:true,batchReasons:[],matchedTags:[],ignoredTags:[]}];
const api=async(action,input)=>{window.calls.push({action,input});if(action==='inboundBatchPreview')return structuredClone(rows); if(input.id===1&&window.blockFirst)await new Promise(resolve=>window.release=resolve);if(input.id===3&&window.fail)throw Error('Test: Datei nicht verfügbar');return{id:input.id+100};};
const meta={users:[],tags:[{id:1,name:'Rechnung'},{id:4,name:'Geprüft'}],folders:[{id:9,name:'Ordner',parent_id:null},{id:10,name:'Unterordner',parent_id:9},{id:11,name:'Archiv',parent_id:null},{id:12,name:'Unterordner',parent_id:11}]};
const dialog=new InboundBatchDialog(api,()=>meta,()=>{window.refreshes++;},async reference=>({url:'/preview/'+reference.id+'.pdf',mime:'application/pdf',name:'Beleg '+reference.id}));
document.getElementById('open').onclick=()=>dialog.open(rows.map(({id,revision})=>({id,revision})));
</script></body></html>'''
with sync_playwright() as p:
    browser=p.chromium.launch(executable_path=os.environ.get('O8_TEST_CHROME','/usr/bin/chromium'),headless=True,args=['--no-sandbox'])
    page=browser.new_page(viewport=dict(width=1400,height=1000)); errors=[]
    page.on('pageerror',lambda error:errors.append(str(error)))
    def route(request):
        path=request.request.url.split('http://o8.test',1)[-1]
        if path.startswith('/preview/'):
            request.fulfill(body=preview_pdf(),content_type='application/pdf');return
        if path=='/':
            bundle=json.dumps(dict(language=language,messages=catalogues[language],de=catalogues['de']))
            request.fulfill(body=html.replace('__LANGUAGE__',language).replace('__LANGUAGE_BUNDLE__',bundle),content_type='text/html');return
        asset=root/'public'/path.lstrip('/')
        if not asset.is_file() or not asset.is_relative_to(root/'public'):request.abort();return
        request.fulfill(body=asset.read_bytes(),content_type='text/javascript' if asset.suffix=='.js' else 'text/css')
    page.route('http://o8.test/**',route)
    for theme in ['light','dark']:
        page.goto('http://o8.test/');page.evaluate('(mode)=>window.applyTheme({mode})',theme);page.locator('#open').click()
        modal=page.locator('#inboundBatchModal');expect(modal).to_be_visible()
        expect(modal).not_to_contain_text('Text nicht verfügbar')
        expect(modal.locator('.document-review-preview iframe')).to_have_attribute('src','/preview/1.pdf#view=FitH&navpanes=0')
        modal.locator('[data-batch-preview="3"]').click()
        expect(modal.locator('.document-review-preview iframe')).to_have_attribute('src','/preview/3.pdf#view=FitH&navpanes=0')
        expect(modal.locator('[data-batch-select="3"]')).to_be_checked()
        expect(modal.locator('[data-batch-select="2"]')).to_be_disabled()
        expect(modal.locator('#batchSelectionSummary')).to_have_text('2 ausgewählt · 2 übernahmefähig · 1 gesperrt')
        expect(modal.locator('#batch-reason-2')).to_contain_text('Dokumentdatum fehlt')
        assert modal.locator('#batchSharedFields').bounding_box()['y']<modal.locator('.inbound-batch-list').bounding_box()['y']
        if os.environ.get('O8_TEST_SCREENSHOTS'):
            modal.locator('.document-review-fields').evaluate('(el)=>el.scrollTop=0')
            page.screenshot(path=os.environ['O8_TEST_SCREENSHOTS']+'/batch-head-'+theme+'.png')
        assert modal.locator('[name="folders"]').count()==0
        assert modal.locator('[name="title"], [name="date"], [name="ownerId"]').count()==0
        modal.locator('[data-batch-all]').click();expect(modal.locator('[type="submit"]')).to_be_disabled()
        modal.locator('[data-batch-all]').click();expect(modal.locator('[data-batch-select="2"]')).not_to_be_checked()
        page.wait_for_function('!bootstrap.Modal.getInstance(document.getElementById("inboundBatchModal"))._isTransitioning')
        modal.get_by_role('combobox',name='Gemeinsame Tags suchen').fill('Geprüft');modal.get_by_role('option',name='Geprüft',exact=True).click()
        expect(modal.locator('[data-batch-tags="1"]')).to_contain_text('Rechnung, Geprüft')
        expect(modal.locator('[data-batch-tags="3"]')).to_contain_text('Geprüft')
        modal.locator('[name="tagMode"]').select_option('replace')
        expect(modal.locator('[data-batch-tags="1"]')).not_to_contain_text('Rechnung')
        modal.locator('[data-batch-folders]').click();folders=page.locator('#inboundBatchFoldersModal');expect(folders).to_be_visible();expect(modal).not_to_be_visible()
        expect(folders.locator('.accept-folder-tree > li > ul input[value="10"]')).to_be_visible()
        expect(folders.locator('.accept-folder-tree > li > ul input[value="12"]')).to_be_visible()
        if os.environ.get('O8_TEST_SCREENSHOTS'):
            page.screenshot(path=os.environ['O8_TEST_SCREENSHOTS']+'/batch-folders-'+theme+'.png')
        folders.locator('[name="folders"][value="10"]').check();folders.locator('[name="folders"][value="12"]').check()
        folders.locator('[type="submit"]').click();expect(modal).to_be_visible();expect(folders).not_to_be_visible()
        expect(modal.locator('#batchFolderSummary')).to_contain_text('Ordner / Unterordner')
        expect(modal.locator('#batchFolderSummary')).to_contain_text('Archiv / Unterordner')
        expect(modal.locator('.document-review-preview iframe')).to_have_attribute('src','/preview/3.pdf#view=FitH&navpanes=0')
        modal.locator('[data-batch-folders]').click();expect(folders).to_be_visible()
        expect(folders.locator('[value="10"]')).to_be_checked();expect(folders.locator('[value="12"]')).to_be_checked()
        folders.locator('[value="10"]').uncheck();folders.locator('[value="9"]').check();folders.locator('.btn-close').click();expect(modal).to_be_visible()
        expect(modal.locator('#batchFolderSummary .tag')).to_have_count(2)
        expect(modal.locator('#batchFolderSummary')).to_contain_text('Ordner / Unterordner')
        expect(modal.locator('[name="tagMode"]')).to_have_value('replace')
        expect(modal.locator('#batchTagPicker .selected-tag')).to_contain_text('Geprüft')
        assert page.evaluate('window.calls.every(call=>call.action==="inboundBatchPreview")')
        if os.environ.get('O8_TEST_SCREENSHOTS'):
            page.screenshot(path=os.environ['O8_TEST_SCREENSHOTS']+'/batch-shared-'+theme+'.png')
        modal.locator('[type="submit"]').click();page.wait_for_function('window.release!==null')
        expect(modal.locator('[data-bs-dismiss]').first).to_be_disabled()
        modal.locator('[data-batch-stop]').click();page.evaluate('window.release()')
        expect(modal.locator('#batchProgressText')).to_contain_text('1 übernommen · 0 fehlgeschlagen · 1 nicht verarbeitet')
        expect(modal.locator('[data-batch-select="1"]')).to_be_disabled()
        modal.locator('[type="submit"]').click();expect(modal.locator('[data-batch-result="3"]')).to_contain_text('Test: Datei nicht verfügbar')
        expect(modal.locator('[data-batch-select="3"]')).to_be_enabled()
        page.evaluate('window.fail=false');modal.locator('[type="submit"]').click()
        expect(modal.locator('[data-batch-result="3"]')).to_have_text('Übernommen als D103')
        expect(modal.locator('.document-review-preview iframe')).to_have_attribute('src','/preview/103.pdf#view=FitH&navpanes=0')
        expect(modal.locator('[type="submit"]')).to_be_disabled()
        calls=page.evaluate('window.calls.filter(x=>x.action==="inboundBatchAccept")')
        assert [call['input']['id'] for call in calls]==[1,3,3]
        for call in calls:assert json.loads(call['input']['shared'])==dict(tagMode='replace',tags=[4],folders=[10,12])
        assert page.evaluate('window.refreshes')==3
        expect(modal).not_to_be_visible()
    language='en';page.goto('http://o8.test/');page.evaluate('window.blockFirst=false;window.fail=false');page.locator('#open').click()
    modal=page.locator('#inboundBatchModal');expect(modal).to_be_visible()
    expect(modal).not_to_contain_text('Text not available')
    expect(modal.locator('[name="tagMode"]')).to_have_value('add')
    expect(modal.locator('#batchFolderSummary')).to_contain_text('Without a target folder')
    modal.locator('[data-batch-select="3"]').uncheck()
    modal.get_by_role('combobox',name='Search shared tags').fill('Geprüft');modal.get_by_role('option',name='Geprüft',exact=True).click()
    modal.get_by_role('button',name='Select folders …',exact=True).click();folders=page.locator('#inboundBatchFoldersModal');expect(folders).to_be_visible()
    folders.locator('[value="9"]').check();folders.locator('[value="10"]').check();folders.get_by_role('button',name='Apply selection',exact=True).click();expect(modal).to_be_visible()
    modal.locator('[type="submit"]').click();expect(modal).not_to_be_visible()
    calls=page.evaluate('window.calls.filter(x=>x.action==="inboundBatchAccept")')
    assert len(calls)==1 and calls[0]['input']['id']==1
    assert json.loads(calls[0]['input']['shared'])==dict(tagMode='add',tags=[4],folders=[9,10])
    for theme in ['light','dark']:
        page.set_viewport_size(dict(width=390,height=844));page.goto('http://o8.test/');page.evaluate('(mode)=>window.applyTheme({mode})',theme);page.locator('#open').click();expect(modal).to_be_visible()
        modal.get_by_role('button',name='Select folders …',exact=True).click();expect(folders).to_be_visible()
        folders.locator('[value="10"]').check();folders.get_by_role('button',name='Apply selection',exact=True).click();expect(modal).to_be_visible()
        expect(modal.locator('#batchFolderSummary')).to_contain_text('Ordner / Unterordner')
        assert page.evaluate('document.documentElement.scrollWidth<=innerWidth')
        assert modal.locator('.document-review-fields').evaluate('(el)=>el.scrollWidth<=el.clientWidth')
        modal.get_by_role('button',name='Select folders …',exact=True).click();expect(folders).to_be_visible()
        folders.locator('[value="10"]').uncheck();folders.get_by_role('button',name='Apply selection',exact=True).click();expect(modal).to_be_visible()
        expect(modal.locator('#batchFolderSummary')).to_contain_text('Without a target folder')
    assert not errors,errors
    print('PASS batch UI: eligibility, common fields, tree, tags, progress, stop, partial failure/retry, Light/Dark/mobile')
    browser.close()
