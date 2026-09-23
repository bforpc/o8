import { decimal, validDate } from './invoice.js';
import { currencyDigits, documentCurrency } from './currency.js';

export const SEARCH_DEFAULTS = {query:'', type:'', dateFrom:'', dateTo:'', includeTags:[], excludeTags:[], amountFrom:'', amountTo:'', amountCurrency:'EUR', includeExpired:false, includeNotSearchable:false, invoiceNumbers:'', accountCode:'', ownerId:'', resultView:'all', latestCount:25, sort:'date-desc', pageSize:50};
export const PAGE_SIZES = [10, 25, 50, 100, 250];
export const SORT_LABELS = {'date-desc':'Datum ↓', 'date-asc':'Datum ↑', title:'Beschreibung A–Z', 'title-desc':'Beschreibung Z–A', 'amount-asc':'Brutto ↑', 'amount-desc':'Brutto ↓', 'invoice-asc':'Belegnr. ↑', 'invoice-desc':'Belegnr. ↓'};
const normalize = value => String(value ?? '').toLocaleLowerCase('de').normalize('NFD').replace(/\p{Diacritic}/gu, '');
const collator = new Intl.Collator('de', {numeric:true, sensitivity:'base'});
const invoiceNumber = doc => doc.invoice?.number ?? '';
const invoiceAmount = doc => doc.invoice && Number.isSafeInteger(doc.amountCents) ? doc.amountCents : null;

export function compileSearch(input = {}) {
    const options = {...SEARCH_DEFAULTS, ...input};
    if (options.ownerId !== '' && (!Number.isSafeInteger(Number(options.ownerId)) || Number(options.ownerId) < 1)) throw new Error('Bitte einen gültigen Besitzer auswählen.');
    for (const [value, label] of [[options.dateFrom,'Datum von'], [options.dateTo,'Datum bis']]) if (value && !validDate(value)) throw new Error(`${label}: Bitte ein gültiges Datum eingeben.`);
    if (options.dateFrom && options.dateTo && options.dateFrom > options.dateTo) throw new Error('Datum von darf nicht nach Datum bis liegen.');
    const digits = currencyDigits(options.amountCurrency);
    const min = String(options.amountFrom).trim() === '' ? null : decimal(options.amountFrom, digits, 'Brutto von');
    const max = String(options.amountTo).trim() === '' ? null : decimal(options.amountTo, digits, 'Brutto bis');
    if (min !== null && max !== null && min > max) throw new Error('Brutto von darf nicht größer als Brutto bis sein.');
    const included = [...new Set([...(options.includeTags || []), ...(options.tag ? [options.tag] : [])])];
    const excluded = options.excludeTags || [];
    if (included.some(tag => excluded.includes(tag))) throw new Error('Derselbe Tag kann nicht zugleich verlangt und ausgeschlossen werden.');
    const terms = normalize(options.query).trim().split(/\s+/).filter(Boolean);
    const idTerms = terms.filter(term => /^d\d+$/.test(term));
    const textTerms = terms.filter(term => !/^d\d+$/.test(term));
    const numbers = String(options.invoiceNumbers).split(/[\n;,]+/).map(value => normalize(value.trim())).filter(Boolean);
    return doc => {
        if (options.ownerId !== '' && doc.ownerId !== Number(options.ownerId)) return false;
        if ((doc.expired === true || doc.expired === 1) && options.includeExpired !== true) return false;
        if ((doc.searchable === false || doc.searchable === 0) && options.includeNotSearchable !== true) return false;
        if (!idTerms.every(term => Number(term.slice(1)) === doc.id)) return false;
        if (options.type && doc.type !== options.type) return false;
        if (options.dateFrom && (!doc.date || doc.date < options.dateFrom)) return false;
        if (options.dateTo && (!doc.date || doc.date > options.dateTo)) return false;
        if (!included.every(tag => doc.tags.includes(tag)) || excluded.some(tag => doc.tags.includes(tag))) return false;
        if (min !== null || max !== null) {
            if (documentCurrency(doc) !== options.amountCurrency) return false;
            const amount = invoiceAmount(doc);
            if (amount === null || (min !== null && amount < min) || (max !== null && amount > max)) return false;
        }
        if (numbers.length && (!doc.invoice || !numbers.some(number => normalize(invoiceNumber(doc)).includes(number)))) return false;
        if (options.accountCode) {
            const invoice = doc.invoice;
            if (!invoice) return false;
            const accounts = [invoice.accountCode];
            if (invoice.mode === 'items') accounts.push(...(invoice.items || []).map(item => item.accountCode || invoice.accountCode));
            if (!accounts.includes(options.accountCode)) return false;
        }
        const haystack = normalize([doc.id, `D${doc.id}`, doc.title, doc.sender, doc.reference, doc.memo, doc.filename, ...doc.tags].join(' '));
        return textTerms.every(term => haystack.includes(term));
    };
}
export function compareDocuments(sort = 'date-desc') {
    return (a, b) => {
        let result;
        if (sort === 'created-desc') {
            const av = Date.parse(a.createdAt), bv = Date.parse(b.createdAt);
            if (Number.isNaN(av) || Number.isNaN(bv)) return Number.isNaN(av) && Number.isNaN(bv) ? b.id - a.id : Number.isNaN(av) ? 1 : -1;
            return bv - av || b.id - a.id;
        }
        if (sort.startsWith('amount-')) {
            const av = invoiceAmount(a), bv = invoiceAmount(b);
            if (av === null || bv === null) return av === bv ? a.id - b.id : av === null ? 1 : -1;
            // Währungen gruppieren, nicht einen vermeintlichen Wechselkurs suggerieren.
            const currencyOrder = documentCurrency(a).localeCompare(documentCurrency(b));
            if (currencyOrder) return currencyOrder;
            result = av - bv;
        } else if (sort.startsWith('invoice-')) {
            const av = invoiceNumber(a), bv = invoiceNumber(b);
            if (!av || !bv) return av === bv ? a.id - b.id : !av ? 1 : -1;
            result = collator.compare(av, bv);
        } else if (sort === 'title' || sort === 'title-desc') result = a.title.localeCompare(b.title, 'de');
        else result = a.date.localeCompare(b.date) || a.id - b.id;
        return (sort.endsWith('-desc') ? -result : result) || a.id - b.id;
    };
}
export function paginate(documents, requestedPage = 1, pageSize = 50) {
    const size = PAGE_SIZES.includes(pageSize) ? pageSize : SEARCH_DEFAULTS.pageSize;
    const total = documents.length, pages = Math.max(1, Math.ceil(total / size));
    const page = Number.isSafeInteger(requestedPage) ? Math.max(1, Math.min(pages, requestedPage)) : 1;
    const offset = (page - 1) * size;
    return {rows:documents.slice(offset, offset + size), total, pages, page, from:total ? offset + 1 : 0, to:Math.min(offset + size, total)};
}
export function searchWindow(documents, options = {}) {
    const input = {...SEARCH_DEFAULTS, ...options};
    if (!['all','latest'].includes(input.resultView)) throw new Error('Unbekannte Ergebnisansicht.');
    let rows = documents;
    if (input.resultView === 'latest') {
        const count = Number(input.latestCount);
        if (!Number.isInteger(count) || count < 1 || count > 10000) throw new Error('Zuletzt hinzugefügt: Bitte 1 bis 10000 Dokumente wählen.');
        rows = [...documents].sort(compareDocuments('created-desc')).slice(0,count);
    }
    return {...paginate(rows, input.resultPage, input.pageSize), matchedTotal:documents.length};
}
