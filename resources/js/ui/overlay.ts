import type Alpine from '@alpinejs/csp';

export function registerModal(alpine: typeof Alpine): void {
    alpine.data('gereqModal', () => ({
        open: false,
        _onKey: null as ((event: KeyboardEvent) => void) | null,

        init() {
            this._onKey = (event: KeyboardEvent) => {
                if (event.key === 'Escape' && this.open) {
                    this.close();
                }
            };
            document.addEventListener('keydown', this._onKey);
        },

        destroy() {
            if (this._onKey) {
                document.removeEventListener('keydown', this._onKey);
            }
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
            this.open = false;
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
