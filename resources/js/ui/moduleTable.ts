import type Alpine from '@alpinejs/csp';

export function registerModuleTable(alpine: typeof Alpine): void {
    alpine.data('moduleTable', () => ({
        query: '',
        status: '',
        empty: false,
        selected: [] as string[],
        pageIds: [] as string[],
        updateIds: [] as string[],
        bulkAction: '',

        init() {
            const el = this.$el as HTMLElement;
            try {
                this.pageIds = JSON.parse(el.dataset.pageIds || '[]') as string[];
            } catch {
                this.pageIds = [];
            }
            try {
                this.updateIds = JSON.parse(el.dataset.updateIds || '[]') as string[];
            } catch {
                this.updateIds = [];
            }

            this.apply();
            this.$watch('query', () => this.apply());
            this.$watch('status', () => this.apply());
            this.$watch('selected', () => this.syncSelectAll());
            this.$nextTick(() => this.syncSelectAll());
        },

        get selectedCount(): number {
            return this.selected.length;
        },

        get allSelected(): boolean {
            const ids = this.selectableIds();
            return ids.length > 0 && ids.every((id: string) => this.selected.includes(id));
        },

        get someSelected(): boolean {
            return this.selectedCount > 0 && !this.allSelected;
        },

        syncSelectAll() {
            const el = this.$refs.selectAll as HTMLInputElement | undefined;
            if (el) {
                el.indeterminate = this.someSelected;
            }
        },

        isSelected(id: string): boolean {
            return this.selected.includes(id);
        },

        /** Visible row ids; falls back to pageIds if the DOM query is empty. */
        selectableIds(): string[] {
            const visible = this.visibleIds();
            return visible.length > 0 ? visible : [...this.pageIds];
        },

        visibleIds(): string[] {
            const root = this.$el as HTMLElement;
            const ids: string[] = [];
            root.querySelectorAll<HTMLElement>('tbody tr[data-id]').forEach((row) => {
                if (!row.hidden && row.dataset.id) {
                    ids.push(row.dataset.id);
                }
            });
            return ids;
        },

        toggleId(id: string) {
            this.selected = this.isSelected(id)
                ? this.selected.filter((item: string) => item !== id)
                : [...this.selected, id];
        },

        toggleAll() {
            const ids = this.selectableIds();
            if (ids.length === 0) {
                return;
            }
            this.selected = this.allSelected
                ? this.selected.filter((id: string) => !ids.includes(id))
                : [...new Set([...this.selected, ...ids])];
        },

        clearSelection() {
            this.selected = [];
            this.bulkAction = '';
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
                const update = row.dataset.update === '1';
                const matchesQuery = q === '' || search.includes(q);
                let matchesStatus = true;
                if (status === 'on') {
                    matchesStatus = enabled;
                } else if (status === 'off') {
                    matchesStatus = !enabled;
                } else if (status === 'module') {
                    matchesStatus = (row.dataset.kind || 'module') === 'module';
                } else if (status === 'integration') {
                    matchesStatus = row.dataset.kind === 'integration';
                } else if (status === 'warn') {
                    matchesStatus = warn;
                } else if (status === 'update') {
                    matchesStatus = update;
                }

                const show = matchesQuery && matchesStatus;
                row.hidden = !show;
                if (show) {
                    visible += 1;
                }
            });

            this.empty = rows.length > 0 && visible === 0;
            this.syncSelectAll();
        },
    }));
}
