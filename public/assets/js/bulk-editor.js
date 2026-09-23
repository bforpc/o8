import { TagPicker } from './tag-picker.js';
const $ = id => document.getElementById(id);

export class BulkEditor {
    constructor(store, onSave) {
        this.store = store; this.onSave = onSave; this.ids = []; this.pending = null;
        $('bulkFolderAction').addEventListener('change', () => this.fields());
        $('bulkTagAction').addEventListener('change', () => this.fields());
        $('bulkEditForm').addEventListener('submit', event => { event.preventDefault(); this.review(false); });
        $('bulkDeleteSelected').addEventListener('click', () => this.review(true));
        $('bulkReviewBack').addEventListener('click', () => this.showForm());
        $('bulkApplyChanges').addEventListener('click', () => this.apply());
        $('bulkEditModal').addEventListener('hidden.bs.modal', () => { this.pending = null; this.ids = []; });
    }
    folderPath(folder, folders) {
        const parts = [folder.name], seen = new Set([folder.id]);
        let parent = folders.find(row => row.id === folder.parentId);
        while (parent && !seen.has(parent.id)) {
            parts.unshift(parent.name); seen.add(parent.id); parent = folders.find(row => row.id === parent.parentId);
        }
        return parts.join(' / ');
    }
    open(ids) {
        if (!ids.length) return;
        bootstrap.Toast.getInstance($('feedbackToast'))?.hide();
        this.ids = [...new Set(ids)]; this.pending = null;
        const data = this.store.snapshot();
        $('bulkEditCount').textContent = `${this.ids.length} ausgewählte Dokumente · Auswahl dieser Ergebnisseite`;
        $('bulkEditIds').textContent = this.ids.map(id => `D${id}`).join(', ');
        $('bulkFolderAction').value = $('bulkTagAction').value = 'keep';
        $('bulkFolderTarget').replaceChildren(new Option('Bitte Ordner wählen …', ''));
        for (const folder of data.folders) $('bulkFolderTarget').add(new Option(this.folderPath(folder, data.folders), String(folder.id)));
        $('bulkOwner').replaceChildren(new Option('Besitzer unverändert lassen', ''));
        for (const user of data.users.filter(user => user.active)) $('bulkOwner').add(new Option(`${user.name} (Demo)`, String(user.id)));
        this.tags = new TagPicker($('bulkTagPicker'), data.tags, [], () => {}, 'Tags für Massenänderung suchen');
        const unavailable = this.ids.some(id => !data.documents.some(doc => doc.id === id && !doc.deletedAt));
        $('bulkEditFields').disabled = unavailable;
        $('bulkReviewChanges').disabled = $('bulkDeleteSelected').disabled = unavailable;
        $('bulkUnavailable').hidden = !unavailable;
        this.fields(); this.showForm();
        bootstrap.Modal.getOrCreateInstance($('bulkEditModal')).show();
    }
    fields() {
        const needsFolder = ['remove','add'].includes($('bulkFolderAction').value);
        $('bulkFolderTargetWrap').hidden = !needsFolder; $('bulkFolderTarget').disabled = !needsFolder;
        $('bulkTagPickerWrap').hidden = $('bulkTagAction').value === 'keep';
        $('bulkTagReplaceHint').hidden = $('bulkTagAction').value !== 'set';
    }
    showForm() {
        this.pending = null; $('bulkEditError').textContent = '';
        $('bulkEditContent').hidden = false; $('bulkEditButtons').hidden = false;
        $('bulkReview').hidden = true; $('bulkReviewButtons').hidden = true;
    }
    review(trash) {
        try {
            const changes = trash ? {trash:true} : {folderAction:$('bulkFolderAction').value, folderId:$('bulkFolderTarget').value, tagAction:$('bulkTagAction').value, tags:this.tags.values(), ownerId:$('bulkOwner').value};
            this.pending = this.store.validateBulkChange(this.ids, changes);
            const plan = this.pending, details = [];
            if (trash) details.push('Ausgewählte Dokumente in den Papierkorb legen. Ordnerverknüpfungen bleiben für die Wiederherstellung erhalten. Keine endgültige Dateilöschung. Andere Eingaben in diesem Popup werden nicht angewendet.');
            if (plan.folderAction === 'remove-all') details.push('Alle normalen Ordnerverknüpfungen entfernen. Dokumente und Buchungsdaten bleiben erhalten.');
            if (['remove','add'].includes(plan.folderAction)) details.push(`${plan.folderAction === 'add' ? 'Zusätzlich verlinken in' : 'Nur direkte Verknüpfung entfernen aus'}: ${$('bulkFolderTarget').selectedOptions[0].textContent}. Andere Ordner und Unterordner-Verknüpfungen bleiben unverändert.`);
            if (plan.tagAction !== 'keep') details.push(`${{add:'Tags hinzufügen',remove:'Tags entfernen',set:'Alle bisherigen Tags ersetzen durch'}[plan.tagAction]}: ${plan.tags.length ? plan.tags.join(', ') : '(keine Tags – alle bisherigen Tags entfernen)'}. Die Tag-Definitionen selbst bleiben erhalten.`);
            if (plan.ownerId !== null) details.push(`Besitzer ändern: ${$('bulkOwner').selectedOptions[0].textContent}.`);
            if (!trash && this.ids.some(id => this.store.document(id)?.inInbox)) details.push('Die ausgewählten Eingangsdokumente werden in Alle Dokumente übernommen und verlassen den Eingang.');
            $('bulkReviewTitle').textContent = trash ? `${this.ids.length} Dokumente wirklich löschen?` : `Änderungen an ${this.ids.length} Dokumenten bestätigen`;
            $('bulkReviewList').replaceChildren();
            for (const text of details) { const li = document.createElement('li'); li.textContent = text; $('bulkReviewList').append(li); }
            $('bulkApplyChanges').textContent = trash ? 'Ja, in den Papierkorb' : 'Änderungen übernehmen';
            $('bulkApplyChanges').classList.toggle('btn-danger', trash);
            $('bulkApplyChanges').classList.toggle('btn-primary', !trash);
            $('bulkEditError').textContent = '';
            $('bulkEditContent').hidden = true; $('bulkEditButtons').hidden = true;
            $('bulkReview').hidden = false; $('bulkReviewButtons').hidden = false;
            $('bulkReviewTitle').focus();
        } catch (error) { $('bulkEditError').textContent = error.message; }
    }
    apply() {
        if (!this.pending) return;
        $('bulkApplyChanges').disabled = true;
        try {
            const plan = this.pending;
            const count = this.store.bulkUpdate(plan.ids, plan);
            this.pending = null;
            bootstrap.Modal.getInstance($('bulkEditModal')).hide();
            this.onSave(count, plan.trash);
        } catch (error) { $('bulkEditError').textContent = error.message; }
        finally { $('bulkApplyChanges').disabled = false; }
    }
}
