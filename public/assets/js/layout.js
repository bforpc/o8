export const DEFAULT_WIDTHS = [18, 27, 29, 26];
export const layoutKey = userId => `o8.layout.v1.${userId}`;
export function normalizeWidths(input) {
    if (!Array.isArray(input) || input.length !== 4 || input.some(n => !Number.isFinite(n) || n < 8 || n > 65)) return [...DEFAULT_WIDTHS];
    const total = input.reduce((a,b) => a+b, 0);
    if (Math.abs(total - 100) > 0.1) return [...DEFAULT_WIDTHS];
    return input.map(n => n * (100 / total));
}
export class ColumnLayout {
    constructor(element, storage, userId, onSave = () => {}) {
        this.element = element; this.storage = storage; this.onSave = onSave;
        this.handles = [...element.querySelectorAll('[data-divider]')];
        this.drag = null;
        this.load(userId);
        this.handles.forEach(handle => {
            handle.addEventListener('pointerdown', event => this.start(event, handle));
            handle.addEventListener('pointermove', event => this.move(event));
            handle.addEventListener('pointerup', () => this.end());
            handle.addEventListener('pointercancel', () => this.end());
            handle.addEventListener('lostpointercapture', () => this.end());
            handle.addEventListener('keydown', event => {
                if (!['ArrowLeft', 'ArrowRight', 'Home'].includes(event.key)) return;
                event.preventDefault();
                if (event.key === 'Home') this.reset();
                else { this.adjust(Number(handle.dataset.divider), event.key === 'ArrowLeft' ? -1 : 1); this.save(); }
            });
            handle.addEventListener('dblclick', () => this.reset());
        });
    }
    load(userId) {
        this.userId = userId;
        try { this.widths = normalizeWidths(JSON.parse(this.storage?.getItem(layoutKey(userId)) || 'null')); }
        catch { this.widths = [...DEFAULT_WIDTHS]; }
        this.apply();
    }
    apply() {
        this.element.style.setProperty('--o8-columns', this.widths.map(value => `minmax(0, ${value}fr)`).join(' 6px '));
        this.handles.forEach((handle, index) => {
            handle.setAttribute('aria-valuenow', String(Math.round(this.widths[index])));
            handle.setAttribute('aria-valuetext', `Spalte ${index + 1}: ${Math.round(this.widths[index])} Prozent`);
        });
    }
    start(event, handle) {
        if (event.button !== 0) return;
        event.preventDefault();
        this.drag = { index: Number(handle.dataset.divider), x: event.clientX, widths: [...this.widths] };
        handle.setPointerCapture(event.pointerId);
        document.body.classList.add('resizing-columns');
    }
    adjust(index, delta, widths = this.widths) {
        const pair = widths[index] + widths[index + 1];
        const available = Math.max(this.element.clientWidth - 18, 1);
        const minimums = [135, 185, 210, 210].map(value => Math.max(8, Math.min(22, value / available * 100)));
        // Bei bereits schmal gespeicherten Paaren darf die Mindestbreite nicht
        // zulasten der Nachbarspalte gehen. Jede gespeicherte Breite bleibt gültig.
        const lower = Math.max(8, pair - 65, Math.min(minimums[index], pair / 2));
        const upper = Math.min(65, pair - 8, pair - Math.min(minimums[index + 1], pair / 2));
        const left = Math.max(lower, Math.min(upper, widths[index] + delta));
        this.widths = [...widths]; this.widths[index] = left; this.widths[index + 1] = pair - left;
        this.apply();
    }
    move(event) {
        if (!this.drag) return;
        this.adjust(this.drag.index, (event.clientX - this.drag.x) / Math.max(this.element.clientWidth - 18, 1) * 100, this.drag.widths);
    }
    end() {
        if (!this.drag) return;
        this.drag = null; document.body.classList.remove('resizing-columns'); this.save();
    }
    save() {
        try { this.storage?.setItem(layoutKey(this.userId), JSON.stringify(this.widths)); this.onSave(Boolean(this.storage)); }
        catch { this.onSave(false); }
    }
    reset() { this.widths = [...DEFAULT_WIDTHS]; this.apply(); this.save(); }
}
