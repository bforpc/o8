const modal = document.querySelector('#evaluationDocumentModal');

if (modal) {
  const preview = modal.querySelector('.evaluation-document-preview');
  const title = modal.querySelector('#evaluationDocumentModalTitle');

  modal.addEventListener('show.bs.modal', (event) => {
    const trigger = event.relatedTarget;
    const documentId = trigger?.dataset.documentId;
    const context = document.body.dataset.overlayContext || document.body.dataset.o8Context || '';
    if (!/^\d+$/.test(documentId || '') || !context || !preview) {
      event.preventDefault();
      return;
    }

    const url = new URL(window.location.href);
    url.search = '';
    url.hash = '';
    url.searchParams.set('api', 'file');
    url.searchParams.set('context', context);
    url.searchParams.set('id', documentId);
    preview.src = url.toString();

    if (title) title.textContent = trigger.dataset.documentTitle || title.dataset.defaultTitle || title.textContent;
  });

  modal.addEventListener('hidden.bs.modal', () => {
    if (preview) preview.removeAttribute('src');
    if (title && title.dataset.defaultTitle) title.textContent = title.dataset.defaultTitle;
  });

  if (title) title.dataset.defaultTitle = title.textContent;
}
