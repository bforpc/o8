const esc=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const t=(key,fallback,values={})=>globalThis.window?.o8Translate?.(`client.${key}`,values)||fallback.replace(/\{([a-zA-Z][a-zA-Z0-9_]*)\}/g,(_,name)=>Object.hasOwn(values,name)?String(values[name]):'');
const present=value=>value!==null&&value!==undefined&&value!=='';
const date=value=>/^\d{4}-\d{2}-\d{2}$/.test(value||'')?value.split('-').reverse().join('.'):value||'–';

/** Display only: calculated totals come from the server, saved totals from the document. */
export function bookingSummary(proposal,{saved=false,accounts=[]}={}) {
    const invoice=proposal.invoice||{}, raw=proposal.amounts||{}, totals=proposal.bookingTotals||{};
    if(!proposal.invoiceAvailable&&!saved)return `<section class="booking-overview"><span class="detail-label">${t('bookingDataLabel','BUCHUNGSDATEN')}</span><p class="small text-body-secondary mb-0">${t('noInvoiceAmounts','Keine KI-Buchungsbeträge vorhanden.')}</p></section>`;
    const currency=invoice.currency||raw.waehrung||'EUR';
    const amount=value=>{
        if(!present(value))return '–';
        if(typeof value!=='string'&&typeof value!=='number')return t('unclearFormat','Unklares Format');
        let numeric=String(value).trim();
        if(/^-?\d{1,3}(?:\.\d{3})+,\d+$/.test(numeric))numeric=numeric.replaceAll('.','');
        else if(/^-?\d{1,3}(?:,\d{3})+\.\d+$/.test(numeric))numeric=numeric.replaceAll(',','');
        numeric=numeric.replace(',','.');
        if(!/^-?\d+(?:\.\d+)?$/.test(numeric)||!Number.isFinite(Number(numeric)))return esc(value)+' '+t('checkAmount','(prüfen)');
        try{return esc(new Intl.NumberFormat('de-DE',{style:'currency',currency,currencyDisplay:'code'}).format(Number(numeric)));}
        catch{return esc(value)+' '+esc(currency);}
    };
    const hasRaw=['netto','mwst','brutto'].some(key=>present(raw[key]))||Boolean(raw.steuern?.length);
    const comparison=!saved||hasRaw;
    const rows=[[t('net','Netto'),'netto','net'],[t('tax','Steuer'),'mwst','tax'],[t('gross','Brutto'),'brutto','gross']].map(([label,source,key])=>{
        const value=totals[key]; const calculated=!saved&&(proposal.bookingDerived?.[key]??(!present(raw[source])&&present(value)));
        return `<tr><th scope="row">${label}</th>${comparison?`<td>${amount(raw[source])}</td>`:''}<td>${amount(value)}${calculated?`<small class="booking-origin">${t('calculated','berechnet')}</small>`:''}</td></tr>`;
    }).join('');
    const account=accounts.find(a=>String(a.id)===String(invoice.accountId));
    const taxes=(invoice.taxes||[]).map(row=>`${present(row.rate)?esc(row.rate)+' %':t('taxRateOpen','Satz offen')}: ${amount(row.amount)}`).join(' · ');
    const status=saved?(invoice.mode==='partial'?` · ${t('partialSaved','UNVOLLSTÄNDIG GESPEICHERT')}`:` · ${t('saved','GESPEICHERT')}`):` · ${t('proposal','VORSCHLAG')}`;
    return `<section class="booking-overview" aria-label="${t('bookingOverview','Buchungsübersicht')}"><span class="detail-label">${t('bookingOverview','BUCHUNGSÜBERSICHT')}${status}</span>
        <dl class="booking-overview-facts"><div><dt>${t('senderLabel','Absender')}</dt><dd>${esc(invoice.sender||'–')}</dd></div><div><dt>${t('invoiceNumber','Belegnummer')}</dt><dd>${esc(invoice.number||'–')}</dd></div><div><dt>${t('invoiceDateLabel','Belegdatum')}</dt><dd>${esc(date(invoice.date))}</dd></div><div><dt>${t('account','Konto')}</dt><dd>${esc(account?account.code+' · '+account.name:invoice.accountId?'#'+invoice.accountId:t('unassigned','Nicht zugeordnet'))}</dd></div></dl>
        <table><caption class="visually-hidden">${saved?t('savedBookingTotals','Gespeicherte Buchungssummen'):t('aiAndCalculatedValues','KI-Werte und vorhandene oder berechnete Übernahmewerte')}</caption><thead><tr><th scope="col">${t('amount','Betrag')}</th>${comparison?`<th scope="col">${t('aiOriginal','KI-Original')}</th>`:''}<th scope="col">${saved?t('saved','Gespeichert'):t('toAccept','Zur Übernahme')}</th></tr></thead><tbody>${rows}</tbody></table>
        ${taxes?`<p class="small mb-1">${t('taxLabel','MWSt: {taxes}',{taxes})}</p>`:''}
        ${proposal.invoiceWarning?`<p class="small text-warning mb-1">${esc(proposal.invoiceWarning)}</p>`:''}
        ${!saved&&proposal.invoiceCalculations?.length?`<p class="small text-body-secondary mb-0">${esc(proposal.invoiceCalculations.join(' '))}</p>`:''}
        ${!saved?`<p class="small text-body-secondary mb-0">${t('proposalNotSaved','Vorschlag – noch nicht gespeichert.')}</p>`:''}</section>`;
}

export function savedBookingSummary(doc,accounts=[]) {
    if(!doc.invoice)return '';
    let ai={};try{ai=typeof doc.ai_data==='string'?JSON.parse(doc.ai_data):doc.ai_data||{};}catch{}
    return bookingSummary({invoice:doc.invoice,amounts:ai?.betraege||{},bookingTotals:{net:doc.invoice.net,tax:doc.invoice.tax,gross:doc.invoice.gross}},{saved:true,accounts});
}
