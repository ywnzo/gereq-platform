import type Alpine from '@alpinejs/csp';

type GalleryItem = {
    kind: string;
    url: string;
    title: string;
    start: string | number;
    end: string | number;
    media_asset_id: string;
};

type LibraryItem = {
    value: string;
    label: string;
    thumb?: string;
    url?: string;
    mime?: string;
    ext?: string;
    kind?: string;
    folder?: string;
};

type GalleryRow = Record<string, unknown>;

function kindFromMime(mime: string, ext: string, fallback = 'photo'): string {
    const m = mime.toLowerCase();
    const e = ext.toLowerCase();
    if (m.startsWith('video/') || ['mp4', 'webm', 'mov', 'm4v', 'ogv'].includes(e)) {
        return 'video';
    }
    if (m.includes('gif') || e === 'gif') {
        return 'gif';
    }
    if (m.startsWith('image/')) {
        return 'photo';
    }

    return fallback;
}

function normalize(row: GalleryRow): GalleryItem {
    return {
        kind: String(row.kind || 'photo'),
        url: String(row.url || ''),
        title: String(row.title || ''),
        start: row.start ?? '',
        end: row.end ?? '',
        media_asset_id: String(row.media_asset_id || ''),
    };
}

export function registerEventGalleryEditor(alpine: typeof Alpine): void {
    alpine.data('eventGalleryEditor', () => ({
        items: [] as GalleryItem[],
        library: [] as LibraryItem[],
        query: '',
        pickerOpen: false,
        pickerIndex: 0,

        init() {
            try {
                const raw = JSON.parse((this.$el as HTMLElement).dataset.items || '[]');
                this.items = Array.isArray(raw) ? raw.map((row) => normalize(row as GalleryRow)) : [];
            } catch {
                this.items = [];
            }
            try {
                const lib = JSON.parse((this.$el as HTMLElement).dataset.library || '[]');
                this.library = Array.isArray(lib) ? (lib as LibraryItem[]) : [];
            } catch {
                this.library = [];
            }
            if (this.items.length === 0) {
                this.add();
            }
        },

        add() {
            this.items.push({ kind: 'photo', url: '', title: '', start: '', end: '', media_asset_id: '' });
        },

        remove(index: number) {
            this.items.splice(index, 1);
            if (this.items.length === 0) {
                this.add();
            }
        },

        fieldName(index: number, field: string): string {
            return 'gallery[' + index + '][' + field + ']';
        },

        isTimed(item: GalleryItem): boolean {
            return item.kind === 'video';
        },

        isImagePreview(item: GalleryItem): boolean {
            return item.kind === 'photo' || item.kind === 'gif';
        },

        openPicker(index: number) {
            this.pickerIndex = index;
            this.query = '';
            this.pickerOpen = true;
            this.$nextTick(() => {
                const search = (this.$el as HTMLElement).querySelector<HTMLInputElement>('.event-gallery-picker__search');
                search?.focus();
            });
        },

        closePicker() {
            this.pickerOpen = false;
        },

        onEscape() {
            if (this.pickerOpen) {
                this.closePicker();
            }
        },

        pick(item: LibraryItem) {
            const row = this.items[this.pickerIndex];
            if (!row) {
                return;
            }
            const url = item.url || item.thumb || '';
            const next: GalleryItem = {
                ...row,
                media_asset_id: item.value || '',
                url,
                kind: item.kind || kindFromMime(item.mime || '', item.ext || '', row.kind || 'photo'),
                title: String(row.title || '').trim() ? row.title : (item.label || ''),
            };
            this.items.splice(this.pickerIndex, 1, next);
            this.pickerOpen = false;
        },

        get hasLibrary(): boolean {
            return this.library.length > 0;
        },

        isVisual(item: LibraryItem): boolean {
            const mime = (item.mime || '').toLowerCase();
            const kind = item.kind || kindFromMime(mime, item.ext || '');

            return kind === 'photo' || kind === 'gif' || mime.startsWith('image/');
        },

        get visibleLibrary(): LibraryItem[] {
            const q = this.query.trim().toLowerCase();
            if (q === '') {
                return this.library;
            }

            return this.library.filter((item) => {
                const hay = `${item.label || ''} ${item.ext || ''} ${item.mime || ''} ${item.folder || ''} ${item.kind || ''}`.toLowerCase();

                return hay.includes(q);
            });
        },
    }));
}
