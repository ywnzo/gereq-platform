import type Alpine from '@alpinejs/csp';

type GereqSelectCtx = {
    open: boolean;
    label: string;
    query: string;
    searchable: boolean;
    $el: HTMLElement;
    $refs: { value?: HTMLInputElement; search?: HTMLInputElement };
    applyFilter: () => void;
    close: () => void;
    applyOption: (option: HTMLElement) => void;
    _onPointer: ((event: PointerEvent) => void) | null;
    _onRootClick: ((event: MouseEvent) => void) | null;
    _onSearchInput: ((event: Event) => void) | null;
    _onSearchKeydown: ((event: KeyboardEvent) => void) | null;
};

export function registerSelect(alpine: typeof Alpine): void {
    alpine.data('gereqSelect', () => ({
        open: false,
        label: '',
        query: '',
        searchable: false,
        _onPointer: null as ((event: PointerEvent) => void) | null,
        _onRootClick: null as ((event: MouseEvent) => void) | null,
        _onSearchInput: null as ((event: Event) => void) | null,
        _onSearchKeydown: null as ((event: KeyboardEvent) => void) | null,

        init(this: GereqSelectCtx) {
            const root = this.$el;
            this.searchable = root.dataset.searchable === '1' || root.dataset.searchable === 'true';
            const selected = root.querySelector('[data-selected="1"]') as HTMLElement | null;
            this.label = selected?.dataset.label || root.dataset.placeholder || 'Vyberte';

            // Nativní click — Alpine CSP @click na option/trigger je nespolehlivý.
            const onRootClick = (event: MouseEvent) => {
                const target = event.target as HTMLElement | null;
                if (!target) {
                    return;
                }

                const trigger = target.closest('.g-select__trigger');
                if (trigger && root.contains(trigger)) {
                    event.preventDefault();
                    this.open = !this.open;
                    if (this.open && this.searchable) {
                        queueMicrotask(() => {
                            const input = root.querySelector<HTMLInputElement>('.g-select__search-input');
                            input?.focus();
                            input?.select();
                        });
                    }
                    return;
                }

                const option = target.closest('.g-select__option') as HTMLElement | null;
                if (option && root.contains(option)) {
                    event.preventDefault();
                    event.stopPropagation();
                    if (option.dataset.empty === '1' || option.hidden || option.classList.contains('is-filtered-out')) {
                        return;
                    }
                    this.applyOption(option);
                }
            };
            root.addEventListener('click', onRootClick);
            this._onRootClick = onRootClick;

            const search = root.querySelector<HTMLInputElement>('.g-select__search-input');
            if (search) {
                const onSearchInput = (event: Event) => {
                    const t = event.target as HTMLInputElement;
                    this.query = t.value;
                    this.applyFilter();
                };
                const onSearchKeydown = (event: KeyboardEvent) => {
                    if (event.key === 'Escape') {
                        event.preventDefault();
                        this.close();
                    }
                };
                search.addEventListener('input', onSearchInput);
                search.addEventListener('keyup', onSearchInput);
                search.addEventListener('search', onSearchInput);
                search.addEventListener('keydown', onSearchKeydown);
                this._onSearchInput = onSearchInput;
                this._onSearchKeydown = onSearchKeydown;
            }

            this.applyFilter();

            const onPointer = (event: PointerEvent) => {
                if (!root.contains(event.target as Node)) {
                    this.close();
                }
            };
            document.addEventListener('pointerdown', onPointer);
            this._onPointer = onPointer;
        },

        destroy(this: GereqSelectCtx) {
            if (this._onPointer) {
                document.removeEventListener('pointerdown', this._onPointer);
            }
            if (this._onRootClick) {
                this.$el.removeEventListener('click', this._onRootClick);
            }
            if (this._onSearchInput || this._onSearchKeydown) {
                const search = this.$el.querySelector<HTMLInputElement>('.g-select__search-input');
                if (this._onSearchInput) {
                    search?.removeEventListener('input', this._onSearchInput);
                    search?.removeEventListener('keyup', this._onSearchInput);
                    search?.removeEventListener('search', this._onSearchInput);
                }
                if (this._onSearchKeydown) {
                    search?.removeEventListener('keydown', this._onSearchKeydown);
                }
            }
        },

        get listClass(): string {
            return this.open ? 'is-open' : '';
        },

        get triggerClass(): string {
            return this.open ? 'is-open' : '';
        },

        get expandedAttr(): string {
            return this.open ? 'true' : 'false';
        },

        toggle(this: GereqSelectCtx) {
            this.open = !this.open;
            if (this.open && this.searchable) {
                queueMicrotask(() => {
                    const input = this.$el.querySelector<HTMLInputElement>('.g-select__search-input');
                    input?.focus();
                    input?.select();
                });
            }
        },

        close(this: GereqSelectCtx) {
            this.open = false;
            this.query = '';
            const input = this.$el.querySelector<HTMLInputElement>('.g-select__search-input');
            if (input) {
                input.value = '';
            }
            this.applyFilter();
        },

        applyFilter(this: GereqSelectCtx) {
            if (!this.searchable) {
                return;
            }
            const root = this.$el;
            const raw = this.query !== ''
                ? this.query
                : (root.querySelector<HTMLInputElement>('.g-select__search-input')?.value ?? '');
            const q = normalizeSearch(raw);

            root.querySelectorAll<HTMLElement>('.g-select__option').forEach((opt) => {
                if (opt.dataset.empty === '1') {
                    return;
                }
                if ((opt.dataset.value || '') === '' && q !== '') {
                    opt.hidden = true;
                    opt.classList.add('is-filtered-out');
                    return;
                }
                const hay = normalizeSearch(`${opt.dataset.label || ''} ${opt.dataset.search || ''}`);
                const match = q === '' || hay.includes(q);
                opt.hidden = !match;
                opt.classList.toggle('is-filtered-out', !match);
            });

            const empty = root.querySelector<HTMLElement>('[data-empty="1"]');
            if (empty) {
                const visible = root.querySelectorAll('.g-select__option:not([data-empty="1"]):not(.is-filtered-out)').length;
                const showEmpty = q !== '' && visible === 0;
                empty.hidden = !showEmpty;
                empty.classList.toggle('is-filtered-out', !showEmpty);
            }
        },

        choose(event: Event) {
            const option = event.currentTarget as HTMLElement;
            if (option.dataset.empty === '1' || option.hidden || option.classList.contains('is-filtered-out')) {
                return;
            }
            this.applyOption(option);
        },

        setValue(value: string) {
            const root = this.$el as HTMLElement;
            const opt = root.querySelector(`[data-value="${CSS.escape(value)}"]`) as HTMLElement | null;
            if (!opt) {
                return false;
            }
            this.applyOption(opt);
            return true;
        },

        applyOption(option: HTMLElement) {
            const root = this.$el as HTMLElement;
            const input = root.querySelector<HTMLInputElement>('input[type="hidden"]');
            if (!input) {
                return;
            }
            const next = option.dataset.value || '';
            const changed = input.value !== next;
            input.value = next;
            this.label = option.dataset.label || '';
            this.open = false;
            this.query = '';
            const search = root.querySelector<HTMLInputElement>('.g-select__search-input');
            if (search) {
                search.value = '';
            }
            this.applyFilter();
            rootUnselect(root);
            option.classList.add('is-selected');
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));

            if (root.dataset.autosubmit === '1' && next !== '') {
                const form = root.closest('form');
                if (form && changed) {
                    // Po microtasku — ať stihne Alpine přepsat label před navigací.
                    queueMicrotask(() => form.submit());
                }
            }
        },
    }));
}

function rootUnselect(root: HTMLElement): void {
    root.querySelectorAll('.is-selected').forEach((node) => node.classList.remove('is-selected'));
}

/** „66KW“ ≈ „66 kW“, tečky u 1.6 necháváme */
function normalizeSearch(value: string): string {
    return value
        .toLocaleLowerCase('cs')
        .normalize('NFD')
        .replace(/\p{M}/gu, '')
        .replace(/[·•,/\\|_\-\s]+/g, '');
}
