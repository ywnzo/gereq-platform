import type Alpine from '@alpinejs/csp';
import { flushUiPreferences, modulesExpanded, patchUiPreferences } from './uiPreferences';

const MODULE_TABLE_STATE_KEY = 'gereq.admin.modules.table';

type ModuleTableSavedState = {
    query?: string;
    status?: string;
    expanded?: string[];
    collapsedFiltered?: string[];
    toast?: string;
    toastTone?: 'success' | 'warning' | 'error';
};

export function registerModuleTable(alpine: typeof Alpine): void {
    alpine.data('moduleTable', () => ({
        query: '',
        status: '',
        empty: false,
        selected: [] as string[],
        pageIds: [] as string[],
        updateIds: [] as string[],
        /** Parent module ids whose submodule rows are expanded (default: collapsed). */
        expanded: [] as string[],
        collapsedFiltered: [] as string[],
        bulkAction: '',
        busy: false,

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

            this.restoreSavedState();
            if (this.expanded.length === 0) {
                this.expanded = modulesExpanded();
            }

            // Native delegation — Alpine CSP @click on the icon toggle was unreliable.
            el.addEventListener('click', (event: Event) => {
                const target = event.target;
                if (!(target instanceof Element)) {
                    return;
                }
                const btn = target.closest('[data-module-children-toggle]') as HTMLElement | null;
                if (!btn || !el.contains(btn)) {
                    return;
                }
                event.preventDefault();
                const id = btn.dataset.parentId || '';
                if (id === '') {
                    return;
                }
                if (this.isFiltering) {
                    this.collapsedFiltered = this.isExpanded(id)
                        ? [...this.collapsedFiltered, id]
                        : this.collapsedFiltered.filter((item: string) => item !== id);
                    this.apply();
                    return;
                }
                this.expanded = this.isExpanded(id)
                    ? this.expanded.filter((item: string) => item !== id)
                    : [...this.expanded, id];
                patchUiPreferences({ modules_expanded: [...this.expanded] });
            });

            this.apply();
            this.$watch('query', () => this.resetFilteredExpansion());
            this.$watch('status', () => this.resetFilteredExpansion());
            this.$watch('expanded', () => this.apply());
            this.$watch('selected', () => this.syncSelectAll());
            this.$nextTick(() => this.syncSelectAll());

            el.addEventListener('submit', (event: Event) => {
                const form = event.target;
                if (!(form instanceof HTMLFormElement) || !el.contains(form)) {
                    return;
                }
                const action = form.dataset.moduleAction || '';
                if (action === 'enable' || action === 'pause' || action === 'disable') {
                    // Confirm dialog (capture) may preventDefault first; after accept it resubmits.
                    if (form.dataset.confirm && form.dataset.confirmAccepted !== '1' && event.defaultPrevented) {
                        return;
                    }
                    event.preventDefault();
                    void this.submitModuleAction(form);
                    return;
                }
                queueMicrotask(() => {
                    if (!event.defaultPrevented) {
                        this.markBusy();
                    }
                });
            });
        },

        markBusy() {
            this.busy = true;
        },

        async submitModuleAction(form: HTMLFormElement) {
            const root = this.$el as HTMLElement;
            if (form.dataset.busy === '1') {
                return;
            }
            form.dataset.busy = '1';
            form.classList.add('is-busy');
            const csrf =
                (window as Window & { __GEREQ_CSRF__?: string }).__GEREQ_CSRF__ ||
                (form.querySelector('input[name="_csrf"]') as HTMLInputElement | null)?.value ||
                '';
            const body = new URLSearchParams();
            new FormData(form).forEach((value, key) => {
                if (typeof value === 'string') {
                    body.set(key, value);
                }
            });
            if (csrf && !body.has('_csrf')) {
                body.set('_csrf', csrf);
            }

            try {
                const res = await fetch(form.action, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrf,
                        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                    },
                    body: body.toString(),
                });
                const payload = (await res.json()) as {
                    data?: {
                        ok?: boolean;
                        message?: string;
                        error?: string | null;
                        modules?: Array<{
                            id: string;
                            enabled: boolean;
                            installed: boolean;
                            parent_id: string | null;
                            parent_enabled: boolean;
                        }>;
                    };
                    error?: { message?: string };
                };
                if (!res.ok) {
                    throw new Error(payload.error?.message || root.dataset.toastError || 'Error');
                }
                const data = payload.data;
                // Module boot/menu/routes apply only on the next request — keep filters via sessionStorage.
                this.persistStateAndReload({
                    toast: data?.error || data?.message || '',
                    toastTone: data?.error ? (data?.message ? 'warning' : 'error') : 'success',
                });
                return;
            } catch (err) {
                const msg =
                    err instanceof Error && err.message
                        ? err.message
                        : root.dataset.toastError || 'Error';
                window.gereq?.toast?.(msg, 'error');
            } finally {
                delete form.dataset.busy;
                form.classList.remove('is-busy');
            }
        },

        persistStateAndReload(extra: Pick<ModuleTableSavedState, 'toast' | 'toastTone'> = {}) {
            const state: ModuleTableSavedState = {
                query: this.query,
                status: this.status,
                expanded: [...this.expanded],
                collapsedFiltered: [...this.collapsedFiltered],
                toast: extra.toast,
                toastTone: extra.toastTone,
            };
            patchUiPreferences({ modules_expanded: [...this.expanded] }, 0);
            try {
                sessionStorage.setItem(MODULE_TABLE_STATE_KEY, JSON.stringify(state));
            } catch {
                /* ignore quota / private mode */
            }
            this.busy = true;
            void flushUiPreferences().finally(() => {
                window.location.reload();
            });
        },

        restoreSavedState() {
            let raw: string | null = null;
            try {
                raw = sessionStorage.getItem(MODULE_TABLE_STATE_KEY);
                if (raw) {
                    sessionStorage.removeItem(MODULE_TABLE_STATE_KEY);
                }
            } catch {
                return;
            }
            if (!raw) {
                return;
            }
            try {
                const state = JSON.parse(raw) as ModuleTableSavedState;
                if (typeof state.query === 'string') {
                    this.query = state.query;
                }
                if (typeof state.status === 'string') {
                    this.status = state.status;
                }
                if (Array.isArray(state.expanded)) {
                    this.expanded = state.expanded.filter((id): id is string => typeof id === 'string');
                }
                if (Array.isArray(state.collapsedFiltered)) {
                    this.collapsedFiltered = state.collapsedFiltered.filter(
                        (id): id is string => typeof id === 'string',
                    );
                }
                if (typeof state.toast === 'string' && state.toast.trim() !== '') {
                    const tone = state.toastTone || 'success';
                    queueMicrotask(() => window.gereq?.toast?.(state.toast || '', tone));
                }
            } catch {
                /* ignore corrupt payload */
            }
        },

        applyModuleState(mod: {
            id: string;
            enabled: boolean;
            installed: boolean;
            parent_id: string | null;
            parent_enabled: boolean;
        }) {
            const root = this.$el as HTMLElement;
            const row = root.querySelector<HTMLElement>(`tbody tr[data-id="${CSS.escape(mod.id)}"]`);
            if (!row) {
                return;
            }
            row.dataset.enabled = mod.enabled ? '1' : '0';
            row.dataset.installed = mod.installed ? '1' : '0';

            const statusCell = row.querySelector<HTMLElement>('[data-module-status]');
            if (statusCell && row.dataset.update !== '1') {
                const label = mod.enabled
                    ? root.dataset.labelEnabled || 'On'
                    : root.dataset.labelDisabled || 'Off';
                statusCell.innerHTML = mod.enabled
                    ? `<span class="badge badge--ok" data-module-status-badge="on">${escapeHtml(label)}</span>`
                    : `<span class="badge badge--danger" data-module-status-badge="off">${escapeHtml(label)}</span>`;
            }

            const pause = row.querySelector<HTMLElement>('[data-module-action="pause"]');
            const enable = row.querySelector<HTMLElement>('[data-module-action="enable"]');
            const blocked = row.querySelector<HTMLElement>('[data-module-action="enable-blocked"]');
            const disable = row.querySelector<HTMLElement>('[data-module-action="disable"]');
            const disableSpacer = row.querySelector<HTMLElement>('[data-module-action="disable-spacer"]');

            if (pause) {
                pause.hidden = !mod.enabled;
            }
            const canEnable = !mod.enabled && mod.parent_enabled;
            if (enable) {
                enable.hidden = !canEnable;
            }
            if (blocked) {
                blocked.hidden = mod.enabled || mod.parent_enabled || !mod.parent_id;
            }
            if (disable) {
                disable.hidden = !mod.installed;
            }
            if (disableSpacer) {
                disableSpacer.hidden = mod.installed;
            }

            // Parent toggle also gates child enable buttons.
            if (!mod.parent_id) {
                root.querySelectorAll<HTMLElement>(`tbody tr[data-parent-id="${CSS.escape(mod.id)}"]`).forEach(
                    (child) => {
                        const childEnabled = child.dataset.enabled === '1';
                        const childEnable = child.querySelector<HTMLElement>('[data-module-action="enable"]');
                        const childBlocked = child.querySelector<HTMLElement>(
                            '[data-module-action="enable-blocked"]',
                        );
                        const childCanEnable = !childEnabled && mod.enabled;
                        if (childEnable) {
                            childEnable.hidden = !childCanEnable;
                        }
                        if (childBlocked) {
                            childBlocked.hidden = childEnabled || mod.enabled;
                        }
                    },
                );
            }
        },

        get refreshBtnClass(): string {
            return this.busy ? 'is-busy' : '';
        },

        get busyAttr(): string {
            return this.busy ? 'true' : 'false';
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
            return id !== '' && this.selected.includes(id);
        },

        isExpanded(id: string): boolean {
            return id !== '' && (this.isFiltering
                ? !this.collapsedFiltered.includes(id)
                : this.expanded.includes(id));
        },

        get isFiltering(): boolean {
            return this.query.trim() !== '' || this.status !== '';
        },

        resetFilteredExpansion() {
            this.collapsedFiltered = [];
            this.apply();
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
            if (id === '') {
                return;
            }
            this.selected = this.isSelected(id)
                ? this.selected.filter((item: string) => item !== id)
                : [...this.selected, id];
        },

        /** Sync selection from the checkbox that actually changed (avoids off-by-one). */
        toggleFromEvent(event: Event) {
            const input = event.target;
            if (!(input instanceof HTMLInputElement) || input.type !== 'checkbox') {
                return;
            }
            const id = input.value;
            if (id === '') {
                return;
            }
            if (input.checked) {
                if (!this.isSelected(id)) {
                    this.selected = [...this.selected, id];
                }
            } else if (this.isSelected(id)) {
                this.selected = this.selected.filter((item: string) => item !== id);
            }
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

        syncBulkAction(event: Event) {
            const target = event.target;
            if (!(target instanceof HTMLInputElement) || target.name !== 'action') {
                return;
            }
            this.bulkAction = target.value;
        },

        confirmBulk(event: Event) {
            if (this.bulkAction !== 'disable') {
                return;
            }

            const form = event.target;
            if (!(form instanceof HTMLFormElement)) {
                return;
            }
            if (form.dataset.confirmAccepted === '1') {
                delete form.dataset.confirmAccepted;
                return;
            }

            event.preventDefault();

            const root = this.$el as HTMLElement;
            const tpl = root.dataset.confirmDisable || '';
            const mediaTpl = root.dataset.confirmDisableMedia || '';
            const msg = tpl.replace('%count%', String(this.selected.length));

            void (async () => {
                const ask =
                    window.gereq?.confirm ??
                    ((opts: { body?: string; title?: string }) =>
                        Promise.resolve(window.confirm(opts.body || opts.title || '')));

                const ok = await ask({
                    title: root.dataset.confirmDisableTitle || undefined,
                    body: msg,
                    danger: true,
                    confirmLabel: root.dataset.confirmDisableLabel || undefined,
                });
                if (!ok) {
                    return;
                }

                const purge = await ask({
                    title: root.dataset.confirmDisableMediaTitle || undefined,
                    body: mediaTpl,
                    danger: true,
                    confirmLabel: root.dataset.confirmDisableMediaLabel || undefined,
                });
                const input = this.$refs.purgeMedia as HTMLInputElement | undefined;
                if (input) {
                    input.value = purge ? '1' : '0';
                }

                form.dataset.confirmAccepted = '1';
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    form.submit();
                }
            })();
        },

        apply() {
            const root = this.$el as HTMLElement;
            const table = root.querySelector<HTMLElement>('table.table');
            const q = this.query.trim().toLowerCase();
            const status = this.status;
            const filtering = q !== '' || status !== '';
            const rows = root.querySelectorAll<HTMLElement>('tbody tr[data-search]');
            let visible = 0;

            table?.classList.toggle('is-module-filtering', filtering);

            const needle = normalizeSearch(q);
            const parentQueryMatch = new Set<string>();
            rows.forEach((row) => {
                const parentId = row.dataset.parentId || '';
                const rowId = row.dataset.id || '';
                if (parentId !== '' || rowId === '') {
                    return;
                }
                const search = normalizeSearch(row.dataset.search || '');
                if (needle === '' || search.includes(needle)) {
                    parentQueryMatch.add(rowId);
                }
            });

            rows.forEach((row) => {
                const search = normalizeSearch(row.dataset.search || '');
                const enabled = row.dataset.enabled === '1';
                const warn = row.dataset.warn === '1';
                const update = row.dataset.update === '1';
                const parentId = row.dataset.parentId || '';
                const rowId = row.dataset.id || '';
                const isChild = parentId !== '';
                const matchesQuery = needle === '' || search.includes(needle);
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

                // A matching parent keeps its submodules in the result.
                const viaParent = isChild && parentQueryMatch.has(parentId);
                const filterMatch = (matchesQuery || viaParent) && matchesStatus;
                let show = filterMatch;
                if (!isChild && rowId !== '') {
                    // Expand state on the parent row — Alpine :class on <tr> must not own this.
                    row.classList.toggle('is-children-open', this.isExpanded(rowId));
                }
                if (isChild) {
                    const open = this.isExpanded(parentId);
                    // data-expanded survives Alpine class bindings that would wipe .is-open.
                    if (open) {
                        row.setAttribute('data-expanded', '1');
                    } else {
                        row.removeAttribute('data-expanded');
                    }
                    row.classList.toggle('is-filter-match', filterMatch);
                    if (!open) {
                        show = false;
                    }
                }

                row.hidden = !show;
                row.classList.toggle('is-row-hidden', !show);
                if (show) {
                    visible += 1;
                }
            });

            root.querySelectorAll<HTMLElement>('[data-module-children-toggle]').forEach((btn) => {
                const id = btn.dataset.parentId || '';
                const open = this.isExpanded(id);
                btn.classList.toggle('is-open', open);
                btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            });

            this.empty = rows.length > 0 && visible === 0;
            this.syncSelectAll();
        },
    }));
}

function normalizeSearch(value: string): string {
    return value
        .toLowerCase()
        .normalize('NFD')
        .replace(/\p{M}/gu, '')
        .replace(/[-_\s]+/g, '');
}

function escapeHtml(value: string): string {
    return value
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');
}
