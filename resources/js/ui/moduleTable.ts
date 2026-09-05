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
        selectedCount: 0,
        allSelected: false,
        showActions: true,

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

            const key = this.storageKey();
            const saved = localStorage.getItem(key);
            if (saved === '0' || saved === '1') {
                this.showActions = saved === '1';
            } else {
                const def = el.dataset.actionsDefault;
                this.showActions = def !== '0';
            }
            this.syncActionsClass();

            this.apply();
            this.syncSelectionState();
            this.$watch('query', () => this.apply());
            this.$watch('status', () => this.apply());
            this.$watch('selected', () => this.syncSelectionState());
        },

        storageKey(): string {
            const el = this.$el as HTMLElement;
            return 'gereq-table-actions:' + (el.dataset.tableId || el.id || location.pathname);
        },

        toggleActions() {
            this.showActions = !this.showActions;
            localStorage.setItem(this.storageKey(), this.showActions ? '1' : '0');
            this.syncActionsClass();
        },

        syncActionsClass() {
            const wrap = (this.$el as HTMLElement).querySelector('.table-wrap');
            if (!(wrap instanceof HTMLElement)) {
                return;
            }
            wrap.classList.toggle('is-actions-off', !this.showActions);
        },

        syncSelectionState() {
            this.selectedCount = this.selected.length;
            const visible = this.visibleIds();
            this.allSelected = visible.length > 0 && visible.every((id: string) => this.selected.includes(id));
            const el = this.$refs.selectAll as HTMLInputElement | undefined;
            if (el) {
                el.indeterminate = this.selectedCount > 0 && !this.allSelected;
            }
        },

        isSelected(id: string): boolean {
            return this.selected.includes(id);
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
            const visible = this.visibleIds();
            this.selected = this.allSelected
                ? this.selected.filter((id: string) => !visible.includes(id))
                : [...new Set([...this.selected, ...visible])];
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
            this.syncSelectionState();
        },
    }));
}
