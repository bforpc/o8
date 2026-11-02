import { TagPicker } from './tag-picker.js';
import { folderTreeMarkup } from './live-inbound-acceptance.js';
import { bookingSummary } from './booking-summary.js';
import { DocumentReviewPreview } from './document-review-preview.js';

const esc=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const t=(key,values={})=>window.o8Translate?.(`client.${key}`,values)||'';
const date=value=>/^\d{4}-\d{2}-\d{2}$/.test(value||'')?value.split('-').reverse().join('.'):t('noDate');

export class InboundBatchDialog {
    constructor(api,metadata,refresh,preview=null) {
        this.api=api; this.metadata=metadata; this.refresh=refresh;
        this.modal=document.getElementById('inboundBatchModal'); this.form=document.getElementById('inboundBatchForm');
        this.preview=new DocumentReviewPreview(this.modal,preview);
        this.modal.addEventListener('shown.bs.modal',()=>{this.modalShown=true;});
        this.modal.addEventListener('hidden.bs.modal',()=>{this.modalShown=false;});
        this.modal.addEventListener('hide.bs.modal',event=>{if(this.running||this.loading)event.preventDefault();});
        this.form.addEventListener('submit',event=>this.run(event));
        this.form.addEventListener('change',()=>this.update());
        this.form.addEventListener('click',event=>{
            const preview=event.target.closest('[data-batch-preview]');
            if(preview) this.previewRow(Number(preview.dataset.batchPreview));
            if(event.target.closest('[data-batch-folders]')) this.chooseFolders();
            if(event.target.closest('[data-batch-all]')) {
                const inputs=[...this.form.querySelectorAll('[data-batch-select]:not(:disabled)')], all=inputs.every(x=>x.checked);
                inputs.forEach(x=>x.checked=!all); this.update();
            }
            if(event.target.closest('[data-batch-stop]')) {this.stopping=true; event.target.disabled=true;}
        });
    }
    async open(items) {
        if(this.running||this.loading||this.choosingFolders)return;
        this.preview.clear();this.previewId=null;
        this.rows=[]; this.folderIds=[]; this.picker=null; this.loading=true; this.form.querySelectorAll('[data-bs-dismiss]').forEach(x=>x.disabled=true); this.form.querySelector('.modal-body').innerHTML=`<p role="status">${esc(t('inboundCheckLoading'))}</p>`;
        this.form.querySelector('[type="submit"]').disabled=true;
        bootstrap.Modal.getOrCreateInstance(this.modal).show();
        try {
            // Bounded read requests keep even a large entrance selection responsive.
            for(let offset=0;offset<items.length;offset+=25) {
                this.rows.push(...await this.api('inboundBatchPreview',{items:JSON.stringify(items.slice(offset,offset+25))},true));
                this.form.querySelector('[role="status"]').textContent=t('checkedProgress',{done:Math.min(offset+25,items.length),total:items.length});
            }
            this.render();
        } catch(error) { this.form.querySelector('.modal-body').textContent=error.message; }
        finally {this.loading=false; this.form.querySelectorAll('[data-bs-dismiss]').forEach(x=>x.disabled=false); this.update();}
    }
    render() {
        const meta=this.metadata();
        this.form.querySelector('.modal-body').innerHTML=`<p class="small text-body-secondary">${esc(t('batchIntro'))}</p>
            <section class="o8-info-group o8-info-group--soft" id="batchSharedFields" aria-labelledby="batchSharedTitle">
                <h3 class="h6" id="batchSharedTitle">${esc(t('sharedDetails'))}</h3><p class="small text-body-secondary mb-2">${esc(t('batchSharedSelection'))}</p>
                <label class="d-block mb-2">${esc(window.o8Translate?.('workspace.tags')||'')}<select class="form-select" name="tagMode"><option value="add">${esc(t('batchAddTags'))}</option><option value="replace">${esc(t('batchSetTags'))}</option></select></label><div id="batchTagPicker"></div>
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-3 mb-2"><h3 class="h6 mb-0" id="batchFoldersTitle">${esc(t('targetFolderOptional'))}</h3><button type="button" class="btn btn-surface btn-sm" data-batch-folders aria-haspopup="dialog">${esc(t('batchChooseFolders'))}</button></div>
                <div id="batchFolderSummary" class="d-flex flex-wrap gap-1" role="status" aria-live="polite"></div>
            </section>
            <section class="o8-info-group o8-info-group--head"><h3 class="h6">${esc(t('documents'))}</h3><p id="batchSelectionSummary" role="status"></p><button type="button" class="btn btn-surface btn-sm mb-2" data-batch-all>${esc(t('batchMarkEligible'))}</button>
            <ul class="inbound-batch-list">${this.rows.map(row=>`<li data-batch-row="${row.id}"><div class="d-flex gap-2 align-items-start"><input class="form-check-input" type="checkbox" data-batch-select="${row.id}" id="batch-item-${row.id}" ${row.batchEligible?'checked':'disabled'} aria-describedby="batch-reason-${row.id}"><div class="flex-grow-1"><label for="batch-item-${row.id}">${esc(row.title||row.originalName||'E'+row.id)}</label><div class="small text-body-secondary">E${row.id} · ${esc(date(row.date))} · ${esc(row.originalName||'')}</div><p class="small mb-1" id="batch-reason-${row.id}">${esc(row.batchEligible?(row.invoiceCalculations?.join(' ')||t('batchEligible')):(row.batchReasons||[]).join(' '))}</p><div class="small text-body-secondary" data-batch-tags="${row.id}"></div><strong class="small" data-batch-result="${row.id}" role="status"></strong></div></div></li>`).join('')}</ul></section>
            <div class="o8-info-group o8-info-group--strong" id="batchProgressWrap" hidden><progress id="batchProgress" class="w-100" value="0" max="1"></progress><p id="batchProgressText" role="status"></p><button type="button" class="btn btn-surface btn-sm" data-batch-stop>${esc(t('stopAfterDocument'))}</button></div><p id="batchError" class="text-danger" role="alert"></p>`;
        this.picker=new TagPicker(document.getElementById('batchTagPicker'),meta.tags.map(t=>t.name),[],()=>this.update(),window.o8Translate?.('common.tagsSearchShared')||'');
        for(const row of this.rows) {
            if(!row.invoice)continue;
            this.form.querySelector(`[data-batch-tags="${row.id}"]`).insertAdjacentHTML('beforebegin',bookingSummary(row,{accounts:meta.accounting?.accounts||[]}));
        }
        if(this.preview.resolve) {
            for(const row of this.rows.filter(row=>row.originalName)) {
                const button=document.createElement('button');button.type='button';button.className='btn btn-sm btn-surface my-2';button.dataset.batchPreview=row.id;button.textContent='Dokument ansehen';button.setAttribute('aria-pressed','false');
                this.form.querySelector(`[data-batch-tags="${row.id}"]`).before(button);
            }
            this.preview.clear();this.preview.mount();
            const first=this.rows.find(row=>row.originalName);if(first)this.previewRow(first.id);
        }
    }
    previewRow(id) {
        const row=this.rows.find(row=>Number(row.id)===Number(id));if(!row)return;
        this.previewId=Number(id);
        for(const button of this.form.querySelectorAll('[data-batch-preview]'))button.setAttribute('aria-pressed',String(Number(button.dataset.batchPreview)===Number(id)));
        this.preview.show({id:row.documentId||row.id,inbound:!row.documentId});
    }
    async chooseFolders() {
        if(this.running||this.loading||this.choosingFolders)return;
        this.choosingFolders=true; this.picker.close();
        if(!this.folderModal) {
            this.folderModal=document.createElement('div'); this.folderModal.id='inboundBatchFoldersModal'; this.folderModal.className='modal fade'; this.folderModal.tabIndex=-1;
            this.folderModal.setAttribute('aria-labelledby','inboundBatchFoldersTitle');
            this.folderModal.innerHTML=`<div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><form class="modal-content"><div class="modal-header"><h2 class="modal-title h5" id="inboundBatchFoldersTitle">${esc(t('batchFoldersTitle'))}</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="${esc(window.o8Translate?.('common.close')||'')}"></button></div><div class="modal-body"><div class="accept-folder-picker" role="group" aria-labelledby="inboundBatchFoldersTitle"></div></div><div class="modal-footer"><button type="button" class="btn btn-surface" data-bs-dismiss="modal">${esc(window.o8Translate?.('common.cancel')||'')}</button><button type="submit" class="btn btn-primary">${esc(t('batchApplyFolders'))}</button></div></form></div>`;
            document.body.append(this.folderModal);
        }
        this.folderModal.querySelector('.accept-folder-picker').innerHTML=folderTreeMarkup(this.metadata().folders);
        for(const box of this.folderModal.querySelectorAll('[name="folders"]'))box.checked=this.folderIds.includes(Number(box.value));
        if(!this.modalShown)await new Promise(resolve=>this.modal.addEventListener('shown.bs.modal',resolve,{once:true}));
        const hidden=new Promise(resolve=>this.modal.addEventListener('hidden.bs.modal',resolve,{once:true}));
        bootstrap.Modal.getOrCreateInstance(this.modal).hide(); await hidden;
        await new Promise(resolve=>{
            const submit=event=>{
                event.preventDefault();
                this.folderIds=[...this.folderModal.querySelectorAll('[name="folders"]:checked')].map(box=>Number(box.value));
                bootstrap.Modal.getInstance(this.folderModal).hide();
            };
            this.folderModal.querySelector('form').addEventListener('submit',submit);
            this.folderModal.addEventListener('hidden.bs.modal',()=>{this.folderModal.querySelector('form').removeEventListener('submit',submit);resolve();},{once:true});
            bootstrap.Modal.getOrCreateInstance(this.folderModal).show();
        });
        this.updateFolders();
        const shown=new Promise(resolve=>this.modal.addEventListener('shown.bs.modal',resolve,{once:true}));
        bootstrap.Modal.getOrCreateInstance(this.modal).show(); await shown;
        this.choosingFolders=false; this.form.querySelector('[data-batch-folders]').focus();
    }
    updateFolders() {
        const folders=new Map(this.metadata().folders.map(folder=>[Number(folder.id),folder]));
        const paths=this.folderIds.map(id=>{
            const names=[],seen=new Set();
            for(let folder=folders.get(id);folder&&!seen.has(Number(folder.id));folder=folders.get(Number(folder.parent_id))) {
                seen.add(Number(folder.id)); names.unshift(folder.name);
            }
            return names.join(' / ');
        });
        document.getElementById('batchFolderSummary').innerHTML=paths.length?paths.map(path=>`<span class="tag">${esc(path)}</span>`).join(''):`<span class="small text-body-secondary">${esc(t('noTargetFolder'))}</span>`;
    }
    update() {
        if(this.loading||!this.picker||!this.form.elements.tagMode)return;
        const ready=this.rows.filter(r=>r.batchEligible&&!r.done), selected=this.form.querySelectorAll('[data-batch-select]:checked:not(:disabled)').length;
        document.getElementById('batchSelectionSummary').textContent=t('selectedEligibleLocked',{selected,ready:ready.length,locked:this.rows.filter(r=>!r.batchEligible).length});
        this.form.querySelector('[type="submit"]').disabled=this.running||!selected;
        this.form.querySelector('[data-batch-all]').hidden=!ready.length;
        this.updateFolders();
        const manual=this.picker.values();
        for(const row of this.rows) {
            const tags=[...new Set([...(this.form.elements.tagMode.value==='add'?(row.matchedTags||[]).map(t=>t.name):[]),...manual])];
            this.form.querySelector(`[data-batch-tags="${row.id}"]`).textContent=t('tagsToImport',{tags:tags.join(', ')||t('none')})+(row.ignoredTags?.length?' · '+t('unknownAiTagsIgnored',{tags:row.ignoredTags.join(', ')}):'');
        }
    }
    async run(event) {
        event.preventDefault(); if(this.running||this.loading||this.choosingFolders)return;
        const selected=new Set([...this.form.querySelectorAll('[data-batch-select]:checked:not(:disabled)')].map(x=>Number(x.dataset.batchSelect)));
        const rows=this.rows.filter(row=>selected.has(Number(row.id))&&row.batchEligible&&!row.done); if(!rows.length)return;
        const manual=this.picker.values(), shared={tagMode:this.form.elements.tagMode.value,
            tags:this.metadata().tags.filter(t=>manual.includes(t.name)).map(t=>Number(t.id)),
            folders:[...this.folderIds]};
        this.running=true; this.stopping=false;
        this.form.querySelectorAll('input,select,button').forEach(x=>x.disabled=true);
        this.form.querySelectorAll('[data-bs-dismiss]').forEach(x=>x.disabled=true);
        const stop=this.form.querySelector('[data-batch-stop]'); stop.disabled=false; stop.hidden=false;
        document.getElementById('batchProgressWrap').hidden=false; document.getElementById('batchError').textContent='';
        const progress=document.getElementById('batchProgress'), summary=document.getElementById('batchProgressText'); progress.max=rows.length; progress.value=0;
        let done=0,failed=0,closeAfterSuccess=false;
        try {
            for(const row of rows) {
                if(this.stopping)break;
                summary.textContent=t('processingItem',{processed:done+failed,total:rows.length,id:row.id});
                const result=this.form.querySelector(`[data-batch-result="${row.id}"]`); result.textContent=t('acceptanceRunning');
                try {
                    const response=await this.api('inboundBatchAccept',{id:row.id,revision:row.revision,proposalToken:row.proposalToken,shared:JSON.stringify(shared)},true);
                    row.done=true; row.documentId=response.id; done++; result.textContent=t('acceptedAs',{id:response.id});
                    if(this.previewId===Number(row.id))this.previewRow(row.id);
                } catch(error) {failed++; result.textContent=t('acceptanceUnconfirmed',{error:error.message});}
                progress.value=done+failed;
            }
            summary.textContent=t('batchDone',{done,failed,remaining:rows.length-done-failed});
            await this.refresh();
            closeAfterSuccess=done===rows.length&&failed===0&&!this.stopping;
        } catch(error) {document.getElementById('batchError').textContent=error.message;}
        finally {
            this.running=false; stop.hidden=true;
            this.form.querySelectorAll('input,select,button').forEach(x=>x.disabled=false);
            for(const row of this.rows) {const box=this.form.querySelector(`[data-batch-select="${row.id}"]`);box.disabled=!row.batchEligible||Boolean(row.done);if(row.done)box.checked=false;}
            this.update();
            if(closeAfterSuccess)bootstrap.Modal.getOrCreateInstance(this.modal).hide();
        }
    }
}
