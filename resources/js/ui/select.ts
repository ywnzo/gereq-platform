import type Alpine from '@alpinejs/csp';

type GereqSelectCtx = {
    open: boolean;
    label: string;
    tone: string;
    query: string;
    searchable: boolean;
    multiple: boolean;
    clearable: boolean;
    activeIndex: number;
    $el: HTMLElement;
    $refs: { value?: HTMLInputElement; search?: HTMLInputElement };
    applyFilter: () => void;
    close: () => void;
    applyOption: (option: HTMLElement) => void;
    syncMultiUi: () => void;
    syncValidateInput: () => void;
    syncNativeOptions: () => void;
    syncNativeRoot: () => void;
    clearValue: () => void;
    removeValue: (value: string) => void;
    moveActive: (delta: number) => void;
    activateOption: () => void;
    visibleOptions: () => HTMLElement[];
    _onPointer: ((event: PointerEvent) => void) | null;
    _onRootClick: ((event: MouseEvent) => void) | null;
    _onRootKeydown: ((event: KeyboardEvent) => void) | null;
    _onSearchInput: ((event: Event) => void) | null;
    _onSearchKeydown: ((event: KeyboardEvent) => void) | null;
    _nativeViewportEvents: AbortController | null;
    _nativeObserver: MutationObserver | null;
    _onNativeChange: (() => void) | null;
    _onNativeInvalid: ((event: Event) => void) | null;
    _onNativeReset: (() => void) | null;
    _onNativeFocus: (() => void) | null;
    _nativeForm: HTMLFormElement | null;
};

export function registerSelect(alpine: typeof Alpine): void {
    alpine.data('gereqSelect', () => ({
        open: false,
        label: '',
        tone: '',
        query: '',
        searchable: false,
        multiple: false,
        clearable: false,
        activeIndex: -1,
        _onPointer: null as ((event: PointerEvent) => void) | null,
        _onRootClick: null as ((event: MouseEvent) => void) | null,
        _onRootKeydown: null as ((event: KeyboardEvent) => void) | null,
        _onSearchInput: null as ((event: Event) => void) | null,
        _onSearchKeydown: null as ((event: KeyboardEvent) => void) | null,
        _nativeViewportEvents: null as AbortController | null,
        _nativeObserver: null as MutationObserver | null,
        _onNativeChange: null as (() => void) | null,
        _onNativeInvalid: null as ((event: Event) => void) | null,
        _onNativeReset: null as (() => void) | null,
        _onNativeFocus: null as (() => void) | null,
        _nativeForm: null as HTMLFormElement | null,

        init(this: GereqSelectCtx) {
            const root = this.$el;
            this.searchable = root.dataset.searchable === '1' || root.dataset.searchable === 'true';
            this.multiple = root.dataset.multiple === '1' || root.dataset.multiple === 'true';
            this.clearable = root.dataset.clearable === '1' || root.dataset.clearable === 'true';

            const native = root.querySelector<HTMLSelectElement>('select[data-select-native]');
            if (native) {
                this._nativeViewportEvents = new AbortController();
                const signal = this._nativeViewportEvents.signal;
                // Keep top-layer menus anchored when their surrounding container scrolls.
                document.addEventListener('scroll', (event) => {
                    const list = root.querySelector('.g-select__list');
                    if (!(event.target instanceof Node) || !list?.contains(event.target)) this.syncNativeRoot();
                }, { capture: true, signal });
                window.addEventListener('resize', () => this.close(), { signal });
                this.syncNativeOptions();
                this._onNativeChange = () => {
                    this.syncNativeOptions();
                    // Existing change handlers may reset the source value synchronously.
                    queueMicrotask(() => {
                        if (root.isConnected) this.syncNativeOptions();
                    });
                };
                native.addEventListener('change', this._onNativeChange);
                this._onNativeInvalid = (event: Event) => {
                    event.preventDefault();
                    this.open = true;
                    const trigger = root.querySelector<HTMLButtonElement>('.g-select__trigger');
                    trigger?.setAttribute('aria-invalid', 'true');
                    trigger?.focus();
                    const error = root.querySelector<HTMLElement>('[data-native-error]');
                    if (error) {
                        error.textContent = native.validationMessage;
                        error.hidden = false;
                    }
                };
                native.addEventListener('invalid', this._onNativeInvalid);
                this._onNativeFocus = () => root.querySelector<HTMLButtonElement>('.g-select__trigger')?.focus();
                native.addEventListener('focus', this._onNativeFocus);
                this._nativeForm = native.form;
                // Reset applies native default values after dispatching the reset event.
                this._onNativeReset = () => setTimeout(() => {
                    if (!root.isConnected) return;
                    this.close();
                    const error = root.querySelector<HTMLElement>('[data-native-error]');
                    if (error) {
                        error.hidden = true;
                        error.textContent = '';
                    }
                    root.querySelector('.g-select__trigger')?.removeAttribute('aria-invalid');
                    this.syncNativeOptions();
                }, 0);
                this._nativeForm?.addEventListener('reset', this._onNativeReset);
                this._nativeObserver = new MutationObserver((records) => {
                    if (records.some((record) => native.contains(record.target)
                        || record.target === native
                        || (record.target === root && record.attributeName === 'data-native-value'))) {
                        this.syncNativeOptions();
                    }
                });
                this._nativeObserver.observe(root, { attributes: true, childList: true, characterData: true, subtree: true });
            }

            if (this.multiple) {
                this.syncMultiUi();
            } else {
                const selected = root.querySelector('[data-selected="1"]') as HTMLElement | null;
                this.label = selected?.dataset.label || root.dataset.placeholder || 'Vyberte';
                this.tone = selected?.dataset.tone || '';
                syncSelectIcon(root, selected);
                syncClearVisibility(root, !!selected?.dataset.value);
            }

            const onRootClick = (event: MouseEvent) => {
                const target = event.target as HTMLElement | null;
                if (!target) {
                    return;
                }

                const clearBtn = target.closest('[data-select-clear]');
                if (clearBtn && root.contains(clearBtn)) {
                    event.preventDefault();
                    event.stopPropagation();
                    this.clearValue();
                    return;
                }

                const chipRemove = target.closest('[data-chip-remove]') as HTMLElement | null;
                if (chipRemove && root.contains(chipRemove)) {
                    event.preventDefault();
                    event.stopPropagation();
                    this.removeValue(chipRemove.getAttribute('data-chip-remove') || '');
                    return;
                }

                const trigger = target.closest('.g-select__trigger');
                if (trigger && root.contains(trigger)) {
                    event.preventDefault();
                    this.open = !this.open;
                    this.activeIndex = -1;
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
                    if (option.dataset.empty === '1' || option.dataset.disabled === '1' || option.hidden || option.classList.contains('is-filtered-out')) {
                        return;
                    }
                    this.applyOption(option);
                }
            };
            root.addEventListener('click', onRootClick);
            this._onRootClick = onRootClick;

            const onRootKeydown = (event: KeyboardEvent) => {
                const target = event.target as HTMLElement | null;
                if (target?.closest('[data-chip-remove]') && (event.key === 'Enter' || event.key === ' ')) {
                    event.preventDefault();
                    this.removeValue(target.closest('[data-chip-remove]')?.getAttribute('data-chip-remove') || '');
                    return;
                }
                if (target?.closest('[data-select-clear]') && (event.key === 'Enter' || event.key === ' ')) {
                    event.preventDefault();
                    this.clearValue();
                    return;
                }

                if (!this.open) {
                    if (event.key === 'ArrowDown' || event.key === 'ArrowUp' || event.key === 'Enter' || event.key === ' ') {
                        const inTrigger = target?.closest('.g-select__trigger');
                        if (inTrigger && root.contains(inTrigger)) {
                            event.preventDefault();
                            this.open = true;
                            this.activeIndex = -1;
                            this.moveActive(1);
                        }
                    }
                    return;
                }

                if (event.key === 'Escape') {
                    event.preventDefault();
                    this.close();
                    return;
                }
                if (event.key === 'ArrowDown') {
                    event.preventDefault();
                    this.moveActive(1);
                    return;
                }
                if (event.key === 'ArrowUp') {
                    event.preventDefault();
                    this.moveActive(-1);
                    return;
                }
                if (event.key === 'Enter') {
                    event.preventDefault();
                    this.activateOption();
                }
            };
            root.addEventListener('keydown', onRootKeydown);
            this._onRootKeydown = onRootKeydown;

            const search = root.querySelector<HTMLInputElement>('.g-select__search-input');
            if (search) {
                const onSearchInput = (event: Event) => {
                    const t = event.target as HTMLInputElement;
                    this.query = t.value;
                    this.applyFilter();
                    this.activeIndex = -1;
                };
                const onSearchKeydown = (event: KeyboardEvent) => {
                    if (event.key === 'Escape') {
                        event.preventDefault();
                        this.close();
                    }
                    if (event.key === 'ArrowDown' || event.key === 'ArrowUp' || event.key === 'Enter') {
                        onRootKeydown(event);
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
            this.syncValidateInput();

            const onPointer = (event: PointerEvent) => {
                if (!root.contains(event.target as Node)) {
                    this.close();
                }
            };
            document.addEventListener('pointerdown', onPointer);
            this._onPointer = onPointer;
        },

        destroy(this: GereqSelectCtx) {
            this._nativeViewportEvents?.abort();
            this.$el.querySelector<HTMLElement>('.g-select__list[popover]')?.hidePopover();
            this._nativeObserver?.disconnect();
            const native = this.$el.querySelector<HTMLSelectElement>('select[data-select-native]');
            if (native && this._onNativeChange) {
                native.removeEventListener('change', this._onNativeChange);
            }
            if (native && this._onNativeInvalid) {
                native.removeEventListener('invalid', this._onNativeInvalid);
            }
            if (native && this._onNativeFocus) {
                native.removeEventListener('focus', this._onNativeFocus);
            }
            if (this._nativeForm && this._onNativeReset) {
                this._nativeForm.removeEventListener('reset', this._onNativeReset);
            }
            if (this._onPointer) {
                document.removeEventListener('pointerdown', this._onPointer);
            }
            if (this._onRootClick) {
                this.$el.removeEventListener('click', this._onRootClick);
            }
            if (this._onRootKeydown) {
                this.$el.removeEventListener('keydown', this._onRootKeydown);
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

        get rootClass(): string {
            return this.open ? 'is-open' : '';
        },

        get listClass(): string {
            return this.open ? 'is-open' : '';
        },

        get triggerClass(): string {
            const classes = [];
            if (this.open) {
                classes.push('is-open');
            }
            if (this.multiple && this.$el.querySelectorAll('[data-select-values] input[name]').length > 0) {
                classes.push('has-value');
            }
            return classes.join(' ');
        },

        get expandedAttr(): string {
            return this.open ? 'true' : 'false';
        },

        get dotClass(): string {
            return this.tone ? `is-visible g-select__dot--${this.tone}` : '';
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
            this.activeIndex = -1;
            const input = this.$el.querySelector<HTMLInputElement>('.g-select__search-input');
            if (input) {
                input.value = '';
            }
            this.$el.querySelectorAll('.g-select__option.is-active').forEach((el) => el.classList.remove('is-active'));
            this.applyFilter();
        },

        visibleOptions(this: GereqSelectCtx): HTMLElement[] {
            return Array.from(
                this.$el.querySelectorAll<HTMLElement>('.g-select__option:not([data-empty="1"]):not(.is-filtered-out)'),
            ).filter((opt) => !opt.hidden && opt.dataset.disabled !== '1');
        },

        moveActive(this: GereqSelectCtx, delta: number) {
            const options = this.visibleOptions();
            if (options.length === 0) {
                this.activeIndex = -1;
                return;
            }
            let next = this.activeIndex + delta;
            if (this.activeIndex < 0) {
                next = delta > 0 ? 0 : options.length - 1;
            }
            if (next < 0) {
                next = options.length - 1;
            }
            if (next >= options.length) {
                next = 0;
            }
            this.activeIndex = next;
            options.forEach((opt, i) => opt.classList.toggle('is-active', i === next));
            options[next]?.scrollIntoView({ block: 'nearest' });
        },

        activateOption(this: GereqSelectCtx) {
            const options = this.visibleOptions();
            const opt = options[this.activeIndex];
            if (opt) {
                this.applyOption(opt);
            }
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
                if ((opt.dataset.value || '') === '' && q !== '' && !this.multiple) {
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

        clearValue(this: GereqSelectCtx) {
            const root = this.$el;
            if (this.multiple) {
                const box = root.querySelector('[data-select-values]');
                box?.querySelectorAll('input[name]').forEach((el) => el.remove());
                rootUnselect(root);
                this.syncMultiUi();
                this.syncValidateInput();
                dispatchNativeChange(root);
                maybeAutosubmit(root, true);
                return;
            }
            const input = root.querySelector<HTMLInputElement>('input.g-select__native:not(.g-select__native--validate)');
            if (!input) {
                return;
            }
            const changed = input.value !== '';
            input.value = '';
            input.setCustomValidity('');
            this.label = root.dataset.placeholder || 'Vyberte';
            this.tone = '';
            syncSelectIcon(root, null);
            rootUnselect(root);
            syncClearVisibility(root, false);
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
            maybeAutosubmit(root, changed);
        },

        removeValue(this: GereqSelectCtx, value: string) {
            if (!this.multiple || value === '') {
                return;
            }
            const root = this.$el;
            const opt = root.querySelector(`.g-select__option[data-value="${CSS.escape(value)}"]`) as HTMLElement | null;
            if (opt?.classList.contains('is-selected')) {
                this.applyOption(opt);
                return;
            }
            root.querySelector(`[data-select-values] input[name][value="${CSS.escape(value)}"]`)?.remove();
            this.syncMultiUi();
            this.syncValidateInput();
            dispatchNativeChange(root);
            maybeAutosubmit(root, true);
        },

        syncNativeRoot(this: GereqSelectCtx) {
            const root = this.$el;
            root.classList.toggle('is-open', this.open);
            const list = root.querySelector<HTMLElement>('.g-select__list[popover]');
            if (!list) return;
            if (!this.open) {
                list.hidePopover();
                return;
            }
            queueMicrotask(() => {
                if (!this.open || !root.isConnected) return;
                const trigger = root.querySelector<HTMLElement>('.g-select__trigger');
                if (!trigger) return;
                const rect = trigger.getBoundingClientRect();
                const gap = 6;
                const margin = 8;
                const below = Math.max(0, window.innerHeight - rect.bottom - gap - margin);
                const above = Math.max(0, rect.top - gap - margin);
                const width = Math.min(Math.max(rect.width, 160), Math.max(0, window.innerWidth - margin * 2));
                list.style.width = `${width}px`;
                list.style.left = `${Math.max(margin, Math.min(rect.left, window.innerWidth - width - margin))}px`;
                list.style.maxHeight = `${Math.min(288, Math.max(above, below))}px`;
                list.showPopover();
                const height = list.getBoundingClientRect().height;
                const upwards = below < height && above > below;
                list.style.maxHeight = `${Math.min(288, upwards ? above : below)}px`;
                list.style.top = `${upwards ? rect.top - gap - Math.min(height, above) : rect.bottom + gap}px`;
            });
        },

        syncNativeOptions(this: GereqSelectCtx) {
            const root = this.$el;
            const native = root.querySelector<HTMLSelectElement>('select[data-select-native]');
            const list = root.querySelector<HTMLElement>('.g-select__options');
            if (!native || !list) {
                return;
            }
            const fragment = document.createDocumentFragment();
            for (const option of native.options) {
                const row = document.createElement('li');
                row.className = 'g-select__option';
                row.dataset.value = option.value;
                row.dataset.label = option.label;
                row.dataset.disabled = option.disabled || (option.parentElement instanceof HTMLOptGroupElement && option.parentElement.disabled) ? '1' : '0';
                row.hidden = option.hidden;
                row.setAttribute('role', 'option');
                row.setAttribute('aria-selected', String(option.selected));
                row.setAttribute('aria-disabled', row.dataset.disabled === '1' ? 'true' : 'false');
                if (option.selected) {
                    row.classList.add('is-selected');
                    row.dataset.selected = '1';
                }
                const text = document.createElement('span');
                text.className = 'g-select__option-text';
                text.textContent = option.label;
                row.appendChild(text);
                const tick = document.createElement('span');
                tick.className = 'g-select__check';
                tick.setAttribute('aria-hidden', 'true');
                tick.textContent = '✓';
                row.appendChild(tick);
                fragment.appendChild(row);
            }
            list.replaceChildren(fragment);
            this.label = native.selectedOptions[0]?.label || root.dataset.placeholder || '';
            const trigger = root.querySelector<HTMLButtonElement>('.g-select__trigger');
            if (trigger) {
                trigger.disabled = native.disabled;
                const fieldLabel = Array.from(native.labels || []).map((label) => {
                    const copy = label.cloneNode(true) as HTMLElement;
                    copy.querySelectorAll('.g-select, select').forEach((control) => control.remove());
                    return copy.textContent?.trim() || '';
                }).filter(Boolean).join(' ');
                for (const attribute of ['aria-labelledby', 'aria-describedby', 'aria-required']) {
                    const value = native.getAttribute(attribute);
                    if (value !== null) trigger.setAttribute(attribute, value);
                    else trigger.removeAttribute(attribute);
                }
                if (native.required) trigger.setAttribute('aria-required', 'true');
                if (native.hasAttribute('aria-invalid')) {
                    trigger.setAttribute('aria-invalid', native.getAttribute('aria-invalid') || 'false');
                }
                trigger.setAttribute('aria-label', native.getAttribute('aria-label') || fieldLabel || native.title || native.name || root.dataset.placeholder || '');
                if (native.validity.valid) {
                    if (native.hasAttribute('aria-invalid')) {
                        trigger.setAttribute('aria-invalid', native.getAttribute('aria-invalid') || 'false');
                    } else {
                        trigger.removeAttribute('aria-invalid');
                    }
                    const error = root.querySelector<HTMLElement>('[data-native-error]');
                    if (error) {
                        error.hidden = true;
                        error.textContent = '';
                    }
                }
            }
        },

        syncValidateInput(this: GereqSelectCtx) {
            const root = this.$el;
            const validate = root.querySelector<HTMLInputElement>('.g-select__native--validate');
            if (!validate) {
                return;
            }
            const count = root.querySelectorAll('[data-select-values] input[name]').length;
            validate.value = count > 0 ? '1' : '';
            validate.setCustomValidity('');
        },

        syncMultiUi(this: GereqSelectCtx) {
            const root = this.$el;
            const chips = root.querySelector<HTMLElement>('[data-select-chips]');
            const placeholder = root.querySelector<HTMLElement>('[data-select-placeholder]');
            if (!chips) {
                return;
            }

            const selected = Array.from(
                root.querySelectorAll<HTMLInputElement>('[data-select-values] input[name]'),
            ).map((input) => input.value);

            chips.innerHTML = '';
            selected.forEach((value) => {
                const opt = root.querySelector(`.g-select__option[data-value="${CSS.escape(value)}"]`) as HTMLElement | null;
                const label = opt?.dataset.label || value;
                const chip = document.createElement('span');
                chip.className = 'g-select__chip';
                chip.dataset.value = value;

                const text = document.createElement('span');
                text.className = 'g-select__chip-label';
                text.textContent = label;

                const remove = document.createElement('span');
                remove.className = 'g-select__chip-remove';
                remove.setAttribute('data-chip-remove', value);
                remove.setAttribute('role', 'button');
                remove.setAttribute('tabindex', '0');
                remove.setAttribute('aria-label', root.dataset.removeLabel || 'Remove');
                const closeTpl =
                    root.querySelector('[data-close-icon]') ||
                    root.querySelector('[data-select-clear]');
                if (closeTpl) {
                    remove.innerHTML = closeTpl.innerHTML;
                } else {
                    remove.textContent = '×';
                }

                chip.appendChild(text);
                chip.appendChild(remove);
                chips.appendChild(chip);
            });

            placeholder?.classList.toggle('is-hidden', selected.length > 0);
            syncClearVisibility(root, selected.length > 0);

            root.querySelectorAll<HTMLElement>('.g-select__option:not([data-empty="1"])').forEach((opt) => {
                const on = selected.includes(opt.dataset.value || '');
                opt.classList.toggle('is-selected', on);
                opt.dataset.selected = on ? '1' : '';
                opt.setAttribute('aria-selected', on ? 'true' : 'false');
            });
        },

        applyOption(this: GereqSelectCtx, option: HTMLElement) {
            const root = this.$el as HTMLElement;

            if (this.multiple) {
                const value = option.dataset.value || '';
                const box = root.querySelector('[data-select-values]');
                if (!box) {
                    return;
                }
                const existing = box.querySelector<HTMLInputElement>(`input[name][value="${CSS.escape(value)}"]`);
                const changed = true;
                if (existing) {
                    existing.remove();
                } else {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.className = 'g-select__native';
                    input.name = root.dataset.inputName || '';
                    input.value = value;
                    input.autocomplete = 'off';
                    const validate = box.querySelector('.g-select__native--validate');
                    if (validate) {
                        box.insertBefore(input, validate);
                    } else {
                        box.appendChild(input);
                    }
                }
                this.syncMultiUi();
                this.syncValidateInput();
                dispatchNativeChange(root);
                // Keep the menu open for multi-select.
                maybeAutosubmit(root, changed);
                return;
            }

            const input = root.querySelector<HTMLInputElement | HTMLSelectElement>('select[data-select-native], input.g-select__native:not(.g-select__native--validate), input[type="hidden"]');
            if (!input) {
                return;
            }
            const next = option.dataset.value || '';
            const changed = input.value !== next;
            input.value = next;
            input.setCustomValidity('');
            this.label = option.dataset.label || '';
            this.tone = option.dataset.tone || '';
            syncSelectIcon(root, option);
            this.open = false;
            this.query = '';
            this.activeIndex = -1;
            const search = root.querySelector<HTMLInputElement>('.g-select__search-input');
            if (search) {
                search.value = '';
            }
            this.applyFilter();
            rootUnselect(root);
            option.classList.add('is-selected');
            option.dataset.selected = '1';
            option.setAttribute('aria-selected', 'true');
            syncClearVisibility(root, next !== '');
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
            maybeAutosubmit(root, changed);
        },
    }));
}

function rootUnselect(root: HTMLElement): void {
    root.querySelectorAll('.g-select__option.is-selected').forEach((node) => {
        node.classList.remove('is-selected');
        if (node instanceof HTMLElement) {
            delete node.dataset.selected;
            node.setAttribute('aria-selected', 'false');
        }
    });
}

function syncSelectIcon(root: HTMLElement, option: HTMLElement | null): void {
    const slot = root.querySelector<HTMLElement>('.g-select__icon-slot');
    const dot = root.querySelector<HTMLElement>('.g-select__dot');
    if (!slot) {
        return;
    }
    const iconEl = option?.querySelector('.g-select__icon');
    if (iconEl) {
        slot.innerHTML = iconEl.innerHTML;
        slot.hidden = false;
        if (dot) {
            dot.hidden = true;
        }
        return;
    }
    slot.innerHTML = '';
    slot.hidden = true;
    if (dot) {
        dot.hidden = false;
    }
}

function syncClearVisibility(root: HTMLElement, hasValue: boolean): void {
    root.querySelectorAll('[data-select-clear]').forEach((el) => {
        el.classList.toggle('is-hidden', !hasValue);
    });
}

function dispatchNativeChange(root: HTMLElement): void {
    const probe =
        root.querySelector<HTMLInputElement>('.g-select__native--validate') ||
        root.querySelector<HTMLInputElement>('input.g-select__native');
    probe?.dispatchEvent(new Event('input', { bubbles: true }));
    probe?.dispatchEvent(new Event('change', { bubbles: true }));
}

function maybeAutosubmit(root: HTMLElement, changed: boolean): void {
    if (root.dataset.autosubmit !== '1' || !changed) {
        return;
    }
    const form = root.closest('form');
    if (!form) {
        return;
    }
    queueMicrotask(() => {
        window.gereq?.pageProgress?.start?.();
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
        } else {
            form.submit();
        }
    });
}

/** "66KW" ≈ "66 kW"; keep dots in 1.6 */
function normalizeSearch(value: string): string {
    return value
        .toLocaleLowerCase('cs')
        .normalize('NFD')
        .replace(/\p{M}/gu, '')
        .replace(/[·•,/\\|_\-\s]+/g, '');
}
