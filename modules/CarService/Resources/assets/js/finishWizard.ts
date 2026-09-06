import type Alpine from '@alpinejs/csp';

export function registerCarserviceFinishWizard(alpine: typeof Alpine): void {
    alpine.data('carserviceFinishWizard', () => ({
        open: false,
        step: '',
        steps: [] as string[],
        _onKey: null as ((event: KeyboardEvent) => void) | null,

        init() {
            const el = this.$el as HTMLElement;
            try {
                this.steps = JSON.parse(el.dataset.steps || '[]') as string[];
            } catch {
                this.steps = [];
            }
            const initial = el.dataset.step || this.steps[0] || '';
            this.step = this.steps.includes(initial) ? initial : (this.steps[0] || '');
            if (el.dataset.open === '1') {
                this.open = true;
            }
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

        get stepIndex(): number {
            const i = this.steps.indexOf(this.step);
            return i < 0 ? 0 : i;
        },

        get panelClass(): string {
            return this.open ? 'is-open' : '';
        },

        get backdropClass(): string {
            return this.open ? 'is-open' : '';
        },

        show() {
            if (!this.step && this.steps.length > 0) {
                this.step = this.steps[0];
            }
            this.open = true;
            this.$nextTick(() => (this.$refs.close as HTMLButtonElement | undefined)?.focus());
        },

        close() {
            this.open = false;
        },
    }));
}
