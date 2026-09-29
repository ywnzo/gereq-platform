import type Alpine from '@alpinejs/csp';

type DriveEntry = {
    kind: 'folder' | 'asset';
    id: string;
    name: string;
    url: string;
    edit_url?: string | null;
    move_url?: string | null;
    mime?: string;
    size?: number | null;
    size_label?: string;
    created?: string;
    is_image?: boolean;
    is_video?: boolean;
    is_pdf?: boolean;
    is_text?: boolean;
    thumb?: string | null;
    file_url?: string | null;
    alt?: string | null;
    folder_id?: string | null;
    dimensions?: string | null;
    path?: string | null;
    icon?: string;
};

type DriveConfig = {
    can_manage: boolean;
    folder_id: string;
    view: 'grid' | 'list';
    urls: {
        index: string;
        store: string;
        bulk: string;
        folder_store: string;
        folder_update: string;
        folder_destroy: string;
        asset_update: string;
        asset_destroy: string;
        asset_move: string;
        folder_move: string;
        folder_edit: string;
        upload_page: string;
    };
    i18n: Record<string, string>;
    folder_options: Array<{ value: string; label: string }>;
};

type MediaDriveState = {
    /** Alpine magic — injected on the component root at runtime. */
    $el: HTMLElement;
    view: 'grid' | 'list';
    selected: string[];
    focusId: string | null;
    previewOpen: boolean;
    previewId: string | null;
    previewText: string;
    previewTextLoading: boolean;
    previewTextError: string;
    newOpen: boolean;
    menuOpen: boolean;
    menuX: number;
    menuY: number;
    menuEntryId: string | null;
    dragging: boolean;
    uploading: boolean;
    uploadProgress: string;
    canManage: boolean;
    folderId: string;
    entries: DriveEntry[];
    urls: DriveConfig['urls'];
    i18n: Record<string, string>;
    folderOptions: Array<{ value: string; label: string }>;
    bulkAction: string;
    bulkFolderId: string;
    _dragDepth: number;
    init(): void;
    get selectedCount(): number;
    get focusEntry(): DriveEntry | null;
    get previewEntry(): DriveEntry | null;
    get menuEntry(): DriveEntry | null;
    get hasSelection(): boolean;
    get menuStyle(): string;
    isSelected(id: string): boolean;
    isFocused(id: string): boolean;
    isPreviewImage(): boolean;
    isPreviewVideo(): boolean;
    isPreviewPdf(): boolean;
    isPreviewText(): boolean;
    previewTextReady(): boolean;
    toggleView(): void;
    setView(view: 'grid' | 'list'): void;
    toggleSelect(id: string, event?: Event): void;
    selectOnly(id: string): void;
    clearSelection(): void;
    selectAll(): void;
    findEntry(id: string): DriveEntry | null;
    openPreview(entry: DriveEntry): void;
    openPreviewById(id: string): void;
    closePreview(): void;
    loadPreviewText(entry: DriveEntry): Promise<void>;
    openEntry(entry: DriveEntry): void;
    openById(id: string): void;
    onTileClick(entry: DriveEntry, event: MouseEvent): void;
    onTileClickById(id: string, event: MouseEvent): void;
    onTileDblClick(entry: DriveEntry): void;
    onContextMenu(entry: DriveEntry, event: MouseEvent): void;
    onContextMenuById(id: string, event: MouseEvent): void;
    closeMenu(): void;
    menuAction(action: string): void;
    renamePreview(): void;
    deletePreview(): void;
    toggleNew(event?: Event): void;
    closeNew(): void;
    pickFiles(event?: Event): void;
    onFilesPicked(event: Event): void;
    onDragEnter(event: DragEvent): void;
    onDragOver(event: DragEvent): void;
    onDragLeave(event: DragEvent): void;
    onDrop(event: DragEvent): void;
    createFolder(): Promise<void>;
    renameEntry(entry: DriveEntry): Promise<void>;
    deleteEntry(entry: DriveEntry): Promise<void>;
    confirmBulk(event: Event): Promise<void>;
    formatSelectedLabel(): string;
    formatMime(entry: DriveEntry | null): string;
    isFolderFocus(): boolean;
    isImageFocus(): boolean;
    csrf(): string;
    postForm(url: string, fields: Record<string, string>): Promise<Response>;
    uploadFiles(files: FileList | File[]): Promise<void>;
    toast(message: string, type?: string): void;
    askConfirm(opts: { title?: string; body?: string; danger?: boolean }): Promise<boolean>;
    askPrompt(opts: {
        title?: string;
        body?: string;
        inputLabel?: string;
        inputValue?: string;
        confirmLabel?: string;
    }): Promise<string | null>;
};

const VIEW_KEY = 'gereq-media-view';

async function readErrorMessage(res: Response): Promise<string> {
    try {
        const payload = (await res.json()) as { error?: { message?: string } };
        const message = payload.error?.message?.trim();
        if (message) {
            return message;
        }
    } catch {
        // ignore non-JSON error bodies
    }

    return '';
}

function parseConfig(root: HTMLElement): DriveConfig {
    const raw = root.dataset.config || '{}';
    return JSON.parse(raw) as DriveConfig;
}

function parseEntries(root: HTMLElement): DriveEntry[] {
    const raw = root.dataset.entries || '[]';
    return JSON.parse(raw) as DriveEntry[];
}

type GereqUi = {
    toast?: (message: string, tone?: string) => void;
    confirm?: (opts: {
        title?: string;
        body?: string;
        danger?: boolean;
        confirmLabel?: string;
    }) => Promise<boolean>;
    prompt?: (opts: {
        title?: string;
        body?: string;
        input?: boolean;
        inputLabel?: string;
        inputValue?: string;
        inputRequired?: boolean;
        confirmLabel?: string;
        danger?: boolean;
    }) => Promise<string | null>;
};

function gereqUi(): GereqUi {
    return (window.gereq ?? {}) as GereqUi;
}

export function registerMediaDrive(alpine: typeof Alpine): void {
    // Alpine injects $el at runtime; omit it from the factory return type.
    alpine.data('mediaDrive', (): Omit<MediaDriveState, '$el'> => ({
        view: 'grid',
        selected: [],
        focusId: null,
        previewOpen: false,
        previewId: null,
        previewText: '',
        previewTextLoading: false,
        previewTextError: '',
        newOpen: false,
        menuOpen: false,
        menuX: 0,
        menuY: 0,
        menuEntryId: null,
        dragging: false,
        uploading: false,
        uploadProgress: '',
        canManage: false,
        folderId: '',
        entries: [],
        urls: {
            index: '/admin/media',
            store: '/admin/media',
            bulk: '/admin/media/bulk',
            folder_store: '/admin/media/folders',
            folder_update: '/admin/media/folders/__ID__',
            folder_destroy: '/admin/media/folders/__ID__/delete',
            asset_update: '/admin/media/__ID__',
            asset_destroy: '/admin/media/__ID__/delete',
            asset_move: '/admin/media/__ID__/move',
            folder_move: '/admin/media/folders/__ID__/move',
            folder_edit: '/admin/media/folders/__ID__/edit',
            upload_page: '/admin/media/upload',
        },
        i18n: {},
        folderOptions: [],
        bulkAction: '',
        bulkFolderId: '',
        _dragDepth: 0,

        init(this: MediaDriveState) {
            const root = this.$el;
            const config = parseConfig(root);
            this.entries = parseEntries(root);
            this.canManage = !!config.can_manage;
            this.folderId = config.folder_id || '';
            this.urls = { ...this.urls, ...config.urls };
            this.i18n = config.i18n || {};
            this.folderOptions = config.folder_options || [];
            this.bulkFolderId = this.folderId;

            const saved = localStorage.getItem(VIEW_KEY);
            if (saved === 'list' || saved === 'grid') {
                this.view = saved;
            } else if (config.view === 'list' || config.view === 'grid') {
                this.view = config.view;
            }

            document.addEventListener('click', (event: MouseEvent) => {
                const target = event.target as Node | null;
                const root = this.$el;
                if (target && root.contains(target)) {
                    const keepNew = (target as Element).closest?.('.media-drive__new');
                    const keepMenu = (target as Element).closest?.('.media-drive__menu');
                    if (keepNew || keepMenu) {
                        return;
                    }
                }
                this.closeMenu();
                this.closeNew();
            });
            document.addEventListener('keydown', (event: KeyboardEvent) => {
                if (event.key === 'Escape') {
                    if (this.previewOpen) {
                        this.closePreview();
                        return;
                    }
                    this.closeMenu();
                    this.closeNew();
                    this.clearSelection();
                }
            });
        },

        get selectedCount() {
            return this.selected.length;
        },

        get hasSelection() {
            return this.selected.length > 0;
        },

        get focusEntry() {
            if (!this.focusId) {
                return null;
            }
            return this.entries.find((row) => row.id === this.focusId) ?? null;
        },

        get previewEntry() {
            if (!this.previewId) {
                return null;
            }
            return this.entries.find((row) => row.id === this.previewId) ?? null;
        },

        get menuEntry() {
            if (!this.menuEntryId) {
                return null;
            }
            return this.entries.find((row) => row.id === this.menuEntryId) ?? null;
        },

        get menuStyle() {
            return `top:${this.menuY}px;left:${this.menuX}px`;
        },

        isSelected(id: string) {
            return this.selected.includes(id);
        },

        isFocused(id: string) {
            return this.focusId === id;
        },

        isPreviewImage() {
            const entry = this.previewEntry;
            return !!entry && entry.kind === 'asset' && !!entry.is_image && !!entry.file_url;
        },

        isPreviewVideo() {
            const entry = this.previewEntry;
            return !!entry && entry.kind === 'asset' && !!entry.is_video && !!entry.file_url;
        },

        isPreviewPdf() {
            const entry = this.previewEntry;
            return !!entry && entry.kind === 'asset' && !!entry.is_pdf && !!entry.file_url;
        },

        isPreviewText() {
            const entry = this.previewEntry;
            return !!entry && entry.kind === 'asset' && !!entry.is_text && !!entry.file_url;
        },

        previewTextReady() {
            return !this.previewTextLoading && !this.previewTextError;
        },

        toggleView() {
            this.setView(this.view === 'grid' ? 'list' : 'grid');
        },

        setView(view: 'grid' | 'list') {
            this.view = view;
            localStorage.setItem(VIEW_KEY, view);
        },

        toggleSelect(id: string, event?: Event) {
            event?.stopPropagation();
            if (this.selected.includes(id)) {
                this.selected = this.selected.filter((row) => row !== id);
            } else {
                this.selected = [...this.selected, id];
            }
            this.focusId = id;
        },

        selectOnly(id: string) {
            this.selected = [id];
            this.focusId = id;
        },

        clearSelection() {
            this.selected = [];
            this.focusId = null;
        },

        selectAll() {
            this.selected = this.entries
                .filter((row) => row.kind === 'asset')
                .map((row) => row.id);
        },

        findEntry(id: string) {
            return this.entries.find((row) => row.id === id) ?? null;
        },

        openPreview(entry: DriveEntry) {
            if (entry.kind === 'folder') {
                window.location.href = entry.url;
                return;
            }
            this.focusId = entry.id;
            this.previewId = entry.id;
            this.previewOpen = true;
            this.previewText = '';
            this.previewTextError = '';
            this.previewTextLoading = false;
            this.closeMenu();
            this.closeNew();
            if (entry.is_text && entry.file_url) {
                void this.loadPreviewText(entry);
            }
        },

        openPreviewById(id: string) {
            const entry = this.findEntry(id);
            if (entry) {
                this.openPreview(entry);
            }
        },

        closePreview() {
            this.previewOpen = false;
            this.previewId = null;
            this.previewText = '';
            this.previewTextError = '';
            this.previewTextLoading = false;
        },

        async loadPreviewText(entry: DriveEntry) {
            if (!entry.file_url) {
                return;
            }
            const maxBytes = 512 * 1024;
            if (typeof entry.size === 'number' && entry.size > maxBytes) {
                this.previewTextError = this.i18n.preview_too_large || 'File is too large for inline preview.';
                return;
            }
            this.previewTextLoading = true;
            this.previewTextError = '';
            this.previewText = '';
            try {
                const res = await fetch(entry.file_url, {
                    credentials: 'same-origin',
                    headers: { Accept: 'text/plain,*/*' },
                });
                if (!res.ok) {
                    throw new Error('preview_failed');
                }
                const text = await res.text();
                if (this.previewId !== entry.id) {
                    return;
                }
                this.previewText =
                    text.length > maxBytes ? `${text.slice(0, maxBytes)}\n…` : text;
            } catch {
                if (this.previewId === entry.id) {
                    this.previewTextError = this.i18n.preview_failed || 'Could not load file preview.';
                }
            } finally {
                if (this.previewId === entry.id) {
                    this.previewTextLoading = false;
                }
            }
        },

        openEntry(entry: DriveEntry) {
            this.openPreview(entry);
        },

        openById(id: string) {
            this.openPreviewById(id);
        },

        onTileClick(entry: DriveEntry, event: MouseEvent) {
            this.closeMenu();
            this.closeNew();
            if (event.metaKey || event.ctrlKey) {
                this.toggleSelect(entry.id);
                return;
            }
            if (event.shiftKey && this.focusId) {
                const ids = this.entries.map((row) => row.id);
                const a = ids.indexOf(this.focusId);
                const b = ids.indexOf(entry.id);
                if (a >= 0 && b >= 0) {
                    const [from, to] = a < b ? [a, b] : [b, a];
                    const range = ids.slice(from, to + 1);
                    this.selected = Array.from(new Set([...this.selected, ...range]));
                    this.focusId = entry.id;
                    return;
                }
            }
            this.focusId = entry.id;
            if (!this.selected.includes(entry.id)) {
                this.selected = [];
            }
        },

        onTileClickById(id: string, event: MouseEvent) {
            const entry = this.findEntry(id);
            if (entry) {
                this.onTileClick(entry, event);
            }
        },

        onTileDblClick(entry: DriveEntry) {
            this.openPreview(entry);
        },

        onContextMenu(entry: DriveEntry, event: MouseEvent) {
            event.preventDefault();
            event.stopPropagation();
            this.focusId = entry.id;
            if (!this.selected.includes(entry.id)) {
                this.selected = [entry.id];
            }
            this.menuEntryId = entry.id;
            this.menuX = event.clientX;
            this.menuY = event.clientY;
            this.menuOpen = true;
            this.newOpen = false;
        },

        onContextMenuById(id: string, event: MouseEvent) {
            const entry = this.findEntry(id);
            if (entry) {
                this.onContextMenu(entry, event);
            }
        },

        renamePreview() {
            if (this.previewEntry) {
                void this.renameEntry(this.previewEntry);
            }
        },

        deletePreview() {
            if (this.previewEntry) {
                void this.deleteEntry(this.previewEntry);
            }
        },

        closeMenu() {
            this.menuOpen = false;
            this.menuEntryId = null;
        },

        menuAction(action: string) {
            const entry = this.menuEntry;
            this.closeMenu();
            if (!entry) {
                return;
            }
            if (action === 'open') {
                this.openPreview(entry);
                return;
            }
            if (action === 'download' && entry.file_url) {
                window.open(entry.file_url, '_blank', 'noopener');
                return;
            }
            if (action === 'rename') {
                void this.renameEntry(entry);
                return;
            }
            if (action === 'move') {
                if (entry.move_url) {
                    window.location.href = entry.move_url;
                    return;
                }
                const url = entry.kind === 'folder'
                    ? this.urls.folder_move.replace('__ID__', entry.id)
                    : this.urls.asset_move.replace('__ID__', entry.id);
                window.location.href = url;
                return;
            }
            if (action === 'edit' && entry.kind === 'asset') {
                window.location.href = entry.edit_url || this.urls.asset_update.replace('__ID__', entry.id) + '/edit';
                return;
            }
            if (action === 'delete') {
                void this.deleteEntry(entry);
            }
        },

        toggleNew(event?: Event) {
            event?.stopPropagation();
            this.newOpen = !this.newOpen;
            this.closeMenu();
        },

        closeNew() {
            this.newOpen = false;
        },

        pickFiles(this: MediaDriveState, event?: Event) {
            event?.preventDefault();
            event?.stopPropagation();
            const input = this.$el.querySelector<HTMLInputElement>('[data-media-file-input]');
            if (!input) {
                this.toast(this.i18n.error_generic || 'Something went wrong.', 'error');
                return;
            }
            // Keep this synchronous with the click gesture, then dismiss the menu.
            input.click();
            this.closeNew();
        },

        onFilesPicked(event: Event) {
            const input = event.target as HTMLInputElement;
            if (input.files?.length) {
                void this.uploadFiles(input.files);
                input.value = '';
            }
        },

        onDragEnter(event: DragEvent) {
            if (!this.canManage) {
                return;
            }
            event.preventDefault();
            this._dragDepth += 1;
            this.dragging = true;
        },

        onDragOver(event: DragEvent) {
            if (!this.canManage) {
                return;
            }
            event.preventDefault();
            if (event.dataTransfer) {
                event.dataTransfer.dropEffect = 'copy';
            }
            this.dragging = true;
        },

        onDragLeave(event: DragEvent) {
            if (!this.canManage) {
                return;
            }
            event.preventDefault();
            this._dragDepth = Math.max(0, this._dragDepth - 1);
            if (this._dragDepth === 0) {
                this.dragging = false;
            }
        },

        onDrop(event: DragEvent) {
            if (!this.canManage) {
                return;
            }
            event.preventDefault();
            this._dragDepth = 0;
            this.dragging = false;
            const files = event.dataTransfer?.files;
            if (files?.length) {
                void this.uploadFiles(files);
            }
        },

        async createFolder() {
            this.closeNew();
            if (!this.canManage) {
                return;
            }
            const name = await this.askPrompt({
                title: this.i18n.folder_create || 'New folder',
                body: this.i18n.folder_create_body || '',
                inputLabel: this.i18n.folder_name || 'Name',
                inputValue: '',
                confirmLabel: this.i18n.create || 'Create',
            });
            if (name === null || name.trim() === '') {
                return;
            }
            const res = await this.postForm(this.urls.folder_store, {
                name: name.trim(),
                parent_id: this.folderId,
            });
            if (!res.ok) {
                this.toast(this.i18n.error_generic || 'Something went wrong.', 'error');
                return;
            }
            window.location.reload();
        },

        async renameEntry(entry: DriveEntry) {
            if (!this.canManage) {
                return;
            }
            const name = await this.askPrompt({
                title: entry.kind === 'folder'
                    ? (this.i18n.folder_rename || 'Rename folder')
                    : (this.i18n.rename || 'Rename'),
                inputLabel: this.i18n.folder_name || 'Name',
                inputValue: entry.name,
                confirmLabel: this.i18n.save || 'Save',
            });
            if (name === null || name.trim() === '' || name.trim() === entry.name) {
                return;
            }
            const url = entry.kind === 'folder'
                ? this.urls.folder_update.replace('__ID__', entry.id)
                : this.urls.asset_update.replace('__ID__', entry.id);
            const fields: Record<string, string> = entry.kind === 'folder'
                ? { name: name.trim() }
                : {
                    title: name.trim(),
                    alt: entry.alt || '',
                    folder_id: entry.folder_id || '',
                };
            const res = await this.postForm(url, fields);
            if (!res.ok) {
                this.toast(this.i18n.error_generic || 'Something went wrong.', 'error');
                return;
            }
            window.location.reload();
        },

        async deleteEntry(entry: DriveEntry) {
            if (!this.canManage) {
                return;
            }
            const ok = await this.askConfirm({
                title: this.i18n.delete_title || 'Delete?',
                body: entry.kind === 'folder'
                    ? (this.i18n.folder_confirm_delete || 'Delete folder?')
                    : (this.i18n.confirm_delete || 'Delete file?'),
                danger: true,
            });
            if (!ok) {
                return;
            }
            const url = entry.kind === 'folder'
                ? this.urls.folder_destroy.replace('__ID__', entry.id)
                : this.urls.asset_destroy.replace('__ID__', entry.id);
            const res = await this.postForm(url, {});
            if (!res.ok) {
                const data = await res.json().catch(() => null) as { error?: { message?: string } } | null;
                this.toast(data?.error?.message || this.i18n.error_generic || 'Something went wrong.', 'error');
                return;
            }
            window.location.reload();
        },

        async confirmBulk(event: Event) {
            if (this.bulkAction !== 'delete') {
                return;
            }
            event.preventDefault();
            const ok = await this.askConfirm({
                title: this.i18n.delete_title || 'Delete?',
                body: this.i18n.bulk_confirm_delete || 'Delete selected?',
                danger: true,
            });
            if (!ok) {
                return;
            }
            const form = event.target as HTMLFormElement;
            const confirmed = form.querySelector<HTMLInputElement>('input[name="confirmed"]');
            if (confirmed) {
                confirmed.value = '1';
            }
            form.submit();
        },

        formatSelectedLabel() {
            return `${this.selectedCount} ${this.i18n.selected || 'selected'}`;
        },

        formatMime(entry: DriveEntry | null) {
            if (!entry) {
                return '—';
            }
            if (entry.kind === 'folder') {
                return this.i18n.folder_badge || 'Folder';
            }
            return entry.mime || '—';
        },

        isFolderFocus() {
            return this.focusEntry?.kind === 'folder';
        },

        isImageFocus() {
            const entry = this.focusEntry;
            return !!entry && entry.kind === 'asset' && !!entry.is_image && !!entry.thumb;
        },

        csrf() {
            return window.__GEREQ_CSRF__ || '';
        },

        async postForm(url: string, fields: Record<string, string>) {
            const body = new FormData();
            body.set('_csrf', this.csrf());
            for (const [key, value] of Object.entries(fields)) {
                body.set(key, value);
            }
            return fetch(url, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': this.csrf(),
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'application/json',
                },
                body,
                credentials: 'same-origin',
            });
        },

        async uploadFiles(files: FileList | File[]) {
            if (!this.canManage || this.uploading) {
                return;
            }
            const list = Array.from(files);
            if (list.length === 0) {
                return;
            }
            this.uploading = true;
            let done = 0;
            let failed = 0;
            let lastError = '';
            for (const file of list) {
                this.uploadProgress = `${done + 1} / ${list.length}`;
                const body = new FormData();
                body.set('_csrf', this.csrf());
                body.set('file', file);
                if (this.folderId) {
                    body.set('folder_id', this.folderId);
                }
                try {
                    const res = await fetch(this.urls.store, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': this.csrf(),
                            'X-Requested-With': 'XMLHttpRequest',
                            Accept: 'application/json',
                        },
                        body,
                        credentials: 'same-origin',
                    });
                    if (!res.ok) {
                        failed += 1;
                        lastError = await readErrorMessage(res);
                    } else {
                        done += 1;
                    }
                } catch {
                    failed += 1;
                }
            }
            this.uploading = false;
            this.uploadProgress = '';
            if (failed > 0 && done === 0) {
                this.toast(lastError || this.i18n.upload_failed || 'Upload failed.', 'error');
                return;
            }
            if (failed > 0) {
                this.toast(
                    (this.i18n.upload_partial || 'Uploaded %ok%, failed %fail%')
                        .replace('%ok%', String(done))
                        .replace('%fail%', String(failed)),
                    'warning',
                );
            }
            window.location.reload();
        },

        toast(message: string, type = 'info') {
            gereqUi().toast?.(message, type);
        },

        async askConfirm(opts: { title?: string; body?: string; danger?: boolean }) {
            const confirm = gereqUi().confirm;
            if (confirm) {
                return confirm(opts);
            }
            return window.confirm(opts.body || opts.title || '');
        },

        async askPrompt(opts: {
            title?: string;
            body?: string;
            inputLabel?: string;
            inputValue?: string;
            confirmLabel?: string;
        }) {
            const prompt = gereqUi().prompt;
            if (prompt) {
                return prompt({ ...opts, input: true, inputRequired: true, danger: false });
            }
            return window.prompt(opts.body || opts.title || '', opts.inputValue || '');
        },
    }));
}
