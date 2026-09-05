import type Alpine from '@alpinejs/csp';

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
    init(): void;
    touch(): void;
    read(): void;
    $el: HTMLElement;
};

function val(root: HTMLElement, name: string, fallback = ''): string {
    const el = root.querySelector<HTMLInputElement | HTMLSelectElement>(`[name="${name}"]`);
    if (!el) return fallback;
    return (el.value || fallback).trim();
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

        init() {
            this.read();
            this.$el.addEventListener('input', () => this.touch());
            this.$el.addEventListener('change', () => this.touch());
        },

        touch() {
            this.read();
        },

        read() {
            const root = this.$el;
            this.name = val(root, 'brand__name') || val(root, 'app__name', 'GEREQ').split(/\s+/)[0] || 'GEREQ';
            this.subtitle = val(root, 'brand__subtitle', 'Platform');
            this.logoUrl = val(root, 'brand__logo_url') || '/brand/icons/favicon.svg';
            this.signal = val(root, 'brand__color_signal', '#d7ff45');
            this.ink = val(root, 'brand__color_ink', '#0a0b0a');
            this.cream = val(root, 'brand__color_cream', '#f5f5ef');
            this.siteName = val(root, 'brand__site_name') || this.name;
            this.siteMark = (val(root, 'brand__site_mark') || this.siteName.slice(0, 2)).toUpperCase().slice(0, 3);
            this.sitePrimary = val(root, 'brand__site_primary', '#17375a');
            this.tagline = val(root, 'brand__tagline');

            const onSignal = contrastOn(this.signal);
            this.adminStyle = `background:${this.cream};color:${this.ink};border-color:color-mix(in srgb, ${this.ink} 12%, transparent)`;
            this.btnStyle = `background:${this.signal};color:${onSignal}`;
            this.siteBarStyle = `background:${this.sitePrimary};color:#fff`;
            this.badgeStyle = `background:rgba(255,255,255,.18);color:#fff`;
        },
    }));
}
