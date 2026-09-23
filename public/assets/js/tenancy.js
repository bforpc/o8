// Ausschließlich M1-Browserisolation, KEINE serverseitige Sicherheitsgrenze.
export const TENANTS_KEY = 'o8.tenants.v1';
export const ACTIVE_TENANT_KEY = 'o8.active-tenant.v1';
const uuid = () => {
    const bytes = globalThis.crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 15) | 64; bytes[8] = (bytes[8] & 63) | 128;
    const hex = [...bytes].map(b=>b.toString(16).padStart(2,'0')).join('');
    return `${hex.slice(0,8)}-${hex.slice(8,12)}-${hex.slice(12,16)}-${hex.slice(16,20)}-${hex.slice(20)}`;
};
export class TenantRegistry {
    constructor(storage) {
        this.storage = storage;
        this.selectedId = storage?.getItem(ACTIVE_TENANT_KEY) || 'default';
        const raw = storage?.getItem(TENANTS_KEY);
        if (raw) {
            const data = JSON.parse(raw);
            if (data.version !== 1 || !Array.isArray(data.tenants) || !data.tenants.length || !data.installationId
                || data.tenants.some(t => !/^(default|[a-f0-9-]{36})$/.test(t.id) || typeof t.name !== 'string' || typeof t.active !== 'boolean')
                || new Set(data.tenants.map(t=>t.id)).size !== data.tenants.length) throw new Error('Mandantenregister beschädigt. Kein automatischer Rückfall auf einen anderen Datenbestand.');
            this.data = data;
        } else {
            this.data = {version:1,installationId:uuid(),tenants:[{id:'default',name:'Standardmandant',email:'',active:true}]};
            storage?.setItem(TENANTS_KEY,JSON.stringify(this.data));
        }
        this.savedValue = storage?.getItem(TENANTS_KEY);
    }
    all() { return structuredClone(this.data.tenants); }
    current() {
        const id = this.selectedId;
        const tenant = this.data.tenants.find(t=>t.id === id && t.active);
        if (!tenant) throw new Error('Aktiver Mandant nicht verfügbar. Kein Zugriff auf einen Ersatzmandanten.');
        return structuredClone(tenant);
    }
    save(id, name, email) {
        name = String(name || '').trim(); email = String(email || '').trim().toLowerCase();
        if (!name || name.length > 190 || /[\u0000-\u001f]/.test(name)) throw new Error('Mandantenname: 1 bis 190 Zeichen.');
        if (email && (email.length > 254 || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email))) throw new Error('Ungültige Kontaktadresse.');
        const next = structuredClone(this.data);
        if (next.tenants.some(t=>t.id !== id && t.name.toLocaleLowerCase('de') === name.toLocaleLowerCase('de'))) throw new Error('Dieser Mandantenname existiert bereits.');
        if (id) {
            const tenant = next.tenants.find(t=>t.id === id); if (!tenant) throw new Error('Mandant nicht gefunden.');
            Object.assign(tenant,{name,email});
        } else { id = uuid(); next.tenants.push({id,name,email,active:true}); }
        this.commit(next); return id;
    }
    setActive(id, active) {
        if (id === this.current().id && !active) throw new Error('Zuerst zu einem anderen Mandanten wechseln.');
        const next = structuredClone(this.data), tenant = next.tenants.find(t=>t.id === id);
        if (!tenant) throw new Error('Mandant nicht gefunden.');
        tenant.active = active === true; this.commit(next);
    }
    switchTo(id) {
        if (!this.data.tenants.some(t=>t.id === id && t.active)) throw new Error('Mandant nicht verfügbar.');
        if (!this.storage) throw new Error('Browser-Speicherung nicht verfügbar.');
        this.storage.setItem(ACTIVE_TENANT_KEY,id); this.selectedId = id;
    }
    commit(next) {
        if (!this.storage) throw new Error('Browser-Speicherung nicht verfügbar.');
        if (this.storage.getItem(TENANTS_KEY) !== this.savedValue) throw new Error('Mandantenverwaltung wurde in einem anderen Fenster geändert. Bitte neu laden.');
        const serialized = JSON.stringify(next); this.storage.setItem(TENANTS_KEY,serialized);
        this.savedValue = serialized; this.data = next;
    }
}
export function tenantStorage(storage, tenantId) {
    if (!/^(default|[a-f0-9-]{36})$/.test(tenantId)) throw new Error('Ungültige Mandanten-ID.');
    // Altdaten gehören ausschließlich zum Standardmandanten; keine Datenkopie.
    const key = name => tenantId === 'default' ? name : `o8.tenant.${tenantId}.${name}`;
    return {tenantId,seedDemo:tenantId === 'default',getItem:name=>storage?.getItem(key(name)),
        setItem:(name,value)=>{ if (!storage) throw new Error('Speicherung nicht verfügbar.'); storage.setItem(key(name),value); },
        removeItem:name=>storage?.removeItem(key(name))};
}
let context;
export function browserTenantContext() {
    if (context) return context;
    let raw; try { raw = window.localStorage; } catch { raw = null; }
    const registry = new TenantRegistry(raw), tenant = registry.current();
    return context = {registry,tenant,storage:tenantStorage(raw,tenant.id)};
}
