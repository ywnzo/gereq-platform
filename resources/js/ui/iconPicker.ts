import type Alpine from '@alpinejs/csp';

function normalize(value: string): string {
    return value
        .trim()
        .toLowerCase()
        .normalize('NFD')
        .replace(/\p{M}/gu, '')
        .replace(/[^a-z0-9]+/g, '');
}

function pretty(name: string): string {
    const trimmed = name.trim();
    if (trimmed === '') {
        return '';
    }

    return trimmed.replaceAll('_', ' ');
}

type IconPickerCtx = {
    open: boolean;
    query: string;
    value: string;
    $el: HTMLElement;
    init(): void;
    destroy(): void;
    toggle(): void;
    close(): void;
    pick(name: string): void;
    applyFilter(): void;
    syncDom(): void;
    _onRootClick: ((event: MouseEvent) => void) | null;
    _onPointer: ((event: PointerEvent) => void) | null;
    _onSearchInput: ((event: Event) => void) | null;
    _onSearchKeydown: ((event: KeyboardEvent) => void) | null;
};

export function registerIconPicker(alpine: typeof Alpine): void {
    alpine.data('gereqIconPicker', () => ({
        open: false,
        query: '',
        value: '',
        _onRootClick: null as ((event: MouseEvent) => void) | null,
        _onPointer: null as ((event: PointerEvent) => void) | null,
        _onSearchInput: null as ((event: Event) => void) | null,
        _onSearchKeydown: null as ((event: KeyboardEvent) => void) | null,

        init(this: IconPickerCtx) {
            const root = this.$el;
            const hidden = root.querySelector<HTMLInputElement>('input[type="hidden"]');
            this.value = (hidden?.value || root.dataset.value || '').trim();
            this.syncDom();

            const onRootClick = (event: MouseEvent) => {
                const target = event.target as HTMLElement | null;
                if (!target) {
                    return;
                }

                const trigger = target.closest('.g-icon-picker__trigger');
                if (trigger && root.contains(trigger)) {
                    event.preventDefault();
                    this.toggle();
                    return;
                }

                const cell = target.closest<HTMLElement>('.g-icon-picker__cell');
                if (cell && root.contains(cell) && cell.dataset.empty !== '1') {
                    event.preventDefault();
                    this.pick(cell.dataset.icon || '');
                }
            };
            root.addEventListener('click', onRootClick);
            this._onRootClick = onRootClick;

            const search = root.querySelector<HTMLInputElement>('.g-icon-picker__search-input');
            if (search) {
                const onSearchInput = (event: Event) => {
                    this.query = (event.target as HTMLInputElement).value;
                    this.applyFilter();
                };
                const onSearchKeydown = (event: KeyboardEvent) => {
                    if (event.key === 'Escape') {
                        event.preventDefault();
                        this.close();
                        return;
                    }
                    if (event.key !== 'Enter') {
                        return;
                    }
                    event.preventDefault();
                    const visible = root.querySelector<HTMLElement>(
                        '.g-icon-picker__cell:not([hidden]):not([data-empty="1"])',
                    );
                    const typed = this.query.trim().replaceAll(' ', '_');
                    if (visible) {
                        this.pick(visible.dataset.icon || typed);
                    } else if (/^[a-z0-9_]+$/i.test(typed)) {
                        this.pick(typed);
                    }
                };
                search.addEventListener('input', onSearchInput);
                search.addEventListener('keydown', onSearchKeydown);
                this._onSearchInput = onSearchInput;
                this._onSearchKeydown = onSearchKeydown;
            }

            const onPointer = (event: PointerEvent) => {
                if (!this.open) {
                    return;
                }
                const target = event.target as Node | null;
                if (target && !root.contains(target)) {
                    this.close();
                }
            };
            document.addEventListener('pointerdown', onPointer);
            this._onPointer = onPointer;
        },

        destroy(this: IconPickerCtx) {
            if (this._onRootClick) {
                this.$el.removeEventListener('click', this._onRootClick);
            }
            if (this._onPointer) {
                document.removeEventListener('pointerdown', this._onPointer);
            }
            const search = this.$el.querySelector<HTMLInputElement>('.g-icon-picker__search-input');
            if (search && this._onSearchInput) {
                search.removeEventListener('input', this._onSearchInput);
            }
            if (search && this._onSearchKeydown) {
                search.removeEventListener('keydown', this._onSearchKeydown);
            }
        },

        toggle(this: IconPickerCtx) {
            this.open = !this.open;
            this.$el.classList.toggle('is-open', this.open);
            const list = this.$el.querySelector('.g-icon-picker__list');
            list?.classList.toggle('is-open', this.open);
            const trigger = this.$el.querySelector('.g-icon-picker__trigger');
            trigger?.classList.toggle('is-open', this.open);
            trigger?.setAttribute('aria-expanded', this.open ? 'true' : 'false');
            if (this.open) {
                queueMicrotask(() => {
                    const input = this.$el.querySelector<HTMLInputElement>('.g-icon-picker__search-input');
                    input?.focus();
                    input?.select();
                });
            }
        },

        close(this: IconPickerCtx) {
            if (!this.open) {
                return;
            }
            this.open = false;
            this.$el.classList.remove('is-open');
            this.$el.querySelector('.g-icon-picker__list')?.classList.remove('is-open');
            this.$el.querySelector('.g-icon-picker__trigger')?.classList.remove('is-open');
            this.$el.querySelector('.g-icon-picker__trigger')?.setAttribute('aria-expanded', 'false');
        },

        pick(this: IconPickerCtx, name: string) {
            const next = name.trim();
            this.value = next;
            const hidden = this.$el.querySelector<HTMLInputElement>('input[type="hidden"]');
            if (hidden) {
                hidden.value = next;
            }
            this.$el.dataset.value = next;
            this.syncDom();
            this.$el.dispatchEvent(new Event('input', { bubbles: true }));
            this.$el.dispatchEvent(new Event('change', { bubbles: true }));
            this.close();
        },

        applyFilter(this: IconPickerCtx) {
            const root = this.$el;
            const q = normalize(this.query);
            let visible = 0;
            root.querySelectorAll<HTMLElement>('.g-icon-picker__cell').forEach((cell) => {
                if (cell.dataset.empty === '1') {
                    return;
                }
                const hay = normalize(`${cell.dataset.icon || ''} ${cell.dataset.search || ''}`);
                const match = q === '' || hay.includes(q);
                cell.hidden = !match;
                if (match) {
                    visible += 1;
                }
            });
            const empty = root.querySelector<HTMLElement>('[data-empty="1"]');
            if (empty) {
                empty.hidden = !(q !== '' && visible === 0);
            }
        },

        syncDom(this: IconPickerCtx) {
            const name = this.value;
            const glyph = this.$el.querySelector('.g-icon-picker__glyph');
            const label = this.$el.querySelector('.g-icon-picker__name');
            const empty = this.$el.querySelector('.g-icon-picker__placeholder');
            if (glyph) {
                glyph.textContent = name || 'apps';
            }
            if (label) {
                label.textContent = pretty(name);
            }
            this.$el.classList.toggle('is-empty', name === '');
            empty?.toggleAttribute('hidden', name !== '');
            label?.toggleAttribute('hidden', name === '');
            this.$el.querySelectorAll<HTMLElement>('.g-icon-picker__cell').forEach((cell) => {
                cell.classList.toggle('is-selected', cell.dataset.icon === name);
            });
        },
    }));
}
