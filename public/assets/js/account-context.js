import { confirmDialog } from './dialog.js';
const t=(key,values={})=>window.o8Translate?.(key,values)||'';

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
            const message=count===0?t('dialogs.deleteTagEmpty',{name}):t('dialogs.deleteTagUsed',{name,count});
            if (await confirmDialog(t('dialogs.deleteTagTitle'),message,t('dialogs.deleteTagAction'),true)) {
                confirmation.value='yes'; dirty=false; form.requestSubmit();
            }
            return;
        }
    }
    if (event.target.matches('[data-tenant-switch]') && dirty) {
        event.preventDefault();
        const form = event.target;
        if (await confirmDialog(t('dialogs.switchTenantTitle'), t('dialogs.switchTenantMessage'), t('dialogs.switchAction'), true)) {
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
    if (await confirmDialog(t('dialogs.discardTitle'), t('dialogs.discardMessage'), t('dialogs.discardAction'), true)) {
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
