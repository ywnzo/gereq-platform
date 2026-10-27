import type Alpine from '@alpinejs/csp';

export function registerStorefrontOrigins(alpine: typeof Alpine): void {
    alpine.data('gereqStorefrontOrigins', () => ({
        mode: 'environment',
        init() {
            this.mode = (this.$el as HTMLElement).dataset.mode || 'environment';
        },
        updateMode(event: Event) {
            const input = event.target;
            if (input instanceof HTMLInputElement && input.name === 'origin_mode') {
                this.mode = input.value;
            }
        },
        get isCustom() { return this.mode === 'custom'; },
        get isEnvironment() { return this.mode === 'environment'; },
    }));
}
