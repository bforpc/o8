import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

const script=readFileSync(new URL('../public/assets/js/live-workspace.js',import.meta.url),'utf8');
const styles=readFileSync(new URL('../public/assets/css/live.css',import.meta.url),'utf8');

test('folder navigation briefly highlights an already-applied non-empty search',()=>{
    assert.match(script,/lastQuerySearch:\s*false/);
    assert.match(script,/state\.lastQuerySearch=Boolean\(input\.query\?\.trim\(\)\)/);
    assert.match(script,/if \(!state\.lastQuerySearch \|\| !input\.value\.trim\(\) \|\| !tools\) return/);
    assert.match(script,/searchFlashTimer=setTimeout\(\(\)=>tools\.classList\.remove\('search-filter-flash'\),500\)/);
    assert.match(script,/if \(button && await discard\(\)\) \{\s*flashActiveSearch\(\)/);
    assert.match(styles,/\.workspace-tools\.search-filter-flash \.search-field/);
    assert.match(styles,/color-mix\(in srgb,var\(--o8-text\) 30%,var\(--o8-surface\)\)/);
    assert.match(styles,/box-shadow: 0 0 0 3px color-mix\(in srgb,var\(--o8-accent\) 55%,transparent\)/);
});
