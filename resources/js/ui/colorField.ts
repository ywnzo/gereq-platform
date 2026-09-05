import type Alpine from '@alpinejs/csp';

const PRESETS = [
    '#d7ff45',
    '#0a0b0a',
    '#f5f5ef',
    '#17375a',
    '#1f4670',
    '#625bff',
    '#72d6ff',
    '#ff6b5e',
    '#16a34a',
    '#dc2626',
    '#d97706',
    '#ffffff',
];

function expandHex(value: string): string | null {
    const raw = value.trim();
    if (/^#[0-9a-fA-F]{6}$/.test(raw)) {
        return raw.toLowerCase();
    }
    if (/^#[0-9a-fA-F]{3}$/.test(raw)) {
        return ('#' + raw[1] + raw[1] + raw[2] + raw[2] + raw[3] + raw[3]).toLowerCase();
    }
    if (/^[0-9a-fA-F]{6}$/.test(raw)) {
        return ('#' + raw).toLowerCase();
    }
    if (/^[0-9a-fA-F]{3}$/.test(raw)) {
        return ('#' + raw[0] + raw[0] + raw[1] + raw[1] + raw[2] + raw[2]).toLowerCase();
    }
    return null;
}

function contrastOn(hex: string): string {
    const full = hex.replace('#', '');
    if (full.length !== 6) return '#18181b';
    const r = parseInt(full.slice(0, 2), 16);
    const g = parseInt(full.slice(2, 4), 16);
    const b = parseInt(full.slice(4, 6), 16);
    const luma = (0.2126 * r + 0.7152 * g + 0.0722 * b) / 255;
    return luma > 0.62 ? '#18181b' : '#ffffff';
}

type ColorFieldState = {
    open: boolean;
    hex: string;
    draft: string;
    presets: string[];
    init(): void;
    toggle(): void;
    close(): void;
    syncHidden(): void;
    pick(hex: string): void;
    fromNative(event: Event): void;
    fromDraft(): void;
    swatchStyle: string;
    checkStyle: string;
    isPreset(hex: string): boolean;
    presetStyle(hex: string): string;
    $dispatch: (name: string, detail?: unknown) => void;
    $el: HTMLElement;
};

export function registerColorField(alpine: typeof Alpine): void {
    alpine.data('gereqColorField', (): ColorFieldState => ({
        open: false,
        hex: '#000000',
        draft: '000000',
        presets: PRESETS,

        init() {
            const seed = this.$el.dataset.value || '#000000';
            const expanded = expandHex(seed) || '#000000';
            this.hex = expanded;
            this.draft = expanded.replace('#', '');
            this.syncHidden();
        },

        get swatchStyle(): string {
            return `background:${this.hex}`;
        },

        get checkStyle(): string {
            return `color:${contrastOn(this.hex)}`;
        },

        toggle() {
            this.open = !this.open;
            if (this.open) {
                this.draft = this.hex.replace('#', '');
            }
        },

        close() {
            this.open = false;
        },

        syncHidden() {
            const input = this.$el.querySelector<HTMLInputElement>('input[type="hidden"]');
            if (input) {
                input.value = this.hex;
            }
        },

        pick(hex: string) {
            const next = expandHex(hex);
            if (!next) return;
            this.hex = next;
            this.draft = next.replace('#', '');
            this.syncHidden();
            this.$dispatch('color-change', { hex: next });
            this.$el.dispatchEvent(new Event('input', { bubbles: true }));
            this.$el.dispatchEvent(new Event('change', { bubbles: true }));
        },

        fromNative(event: Event) {
            const target = event.target as HTMLInputElement | null;
            if (!target) return;
            this.pick(target.value);
        },

        fromDraft() {
            const next = expandHex(this.draft.startsWith('#') ? this.draft : `#${this.draft}`);
            if (next) {
                this.pick(next);
            } else {
                this.draft = this.hex.replace('#', '');
            }
        },

        isPreset(hex: string): boolean {
            return this.hex.toLowerCase() === hex.toLowerCase();
        },

        presetStyle(hex: string): string {
            return `background:${hex}`;
        },
    }));
}
