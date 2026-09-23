// Keine Wechselkurse: Beträge bleiben immer in ihrer Belegwährung.
const supported = typeof Intl.supportedValuesOf === 'function'
    ? Intl.supportedValuesOf('currency') : ['EUR','USD','CHF','GBP','CAD','AUD','JPY','CNY','SEK','NOK','DKK','PLN','KWD'];
export const CURRENCIES = ['EUR', ...supported.filter(code => code !== 'EUR').sort()];
export function currencyDigits(currency = 'EUR') {
    if (!CURRENCIES.includes(currency)) throw new Error('Bitte eine gültige Währung auswählen.');
    return new Intl.NumberFormat('de-DE', {style:'currency', currency}).resolvedOptions().maximumFractionDigits;
}
export function money(value, currency = 'EUR') {
    if (value == null) return '–';
    const digits = currencyDigits(currency);
    return new Intl.NumberFormat('de-DE', {style:'currency', currency, currencyDisplay:'code'}).format(value / 10 ** digits);
}
export function moneyInput(value, currency = 'EUR') {
    const digits = currencyDigits(currency), scale = 10n ** BigInt(digits);
    const abs = BigInt(Math.abs(value));
    return `${value < 0 ? '-' : ''}${abs / scale}${digits ? '.' + String(abs % scale).padStart(digits, '0') : ''}`;
}
export const documentCurrency = doc => doc.invoice?.currency || 'EUR';
