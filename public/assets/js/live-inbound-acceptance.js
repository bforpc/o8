import { TagPicker } from './tag-picker.js';
import { invoiceTotals, validateInvoice } from './invoice.js';
import { money } from './currency.js';
import { DocumentReviewPreview } from './document-review-preview.js';

const esc=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const tagsMarkup=tags=>tags.length?tags.map(tag=>`<span class="tag">${esc(tag)}</span>`).join(' '):'<span class="small text-body-secondary">Keine</span>';

export function folderTreeMarkup(folders) {
    const ids=new Set(folders.map(f=>String(f.id))), seen=new Set();
    const branch=parent=>folders.filter(f=>parent===null?f.parent_id==null||!ids.has(String(f.parent_id)):String(f.parent_id)===String(parent)).map(f=>{
        if(seen.has(String(f.id)))return ''; seen.add(String(f.id));
        const children=branch(f.id);
        return `<li><label><input class="form-check-input" type="checkbox" name="folders" value="${Number(f.id)}"><span>${esc(f.name)}</span></label>${children?`<ul>${children}</ul>`:''}</li>`;
    }).join('');
    return `<ul class="accept-folder-tree">${branch(null)||'<li class="small text-body-secondary">Keine Ordner vorhanden.</li>'}</ul>`;
}

/** One acceptance draft; nothing is persisted before the final acceptance. */
export class InboundAcceptanceDialog {
    constructor(api, metadata, editor, saved, preview=null) {
        this.api=api; this.metadata=metadata; this.editor=editor; this.saved=saved;
        this.modal=document.getElementById('inboundAcceptModal'); this.form=document.getElementById('inboundAcceptForm');
        this.preview=new DocumentReviewPreview(this.modal,preview);
        this.form.addEventListener('submit',event=>this.submit(event));
        this.form.addEventListener('change',event=>{if(event.target.name==='tagMode')this.tagSummary();});
        this.form.addEventListener('click',event=>{
            if(event.target.closest('[data-accept-invoice]')) this.editInvoice();
            if(event.target.closest('[data-remove-invoice]')) { this.invoice=null; this.invoiceWarning=''; this.summary(); }
        });
    }
    async open(id) {
        if (this.saving) return;
        this.preview.clear();
        this.proposal=await this.api('inboundProposal',{id});
        const p=this.proposal, meta=this.metadata();
        this.invoice=p.invoiceAvailable?structuredClone(p.invoice):null; this.invoiceWarning=p.invoiceWarning||'';
        const field=(name,label,type='text')=>`<label class="col-sm-6">${label}<input class="form-control" name="${name}" type="${type}" value="${esc(p[name])}" ${name==='title'?'required':''} maxlength="255"></label>`;
        this.form.querySelector('.modal-body').innerHTML=`
            <p class="small text-body-secondary">KI-Vorschläge prüfen und bei Bedarf korrigieren. Erst „Übernehmen“ speichert das Dokument im DMS.</p>
            <div class="row g-2">${field('title','Titel')}${field('sender','Absender')}${field('date','Dokumentdatum','date')}${field('reference','Referenz')}
                <label class="col-sm-6">Dokumentart<select class="form-select" name="documentType">${Object.entries({document:'Dokument',invoice:'Rechnung',credit_note:'Gutschrift',contract:'Vertrag',certificate:'Bescheinigung'}).map(([value,label])=>`<option value="${value}" ${p.documentType===value?'selected':''}>${label}</option>`).join('')}</select></label>
                ${meta.users.length?`<label class="col-sm-6">Besitzer<select class="form-select" name="ownerId">${meta.users.map(u=>`<option value="${u.id}" ${Number(u.id)===p.ownerId?'selected':''}>${esc(u.display_name)}</option>`).join('')}</select></label>`:''}
                <label class="col-12">Notiz<textarea class="form-control" name="memo" maxlength="10000" rows="2">${esc(p.memo)}</textarea></label>
            </div>
            <section class="mt-3"><h3 class="h6">Tags</h3>
                <div class="accept-tag-comparison"><div><span class="detail-label">KI-Tags (Originalvorschlag)</span><div id="acceptAiTags" class="tag-list">${tagsMarkup(p.aiTags||[])}</div></div>
                <div><span class="detail-label">Im Katalog erkannt</span><div class="tag-list">${tagsMarkup(p.matchedTags.map(t=>t.name))}</div></div>
                <div><span class="detail-label">Ignoriert – nicht im aktiven Katalog</span><div class="tag-list">${tagsMarkup(p.ignoredTags)}</div></div></div>
                <label class="d-block my-2">Umgang mit KI-Tags<select class="form-select" name="tagMode"><option value="add">Erkannte Tags verwenden und manuelle Tags ergänzen</option><option value="replace">KI-Tags ignorieren – nur manuelle Auswahl verwenden</option></select></label>
                <div id="acceptTagPicker"></div><span class="detail-label mt-2">Diese Tags werden übernommen</span><div id="acceptFinalTags" class="tag-list" aria-live="polite"></div>
            </section>
            <section class="mt-3"><h3 class="h6" id="acceptFoldersTitle">Zielordner (optional)</h3><div class="accept-folder-picker" role="group" aria-labelledby="acceptFoldersTitle">${folderTreeMarkup(meta.folders)}</div>
                <p class="small text-body-secondary">Ohne Auswahl erscheint das Dokument nur unter „Alle Dokumente“. Jede Markierung verknüpft genau diesen Ordner.</p></section>
            <section class="mt-3"><h3 class="h6">Buchungsdaten</h3><p class="small">KI-Summen: Netto ${esc(p.amounts.netto??'–')} · Steuer ${esc(p.amounts.mwst??'–')} · Brutto ${esc(p.amounts.brutto??'–')} ${esc(p.amounts.waehrung??'')}</p>
                <p class="small text-body-secondary">Vorhandene KI-Buchungsdaten werden automatisch mit übernommen. Bitte prüfen; unklare Angaben müssen korrigiert werden.</p>
                <p id="acceptInvoiceSummary" aria-live="polite"></p><button type="button" class="btn btn-surface btn-sm" data-accept-invoice>Buchungsdaten prüfen / erfassen …</button>
                <button type="button" class="btn btn-surface btn-sm" data-remove-invoice hidden>Ohne Buchungsdaten übernehmen</button>
            </section><p class="text-warning small mt-3">${esc(p.warning)}</p><div id="acceptError" class="text-danger mt-2" role="alert"></div>`;
        this.picker=new TagPicker(document.getElementById('acceptTagPicker'),meta.tags.map(t=>t.name),[],()=>this.tagSummary(),'Manuelle Tags suchen');
        this.preview.mount(); this.preview.show({id:p.id,inbound:true});
        this.tagSummary(); this.summary(); bootstrap.Modal.getOrCreateInstance(this.modal).show();
    }
    tagSummary() {
        const ai=this.form.elements.tagMode.value==='add'?this.proposal.matchedTags.map(t=>t.name):[];
        document.getElementById('acceptFinalTags').innerHTML=tagsMarkup([...new Set([...ai,...this.picker.values()])]);
    }
    summary() {
        const node=document.getElementById('acceptInvoiceSummary');
        node.textContent='Keine Buchungsdaten zur Übernahme ausgewählt.';
        if(this.invoice) {
            try { node.textContent=`Automatisch zur Übernahme: ${this.invoice.number?this.invoice.number+' · ':''}${money(invoiceTotals(this.invoice).grossCents,this.invoice.currency)} brutto`; }
            catch { node.textContent='KI-Buchungsdaten zur Übernahme vorgemerkt; bitte fehlende Angaben ergänzen.'; }
            if(this.invoiceWarning)node.textContent+=' '+this.invoiceWarning;
            else if(this.proposal.invoiceCalculations?.length)node.textContent+=' '+this.proposal.invoiceCalculations.join(' ');
        }
        this.form.querySelector('[data-remove-invoice]').hidden=!this.invoice;
    }
    editInvoice() {
        const draft=structuredClone(this.invoice||this.proposal.invoice);
        draft.sender=this.form.elements.sender.value; draft.number=this.form.elements.reference.value; draft.date=this.form.elements.date.value;
        const invoiceModal=document.getElementById('invoiceModal');
        this.modal.addEventListener('hidden.bs.modal',()=>{
            invoiceModal.addEventListener('hidden.bs.modal',()=>bootstrap.Modal.getOrCreateInstance(this.modal).show(),{once:true});
            this.editor.open({invoice:draft,previewReference:{id:this.proposal.id,inbound:true}},this.metadata().accounting,invoice=>{this.invoice=invoice; this.invoiceWarning=''; this.summary(); this.form.elements.sender.value=invoice.sender; this.form.elements.reference.value=invoice.number; this.form.elements.date.value=invoice.date;});
        },{once:true});
        bootstrap.Modal.getInstance(this.modal).hide();
    }
    async submit(event) {
        event.preventDefault(); if (this.saving) return; this.saving=true;
        const button=this.form.querySelector('[type="submit"]'); button.disabled=true;
        try {
            const input=Object.fromEntries(new FormData(this.form));
            input.folders=[...this.form.querySelectorAll('[name="folders"]:checked')].map(o=>Number(o.value));
            const chosen=this.picker.values(); input.tags=this.metadata().tags.filter(t=>chosen.includes(t.name)).map(t=>Number(t.id));
            input.proposalToken=this.proposal.proposalToken;
            input.invoice=this.invoice?{...this.invoice,sender:input.sender,number:input.reference,date:input.date}:null;
            if(input.invoice) { if(this.invoiceWarning)throw new Error(this.invoiceWarning); validateInvoice(input.invoice); }
            const result=await this.api('inboundAccept',{id:this.proposal.id,revision:this.proposal.revision,input:JSON.stringify(input)},true);
            bootstrap.Modal.getInstance(this.modal).hide(); await this.saved(result.id);
        } catch(error) { document.getElementById('acceptError').textContent=error.message; }
        finally { this.saving=false; button.disabled=false; }
    }
}
