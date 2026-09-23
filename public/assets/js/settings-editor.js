import { SettingsDraftStore } from './settings-draft.js';
const $ = id => document.getElementById(id);
const fields = form => Object.fromEntries(new FormData(form).entries());

export class SettingsEditor {
    constructor(store, storage, profile, confirm, onSave) {
        this.store = store; this.drafts = new SettingsDraftStore(storage); this.profile = profile;
        this.confirm = confirm; this.onSave = onSave; this.editingTag = null;
        $('personalTrashForm').addEventListener('submit', event => {
            event.preventDefault();
            try {
                this.drafts.savePersonal(this.userId(),fields(event.currentTarget));
                const days = this.drafts.personal(this.userId()).trashDays;
                $('personalTrashStatus').textContent = `${days ? `Aufbewahrung: ${days} Tage.` : 'Unbegrenzte Aufbewahrung.'} Für dieses Benutzerprofil gespeichert. Automatische Löschung erst mit der Serveranbindung in M2.`;
            } catch (error) { $('personalTrashStatus').textContent = error.message; }
        });
        $('personalProfileForm').addEventListener('submit', event => {
            event.preventDefault();
            try {
                store.saveDemoProfile(this.userId(),fields(event.currentTarget)); this.renderUsers();
                $('personalProfileStatus').textContent = 'Demo-Profil gespeichert. Keine Änderung eines echten Benutzerkontos.';
                onSave('Demo-Profil gespeichert.');
            } catch (error) { $('personalProfileStatus').textContent = error.message; }
        });
        for (const kind of ['imap','webdav']) $(`${kind}DraftForm`).addEventListener('submit', event => {
            event.preventDefault();
            try {
                const input = fields(event.currentTarget); input.recursive = input.recursive === 'on';
                this.drafts.saveSource(this.userId(),kind,input);
                $(`${kind}DraftStatus`).textContent = 'Verbindungsentwurf für dieses Profil gespeichert. Kein Abruf aktiviert; keine Zugangsdaten gespeichert.';
            } catch (error) { $(`${kind}DraftStatus`).textContent = error.message; }
        });
        $('globalDraftForm').addEventListener('submit', event => {
            event.preventDefault();
            try {
                this.drafts.saveGlobal(fields(event.currentTarget));
                $('globalDraftStatus').textContent = 'Admin-Entwurf gespeichert. Keine Pfade, Dateien oder Linux-Rechte auf dem Server geändert.';
            } catch (error) { $('globalDraftStatus').textContent = error.message; }
        });
        $('tagAdminSearch').addEventListener('input', () => this.renderTags());
        $('tagAdminCreate').addEventListener('click', () => this.openTag(null));
        $('tagAdminForm').addEventListener('submit', event => {
            event.preventDefault();
            try {
                if (this.editingTag === null) store.createTag($('tagAdminName').value);
                else store.renameTag(this.editingTag,$('tagAdminName').value);
                bootstrap.Modal.getInstance($('tagAdminModal')).hide(); this.renderTags(); onSave('Tag-Katalog aktualisiert.');
            } catch (error) { $('tagAdminError').textContent = error.message; }
        });
        $('tagAdminModal').addEventListener('shown.bs.modal', () => $('tagAdminName').focus());
        this.render();
    }
    userId() { return this.profile() === 'second' ? 2 : 1; }
    fill(form, values) {
        for (const [name,value] of Object.entries(values)) {
            const input = form.elements.namedItem(name);
            if (!input) continue;
            if (input.type === 'checkbox') input.checked = value === true; else input.value = value;
        }
    }
    render() {
        const user = this.store.snapshot().users.find(row => row.id === this.userId());
        this.fill($('personalProfileForm'),user || {});
        this.fill($('personalTrashForm'),this.drafts.personal(this.userId()));
        $('personalTrashStatus').textContent = '';
        $('settingsProfileName').textContent = `${user?.name || 'Vorschauprofil'} · ${this.profile() === 'second' ? 'Zweites Profil' : 'Standard'}`;
        for (const kind of ['imap','webdav']) {
            this.fill($(`${kind}DraftForm`),this.drafts.source(this.userId(),kind)); $(`${kind}DraftStatus`).textContent = '';
        }
        this.fill($('globalDraftForm'),this.drafts.global());
        $('personalProfileStatus').textContent = ''; this.renderUsers(); this.renderTags();
    }
    renderUsers() {
        $('settingsUsers').replaceChildren();
        for (const user of this.store.snapshot().users) {
            const row = document.createElement('tr');
            for (const value of [user.name,user.email,user.role === 'admin' ? 'Admin' : 'User',user.active ? 'Aktiv' : 'Gesperrt']) {
                const cell = document.createElement('td'); cell.textContent = value; row.append(cell);
            }
            $('settingsUsers').append(row);
        }
    }
    openTag(name) {
        this.editingTag = name; $('tagAdminTitle').textContent = name === null ? 'Tag hinzufügen' : 'Tag umbenennen';
        $('tagAdminName').value = name || ''; $('tagAdminError').textContent = '';
        $('tagAdminUsage').textContent = name === null ? 'Nur hier werden neue Tags angelegt. Import und AI verwenden ausschließlich vorhandene Tags.' : `Die Änderung gilt für ${this.store.tagUsage(name)} Dokument(e), einschließlich Eingang und Papierkorb.`;
        bootstrap.Modal.getOrCreateInstance($('tagAdminModal')).show();
    }
    renderTags() {
        const query = $('tagAdminSearch').value.trim().toLocaleLowerCase('de');
        const tags = this.store.snapshot().tags.filter(name => name.toLocaleLowerCase('de').includes(query)).sort((a,b)=>a.localeCompare(b,'de'));
        $('tagAdminCount').textContent = `${tags.length} Treffer · maximal 50 angezeigt; zum Eingrenzen suchen.`;
        $('tagAdminList').replaceChildren();
        for (const name of tags.slice(0,50)) {
            const row = document.createElement('div'); row.className = 'tag-admin-row';
            const label = document.createElement('span'); label.textContent = `${name} · ${this.store.tagUsage(name)} Dokument(e)`;
            const edit = document.createElement('button'); edit.type = 'button'; edit.className = 'btn btn-sm btn-surface'; edit.textContent = 'Ändern'; edit.setAttribute('aria-label', `Tag ${name} ändern`);
            edit.addEventListener('click', () => this.openTag(name));
            const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'btn btn-sm btn-outline-danger'; remove.textContent = 'Löschen'; remove.setAttribute('aria-label', `Tag ${name} löschen`);
            remove.addEventListener('click', () => {
                if (this.store.tagUsage(name)) { $('tagAdminStatus').textContent = 'Verwendete Tags können nicht gelöscht werden. Zuerst die Zuordnungen entfernen, auch im Eingang und Papierkorb.'; return; }
                this.confirm('Tag löschen?', `Den unbenutzten Tag „${name}“ aus dem gemeinsamen Katalog löschen?`, 'Tag löschen', () => {
                    try { this.store.deleteTag(name); this.renderTags(); this.onSave('Tag gelöscht.'); }
                    catch (error) { $('tagAdminStatus').textContent = error.message; }
                });
            });
            row.append(label,edit,remove); $('tagAdminList').append(row);
        }
        $('tagAdminStatus').textContent = '';
    }
}
