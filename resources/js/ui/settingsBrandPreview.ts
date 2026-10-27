import type Alpine from '@alpinejs/csp';
import { applyColorField } from './colorField';
import { generateBrandPalette } from './brandPaletteGenerator';

export function registerSettingsBrandPreview(alpine: typeof Alpine): void {
    alpine.data('settingsBrandPreview', () => ({
        name: 'GEREQ', subtitle: 'Platform', logoUrl: '/brand/icons/favicon.svg',
        adminStyle: '', btnStyle: '', swatchSignal: '',
        root: null as HTMLElement | null,
        paletteWarning: '',
        paletteTimer: 0,
        paletteRevision: 0,
        storefrontTheme: 'light',
        init() {
            this.root = this.$el as HTMLElement;
            this.storefrontTheme = this.root.querySelector<HTMLInputElement>('[name="palette_storefront_mode"]')?.value || 'light';
            this.queuePalettePreview(false);
            for (const event of ['input', 'change', 'color-change', 'media-change']) {
                this.root.addEventListener(event, (event) => {
                    if (!(event.target instanceof Element)) return;
                    if (event.target instanceof HTMLInputElement && ['brand__name', 'brand__subtitle'].includes(event.target.name)) {
                        this.applyIdentity();
                    }
                    if (event.target.closest('.brand-settings__appearance')) {
                        this.applyAppearance();
                        if (event.target instanceof HTMLInputElement && event.target.name === 'admin_font') this.applyFont();
                        this.queuePalettePreview();
                    } else if (event.target.closest('.brand-settings__palette') && !event.target.closest('.brand-settings__generator')) {
                        const dirty = this.root?.querySelector<HTMLInputElement>('[name="palette_dirty"]');
                        if (dirty) dirty.value = '1';
                        this.queuePalettePreview();
                    }
                });
            }
        },
        applyIdentity() {
            const name = this.root?.querySelector<HTMLInputElement>('[name="brand__name"]')?.value.trim() || '';
            const subtitle = this.root?.querySelector<HTMLInputElement>('[name="brand__subtitle"]')?.value.trim() || '';
            const appName = document.querySelector<HTMLInputElement>('[name="app__name"]')?.value.trim() || this.root?.dataset.appName || '';
            const fallback = appName.replace(/\s+Platform$/iu, '').trim().split(/\s+/u)[0] || 'GEREQ';
            const displayName = name || fallback;
            const brandName = document.querySelector<HTMLElement>('.sidebar__brand .brand__name');
            const brandSubtitle = document.querySelector<HTMLElement>('.sidebar__brand .brand__sub');
            const previewName = this.root?.querySelector<HTMLElement>('.brand-storefront-preview__nav strong');
            if (brandName) brandName.textContent = displayName;
            if (brandSubtitle) brandSubtitle.textContent = subtitle || this.root?.dataset.defaultSubtitle || '';
            if (previewName) previewName.textContent = displayName;
            const initials = document.querySelector<HTMLElement>('.sidebar__brand .brand__initials');
            const mark = this.root?.querySelector<HTMLInputElement>('[name="brand__site_mark"]')?.value.trim() || '';
            if (initials) {
                const words = displayName.split(/\s+/u);
                initials.textContent = (mark || (words.length > 1
                    ? Array.from(words[0])[0] + Array.from(words[1])[0]
                    : Array.from(words[0]).slice(0, 2).join(''))).toUpperCase();
            }
        },
        generatePalette() {
            const style = this.root?.querySelector<HTMLInputElement>('[name="palette_generator_style"]')?.value || 'balanced';
            for (const [key, value] of Object.entries(generateBrandPalette(style))) {
                applyColorField(`palette_${key}`, value);
            }
        },
        get storefrontThemeIcon(): string {
            return this.storefrontTheme === 'dark' ? 'dark_mode' : 'light_mode';
        },
        get storefrontThemeLabel(): string {
            const label = this.root?.dataset.storefrontThemeLabel || '';
            const mode = this.storefrontTheme === 'dark' ? this.root?.dataset.storefrontThemeDark : this.root?.dataset.storefrontThemeLight;
            return `${label}: ${mode || ''}`;
        },
        toggleStorefrontTheme() {
            this.storefrontTheme = this.storefrontTheme === 'dark' ? 'light' : 'dark';
            const input = this.root?.querySelector<HTMLInputElement>('[name="palette_storefront_mode"]');
            if (input) {
                input.value = this.storefrontTheme;
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }
        },
        applyAppearance() {
            const motion = this.root?.querySelector<HTMLInputElement>('[name="admin_motion"]')?.value ?? '1';
            const opacity = this.root?.querySelector<HTMLInputElement>('[name="admin_panel_opacity"]')?.value ?? '100';
            document.documentElement.dataset.ambientMotion = motion;
            const output = this.root?.querySelector<HTMLOutputElement>('[data-opacity-output]');
            if (output) output.value = `${opacity}%`;
        },
        applyFont() {
            const font = this.root?.querySelector<HTMLInputElement>('[name="admin_font"]')?.value ?? 'default';
            const fonts = JSON.parse(this.root?.dataset.adminFonts || '{}') as Record<string, string>;
            if (fonts[font]) {
                document.documentElement.style.setProperty('--gereq-font-sans', fonts[font], 'important');
                document.documentElement.style.setProperty('--gereq-font-display', fonts[font], 'important');
            }
        },
        queuePalettePreview(applyAdmin = true) {
            const root = this.root;
            if (!root) return;
            const revision = ++this.paletteRevision;
            window.clearTimeout(this.paletteTimer);
            this.paletteTimer = window.setTimeout(async () => {
                const data = new FormData();
                for (const key of ['primary', 'secondary', 'accent', 'light_background', 'light_surface', 'light_header', 'dark_background', 'dark_surface', 'dark_header', 'storefront_mode']) {
                    const input = root.querySelector<HTMLInputElement>(`[name="palette_${key}"]`);
                    if (!input) return;
                    data.set(`palette_${key}`, input.value);
                }
                for (const name of ['admin_motion', 'admin_panel_opacity']) {
                    const input = root.querySelector<HTMLInputElement>(`[name="${name}"]`);
                    if (input) data.set(name, input.value);
                }
                const form = root.closest('form');
                const csrf = form?.querySelector<HTMLInputElement>('input[name="_csrf"]')?.value ?? '';
                data.set('_csrf', csrf);
                try {
                    const response = await fetch(root.dataset.paletteUrl ?? '', {
                        method: 'POST', body: data, credentials: 'same-origin',
                        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf },
                    });
                    if (!response.ok) throw new Error();
                    const { data: result } = await response.json() as { data: { css: Record<'light' | 'dark', Record<string, string>>; modes: Record<'light' | 'dark', Record<string, string>>; storefront_mode: 'light' | 'dark' } };
                    if (revision !== this.paletteRevision) return;
                    const preview = root.querySelector<HTMLElement>('.brand-storefront-preview');
                    if (preview) {
                        const mode = result.modes[result.storefront_mode];
                        for (const key of ['background', 'surface', 'text', 'text_on_surface', 'text_muted', 'border', 'primary', 'on_primary', 'secondary', 'on_secondary', 'accent', 'on_accent']) {
                            preview.style.setProperty(`--preview-${key.replaceAll('_', '-')}`, mode[key]);
                        }
                    }
                    if (!applyAdmin) {
                        this.paletteWarning = '';
                        return;
                    }
                    const rules = (tokens: Record<string, string>) => Object.entries(tokens).map(([name, value]) => `${name}: ${value} !important;`).join('');
                    let tag = document.getElementById('gereq-brand-live') as HTMLStyleElement | null;
                    if (!tag) {
                        tag = document.createElement('style');
                        tag.id = 'gereq-brand-live';
                        const nonce = document.documentElement.dataset.cspNonce;
                        if (nonce) tag.nonce = nonce;
                        document.head.appendChild(tag);
                    }
                    tag.textContent = `html[data-brand-palette="1"]:not(.dark):not([data-theme="gereq-dark"]) { ${rules(result.css.light)} } html.dark[data-brand-palette="1"], html[data-theme="gereq-dark"][data-brand-palette="1"] { ${rules(result.css.dark)} }`;
                    document.documentElement.dataset.brandPalette = '1';
                    this.paletteWarning = '';
                } catch {
                    if (revision === this.paletteRevision) this.paletteWarning = root.dataset.paletteError ?? '';
                }
            }, 150);
        },
    }));
}
