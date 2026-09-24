import { applyTheme, DEFAULT_THEME, normalizeTheme, rememberedTheme, rememberTheme } from './theme.js';

const body = document.body;
let preferences = null;
try { preferences = body.dataset.o8Preferences ? JSON.parse(body.dataset.o8Preferences) : null; }
catch { preferences = null; }
const legacyMode = document.cookie.split('; ').find(value => value.startsWith('o8_theme_mode='))?.split('=')[1];
let theme = normalizeTheme(preferences?.theme ?? rememberedTheme() ?? (legacyMode ? { mode: legacyMode } : DEFAULT_THEME));
const isOverlay = body.classList.contains('overlay-page');

function display(settings) {
    applyTheme(settings);
}
display(theme);
if (preferences && !isOverlay) rememberTheme(theme);

document.getElementById('foundationThemeToggle')?.addEventListener('click', async event => {
    const button = event.currentTarget;
    const previous = theme;
    theme = normalizeTheme({ ...theme, mode: document.documentElement.dataset.bsTheme === 'dark' ? 'light' : 'dark' });
    display(theme);
    rememberTheme(theme);
    if (!preferences || !body.dataset.o8Context || !body.dataset.o8Csrf) return;
    button.disabled = true;
    try {
        const next = { ...preferences, mode: theme.mode, theme };
        const form = new URLSearchParams({ csrf: body.dataset.o8Csrf, context_token: body.dataset.o8Context, preferences: JSON.stringify(next) });
        const response = await fetch(`${location.pathname}?api=preferences`, { method: 'POST', body: form, credentials: 'same-origin' });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.error || 'Darstellung konnte nicht gespeichert werden.');
        preferences = next;
    } catch {
        theme = previous;
        display(theme);
        rememberTheme(theme);
        let notice = document.getElementById('foundationThemeError');
        if (!notice) {
            notice = document.createElement('div');
            notice.id = 'foundationThemeError';
            notice.className = 'alert alert-danger';
            notice.setAttribute('role', 'alert');
            document.querySelector('.foundation-content')?.prepend(notice);
        }
        notice.textContent = 'Darstellung konnte nicht gespeichert werden. Bitte erneut anmelden oder später versuchen.';
    } finally { button.disabled = false; }
});
