import { confirmDialog } from './dialog.js';

let dirty = false;
document.addEventListener('input', event => {
    if (event.target.closest('form') && !event.target.closest('[data-tenant-switch]')) dirty = true;
});
document.addEventListener('change', event => {
    if (event.target.closest('form') && !event.target.closest('[data-tenant-switch]')) dirty = true;
});
document.addEventListener('submit', async event => {
    if (event.target.matches('[data-tag-delete]')) {
        const form=event.target;
        const confirmation=form.querySelector('input[name="confirm"]');
        if (confirmation.value!=='yes') {
            event.preventDefault();
            const count=Number(form.dataset.tagCount), name=form.dataset.tagName;
            const message=count===0?`Tag „${name}“ endgültig löschen? Es ist keinem Dokument zugeordnet.`:`Tag „${name}“ ist ${count} Dokument${count===1?'':'en'} zugeordnet. Beim Löschen wird dieses Tag von allen ${count} Dokument${count===1?'':'en'} entfernt. Fortfahren?`;
            if (await confirmDialog('Tag endgültig löschen?',message,'Tag löschen',true)) {
                confirmation.value='yes'; dirty=false; form.requestSubmit();
            }
            return;
        }
    }
    if (event.target.matches('[data-tenant-switch]') && dirty) {
        event.preventDefault();
        const form = event.target;
        if (await confirmDialog('Mandant wechseln?', 'Ungespeicherte Änderungen verwerfen und den Mandanten wechseln?', 'Wechseln', true)) {
            dirty = false;
            form.requestSubmit();
        }
        return;
    }
    dirty = false;
});
document.addEventListener('click', async event => {
    const link = event.target.closest('a[href]');
    if (!dirty || !link || event.defaultPrevented || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || link.target === '_blank') return;
    event.preventDefault();
    const href = link.href;
    if (await confirmDialog('Änderungen verwerfen?', 'Ungespeicherte Änderungen gehen verloren.', 'Verwerfen', true)) {
        dirty = false;
        location.href = href;
    }
});
for (const select of document.querySelectorAll('[data-account-mode]')) {
    const update = () => {
        const form = select.closest('form');
        for (const kind of ['new', 'existing']) {
            const block = form.querySelector('[data-' + kind + '-account]');
            const enabled = select.value === kind;
            block.hidden = !enabled;
            for (const input of block.querySelectorAll('input')) input.disabled = !enabled;
        }
    };
    select.addEventListener('change', update); update();
}
