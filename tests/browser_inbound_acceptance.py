"""Real Chromium/Bootstrap dialog interaction; API fixture, no production writes."""
import json
import os
from pathlib import Path
from playwright.sync_api import sync_playwright, expect
from pdf_preview_fixture import preview_pdf

root = Path(__file__).resolve().parents[1]
view = (root / 'app/views/live-workspace.php').read_text()
modals = '\n'.join(line for line in view.splitlines() if 'id="inboundAcceptModal"' in line or 'id="invoiceModal"' in line)
proposal = dict(id=7, revision=3, proposalToken='fixture-snapshot', ownerId=1, title='KI-Titel', sender='Testfirma', date='2026-09-21',
                documentType='invoice', reference='KI-42', memo='', matchedTags=[dict(id=1,name='Rechnung')],
                aiTags=['rechnung','Rechnungen'], ignoredTags=['Rechnungen'], warning='', invoiceAvailable=True, invoiceComplete=True, partialInvoice=None, invoiceWarning='', amounts=dict(netto='100.00',mwst='19.00',brutto='119.00',waehrung='EUR'),
                invoice=dict(sender='Testfirma',number='KI-42',date='2026-09-21',currency='EUR',mode='totals',accountId='',items=[],net='100.00',taxes=[dict(rate='19',amount='19.00')]))
metadata = dict(tags=[dict(id=1,name='Rechnung'),dict(id=2,name='Manuell')], users=[dict(id=1,display_name='Admin')],
                folders=[dict(id=10,name='Kind',parent_id=9),dict(id=9,name='Ziel',parent_id=None)], accounting=dict(accounts=[],vatRates=['7','19']))
html = '''<!doctype html><html lang="de"><head><link rel="stylesheet" href="/assets/vendor/bootstrap/bootstrap.min.css"><link rel="stylesheet" href="/assets/css/app.css"><link rel="stylesheet" href="/assets/css/components.css"><link rel="stylesheet" href="/assets/css/live.css"><script src="/assets/vendor/bootstrap/bootstrap.bundle.min.js"></script></head><body>'''+modals+'''<button id="open">Übernahme</button><script type="module">
import {InboundAcceptanceDialog} from '/assets/js/live-inbound-acceptance.js';
import {LiveInvoiceEditor} from '/assets/js/live-invoice.js';
import {applyTheme} from '/assets/js/theme.js';
window.applyTheme=applyTheme;
window.calls=[]; window.saved=[]; window.fail=false;
const api=async(action,input,write)=>{window.calls.push({action,input,write}); if(action==='inboundProposal')return PROPOSAL; if(window.fail)throw Error('Test: Zielordner nicht verfügbar'); return {id:42};};
const preview=async reference=>{if(window.previewFailure)throw Error('Test: Datei nicht lesbar');return {url:'/preview/'+reference.id+'.pdf',mime:'application/pdf',name:'Rechnung.pdf'};};
const editor=new LiveInvoiceEditor(api,()=>{},preview);
const dialog=new InboundAcceptanceDialog(api,()=>(METADATA),editor,id=>window.saved.push(id),preview);
window.acceptanceDialog=dialog;
document.getElementById('open').onclick=()=>dialog.open(7);
</script></body></html>'''
html = html.replace('PROPOSAL',json.dumps(proposal)).replace('METADATA',json.dumps(metadata))

with sync_playwright() as p:
    browser=p.chromium.launch(executable_path=os.environ.get('O8_TEST_CHROME','/usr/bin/chromium'),headless=True,args=['--no-sandbox'])
    page=browser.new_page(viewport=dict(width=1440,height=1000)); errors=[]
    page.on('pageerror',lambda e:(errors.append(str(e)),print('Browser error:',e)))
    def route(request):
        path=request.request.url.split('http://o8.test',1)[-1]
        if path.startswith('/preview/'):
            request.fulfill(body=preview_pdf(),content_type='application/pdf'); return
        if path=='/': request.fulfill(body=html,content_type='text/html'); return
        asset=root/'public'/path.lstrip('/')
        if not asset.is_file() or not asset.is_relative_to(root/'public'): request.abort(); return
        request.fulfill(body=asset.read_bytes(),content_type='text/javascript' if asset.suffix=='.js' else 'text/css')
    page.route('http://o8.test/**',route); page.goto('http://o8.test/')
    for theme in ['light','dark']:
        page.evaluate('(theme)=>window.applyTheme({mode:theme})',theme)
        page.locator('#open').click(); modal=page.locator('#inboundAcceptModal'); expect(modal).to_be_visible()
        expect(modal.locator('[name="title"]')).to_have_value('KI-Titel')
        expect(modal.locator('.accept-tag-info')).not_to_have_attribute('open','')
        expect(modal.locator('#acceptTagPicker .selected-tag')).to_contain_text('Rechnung')
        modal.locator('.accept-tag-info summary').click()
        expect(modal.locator('#acceptAiTags')).to_contain_text('rechnung')
        expect(modal.locator('#acceptAiTags')).to_contain_text('Rechnungen')
        modal.locator('.accept-tag-info summary').click()
        expect(modal.locator('.document-review-preview iframe')).to_have_attribute('src','/preview/7.pdf#view=FitH&navpanes=0')
        preview_box=modal.locator('.document-review-preview').bounding_box()
        fields_box=modal.locator('.document-review-fields').bounding_box()
        assert preview_box['x']+preview_box['width']<=fields_box['x']
        assert abs(preview_box['y']-fields_box['y'])<2
        assert preview_box['height']>400
        assert modal.locator('.document-review-fields').evaluate('(el)=>el.scrollWidth<=el.clientWidth')
        if os.environ.get('O8_TEST_SCREENSHOTS'):
            page.screenshot(path=os.environ['O8_TEST_SCREENSHOTS']+'/acceptance-'+theme+'.png')
        expect(modal.locator('#acceptTagPicker .selected-tag')).to_contain_text('Rechnung')
        expect(modal.locator('.accept-folder-tree > li > ul input[value="10"]')).to_be_visible()
        expect(modal.locator('#acceptInvoiceSummary')).to_contain_text('Automatisch zur Übernahme: KI-42')
        modal.locator('[type="submit"]').click(); expect(modal).not_to_be_visible()
        automatic=json.loads(page.evaluate('window.calls.filter(x=>x.action==="inboundAccept").at(-1).input.input'))
        assert automatic['invoice']['net']=='100.00' and automatic['invoice']['taxes'][0]['rate']=='19'
        assert automatic['tagMode']=='replace' and automatic['tags']==[1]
        page.evaluate('window.calls=[]'); page.locator('#open').click(); expect(modal).to_be_visible()
        label=modal.locator('[name="title"]').locator('..')
        assert label.evaluate('(el)=>parseFloat(getComputedStyle(el).fontSize)<parseFloat(getComputedStyle(el.querySelector("input")).fontSize)')
        assert label.evaluate('(el)=>getComputedStyle(el).color!==getComputedStyle(el.querySelector("input")).color')
        modal.locator('[data-accept-invoice]').click(); invoice=page.locator('#invoiceModal'); expect(invoice).to_be_visible(); expect(modal).not_to_be_visible()
        expect(invoice.locator('.document-review-preview iframe')).to_have_attribute('src','/preview/7.pdf#view=FitH&navpanes=0')
        assert invoice.locator('.document-review-preview').bounding_box()['x']<invoice.locator('#invoiceEditor').bounding_box()['x']
        if os.environ.get('O8_TEST_SCREENSHOTS'):
            page.screenshot(path=os.environ['O8_TEST_SCREENSHOTS']+'/booking-'+theme+'.png')
        expect(invoice.locator('[data-tax-field="rate"]')).to_have_value('19')
        net_box=invoice.locator('#invoiceNet').bounding_box()
        rate_box=invoice.locator('[data-tax-field="rate"]').first.bounding_box()
        tax_box=invoice.locator('[data-tax-field="amount"]').first.bounding_box()
        assert abs(net_box['y']-rate_box['y'])<2, (net_box,rate_box)
        assert abs(net_box['y']-tax_box['y'])<2, (net_box,tax_box)
        invoice.locator('[data-tax-field="amount"]').fill('unbekannt')
        invoice.locator('[type="submit"]').click(); expect(invoice.locator('#invoiceError')).to_contain_text('MWSt-Betrag')
        assert not page.evaluate('window.calls.some(x=>x.write)'), 'Draft must not write before acceptance'
        invoice.locator('[data-tax-field="amount"]').fill('19.00')
        invoice.locator('[type="submit"]').click(); expect(invoice).not_to_be_visible(); expect(modal).to_be_visible()
        expect(modal.locator('#acceptInvoiceSummary')).to_contain_text('119,00')
        assert not page.evaluate('window.calls.some(x=>x.action==="invoice")'), 'No early booking API write'
        modal.get_by_role('button',name='Tag Rechnung entfernen').click()
        expect(modal.locator('#acceptTagPicker .selected-tag')).to_have_count(0)
        page.wait_for_function('!bootstrap.Modal.getInstance(document.getElementById("inboundAcceptModal"))._isTransitioning')
        modal.get_by_role('combobox',name='Tags suchen und auswählen').fill('Manuell')
        modal.get_by_role('option',name='Manuell',exact=True).click()
        expect(modal.locator('#acceptTagPicker .selected-tag')).to_contain_text('Manuell')
        modal.locator('[name="folders"][value="10"]').check()
        page.evaluate('window.fail=true'); modal.locator('[type="submit"]').click(); expect(modal.locator('#acceptError')).to_contain_text('Zielordner')
        expect(modal).to_be_visible(); expect(modal.locator('[name="title"]')).to_have_value('KI-Titel')
        page.evaluate('window.fail=false'); modal.locator('[type="submit"]').click(); expect(modal).not_to_be_visible()
        payload=json.loads(page.evaluate('window.calls.filter(x=>x.action==="inboundAccept").at(-1).input.input'))
        assert payload['tagMode']=='replace' and payload['folders']==[10] and payload['tags']==[2] and payload['invoice']['taxes'][0]['rate']=='19'
        page.evaluate('window.calls=[]')
    assert page.evaluate('window.saved')==[42,42,42,42]
    assert not errors, errors
    for width in [2560,3440]:
        page.set_viewport_size(dict(width=width,height=1000));page.locator('#open').click()
        modal=page.locator('#inboundAcceptModal');expect(modal).to_be_visible()
        box=modal.locator('.modal-dialog').bounding_box()
        assert box['x']<=20 and box['width']>=width-40, box
        modal.locator('.btn-close').click();expect(modal).not_to_be_visible()
    page.set_viewport_size(dict(width=390,height=844)); page.locator('#open').click()
    expect(page.locator('#inboundAcceptModal')).to_be_visible()
    expect(page.locator('#inboundAcceptModal .document-review-preview iframe')).to_be_visible()
    preview_box=page.locator('#inboundAcceptModal .document-review-preview').bounding_box()
    fields_box=page.locator('#inboundAcceptModal .document-review-fields').bounding_box()
    assert preview_box['y']+preview_box['height']<=fields_box['y']
    assert page.evaluate('document.documentElement.scrollWidth<=window.innerWidth')
    page.locator('#inboundAcceptModal .btn-close').click(); expect(page.locator('#inboundAcceptModal')).not_to_be_visible()
    page.wait_for_function('!document.querySelector("#inboundAcceptModal iframe")')
    page.evaluate('window.previewFailure=true');page.locator('#open').click()
    expect(page.locator('#inboundAcceptModal .document-review-preview')).to_contain_text('Datei nicht lesbar')
    expect(page.locator('#inboundAcceptModal [type="submit"]')).to_be_enabled()
    page.locator('#inboundAcceptModal .btn-close').click();expect(page.locator('#inboundAcceptModal')).not_to_be_visible()
    page.evaluate('window.previewFailure=false');page.locator('#open').click()
    modal=page.locator('#inboundAcceptModal');expect(modal).to_be_visible()
    modal.locator('[name="reference"]').fill('')
    modal.locator('[data-accept-invoice]').click();invoice=page.locator('#invoiceModal');expect(invoice).to_be_visible()
    expect(invoice.locator('#invoiceNumber')).to_have_value('')
    assert not invoice.locator('#invoiceNumber').evaluate('(el)=>el.required')
    invoice.locator('[type="submit"]').click();expect(invoice).not_to_be_visible();expect(modal).to_be_visible()
    modal.locator('[type="submit"]').click();expect(modal).not_to_be_visible()
    without_number=json.loads(page.evaluate('window.calls.filter(x=>x.action==="inboundAccept").at(-1).input.input'))
    assert without_number['invoice']['number']=='' and without_number['invoice']['net']=='100.00'
    # Common captions must also win over the existing administration and DMS rules.
    page.add_style_tag(content=(root/'public/assets/css/foundation.css').read_text())
    page.evaluate('''() => {document.documentElement.dataset.live='1'; const sample=document.createElement('div'); sample.id='captionSamples'; sample.innerHTML=`
      <form class="foundation-page"><label class="form-label">Benutzer<input class="form-control" value="Lesbarer Wert"></label></form>
      <form id="liveFilters"><div class="advanced-search-grid"><label>Datum<input class="form-control" type="date"></label></div></form>
      <form id="appearanceForm"><label>Schrift<select class="form-select"><option>System</option></select></label></form>
      <div class="details-content"><form><label>Titel<input class="form-control"></label></form></div>`; document.body.append(sample); }''')
    for theme in ['light','dark']:
        for density in ['comfortable','compact']:
            page.evaluate('([mode,density])=>window.applyTheme({mode,density})',[theme,density])
            captions=page.locator('#captionSamples label').evaluate_all('(nodes)=>nodes.map(el=>({size:getComputedStyle(el).fontSize,color:getComputedStyle(el).color,weight:getComputedStyle(el).fontWeight}))')
            assert all(caption==captions[0] for caption in captions), captions
            assert captions[0]['size']==('13.75px' if density=='comfortable' else '12.5px'), captions
            assert captions[0]['weight']=='400'
    page.evaluate('window.acceptanceDialog.open(7,10)')
    modal=page.locator('#inboundAcceptModal');expect(modal).to_be_visible()
    expect(modal.locator('#inboundAcceptTitle')).to_have_text('Aus Eingang in Ordner verschieben')
    expect(modal.locator('.modal-footer [type="submit"]')).to_have_text('Verschieben')
    expect(modal.locator('[name="folders"][value="10"]')).to_be_checked()
    expect(modal.locator('[name="folders"][value="9"]')).not_to_be_checked()
    modal.locator('.btn-close').click();expect(modal).not_to_be_visible()
    print('PASS acceptance dialog: Light/Dark, mobile, draft booking, folder drop preselection, validation, error retention, payload, close')
    browser.close()
