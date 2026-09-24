"""After foundation + administration browser tests; disposable fixture only."""
import re
import sys
from playwright.sync_api import sync_playwright, expect

assert re.fullmatch(r'/tmp/o8-m2-test\.[A-Za-z0-9]+/web',sys.argv[1])
BASE='http://127.0.0.1:18089/'

def form(page, action):
    return page.locator('form:has(input[name="action"][value="'+action+'"])')

with sync_playwright() as p:
    browser=p.chromium.launch(headless=True,args=['--no-sandbox'])
    page=browser.new_page()
    errors=[]
    page.on('pageerror',lambda error:errors.append(str(error)))
    page.goto(BASE+"?login=operator")
    page.locator('[name="login"]').fill('admin')
    page.locator('[name="password"]').fill('Browser-only-password-456')
    page.get_by_role('button',name='Anmelden',exact=True).click()
    target=page.locator('article').filter(has=page.get_by_role('heading',name='Second tenant Aktiv',exact=True))
    tenant_id=target.get_attribute('data-tenant-id')
    target.get_by_role('button',name='Mandant endgültig löschen …',exact=True).click()
    expect(page.get_by_role('heading',name='Mandant endgültig löschen – Bestätigung 1 von 2')).to_be_visible()
    first=form(page,'tenant_delete_confirm')
    # A forged final request cannot skip the first confirmation.
    forged=page.request.post(page.url,form={'action':'tenant_delete_start','id':tenant_id,'csrf':first.locator('[name="csrf"]').input_value(),'delete_token':first.locator('[name="delete_token"]').input_value(),'tenant_name':'Second tenant','confirm':'yes'})
    assert 'Löschbestätigung fehlt' in forged.text()
    page.get_by_role('button',name='Abbrechen – nichts löschen').click()
    expect(page.get_by_role('heading',name='Second tenant Aktiv',exact=True)).to_be_visible()
    target=page.locator('article').filter(has=page.get_by_role('heading',name='Second tenant Aktiv',exact=True))
    target.get_by_role('button',name='Mandant endgültig löschen …',exact=True).click()
    first=form(page,'tenant_delete_confirm')
    first.locator('[name="confirm"]').check()
    first.get_by_role('button',name='Weiter zur zweiten Bestätigung').click()
    expect(page.get_by_role('heading',name='Mandant endgültig löschen – Bestätigung 2 von 2')).to_be_visible()
    second=form(page,'tenant_delete_start')
    second.locator('[name="tenant_name"]').fill('Wrong tenant')
    second.locator('[name="confirm"]').check()
    second.get_by_role('button',name='Jetzt endgültig löschen').click()
    assert 'Mandantennamen exakt' in page.get_by_role('alert').first.inner_text()
    second=form(page,'tenant_delete_start')
    # Correct name without the second checkbox is also rejected server-side.
    denied=page.request.post(page.url,form={'action':'tenant_delete_start','id':tenant_id,'csrf':second.locator('[name="csrf"]').input_value(),'delete_token':second.locator('[name="delete_token"]').input_value(),'tenant_name':'Second tenant'})
    assert 'ausdrücklich bestätigen' in denied.text()
    for width in [390,1280]:
        page.set_viewport_size({'width':width,'height':900})
        assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
    # Pause automatic progression to verify that reload/resume does not need a new confirmation.
    page.route('**/assets/js/tenant-delete.js',lambda route: route.fulfill(content_type='application/javascript',body=''))
    second.locator('[name="tenant_name"]').fill('Second tenant')
    second.locator('[name="confirm"]').check()
    second.get_by_role('button',name='Jetzt endgültig löschen').click()
    expect(page.get_by_role('heading',name='Mandant wird endgültig gelöscht')).to_be_visible()
    page.goto(BASE+'?section=tenants')
    target=page.locator('article[data-tenant-id="'+tenant_id+'"]')
    expect(target.get_by_role('link',name='Löschung fortsetzen / Fortschritt')).to_be_visible()
    assert target.get_by_text('Mandant bearbeiten',exact=True).count()==0
    page.unroute('**/assets/js/tenant-delete.js')
    target.get_by_role('link',name='Löschung fortsetzen / Fortschritt').click()
    expect(page.locator('.alert-success')).to_contain_text('endgültig gelöscht',timeout=30000)
    assert page.locator('article[data-tenant-id="'+tenant_id+'"]').count()==0
    expect(page.get_by_role('heading',name='Browser tenant Aktiv',exact=True)).to_be_visible()
    assert not errors,errors
    browser.close()
print('PASS: two server-enforced confirmations, name check, cancellation, missing checkbox rejection, mobile layout, interruption/resume, automatic bounded processing and surviving second tenant.')
