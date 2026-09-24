import test from 'node:test';
import assert from 'node:assert/strict';
import { DEFAULT_THEME, FONT_FAMILIES, FONT_SIZES, normalizeTheme, rememberedTheme } from '../public/assets/js/theme.js';

test('old appearance settings receive readable typography defaults without losing colors', () => {
    const theme = normalizeTheme({mode:'dark', density:'compact', dark:{accent:'#123456'}});
    assert.equal(theme.fontFamily, 'system');
    assert.equal(theme.fontSize, 125);
    assert.equal(theme.dark.accent, '#123456');
    assert.equal(theme.mode, 'dark');
    assert.equal(theme.density, 'compact');
});
test('only local generic font families and supported sizes are accepted', () => {
    for (const fontFamily of Object.keys(FONT_FAMILIES)) for (const fontSize of FONT_SIZES) {
        const theme = normalizeTheme({fontFamily, fontSize});
        assert.equal(theme.fontFamily, fontFamily);
        assert.equal(theme.fontSize, fontSize);
    }
    for (const fontSize of [0, -1, 201, Infinity, '150', null]) assert.equal(normalizeTheme({fontSize}).fontSize, DEFAULT_THEME.fontSize);
    for (const fontFamily of ['url(https://example.org/font)', '__proto__', 'constructor', null]) assert.equal(normalizeTheme({fontFamily}).fontFamily, DEFAULT_THEME.fontFamily);
    assert.deepEqual(normalizeTheme(null), DEFAULT_THEME);
});
test('login and administration recover the complete last DMS palette', () => {
    const theme = normalizeTheme({ mode:'dark', dark:{accent:'#d7a86e',background:'#111827',surface:'#202d3c'}, fontFamily:'serif', fontSize:150 });
    const cookie = `unrelated=1; o8_theme=${encodeURIComponent(JSON.stringify(theme))}; o8_theme_mode=dark`;
    assert.deepEqual(rememberedTheme(cookie),theme);
    assert.equal(rememberedTheme('o8_theme=%7Bbroken'),null);
});
