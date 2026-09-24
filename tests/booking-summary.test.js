import test from 'node:test';
import assert from 'node:assert/strict';
import { bookingSummary, savedBookingSummary } from '../public/assets/js/booking-summary.js';

test('overview distinguishes original and calculated amounts and keeps zero amounts',()=>{
    const html=bookingSummary({invoiceAvailable:true,invoice:{sender:'Firma',number:'R42',date:'2026-09-21',currency:'EUR',accountId:'3',taxes:[{rate:'19',amount:'19.00'}]},amounts:{brutto:'119.00'},bookingTotals:{net:'100.00',tax:'19.00',gross:'119.00'},bookingDerived:{net:true,tax:true,gross:false}},{accounts:[{id:3,code:'4900',name:'Aufwand'}]});
    assert.match(html,/KI-Original/); assert.match(html,/Zur Übernahme/); assert.equal((html.match(/class="booking-origin"/g)||[]).length,2);
    assert.match(html,/100,00/); assert.match(html,/119,00/); assert.match(html,/21\.09\.2026/); assert.match(html,/4900 · Aufwand/);
    assert.match(bookingSummary({invoiceAvailable:true,invoice:{currency:'EUR'},amounts:{mwst:0},bookingTotals:{tax:'0.00'}}),/0,00/);
});
test('incomplete values, warnings and untrusted text render without invented amounts or HTML',()=>{
    const html=bookingSummary({invoiceAvailable:true,invoice:{currency:'EUR',sender:'<script>bad</script>',taxes:[{rate:'',amount:'19'}]},amounts:{brutto:119},bookingTotals:{net:null,tax:19,gross:119},invoiceWarning:'<b>Prüfen</b>'});
    assert.match(html,/Satz offen/); assert.match(html,/&lt;script&gt;/); assert.doesNotMatch(html,/<script>/);assert.match(html,/&lt;b&gt;Prüfen/); assert.match(html,/>–</);
    assert.match(bookingSummary({invoiceAvailable:false}),/Keine KI-Buchungsbeträge/);
});
test('saved document overview uses stored amounts, never re-derives from old AI data',()=>{
    const html=savedBookingSummary({ai_data:JSON.stringify({betraege:{netto:'100',brutto:'119'}}),invoice:{currency:'EUR',net:'200.0000',tax:'38.0000',gross:'238.0000',taxes:[{rate:'19',amount:'38'}]}});
    assert.match(html,/Gespeichert/);assert.match(html,/119,00/);assert.match(html,/238,00/);assert.doesNotMatch(html,/class="booking-origin"/);
    assert.equal(savedBookingSummary({invoice:null}),'');
    assert.doesNotThrow(()=>savedBookingSummary({ai_data:'invalid',invoice:{currency:'EUR',net:'0',tax:'0',gross:'0'}}));
});
test('saved partial booking shows unknown amounts as open rather than zero',()=>{
    const html=savedBookingSummary({invoice:{mode:'partial',sender:'Firma',number:'',date:null,currency:'EUR',net:null,tax:null,gross:'119.00',taxes:[]}});
    assert.match(html,/UNVOLLSTÄNDIG GESPEICHERT/);
    assert.match(html,/119,00/);
    assert.equal((html.match(/>–</g)||[]).length>=2,true);
    assert.doesNotMatch(html,/0,00 EUR/);
});
