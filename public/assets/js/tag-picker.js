// Suchbare Mehrfachauswahl ohne Erstellung unbekannter Tags. DOM statt HTML-Interpolation.
export class TagPicker {
    constructor(container, tags, selected = [], onChange = () => {}, label = 'Vorhandene Tags suchen') {
        this.onChange = onChange;
        this.container = container; this.tags = [...tags].sort((a,b) => a.localeCompare(b, 'de'));
        this.selected = new Set(selected); this.activeIndex = -1;
        container.innerHTML = '<div class="selected-tags"></div><div class="tag-search-wrap"><input class="form-control" type="search" role="combobox" aria-label="Vorhandene Tags suchen" aria-autocomplete="list" aria-expanded="false" autocomplete="off" placeholder="Tags suchen und auswählen …"><div class="tag-picker-options" role="listbox" aria-label="Verfügbare Tags" aria-multiselectable="true" hidden></div></div><div class="tag-picker-count" role="status"></div>';
        this.input = container.querySelector('input'); this.list = container.querySelector('[role="listbox"]');
        this.input.setAttribute('aria-label', label);
        this.list.id = `${container.id}-options`; this.input.setAttribute('aria-controls', this.list.id);
        this.input.addEventListener('focus', () => this.open());
        this.input.addEventListener('input', () => { this.activeIndex = -1; this.open(); });
        this.input.addEventListener('keydown', event => {
            if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); this.close(); return; }
            if (['ArrowDown', 'ArrowUp'].includes(event.key)) {
                event.preventDefault(); this.open();
                this.activeIndex = Math.max(0, Math.min(this.matches.length - 1, this.activeIndex + (event.key === 'ArrowDown' ? 1 : -1))); this.markActive();
            }
            if (event.key === 'Enter') {
                event.preventDefault(); if (this.matches[this.activeIndex]) this.toggle(this.matches[this.activeIndex]);
            }
        });
        container.addEventListener('focusout', () => setTimeout(() => { if (!container.contains(document.activeElement)) this.close(); }, 0));
        this.renderSelected();
    }
    values() { return [...this.selected]; }
    open() { this.list.hidden = false; this.input.setAttribute('aria-expanded', 'true'); this.renderOptions(); }
    close() { this.list.hidden = true; this.input.setAttribute('aria-expanded', 'false'); this.input.removeAttribute('aria-activedescendant'); }
    toggle(tag) {
        if (this.selected.has(tag)) this.selected.delete(tag); else this.selected.add(tag);
        this.renderSelected(); this.renderOptions(); this.input.focus();
        this.onChange(this.values());
    }
    renderSelected() {
        const box = this.container.querySelector('.selected-tags'); box.replaceChildren();
        for (const tag of this.selected) {
            const button = document.createElement('button'); button.type = 'button'; button.className = 'selected-tag';
            button.textContent = `${tag} ×`; button.setAttribute('aria-label', `Tag ${tag} entfernen`);
            button.addEventListener('click', () => this.toggle(tag)); box.append(button);
        }
        this.container.querySelector('.tag-picker-count').textContent = `${this.selected.size} ausgewählt · ${this.tags.length} Tags verfügbar`;
    }
    renderOptions() {
        const query = this.input.value.trim().toLocaleLowerCase('de');
        this.matches = this.tags.filter(tag => tag.toLocaleLowerCase('de').includes(query)).slice(0, 60);
        this.list.replaceChildren();
        this.matches.forEach((tag, index) => {
            const option = document.createElement('button'); option.type = 'button'; option.tabIndex = -1; option.id = `${this.list.id}-${index}`;
            option.setAttribute('role', 'option'); option.setAttribute('aria-selected', String(this.selected.has(tag)));
            option.textContent = `${this.selected.has(tag) ? '✓ ' : ''}${tag}`;
            option.addEventListener('mousedown', event => event.preventDefault());
            option.addEventListener('click', () => this.toggle(tag)); this.list.append(option);
        });
        if (!this.matches.length) { const empty = document.createElement('div'); empty.className = 'tag-picker-empty'; empty.textContent = 'Keine passenden Tags.'; this.list.append(empty); }
        else if (this.tags.filter(tag => tag.toLocaleLowerCase('de').includes(query)).length > 60) {
            const hint = document.createElement('div'); hint.className = 'tag-picker-empty'; hint.textContent = 'Erste 60 Treffer. Suche eingrenzen, um weitere Tags zu finden.'; this.list.append(hint);
        }
        this.markActive();
    }
    markActive() {
        [...this.list.querySelectorAll('[role="option"]')].forEach((element, index) => element.classList.toggle('is-highlighted', index === this.activeIndex));
        const active = this.list.querySelector('.is-highlighted');
        if (active) { this.input.setAttribute('aria-activedescendant', active.id); active.scrollIntoView({ block: 'nearest' }); }
        else this.input.removeAttribute('aria-activedescendant');
    }
}
