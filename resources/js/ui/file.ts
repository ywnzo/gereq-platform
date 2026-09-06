import type Alpine from '@alpinejs/csp';

type GereqFileCtx = {
    fileName: string;
    fileMeta: string;
    dragging: boolean;
    hasFile: boolean;
    dropTitle: string;
    dropHint: string;
    $el: HTMLElement;
    boxClass: string;
    titleText: string;
    metaText: string;
    formatSize: (bytes: number) => string;
    applyFiles: (files: FileList | null) => void;
    clear: () => void;
    _dragDepth: number;
};

function formatBytes(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }
    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(bytes < 10 * 1024 ? 1 : 0)} KB`;
    }
    return `${(bytes / (1024 * 1024)).toFixed(bytes < 10 * 1024 * 1024 ? 1 : 0)} MB`;
}

export function registerFile(alpine: typeof Alpine): void {
    alpine.data('gereqFile', () => ({
        fileName: '',
        fileMeta: '',
        dragging: false,
        hasFile: false,
        dropTitle: '',
        dropHint: '',
        _dragDepth: 0,

        init(this: GereqFileCtx) {
            const root = this.$el;
            this.dropTitle = root.dataset.dropTitle || '';
            this.dropHint = root.dataset.dropHint || '';

            const input = root.querySelector<HTMLInputElement>('.g-file__input');
            if (!input) {
                return;
            }

            input.addEventListener('change', () => {
                this.applyFiles(input.files);
            });

            const zone = root.querySelector<HTMLElement>('.g-file__shell') ?? root;

            zone.addEventListener('dragenter', (event) => {
                event.preventDefault();
                this._dragDepth += 1;
                this.dragging = true;
            });

            zone.addEventListener('dragover', (event) => {
                event.preventDefault();
                if (event.dataTransfer) {
                    event.dataTransfer.dropEffect = 'copy';
                }
                this.dragging = true;
            });

            zone.addEventListener('dragleave', (event) => {
                event.preventDefault();
                this._dragDepth = Math.max(0, this._dragDepth - 1);
                if (this._dragDepth === 0) {
                    this.dragging = false;
                }
            });

            zone.addEventListener('drop', (event) => {
                event.preventDefault();
                this._dragDepth = 0;
                this.dragging = false;
                const files = event.dataTransfer?.files ?? null;
                if (!files?.length) {
                    return;
                }
                if (typeof DataTransfer !== 'undefined') {
                    const dt = new DataTransfer();
                    const max = input.multiple ? files.length : 1;
                    for (let i = 0; i < max; i += 1) {
                        dt.items.add(files[i]);
                    }
                    input.files = dt.files;
                }
                this.applyFiles(input.files);
                input.dispatchEvent(new Event('change', { bubbles: true }));
            });

            const clearBtn = root.querySelector<HTMLElement>('[data-file-clear]');
            clearBtn?.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                this.clear();
            });
        },

        get boxClass(this: GereqFileCtx): string {
            return [
                this.dragging ? 'is-dragging' : '',
                this.hasFile ? 'is-filled' : '',
            ].filter(Boolean).join(' ');
        },

        get titleText(this: GereqFileCtx): string {
            if (!this.hasFile) {
                return this.dropTitle;
            }
            if (this.$el.classList.contains('g-file--compact') && this.fileMeta) {
                return `${this.fileName} · ${this.fileMeta}`;
            }
            return this.fileName;
        },

        get metaText(this: GereqFileCtx): string {
            if (!this.hasFile) {
                return this.dropHint;
            }
            if (this.$el.classList.contains('g-file--compact')) {
                return '';
            }
            return this.fileMeta;
        },

        formatSize(this: GereqFileCtx, bytes: number): string {
            return formatBytes(bytes);
        },

        applyFiles(this: GereqFileCtx, files: FileList | null) {
            if (!files?.length) {
                this.hasFile = false;
                this.fileName = '';
                this.fileMeta = '';
                return;
            }

            this.hasFile = true;
            if (files.length === 1) {
                const file = files[0];
                this.fileName = file.name;
                this.fileMeta = this.formatSize(file.size);
                return;
            }

            let total = 0;
            for (let i = 0; i < files.length; i += 1) {
                total += files[i].size;
            }
            this.fileName = `${files.length}×`;
            this.fileMeta = this.formatSize(total);
        },

        clear(this: GereqFileCtx) {
            const input = this.$el.querySelector<HTMLInputElement>('.g-file__input');
            if (input) {
                input.value = '';
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }
            this.hasFile = false;
            this.fileName = '';
            this.fileMeta = '';
            this.dragging = false;
            this._dragDepth = 0;
        },
    }));
}
