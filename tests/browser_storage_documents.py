"""Run after the shared-account browser fixture; all files stay in its disposable directory."""
import os
import re
import sys
import pwd
import grp
import time
from playwright.sync_api import sync_playwright, expect
root=sys.argv[1]
assert re.fullmatch(r'/tmp/o8-m2-test\.[A-Za-z0-9]+/web',root)
BASE='http://127.0.0.1:18089/'
base=os.path.dirname(root)+'/browser-documents'
folder_name='Receipts '+str(time.time_ns())
child_name='Nested '+str(time.time_ns())
tag_name='Test tag '+str(time.time_ns())
tag_name_2='Second tag '+str(time.time_ns())
os.makedirs(base,exist_ok=True)

def form(page,action):
    return page.locator('form:has(input[name="action"][value="'+action+'"])')

def login(page,user,password,tenant='Browser tenant',operator=False):
    page.goto(BASE+('?login=operator' if operator else ''))
    page.locator('[name="login"]').fill(user)
    page.locator('[name="password"]').fill(password)
    page.get_by_role('button',name='Anmelden',exact=True).click()
    if page.get_by_role('heading',name='Mandant auswählen',exact=True).count():
        card=page.locator('form.tenant-card').filter(has=page.get_by_role('heading',name=tenant,exact=True))
        card.get_by_role('button',name='Mandant öffnen').click()

def pdf():
    stream=b'BT /F1 20 Tf 50 700 Td (o8 upload test) Tj ET'
    objects=[b'<< /Type /Catalog /Pages 2 0 R >>',b'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
             b'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 500 750] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
             b'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',b'<< /Length '+str(len(stream)).encode()+b' >>\nstream\n'+stream+b'\nendstream']
    result=b'%PDF-1.4\n'; offsets=[0]
    for i,obj in enumerate(objects,1):
        offsets.append(len(result)); result+=str(i).encode()+b' 0 obj\n'+obj+b'\nendobj\n'
    xref=len(result)
    result+=b'xref\n0 6\n0000000000 65535 f \n'+b''.join(('%010d 00000 n \n'%x).encode() for x in offsets[1:])
    return result+b'trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n'+str(xref).encode()+b'\n%%EOF\n'

with sync_playwright() as p:
    launch={'executable_path':os.environ['O8_TEST_CHROME']} if os.environ.get('O8_TEST_CHROME') else {'channel':'chromium'}
    browser=p.chromium.launch(headless=True,args=['--no-sandbox'],**launch)
    operator=browser.new_page()
    login(operator,'admin','Browser-only-password-456',operator=True)
    operator.get_by_role('link',name='Storage',exact=True).click()
    card=operator.locator('article').filter(has=operator.get_by_role('heading',name=re.compile('^Browser tenant')))
    setup=form(card,'storage_configure')
    if setup.locator('[name="root_path"]').is_editable():
        setup.locator('[name="root_path"]').fill(base)
        setup.locator('[name="linux_owner"]').fill(pwd.getpwuid(os.geteuid()).pw_name)
        setup.locator('[name="linux_group"]').fill(grp.getgrgid(os.getegid()).gr_name)
    setup.locator('[name="confirm"]').check()
    setup.get_by_role('button',name='Prüfen und speichern').click()
    expect(operator.get_by_role('status')).to_contain_text('geprüft und gespeichert')
    page=browser.new_page(viewport={'width':1440,'height':1100})
    errors=[]; failed=[]; console_errors=[]; native_dialogs=[]
    page.on('pageerror',lambda e:errors.append(str(e)))
    page.on('requestfailed',lambda r:failed.append((r.url,r.failure)))
    page.on('console',lambda m:console_errors.append(m.text) if m.type=='error' else None)
    page.on('dialog',lambda d:(native_dialogs.append(d.type),d.dismiss()))
    login(page,'admin','Browser-only-password-456')
    expect(page.get_by_role('button',name='Dokumente hochladen',exact=True)).to_be_enabled()
    assert page.locator('.app-header .header-intake + .workspace-menu').count()==1
    assert page.locator('.demo-strip, .workspace-heading').count()==0
    assert page.locator('#documentWorkspace').bounding_box()['y']+page.locator('#documentWorkspace').bounding_box()['height'] <= 1100
    assert page.evaluate('document.documentElement.scrollHeight <= innerHeight + 2')
    assert page.locator('.header-tools').get_by_role('link',name='Dokumente',exact=True).count()==0
    expect(page.locator('.app-header .brand')).to_have_attribute('href','?section=documents')
    expect(page.locator('.workspace-menu')).to_be_visible()
    assert page.locator('.workspace-menu-panel [data-admin-overlay="users"]').count()==1
    assert page.locator('.workspace-menu-panel [data-admin-overlay="accounting"]').count()==1
    assert page.locator('.workspace-menu-panel [data-admin-overlay="evaluation"]').count()==1
    assert page.locator('.workspace-menu-panel [data-admin-overlay="choose"]').count()==1
    assert page.locator('.workspace-menu-panel [data-admin-overlay="account"]').count()==1
    assert page.locator('.workspace-menu-panel [data-logout-form]').count()==1
    before=page.locator('#documentWorkspace').bounding_box()
    for section,heading in [('choose','Mandant auswählen'),('account','Mein Konto'),('users','Benutzer dieses Mandanten'),('accounting','Buchhaltung'),('evaluation','Buchhaltungsauswertung')]:
        page.locator('.workspace-menu summary').click()
        page.locator('[data-admin-overlay="'+section+'"]').click()
        frame=page.frame_locator('#adminOverlayFrame')
        expect(frame.get_by_role('heading',name=heading,exact=True)).to_be_visible()
        assert page.locator('#documentWorkspace').bounding_box()==before
        page.locator('#adminOverlay .btn-close').click()
        expect(page.locator('#adminOverlay')).not_to_be_visible()
    page.locator('.workspace-menu summary').click()
    page.locator('[data-admin-overlay="settings"]').click()
    settings_frame=page.frame_locator('#adminOverlayFrame')
    expect(settings_frame.get_by_role('heading',name='Mandanten-Sitzung')).to_be_visible()
    settings_frame.locator('input[name="minutes"]').fill('480')
    settings_frame.locator('form:has(input[name="action"][value="session_timeout_save"]) button[type="submit"]').click()
    expect(settings_frame.get_by_role('status')).to_contain_text('Sitzungsdauer')
    page.locator('#adminOverlay .btn-close').click()
    page.locator('.workspace-menu summary').click()
    page.locator('[data-admin-overlay="accounting"]').click()
    frame=page.frame_locator('#adminOverlayFrame')
    frame.locator('[name="framework"]').fill('Browser-Kontenplan')
    frame.locator('[name="vat_rates"]').fill('7\n19')
    frame.locator('[name="accounts"]').fill('4980; Browser-Konto')
    frame.get_by_role('button',name='Buchhaltungs-Setup speichern',exact=True).click()
    expect(frame.get_by_role('status')).to_contain_text('Buchhaltungs-Einstellungen gespeichert')
    page.locator('#adminOverlay .btn-close').click()
    page.locator('.workspace-menu summary').click()
    page.locator('#themeToggle').click()
    page.locator('.workspace-menu summary').click()
    page.locator('[data-admin-overlay="account"]').click()
    expect(page.frame_locator('#adminOverlayFrame').locator('html')).to_have_attribute('data-bs-theme','dark')
    page.locator('#adminOverlay .btn-close').click()
    page.locator('.workspace-menu summary').click()
    page.locator('#themeToggle').click()
    page.locator('.workspace-menu summary').click()
    page.locator('[data-admin-overlay="users"]').click()
    users_frame=page.frame_locator('#adminOverlayFrame')
    users_frame.get_by_text('Benutzer anlegen',exact=True).first.click()
    new_login='overlay_user_'+str(time.time_ns())
    create=users_frame.locator('form:has(input[name="action"][value="user_create"])')
    create.locator('[name="login"]').fill(new_login)
    create.locator('[name="display_name"]').fill('Overlay Test User')
    create.locator('[name="email"]').fill(new_login+'@example.test')
    create.locator('[name="temporary_password"]').fill('secret6')
    create.get_by_role('button',name='Benutzer anlegen',exact=True).click()
    expect(users_frame.get_by_role('heading',name='Overlay Test User Aktiv user')).to_be_visible()
    page.locator('#adminOverlay .btn-close').click()
    member=browser.new_page()
    login(member,new_login,'secret6')
    expect(member.get_by_role('heading',name='Startpasswort ändern')).to_be_visible()
    member.locator('[name="old_password"]').fill('secret6')
    member.locator('[name="new_password"]').fill('secret7')
    member.locator('[name="repeat_password"]').fill('secret7')
    member.get_by_role('button',name='Passwort speichern').click()
    expect(member.locator('#liveApp')).to_be_visible()
    assert member.locator('[data-admin-overlay="users"]').count()==0
    member.locator('.workspace-menu summary').click()
    expect(member.locator('[data-admin-overlay="account"]')).to_be_visible()
    member.locator('[data-admin-overlay="account"]').click()
    expect(member.frame_locator('#adminOverlayFrame').get_by_role('heading',name='Mein Konto',exact=True)).to_be_visible()
    member.locator('#adminOverlay .btn-close').click()
    member.close()
    page.locator('.workspace-menu summary').click()
    expect(page.locator('#themeToggle')).to_be_visible()
    expect(page.locator('#appearanceOpen')).to_be_visible()
    relaxed_button=page.locator('#searchForm button[aria-label="Suchen"]').bounding_box()['height']
    relaxed_folder=page.locator('[data-scope="inbox"]').bounding_box()['height']
    page.locator('#appearanceOpen').click()
    expect(page.locator('#appearanceModal')).to_be_visible()
    page.locator('#appearanceForm [name="density"]').select_option('compact')
    page.locator('#appearanceForm').get_by_role('button',name='Speichern',exact=True).click()
    expect(page.locator('html')).to_have_attribute('data-density','compact')
    assert page.locator('#searchForm button[aria-label="Suchen"]').bounding_box()['height'] < relaxed_button
    assert page.locator('[data-scope="inbox"]').bounding_box()['height'] < relaxed_folder
    typography=page.evaluate('''() => [
        '#liveFilters legend',
        '#liveFilters label:has(input[name="includeExpired"])',
        '#liveFilters label:has(input[name="dateFrom"])',
        '#liveFilters select[name="sort"]',
        '#appearanceForm legend',
        '#appearanceForm label:has(select[name="density"])'
    ].map(selector => {
        const style=getComputedStyle(document.querySelector(selector));
        return [style.fontSize,style.fontFamily];
    })''')
    assert len(set(size for size,family in typography))==1,typography
    assert len(set(family for size,family in typography))==1,typography
    page.locator('#appearanceForm [name="density"]').select_option('comfortable')
    page.locator('#appearanceForm').get_by_role('button',name='Speichern',exact=True).click()
    expect(page.locator('html')).to_have_attribute('data-density','comfortable')
    page.locator('#appearanceModal .btn-close').click()
    expect(page.locator('#appearanceModal')).not_to_be_visible()
    expect(page.locator('#resetColumns')).to_be_visible()
    # Prior failed runs may leave accepted fixtures; this run uploads a fresh inbox file.
    page.get_by_role('button',name='Dokumente hochladen',exact=True).click()
    page.locator('#uploadFiles').set_input_files({'name':'Test <safe>.pdf','mimeType':'application/pdf','buffer':pdf()})
    page.get_by_role('button',name='Hochladen',exact=True).click()
    expect(page.locator('#uploadModal')).not_to_be_visible(timeout=10000)
    expect(page.locator('#documentList')).to_contain_text('Test <safe>.pdf')
    expect(page.locator('#documentId')).to_contain_text('D')
    assert page.locator('safe').count()==0
    docid=int(page.locator('#documentId').inner_text()[1:])
    context=page.locator('#liveApp').get_attribute('data-context')
    original=page.request.get(BASE,params={'api':'file','id':docid,'context':context})
    assert original.status==200 and original.body()==pdf()
    assert original.headers['content-type']=='application/pdf'
    assert original.headers['x-content-type-options']=='nosniff'
    partial=page.request.get(BASE,params={'api':'file','id':docid,'context':context},headers={'Range':'bytes=0-4'})
    assert partial.status==206 and partial.body()==b'%PDF-'
    badrange=page.request.get(BASE,params={'api':'file','id':docid,'context':context},headers={'Range':'bytes=99999999-'})
    assert badrange.status==416
    assert page.request.get(BASE,params={'api':'file','id':docid,'context':'old-context'}).status==409
    # Repeated refreshes must not generate a storm of failed preview loads.
    for _ in range(3):
        with page.expect_response(lambda r:'api=get' in r.url):
            page.locator('#searchForm').get_by_role('button',name='Suchen',exact=True).click()
        expect(page.locator('.live-detail-intro')).to_be_visible()
    page.locator('#documentEditToggle').click()
    expect(page.locator('#documentEditToggle')).to_have_attribute('aria-expanded','true')
    expect(page.locator('.live-detail-actions [data-status="trash"]')).to_be_visible()
    assert page.locator('.live-detail-folders summary').count()==0
    expect(page.locator('.live-detail-folders h3')).to_contain_text('Ordnerverknüpfungen')
    expect(page.locator('#documentForm [name="title"]')).to_be_visible()
    page.locator('#documentForm [name="title"]').fill('Invoice sample')
    page.locator('[data-scope="all"]').click()
    expect(page.locator('#o8ActionDialog')).to_be_visible()
    page.locator('#o8ActionDialog').get_by_role('button',name='Abbrechen').click()
    expect(page.locator('#documentForm [name="title"]')).to_have_value('Invoice sample')
    page.locator('#documentForm [name="sender"]').fill('Fixture sender')
    page.locator('#documentForm [name="date"]').fill('2026-09-01')
    page.locator('#documentForm').get_by_role('button',name='Speichern & übernehmen',exact=True).click()
    expect(page.locator('#resultCount')).to_contain_text('0 Dokumente')
    page.locator('[data-scope="all"]').click()
    expect(page.locator('#documentList')).to_contain_text('Invoice sample')
    doc_row=page.locator('[data-drag-document="'+str(docid)+'"]')
    expect(doc_row).to_have_class(re.compile(r'\bis-active\b'))
    expect(doc_row.locator('time')).to_have_text('01.09.2026')
    expect(page.locator('.live-detail-facts')).to_contain_text('01.09.2026')
    assert page.locator('.live-detail-intro .live-detail-actions').count()==1
    assert page.locator('.live-detail-tags').bounding_box()['y'] < page.locator('.live-detail-folders').bounding_box()['y'] < page.locator('.live-invoice-summary').bounding_box()['y']
    # M2.8: booking data stays on the document and is stored server-side.
    page.locator('[data-invoice]').click()
    expect(page.locator('#invoiceModal')).to_be_visible()
    page.locator('#invoiceSender').fill('Fixture sender')
    page.locator('#invoiceNumber').fill('BK-BROWSER-1')
    page.locator('#invoiceDate').fill('2026-09-17')
    page.locator('#invoiceNet').fill('100.00')
    page.locator('[data-invoice-action="add-tax"]').click()
    page.locator('[data-tax-field="rate"]').select_option('19')
    page.locator('[data-tax-field="amount"]').fill('19.00')
    page.locator('#invoiceForm button[type="submit"]').click()
    expect(page.locator('#invoiceModal')).not_to_be_visible(timeout=10000)
    expect(page.locator('.live-invoice-summary')).to_contain_text('119')
    expect(page.locator('[data-drag-document="'+str(docid)+'"] .doc-gross')).to_have_text('119,00 EUR')
    page.locator('[data-invoice]').click()
    page.locator('#invoiceMode').select_option('partial')
    expect(page.locator('#invoiceGross')).to_be_visible()
    assert page.locator('#invoiceSender').get_attribute('required') is None
    assert page.locator('#invoiceDate').get_attribute('required') is None
    page.locator('#invoiceSender').fill('')
    page.locator('#invoiceNumber').fill('')
    page.locator('#invoiceDate').fill('')
    page.locator('#invoiceGross').fill('119.00')
    page.locator('#invoiceForm button[type="submit"]').click()
    expect(page.locator('#invoiceModal')).not_to_be_visible(timeout=10000)
    expect(page.locator('.live-invoice-summary')).to_contain_text('119')
    page.locator('.workspace-menu summary').click()
    page.locator('[data-admin-overlay="evaluation"]').click()
    frame=page.frame_locator('#adminOverlayFrame')
    assert frame.locator('#evaluationFilter .workspace-tools .search-field').count()==1
    frame.get_by_role('button',name='Detailsuche').click()
    expect(frame.locator('#evaluationDetails')).to_have_class(re.compile(r'\bshow\b'))
    frame.locator('[name="dateFrom"]').fill('2026-09-01')
    frame.locator('[name="dateTo"]').fill('2026-09-30')
    expect(frame.locator('.evaluation-folder-picker')).to_be_visible()
    frame.get_by_role('button',name='Suchen',exact=True).click()
    expect(frame.get_by_role('heading',name='Gesamtsummen')).to_be_visible()
    expect(frame.locator('.evaluation-totals')).to_contain_text('119,00')
    expect(frame.locator('.evaluation-month-list')).to_be_visible()
    expect(frame.locator('.evaluation-month')).to_contain_text('1 Dok.')
    assert page.locator('#documentWorkspace').bounding_box()==before
    page.locator('#adminOverlay .btn-close').click()
    assert page.locator('[data-drag-document="'+str(docid)+'"] .doc-second-line').evaluate('''row => {
        const sender=row.querySelector('.doc-sender').getBoundingClientRect();
        const amount=row.querySelector('.doc-gross').getBoundingClientRect();
        return sender.right <= amount.left && Math.abs(sender.top-amount.top) < 10;
    }''')
    page.locator('#folderCreate').click()
    page.locator('#folderForm [name="name"]').fill(folder_name)
    page.locator('#folderForm').get_by_role('button',name='Speichern',exact=True).click()
    expect(page.locator('#folderModal')).not_to_be_visible()
    folder=page.locator('[data-drop-folder]').filter(has_text=folder_name).get_attribute('data-drop-folder')
    assert page.locator('[data-create-child]').count()==0
    assert page.locator('[data-scope="'+folder+'"] .count').inner_text()=='0'
    page.evaluate('''folder => {
      const source=document.querySelector('[data-drag-document] .doc-open');
      const target=document.querySelector('[data-drop-folder="'+folder+'"]');
      const a=source.getBoundingClientRect(), b=target.getBoundingClientRect();
      source.dispatchEvent(new PointerEvent('pointerdown',{bubbles:true,cancelable:true,pointerId:71,pointerType:'mouse',button:0,clientX:a.left+a.width/2,clientY:a.top+a.height/2}));
      document.dispatchEvent(new PointerEvent('pointermove',{bubbles:true,cancelable:true,pointerId:71,pointerType:'mouse',button:0,clientX:b.left+b.width/2,clientY:b.top+b.height/2}));
    }''',folder)
    expect(page.locator('[data-drop-folder="'+folder+'"]')).to_have_class(re.compile('drop-target'))
    page.evaluate("document.dispatchEvent(new PointerEvent('pointercancel',{bubbles:true,pointerId:71,pointerType:'mouse'}))")
    expect(page.locator('[data-drop-folder="'+folder+'"]')).not_to_have_class(re.compile('drop-target'))
    choices=page.locator('[data-select]')
    assert choices.count()>=2
    choices.nth(0).check(); choices.nth(1).check()
    drag_id=choices.nth(0).get_attribute('data-select')
    page.locator('[data-drag-document="'+drag_id+'"] .doc-open').drag_to(page.locator('[data-drop-folder="'+folder+'"]'))
    expect(page.locator('#o8ActionDialog')).to_be_visible()
    expect(page.locator('#o8ActionDialogMessage')).to_contain_text('2 ausgewählte Dokumente')
    expect(page.locator('#o8ActionDialogMessage')).to_contain_text(folder_name)
    page.locator('#o8ActionDialog').get_by_role('button',name='Abbrechen').click()
    expect(page.locator('#o8ActionDialog')).not_to_be_visible()
    choices.nth(1).uncheck()
    assert page.locator('[data-scope="'+folder+'"] .count').inner_text()=='0'
    # M2.7: use the existing multi-selection and compact bulk editor.
    expect(page.locator('#openBulkEditor')).to_be_enabled()
    page.locator('#openBulkEditor').click()
    expect(page.locator('#bulkEditModal')).to_be_visible()
    page.locator('#bulkFolderAction').select_option('add')
    page.locator('#bulkFolderTarget').select_option(folder)
    page.locator('#bulkEditForm').get_by_role('button',name='Änderungen übernehmen',exact=True).click()
    expect(page.locator('#o8ActionDialog')).to_be_visible()
    page.locator('#o8ActionDialog').get_by_role('button',name='Abbrechen').click()
    expect(page.locator('#bulkEditModal')).to_be_visible()
    expect(page.locator('#bulkFolderTarget')).to_have_value(folder)
    page.locator('#bulkEditForm').get_by_role('button',name='Änderungen übernehmen',exact=True).click()
    expect(page.locator('#o8ActionDialog')).to_be_visible()
    page.locator('#o8ActionDialogConfirm').click()
    expect(page.locator('#bulkEditModal')).not_to_be_visible(timeout=10000)
    expect(page.locator('#openBulkEditor')).to_be_disabled()
    expect(page.locator('.live-detail-folders')).to_be_visible()
    expect(page.locator('#linkButton')).to_be_hidden()
    page.locator('#documentEditToggle').click()
    expect(page.locator('#linkButton')).to_be_visible()
    page.locator('#linkFolder').select_option(folder)
    page.locator('#linkButton').click()
    expect(page.locator('#o8ActionDialog')).to_be_visible()
    page.locator('#o8ActionDialogConfirm').click()
    expect(page.locator('[data-scope="'+folder+'"]')).to_have_class(re.compile('has-selected-document'))
    expect(page.locator('[data-scope="'+folder+'"] .count')).to_have_text('1')
    expect(page.locator('[data-drag-document="'+str(docid)+'"] .doc-meta-count').first).to_contain_text('1')
    page.locator('#searchForm [name="query"]').fill('D'+str(docid))
    page.locator('#searchForm button[aria-label="Suchen"]').click()
    expect(page.locator('[data-scope="'+folder+'"] .count')).to_have_text('1')
    page.locator('#searchForm [name="query"]').fill('kein-treffer-987654')
    page.locator('#searchForm button[aria-label="Suchen"]').click()
    expect(page.locator('[data-scope="'+folder+'"] .count')).to_have_text('0')
    page.locator('#resetSearch').click()
    expect(page.locator('[data-scope="'+folder+'"] .count')).to_have_text('1')
    page.locator('[data-edit-folder="'+folder+'"]').click()
    page.locator('#folderCreateChild').click()
    expect(page.locator('#folderModal')).to_be_visible()
    page.locator('#folderForm [name="name"]').fill(child_name)
    expect(page.locator('#folderParent')).to_have_value(folder)
    page.locator('#folderForm').get_by_role('button',name='Speichern',exact=True).click()
    expect(page.locator('#folderModal')).not_to_be_visible()
    child=page.locator('[data-drop-folder]').filter(has_text=child_name).get_attribute('data-drop-folder')
    page.locator('[data-edit-folder="'+child+'"]').click()
    page.locator('#folderParent').select_option('')
    page.locator('#folderForm').get_by_role('button',name='Speichern',exact=True).click()
    expect(page.locator('#folderModal')).not_to_be_visible()
    expect(page.locator('#tagCreate')).to_be_hidden()
    page.locator('#documentEditToggle').click()
    expect(page.locator('#tagCreate')).to_be_visible()
    page.locator('#tagCreate').click()
    expect(page.locator('#o8ActionDialog')).to_be_visible()
    page.locator('#o8ActionDialogInput').fill(tag_name)
    page.locator('#o8ActionDialogConfirm').click()
    expect(page.locator('#liveNotice')).to_contain_text('Tag angelegt')
    expect(page.get_by_role('option',name=tag_name,exact=True)).to_be_visible()
    page.get_by_role('option',name=tag_name,exact=True).click()
    page.locator('#tagCreate').click()
    expect(page.locator('#o8ActionDialog')).to_be_visible()
    page.locator('#o8ActionDialogInput').fill(tag_name_2)
    page.locator('#o8ActionDialogConfirm').click()
    expect(page.get_by_role('option',name=tag_name_2,exact=True)).to_be_visible()
    expect(page.locator('#liveTagPicker .selected-tags')).to_contain_text(tag_name)
    page.locator('#liveTagPicker input').fill(tag_name_2)
    page.get_by_role('option',name=tag_name_2,exact=True).click()
    page.locator('#documentForm').get_by_role('button',name='Speichern',exact=True).click()
    expect(page.locator('#liveNotice')).to_contain_text('Dokument gespeichert')
    expect(page.locator('[data-drag-document="'+str(docid)+'"]')).to_have_class(re.compile(r'\bis-active\b'))
    assert page.evaluate('''() => {
        const before = document.querySelector('#documentWorkspace').getBoundingClientRect().top;
        document.querySelector('#liveNotice').hidden = true;
        const after = document.querySelector('#documentWorkspace').getBoundingClientRect().top;
        document.querySelector('#liveNotice').hidden = false;
        return before === after;
    }''')
    expect(page.locator('#liveNotice')).to_be_hidden(timeout=4000)
    doc_row=page.locator('[data-drag-document="'+str(docid)+'"]')
    expect(doc_row.locator('.doc-tag-count')).to_have_text('2')
    doc_row.locator('.doc-tag-count').hover()
    expect(page.locator('#docTagsTooltip')).to_contain_text(tag_name)
    expect(page.locator('#docTagsTooltip')).to_contain_text(tag_name_2)
    # Server-backed theme/widths survive reload, with no demo profile involved.
    page.locator('.workspace-menu summary').click()
    page.locator('#appearanceOpen').click()
    for field,value in {'darkAccent':'#d7a86e','darkBackground':'#111827','darkSurface':'#202d3c'}.items():
        page.locator('#appearanceForm [name="'+field+'"]').fill(value)
    page.locator('#appearanceForm').get_by_role('button',name='Speichern',exact=True).click()
    page.locator('#appearanceModal .btn-close').click()
    page.locator('.workspace-menu summary').click()
    page.locator('#themeToggle').click()
    expect(page.locator('html')).to_have_attribute('data-bs-theme','dark')
    page.locator('[data-divider="0"]').focus()
    page.keyboard.press('ArrowRight')
    page.wait_for_timeout(300)
    columns=page.locator('#documentWorkspace').get_attribute('style')
    page.reload()
    expect(page.locator('#uploadOpen')).to_be_enabled()
    expect(page.locator('html')).to_have_attribute('data-bs-theme','dark')
    assert page.locator('#documentWorkspace').get_attribute('style')==columns
    page.locator('[data-scope="all"]').click()
    expect(page.locator('#documentList')).to_contain_text('Invoice sample')
    page.screenshot(path=os.path.dirname(root)+'/documents-desktop.png',full_page=True)
    for width in [390,320]:
        page.set_viewport_size({'width':width,'height':900})
        for pane in ['folders','list','preview','details']:
            page.locator('[data-live-pane="'+pane+'"]').click()
            assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
    page.screenshot(path=os.path.dirname(root)+'/documents-mobile.png',full_page=True)
    page.set_viewport_size({'width':1440,'height':1100})
    page.locator('[data-scope="all"]').click()
    page.locator('[data-document="'+str(docid)+'"]').click()
    expect(page.locator('[data-scope="'+folder+'"] .count')).to_have_text('1')
    trash_before=int(page.locator('[data-scope="trash"] .count').inner_text())
    page.locator('[data-status="trash"]').click()
    expect(page.locator('#o8ActionDialog')).to_be_visible()
    page.locator('#o8ActionDialogConfirm').click()
    expect(page.locator('[data-scope="'+folder+'"] .count')).to_have_text('0')
    expect(page.locator('[data-scope="trash"] .count')).to_have_text(str(trash_before+1))
    page.locator('[data-scope="trash"]').click()
    page.locator('[data-document="'+str(docid)+'"]').click()
    page.locator('[data-status="restore"]').click()
    expect(page.locator('[data-scope="'+folder+'"] .count')).to_have_text('1')
    expect(page.locator('[data-scope="trash"] .count')).to_have_text(str(trash_before))
    palette=page.evaluate('''() => Object.fromEntries(['--o8-accent','--o8-background','--o8-surface'].map(key => [key,getComputedStyle(document.documentElement).getPropertyValue(key).trim()]))''')
    assert palette=={'--o8-accent':'#d7a86e','--o8-background':'#111827','--o8-surface':'#202d3c'},palette
    for section in ['settings','account','users']:
        page.locator('.workspace-menu summary').click()
        page.locator('[data-admin-overlay="'+section+'"]').click()
        frame=page.frame_locator('#adminOverlayFrame')
        expect(frame.locator('html')).to_have_attribute('data-bs-theme','dark')
        overlay_palette=frame.locator('html').evaluate('''root => Object.fromEntries(['--o8-accent','--o8-background','--o8-surface'].map(key => [key,getComputedStyle(root).getPropertyValue(key).trim()]))''')
        assert overlay_palette==palette,(section,overlay_palette,palette)
        page.locator('#adminOverlay .btn-close').click()
    page.goto(BASE+'?section=users')
    expect(page.locator('html')).to_have_attribute('data-bs-theme','dark')
    direct_palette=page.evaluate('''() => Object.fromEntries(['--o8-accent','--o8-background','--o8-surface'].map(key => [key,getComputedStyle(document.documentElement).getPropertyValue(key).trim()]))''')
    assert direct_palette==palette,(direct_palette,palette)
    guest=browser.new_page()
    theme_cookies=[{'name':item['name'],'value':item['value'],'url':BASE} for item in page.context.cookies() if item['name'] in ['o8_theme','o8_theme_mode']]
    guest.context.add_cookies(theme_cookies)
    guest.goto(BASE+'?login=operator')
    expect(guest.locator('html')).to_have_attribute('data-bs-theme','dark')
    login_palette=guest.evaluate('''() => Object.fromEntries(['--o8-accent','--o8-background','--o8-surface'].map(key => [key,getComputedStyle(document.documentElement).getPropertyValue(key).trim()]))''')
    assert login_palette==palette,(login_palette,palette)
    guest.close()
    with page.expect_response(lambda response: 'api=preferences' in response.url) as theme_saved:
        page.locator('#foundationThemeToggle').click()
    assert theme_saved.value.ok
    expect(page.locator('html')).to_have_attribute('data-bs-theme','light')
    page.goto(BASE+'?section=documents')
    expect(page.locator('html')).to_have_attribute('data-bs-theme','light')
    # Another tenant cannot access the same ID, even with its own valid context.
    other=browser.new_page()
    login(other,'member','xyz789','Shared tenant')
    assert other.locator('[data-admin-overlay="accounting"]').count()==0
    assert other.locator('[data-admin-overlay="evaluation"]').count()==0
    othercontext=other.locator('#liveApp').get_attribute('data-context')
    denied=other.request.get(BASE,params={'api':'file','id':docid,'context':othercontext})
    assert denied.status==400 and not denied.json()['success']
    # The operator remains barred from the document API.
    assert operator.request.get(BASE,params={'api':'get','id':docid,'context':context}).status==401
    assert not errors,errors
    assert not console_errors,console_errors
    assert not native_dialogs,native_dialogs
    unexpected=[x for x in failed if 'ERR_ABORTED' not in str(x[1])]
    assert not unexpected,unexpected
    browser.close()
print('PASS document browser: storage assignment, upload, safe rendering, exact/range downloads, context/tenant/operator protection, inbox, metadata, folder links, tag picker, persisted theme/widths and mobile layout.')
