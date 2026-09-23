// M1: nur nicht geheime Entwurfswerte. Keine Verbindung, kein chmod/chown, keine Autorisierung.
export const SETTINGS_DRAFT_KEY = 'o8.settings-draft.v1';
const copy = value => structuredClone(value);
export const GLOBAL_DEFAULTS = {storagePath:'', linuxOwner:'', linuxGroup:'', fileMode:'0640', directoryMode:'0750', maxUploadMb:50};
export const PERSONAL_DEFAULTS = {trashDays:0}; // 0 = unbegrenzt aufbewahren.
export const SOURCE_DEFAULTS = {
    imap:{host:'',port:993,security:'tls',username:'',folder:'INBOX',intervalMinutes:15,auth:'password'},
    webdav:{url:'',username:'',folder:'/',intervalMinutes:15,recursive:false},
};
const text = (value, max = 255) => {
    const result = String(value ?? '').trim();
    if (result.length > max || /[\u0000-\u001f\u007f]/.test(result)) throw new Error(`Ungültiger Text oder mehr als ${max} Zeichen.`);
    return result;
};
const integer = (value, min, max, label) => {
    const result = Number(value);
    if (!Number.isInteger(result) || result < min || result > max) throw new Error(`${label}: ${min} bis ${max}.`);
    return result;
};
export function validateGlobalDraft(input) {
    const storagePath = text(input.storagePath, 768);
    if (!storagePath.startsWith('/') || storagePath === '/' || storagePath.split('/').includes('..')) throw new Error('Bitte einen absoluten Storage-Pfad ohne .. angeben, nicht das Wurzelverzeichnis.');
    const linuxOwner = text(input.linuxOwner,100), linuxGroup = text(input.linuxGroup,100);
    if (![linuxOwner,linuxGroup].every(value => /^(?:[a-z_][a-z0-9_-]*|[1-9][0-9]*)$/.test(value) && value !== 'root')) throw new Error('Linux-Besitzer/Gruppe: gültiger Name oder positive UID/GID, nicht root.');
    if (!['0600','0640','0660'].includes(input.fileMode) || !['0700','0750','0770'].includes(input.directoryMode)) throw new Error('Bitte sichere Datei-/Verzeichnisrechte aus der Liste wählen.');
    return {storagePath,linuxOwner,linuxGroup,fileMode:input.fileMode,directoryMode:input.directoryMode,
        maxUploadMb:integer(input.maxUploadMb,1,1024,'Uploadlimit in MB')};
}
export function validatePersonalDraft(input) {
    if (!input || !['string','number'].includes(typeof input.trashDays) || !/^\d+$/.test(String(input.trashDays).trim())) throw new Error('Bitte ganze Tage eingeben; 0 bedeutet unbegrenzt.');
    return {trashDays:integer(input.trashDays,0,36500,'Papierkorb-Aufbewahrung in Tagen')};
}
export function validateSourceDraft(kind, input) {
    const intervalMinutes=integer(input.intervalMinutes,0,1440,'Abrufintervall in Minuten');
    if (intervalMinutes>0 && intervalMinutes<5) throw new Error('Abrufintervall in Minuten: 0 (nur manuell) oder 5 bis 1440.');
    const common = {username:text(input.username),folder:text(input.folder,768),intervalMinutes};
    if (!common.username || !common.folder) throw new Error('Benutzername und Quellordner fehlen.');
    if (kind === 'imap') {
        const host = text(input.host);
        if (!/^[a-z0-9.-]+$/i.test(host) || !['tls','starttls'].includes(input.security) || !['password','oauth2'].includes(input.auth)) throw new Error('Bitte Mailserver (ohne URL), TLS-Verfahren und Anmeldung wählen.');
        return {...common,host,port:integer(input.port,1,65535,'Port'),security:input.security,auth:input.auth};
    }
    if (kind === 'webdav') {
        const url = new URL(text(input.url,768));
        if (url.protocol !== 'https:' || url.username || url.password || url.search || url.hash) throw new Error('WebDAV benötigt eine HTTPS-URL ohne Zugangsdaten, Query oder Fragment.');
        if (!common.folder.startsWith('/') || common.folder.split('/').includes('..')) throw new Error('WebDAV-Ordner bitte ab / und ohne .. angeben.');
        return {...common,url:url.href,recursive:input.recursive === true};
    }
    throw new Error('Unbekannte Quelle.');
}
export class SettingsDraftStore {
    constructor(storage) {
        this.storage = storage; this.data = {global:copy(GLOBAL_DEFAULTS),users:{}};
        try {
            const saved = JSON.parse(storage?.getItem(SETTINGS_DRAFT_KEY) || 'null');
            if (saved) {
                try { this.data.global = validateGlobalDraft(saved.global); } catch { /* Ungültigen Entwurf ignorieren. */ }
                for (const id of ['1','2']) {
                    try {
                        const personal = validatePersonalDraft(saved.users?.[id]?.personal);
                        this.data.users[id] ??= {}; this.data.users[id].personal = personal;
                    } catch { /* Alte globale Fristen nicht auf persönliche Bestände übertragen. */ }
                    for (const kind of ['imap','webdav']) {
                        try {
                            const value = validateSourceDraft(kind, saved.users?.[id]?.[kind]);
                            this.data.users[id] ??= {}; this.data.users[id][kind] = value;
                        } catch { /* Nur erlaubte, validierte Felder laden. */ }
                    }
                }
            }
        } catch { /* Frischer Entwurf bei ungültigem Browserbestand. */ }
    }
    global() { return copy(this.data.global); }
    personal(userId) { return copy(this.data.users[userId]?.personal ?? PERSONAL_DEFAULTS); }
    savePersonal(userId, input) {
        if (![1,2].includes(userId)) throw new Error('Unbekanntes Vorschauprofil.');
        const personal = validatePersonalDraft(input), next = copy(this.data);
        next.users[userId] ??= {}; next.users[userId].personal = personal; this.commit(next);
    }
    source(userId, kind) { return copy(this.data.users[userId]?.[kind] ?? SOURCE_DEFAULTS[kind]); }
    saveGlobal(input) { this.commit({...this.data,global:validateGlobalDraft(input)}); }
    saveSource(userId, kind, input) {
        if (![1,2].includes(userId)) throw new Error('Unbekanntes Vorschauprofil.');
        const next = copy(this.data);
        next.users[userId] ??= {}; next.users[userId][kind] = validateSourceDraft(kind,input); this.commit(next);
    }
    commit(next) {
        if (!this.storage) throw new Error('Browser-Speicherung nicht verfügbar. Entwurf nicht gespeichert.');
        this.storage.setItem(SETTINGS_DRAFT_KEY,JSON.stringify(next)); this.data = next;
    }
}
