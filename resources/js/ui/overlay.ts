import type Alpine from '@alpinejs/csp';

export function registerModal(alpine: typeof Alpine): void {
    alpine.data('gereqModal', () => ({
        open: false,
        _root: null as HTMLElement | null,
        _onKey: null as ((event: KeyboardEvent) => void) | null,
        _onModalLink: null as ((event: MouseEvent) => void) | null,

        init() {
            this._root = this.$el as HTMLElement;
            this._onKey = (event: KeyboardEvent) => {
                if (event.key === 'Escape' && this.open) {
                    this.close();
                }
            };
            document.addEventListener('keydown', this._onKey);
            const hash = this._root.dataset.modalHash;
            if (hash) {
                this._onModalLink = (event: MouseEvent) => {
                    const target = event.target;
                    const link = target instanceof Element ? target.closest('a[href]') : null;
                    if (link?.getAttribute('href') === `#${hash}`) {
                        event.preventDefault();
                        this.show();
                    }
                };
                document.addEventListener('click', this._onModalLink);
                const modalParam = new URLSearchParams(window.location.search).get('modal');
                if (window.location.hash === `#${hash}` || modalParam === hash) {
                    this.show();
                    const url = new URL(window.location.href);
                    if (modalParam === hash) url.searchParams.delete('modal');
                    if (url.hash === `#${hash}`) url.hash = '';
                    history.replaceState(null, '', url);
                }
            }
        },

        destroy() {
            if (this._onKey) {
                document.removeEventListener('keydown', this._onKey);
            }
            if (this._onModalLink) document.removeEventListener('click', this._onModalLink);
        },

        get panelClass(): string {
            return this.open ? 'is-open' : '';
        },

        get backdropClass(): string {
            return this.open ? 'is-open' : '';
        },

        show() {
            this.open = true;
            this.$nextTick(() => (this.$refs.close as HTMLButtonElement | undefined)?.focus());
        },

        close() {
            const eventName = this._root?.dataset.modalCloseEvent;
            if (this.open && eventName && !window.dispatchEvent(new Event(eventName, { cancelable: true }))) return;
            this.open = false;
            this._root?.querySelector('[data-modal-error-summary]')?.remove();
            const formId = this._root?.dataset.resetForm;
            const form = formId ? document.getElementById(formId) : null;
            if (form instanceof HTMLFormElement) {
                form.reset();
                form.querySelectorAll<HTMLInputElement>('input[type="checkbox"][data-reset-checked]').forEach((field) => {
                    field.checked = field.dataset.resetChecked === '1';
                    field.dispatchEvent(new Event('change', { bubbles: true }));
                });
                if (form.dataset.resetValues) {
                    const values = JSON.parse(form.dataset.resetValues) as Record<string, string>;
                    for (const [name, value] of Object.entries(values)) {
                        const field = form.elements.namedItem(name);
                        if (field instanceof HTMLInputElement || field instanceof HTMLSelectElement || field instanceof HTMLTextAreaElement) {
                            field.value = value;
                            field.dispatchEvent(new Event('input', { bubbles: true }));
                            field.dispatchEvent(new Event('change', { bubbles: true }));
                            field.removeAttribute('aria-invalid');
                        }
                    }
                    form.querySelectorAll('.g-field.is-invalid').forEach((field) => field.classList.remove('is-invalid'));
                    form.querySelectorAll('.g-error:not([data-native-error])').forEach((error) => error.remove());
                    form.querySelectorAll<HTMLElement>('[data-native-error]').forEach((error) => { error.hidden = true; });
                }
            }
        },
    }));
}

export function registerPopover(alpine: typeof Alpine): void {
    alpine.data('gereqPopover', () => ({
        open: false,
        _onPointer: null as ((event: PointerEvent) => void) | null,

        init() {
            const root = this.$el as HTMLElement;
            this._onPointer = (event: PointerEvent) => {
                if (!root.contains(event.target as Node)) {
                    this.close();
                }
            };
            document.addEventListener('pointerdown', this._onPointer);
        },

        destroy() {
            if (this._onPointer) {
                document.removeEventListener('pointerdown', this._onPointer);
            }
        },

        get panelClass(): string {
            return this.open ? 'is-open' : '';
        },

        toggle() {
            this.open = !this.open;
        },

        close() {
            this.open = false;
        },
    }));
}
