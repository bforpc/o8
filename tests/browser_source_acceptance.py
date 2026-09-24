"""Render the real source policy partial; exercise existing pickers in an isolated page."""
import os, subprocess
from pathlib import Path
from playwright.sync_api import sync_playwright, expect

root=Path(__file__).resolve().parents[1]
partial=subprocess.check_output(['php','-r',r'''
function h($value){return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
$root='/o8'; $prefix='source-test'; $cfg=['acceptance'=>['enabled'=>true,'tagMode'=>'replace','folders'=>[2],'tags'=>[3]]];
$acceptanceFolders=[['id'=>1,'name'=>'Versicherung','parent_id'=>null],['id'=>2,'name'=>'Verträge','parent_id'=>1]];
$acceptanceTags=[['id'=>3,'name'=>'Rechnung'],['id'=>4,'name'=>'Geprüft']];
require 'app/views/source-acceptance.php';
'''],cwd=root).decode()
html='''<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="/assets/vendor/bootstrap/bootstrap.min.css"><link rel="stylesheet" href="/assets/css/app.css"><link rel="stylesheet" href="/assets/css/foundation.css">
</head><body><div class="app-shell"><main class="foundation-main"><section class="account-sources p-3"><form class="row g-3">'''+partial+'''</form></section></main></div><script type="module" src="/assets/js/source-acceptance.js"></script></body></html>'''
with sync_playwright() as p:
    browser=p.chromium.launch(executable_path=os.environ.get('O8_TEST_CHROME','/usr/bin/chromium'),headless=True,args=['--no-sandbox'])
    page=browser.new_page(); errors=[]; page.on('pageerror',lambda e:errors.append(str(e)))
    def route(request):
        path=request.request.url.split('http://o8.test',1)[-1]
        if path=='/': request.fulfill(body=html,content_type='text/html'); return
        asset=root/'public'/path.lstrip('/')
        if not asset.is_file() or not asset.is_relative_to(root/'public'): request.abort(); return
        request.fulfill(body=asset.read_bytes(),content_type='text/javascript' if asset.suffix=='.js' else 'text/css')
    page.route('http://o8.test/**',route)
    for width in [1200,390,2560,3440]:
        for theme in ['light','dark']:
            page.set_viewport_size({'width':width,'height':900}); page.goto('http://o8.test/')
            page.evaluate('(theme)=>document.documentElement.dataset.bsTheme=theme',theme)
            expect(page.locator('[name="acceptance_enabled"]')).to_be_checked()
            expect(page.locator('[name="acceptance_folders[]"][value="2"]')).to_be_checked()
            assert page.locator('.accept-folder-tree ul').count()==1
            picker=page.get_by_role('combobox',name='Feste Übernahme-Tags suchen')
            picker.fill('Geprüft'); page.get_by_role('option',name='Geprüft',exact=True).click()
            assert page.locator('[name="acceptance_tags[]"]').count()==2
            page.get_by_role('button',name='Tag Rechnung entfernen',exact=True).click()
            assert page.locator('[name="acceptance_tags[]"]').count()==1
            expect(page.locator('[name="acceptance_tag_mode"]')).to_have_value('replace')
            assert page.evaluate('document.documentElement.scrollWidth<=innerWidth')
            if width>=2560:
                shell=page.locator('.app-shell').bounding_box();main=page.locator('.foundation-main').bounding_box()
                assert shell['width']>=width-2 and main['width']>=width-2
                content=page.locator('.account-sources').bounding_box()
                assert 0<content['x']<65 and 0<width-content['x']-content['width']<65
    assert not errors,errors
    browser.close()
    print('PASS source acceptance: real form, tag selection/removal, folder tree, Light/Dark, desktop/mobile')
