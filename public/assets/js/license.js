export const LICENSE_KEY = 'o8.support-license.v1';
const decode = value => {
    if (typeof value !== 'string' || !/^[A-Za-z0-9_-]+$/.test(value)) throw new Error('Ungültige Lizenzkodierung.');
    const bytes = Uint8Array.from(atob(value.replace(/-/g,'+').replace(/_/g,'/')),c=>c.charCodeAt(0));
    const canonical = btoa(String.fromCharCode(...bytes)).replace(/=/g,'').replace(/\+/g,'-').replace(/\//g,'_');
    if (canonical !== value) throw new Error('Ungültige Lizenzkodierung.');
    return bytes;
};
export async function verifyLicense(raw, keys, context, now = new Date(), cryptoApi = globalThis.crypto) {
    if (typeof raw !== 'string' || raw.length > 16384) throw new Error('Lizenzdatei zu groß (maximal 16 KB).');
    let envelope, payload, bytes;
    try {
        envelope = JSON.parse(raw); bytes = decode(envelope.payload);
        payload = JSON.parse(new TextDecoder('utf-8',{fatal:true}).decode(bytes));
    } catch { throw new Error('Ungültige Lizenzdatei.'); }
    if (!payload || typeof payload.key_id !== 'string' || !Object.hasOwn(keys,payload.key_id)) throw new Error('Unbekannter Aussteller. Öffentlichen Prüfschlüssel durch den Betreiber hinterlegen lassen.');
    if (!cryptoApi?.subtle) throw new Error('Offline-Signaturprüfung benötigt einen sicheren Browserkontext (HTTPS oder localhost).');
    const signature = decode(envelope.signature), publicKey = decode(keys[payload.key_id]);
    if (signature.length !== 64 || publicKey.length !== 32) throw new Error('Ungültige Signatur oder Prüfschlüssel.');
    const key = await cryptoApi.subtle.importKey('raw',publicKey,{name:'Ed25519'},false,['verify']);
    if (!await cryptoApi.subtle.verify('Ed25519',key,signature,bytes)) throw new Error('Signatur ungültig. Lizenz wurde verändert oder nicht vom Aussteller signiert.');
    if (payload.version !== 1 || payload.product !== 'o8' || payload.plan !== 'support'
        || !/^[A-Za-z0-9._-]{1,100}$/.test(payload.license_id || '')
        || typeof payload.customer !== 'string' || !payload.customer.trim() || payload.customer.length > 190
        || /[\u0000-\u001f]/.test(payload.customer)
        || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/.test(payload.issued_at || '')
        || !/^\d{4}-\d{2}-\d{2}$/.test(payload.support_until || '')
        || !Array.isArray(payload.features) || payload.features.length !== 0) throw new Error('Lizenzinhalt wird nicht unterstützt.');
    const issued = new Date(payload.issued_at), end = new Date(`${payload.support_until}T00:00:00Z`);
    if (!Number.isFinite(+issued) || !Number.isFinite(+end) || issued.toISOString().replace('.000','') !== payload.issued_at
        || end.toISOString().slice(0,10) !== payload.support_until || +issued > +now || +issued >= +end + 86400000) throw new Error('Ungültiger Lizenzzeitraum.');
    if (payload.tenant_id !== context.tenantId || payload.installation_id !== context.installationId) throw new Error('Die Lizenz gehört zu einem anderen Mandanten oder einer anderen Installation.');
    return {payload,status:+now < +end + 86400000 ? 'active' : 'expired'};
}
