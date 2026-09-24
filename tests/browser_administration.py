"""After browser_foundation.py, using only its disposable local fixture."""
import re
import sys
from playwright.sync_api import sync_playwright

assert re.fullmatch(r'/tmp/o8-m2-test\.[A-Za-z0-9]+/web', sys.argv[1])
BASE = 'http://127.0.0.1:18089/'

def form(page, action):
    return page.locator('form:has(input[name="action"][value="'+action+'"])')

def login(page, kind, password, tenant='', user='admin'):
    page.goto(BASE + ('?login=operator' if kind == 'operator' else ''))
    page.locator('[name="login"]').fill(user)
    page.locator('[name="password"]').fill(password)
    page.get_by_role('button', name='Anmelden', exact=True).click()

with sync_playwright() as p:
    browser = p.chromium.launch(headless=True, args=['--no-sandbox'])
    operator = browser.new_page()
    errors=[]
    operator.on('pageerror', lambda e: errors.append(str(e)))
    login(operator,'operator','Browser-only-password-456')
    assert operator.get_by_role('link',name='Mandanten',exact=True).is_visible()
    assert operator.get_by_role('link',name='Benutzer',exact=True).count()==0
    operator.get_by_text('Neuen Mandanten anlegen',exact=True).click()
    create=form(operator,'tenant_create')
    for key,value in {'tenant_name':'Second tenant','login':'second','display_name':'Second Admin','email':'second@example.test','temporary_password':'secret6'}.items():
        create.locator('[name="'+key+'"]').fill(value)
    create.get_by_role('button',name='Mandant anlegen',exact=True).click()
    assert operator.get_by_role('heading',name='Second tenant Aktiv',exact=True).is_visible()
    first=operator.locator('article').filter(has=operator.get_by_role('heading',name='Browser tenant Aktiv',exact=True))
    uuid=first.locator('.text-break').inner_text()
    first.get_by_role('button',name='Abmelden und zum Mandanten-Login').click()
    assert operator.locator('[name="kind"]').input_value()=='account'
    assert operator.locator('[name="tenant"]').count()==0
    operator.locator('[name="login"]').fill('admin')
    operator.locator('[name="password"]').fill('Browser-only-password-456')
    operator.get_by_role('button',name='Anmelden',exact=True).click()
    admin=operator
    admin.get_by_role('link',name='Benutzer',exact=True).click()
    assert admin.get_by_role('link',name='Benutzer',exact=True).is_visible()
    assert admin.get_by_role('link',name='Mandanten',exact=True).count()==0
    assert admin.locator('nav.nav-pills').count()==0
    # Last-admin protection is enforced despite the submitted confirmation.
    own=admin.locator('article').first
    own.get_by_text('Benutzer bearbeiten',exact=True).click()
    own.locator('[name="role"]').select_option('user')
    own.locator('[name="confirm"]').check()
    own.get_by_role('button',name='Benutzer speichern').click()
    assert 'letzte aktive Administrator' in admin.get_by_role('alert').first.inner_text()
    admin.get_by_text('Benutzer anlegen',exact=True).first.click()
    create=form(admin,'user_create')
    for key,value in {'login':'member','display_name':'Member <safe>','email':'member@example.test','temporary_password':'secret6'}.items():
        create.locator('[name="'+key+'"]').fill(value)
    create.get_by_role('button',name='Benutzer anlegen',exact=True).click()
    assert admin.get_by_role('heading',name='Member <safe> Aktiv user',exact=True).is_visible()
    assert admin.locator('safe').count()==0
    member=browser.new_page()
    login(member,'tenant','secret6',uuid,'member')
    assert member.get_by_role('heading',name='Startpasswort ändern').is_visible()
    member.locator('[name="old_password"]').fill('secret6')
    member.locator('[name="new_password"]').fill('xyz789')
    member.locator('[name="repeat_password"]').fill('xyz789')
    member.get_by_role('button',name='Passwort speichern').click()
    assert member.get_by_role('link',name='Benutzer',exact=True).count()==0
    assert member.get_by_role('link',name='Mein Konto',exact=True).is_visible()
    csrf=member.locator('[name="csrf"]').first.input_value()
    denied=member.request.post(BASE,form={'csrf':csrf,'action':'user_create','context_token':member.locator('[name="context_token"]').first.input_value(),'role':'admin','login':'attack','display_name':'Attack','email':'attack@example.test','temporary_password':'secret6'})
    assert 'Nur für Mandanten-Admins.' in denied.text()
    # Promote member; the previous session must become invalid immediately.
    admin.reload()
    row=admin.locator('article').filter(has=admin.get_by_role('heading',name='Member <safe> Aktiv user',exact=True))
    row.get_by_text('Benutzer bearbeiten',exact=True).click()
    row.locator('[name="role"]').select_option('admin')
    row.locator('form:has(input[value="user_update"]) [name="confirm"]').check()
    row.get_by_role('button',name='Benutzer speichern').click()
    member.reload()
    assert member.get_by_role('heading',name='Mandant auswählen',exact=True).is_visible()
    assert admin.get_by_text('Passwort zurücksetzen',exact=True).count()==0
    member.get_by_role('button',name='Mandant öffnen',exact=True).click()
    assert member.get_by_role('link',name='Benutzer',exact=True).is_visible()
    admin.locator('details.header-more > summary').click()
    admin.get_by_role('link',name='Mein Konto',exact=True).click()
    assert admin.get_by_role('heading',name='Eigenes Passwort ändern').is_visible()
    for page in [admin,member]:
        for width in [390,1280]:
            page.set_viewport_size({'width':width,'height':900})
            assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
    assert not errors, errors
    browser.close()
print('PASS administration browser: tenant creation/login shortcut, role-specific menus, last-admin guard, user creation, forced six-character password change, XSS escaping, denied direct action, role/membership revocation, protected shared password, account and mobile layout.')
