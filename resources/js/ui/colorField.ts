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

/** External apply (e.g. brand style generator) → field name → pick(). */
const pickByField = new Map<string, (hex: string) => void>();

export function applyColorField(fieldName: string, hex: string): boolean {
    const pick = pickByField.get(fieldName);
    if (!pick) {
        return false;
    }
    pick(hex);
    return true;
}

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
    fieldName: string;
    _onNative: ((event: Event) => void) | null;
    _onDraft: ((event: Event) => void) | null;
    init(): void;
    destroy(): void;
    toggle(): void;
    close(): void;
    syncHidden(): void;
    syncDom(): void;
    pick(hex: string): void;
    fromNative(): void;
    fromDraft(): void;
    swatchStyle: string;
    checkStyle: string;
    isPreset(hex: string): boolean;
    presetStyle(hex: string): string;
    checkOn(hex: string): string;
    $dispatch: (name: string, detail?: unknown) => void;
    $el: HTMLElement;
};

export function registerColorField(alpine: typeof Alpine): void {
    alpine.data('gereqColorField', (): ColorFieldState => ({
        open: false,
        hex: '#000000',
        draft: '000000',
        presets: PRESETS,
        fieldName: '',
        _onNative: null,
        _onDraft: null,

        init() {
            const hidden = this.$el.querySelector<HTMLInputElement>('input[type="hidden"]');
            this.fieldName = hidden?.name || '';
            const seed = this.$el.dataset.value || hidden?.value || '#000000';
            const expanded = expandHex(seed) || '#000000';
            this.hex = expanded;
            this.draft = expanded.replace('#', '');
            this.syncHidden();
            this.syncDom();

            if (this.fieldName) {
                pickByField.set(this.fieldName, (hex: string) => this.pick(hex));
            }

            // Native listeners — Alpine CSP @input/@blur on color/hex is unreliable.
            const native = this.$el.querySelector<HTMLInputElement>('input[type="color"]');
            const draft = this.$el.querySelector<HTMLInputElement>('.color-picker__hex-input');
            this._onNative = () => this.fromNative();
            this._onDraft = () => this.fromDraft();
            native?.addEventListener('input', this._onNative);
            native?.addEventListener('change', this._onNative);
            draft?.addEventListener('change', this._onDraft);
            draft?.addEventListener('blur', this._onDraft);
            draft?.addEventListener('keydown', (event: KeyboardEvent) => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    this.fromDraft();
                }
            });
        },

        destroy() {
            if (this.fieldName && pickByField.get(this.fieldName)) {
                pickByField.delete(this.fieldName);
            }
            const native = this.$el.querySelector<HTMLInputElement>('input[type="color"]');
            const draft = this.$el.querySelector<HTMLInputElement>('.color-picker__hex-input');
            if (this._onNative) {
                native?.removeEventListener('input', this._onNative);
                native?.removeEventListener('change', this._onNative);
            }
            if (this._onDraft) {
                draft?.removeEventListener('change', this._onDraft);
                draft?.removeEventListener('blur', this._onDraft);
            }
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

        syncDom() {
            const bare = this.hex.replace('#', '');
            const native = this.$el.querySelector<HTMLInputElement>('input[type="color"]');
            const draft = this.$el.querySelector<HTMLInputElement>('.color-picker__hex-input');
            const face = this.$el.querySelector<HTMLElement>('.color-picker__chip-face');
            if (native && native.value.toLowerCase() !== this.hex.toLowerCase()) {
                native.value = this.hex;
            }
            if (draft && draft.value.toLowerCase() !== bare.toLowerCase()) {
                draft.value = bare;
            }
            if (face) {
                face.style.background = this.hex;
            }
            this.$el.dataset.value = this.hex;
        },

        pick(hex: string) {
            const next = expandHex(hex);
            if (!next) return;
            if (next === this.hex) {
                this.syncHidden();
                this.syncDom();
                this.close();
                return;
            }
            this.hex = next;
            this.draft = next.replace('#', '');
            this.syncHidden();
            this.syncDom();
            this.$dispatch('color-change', { hex: next });
            this.$el.dispatchEvent(new Event('input', { bubbles: true }));
            this.$el.dispatchEvent(new Event('change', { bubbles: true }));
            this.close();
        },

        fromNative() {
            const target = this.$el.querySelector<HTMLInputElement>('input[type="color"]');
            if (!target) return;
            this.pick(target.value);
        },

        fromDraft() {
            const draftEl = this.$el.querySelector<HTMLInputElement>('.color-picker__hex-input');
            const raw = (draftEl?.value || this.draft).trim();
            this.draft = raw.replace(/^#/, '');
            const next = expandHex(raw.startsWith('#') ? raw : `#${raw}`);
            if (next) {
                this.pick(next);
            } else {
                this.draft = this.hex.replace('#', '');
                this.syncDom();
            }
        },

        isPreset(hex: string): boolean {
            return this.hex.toLowerCase() === hex.toLowerCase();
        },

        presetStyle(hex: string): string {
            return `background:${hex}`;
        },

        checkOn(hex: string): string {
            return `color:${contrastOn(hex)}`;
        },
    }));
}
