// Suchbare Einzelauswahl; Texteingabe legt kein Konto an.
const fallback = {accountSearchPlaceholder:'Kontonummer oder Bezeichnung suchen …',accountNotConfigured:'{value} · nicht im Setup',accountRemove:'Kontozuordnung entfernen',accountsNeedSetup:'Konten zuerst in Einstellungen → Buchhaltung hinterlegen.',noMatchingAccounts:'Keine passenden Konten.',firstAccountsHint:'Erste 60 Treffer. Suche bitte eingrenzen.'};
const t = (key, values={}) => (globalThis.o8Translate ? globalThis.o8Translate(`common.${key}`, values) : (fallback[key]||'')).replace(/\{([a-zA-Z][a-zA-Z0-9_]*)\}/g,(_,name)=>Object.hasOwn(values,name)?String(values[name]):'');
const source = value => globalThis.o8TranslateSource ? globalThis.o8TranslateSource(value) : value;
export class AccountPicker {
    constructor(container, accounts, value, label, emptyLabel, onChange) {
        this.container = container; this.accounts = accounts; this.value = value || ''; this.emptyLabel = emptyLabel; this.onChange = onChange; this.active = -1;
        container.innerHTML = '<div class="account-selection"></div><input class="form-control form-control-sm" type="search" role="combobox" aria-autocomplete="list" aria-expanded="false" autocomplete="off"><div class="tag-picker-options" role="listbox" hidden></div>';
        this.input = container.querySelector('input'); this.list = container.querySelector('[role="listbox"]');
        this.input.placeholder=t('accountSearchPlaceholder'); this.input.setAttribute('aria-label',source(label)); this.list.setAttribute('aria-label',source(label));
        this.list.id = `${container.id}-options`; this.input.setAttribute('aria-controls', this.list.id);
        this.input.addEventListener('focus', () => this.open());
        this.input.addEventListener('input', () => { this.active = -1; this.open(); });
        this.input.addEventListener('keydown', event => {
            if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); this.close(); }
            if (['ArrowDown','ArrowUp'].includes(event.key)) {
                event.preventDefault(); this.open(); this.active = Math.max(0, Math.min(this.matches.length - 1, this.active + (event.key === 'ArrowDown' ? 1 : -1))); this.mark();
            }
            if (event.key === 'Enter') { event.preventDefault(); if (!this.list.hidden && this.matches[this.active]) this.choose(String(this.matches[this.active].value ?? this.matches[this.active].code)); }
        });
        container.addEventListener('focusout', () => setTimeout(() => { if (!container.contains(document.activeElement)) this.close(); }, 0));
        this.selection();
    }
    selection() {
        const area = this.container.querySelector('.account-selection'); area.replaceChildren();
        const account = this.accounts.find(row => String(row.value ?? row.code) === String(this.value));
        const label = document.createElement('span'); label.textContent = this.value ? (account ? `${account.code} · ${account.name}` : t('accountNotConfigured',{value:this.value})) : source(this.emptyLabel);
        area.append(label);
        if (this.value) {
            const clear = document.createElement('button'); clear.type = 'button'; clear.className = 'btn btn-sm'; clear.textContent = '×'; clear.setAttribute('aria-label', t('accountRemove'));
            clear.addEventListener('click', () => this.choose('')); area.append(clear);
        }
    }
    choose(value) { this.value = value; this.input.value = ''; this.onChange(value); this.selection(); this.input.focus(); this.close(); }
    close() { this.list.hidden = true; this.input.setAttribute('aria-expanded', 'false'); this.input.removeAttribute('aria-activedescendant'); }
    open() {
        const query = this.input.value.trim().toLocaleLowerCase('de');
        const all = [{code:'', name:this.emptyLabel, value:''}, ...this.accounts].filter(row => `${row.code} ${row.name}`.toLocaleLowerCase('de').includes(query));
        this.matches = all.slice(0, 60); this.list.replaceChildren();
        this.matches.forEach((row, index) => {
            const button = document.createElement('button'); button.type = 'button'; button.tabIndex = -1; button.id = `${this.list.id}-${index}`;
            const value=String(row.value ?? row.code); button.setAttribute('role', 'option'); button.setAttribute('aria-selected', String(value === String(this.value))); button.textContent = row.code ? `${row.code} · ${row.name}` : row.name;
            button.addEventListener('mousedown', event => event.preventDefault()); button.addEventListener('click', () => this.choose(value)); this.list.append(button);
        });
        if (!all.length || all.length > 60 || !this.accounts.length) {
            const hint = document.createElement('div'); hint.className = 'tag-picker-empty';
            hint.textContent = !this.accounts.length ? t('accountsNeedSetup') : !all.length ? t('noMatchingAccounts') : t('firstAccountsHint');
            this.list.append(hint);
        }
        this.list.hidden = false; this.input.setAttribute('aria-expanded', 'true'); this.mark();
    }
    mark() {
        this.list.querySelectorAll('[role="option"]').forEach((row, index) => row.classList.toggle('is-highlighted', index === this.active));
        const active = this.list.querySelector('.is-highlighted');
        if (active) { this.input.setAttribute('aria-activedescendant', active.id); active.scrollIntoView({block:'nearest'}); }
        else this.input.removeAttribute('aria-activedescendant');
    }
}
