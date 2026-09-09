import Alpine from '@alpinejs/csp';
import { registerUi } from './ui';
import '../css/kernel.css';
import '../../modules/BikeService/Resources/assets/css/admin.css';
import '../../modules/CarService/Resources/assets/css/admin.css';
import '../../modules/SearchEngines/Resources/assets/css/admin.css';
import '../../modules/Fleet/Resources/assets/css/admin.css';
import '../../modules/Calendar/Resources/assets/css/admin.css';
import '../../modules/Customers/Resources/assets/css/admin.css';
import '../../modules/PartsCatalog/Resources/assets/css/admin.css';
import '../../modules/Cms/Resources/assets/css/admin.css';
import '../../modules/CRM/Resources/assets/css/admin.css';
import '../../modules/Emailing/Resources/assets/css/admin.css';
import '../../modules/Products/Resources/assets/css/admin.css';
import '../../modules/ProductDiscounts/Resources/assets/css/admin.css';
import '../../modules/Vitality/Resources/assets/css/admin.css';
import '../../modules/ESign/Resources/assets/css/admin.css';
import '../../modules/Portals/Resources/assets/css/admin.css';

type PaletteCommand = {
    id: string;
    label: string;
    url: string;
    icon?: string | null;
    permission?: string | null;
    keywords?: string[];
    group?: string;
    subtitle?: string | null;
    kind?: 'command' | 'hit';
};

declare global {
    interface Window {
        __GEREQ_COMMANDS__: PaletteCommand[];
        __GEREQ_CSRF__: string;
        __GEREQ_SEARCH_URL__?: string;
        Alpine: typeof Alpine;
        gereqConfirmModuleDisable?: (form: HTMLFormElement, disableMsg: string, mediaMsg: string) => boolean;
        gereqConfirmModuleVersion?: (
            form: HTMLFormElement,
            isDowngrade: boolean,
            activateMsg: string,
            downgradeMsg: string,
        ) => boolean;
    }
}

window.gereqConfirmModuleDisable = (form, disableMsg, mediaMsg) => {
    if (!window.confirm(disableMsg)) {
        return false;
    }
    const purge = window.confirm(mediaMsg);
    const input = form.querySelector<HTMLInputElement>('input[name="purge_media"]');
    if (input) {
        input.value = purge ? '1' : '0';
    }
    return true;
};

window.gereqConfirmModuleVersion = (form, isDowngrade, activateMsg, downgradeMsg) => {
    if (!window.confirm(isDowngrade ? downgradeMsg : activateMsg)) {
        return false;
    }
    const input = form.querySelector<HTMLInputElement>('input[name="confirm_downgrade"]');
    if (input) {
        input.value = isDowngrade ? '1' : '0';
    }
    return true;
};

Alpine.data('gereqAdmin', () => ({
    sidebarOpen: false,
    collapsed: localStorage.getItem('gereq-sidebar') === 'collapsed',
    paletteOpen: false,
    userOpen: false,
    query: '',
    commands: window.__GEREQ_COMMANDS__ ?? [],
    liveHits: [] as PaletteCommand[],
    searchLoading: false,
    visibleCommands: [] as PaletteCommand[],
    _searchTimer: null as ReturnType<typeof setTimeout> | null,
    _searchAbort: null as AbortController | null,

    init() {
        this.refreshCommands();
        document.body.dataset.sidebar = this.collapsed ? 'collapsed' : 'expanded';
        document.addEventListener('keydown', (event: KeyboardEvent) => {
            if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                this.openPalette();
            }
            if (event.key === 'Escape') {
                this.closePalette();
                this.userOpen = false;
            }
        });
    },

    get sidebarClass(): string {
        return (this.collapsed ? 'is-collapsed ' : '') + (this.sidebarOpen ? 'is-open' : '');
    },

    get backdropClass(): string {
        return this.sidebarOpen ? 'is-visible' : '';
    },

    get paletteClass(): string {
        return this.paletteOpen ? 'is-open' : '';
    },

    get userMenuClass(): string {
        return this.userOpen ? 'is-open' : '';
    },

    openSidebar() {
        if (this.collapsed) {
            this.expandSidebar();
            return;
        }
        this.sidebarOpen = true;
    },

    closeSidebar() {
        this.sidebarOpen = false;
    },

    expandSidebar() {
        this.collapsed = false;
        localStorage.setItem('gereq-sidebar', 'expanded');
        document.body.dataset.sidebar = 'expanded';
    },

    toggleCollapsed() {
        this.collapsed = !this.collapsed;
        localStorage.setItem('gereq-sidebar', this.collapsed ? 'collapsed' : 'expanded');
        document.body.dataset.sidebar = this.collapsed ? 'collapsed' : 'expanded';
        if (!this.collapsed) {
            this.sidebarOpen = false;
        }
    },

    toggleMenu() {
        if (this.collapsed) {
            this.expandSidebar();
            return;
        }
        if (window.matchMedia('(max-width: 860px)').matches) {
            this.sidebarOpen = !this.sidebarOpen;
            return;
        }
        this.toggleCollapsed();
    },

    openPalette() {
        this.paletteOpen = true;
        this.$nextTick(() => (this.$refs.paletteInput as HTMLInputElement | undefined)?.focus());
    },

    closePalette() {
        this.paletteOpen = false;
        this._searchAbort?.abort();
        if (this._searchTimer) {
            clearTimeout(this._searchTimer);
            this._searchTimer = null;
        }
    },

    toggleUserMenu() {
        this.userOpen = !this.userOpen;
    },

    closeUserMenu() {
        this.userOpen = false;
    },

    cycleTheme() {
        const order = ['dark', 'light', 'system'] as const;
        const current = localStorage.getItem('gereq-theme') || 'dark';
        const next = order[(order.indexOf(current as typeof order[number]) + 1) % order.length];
        localStorage.setItem('gereq-theme', next);
        const dark = next === 'dark' || (next === 'system' && matchMedia('(prefers-color-scheme: dark)').matches);
        document.documentElement.classList.toggle('dark', dark);
        document.documentElement.dataset.theme = dark ? 'gereq-dark' : 'gereq';
    },

    refreshCommands() {
        const q = this.query.trim().toLowerCase();
        if (!q) {
            this.liveHits = [];
            this.searchLoading = false;
            this.visibleCommands = this.commands;
            this._searchAbort?.abort();
            if (this._searchTimer) {
                clearTimeout(this._searchTimer);
                this._searchTimer = null;
            }
            return;
        }

        const staticHits = this.commands.filter((command: PaletteCommand) => {
            const haystack = [command.label, command.group, ...(command.keywords ?? [])].join(' ').toLowerCase();
            return haystack.includes(q);
        });
        this.visibleCommands = [...staticHits, ...this.liveHits];

        if (this._searchTimer) {
            clearTimeout(this._searchTimer);
        }
        this._searchTimer = setTimeout(() => {
            void this.fetchLiveHits(q);
        }, 220);
    },

    async fetchLiveHits(q: string) {
        const url = window.__GEREQ_SEARCH_URL__;
        if (!url || q.length < 2) {
            this.liveHits = [];
            this.mergeVisible();
            return;
        }
        this._searchAbort?.abort();
        const abort = new AbortController();
        this._searchAbort = abort;
        this.searchLoading = true;
        try {
            const res = await fetch(`${url}?q=${encodeURIComponent(q)}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                signal: abort.signal,
            });
            if (!res.ok) {
                throw new Error(`HTTP ${res.status}`);
            }
            const json = await res.json();
            const results = Array.isArray(json.results) ? json.results : [];
            this.liveHits = results.map((row: Record<string, unknown>) => ({
                id: String(row.id ?? ''),
                label: String(row.label ?? ''),
                url: String(row.url ?? '#'),
                group: String(row.group ?? ''),
                subtitle: row.subtitle != null ? String(row.subtitle) : null,
                icon: row.icon != null ? String(row.icon) : null,
                keywords: Array.isArray(row.keywords) ? (row.keywords as string[]) : [],
                kind: 'hit' as const,
            }));
        } catch (err) {
            if ((err as Error).name === 'AbortError') {
                return;
            }
            this.liveHits = [];
        } finally {
            this.searchLoading = false;
            this.mergeVisible();
        }
    },

    mergeVisible() {
        const q = this.query.trim().toLowerCase();
        if (!q) {
            this.visibleCommands = this.commands;
            return;
        }
        const staticHits = this.commands.filter((command: PaletteCommand) => {
            const haystack = [command.label, command.group, ...(command.keywords ?? [])].join(' ').toLowerCase();
            return haystack.includes(q);
        });
        this.visibleCommands = [...staticHits, ...this.liveHits];
    },

    onCommandClick(event: Event) {
        const target = event.currentTarget as HTMLAnchorElement | null;
        if (target?.dataset.command === 'logout') {
            event.preventDefault();
            (document.getElementById('logout-form') as HTMLFormElement | null)?.submit();
        }
    },
}));

Alpine.data('navGroup', () => ({
    open: true,
    init() {
        const key = this.storageKey();
        const stored = localStorage.getItem(key);
        if (stored === '0') {
            this.open = false;
        } else if (stored === '1') {
            this.open = true;
        }
    },
    storageKey(): string {
        return 'gereq-nav-group:' + ((this.$el as HTMLElement).dataset.group || '');
    },
    get chevronClass(): string {
        return this.open ? 'is-open' : '';
    },
    get expandedAttr(): string {
        return this.open ? 'true' : 'false';
    },
    toggle() {
        this.open = !this.open;
        localStorage.setItem(this.storageKey(), this.open ? '1' : '0');
    },
}));

Alpine.data('navTree', () => ({
    open: false,
    init() {
        const el = this.$el as HTMLElement;
        const key = this.storageKey();
        const stored = localStorage.getItem(key);
        const routeOpen = (el.dataset.defaultOpen || '0') === '1';
        if (routeOpen) {
            this.open = true;
        } else if (stored === '1') {
            this.open = true;
        } else if (stored === '0') {
            this.open = false;
        } else {
            this.open = false;
        }
    },
    storageKey(): string {
        return 'gereq-nav-tree:' + ((this.$el as HTMLElement).dataset.treeId || '');
    },
    get chevronClass(): string {
        return this.open ? 'is-open' : '';
    },
    get expandedAttr(): string {
        return this.open ? 'true' : 'false';
    },
    toggle() {
        this.open = !this.open;
        localStorage.setItem(this.storageKey(), this.open ? '1' : '0');
    },
}));

Alpine.data('rowMenu', () => ({
    open: false,
    menuStyle: {} as Record<string, string>,
    toggle() {
        if (this.open) {
            this.close();
            return;
        }
        this.open = true;
        this.$nextTick(() => this.place());
    },
    close() {
        this.open = false;
        this.menuStyle = {};
    },
    place() {
        const btn = this.$refs.button as HTMLElement | undefined;
        const menu = this.$refs.menu as HTMLElement | undefined;
        if (!btn || !menu) {
            return;
        }

        const rect = btn.getBoundingClientRect();
        const menuWidth = menu.offsetWidth || 220;
        const menuHeight = menu.offsetHeight || 0;
        const gap = 6;
        let top = rect.bottom + gap;
        let left = rect.right - menuWidth;

        if (top + menuHeight > window.innerHeight - 8) {
            top = Math.max(8, rect.top - menuHeight - gap);
        }
        if (left < 8) {
            left = 8;
        }
        if (left + menuWidth > window.innerWidth - 8) {
            left = Math.max(8, window.innerWidth - menuWidth - 8);
        }

        this.menuStyle = {
            position: 'fixed',
            top: `${Math.round(top)}px`,
            left: `${Math.round(left)}px`,
            right: 'auto',
            zIndex: '80',
        };
    },
    get menuClass(): string {
        return this.open ? 'is-open' : '';
    },
}));

Alpine.data('dataTable', () => ({
    selected: [] as string[],
    pageIds: [] as string[],
    bulkAction: '',
    query: '',
    init() {
        const el = this.$el as HTMLElement;
        this.query = el.dataset.initialQuery || '';
        try {
            this.pageIds = JSON.parse(el.dataset.pageIds || '[]') as string[];
        } catch {
            this.pageIds = [];
        }
        this.syncFit();
        this.$watch('selected', () => this.syncSelectAll());
        this.$nextTick(() => {
            this.syncSelectAll();
            this.syncFit();
        });
    },
    matchText(text: string): boolean {
        const q = this.query.trim().toLowerCase();
        if (q === '') {
            return true;
        }

        return String(text).toLowerCase().includes(q);
    },
    syncFit() {
        const shell = this.$el as HTMLElement;
        const wrap = shell.querySelector('.table-wrap');
        if (!(wrap instanceof HTMLElement)) {
            return;
        }
        // Real page content after the list (rates, hooks…) — not fixed drawers / scripts.
        const siblingContent = shell.parentElement
            ? Array.from(shell.parentElement.children).some((el) => {
                if (el === shell || !(el instanceof HTMLElement)) {
                    return false;
                }
                const tag = el.tagName;
                if (tag === 'SCRIPT' || tag === 'STYLE' || tag === 'TEMPLATE' || tag === 'LINK') {
                    return false;
                }
                if (
                    el.classList.contains('g-drawer')
                    || el.classList.contains('alert')
                    || el.classList.contains('alert-success')
                    || el.classList.contains('alert-error')
                ) {
                    return false;
                }

                return true;
            })
            : false;
        if (siblingContent) {
            shell.classList.add('list-shell--fit');
            return;
        }
        // Stretch when there is at least one data row / card; hug content only when empty.
        const dataRows = Array.from(wrap.querySelectorAll('tbody > tr')).filter(
            (tr) => !(tr instanceof HTMLElement && tr.querySelector('.empty')),
        );
        const hasCards = wrap.querySelector('.bs-catalog__card, .bs-catalog__grid') !== null;
        shell.classList.toggle('list-shell--fit', dataRows.length === 0 && !hasCards);
    },
    get selectedCount(): number {
        return this.selected.length;
    },
    get allSelected(): boolean {
        return this.pageIds.length > 0 && this.pageIds.every((id) => this.selected.includes(id));
    },
    get someSelected(): boolean {
        return this.selected.length > 0 && !this.allSelected;
    },
    isSelected(id: string): boolean {
        return this.selected.includes(id);
    },
    syncSelectAll() {
        const el = this.$refs.selectAll as HTMLInputElement | undefined;
        if (!el) {
            return;
        }
        el.indeterminate = this.someSelected;
    },
    toggleId(id: string) {
        this.selected = this.isSelected(id)
            ? this.selected.filter((item) => item !== id)
            : [...this.selected, id];
    },
    toggleAll() {
        this.selected = this.allSelected ? [] : [...this.pageIds];
    },
    clearSelection() {
        this.selected = [];
        this.bulkAction = '';
    },
    confirmBulk(event: Event) {
        if (this.selected.length === 0) {
            event.preventDefault();
            return;
        }
        if (this.bulkAction === 'delete') {
            const message = (this.$el as HTMLElement).dataset.bulkDeleteConfirm
                || 'Delete selected items?';
            if (!window.confirm(message)) {
                event.preventDefault();
            }
        }
    },
}));

registerUi(Alpine);

window.Alpine = Alpine;
Alpine.start();
