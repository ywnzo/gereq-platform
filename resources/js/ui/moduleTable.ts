import type Alpine from '@alpinejs/csp';

export function registerModuleTable(alpine: typeof Alpine): void {
    alpine.data('moduleTable', () => ({
        query: '',
        status: '',
        empty: false,

        init() {
            this.apply();
            this.$watch('query', () => this.apply());
            this.$watch('status', () => this.apply());
        },

        apply() {
            const root = this.$el as HTMLElement;
            const q = this.query.trim().toLowerCase();
            const status = this.status;
            const rows = root.querySelectorAll<HTMLElement>('tbody tr[data-search]');
            let visible = 0;

            rows.forEach((row) => {
                const search = (row.dataset.search || '').toLowerCase();
                const enabled = row.dataset.enabled === '1';
                const warn = row.dataset.warn === '1';
                const matchesQuery = q === '' || search.includes(q);
                let matchesStatus = true;
                if (status === 'on') {
                    matchesStatus = enabled;
                } else if (status === 'off') {
                    matchesStatus = !enabled;
                } else if (status === 'warn') {
                    matchesStatus = warn;
                }

                const show = matchesQuery && matchesStatus;
                row.hidden = !show;
                if (show) {
                    visible += 1;
                }
            });

            this.empty = rows.length > 0 && visible === 0;
        },
    }));
}
