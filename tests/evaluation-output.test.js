import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const view=fs.readFileSync(new URL('../app/views/admin-evaluation.php',import.meta.url),'utf8');
const backend=fs.readFileSync(new URL('../app/Documents/Evaluation.php',import.meta.url),'utf8');
const script=fs.readFileSync(new URL('../public/assets/js/evaluation.js',import.meta.url),'utf8');

test('monthly evaluation output provides collapsible document rows and a PDF preview dialog',()=>{
    assert.match(view,/<details class="evaluation-document-details">/);
    for(const key of ['evaluationDate','evaluationDescription','evaluationTags','evaluationFolders','gross']) assert.match(view,new RegExp(`search\\.${key}`));
    assert.match(view,/class="text-end text-nowrap fw-medium"/);
    assert.match(view,/id="evaluationDocumentModal"/);
    assert.match(backend,/SELECT DATE_FORMAT\(ai\.invoice_date/); // Document rows are queried separately from the monthly aggregates.
    assert.match(backend,/\['documents'\]\[\]\=/);
    assert.match(script,/searchParams\.set\('api', 'file'\)/);
    assert.match(script,/removeAttribute\('src'\)/);
});
