import test from 'node:test';
import assert from 'node:assert/strict';
import {TagPicker, confirmNewTagSelection} from '../public/assets/js/tag-picker.js';
import fs from 'node:fs';

function picker(tags, selected) {
    return {
        tags,
        selected: new Set(selected),
        same: TagPicker.prototype.same,
        values: TagPicker.prototype.values,
        newValues: TagPicker.prototype.newValues,
        toggle(name) { this.selected.delete(name); },
    };
}

test('case-insensitive existing names never request a new tag', async () => {
    const chosen=picker(['Rechnung'], ['rechnung']);
    let questions=0;
    assert.deepEqual(await confirmNewTagSelection(chosen, async () => { questions++; return true; }), []);
    assert.equal(questions, 0);
});

test('new tags require confirmation; declined tags are removed from selection', async () => {
    const chosen=picker(['Rechnung'], ['Rechnung','Neu eins','Neu zwei']);
    const asked=[];
    const result=await confirmNewTagSelection(chosen, async (_title,message) => {
        asked.push(message);
        return message.includes('Neu zwei');
    });
    assert.deepEqual(result,['Neu zwei']);
    assert.deepEqual(chosen.values(),['Rechnung','Neu zwei']);
    assert.equal(asked.length,2);
});

test('document edit sends only confirmed new tags with the normal save request', () => {
    const source=fs.readFileSync(new URL('../public/assets/js/live-workspace.js',import.meta.url),'utf8');
    assert.match(source,/confirmNewTagSelection\(picker,confirmDialog\)/);
    assert.match(source,/values\.newTags=JSON\.stringify\(newTags\)/);
    assert.match(source,/await write\('save',\{\.\.\.values,id:d\.id,revision:d\.revision\}/);
});
