"""Run after foundation/administration fixtures, before the deletion browser test."""
import os
import re
import sys
from playwright.sync_api import sync_playwright, expect

root=sys.argv[1]
assert re.fullmatch(r'/tmp/o8-m2-test\.[A-Za-z0-9]+/web',root)
BASE='http://127.0.0.1:18089/'
with sync_playwright() as p:
    browser=p.chromium.launch(headless=True,args=['--no-sandbox'])
    page=browser.new_page(viewport={'width':1280,'height':1000})
    page.goto(BASE+"?login=operator")
    page.locator('[name="login"]').fill('admin')
    page.locator('[name="password"]').fill('Browser-only-password-456')
    page.get_by_role('button',name='Anmelden',exact=True).click()
    page.get_by_text('Neuen Mandanten anlegen',exact=True).click()
    create=page.locator('form:has(input[value="tenant_create"])')
    for key,value in {'tenant_name':'  BROWSER TENANT  ','login':'admin','display_name':'Admin','email':'admin@example.test','temporary_password':'secret6'}.items():
        create.locator('[name="'+key+'"]').fill(value)
    create.get_by_role('button',name='Mandant anlegen',exact=True).click()
    expect(page.get_by_role('alert').first).to_contain_text('Mandantenname ist bereits vergeben')
    assert page.locator('article[data-tenant-id]').count()==2
    second=page.locator('article').filter(has=page.get_by_role('heading',name='Second tenant Aktiv',exact=True))
    second.get_by_text('Mandant bearbeiten',exact=True).click()
    edit=second.locator('form:has(input[value="tenant_update"])')
    edit.locator('[name="tenant_name"]').fill('Browser tenant')
    edit.locator('[name="confirm"]').check()
    edit.get_by_role('button',name='Mandant speichern',exact=True).click()
    expect(page.get_by_role('alert').first).to_contain_text('Mandantenname ist bereits vergeben')
    expect(page.get_by_role('heading',name='Second tenant Aktiv',exact=True)).to_be_visible()
    page.goto(BASE+'?section=tenants')
    ratios=page.evaluate('''() => {
      const luminance = color => {
        const rgb=color.match(/[\\d.]+/g).slice(0,3).map(Number).map(v=>v/255).map(v=>v<=.04045?v/12.92:((v+.055)/1.055)**2.4);
        return rgb[0]*.2126+rgb[1]*.7152+rgb[2]*.0722;
      };
      return [['.tenant-count','.tenant-count'],['.tenant-monogram','.tenant-monogram'],['.tenant-create > summary','.tenant-create > summary'],['.tenant-uuid','.tenant-card']].map(([fg,bg])=>{
        const a=luminance(getComputedStyle(document.querySelector(fg)).color);
        const b=luminance(getComputedStyle(document.querySelector(bg)).backgroundColor);
        return (Math.max(a,b)+.05)/(Math.min(a,b)+.05);
      });
    }''')
    assert min(ratios)>=4.5,ratios
    page.locator('#foundationThemeToggle').click()
    assert page.locator('html').get_attribute('data-bs-theme') == 'dark'
    dark_ratios=page.evaluate('''() => {
      const luminance = color => {
        const rgb=color.match(/[\\d.]+/g).slice(0,3).map(Number).map(v=>v/255).map(v=>v<=.04045?v/12.92:((v+.055)/1.055)**2.4);
        return rgb[0]*.2126+rgb[1]*.7152+rgb[2]*.0722;
      };
      return [['.tenant-count','.tenant-count'],['.tenant-monogram','.tenant-monogram'],['.tenant-create > summary','.tenant-create > summary'],['.tenant-uuid','.tenant-card']].map(([fg,bg])=>{
        const a=luminance(getComputedStyle(document.querySelector(fg)).color);
        const b=luminance(getComputedStyle(document.querySelector(bg)).backgroundColor);
        return (Math.max(a,b)+.05)/(Math.min(a,b)+.05);
      });
    }''')
    assert min(dark_ratios)>=4.5,dark_ratios
    page.screenshot(path=os.path.dirname(root)+'/tenants-desktop.png',full_page=True)
    page.set_viewport_size({'width':390,'height':844})
    assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
    page.screenshot(path=os.path.dirname(root)+'/tenants-mobile.png',full_page=True)
    page.locator('.tenant-card').first.get_by_text('Mandant bearbeiten',exact=True).click()
    assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
    browser.close()
print('PASS tenant design: desktop/mobile, measured text contrasts >=4.5:1, duplicate creation and rename rejected with helpful messages.')
