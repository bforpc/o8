import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const script=readFileSync(new URL('../public/assets/js/live-workspace.js',import.meta.url),'utf8');
const styles=readFileSync(new URL('../public/assets/css/live.css',import.meta.url),'utf8');
const de=JSON.parse(readFileSync(new URL('../lang/de.json',import.meta.url),'utf8')).messages;
const en=JSON.parse(readFileSync(new URL('../lang/en.json',import.meta.url),'utf8')).messages;

test('folder heading has one accessible expand/collapse-all control using saved state',()=>{
    assert.match(script,/class="folder-nav-label folder-nav-heading"/);
    assert.match(script,/data-folder-toggle-all/);
    assert.match(script,/collapsedFolderIds=allCollapsed\?new Set\(\):new Set\(expandable\.map/);
    assert.match(script,/if \(toggleAll\)[\s\S]*?void savePreferences\(\)/);
    assert.match(styles,/\.folder-expand-all\[data-action="expand"\]/);
    assert.match(styles,/\.folder-expand-all\[data-action="collapse"\]/);
    assert.equal(typeof de.workspace.expandAllFolders,'string');
    assert.equal(typeof de.workspace.collapseAllFolders,'string');
    assert.equal(typeof en.workspace.expandAllFolders,'string');
    assert.equal(typeof en.workspace.collapseAllFolders,'string');
});
