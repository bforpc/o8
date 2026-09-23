import { TagPicker } from './tag-picker.js';
import { folderTreeMarkup } from './live-inbound-acceptance.js';
import { bookingSummary } from './booking-summary.js';
import { DocumentReviewPreview } from './document-review-preview.js';

const esc=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const date=value=>/^\d{4}-\d{2}-\d{2}$/.test(value||'')?value.split('-').reverse().join('.'):'Datum fehlt';

export class InboundBatchDialog {
    constructor(api,metadata,refresh,preview=null) {
        this.api=api; this.metadata=metadata; this.refresh=refresh;
        this.modal=document.getElementById('inboundBatchModal'); this.form=document.getElementById('inboundBatchForm');
        this.preview=new DocumentReviewPreview(this.modal,preview);
        this.modal.addEventListener('hide.bs.modal',event=>{if(this.running||this.loading)event.preventDefault();});
        this.form.addEventListener('submit',event=>this.run(event));
        this.form.addEventListener('change',()=>this.update());
        this.form.addEventListener('click',event=>{
            const preview=event.target.closest('[data-batch-preview]');
            if(preview) this.previewRow(Number(preview.dataset.batchPreview));
            if(event.target.closest('[data-batch-all]')) {
                const inputs=[...this.form.querySelectorAll('[data-batch-select]:not(:disabled)')], all=inputs.every(x=>x.checked);
                inputs.forEach(x=>x.checked=!all); this.update();
            }
            if(event.target.closest('[data-batch-stop]')) {this.stopping=true; event.target.disabled=true;}
        });
    }
    async open(items) {
        if(this.running||this.loading)return;
        this.preview.clear();this.previewId=null;
        this.rows=[]; this.picker=null; this.loading=true; this.form.querySelectorAll('[data-bs-dismiss]').forEach(x=>x.disabled=true); this.form.querySelector('.modal-body').innerHTML='<p role="status">KI-Daten und Übernahmefähigkeit werden geprüft …</p>';
        this.form.querySelector('[type="submit"]').disabled=true;
        bootstrap.Modal.getOrCreateInstance(this.modal).show();
        try {
            // Bounded read requests keep even a large entrance selection responsive.
            for(let offset=0;offset<items.length;offset+=25) {
                this.rows.push(...await this.api('inboundBatchPreview',{items:JSON.stringify(items.slice(offset,offset+25))},true));
                this.form.querySelector('[role="status"]').textContent=`${Math.min(offset+25,items.length)} von ${items.length} geprüft …`;
            }
            this.render();
        } catch(error) { this.form.querySelector('.modal-body').textContent=error.message; }
        finally {this.loading=false; this.form.querySelectorAll('[data-bs-dismiss]').forEach(x=>x.disabled=false); this.update();}
    }
    render() {
        const meta=this.metadata();
        this.form.querySelector('.modal-body').innerHTML=`<p class="small text-body-secondary">Titel und Dokumentdatum kommen aus der KI. Vollständige oder eindeutig errechnete Buchungsdaten werden mit übernommen; Dokumente ohne Buchungsbezug benötigen keine Buchungsdaten. Unvollständige Dokumente sind gesperrt – bitte einzeln bearbeiten oder erneut analysieren.</p>
            <p id="batchSelectionSummary" role="status"></p><button type="button" class="btn btn-surface btn-sm mb-2" data-batch-all>Geeignete markieren / demarkieren</button>
            <ul class="inbound-batch-list">${this.rows.map(row=>`<li data-batch-row="${row.id}"><div class="d-flex gap-2 align-items-start"><input class="form-check-input" type="checkbox" data-batch-select="${row.id}" id="batch-item-${row.id}" ${row.batchEligible?'checked':'disabled'} aria-describedby="batch-reason-${row.id}"><div class="flex-grow-1"><label for="batch-item-${row.id}">${esc(row.title||row.originalName||'E'+row.id)}</label><div class="small text-body-secondary">E${row.id} · ${esc(date(row.date))} · ${esc(row.originalName||'')}</div><p class="small mb-1" id="batch-reason-${row.id}">${esc(row.batchEligible?(row.invoiceCalculations?.join(' ')||'Übernahmefähig.'):(row.batchReasons||[]).join(' '))}</p><div class="small text-body-secondary" data-batch-tags="${row.id}"></div><strong class="small" data-batch-result="${row.id}" role="status"></strong></div></div></li>`).join('')}</ul>
            <fieldset class="mt-3" id="batchSharedFields"><legend>Gemeinsame Angaben</legend><label class="d-block mb-2">Tags<select class="form-select" name="tagMode"><option value="add">Vorhandene KI-Tags verwenden und manuelle Tags ergänzen</option><option value="replace">KI-Tags verwerfen – nur manuelle Tags verwenden</option></select></label><div id="batchTagPicker"></div><h3 class="h6 mt-3" id="batchFoldersTitle">Zielordner (optional)</h3><div class="accept-folder-picker" role="group" aria-labelledby="batchFoldersTitle">${folderTreeMarkup(meta.folders)}</div><p class="small text-body-secondary">Ohne Zielordner erscheinen die Dokumente unter „Alle Dokumente“. Besitzer bleiben unverändert.</p></fieldset>
            <div class="mt-3" id="batchProgressWrap" hidden><progress id="batchProgress" class="w-100" value="0" max="1"></progress><p id="batchProgressText" role="status"></p><button type="button" class="btn btn-surface btn-sm" data-batch-stop>Nach diesem Dokument anhalten</button></div><p id="batchError" class="text-danger" role="alert"></p>`;
        this.picker=new TagPicker(document.getElementById('batchTagPicker'),meta.tags.map(t=>t.name),[],()=>this.update(),'Gemeinsame Tags suchen');
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
    update() {
        if(this.loading||!this.picker||!this.form.elements.tagMode)return;
        const ready=this.rows.filter(r=>r.batchEligible&&!r.done), selected=this.form.querySelectorAll('[data-batch-select]:checked:not(:disabled)').length;
        document.getElementById('batchSelectionSummary').textContent=`${selected} ausgewählt · ${ready.length} übernahmefähig · ${this.rows.filter(r=>!r.batchEligible).length} gesperrt`;
        this.form.querySelector('[type="submit"]').disabled=this.running||!selected;
        this.form.querySelector('[data-batch-all]').hidden=!ready.length;
        const manual=this.picker.values();
        for(const row of this.rows) {
            const tags=[...new Set([...(this.form.elements.tagMode.value==='add'?(row.matchedTags||[]).map(t=>t.name):[]),...manual])];
            this.form.querySelector(`[data-batch-tags="${row.id}"]`).textContent=`Tags zur Übernahme: ${tags.join(', ')||'Keine'}${row.ignoredTags?.length?' · Unbekannte KI-Tags ignoriert: '+row.ignoredTags.join(', '):''}`;
        }
    }
    async run(event) {
        event.preventDefault(); if(this.running||this.loading)return;
        const selected=new Set([...this.form.querySelectorAll('[data-batch-select]:checked:not(:disabled)')].map(x=>Number(x.dataset.batchSelect)));
        const rows=this.rows.filter(row=>selected.has(Number(row.id))&&row.batchEligible&&!row.done); if(!rows.length)return;
        const manual=this.picker.values(), shared={tagMode:this.form.elements.tagMode.value,
            tags:this.metadata().tags.filter(t=>manual.includes(t.name)).map(t=>Number(t.id)),
            folders:[...this.form.querySelectorAll('[name="folders"]:checked')].map(x=>Number(x.value))};
        this.running=true; this.stopping=false;
        this.form.querySelectorAll('input,select,button').forEach(x=>x.disabled=true);
        this.form.querySelectorAll('[data-bs-dismiss]').forEach(x=>x.disabled=true);
        const stop=this.form.querySelector('[data-batch-stop]'); stop.disabled=false; stop.hidden=false;
        document.getElementById('batchProgressWrap').hidden=false; document.getElementById('batchError').textContent='';
        const progress=document.getElementById('batchProgress'), summary=document.getElementById('batchProgressText'); progress.max=rows.length; progress.value=0;
        let done=0,failed=0;
        try {
            for(const row of rows) {
                if(this.stopping)break;
                summary.textContent=`${done+failed} von ${rows.length} verarbeitet – E${row.id} wird übernommen …`;
                const result=this.form.querySelector(`[data-batch-result="${row.id}"]`); result.textContent='Übernahme läuft …';
                try {
                    const response=await this.api('inboundBatchAccept',{id:row.id,revision:row.revision,proposalToken:row.proposalToken,shared:JSON.stringify(shared)},true);
                    row.done=true; row.documentId=response.id; done++; result.textContent=`Übernommen als D${response.id}`;
                    if(this.previewId===Number(row.id))this.previewRow(row.id);
                } catch(error) {failed++; result.textContent=`Übernahme nicht bestätigt: ${error.message}`;}
                progress.value=done+failed;
            }
            summary.textContent=`${done} übernommen · ${failed} fehlgeschlagen · ${rows.length-done-failed} nicht verarbeitet. Erfolgreiche Übernahmen bleiben gespeichert.`;
            await this.refresh();
        } catch(error) {document.getElementById('batchError').textContent=error.message;}
        finally {
            this.running=false; stop.hidden=true;
            this.form.querySelectorAll('input,select,button').forEach(x=>x.disabled=false);
            for(const row of this.rows) {const box=this.form.querySelector(`[data-batch-select="${row.id}"]`);box.disabled=!row.batchEligible||Boolean(row.done);if(row.done)box.checked=false;}
            this.update();
        }
    }
}
