// One CSRF-protected POST per bounded batch. GET never deletes anything.
// A failed batch is rendered without this script, so failures do not loop.
const next = document.querySelector('form[data-tenant-delete-next]');
if (next) {
    let submitted = false;
    next.addEventListener('submit', event => {
        if (submitted) event.preventDefault();
        submitted = true;
    });
    window.setTimeout(() => { if (!submitted) next.requestSubmit(); }, 250);
}
