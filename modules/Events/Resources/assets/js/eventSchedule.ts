import type Alpine from '@alpinejs/csp';

/**
 * Show or hide the event date fields.
 * Inline Alpine expressions are not used: the CSP build cannot parse getters
 * or if-statements, and a failed x-if removed the inputs from the form so
 * save always failed with "fill in the start".
 */
export function registerEventSchedule(alpine: typeof Alpine): void {
    alpine.data('eventSchedule', () => ({
        slugs: [] as string[],
        category: '',
        hideDates: false,
        showDates: true,

        init() {
            const root = this.$el as HTMLElement;
            this.slugs = readSlugs(root.dataset.slugs || '');
            const input = root.querySelector<HTMLInputElement>('input[name="category"]');
            const fromInput = input?.value?.trim() || '';
            this.category = fromInput !== '' ? fromInput : (root.dataset.category || '').trim();
            this.apply();

            root.addEventListener('change', (event) => {
                const target = event.target;
                if (!(target instanceof HTMLInputElement) || target.name !== 'category') {
                    return;
                }
                this.category = target.value.trim();
                this.apply();
            });
        },

        apply() {
            const hide = this.category !== '' && this.slugs.includes(this.category);
            this.hideDates = hide;
            this.showDates = !hide;
            const root = this.$el as HTMLElement;
            const hint = root.querySelector<HTMLElement>('[data-schedule-hint]');
            const dates = root.querySelector<HTMLElement>('[data-schedule-dates]');
            if (hint) {
                hint.hidden = !hide;
            }
            if (dates) {
                dates.hidden = hide;
            }
        },
    }));
}

function readSlugs(raw: string): string[] {
    try {
        const parsed = JSON.parse(raw) as unknown;
        if (!Array.isArray(parsed)) {
            return [];
        }

        return parsed.map((item) => String(item));
    } catch {
        return [];
    }
}
