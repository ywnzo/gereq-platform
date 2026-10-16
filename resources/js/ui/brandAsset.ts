import type Alpine from '@alpinejs/csp';

type BrandAssetState = {
    fileName: string;
    preview: string;
    init(): void;
    onFile(event: Event): void;
};

export function registerBrandAsset(alpine: typeof Alpine): void {
    alpine.data('gereqBrandAsset', (): Alpine.AlpineComponent<BrandAssetState> => ({
        fileName: '',
        preview: '',

        init() {
            this.preview = this.$el.dataset.preview || '';
        },

        onFile(event: Event) {
            const input = event.target as HTMLInputElement | null;
            const file = input?.files?.[0];
            if (!file) {
                this.fileName = '';
                return;
            }
            this.fileName = file.name;
            if (this.preview.startsWith('blob:')) {
                URL.revokeObjectURL(this.preview);
            }
            this.preview = URL.createObjectURL(file);
        },
    }));
}
