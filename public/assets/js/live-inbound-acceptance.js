import { TagPicker, confirmNewTagSelection } from './tag-picker.js';
import { confirmDialog } from './dialog.js';
import { invoiceTotals, validateInvoice } from './invoice.js';
import { money } from './currency.js';
import { DocumentReviewPreview } from './document-review-preview.js';

const esc=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const t=(key,values={})=>window.o8Translate?.(`client.${key}`,values)||'';
const tagsMarkup=tags=>tags.length?tags.map(tag=>`<span class="tag">${esc(tag)}</span>`).join(' '):`<span class="small text-body-secondary">${esc(t('none'))}</span>`;

export function folderTreeMarkup(folders) {
    const ids=new Set(folders.map(f=>String(f.id))), seen=new Set();
    const branch=parent=>folders.filter(f=>parent===null?f.parent_id==null||!ids.has(String(f.parent_id)):String(f.parent_id)===String(parent)).map(f=>{
        if(seen.has(String(f.id)))return ''; seen.add(String(f.id));
        const children=branch(f.id);
        return `<li><label><input class="form-check-input" type="checkbox" name="folders" value="${Number(f.id)}"><span>${esc(f.name)}</span></label>${children?`<ul>${children}</ul>`:''}</li>`;
    }).join('');
    return `<ul class="accept-folder-tree">${branch(null)||`<li class="small text-body-secondary">${esc(t('noFolders'))}</li>`}</ul>`;
}

/** One acceptance draft; nothing is persisted before the final acceptance. */
export class InboundAcceptanceDialog {
    constructor(api, metadata, editor, saved, preview=null) {
        this.api=api; this.metadata=metadata; this.editor=editor; this.saved=saved;
        this.modal=document.getElementById('inboundAcceptModal'); this.form=document.getElementById('inboundAcceptForm');
        this.preview=new DocumentReviewPreview(this.modal,preview);
        this.form.addEventListener('submit',event=>this.submit(event));
        this.form.addEventListener('click',event=>{
            if(event.target.closest('[data-accept-invoice]')) this.editInvoice();
            if(event.target.closest('[data-remove-invoice]')) { this.invoice=null; this.partialInvoice=null; this.invoiceWarning=''; this.summary(); }
        });
    }
    async open(id, targetFolderId=null) {
        if (this.saving) return;
        this.preview.clear();
        this.modal.querySelector('#inboundAcceptTitle').textContent=targetFolderId===null?t('acceptToDms'):t('moveFromInboxToFolder');
        this.form.querySelector('.modal-footer [type="submit"]').textContent=targetFolderId===null?t('accept'):t('moveAction');
        this.proposal=await this.api('inboundProposal',{id});
        const p=this.proposal, meta=this.metadata();
        this.invoice=p.invoiceComplete?structuredClone(p.invoice):null;
        this.partialInvoice=p.partialInvoice?structuredClone(p.partialInvoice):null;
        this.invoiceWarning=p.invoiceWarning||'';
        const field=(name,label,type='text')=>`<label class="col-sm-6">${label}<input class="form-control" name="${name}" type="${type}" value="${esc(p[name])}" ${name==='title'?'required':''} maxlength="255"></label>`;
        this.form.querySelector('.modal-body').innerHTML=`
            <p class="small text-body-secondary">${esc(t('reviewAiSuggestions'))}</p>
            <section class="o8-info-group o8-info-group--head"><h3 class="h6">${esc(t('document'))}</h3><div class="row g-2">${field('title',t('title'))}${field('sender',t('sender'))}${field('date',t('documentDate'),'date')}${field('reference',t('reference'))}
                <label class="col-sm-6">${esc(t('documentType'))}<select class="form-select" name="documentType">${Object.entries({document:t('typeDocument'),invoice:t('typeInvoice'),credit_note:t('typeCreditNote'),contract:t('typeContract'),certificate:t('typeCertificate')}).map(([value,label])=>`<option value="${value}" ${p.documentType===value?'selected':''}>${esc(label)}</option>`).join('')}</select></label>
                ${meta.users.length?`<label class="col-sm-6">${esc(t('owner'))}<select class="form-select" name="ownerId">${meta.users.map(u=>`<option value="${u.id}" ${Number(u.id)===p.ownerId?'selected':''}>${esc(u.display_name)}</option>`).join('')}</select></label>`:''}
                <label class="col-12">${esc(t('memo'))}<textarea class="form-control" name="memo" maxlength="10000" rows="2">${esc(p.memo)}</textarea></label>
            </div></section>
            <section class="o8-info-group o8-info-group--soft"><h3 class="h6">${esc(window.o8Translate?.('search.tags')||'')}</h3>
                <details class="accept-tag-info"><summary>${esc(t('aiTagDetails'))}</summary><div class="accept-tag-comparison"><div><span class="detail-label">${esc(t('aiTagsOriginal'))}</span><div id="acceptAiTags" class="tag-list">${tagsMarkup(p.aiTags||[])}</div></div>
                <div><span class="detail-label">${esc(t('catalogMatches'))}</span><div class="tag-list">${tagsMarkup(p.matchedTags.map(t=>t.name))}</div></div>
                <div><span class="detail-label">${esc(t('ignoredNotInCatalog'))}</span><div class="tag-list">${tagsMarkup(p.ignoredTags)}</div></div></div></details>
                <div id="acceptTagPicker"></div>
            </section>
            <section class="o8-info-group"><h3 class="h6" id="acceptFoldersTitle">${esc(t('targetFolderSelection'))}</h3><div class="accept-folder-picker" role="group" aria-labelledby="acceptFoldersTitle">${folderTreeMarkup(meta.folders)}</div>
                <p class="small text-body-secondary">${esc(t('onlyAllDocuments'))}</p></section>
            <section class="o8-info-group o8-info-group--strong"><h3 class="h6">${esc(t('bookingData'))}</h3><p class="small">${esc(t('aiAmounts',{net:p.amounts.netto??'–',tax:p.amounts.mwst??'–',gross:p.amounts.brutto??'–',currency:p.amounts.waehrung??''}))}</p>
                <p class="small text-body-secondary">${esc(t('aiAmountsPartialInfo'))}</p>
                <p id="acceptInvoiceSummary" aria-live="polite"></p><button type="button" class="btn btn-surface btn-sm" data-accept-invoice>${esc(t('reviewBookingData'))}</button>
                <button type="button" class="btn btn-surface btn-sm" data-remove-invoice hidden>${esc(t('acceptWithoutBooking'))}</button>
            </section><p class="text-warning small mt-3">${esc(p.warning)}</p><div id="acceptError" class="text-danger mt-2" role="alert"></div>`;
        if (targetFolderId!==null) {
            const folder=this.form.querySelector(`[name="folders"][value="${Number(targetFolderId)}"]`);
            if (folder) folder.checked=true;
        }
        this.picker=new TagPicker(document.getElementById('acceptTagPicker'),meta.tags.map(t=>t.name),p.matchedTags.map(t=>t.name),()=>{},t('chooseTags'),true);
        this.preview.mount(); this.preview.show({id:p.id,inbound:true});
        this.summary(); bootstrap.Modal.getOrCreateInstance(this.modal).show();
    }
    summary() {
        const node=document.getElementById('acceptInvoiceSummary');
        node.textContent=t('noBookingSelected');
        if(this.invoice) {
            try { node.textContent=t('invoiceReady',{number:this.invoice.number?this.invoice.number+' · ':'',amount:money(invoiceTotals(this.invoice).grossCents,this.invoice.currency)}); }
            catch { node.textContent=t('invoiceProposalReady'); }
            if(this.proposal.invoiceCalculations?.length)node.textContent+=' '+this.proposal.invoiceCalculations.join(' ');
        } else if(this.partialInvoice) {
            const x=this.partialInvoice;
            node.textContent=t('invoicePartialReady',{net:x.net??t('invoiceInOpen'),tax:x.tax??t('invoiceInOpen'),gross:x.gross??t('invoiceInOpen'),currency:x.currency});
            if(this.invoiceWarning)node.textContent+=' '+this.invoiceWarning;
        }
        this.form.querySelector('[data-remove-invoice]').hidden=!this.invoice&&!this.partialInvoice;
    }
    editInvoice() {
        const draft=structuredClone(this.invoice||this.proposal.invoice);
        draft.sender=this.form.elements.sender.value; draft.number=this.form.elements.reference.value; draft.date=this.form.elements.date.value;
        const invoiceModal=document.getElementById('invoiceModal');
        this.modal.addEventListener('hidden.bs.modal',()=>{
            invoiceModal.addEventListener('hidden.bs.modal',()=>bootstrap.Modal.getOrCreateInstance(this.modal).show(),{once:true});
            this.editor.open({invoice:draft,previewReference:{id:this.proposal.id,inbound:true}},this.metadata().accounting,invoice=>{this.invoice=invoice; this.partialInvoice=null; this.invoiceWarning=''; this.summary(); this.form.elements.sender.value=invoice.sender; this.form.elements.reference.value=invoice.number; this.form.elements.date.value=invoice.date;});
        },{once:true});
        bootstrap.Modal.getInstance(this.modal).hide();
    }
    async submit(event) {
        event.preventDefault(); if (this.saving) return; this.saving=true;
        const button=this.form.querySelector('[type="submit"]'); button.disabled=true;
        try {
            const newTags=await confirmNewTagSelection(this.picker,confirmDialog);
            const input=Object.fromEntries(new FormData(this.form));
            input.folders=[...this.form.querySelectorAll('[name="folders"]:checked')].map(o=>Number(o.value));
            const chosen=this.picker.values(); input.tags=this.metadata().tags.filter(t=>chosen.includes(t.name)).map(t=>Number(t.id));
            input.newTags=newTags;
            input.tagMode='replace';
            input.proposalToken=this.proposal.proposalToken;
            input.invoice=this.invoice?{...this.invoice,sender:input.sender,number:input.reference,date:input.date}:null;
            input.partialInvoice=this.partialInvoice?{...this.partialInvoice,sender:input.sender,number:input.reference,date:input.date}:null;
            if(input.invoice) validateInvoice(input.invoice);
            const result=await this.api('inboundAccept',{id:this.proposal.id,revision:this.proposal.revision,input:JSON.stringify(input)},true);
            bootstrap.Modal.getInstance(this.modal).hide(); await this.saved(result.id);
        } catch(error) { document.getElementById('acceptError').textContent=error.message; }
        finally { this.saving=false; button.disabled=false; }
    }
}
