/** Read-only original preview alongside an existing modal form. */
export class DocumentReviewPreview {
    constructor(modal,resolve,editorRoot=null) {
        this.modal=modal; this.resolve=resolve; this.editorRoot=editorRoot; this.sequence=0;
        modal.addEventListener('hidden.bs.modal',()=>{++this.sequence;this.pane?.replaceChildren();this.loaded=false;});
        modal.addEventListener('shown.bs.modal',()=>{if(this.reference&&!this.loaded)this.show(this.reference);});
    }
    mount() {
        if(!this.resolve)return;
        this.modal.classList.add('document-review-modal');
        let body=this.modal.querySelector('.modal-body');
        if(this.editorRoot) {
            if(body===this.editorRoot) {
                body=document.createElement('div'); body.className='modal-body';
                this.editorRoot.before(body); body.append(this.editorRoot);
                this.editorRoot.classList.remove('modal-body'); this.editorRoot.classList.add('document-review-fields');
            }
            if(this.pane?.isConnected)return;
        } else {
            const fields=document.createElement('div'); fields.className='document-review-fields';
            fields.append(...body.childNodes); body.append(fields);
        }
        this.pane=document.createElement('aside'); this.pane.className='document-review-preview'; this.pane.setAttribute('aria-label','Originaldokument');
        body.prepend(this.pane);
    }
    clear() {this.reference=null;++this.sequence;this.loaded=false;this.pane?.replaceChildren();}
    async show(reference) {
        this.reference=reference;
        if(!this.resolve||!this.pane||!reference)return;
        const sequence=++this.sequence; this.loaded=true;
        this.pane.textContent='Dokumentvorschau wird geladen …';
        try {
            const file=await this.resolve(reference);
            if(sequence!==this.sequence)return;
            this.pane.replaceChildren();
            const header=document.createElement('div'); header.className='document-review-preview-heading';
            const title=document.createElement('strong'); title.textContent=file.name||'Originaldokument';
            const link=document.createElement('a'); link.className='btn btn-sm btn-surface';link.textContent='Separat öffnen';link.href=file.url;link.target='_blank';link.rel='noopener noreferrer';
            header.append(title,link);this.pane.append(header);
            if(!['application/pdf','image/jpeg','image/png'].includes(file.mime))throw Error('Für diesen Dateityp ist keine Vorschau verfügbar.');
            const media=document.createElement(file.mime==='application/pdf'?'iframe':'img');
            if(media.tagName==='IFRAME')media.title='Originaldokument zur Datenerfassung';else media.alt=file.name||'Originaldokument';
            media.src=file.mime==='application/pdf'?file.url+'#view=FitH&navpanes=0':file.url;this.pane.append(media);
        } catch(error) {
            if(sequence!==this.sequence)return;
            this.pane.replaceChildren();const message=document.createElement('p');message.className='small text-body-secondary';message.textContent='Vorschau nicht verfügbar: '+error.message;this.pane.append(message);
        }
    }
}
