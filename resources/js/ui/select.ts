import type Alpine from '@alpinejs/csp';

export function registerSelect(alpine: typeof Alpine): void {
    alpine.data('gereqSelect', () => ({
        open: false,
        label: '',

        init() {
            const root = this.$el as HTMLElement;
            const selected = root.querySelector('[data-selected="1"]') as HTMLElement | null;
            this.label = selected?.dataset.label || root.dataset.placeholder || 'Vyberte';
            const onPointer = (event: PointerEvent) => {
                if (!root.contains(event.target as Node)) {
                    this.close();
                }
            };
            document.addEventListener('pointerdown', onPointer);
            this._onPointer = onPointer;
        },

        _onPointer: null as ((event: PointerEvent) => void) | null,

        destroy() {
            if (this._onPointer) {
                document.removeEventListener('pointerdown', this._onPointer);
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

        toggle() {
            this.open = !this.open;
        },

        close() {
            this.open = false;
        },

        choose(event: Event) {
            const option = event.currentTarget as HTMLElement;
            const input = this.$refs.value as HTMLInputElement | undefined;
            if (!input) {
                return;
            }
            input.value = option.dataset.value || '';
            this.label = option.dataset.label || '';
            this.open = false;
            rootUnselect(this.$el as HTMLElement);
            option.classList.add('is-selected');
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
        },
    }));
}

function rootUnselect(root: HTMLElement): void {
    root.querySelectorAll('.is-selected').forEach((node) => node.classList.remove('is-selected'));
}
