import { LICENSE_KEY, verifyLicense } from './license.js';
const $ = id => document.getElementById(id);
export class LicenseEditor {
    constructor(storage, context, confirm) {
        this.storage = storage; this.context = {tenantId:context.tenant.id,installationId:context.registry.data.installationId};
        this.keys = JSON.parse($('licenseSettings').dataset.publicKeys || '{}');
        $('licenseTenantId').textContent = this.context.tenantId; $('licenseInstallationId').textContent = this.context.installationId;
        $('licenseImportForm').addEventListener('submit',async event => {
            event.preventDefault(); const button = $('licenseImport'); button.disabled = true;
            try {
                const file = $('licenseFile').files[0];
                if (!file || file.size > 16384) throw new Error('Bitte eine Lizenzdatei bis 16 KB auswählen.');
                const raw = await file.text(); await this.verify(raw);
                storage.setItem(LICENSE_KEY,raw); await this.render(); $('licenseMessage').textContent = 'Signatur geprüft und Lizenz für diesen Mandanten gespeichert.';
            } catch (error) { $('licenseMessage').textContent = error.message; }
            finally { button.disabled = false; }
        });
        $('licenseRemove').addEventListener('click',()=>confirm('Supportlizenz entfernen?', 'Nur die lokal hinterlegte Lizenz dieses Mandanten entfernen? Dokumente und Community-Funktionen bleiben unverändert.', 'Lizenz entfernen', async()=>{
            try { storage.removeItem(LICENSE_KEY); await this.render(); } catch (error) { $('licenseMessage').textContent = error.message; }
        }));
        this.render();
    }
    async verify(raw) {
        try { return await verifyLicense(raw,this.keys,this.context); }
        catch (error) {
            if (error.name !== 'NotSupportedError' && !error.message.includes('sicheren Browserkontext')) throw error;
            const response = await fetch(new URL('../../license-check.php',import.meta.url),{
                method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({license:raw,...this.context}),signal:AbortSignal.timeout(10000),
            });
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.error || 'Lokale Lizenzprüfung fehlgeschlagen.');
            return result.data;
        }
    }
    async render() {
        $('licenseState').textContent = 'Community · kostenlos'; $('licenseDetails').textContent = 'Alle Grundfunktionen bleiben ohne Supportlizenz nutzbar.';
        $('licenseMessage').textContent = ''; $('licenseRemove').disabled = true;
        try {
            const raw = this.storage.getItem(LICENSE_KEY); if (!raw) return;
            $('licenseRemove').disabled = false;
            const result = await this.verify(raw);
            $('licenseState').textContent = result.status === 'active' ? 'Support aktiv' : 'Support abgelaufen · Community weiterhin nutzbar';
            $('licenseDetails').textContent = `${result.payload.customer} · ${result.payload.license_id} · Support bis einschließlich ${result.payload.support_until} (UTC)`;
        } catch (error) { $('licenseMessage').textContent = error.message; }
    }
}
