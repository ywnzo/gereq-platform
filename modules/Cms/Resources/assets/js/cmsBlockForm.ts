import type Alpine from '@alpinejs/csp';

export function registerCmsBlockForm(alpine: typeof Alpine): void {
    alpine.data('cmsBlockForm', () => ({
        type: 'text',

        init() {
            const select = (this.$el as HTMLElement).querySelector('select[name="type"]') as HTMLSelectElement | null;
            if (select && select.value) {
                this.type = select.value;
            }
        },

        get isHeading(): boolean {
            return this.type === 'heading';
        },
        get isText(): boolean {
            return this.type === 'text';
        },
        get isHtml(): boolean {
            return this.type === 'html';
        },
        get isImage(): boolean {
            return this.type === 'image';
        },
        get isCta(): boolean {
            return this.type === 'cta';
        },
        get isColumns(): boolean {
            return this.type === 'columns';
        },
        get isDivider(): boolean {
            return this.type === 'divider';
        },
        get isProducts(): boolean {
            return this.type === 'products';
        },
        get isGallery(): boolean {
            return this.type === 'gallery';
        },
        get isQuote(): boolean {
            return this.type === 'quote';
        },
        get isVideo(): boolean {
            return this.type === 'video';
        },
        get isSpacer(): boolean {
            return this.type === 'spacer';
        },
    }));
}
