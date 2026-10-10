import type Alpine from '@alpinejs/csp';
import { applyColorField } from './colorField';

type BrandPalette = {
    signal: string;
    ink: string;
    cream: string;
    sitePrimary: string;
};

/** Cohesive admin + public palettes (logos stay untouched). */
const STYLE_PALETTES: BrandPalette[] = [
    { signal: '#d7ff45', ink: '#0a0b0a', cream: '#f5f5ef', sitePrimary: '#17375a' },
    { signal: '#1d8663', ink: '#0a0b0a', cream: '#f8f9ef', sitePrimary: '#17976c' },
    { signal: '#0ea5e9', ink: '#0b1220', cream: '#f4f8fb', sitePrimary: '#0369a1' },
    { signal: '#e11d48', ink: '#14080c', cream: '#fff7f8', sitePrimary: '#9f1239' },
    { signal: '#ea580c', ink: '#1a0d06', cream: '#fff8f2', sitePrimary: '#c2410c' },
    { signal: '#2563eb', ink: '#0b1224', cream: '#f5f7fc', sitePrimary: '#1e3a8a' },
    { signal: '#0f766e', ink: '#071412', cream: '#f3faf8', sitePrimary: '#115e59' },
    { signal: '#ca8a04', ink: '#141009', cream: '#fbf8ef', sitePrimary: '#854d0e' },
    { signal: '#334155', ink: '#0f172a', cream: '#f8fafc', sitePrimary: '#0f172a' },
    { signal: '#16a34a', ink: '#0a140e', cream: '#f4faf6', sitePrimary: '#166534' },
    { signal: '#db2777', ink: '#160812', cream: '#fdf5f9', sitePrimary: '#9d174d' },
    { signal: '#7c3aed', ink: '#10081c', cream: '#f7f5ff', sitePrimary: '#5b21b6' },
    { signal: '#0891b2', ink: '#071318', cream: '#f2fafc', sitePrimary: '#155e75' },
    { signal: '#b45309', ink: '#1a1006', cream: '#fbf6ee', sitePrimary: '#92400e' },
];

type BrandPreviewState = {
    name: string;
    subtitle: string;
    logoUrl: string;
    signal: string;
    ink: string;
    cream: string;
    siteName: string;
    siteMark: string;
    sitePrimary: string;
    tagline: string;
    adminStyle: string;
    btnStyle: string;
    siteBarStyle: string;
    badgeStyle: string;
    swatchSignal: string;
    swatchInk: string;
    swatchCream: string;
    swatchSite: string;
    styleIndex: number;
    init(): void;
    touch(): void;
    read(): void;
    paint(): void;
    generateStyle(): void;
    applyPalette(palette: BrandPalette): void;
    setPickerColor(fieldName: string, hex: string): void;
    $el: HTMLElement;
};

function val(root: HTMLElement, name: string, fallback = ''): string {
    const el = root.querySelector<HTMLInputElement | HTMLSelectElement>(`[name="${name}"]`);
    if (!el) return fallback;
    return (el.value || fallback).trim();
}

function logoPreview(root: HTMLElement): string {
    const box = root.querySelector<HTMLElement>('.media-box[data-field="brand__logo_url"]');
    if (box) {
        const clear = box.querySelector<HTMLInputElement>('.media-box__clear-flag');
        if (clear?.checked) {
            return '';
        }
        const thumb = box.querySelector<HTMLImageElement>('.media-box__thumb-img');
        const src = (thumb?.getAttribute('src') || thumb?.src || '').trim();
        if (src !== '') {
            return src;
        }
    }
    return val(root, 'brand__logo_url');
}

function contrastOn(hex: string): string {
    const raw = hex.replace('#', '');
    const full = raw.length === 3 ? raw[0] + raw[0] + raw[1] + raw[1] + raw[2] + raw[2] : raw;
    if (!/^[0-9a-fA-F]{6}$/.test(full)) return '#18181b';
    const r = parseInt(full.slice(0, 2), 16);
    const g = parseInt(full.slice(2, 4), 16);
    const b = parseInt(full.slice(4, 6), 16);
    const luma = (0.2126 * r + 0.7152 * g + 0.0722 * b) / 255;
    return luma > 0.55 ? '#18181b' : '#ffffff';
}

function findPaletteIndex(current: BrandPalette): number {
    return STYLE_PALETTES.findIndex(
        (p) =>
            p.signal.toLowerCase() === current.signal.toLowerCase()
            && p.ink.toLowerCase() === current.ink.toLowerCase()
            && p.cream.toLowerCase() === current.cream.toLowerCase()
            && p.sitePrimary.toLowerCase() === current.sitePrimary.toLowerCase(),
    );
}

/** Live-apply brand tokens so admin chrome updates while editing (before Save). */
function applyLiveBrand(signal: string, onSignal: string, ink: string, cream: string): void {
    const root = document.documentElement;
    const map: Record<string, string> = {
        '--gereq-signal': signal,
        '--gereq-brand': ink,
        '--brand-ink': ink,
        '--brand-cream': cream,
        '--primary': signal,
        '--primary-fg': onSignal,
        '--accent': signal,
        '--focus': signal,
        '--sidebar-line': signal,
        '--color-primary': signal,
        '--color-primary-content': onSignal,
        '--color-secondary': signal,
        '--color-secondary-content': onSignal,
        '--color-accent': signal,
        '--color-accent-content': onSignal,
    };
    for (const [name, value] of Object.entries(map)) {
        root.style.setProperty(name, value);
    }

    // Override brand_css() !important block injected after kernel.css.
    let tag = document.getElementById('gereq-brand-live') as HTMLStyleElement | null;
    if (!tag) {
        tag = document.createElement('style');
        tag.id = 'gereq-brand-live';
        document.head.appendChild(tag);
    }
    const body = Object.entries(map)
        .map(([name, value]) => `${name}: ${value} !important;`)
        .join('');
    tag.textContent = `:root, html, html.dark, [data-theme="gereq"], [data-theme="gereq-dark"] { ${body} }`;
}

export function registerSettingsBrandPreview(alpine: typeof Alpine): void {
    alpine.data('settingsBrandPreview', (): BrandPreviewState => ({
        name: 'GEREQ',
        subtitle: 'Platform',
        logoUrl: '/brand/icons/favicon.svg',
        signal: '#d7ff45',
        ink: '#0a0b0a',
        cream: '#f5f5ef',
        siteName: 'Site',
        siteMark: 'RE',
        sitePrimary: '#17375a',
        tagline: '',
        adminStyle: '',
        btnStyle: '',
        siteBarStyle: '',
        badgeStyle: '',
        swatchSignal: 'background:#d7ff45',
        swatchInk: 'background:#0a0b0a',
        swatchCream: 'background:#f5f5ef',
        swatchSite: 'background:#17375a',
        styleIndex: 0,

        init() {
            this.read();
            this.$el.addEventListener('input', () => this.touch());
            this.$el.addEventListener('change', () => this.touch());
            this.$el.addEventListener('color-change', () => this.touch());
            this.$el.addEventListener('media-change', () => this.touch());
        },

        touch() {
            this.read();
        },

        read() {
            const root = this.$el;
            this.name = val(root, 'brand__name') || val(root, 'app__name', 'GEREQ').split(/\s+/)[0] || 'GEREQ';
            this.subtitle = val(root, 'brand__subtitle', 'Platform');
            this.logoUrl = logoPreview(root) || '/brand/icons/favicon.svg';
            this.signal = val(root, 'brand__color_signal', '#d7ff45');
            this.ink = val(root, 'brand__color_ink', '#0a0b0a');
            this.cream = val(root, 'brand__color_cream', '#f5f5ef');
            this.siteName = val(root, 'brand__site_name') || this.name;
            this.siteMark = (val(root, 'brand__site_mark') || this.siteName.slice(0, 2)).toUpperCase().slice(0, 3);
            this.sitePrimary = val(root, 'brand__site_primary', '#17375a');
            this.tagline = val(root, 'brand__tagline');
            this.paint();
        },

        paint() {
            const onSignal = contrastOn(this.signal);
            this.adminStyle = [
                `background:${this.cream}`,
                `color:${this.ink}`,
                `--brand-preview-ink:${this.ink}`,
                `--brand-preview-signal:${this.signal}`,
                `border-color:color-mix(in srgb, ${this.ink} 14%, transparent)`,
            ].join(';');
            this.btnStyle = `background:${this.signal};color:${onSignal}`;
            this.siteBarStyle = `background:${this.sitePrimary};color:#fff`;
            this.badgeStyle = 'background:rgba(255,255,255,.18);color:#fff';
            this.swatchSignal = `background:${this.signal}`;
            this.swatchInk = `background:${this.ink}`;
            this.swatchCream = `background:${this.cream};box-shadow:inset 0 0 0 1px color-mix(in srgb, ${this.ink} 18%, transparent)`;
            this.swatchSite = `background:${this.sitePrimary}`;
            applyLiveBrand(this.signal, onSignal, this.ink, this.cream);
        },

        generateStyle() {
            const current: BrandPalette = {
                signal: this.signal,
                ink: this.ink,
                cream: this.cream,
                sitePrimary: this.sitePrimary,
            };
            const matched = findPaletteIndex(current);
            const from = matched >= 0 ? matched : this.styleIndex;
            this.styleIndex = (from + 1) % STYLE_PALETTES.length;
            this.applyPalette(STYLE_PALETTES[this.styleIndex]!);
        },

        applyPalette(palette: BrandPalette) {
            this.setPickerColor('brand__color_signal', palette.signal);
            this.setPickerColor('brand__color_ink', palette.ink);
            this.setPickerColor('brand__color_cream', palette.cream);
            this.setPickerColor('brand__site_primary', palette.sitePrimary);

            this.signal = palette.signal;
            this.ink = palette.ink;
            this.cream = palette.cream;
            this.sitePrimary = palette.sitePrimary;
            this.paint();
        },

        setPickerColor(fieldName: string, hex: string) {
            if (applyColorField(fieldName, hex)) {
                return;
            }
            const hidden = this.$el.querySelector<HTMLInputElement>(`input[type="hidden"][name="${fieldName}"]`);
            if (!hidden) {
                return;
            }
            hidden.value = hex;
            const picker = hidden.closest('.color-picker');
            if (!picker) {
                return;
            }
            const bare = hex.replace('#', '');
            const native = picker.querySelector<HTMLInputElement>('input[type="color"]');
            const draft = picker.querySelector<HTMLInputElement>('.color-picker__hex-input');
            const face = picker.querySelector<HTMLElement>('.color-picker__chip-face');
            if (native) {
                native.value = hex;
            }
            if (draft) {
                draft.value = bare;
            }
            if (face) {
                face.style.background = hex;
            }
        },
    }));
}
