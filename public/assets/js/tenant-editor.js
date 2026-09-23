const $ = id => document.getElementById(id);
export class TenantEditor {
    constructor(registry, confirm) {
        this.registry = registry; this.confirm = confirm; this.editingId = null;
        $('tenantSelect').addEventListener('change',()=>{
            const id = $('tenantSelect').value; $('tenantSelect').value = registry.current().id;
            if (id === registry.current().id) return;
            confirm('Mandant wechseln?', 'Die Arbeitsoberfläche wird neu geladen. Ungespeicherte Eingaben werden verworfen. Gespeicherte Daten bleiben beim bisherigen Mandanten.', 'Mandant wechseln',()=>{
                try { registry.switchTo(id); location.reload(); } catch (error) { $('tenantStatus').textContent = error.message; }
            },false);
        });
        $('tenantCreate').addEventListener('click',()=>this.open(null));
        $('tenantForm').addEventListener('submit',event=>{
            event.preventDefault();
            try {
                registry.save(this.editingId,$('tenantName').value,$('tenantEmail').value);
                bootstrap.Modal.getInstance($('tenantModal')).hide(); this.render();
            } catch (error) { $('tenantFormError').textContent = error.message; }
        });
        this.render();
    }
    open(tenant) {
        this.editingId = tenant?.id || null; $('tenantName').value = tenant?.name || ''; $('tenantEmail').value = tenant?.email || '';
        $('tenantFormError').textContent = ''; $('tenantTitle').textContent = tenant ? 'Mandant bearbeiten' : 'Mandant anlegen';
        bootstrap.Modal.getOrCreateInstance($('tenantModal')).show();
    }
    render() {
        const current = this.registry.current();
        $('activeTenantName').textContent = current.name;
        $('tenantSelect').replaceChildren(...this.registry.all().filter(t=>t.active).map(t=>new Option(t.name,t.id,false,t.id === current.id)));
        $('tenantList').replaceChildren();
        for (const tenant of this.registry.all()) {
            const row = document.createElement('div'); row.className = 'tag-admin-row';
            const name = document.createElement('span'); name.textContent = `${tenant.name} · ${tenant.active ? 'Aktiv' : 'Deaktiviert'}${tenant.email ? ' · '+tenant.email : ''}`;
            const edit = document.createElement('button'); edit.className = 'btn btn-sm btn-surface'; edit.type = 'button'; edit.textContent = 'Bearbeiten'; edit.setAttribute('aria-label',`Mandant ${tenant.name} bearbeiten`); edit.onclick = ()=>this.open(tenant);
            const toggle = document.createElement('button'); toggle.className = 'btn btn-sm btn-surface'; toggle.type = 'button'; toggle.textContent = tenant.active ? 'Deaktivieren' : 'Aktivieren'; toggle.disabled = tenant.id === current.id;
            toggle.setAttribute('aria-label',`Mandant ${tenant.name} ${tenant.active ? 'deaktivieren' : 'aktivieren'}`);
            toggle.onclick = ()=>this.confirm('Mandantenstatus ändern?', `${tenant.name}: ${tenant.active ? 'deaktivieren' : 'aktivieren'}? Daten werden nicht gelöscht.`, 'Bestätigen',()=>{
                try { this.registry.setActive(tenant.id,!tenant.active); this.render(); } catch (error) { $('tenantStatus').textContent = error.message; }
            });
            row.append(name,edit,toggle); $('tenantList').append(row);
        }
    }
}
