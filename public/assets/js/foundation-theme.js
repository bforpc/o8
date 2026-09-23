const cookie = document.cookie.split('; ').find(value => value.startsWith('o8_theme_mode='));
const initial = cookie ? decodeURIComponent(cookie.split('=')[1]) : 'light';
const apply = mode => { document.documentElement.dataset.bsTheme = mode; document.body.dataset.bsTheme = mode; };
apply(['light','dark'].includes(initial) ? initial : 'light');
document.getElementById('foundationThemeToggle')?.addEventListener('click', () => {
    const next = document.documentElement.dataset.bsTheme === 'dark' ? 'light' : 'dark';
    apply(next); document.cookie = `o8_theme_mode=${encodeURIComponent(next)}; Max-Age=31536000; Path=/; SameSite=Strict`;
});
