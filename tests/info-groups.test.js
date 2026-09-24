import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const source = path => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

test('information groups share theme colors and spacing', () => {
    const css = source('public/assets/css/app.css');
    assert.match(css, /\.o8-info-group--head/);
    assert.match(css, /\.o8-info-group--soft/);
    assert.match(css, /\.o8-info-group--strong/);
    assert.match(css, /background: color-mix\(in srgb, var\(--o8-accent\).*var\(--o8-surface\)/);
    assert.match(css, /#appearanceForm > fieldset/);
    assert.match(css, /#bulkEditModal \.bulk-edit-section/);
    assert.match(css, /#sourceInventory/);
});

test('inbound, acceptance, accounting and account forms use the shared groups', () => {
    for (const path of [
        'public/assets/js/live-workspace.js',
        'public/assets/js/live-inbound-acceptance.js',
        'public/assets/js/live-inbound-batch.js',
        'public/assets/js/live-invoice.js',
        'app/views/admin-account.php',
        'app/views/admin-accounting.php',
        'app/views/admin-sources.php',
        'app/views/admin-users.php',
        'app/views/admin-tenants.php',
    ]) assert.match(source(path), /o8-info-group/, path);
});
