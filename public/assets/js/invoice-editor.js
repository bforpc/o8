import { newInvoice, invoiceTotals, calculateLine, unitPrices } from './invoice.js';
import { AccountPicker } from './account-picker.js';
import { CURRENCIES, money as formatMoney, moneyInput } from './currency.js';

const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
export class InvoiceEditor {
    constructor(store, onSave) {
        this.store = store; this.onSave = onSave;
        this.root = document.getElementById('invoiceEditor');
        this.form = document.getElementById('invoiceForm');
        this.form.addEventListener('submit', event => this.save(event));
        this.root.addEventListener('input', event => {
            this.read();
            const field = event.target.dataset.itemField;
            if (['unitNet', 'unitGross'].includes(field)) this.draft.items[Number(event.target.dataset.index)].priceBasis = field === 'unitGross' ? 'gross' : 'net';
            this.calculate();
        });
        this.root.addEventListener('change', event => {
            if (event.target.id === 'invoiceCurrency') {
                this.read();
                // Keine Umrechnung; lediglich unnötige Nachkomma-Nullen entfernen.
                const trimZeros = value => String(value).replace(',', '.').replace(/(\.\d*?[1-9])0+$|\.0+$/, '$1');
                this.draft.net = trimZeros(this.draft.net);
                this.draft.taxes.forEach(row => { row.amount = trimZeros(row.amount); });
                this.draft.items.forEach(item => { item.unitNet = trimZeros(item.unitNet); item.unitGross = trimZeros(item.unitGross); });
                this.render(); return;
            }
            if (event.target.id !== 'invoiceMode') return;
            // Beide Entwürfe bleiben bis zum Speichern bestehen. Beim Wechsel zu
            // Summen die gerade berechneten Positionen als Ausgangswerte anbieten.
            this.read(false);
            const mode = event.target.value;
            if (mode === 'totals' && this.draft.mode === 'items') {
                try {
                    const totals = invoiceTotals(this.draft);
                    this.draft.net = moneyInput(totals.netCents, this.draft.currency);
                    this.draft.taxes = totals.taxes.map(row => ({rate: String(row.rateBasisPoints / 100), amount: moneyInput(row.taxCents, this.draft.currency)}));
                } catch { /* Unvollständige Positionen werden nicht als Summen übernommen. */ }
            }
            this.draft.mode = mode;
            if (mode === 'items' && !this.draft.items.length) this.draft.items.push(this.blankItem());
            this.render();
        });
        this.root.addEventListener('click', event => {
            const button = event.target.closest('[data-invoice-action]');
            if (!button) return;
            this.read();
            const action = button.dataset.invoiceAction;
            if (action === 'add-item') this.draft.items.push(this.blankItem());
            if (action === 'delete-item') this.draft.items.splice(Number(button.dataset.index), 1);
            if (action === 'add-tax') this.draft.taxes.push({rate:this.settings.vatRates[1] || this.settings.vatRates[0], amount:moneyInput(0, this.draft.currency)});
            if (action === 'delete-tax') this.draft.taxes.splice(Number(button.dataset.index), 1);
            this.render();
            if (action === 'add-item') this.root.querySelector('.invoice-line:last-child input')?.focus();
            if (action === 'add-tax') this.root.querySelector('.manual-tax-row:last-child input')?.focus();
        });
    }
    blankItem() { return {description:'', quantity:'1', unitNet:moneyInput(0, this.draft.currency), unitGross:moneyInput(0, this.draft.currency), priceBasis:'net', accountCode:'', vatRate:this.settings.vatRates[0]}; }
    open(doc) {
        this.documentId = doc.id; this.draft = structuredClone(doc.invoice || newInvoice(doc));
        this.settings = this.store.accountingSettings();
        if (!doc.invoice && this.draft.mode === 'items') this.draft.items = [this.blankItem()];
        this.render(); bootstrap.Modal.getOrCreateInstance(document.getElementById('invoiceModal')).show();
    }
    field(id, label, value, type = 'text') {
        return `<label class="invoice-field">${label}<input id="${id}" class="form-control" type="${type}" value="${escape(value)}" required ${type === 'text' ? 'maxlength="255"' : ''}></label>`;
    }
    render() {
        const invoice = this.draft;
        this.root.innerHTML = `<div class="invoice-header-fields">${this.field('invoiceSender','Absender',invoice.sender)}${this.field('invoiceNumber','Beleg-/Rechnungsnummer',invoice.number)}${this.field('invoiceDate','Belegdatum',invoice.date,'date')}</div>
            <section class="invoice-account"><h3 class="invoice-field-label">Buchungskonto des Belegs</h3><div id="invoiceAccountPicker"></div><p class="small text-body-secondary mt-2">Vorgabe für Positionen ohne eigenes Konto. Konten und MWSt-Sätze werden in Einstellungen → Buchhaltung gepflegt.</p></section>
            <div class="invoice-mode-row"><label for="invoiceMode">Erfassung</label><select id="invoiceMode" class="form-select"><option value="items" ${invoice.mode === 'items' ? 'selected' : ''}>Einzelpositionen</option><option value="totals" ${invoice.mode === 'totals' ? 'selected' : ''}>Nur Gesamtsummen</option></select><label for="invoiceCurrency">Währung</label><select id="invoiceCurrency" class="form-select">${CURRENCIES.map(code => `<option ${code === invoice.currency ? 'selected' : ''}>${code}</option>`).join('')}</select></div>
            <p class="small text-body-secondary">Die Währung gilt für den gesamten Beleg. Ein Wechsel rechnet Beträge nicht um. Rundung auf die Nachkommastellen der gewählten Währung.</p>
            ${invoice.mode === 'items' ? this.itemsMarkup() : this.totalsMarkup()}
            <div id="invoiceError" class="text-danger small mt-3" role="alert"></div><div id="invoiceTotals" class="invoice-totals"></div>`;
        new AccountPicker(document.getElementById('invoiceAccountPicker'), this.settings.accounts, invoice.accountCode, 'Buchungskonto des Belegs suchen', 'Noch kein Belegkonto', value => {
            this.draft.accountCode = value; this.renderItemAccounts();
        });
        this.renderItemAccounts(); this.calculate();
    }
    renderItemAccounts() {
        this.root.querySelectorAll('[data-item-account]').forEach(container => {
            const index = Number(container.dataset.itemAccount), account = this.settings.accounts.find(row => row.code === this.draft.accountCode);
            new AccountPicker(container, this.settings.accounts, this.draft.items[index].accountCode, `Position ${index + 1}: Buchungskonto suchen`, account ? `Belegkonto verwenden (${account.code} · ${account.name})` : 'Belegkonto verwenden (noch nicht gesetzt)', value => { this.draft.items[index].accountCode = value; });
        });
    }
    rateOptions(value) {
        const canonical = String(Number(String(value).replace(',', '.')));
        const missing = this.settings.vatRates.includes(canonical) ? '' : `<option value="${escape(value)}" selected disabled>${escape(value)} % · nicht im Setup</option>`;
        return missing + this.settings.vatRates.map(rate => `<option value="${escape(rate)}" ${rate === canonical ? 'selected' : ''}>${escape(rate.replace('.', ','))} %</option>`).join('');
    }
    itemsMarkup() {
        const input = (key, label, item, index, extra = '') => `<label class="line-${key}"><span>${label}</span><input class="form-control form-control-sm" data-item-field="${key}" data-index="${index}" aria-label="Position ${index + 1}: ${label}" value="${escape(item[key])}" ${key === 'description' ? 'maxlength="500"' : 'inputmode="decimal"'} ${extra}></label>`;
        return `<div class="invoice-lines">${this.draft.items.map((item, index) => `<div class="invoice-line" data-line="${index}"><div class="line-heading">Position ${index + 1}<button type="button" class="btn btn-sm icon-btn" data-invoice-action="delete-item" data-index="${index}" aria-label="Position ${index + 1} entfernen">×</button></div>${input('description','Bezeichnung',item,index)}${input('quantity','Menge',item,index)}${input('unitNet','Einzelpreis netto',item,index)}${input('unitGross','Einzelpreis brutto',item,index)}<label class="line-vatRate"><span>MWSt-Satz</span><select class="form-select form-select-sm" data-item-field="vatRate" data-index="${index}" aria-label="Position ${index + 1}: MWSt-Satz">${this.rateOptions(item.vatRate)}</select></label><div class="line-account"><span class="invoice-field-label">Buchungskonto</span><div id="itemAccountPicker-${index}" data-item-account="${index}"></div></div><div class="line-basis" data-line-basis="${index}"></div><div class="line-results"><span>Netto <strong data-line-net="${index}">–</strong></span><span>MWSt <strong data-line-tax="${index}">–</strong></span><span>Brutto <strong data-line-gross="${index}">–</strong></span></div></div>`).join('')}</div><button type="button" class="btn btn-surface mt-3" data-invoice-action="add-item">+ Position hinzufügen</button><p class="small text-body-secondary mt-2">Zuletzt geänderter Einzelpreis (Netto oder Brutto) × Menge ist die Grundlage. Der andere Preis wird anhand der MWSt berechnet; Mengen- und Steueränderungen behalten die Grundlage bei. Positionssummen werden auf die Nachkommastellen der Belegwährung gerundet. Gutschriften mit negativem Preis erfassen.</p>`;
    }
    totalsMarkup() {
        return `<div class="manual-invoice-totals"><label class="invoice-field">Gesamtsumme netto<input id="invoiceNet" class="form-control" inputmode="decimal" value="${escape(this.draft.net)}"></label><div><span class="invoice-field-label">Mehrwertsteuer laut Beleg</span><div class="manual-tax-list">${this.draft.taxes.map((row,index) => `<div class="manual-tax-row"><label>Steuersatz<select class="form-select form-select-sm" data-tax-field="rate" data-index="${index}">${this.rateOptions(row.rate)}</select></label><label>MWSt-Betrag<input class="form-control form-control-sm" inputmode="decimal" data-tax-field="amount" data-index="${index}" value="${escape(row.amount)}"></label><button type="button" class="btn icon-btn" data-invoice-action="delete-tax" data-index="${index}" aria-label="Steuersumme ${index+1} entfernen">×</button></div>`).join('')}</div><button type="button" class="btn btn-surface btn-sm mt-2" data-invoice-action="add-tax">+ Weiterer Steuersatz</button></div></div><p class="small text-body-secondary mt-3">Nettosumme und Steuerbeträge aus dem Beleg übernehmen. Brutto wird daraus berechnet. Einzelpositionen sind in dieser Erfassungsart nicht erforderlich.</p>`;
    }
    read() {
        this.draft.sender = document.getElementById('invoiceSender').value;
        this.draft.number = document.getElementById('invoiceNumber').value;
        this.draft.date = document.getElementById('invoiceDate').value;
        this.draft.currency = document.getElementById('invoiceCurrency').value;
        // Modus erst im change-Handler wechseln; input auf select darf die
        // bisherige Erfassungsart nicht vor der Umrechnung überschreiben.
        this.root.querySelectorAll('[data-item-field]').forEach(input => { this.draft.items[Number(input.dataset.index)][input.dataset.itemField] = input.value; });
        if (document.getElementById('invoiceNet')) this.draft.net = document.getElementById('invoiceNet').value;
        this.root.querySelectorAll('[data-tax-field]').forEach(input => { this.draft.taxes[Number(input.dataset.index)][input.dataset.taxField] = input.value; });
    }
    calculate() {
        const money = value => formatMoney(value, this.draft.currency);
        if (this.draft.mode === 'items') this.draft.items.forEach((item,index) => {
            const derivedField = item.priceBasis === 'gross' ? 'unitNet' : 'unitGross';
            const derivedInput = this.root.querySelector(`[data-item-field="${derivedField}"][data-index="${index}"]`);
            try {
                const prices = unitPrices(item, this.draft.currency);
                item[derivedField] = moneyInput(prices[`${derivedField}Cents`], this.draft.currency);
            } catch { item[derivedField] = ''; }
            derivedInput.value = item[derivedField];
            this.root.querySelector(`[data-line-basis="${index}"]`).textContent = `Berechnungsgrundlage: ${item.priceBasis === 'gross' ? 'Brutto' : 'Netto'}-Einzelpreis`;
            let line = null; try { line = calculateLine(item, this.draft.currency); } catch { /* Während der Eingabe darf ein Feld vorübergehend leer sein. */ }
            for (const [key, field] of [['net','netCents'],['tax','taxCents'],['gross','grossCents']]) {
                const element = this.root.querySelector(`[data-line-${key}="${index}"]`); if (element) element.textContent = line ? money(line[field]) : '–';
            }
        });
        try {
            const result = invoiceTotals(this.draft);
            document.getElementById('invoiceTotals').innerHTML = `<div><span>Netto gesamt</span><strong>${money(result.netCents)}</strong></div>${result.taxes.map(tax => `<div><span>MWSt ${(tax.rateBasisPoints/100).toLocaleString('de-DE')} %</span><strong>${money(tax.taxCents)}</strong></div>`).join('')}<div class="invoice-grand-total"><span>Brutto gesamt</span><strong>${money(result.grossCents)}</strong></div>`;
            document.getElementById('invoiceFooterGross').textContent = money(result.grossCents);
            document.getElementById('invoiceError').textContent = '';
        } catch (error) {
            document.getElementById('invoiceError').textContent = error.message;
            document.getElementById('invoiceTotals').replaceChildren(); document.getElementById('invoiceFooterGross').textContent = '–';
        }
    }
    save(event) {
        event.preventDefault(); this.read();
        try {
            this.store.saveInvoice(this.documentId, this.draft);
            bootstrap.Modal.getInstance(document.getElementById('invoiceModal')).hide(); this.onSave();
        } catch (error) { document.getElementById('invoiceError').textContent = error.message; }
    }
}
