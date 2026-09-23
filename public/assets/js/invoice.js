import { currencyDigits } from './currency.js';
// Historische *Cents-Felder enthalten kleinste Währungseinheiten (EUR: Cent).
// Steuersätze in Basispunkten, Mengen mit bis zu drei Nachkommastellen.
// Rundung pro Position, kaufmännisch (auch bei negativen Beträgen).
export function validDate(value) {
    return /^\d{4}-\d{2}-\d{2}$/.test(value || '') && !Number.isNaN(Date.parse(value))
        && new Date(value).toISOString().slice(0, 10) === value;
}
export function decimal(value, digits = 2, label = 'Betrag') {
    const string = String(value ?? '').trim().replace(',', '.');
    if (!new RegExp(`^-?\\d+${digits ? `(?:\\.\\d{1,${digits}})?` : ''}$`).test(string)) throw new Error(`${label}: Bitte eine Zahl mit höchstens ${digits} Nachkommastellen eingeben.`);
    const negative = string.startsWith('-');
    const [whole, fraction = ''] = string.replace('-', '').split('.');
    const result = BigInt(whole) * 10n ** BigInt(digits) + BigInt(fraction.padEnd(digits, '0'));
    if (result > BigInt(Number.MAX_SAFE_INTEGER)) throw new Error(`${label} ist zu groß.`);
    return Number(negative ? -result : result);
}
export function roundRatio(numerator, denominator) {
    const sign = numerator < 0n ? -1n : 1n;
    const value = numerator < 0n ? -numerator : numerator;
    const rounded = sign * ((value + denominator / 2n) / denominator);
    if (rounded > BigInt(Number.MAX_SAFE_INTEGER) || rounded < BigInt(-Number.MAX_SAFE_INTEGER)) throw new Error('Betrag ist zu groß.');
    return Number(rounded);
}
function rate(value) {
    const result = decimal(value, 2, 'MWSt-Satz');
    if (result < 0 || result > 10000) throw new Error('MWSt-Satz muss zwischen 0 und 100 % liegen.');
    return result;
}
function sum(values) {
    const result = values.reduce((total, value) => total + BigInt(value), 0n);
    if (result > BigInt(Number.MAX_SAFE_INTEGER) || result < BigInt(-Number.MAX_SAFE_INTEGER)) throw new Error('Gesamtsumme ist zu groß.');
    return Number(result);
}
export function calculateLine(item, currency = 'EUR') {
    const quantity = decimal(item.quantity, 3, 'Menge');
    if (quantity <= 0) throw new Error('Menge muss größer als null sein. Für Gutschriften einen negativen Einzelpreis verwenden.');
    const rateBasisPoints = rate(item.vatRate);
    const prices = unitPrices(item, currency);
    let netCents, taxCents, grossCents;
    if (item.priceBasis === 'gross') {
        grossCents = roundRatio(BigInt(quantity) * BigInt(prices.unitGrossCents), 1000n);
        netCents = roundRatio(BigInt(grossCents) * 10000n, BigInt(10000 + rateBasisPoints));
        taxCents = sum([grossCents, -netCents]);
    } else {
        netCents = roundRatio(BigInt(quantity) * BigInt(prices.unitNetCents), 1000n);
        taxCents = roundRatio(BigInt(netCents) * BigInt(rateBasisPoints), 10000n);
        grossCents = sum([netCents, taxCents]);
    }
    return { netCents, taxCents, grossCents, rateBasisPoints };
}
export function unitPrices(item, currency = 'EUR') {
    const digits = currencyDigits(currency);
    const factor = BigInt(10000 + rate(item.vatRate));
    if (item.priceBasis && !['net', 'gross'].includes(item.priceBasis)) throw new Error('Ungültige Preisgrundlage.');
    if (item.priceBasis === 'gross') {
        const unitGrossCents = decimal(item.unitGross, digits, 'Einzelpreis brutto');
        return { unitGrossCents, unitNetCents: roundRatio(BigInt(unitGrossCents) * 10000n, factor) };
    }
    const unitNetCents = decimal(item.unitNet, digits, 'Einzelpreis netto');
    return { unitNetCents, unitGrossCents: roundRatio(BigInt(unitNetCents) * factor, 10000n) };
}
export const centsInput = cents => `${cents < 0 ? '-' : ''}${BigInt(Math.abs(cents)) / 100n}.${String(Math.abs(cents) % 100).padStart(2, '0')}`;
export function invoiceTotals(invoice) {
    const digits = currencyDigits(invoice.currency);
    let lines = [], netCents = 0;
    const taxes = new Map();
    if (invoice.mode === 'items') {
        if (!invoice.items?.length) throw new Error('Bitte mindestens eine Position erfassen.');
        lines = invoice.items.map(item => calculateLine(item, invoice.currency));
        netCents = sum(lines.map(line => line.netCents));
        for (const line of lines) taxes.set(line.rateBasisPoints, sum([taxes.get(line.rateBasisPoints) || 0, line.taxCents]));
    } else if (invoice.mode === 'totals') {
        netCents = decimal(invoice.net, digits, 'Nettosumme');
        for (const row of invoice.taxes || []) {
            const key = rate(row.rate);
            const taxCents = decimal(row.amount, digits, 'MWSt-Betrag');
            if (key === 0 && taxCents !== 0) throw new Error('Bei 0 % muss der MWSt-Betrag null sein.');
            taxes.set(key, sum([taxes.get(key) || 0, taxCents]));
        }
    } else throw new Error('Erfassungsart fehlt.');
    const taxCents = sum([...taxes.values()]);
    return { netCents, taxCents, grossCents: sum([netCents, taxCents]), taxes: [...taxes].sort((a,b) => a[0] - b[0]).map(([rateBasisPoints, taxCents]) => ({ rateBasisPoints, taxCents })), lines };
}
export function newInvoice(doc) {
    // Ohne Buchungsdaten keine Steuer unterstellen, z. B. bei einer Spende.
    return { sender: doc.sender || '', number: doc.reference || '', date: doc.date || '', accountCode: '', currency: 'EUR', mode: 'totals', net: centsInput(doc.amountCents ?? 0), taxes: [], items: [] };
}
export function validateInvoice(invoice) {
    if (!invoice.sender?.trim()) throw new Error('Bitte den Absender eingeben.');
    if (!validDate(invoice.date)) throw new Error('Bitte ein gültiges Belegdatum eingeben.');
    currencyDigits(invoice.currency);
    return invoiceTotals(invoice);
}
