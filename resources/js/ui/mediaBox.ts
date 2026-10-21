import type Alpine from '@alpinejs/csp';

type FolderOption = { id: string; label: string };

type LibraryItem = {
    value: string;
    label: string;
    thumb?: string;
    mime?: string;
    ext?: string;
    width?: number | null;
    height?: number | null;
    created?: number;
    folder_id?: string;
    folder?: string;
};

type GereqMediaBoxCtx = {
    contextKey: string;
    clearedSelection: { value: string; preview: string; id: string } | null;
    syncExternal: (event: CustomEvent<{ context: string; value: string; preview: string; selectedId: string; library?: LibraryItem[] }>) => void;
    preview: string;
    selectedId: string;
    clearing: boolean;
    showUrl: boolean;
    dialogOpen: boolean;
    initialPreview: string;
    labelChoose: string;
    labelChange: string;
    clearLabel: string;
    undoLabel: string;
    resultsTemplate: string;
    folderRootLabel: string;
    query: string;
    typeFilter: string;
    orientFilter: string;
    folderFilter: string;
    sortBy: string;
    visibleCount: number;
    totalCount: number;
    libraryEmpty: boolean;
    resultsText: string;
    folderOptions: FolderOption[];
    items: LibraryItem[];
    $el: HTMLElement;
    $refs: {
        url?: HTMLInputElement;
        urlVisible?: HTMLInputElement;
        asset?: HTMLInputElement;
        dialog?: HTMLElement;
        layer?: HTMLDialogElement;
        search?: HTMLInputElement;
        grid?: HTMLElement;
    };
    $nextTick: (fn: () => void) => void;
    hasPreview: boolean;
    triggerLabel: string;
    hasActiveFilters: boolean;
    init: () => void;
    fileInput: () => HTMLInputElement | null;
    libraryGrid: () => HTMLElement | null;
    open: () => void;
    close: () => void;
    onLayerClosed: () => void;
    pick: (id: string, thumb: string) => void;
    clearPhoto: () => void;
    undoClear: () => void;
    onCardClear: () => void;
    onFileChange: () => void;
    onUrlVisibleInput: () => void;
    syncUrlVisible: () => void;
    setUrlValue: (value: string, kind?: string) => void;
    toggleUrl: () => void;
    isSelected: (id: string) => boolean;
    revokeBlob: () => void;
    resetFileWidget: () => void;
    parseLibrary: () => void;
    buildFolderOptions: () => void;
    applyFilters: () => void;
    resetFilters: () => void;
    formatResults: () => string;
    itemMatchesData: (item: LibraryItem) => boolean;
    orientationOfData: (item: LibraryItem) => string;
};

export function registerMediaBox(alpine: typeof Alpine): void {
    alpine.data('gereqMediaBox', () => ({
        contextKey: '',
        clearedSelection: null as { value: string; preview: string; id: string } | null,
        preview: '',
        selectedId: '',
        clearing: false,
        showUrl: false,
        dialogOpen: false,
        initialPreview: '',
        labelChoose: '',
        labelChange: '',
        clearLabel: '',
        undoLabel: '',
        resultsTemplate: '',
        folderRootLabel: '',
        query: '',
        typeFilter: '',
        orientFilter: '',
        folderFilter: '',
        sortBy: 'newest',
        visibleCount: 0,
        totalCount: 0,
        libraryEmpty: true,
        resultsText: '',
        folderOptions: [] as FolderOption[],
        items: [] as LibraryItem[],

        init(this: GereqMediaBoxCtx) {
            this.initialPreview = this.$el.dataset.preview || '';
            this.preview = this.initialPreview;
            this.showUrl = this.$el.dataset.showUrl === '1';
            this.selectedId = this.$el.dataset.selected || '';

            const trigger = this.$el.querySelector<HTMLElement>('.media-box__trigger');
            this.labelChoose = trigger?.dataset.labelChoose || '';
            this.labelChange = trigger?.dataset.labelChange || '';
            this.clearLabel = this.$el.dataset.labelClear || '';
            this.undoLabel = this.$el.dataset.labelUndo || '';
            this.resultsTemplate = this.$el.dataset.labelResults || '%count% / %total%';
            this.folderRootLabel = this.$el.dataset.labelFolderRoot || '';

            this.parseLibrary();
            this.buildFolderOptions();
            this.applyFilters();

            const input = this.fileInput();
            input?.addEventListener('change', () => this.onFileChange());
        },

        syncExternal(this: GereqMediaBoxCtx, event: CustomEvent<{ context: string; value: string; preview: string; selectedId: string; library?: LibraryItem[] }>) {
            const state = event.detail;
            if (!state || typeof state.context !== 'string') return;
            if (state.library && JSON.stringify(this.items) !== JSON.stringify(state.library)) {
                this.items = state.library;
                this.totalCount = this.items.length;
                this.buildFolderOptions();
                this.$nextTick(() => this.applyFilters());
            }
            const changedContext = this.contextKey !== state.context;
            if (!changedContext && this.$refs.url?.value === state.value && this.selectedId === state.selectedId && this.preview === state.preview) return;
            this.contextKey = state.context;
            if (changedContext) this.close();
            this.revokeBlob();
            this.preview = state.preview;
            this.initialPreview = state.preview;
            this.selectedId = state.selectedId;
            this.clearing = false;
            this.clearedSelection = null;
            if (this.$refs.url) this.$refs.url.value = state.value;
            if (this.$refs.urlVisible) this.$refs.urlVisible.value = state.value;
            if (this.$refs.asset) this.$refs.asset.value = state.selectedId;
            this.resetFileWidget();
            this.preview = state.preview;
        },

        parseLibrary(this: GereqMediaBoxCtx) {
            const raw = this.$el.dataset.library || '[]';
            try {
                const parsed = JSON.parse(raw) as unknown;
                this.items = Array.isArray(parsed) ? (parsed as LibraryItem[]) : [];
            } catch {
                this.items = [];
            }
            const fallbackTotal = Number(this.$el.dataset.libraryTotal || '0');
            if (this.items.length === 0 && fallbackTotal > 0) {
                // DOM-only fallback when JSON is missing: synthesize from thumb buttons.
                this.items = [...this.$el.querySelectorAll<HTMLElement>('.media-box__thumb')].map((el) => ({
                    value: el.getAttribute('data-id') || '',
                    label: el.getAttribute('data-label') || '',
                    thumb: el.getAttribute('data-thumb') || '',
                    mime: el.getAttribute('data-mime') || '',
                    ext: el.getAttribute('data-ext') || '',
                    width: Number(el.getAttribute('data-width') || '0') || null,
                    height: Number(el.getAttribute('data-height') || '0') || null,
                    created: Number(el.getAttribute('data-created') || '0'),
                    folder_id: el.getAttribute('data-folder-id') || '',
                    folder: el.getAttribute('data-folder') || '',
                }));
            }
            this.totalCount = this.items.length || fallbackTotal;
            this.visibleCount = this.totalCount;
            this.libraryEmpty = this.totalCount === 0;
            this.resultsText = this.formatResults();
        },

        fileInput(this: GereqMediaBoxCtx): HTMLInputElement | null {
            return this.$el.querySelector<HTMLInputElement>('.g-file__input');
        },

        libraryGrid(this: GereqMediaBoxCtx): HTMLElement | null {
            return this.$el.querySelector<HTMLElement>('.media-box__grid');
        },

        get hasPreview(): boolean {
            return !this.clearing && this.preview !== '';
        },

        get triggerLabel(): string {
            return this.hasPreview ? this.labelChange : this.labelChoose;
        },

        get hasActiveFilters(): boolean {
            return this.query.trim() !== ''
                || this.typeFilter !== ''
                || this.orientFilter !== ''
                || this.folderFilter !== ''
                || this.sortBy !== 'newest';
        },

        formatResults(this: GereqMediaBoxCtx): string {
            return this.resultsTemplate
                .replace('%count%', String(this.visibleCount))
                .replace('%total%', String(this.totalCount));
        },

        isSelected(this: GereqMediaBoxCtx, id: string): boolean {
            return !this.clearing && this.selectedId === id;
        },

        open(this: GereqMediaBoxCtx) {
            const layer = this.$refs.layer;
            if (!layer) return;
            // The top layer escapes panel stacking contexts without moving form controls.
            if (!layer.open) layer.showModal();
            this.dialogOpen = true;
            this.syncUrlVisible();
            this.$nextTick(() => {
                this.applyFilters();
                const focusEl = this.$refs.search
                    || this.$refs.dialog?.querySelector<HTMLElement>('button, [href], input, select, textarea');
                focusEl?.focus();
            });
        },

        close(this: GereqMediaBoxCtx) {
            this.$refs.layer?.close();
            this.dialogOpen = false;
        },

        onLayerClosed(this: GereqMediaBoxCtx) {
            this.dialogOpen = false;
        },

        revokeBlob(this: GereqMediaBoxCtx) {
            if (this.preview.startsWith('blob:')) {
                URL.revokeObjectURL(this.preview);
            }
        },

        resetFileWidget(this: GereqMediaBoxCtx) {
            const input = this.fileInput();
            if (!input) {
                return;
            }
            input.value = '';
            input.dispatchEvent(new Event('change', { bubbles: true }));
        },

        setUrlValue(this: GereqMediaBoxCtx, value: string, kind = 'url') {
            if (this.$refs.url) {
                this.$refs.url.value = value;
                this.$refs.url.dispatchEvent(new Event('input', { bubbles: true }));
            }
            if (this.$refs.urlVisible) {
                this.$refs.urlVisible.value = value;
            }
            this.$el.dispatchEvent(new CustomEvent('media-change', { bubbles: true, detail: { value, kind, selectedId: this.selectedId, context: this.contextKey } }));
        },

        syncUrlVisible(this: GereqMediaBoxCtx) {
            if (this.$refs.urlVisible && this.$refs.url) {
                this.$refs.urlVisible.value = this.$refs.url.value;
            }
        },

        pick(this: GereqMediaBoxCtx, id: string, thumb: string) {
            this.clearing = false;
            this.selectedId = id;
            this.revokeBlob();
            this.preview = thumb || '';
            if (this.$refs.asset) {
                this.$refs.asset.value = id;
            }
            this.setUrlValue(thumb || '', 'pick');
            this.resetFileWidget();
            this.close();
        },

        clearPhoto(this: GereqMediaBoxCtx) {
            this.clearedSelection = { value: this.$refs.url?.value || '', preview: this.preview, id: this.selectedId };
            this.clearing = true;
            this.selectedId = '';
            this.revokeBlob();
            this.preview = '';
            if (this.$refs.asset) {
                this.$refs.asset.value = '';
            }
            this.setUrlValue('', 'clear');
            this.resetFileWidget();
        },

        onCardClear(this: GereqMediaBoxCtx) {
            if (this.hasPreview) {
                this.clearPhoto();
                return;
            }
            this.undoClear();
        },

        undoClear(this: GereqMediaBoxCtx) {
            this.clearing = false;
            const previous = this.clearedSelection;
            this.preview = previous?.preview ?? this.initialPreview;
            this.selectedId = previous?.id ?? '';
            if (this.$refs.asset) this.$refs.asset.value = this.selectedId;
            this.setUrlValue(previous?.value ?? this.initialPreview, 'undo');
        },

        onFileChange(this: GereqMediaBoxCtx) {
            const input = this.fileInput();
            const file = input?.files?.[0];
            if (!file) {
                if (this.selectedId || this.clearing) {
                    return;
                }
                const url = (this.$refs.url?.value || '').trim();
                this.revokeBlob();
                this.preview = url;
                return;
            }
            this.clearing = false;
            this.selectedId = '';
            if (this.$refs.asset) {
                this.$refs.asset.value = '';
            }
            this.revokeBlob();
            this.preview = URL.createObjectURL(file);
            this.$el.dispatchEvent(new CustomEvent('media-change', {
                bubbles: true,
                detail: { value: this.preview, kind: 'file', file, selectedId: '', context: this.contextKey },
            }));
        },

        onUrlVisibleInput(this: GereqMediaBoxCtx) {
            const input = this.fileInput();
            if (input?.files?.length) {
                return;
            }
            const url = (this.$refs.urlVisible?.value || '').trim();
            this.clearing = false;
            this.selectedId = '';
            if (this.$refs.asset) this.$refs.asset.value = '';
            this.revokeBlob();
            this.preview = url;
            this.setUrlValue(url);
        },

        toggleUrl(this: GereqMediaBoxCtx) {
            this.showUrl = !this.showUrl;
            if (this.showUrl) {
                this.syncUrlVisible();
                this.$nextTick(() => this.$refs.urlVisible?.focus());
            }
        },

        buildFolderOptions(this: GereqMediaBoxCtx) {
            const map = new Map<string, string>();
            let hasRoot = false;
            this.items.forEach((item) => {
                const id = item.folder_id || '';
                if (id === '') {
                    hasRoot = true;
                    return;
                }
                const label = (item.folder || '').trim() || id;
                if (!map.has(id)) {
                    map.set(id, label);
                }
            });
            const folders: FolderOption[] = [...map.entries()]
                .map(([id, label]) => ({ id, label }))
                .sort((a, b) => a.label.localeCompare(b.label, undefined, { sensitivity: 'base' }));
            if (hasRoot) {
                folders.unshift({ id: '_root', label: this.folderRootLabel || '—' });
            }
            this.folderOptions = folders;
        },

        orientationOfData(this: GereqMediaBoxCtx, item: LibraryItem): string {
            const w = Number(item.width || 0);
            const h = Number(item.height || 0);
            if (!w || !h) {
                return '';
            }
            if (w === h) {
                return 'square';
            }
            return w > h ? 'landscape' : 'portrait';
        },

        itemMatchesData(this: GereqMediaBoxCtx, item: LibraryItem): boolean {
            const q = this.query.trim().toLowerCase();
            if (q !== '') {
                const hay = `${item.label || ''} ${item.ext || ''} ${item.mime || ''} ${item.folder || ''}`.toLowerCase();
                if (!hay.includes(q)) {
                    return false;
                }
            }
            if (this.typeFilter !== '') {
                if ((item.ext || '').toLowerCase() !== this.typeFilter) {
                    return false;
                }
            }
            if (this.orientFilter !== '') {
                if (this.orientationOfData(item) !== this.orientFilter) {
                    return false;
                }
            }
            if (this.folderFilter !== '') {
                const folderId = item.folder_id || '';
                if (this.folderFilter === '_root') {
                    if (folderId !== '') {
                        return false;
                    }
                } else if (folderId !== this.folderFilter) {
                    return false;
                }
            }
            return true;
        },

        applyFilters(this: GereqMediaBoxCtx) {
            const matched = this.items.filter((item) => this.itemMatchesData(item));
            matched.sort((a, b) => {
                if (this.sortBy === 'name') {
                    return (a.label || '').localeCompare(b.label || '', undefined, { sensitivity: 'base' });
                }
                const ac = Number(a.created || 0);
                const bc = Number(b.created || 0);
                return this.sortBy === 'oldest' ? ac - bc : bc - ac;
            });

            const matchedIds = matched.map((item) => item.value);
            const matchedSet = new Set(matchedIds);
            const grid = this.libraryGrid();
            const thumbs = [...this.$el.querySelectorAll<HTMLElement>('.media-box__thumb')];
            const byId = new Map(thumbs.map((el) => [el.getAttribute('data-id') || '', el]));

            thumbs.forEach((el) => {
                el.hidden = true;
            });
            matchedIds.forEach((id) => {
                const el = byId.get(id);
                if (!el) {
                    return;
                }
                el.hidden = false;
                if (grid) {
                    grid.appendChild(el);
                }
            });

            this.totalCount = this.items.length;
            this.visibleCount = matched.length;
            this.libraryEmpty = matched.length === 0;
            this.resultsText = this.formatResults();
        },

        resetFilters(this: GereqMediaBoxCtx) {
            this.query = '';
            this.typeFilter = '';
            this.orientFilter = '';
            this.folderFilter = '';
            this.sortBy = 'newest';
            this.applyFilters();
            this.$nextTick(() => this.$refs.search?.focus());
        },
    }));
}
