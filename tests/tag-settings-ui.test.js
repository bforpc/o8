import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const source = path => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

test('tag changes return to the open settings overlay with a refreshed list', () => {
    const route = source('app/foundation.php');
    const view = source('app/views/admin-tags.php');
    assert.match(route, /'accounting','settings','documents'/);
    assert.match(route, /if \(field\('overlay'\)==='1'\) \$destination/);
    assert.match(view, /postFields\('tag_create','settings'\)/);
    assert.match(view, /postFields\('tag_rename','settings'\)/);
    assert.match(view, /postFields\('tag_delete','settings'\)/);
    assert.match(view, /name="section" value="settings"/);
});

test('each tag has one compact input and adjacent save/delete actions', () => {
    const view = source('app/views/admin-tags.php');
    const css = source('public/assets/css/foundation.css');
    assert.match(view, /class="tag-settings-row"/);
    assert.match(view, /class="tag-settings-rename"/);
    assert.match(view, /class="tag-settings-delete"/);
    assert.doesNotMatch(view, /<strong><\?= h\(\$tag\['name'\]\)/);
    assert.match(css, /\.tag-settings-row \{ display: flex; align-items: center/);
});
