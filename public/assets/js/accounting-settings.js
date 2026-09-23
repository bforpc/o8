import { decimal } from './invoice.js';

export const ACCOUNTING_KEY = 'o8.accounting.v1';
export const DEFAULT_ACCOUNTING = { framework: '', vatRates: ['19', '7'], accounts: [] };
export function validateAccounting(input) {
    const framework = String(input.framework || '').trim();
    if (framework.length > 100) throw new Error('Kontorahmen: höchstens 100 Zeichen.');
    if (!Array.isArray(input.vatRates) || !input.vatRates.length) throw new Error('Mindestens einen MWSt-Satz hinterlegen.');
    const vatRates = input.vatRates.map(value => {
        const number = decimal(value, 2, 'MWSt-Satz');
        if (number < 0 || number > 10000) throw new Error('MWSt-Satz muss zwischen 0 und 100 % liegen.');
        return String(number / 100);
    });
    if (new Set(vatRates).size !== vatRates.length) throw new Error('MWSt-Sätze dürfen nicht doppelt vorkommen.');
    if (!Array.isArray(input.accounts)) throw new Error('Kontenliste fehlt.');
    const accounts = input.accounts.map(row => {
        const code = String(row.code || '').trim(), name = String(row.name || '').trim();
        if (!/^[\w.-]{1,32}$/.test(code) || !name || name.length > 190) throw new Error('Jedes Konto benötigt eine eindeutige Nummer (max. 32 Zeichen) und eine Bezeichnung (max. 190 Zeichen).');
        return {code, name};
    });
    if (new Set(accounts.map(row => row.code)).size !== accounts.length) throw new Error('Kontonummern dürfen nicht doppelt vorkommen.');
    return { framework, vatRates, accounts };
}
export function validateInvoiceChoices(invoice, settings) {
    const accountCodes = new Set(settings.accounts.map(row => row.code));
    for (const code of [invoice.accountCode, ...(invoice.items || []).map(row => row.accountCode)]) {
        if (code && !accountCodes.has(code)) throw new Error(`Buchungskonto „${code}“ fehlt im Setup. Verwendete Konten können nicht entfernt werden.`);
    }
    for (const value of [...(invoice.items || []).map(row => row.vatRate), ...(invoice.taxes || []).map(row => row.rate)]) {
        if (!settings.vatRates.includes(String(decimal(value, 2, 'MWSt-Satz') / 100))) throw new Error(`MWSt-Satz „${value} %“ fehlt im Setup. Verwendete Sätze können nicht entfernt werden.`);
    }
}

export class AccountingSetup {
    constructor(store, onSave) {
        this.store = store; this.onSave = onSave;
        this.form = document.getElementById('accountingSetupForm');
        this.form.addEventListener('submit', event => {
            event.preventDefault();
            try {
                const rows = document.getElementById('setupAccounts').value.split('\n').map(row => row.trim()).filter(Boolean);
                const accounts = rows.map(row => { const split = row.indexOf(';'); if (split < 0) throw new Error('Konten bitte als Nummer; Bezeichnung eingeben, ein Konto pro Zeile.'); return {code:row.slice(0, split), name:row.slice(split + 1)}; });
                store.saveAccountingSettings({framework:document.getElementById('setupFramework').value, vatRates:document.getElementById('setupVatRates').value.split('\n').map(row => row.trim()).filter(Boolean), accounts});
                this.render(); this.onSave();
            } catch (error) { document.getElementById('accountingSetupStatus').textContent = error.message; }
        });
        this.render();
    }
    render() {
        const settings = this.store.accountingSettings();
        document.getElementById('setupFramework').value = settings.framework;
        document.getElementById('setupVatRates').value = settings.vatRates.join('\n');
        document.getElementById('setupAccounts').value = settings.accounts.map(row => `${row.code}; ${row.name}`).join('\n');
        document.getElementById('accountingSetupStatus').textContent = '';
    }
}
