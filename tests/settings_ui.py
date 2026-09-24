"""Navigate through the real desktop/mobile settings menu before interacting."""
from playwright.sync_api import expect

def show_settings_for(page, selector):
    section = page.locator(selector).first.evaluate('(el)=>el.closest("[data-settings-panel]")?.dataset.settingsPanel || null')
    if section is None:
        return
    panel = page.locator(f'[data-settings-panel="{section}"]')
    if panel.is_visible():
        return
    expect(page.locator('#page-settings')).to_be_visible()
    if page.locator('#settingsSectionSelect').is_visible():
        page.locator('#settingsSectionSelect').select_option(section)
    else:
        page.locator(f'[data-settings-section="{section}"]').click()
    expect(panel).to_be_visible()
