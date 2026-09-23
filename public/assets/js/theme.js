import { browserTenantContext } from './tenancy.js';
export const THEME_KEY = 'o8.appearance.v1';
export const themeKey = profile => profile === 'second' ? `${THEME_KEY}.second` : THEME_KEY;
// Generische lokale Schriftfamilien: keine Webfonts, Downloads oder Installation.
export const FONT_FAMILIES = {
    system: 'system-ui, sans-serif',
    sans: 'sans-serif',
    serif: 'serif',
    mono: 'monospace',
};
export const FONT_SIZES = [100, 110, 125, 150, 175, 200];
export const DEFAULT_THEME = {
    mode: 'system', density: 'comfortable',
    fontFamily: 'system', fontSize: 125,
    light: { accent: '#326d62', background: '#f3f4f0', surface: '#ffffff' },
    dark: { accent: '#8fc6b2', background: '#141b1a', surface: '#1d2725' },
};
export function validColor(value) { return /^#[0-9a-f]{6}$/i.test(value || ''); }
export function textOn(color) {
    const rgb = color.slice(1).match(/../g).map(value => parseInt(value, 16) / 255)
        .map(value => value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4);
    const luminance = rgb[0] * 0.2126 + rgb[1] * 0.7152 + rgb[2] * 0.0722;
    return (luminance + 0.05) / 0.05 > 1.05 / (luminance + 0.05) ? '#111816' : '#ffffff';
}
export function normalizeTheme(input = {}) {
    if (!input || typeof input !== 'object') input = {};
    const output = structuredClone(DEFAULT_THEME);
    if (['light', 'dark', 'system'].includes(input.mode)) output.mode = input.mode;
    if (['comfortable', 'compact'].includes(input.density)) output.density = input.density;
    if (Object.hasOwn(FONT_FAMILIES, input.fontFamily)) output.fontFamily = input.fontFamily;
    if (FONT_SIZES.includes(input.fontSize)) output.fontSize = input.fontSize;
    for (const mode of ['light', 'dark']) for (const key of ['accent', 'background', 'surface']) {
        if (validColor(input[mode]?.[key])) output[mode][key] = input[mode][key];
    }
    return output;
}
export function readTheme(profile) {
    try {
        const storage = browserTenantContext().storage;
        profile ??= storage.getItem('o8.demo.profile');
        return normalizeTheme(JSON.parse(storage.getItem(themeKey(profile)) || '{}'));
    }
    catch { return structuredClone(DEFAULT_THEME); }
}
export function applyTheme(settings = readTheme()) {
    settings = normalizeTheme(settings);
    const mode = settings.mode === 'system' ? (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light') : settings.mode;
    const colors = settings[mode];
    const root = document.documentElement;
    root.dataset.bsTheme = mode; root.dataset.density = settings.density;
    root.style.setProperty('--bs-body-font-family', FONT_FAMILIES[settings.fontFamily]);
    root.style.setProperty('--o8-font-scale', String(settings.fontSize / 100));
    root.style.setProperty('--o8-accent', colors.accent);
    root.style.setProperty('--o8-on-accent', textOn(colors.accent));
    root.style.setProperty('--o8-background', colors.background);
    root.style.setProperty('--o8-surface', colors.surface);
    root.style.setProperty('--o8-text', textOn(colors.surface) === '#ffffff' ? '#edf3f0' : '#202b27');
    return mode;
}
if (typeof document !== 'undefined' && document.documentElement.dataset.live !== '1') {
    applyTheme();
    matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => applyTheme());
}
