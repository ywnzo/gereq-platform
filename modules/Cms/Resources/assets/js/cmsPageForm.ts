import type Alpine from '@alpinejs/csp';

export function registerCmsPageForm(alpine: typeof Alpine): void {
    alpine.data('cmsPageForm', () => ({
        contentMode: 'blocks',

        init() {
            const root = this.$el as HTMLElement;
            const seeded = (root.dataset.contentMode || '').trim();
            if (seeded) {
                this.contentMode = seeded;
            }
            const form = root.closest('form') || root;
            if (root.dataset.newPage === '1') {
                const title = form.querySelector<HTMLInputElement>('input[name="title"]');
                const slug = form.querySelector<HTMLInputElement>('input[name="slug"]');
                if (title && slug) {
                    let manuallyEdited = slug.value !== '';
                    slug.addEventListener('input', () => { manuallyEdited = true; });
                    title.addEventListener('input', () => {
                        if (!manuallyEdited) {
                            slug.value = title.value.normalize('NFD').replace(/[\u0300-\u036f]/g, '')
                                .toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
                        }
                    });
                }
            }
            const input = form.querySelector<HTMLInputElement>('input[name="content_mode"]');
            if (input?.value) {
                this.contentMode = input.value;
            }
        },

        syncMode(event: Event) {
            const target = event.target as HTMLInputElement | HTMLSelectElement | null;
            if (!target || target.name !== 'content_mode') {
                return;
            }
            this.contentMode = target.value || 'blocks';
        },

        get isRaw(): boolean {
            return this.contentMode === 'raw';
        },

        get isBlocks(): boolean {
            return this.contentMode !== 'raw';
        },
    }));
}
