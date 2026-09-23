import { confirmDialog } from './dialog.js';

let dirty = false;
document.addEventListener('input', event => {
    if (event.target.closest('form') && !event.target.closest('[data-tenant-switch]')) dirty = true;
});
document.addEventListener('change', event => {
    if (event.target.closest('form') && !event.target.closest('[data-tenant-switch]')) dirty = true;
});
document.addEventListener('submit', async event => {
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
