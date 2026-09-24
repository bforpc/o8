"""Run only against the disposable M2 test copy, never a real installation."""
import os
import re
import subprocess
import sys
from playwright.sync_api import sync_playwright

root = sys.argv[1]
assert re.fullmatch(r"/tmp/o8-m2-test\.[A-Za-z0-9]+/web", root)
token = subprocess.check_output(["php", "-r", "require 'app/bootstrap.php'; $r=new O8\\Core\\Runtime('storage/system'); echo $r->issueSetupToken();"], cwd=root, text=True)
with sync_playwright() as p:
    browser = p.chromium.launch(headless=True, args=["--no-sandbox"])
    page = browser.new_page()
    base = "http://127.0.0.1:18089/"
    page.goto(base)
    assert page.get_by_role("heading", name="Datenbank einrichten").is_visible()
    page.set_viewport_size({'width': 1600, 'height': 900})
    panel = page.locator('.foundation-panel').bounding_box()
    assert abs((panel['x'] + panel['width']/2) - 800) < 2
    page.locator('[name="setup_token"]').fill(token)
    page.get_by_role("button", name="Installation starten").click()
    assert page.get_by_role("heading", name="Betreiber-Anmeldung", exact=True).is_visible()
    panel = page.locator('.foundation-panel').bounding_box()
    assert abs((panel['x'] + panel['width']/2) - 800) < 2
    # Invalid CSRF cannot submit an otherwise correct login.
    response = page.request.post(base, form={"action":"login","csrf":"wrong","kind":"operator","login":"admin","password":"owndms8","setup_token":token})
    assert "Sitzung abgelaufen" in response.text()
    assert "Startpasswort ändern" not in response.text()
    page.locator('[name="login"]').fill('admin')
    page.locator('[name="password"]').fill('owndms8')
    page.get_by_text('Ersteinrichtung: Einrichtungscode', exact=True).click()
    page.locator('[name="setup_token"]').fill(token)
    page.get_by_role('button', name='Anmelden', exact=True).click()
    assert page.get_by_role('heading', name='Startpasswort ändern').is_visible()
    page.locator('[name="old_password"]').fill('owndms8')
    page.locator('[name="new_password"]').fill('Browser-only-password-456')
    page.locator('[name="repeat_password"]').fill('Browser-only-password-456')
    page.get_by_role('button', name='Passwort speichern').click()
    assert page.get_by_role('heading', name='Administrator & erster Mandant').is_visible()
    page.locator('[name="display_name"]').fill('Browser Admin')
    page.locator('[name="email"]').fill('browser@example.test')
    page.locator('[name="tenant_name"]').fill('Browser tenant')
    page.get_by_role('button', name='Ersteinrichtung abschließen').click()
    assert page.get_by_role('heading', name='Angemeldet als Browser Admin').is_visible()
    uuid = page.locator('.text-break').inner_text()
    page.get_by_role('button', name='Abmelden', exact=True).click()
    page.locator('[name="login"]').fill('admin')
    page.locator('[name="password"]').fill('Browser-only-password-456')
    page.get_by_role('button', name='Anmelden', exact=True).click()
    assert 'Mandant: Browser tenant' in page.inner_text('body')
    assert 'M2 · TESTBETRIEB' not in page.inner_text('body')
    assert 'o7-Übernahme' not in page.inner_text('body')
    assert page.locator('.header-tools > .header-intake + .workspace-menu').count()==1
    page.evaluate("document.getElementById('liveError').hidden = false")
    assert page.locator('.workspace-menu summary').is_visible()
    page.locator('.workspace-menu summary').click()
    assert page.locator('.workspace-menu-panel [data-logout-form]').is_visible()
    page.locator('.workspace-menu summary').click()
    page.evaluate("document.getElementById('liveError').hidden = true")
    assert page.locator('.app-header #uploadOpen').is_disabled()
    assert page.locator('.workspace-page > #searchForm').count()==1
    for size in [390, 1280]:
        page.set_viewport_size({'width':size,'height':900})
        assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
    for path in ['config.php','config.php.dist','storage/system/installation.json','database/migrations/001_core.sql','o7/']:
        assert page.request.get(base+path).status == 404
    cookie = next(c for c in page.context.cookies() if c['name']=='o8_session')
    assert cookie['httpOnly'] and cookie['sameSite']=='Strict'
    browser.close()
print('Browser checks passed: install, CSRF, forced password change, onboarding, tenant login, mobile, internal-path protection, session flags.')
