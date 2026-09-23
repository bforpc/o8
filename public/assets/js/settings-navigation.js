// Navigation trennt die Masken, verändert aber weder Formulardaten noch Rechte.
export class SettingsNavigation {
    constructor(storage, profile) {
        this.storage = storage; this.profile = profile;
        this.panels = [...document.querySelectorAll('[data-settings-panel]')];
        this.buttons = [...document.querySelectorAll('[data-settings-section]')];
        this.select = document.getElementById('settingsSectionSelect');
        for (const button of this.buttons) {
            const panel = this.panels.find(panel=>panel.dataset.settingsPanel === button.dataset.settingsSection);
            button.setAttribute('aria-controls',panel.id);
            button.addEventListener('click',()=>this.show(button.dataset.settingsSection,true));
        }
        this.select.addEventListener('change',()=>this.show(this.select.value,true));
        this.restore();
    }
    key() { return `o8.settings.section.v1.${this.profile()}`; }
    restore() {
        let section = 'profile';
        try { section = this.storage?.getItem(this.key()) || section; } catch { /* Standardbereich. */ }
        this.show(section);
    }
    show(section, focus = false) {
        if (!this.panels.some(panel=>panel.dataset.settingsPanel === section)) section = 'profile';
        for (const panel of this.panels) panel.hidden = panel.dataset.settingsPanel !== section;
        for (const button of this.buttons) {
            if (button.dataset.settingsSection === section) button.setAttribute('aria-current','page');
            else button.removeAttribute('aria-current');
        }
        this.select.value = section;
        try { this.storage?.setItem(this.key(),section); } catch { /* Navigation ohne Persistenz bleibt nutzbar. */ }
        if (focus) {
            const heading = this.panels.find(panel=>!panel.hidden).querySelector('h2');
            heading.tabIndex = -1; heading.focus({preventScroll:true});
        }
    }
}
