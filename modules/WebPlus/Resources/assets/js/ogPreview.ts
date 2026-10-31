import type Alpine from '@alpinejs/csp';

type OgTab = 'facebook' | 'messenger' | 'twitter' | 'linkedin';

type OgPreviewOptions = {
    mode?: 'meta' | 'settings';
    siteName?: string;
    defaultOgImage?: string;
    isTemplate?: boolean;
};

type OgPreviewState = {
    tab: OgTab;
    title: string;
    description: string;
    image: string;
    twitterImage: string;
    siteName: string;
    twitterCard: string;
    displayUrl: string;
    path: string;
    samplePath: string;
    isTemplate: boolean;
    titleSource: string;
    imageSource: string;
    mode: 'meta' | 'settings';
    defaultOgImage: string;
    localOgPreview: string;
    localTwitterPreview: string;
    init(): void;
    setTab(tab: OgTab): void;
    touch(): void;
    read(): void;
    interpolate(text: string): string;
    fieldValue(name: string): string;
    filePreview(inputName: string): string;
    resolvedTitle: string;
    resolvedDescription: string;
    resolvedImage: string;
    resolvedTwitterImage: string;
    resolvedSiteName: string;
    isLargeTwitter: boolean;
    $el: HTMLElement;
};

function hostnameFrom(url: string, fallback = 'example.com'): string {
    const raw = url.trim();
    if (raw === '') {
        return fallback;
    }
    try {
        const withProto = /^https?:\/\//i.test(raw) ? raw : `https://${raw}`;
        return new URL(withProto).hostname.replace(/^www\./, '') || fallback;
    } catch {
        return fallback;
    }
}

function tokensFromTemplate(template: string, sample: string): Record<string, string> {
    const tmpl = template.trim();
    const samp = sample.trim();
    if (tmpl === '' || samp === '') {
        return {};
    }
    const tParts = tmpl.replace(/^\/+|\/+$/g, '').split('/').filter(Boolean);
    const sParts = samp.replace(/^\/+|\/+$/g, '').split('/').filter(Boolean);
    const out: Record<string, string> = {};
    for (let i = 0; i < tParts.length; i++) {
        const part = tParts[i] ?? '';
        const m = part.match(/^\{([a-zA-Z0-9_]+)\}$/);
        if (m && sParts[i]) {
            out[m[1]] = decodeURIComponent(sParts[i]);
        }
    }
    if (out.slug && !out.title) {
        out.title = out.slug.replace(/[-_]+/g, ' ');
    }
    if (out.page && !out.title) {
        out.title = out.page.replace(/[-_]+/g, ' ');
    }
    return out;
}

export function registerWebplusOgPreview(alpine: typeof Alpine): void {
    alpine.data(
        'webplusOgPreview',
        (options: OgPreviewOptions = {}): OgPreviewState =>
            ({
                tab: 'facebook' as OgTab,
                title: '',
                description: '',
                image: '',
                twitterImage: '',
                siteName: options.siteName || '',
                twitterCard: 'summary_large_image',
                displayUrl: 'example.com',
                path: '',
                samplePath: '',
                isTemplate: Boolean(options.isTemplate),
                titleSource: '',
                imageSource: '',
                mode: options.mode === 'settings' ? 'settings' : 'meta',
                defaultOgImage: options.defaultOgImage || '',
                localOgPreview: '',
                localTwitterPreview: '',

                init() {
                    this.read();
                    const root = this.$el.closest('form') ?? this.$el;
                    root.addEventListener('input', () => this.touch());
                    root.addEventListener('change', () => this.touch());
                },

                setTab(tab: OgTab) {
                    this.tab = tab;
                },

                touch() {
                    this.read();
                },

                fieldValue(name: string): string {
                    const root = this.$el.closest('form') ?? this.$el;
                    const el = root.querySelector<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>(
                        `[name="${name}"]`,
                    );
                    if (!el) {
                        return '';
                    }
                    return (el.value || '').trim();
                },

                filePreview(inputName: string): string {
                    const root = this.$el.closest('form') ?? this.$el;
                    const input = root.querySelector<HTMLInputElement>(`input[type="file"][name="${inputName}"]`);
                    const file = input?.files?.[0];
                    if (!file || !file.type.startsWith('image/')) {
                        return '';
                    }
                    return URL.createObjectURL(file);
                },

                interpolate(text: string): string {
                    if (!this.isTemplate || text === '') {
                        return text;
                    }
                    const tokens = tokensFromTemplate(this.path, this.samplePath || this.path);
                    return text.replace(/\{([a-zA-Z0-9_]+)\}/g, (full, key: string) => tokens[key] ?? full);
                },

                read() {
                    if (this.mode === 'settings') {
                        this.siteName = this.fieldValue('webplus__site_name') || this.siteName;
                        this.defaultOgImage = this.fieldValue('webplus__default_og_image') || this.defaultOgImage;
                        this.localOgPreview = this.filePreview('default_og_image_file');
                        this.title = this.siteName || 'Site';
                        this.description = '';
                        this.image = this.localOgPreview || this.defaultOgImage;
                        this.twitterImage = this.image;
                        this.twitterCard = 'summary_large_image';
                        this.displayUrl = hostnameFrom(
                            this.fieldValue('webplus__frontend_url') || this.fieldValue('webplus__base_url'),
                        );
                        this.titleSource = 'site';
                        this.imageSource = this.image ? (this.localOgPreview ? 'upload' : 'default') : '';
                        return;
                    }

                    this.path = this.fieldValue('path');
                    this.samplePath = this.fieldValue('og_sample_path') || this.samplePath;
                    this.isTemplate = this.path.includes('{') && this.path.includes('}');

                    const seoTitle = this.fieldValue('seo_title');
                    const seoDescription = this.fieldValue('seo_description');
                    const ogTitle = this.fieldValue('og_title');
                    const ogDescription = this.fieldValue('og_description');

                    this.localOgPreview = this.filePreview('og_image_file');
                    this.localTwitterPreview = this.filePreview('twitter_image_file');

                    const ogImageField = this.fieldValue('og_image_url');
                    const twitterImageField = this.fieldValue('twitter_image_url');

                    this.image = this.localOgPreview || ogImageField || this.defaultOgImage;
                    this.twitterImage = this.localTwitterPreview || twitterImageField || this.image;
                    this.twitterCard = this.fieldValue('twitter_card') || 'summary_large_image';

                    if (ogTitle) {
                        this.title = ogTitle;
                        this.titleSource = 'og';
                    } else if (seoTitle) {
                        this.title = seoTitle;
                        this.titleSource = 'seo';
                    } else {
                        this.title = this.siteName || 'Untitled';
                        this.titleSource = 'site';
                    }

                    if (ogDescription) {
                        this.description = ogDescription;
                    } else if (seoDescription) {
                        this.description = seoDescription;
                    } else {
                        this.description = '';
                    }

                    const frontend = this.fieldValue('og_url') || this.path || '/';
                    this.displayUrl = hostnameFrom(frontend);

                    if (this.localOgPreview) {
                        this.imageSource = 'upload';
                    } else if (ogImageField) {
                        this.imageSource = 'og';
                    } else if (this.defaultOgImage) {
                        this.imageSource = 'default';
                    } else {
                        this.imageSource = '';
                    }
                },

                get resolvedTitle(): string {
                    if (this.mode === 'settings') {
                        return this.title || this.siteName || 'Site';
                    }
                    if (this.tab === 'twitter') {
                        const tw = this.fieldValue('twitter_title');
                        if (tw) {
                            return this.interpolate(tw);
                        }
                    }
                    return this.interpolate(this.title);
                },

                get resolvedDescription(): string {
                    if (this.mode === 'settings') {
                        return this.description;
                    }
                    if (this.tab === 'twitter') {
                        const tw = this.fieldValue('twitter_description');
                        if (tw) {
                            return this.interpolate(tw);
                        }
                    }
                    return this.interpolate(this.description);
                },

                get resolvedImage(): string {
                    return this.image;
                },

                get resolvedTwitterImage(): string {
                    return this.twitterImage || this.image;
                },

                get resolvedSiteName(): string {
                    return this.siteName || this.displayUrl;
                },

                get isLargeTwitter(): boolean {
                    return this.twitterCard !== 'summary';
                },
            }) as OgPreviewState,
    );
}
