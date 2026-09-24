"""Run after foundation/administration (and optional style/deletion) on their disposable fixture."""
import re
import sys
from playwright.sync_api import sync_playwright, expect

assert re.fullmatch(r'/tmp/o8-m2-test\.[A-Za-z0-9]+/web',sys.argv[1])
BASE='http://127.0.0.1:18089/'

def form(page, action):
    return page.locator('form:has(input[name="action"][value="'+action+'"])')

def login(page, user, password, operator=False):
    page.goto(BASE+('?login=operator' if operator else ''))
    page.locator('[name="login"]').fill(user)
    page.locator('[name="password"]').fill(password)
    page.get_by_role('button',name='Anmelden',exact=True).click()

with sync_playwright() as p:
    browser=p.chromium.launch(headless=True,args=['--no-sandbox'])
    errors=[]
    context=browser.new_context()
    public=context.new_page()
    public.on('pageerror',lambda e:errors.append(str(e)))
    public.goto(BASE)
    assert public.locator('[name="tenant"],[name="tenant_id"]').count()==0
    assert 'Browser tenant' not in public.content()
    assert public.get_by_role('link',name='Betreiber-Anmeldung – Mandantenverwaltung').is_visible()
    operator=browser.new_page()
    login(operator,'admin','Browser-only-password-456',True)
    if operator.get_by_role('heading',name='Shared tenant Aktiv',exact=True).count()==0:
        operator.get_by_text('Neuen Mandanten anlegen',exact=True).click()
        create=form(operator,'tenant_create')
        create.locator('[name="tenant_name"]').fill('Shared tenant')
        create.locator('[name="admin_mode"]').select_option('existing')
        assert create.locator('[name="login"]').is_disabled()
        create.locator('[name="existing_login"]').fill('browser@example.test')
        create.get_by_role('button',name='Mandant anlegen',exact=True).click()
    expect(operator.get_by_role('heading',name='Shared tenant Aktiv',exact=True)).to_be_visible()
    login(public,'browser@example.test','Browser-only-password-456')
    expect(public.get_by_role('heading',name='Mandant auswählen',exact=True)).to_be_visible()
    cards=public.locator('form.tenant-card')
    assert cards.count()==2
    shared=cards.filter(has=public.get_by_role('heading',name='Shared tenant',exact=True))
    shared.get_by_role('button',name='Mandant öffnen',exact=True).click()
    expect(public.locator('.foundation-context')).to_contain_text('Mandant: Shared tenant')
    public.get_by_role('link',name='Benutzer',exact=True).click()
    select=form(public,'tenant_switch').locator('[name="tenant_id"]')
    choices=select.locator('option').evaluate_all('(items)=>items.map(x=>({id:x.value,name:x.textContent.trim()}))')
    first_id=next(x['id'] for x in choices if x['name']=='Browser tenant')
    shared_id=next(x['id'] for x in choices if x['name']=='Shared tenant')
    # Open a second tab and retain a form from the old context.
    stale=public.context.new_page()
    stale.goto(BASE)
    old_csrf=stale.locator('[name="csrf"]').first.input_value()
    old_context=stale.locator('[name="context_token"]').first.input_value()
    public.get_by_text('Benutzer anlegen',exact=True).first.click()
    form(public,'user_create').locator('[name="login"]').fill('unsaved')
    select.select_option(first_id)
    public.once('dialog',lambda dialog:dialog.dismiss())
    form(public,'tenant_switch').get_by_role('button',name='Wechseln',exact=True).click()
    expect(public.locator('.foundation-context')).to_contain_text('Mandant: Shared tenant')
    public.once('dialog',lambda dialog:dialog.accept())
    form(public,'tenant_switch').get_by_role('button',name='Wechseln',exact=True).click()
    expect(public.locator('.foundation-context')).to_contain_text('Mandant: Browser tenant')
    denied=stale.request.post(BASE,form={'action':'user_invite','role':'admin','csrf':old_csrf,'context_token':old_context})
    assert 'Sitzung abgelaufen' in denied.text()
    csrf=public.locator('[name="csrf"]').first.input_value()
    denied=public.request.post(BASE,form={'action':'user_invite','role':'admin','csrf':csrf,'context_token':old_context})
    assert 'Mandantenkontext geändert' in denied.text()
    # Invite a second existing account; no global directory exposed to tenant admins.
    select=form(public,'tenant_switch').locator('[name="tenant_id"]')
    select.select_option(shared_id)
    form(public,'tenant_switch').get_by_role('button',name='Wechseln',exact=True).click()
    public.get_by_role('link',name='Benutzer',exact=True).click()
    public.get_by_text('Bestehendes Benutzerkonto einladen',exact=True).click()
    form(public,'user_invite').get_by_role('button',name='Einladungscode erstellen').click()
    code=public.locator('input[readonly]').input_value()
    assert re.fullmatch('[a-f0-9]{48}',code)
    member=browser.new_page()
    login(member,'member@example.test','xyz789')
    expect(member.locator('.foundation-context')).to_contain_text('Mandant: Browser tenant')
    member.get_by_role('link',name='Mein Konto',exact=True).click()
    member.get_by_text('Einladung zu einem weiteren Mandanten annehmen',exact=True).click()
    form(member,'invitation_accept').locator('[name="invitation"]').fill(code)
    form(member,'invitation_accept').get_by_role('button',name='Einladung annehmen',exact=True).click()
    expect(member.locator('.foundation-context')).to_contain_text('Mandant: Shared tenant · Rolle: user')
    assert member.get_by_role('link',name='Benutzer',exact=True).count()==0
    assert form(member,'tenant_switch').locator('option').count()==2
    for width in [390,1280]:
        member.set_viewport_size({'width':width,'height':1000})
        assert member.evaluate('document.documentElement.scrollWidth <= innerWidth')
    member.screenshot(path=sys.argv[1]+'/shared-account-desktop.png',full_page=True)
    member.set_viewport_size({'width':390,'height':900})
    member.screenshot(path=sys.argv[1]+'/shared-account-mobile.png',full_page=True)
    assert not errors,errors
    browser.close()
print('PASS shared-account browser: private tenant list, automatic/successive selection, existing first admin, confirmed dirty switch, stale-tab CSRF/context rejection, invitation acceptance, independent roles, responsive layout.')
