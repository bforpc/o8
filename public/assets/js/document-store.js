import { createDemoData, createEmptyDemoData } from './demo-data.js';
import { validateInvoice, validDate } from './invoice.js';
import { ACCOUNTING_KEY, DEFAULT_ACCOUNTING, validateAccounting, validateInvoiceChoices } from './accounting-settings.js';
import { compileSearch, compareDocuments } from './document-search.js';

export const DEMO_STORAGE_KEY = 'o8.demo.v1';
const clone = value => JSON.parse(JSON.stringify(value));
const normalize = value => String(value).toLocaleLowerCase('de').normalize('NFD').replace(/\p{Diacritic}/gu, '');

// Austauschbarer Datenzugriff: Im ersten Meilenstein ein Browser-Demobestand.
// Produktionszugriff und Berechtigungen folgen serverseitig in Meilenstein 2.
export class DocumentStore {
    constructor(storage = null) {
        this.storage = storage;
        this.storageFailed = false;
        this.seed = storage?.seedDemo === false ? createEmptyDemoData : createDemoData;
        this.data = this.seed();
        try {
            const saved = JSON.parse(storage?.getItem(DEMO_STORAGE_KEY) || 'null');
            if ([1, 2].includes(saved?.version) && Array.isArray(saved.documents) && Array.isArray(saved.folders) && Array.isArray(saved.links) && Array.isArray(saved.tags)) {
                this.data = saved;
                if (saved.version === 1) {
                    const linked = new Set(saved.links.map(link => link.documentId));
                    this.data.documents.forEach(doc => { doc.inInbox = !linked.has(doc.id); });
                    this.data.tags = [...new Set([...this.data.tags, ...createDemoData().tags])];
                    this.data.version = 2;
                }
            }
        } catch { this.storageFailed = true; }
        // Ältere M1-Browserbestände um die ausdrücklich fiktive Anwenderliste ergänzen.
        if (!Array.isArray(this.data.users)) this.data.users = this.seed().users;
        for (const user of this.data.users) {
            const demo = this.seed().users.find(row => row.id === user.id);
            user.email ??= demo?.email ?? '';
            user.role ??= demo?.role ?? 'user'; // Nur fiktiver M1-Bestand, keine Autorisierung.
        }
        this.data.documents.forEach(doc => {
            if (doc.ownerId == null) doc.ownerId = 1;
            doc.expired = doc.expired === true || doc.expired === 1;
            doc.searchable = doc.searchable !== false && doc.searchable !== 0;
            if (doc.invoice) doc.invoice.currency ??= 'EUR';
        });
        this.accounting = clone(DEFAULT_ACCOUNTING);
        try {
            const saved = JSON.parse(storage?.getItem(ACCOUNTING_KEY) || 'null');
            if (saved) this.accounting = validateAccounting(saved);
        } catch { this.storageFailed = true; }
    }
    accountingSettings() { return clone(this.accounting); }
    saveAccountingSettings(input) {
        const settings = validateAccounting(input);
        for (const doc of this.data.documents) if (doc.invoice) validateInvoiceChoices(doc.invoice, settings);
        this.accounting = settings;
        try { this.storage?.setItem(ACCOUNTING_KEY, JSON.stringify(settings)); }
        catch { this.storageFailed = true; }
    }
    persist() {
        try { this.storage?.setItem(DEMO_STORAGE_KEY, JSON.stringify(this.data)); }
        catch { this.storageFailed = true; }
    }
    snapshot() { return clone(this.data); }
    saveDemoProfile(id, {name, email}) {
        const user = this.data.users.find(row => row.id === Number(id));
        name = String(name || '').trim(); email = String(email || '').trim().toLowerCase();
        if (!user || !name || name.length > 190 || email.length > 254 || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) throw new Error('Bitte Name und gültige E-Mail-Adresse eingeben.');
        if (this.data.users.some(row => row.id !== user.id && row.email?.toLowerCase() === email)) throw new Error('Diese E-Mail-Adresse wird bereits verwendet.');
        user.name = name; user.email = email; this.persist();
    }
    validateTagName(name, previous = null) {
        name = String(name || '').trim();
        if (!name || name.length > 190) throw new Error('Tagname: 1 bis 190 Zeichen.');
        if (this.data.tags.some(tag => tag !== previous && normalize(tag) === normalize(name))) throw new Error('Dieser Tag existiert bereits.');
        return name;
    }
    tagUsage(name) { return this.data.documents.filter(doc => doc.tags.includes(name)).length; }
    createTag(name) {
        name = this.validateTagName(name); this.data.tags.push(name); this.persist(); return name;
    }
    renameTag(previous, name) {
        if (!this.data.tags.includes(previous)) throw new Error('Tag nicht gefunden.');
        name = this.validateTagName(name, previous);
        this.data.tags = this.data.tags.map(tag => tag === previous ? name : tag);
        for (const doc of this.data.documents) doc.tags = [...new Set(doc.tags.map(tag => tag === previous ? name : tag))];
        this.persist(); return name;
    }
    deleteTag(name) {
        if (!this.data.tags.includes(name)) throw new Error('Tag nicht gefunden.');
        if (this.tagUsage(name)) throw new Error('Dieser Tag wird noch verwendet, auch Eingang und Papierkorb zählen mit. Bitte zuerst die Zuordnungen entfernen.');
        this.data.tags = this.data.tags.filter(tag => tag !== name); this.persist();
    }
    document(id) { return clone(this.data.documents.find(doc => doc.id === Number(id)) || null); }
    foldersFor(id) {
        const ids = new Set(this.data.links.filter(link => link.documentId === Number(id)).map(link => link.folderId));
        return clone(this.data.folders.filter(folder => ids.has(folder.id)));
    }
    validateBulkChange(documentIds, changes = {}) {
        if (!Array.isArray(documentIds) || !documentIds.length) throw new Error('Bitte mindestens ein Dokument per Checkbox auswählen.');
        const ids = [...new Set(documentIds.map(Number))];
        const docs = ids.map(id => this.data.documents.find(doc => doc.id === id));
        if (docs.some(doc => !doc || doc.deletedAt)) throw new Error('Die Auswahl enthält nicht verfügbare Dokumente. Dokumente im Papierkorb bitte zuerst wiederherstellen.');
        const folderAction = changes.folderAction ?? 'keep', tagAction = changes.tagAction ?? 'keep';
        if (!['keep','remove-all','remove','add'].includes(folderAction) || !['keep','add','remove','set'].includes(tagAction)) throw new Error('Unbekannte Massenänderung.');
        const folderId = Number(changes.folderId), ownerId = changes.ownerId == null || changes.ownerId === '' ? null : Number(changes.ownerId);
        const tags = [...new Set(changes.tags || [])];
        if (['remove','add'].includes(folderAction) && !this.data.folders.some(folder => folder.id === folderId)) throw new Error('Bitte einen vorhandenen Ordner auswählen.');
        if (tagAction !== 'keep' && tags.some(tag => !this.data.tags.includes(tag))) throw new Error('Bitte nur vorhandene Tags auswählen.');
        if (['add','remove'].includes(tagAction) && !tags.length) throw new Error('Bitte mindestens einen Tag auswählen.');
        if (ownerId !== null && !this.data.users.some(user => user.id === ownerId && user.active)) throw new Error('Bitte einen aktiven Anwender auswählen.');
        const trash = changes.trash === true;
        const edits = folderAction !== 'keep' || tagAction !== 'keep' || ownerId !== null;
        if (trash && edits) throw new Error('Löschen muss getrennt von anderen Änderungen erfolgen.');
        if (!trash && !edits) throw new Error('Bitte mindestens eine Änderung wählen.');
        return {ids, folderAction, folderId, tagAction, tags, ownerId, trash};
    }
    bulkUpdate(documentIds, changes) {
        // Vor jeder Mutation die vollständige Auswahl und alle Änderungen validieren.
        const plan = this.validateBulkChange(documentIds, changes);
        const ids = new Set(plan.ids), next = clone(this.data);
        if (plan.folderAction === 'remove-all' || plan.folderAction === 'remove') {
            next.links = next.links.filter(link => !ids.has(link.documentId) || (plan.folderAction === 'remove' && link.folderId !== plan.folderId));
        }
        if (plan.folderAction === 'add') {
            const existing = new Set(next.links.filter(link => link.folderId === plan.folderId).map(link => link.documentId));
            for (const id of ids) if (!existing.has(id)) next.links.push({documentId:id,folderId:plan.folderId});
        }
        const now = new Date().toISOString();
        for (const doc of next.documents) {
            if (!ids.has(doc.id)) continue;
            if (plan.tagAction === 'add') doc.tags = [...new Set([...doc.tags, ...plan.tags])];
            if (plan.tagAction === 'remove') doc.tags = doc.tags.filter(tag => !plan.tags.includes(tag));
            if (plan.tagAction === 'set') doc.tags = [...plan.tags];
            if (plan.ownerId !== null) doc.ownerId = plan.ownerId;
            if (plan.trash) doc.deletedAt = now;
            else doc.inInbox = false;
        }
        this.data = next; this.persist();
        return plan.ids.length;
    }
    descendants(folderId) {
        const ids = new Set([Number(folderId)]);
        let changed = true;
        while (changed) {
            changed = false;
            for (const folder of this.data.folders) {
                if (ids.has(folder.parentId) && !ids.has(folder.id)) { ids.add(folder.id); changed = true; }
            }
        }
        return ids;
    }
    list(options = {}) {
        const {scope = 'all', sort = 'date-desc'} = options;
        // Vorschaukontext, ausdrücklich kein Ersatz für serverseitige Rechte in M2.
        const viewer = options.demoUserId == null ? null : this.data.users.find(user => user.id === options.demoUserId && user.active);
        if (options.demoUserId != null && !viewer) return [];
        if (viewer && viewer.role !== 'admin' && options.ownerId) throw new Error('Der Besitzerfilter ist nur für Admins verfügbar.');
        const matches = compileSearch(options);
        const folderIds = typeof scope === 'number' ? this.descendants(scope) : null;
        const linkedIds = new Set(this.data.links.filter(link => !folderIds || folderIds.has(link.folderId)).map(link => link.documentId));
        return clone(this.data.documents.filter(doc => {
            if (viewer && viewer.role !== 'admin' && doc.ownerId !== viewer.id) return false;
            if (scope === 'trash' ? !doc.deletedAt : Boolean(doc.deletedAt)) return false;
            if (scope !== 'trash' && scope !== 'inbox' && doc.inInbox) return false;
            if (scope === 'unfiled' && linkedIds.has(doc.id)) return false;
            if (scope === 'inbox' && !doc.inInbox) return false;
            if (folderIds && !linkedIds.has(doc.id)) return false;
            return matches(doc);
        }).sort(compareDocuments(sort)));
    }
    link(documentIds, folderIds) {
        const validDocs = new Set(this.data.documents.filter(doc => !doc.deletedAt).map(doc => doc.id));
        const validFolders = new Set(this.data.folders.map(folder => folder.id));
        const existing = new Set(this.data.links.map(link => `${link.documentId}:${link.folderId}`));
        const docIds = documentIds.map(Number), targetIds = folderIds.map(Number);
        if (docIds.some(id => !validDocs.has(id))) throw new Error('Dokument nicht gefunden.');
        if (targetIds.some(id => !validFolders.has(id))) throw new Error('Ordner nicht gefunden.');
        for (const documentId of docIds) {
            for (const folderId of targetIds) {
                const key = `${documentId}:${folderId}`;
                if (!existing.has(key)) { this.data.links.push({ documentId, folderId }); existing.add(key); }
            }
            if (targetIds.length) this.data.documents.find(doc => doc.id === documentId).inInbox = false;
        }
        this.persist();
    }
    unlink(documentIds, folderId) {
        const ids = new Set(documentIds.map(Number));
        this.data.links = this.data.links.filter(link => !(ids.has(link.documentId) && link.folderId === Number(folderId)));
        this.persist();
    }
    trash(documentIds) {
        const ids = new Set(documentIds.map(Number));
        for (const doc of this.data.documents) {
            if (ids.has(doc.id) && !doc.deletedAt) doc.deletedAt = new Date().toISOString();
        }
        // Verknüpfungen bleiben für eine vollständige Wiederherstellung erhalten.
        this.persist();
    }
    restore(documentIds) {
        const ids = new Set(documentIds.map(Number));
        for (const doc of this.data.documents) if (ids.has(doc.id)) doc.deletedAt = null;
        this.persist();
    }
    saveDocument(id, { title, date, memo, tags, type, expired, searchable }) {
        const doc = this.data.documents.find(item => item.id === Number(id));
        if (!doc || doc.deletedAt) throw new Error('Dokument nicht verfügbar.');
        if (!title.trim()) throw new Error('Bitte eine Beschreibung eingeben.');
        if (!validDate(date)) throw new Error('Bitte ein gültiges Datum eingeben.');
        const nextType = type ?? doc.type;
        if (!['Rechnung', 'Gutschrift', 'Vertrag', 'Bescheinigung', 'Dokument'].includes(nextType)) throw new Error('Unbekannte Dokumentart.');
        if ((expired !== undefined && typeof expired !== 'boolean') || (searchable !== undefined && typeof searchable !== 'boolean')) throw new Error('Ungültiger Dokumentstatus.');
        doc.expired = expired ?? doc.expired;
        doc.searchable = searchable ?? doc.searchable;
        doc.type = nextType;
        doc.title = title.trim(); doc.date = date; doc.memo = memo.trim();
        doc.tags = [...new Set(tags.filter(tag => this.data.tags.includes(tag)))];
        doc.inInbox = false;
        this.persist();
    }
    saveInvoice(id, invoice) {
        const doc = this.data.documents.find(item => item.id === Number(id));
        if (!doc || doc.deletedAt) throw new Error('Kein bearbeitbares Dokument ausgewählt.');
        const totals = validateInvoice(invoice);
        validateInvoiceChoices(invoice, this.accounting);
        doc.invoice = clone(invoice);
        doc.sender = invoice.sender.trim(); doc.reference = invoice.number.trim(); doc.date = invoice.date;
        doc.amountCents = totals.grossCents;
        doc.inInbox = false;
        this.persist();
    }
    completeInbox(id) {
        const doc = this.data.documents.find(item => item.id === Number(id));
        if (!doc || doc.deletedAt) throw new Error('Dokument nicht verfügbar.');
        doc.inInbox = false; this.persist();
    }
    createFolder(name, parentId = null) {
        name = this.validateFolderName(name, parentId);
        if (parentId !== null && !this.data.folders.some(folder => folder.id === parentId)) throw new Error('Übergeordneter Ordner fehlt.');
        const folder = { id: Math.max(0, ...this.data.folders.map(item => item.id)) + 1, name, parentId, color: '#39756d' };
        this.data.folders.push(folder); this.persist(); return clone(folder);
    }
    validateFolderName(name, parentId, ignoreId = null) {
        name = String(name).trim();
        if (!name || name.length > 100) throw new Error('Ordnername: 1 bis 100 Zeichen.');
        if (this.data.folders.some(folder => folder.id !== ignoreId && folder.parentId === parentId && normalize(folder.name) === normalize(name))) throw new Error('Dieser Ordner existiert hier bereits.');
        return name;
    }
    folderUsage(id) {
        const folder = this.data.folders.find(folder => folder.id === Number(id));
        if (!folder) throw new Error('Ordner nicht gefunden.');
        const linkedIds = new Set(this.data.links.filter(link => link.folderId === folder.id).map(link => link.documentId));
        const trashed = this.data.documents.filter(doc => linkedIds.has(doc.id) && doc.deletedAt).length;
        const documents = linkedIds.size - trashed;
        const children = this.data.folders.filter(child => child.parentId === folder.id).length;
        return {documents, trashed, children, empty:documents === 0 && children === 0};
    }
    renameFolder(id, name) {
        const folder = this.data.folders.find(folder => folder.id === Number(id));
        if (!folder) throw new Error('Ordner nicht gefunden.');
        folder.name = this.validateFolderName(name, folder.parentId, folder.id);
        this.persist(); return clone(folder);
    }
    deleteFolder(id) {
        const usage = this.folderUsage(id);
        if (!usage.empty) throw new Error('Nur Ordner ohne aktive Dokumente und ohne Unterordner können gelöscht werden.');
        // Papierkorb-Dokumente bleiben erhalten; nur ihre Zuordnung zum
        // entfernten Ordner entfällt, auch bei späterer Wiederherstellung.
        this.data.links = this.data.links.filter(link => link.folderId !== Number(id));
        this.data.folders = this.data.folders.filter(folder => folder.id !== Number(id));
        this.persist();
    }
    reset() { this.data = this.seed(); this.persist(); }
}
