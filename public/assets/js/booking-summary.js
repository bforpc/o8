const esc=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const present=value=>value!==null&&value!==undefined&&value!=='';
const date=value=>/^\d{4}-\d{2}-\d{2}$/.test(value||'')?value.split('-').reverse().join('.'):value||'–';

/** Display only: calculated totals come from the server, saved totals from the document. */
export function bookingSummary(proposal,{saved=false,accounts=[]}={}) {
    const invoice=proposal.invoice||{}, raw=proposal.amounts||{}, totals=proposal.bookingTotals||{};
    if(!proposal.invoiceAvailable&&!saved)return '<section class="booking-overview"><span class="detail-label">BUCHUNGSDATEN</span><p class="small text-body-secondary mb-0">Keine KI-Buchungsbeträge vorhanden.</p></section>';
    const currency=invoice.currency||raw.waehrung||'EUR';
    const amount=value=>{
        if(!present(value))return '–';
        if(typeof value!=='string'&&typeof value!=='number')return 'Unklares Format';
        let numeric=String(value).trim();
        if(/^-?\d{1,3}(?:\.\d{3})+,\d+$/.test(numeric))numeric=numeric.replaceAll('.','');
        else if(/^-?\d{1,3}(?:,\d{3})+\.\d+$/.test(numeric))numeric=numeric.replaceAll(',','');
        numeric=numeric.replace(',','.');
        if(!/^-?\d+(?:\.\d+)?$/.test(numeric)||!Number.isFinite(Number(numeric)))return esc(value)+' (prüfen)';
        try{return esc(new Intl.NumberFormat('de-DE',{style:'currency',currency,currencyDisplay:'code'}).format(Number(numeric)));}
        catch{return esc(value)+' '+esc(currency);}
    };
    const hasRaw=['netto','mwst','brutto'].some(key=>present(raw[key]))||Boolean(raw.steuern?.length);
    const comparison=!saved||hasRaw;
    const rows=[['Netto','netto','net'],['Steuer','mwst','tax'],['Brutto','brutto','gross']].map(([label,source,key])=>{
        const value=totals[key]; const calculated=!saved&&(proposal.bookingDerived?.[key]??(!present(raw[source])&&present(value)));
        return `<tr><th scope="row">${label}</th>${comparison?`<td>${amount(raw[source])}</td>`:''}<td>${amount(value)}${calculated?'<small class="booking-origin">berechnet</small>':''}</td></tr>`;
    }).join('');
    const account=accounts.find(a=>String(a.id)===String(invoice.accountId));
    const taxes=(invoice.taxes||[]).map(row=>`${present(row.rate)?esc(row.rate)+' %':'Satz offen'}: ${amount(row.amount)}`).join(' · ');
    return `<section class="booking-overview" aria-label="Buchungsübersicht"><span class="detail-label">BUCHUNGSÜBERSICHT${saved?' · GESPEICHERT':' · VORSCHLAG'}</span>
        <dl class="booking-overview-facts"><div><dt>Absender</dt><dd>${esc(invoice.sender||'–')}</dd></div><div><dt>Belegnummer</dt><dd>${esc(invoice.number||'–')}</dd></div><div><dt>Belegdatum</dt><dd>${esc(date(invoice.date))}</dd></div><div><dt>Konto</dt><dd>${esc(account?account.code+' · '+account.name:invoice.accountId?'#'+invoice.accountId:'Nicht zugeordnet')}</dd></div></dl>
        <table><caption class="visually-hidden">${saved?'Gespeicherte Buchungssummen':'KI-Werte und vorhandene oder berechnete Übernahmewerte'}</caption><thead><tr><th scope="col">Betrag</th>${comparison?'<th scope="col">KI-Original</th>':''}<th scope="col">${saved?'Gespeichert':'Zur Übernahme'}</th></tr></thead><tbody>${rows}</tbody></table>
        ${taxes?`<p class="small mb-1">MWSt: ${taxes}</p>`:''}
        ${proposal.invoiceWarning?`<p class="small text-warning mb-1">${esc(proposal.invoiceWarning)}</p>`:''}
        ${!saved&&proposal.invoiceCalculations?.length?`<p class="small text-body-secondary mb-0">${esc(proposal.invoiceCalculations.join(' '))}</p>`:''}
        ${!saved?'<p class="small text-body-secondary mb-0">Vorschlag – noch nicht gespeichert.</p>':''}</section>`;
}

export function savedBookingSummary(doc,accounts=[]) {
    if(!doc.invoice)return '';
    let ai={};try{ai=typeof doc.ai_data==='string'?JSON.parse(doc.ai_data):doc.ai_data||{};}catch{}
    return bookingSummary({invoice:doc.invoice,amounts:ai?.betraege||{},bookingTotals:{net:doc.invoice.net,tax:doc.invoice.tax,gross:doc.invoice.gross}},{saved:true,accounts});
}
