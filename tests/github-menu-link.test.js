import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const view=readFileSync(new URL('../app/views/live-workspace.php',import.meta.url),'utf8');
const de=JSON.parse(readFileSync(new URL('../lang/de.json',import.meta.url),'utf8')).messages;
const en=JSON.parse(readFileSync(new URL('../lang/en.json',import.meta.url),'utf8')).messages;

test('DMS menu links to the public GitHub project safely',()=>{
    assert.match(view,/<a class="btn btn-surface" href="https:\/\/github\.com\/bforpc\/o8" target="_blank" rel="noopener noreferrer">[\s\S]*?tr\('navigation\.githubProject'\)/);
    assert.equal(typeof de.navigation.githubProject,'string');
    assert.equal(typeof en.navigation.githubProject,'string');
});
