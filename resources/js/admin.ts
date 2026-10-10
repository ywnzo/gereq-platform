import Alpine from '@alpinejs/csp';
import { registerUi } from './ui';
import '../css/kernel.css';
import '../css/customers.css';
import '../css/media.css';
import '../css/queues.css';
/* Module CSS is built per-module and linked by RenderEngine for enabled modules only. */

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
        gereqConfirmModuleVersion?: (
            form: HTMLFormElement,
            isDowngrade: boolean,
            activateMsg: string,
            downgradeMsg: string,
        ) => boolean;
    }
}

/** Sync fallback for version activate/downgrade forms that still use onclick. */
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

const SIDEBAR_NARROW_MQ = '(max-width: 860px)';

Alpine.data('gereqAdmin', () => ({
    sidebarOpen: false,
    collapsed: localStorage.getItem('gereq-sidebar') === 'collapsed',
    isNarrow: false,
    paletteOpen: false,
    userOpen: false,
    query: '',
    commands: window.__GEREQ_COMMANDS__ ?? [],
    liveHits: [] as PaletteCommand[],
    searchLoading: false,
    visibleCommands: [] as PaletteCommand[],
    _searchTimer: null as ReturnType<typeof setTimeout> | null,
    _searchAbort: null as AbortController | null,
    _narrowMq: null as MediaQueryList | null,
    _onNarrowChange: null as (() => void) | null,
    _onKeydown: null as ((event: KeyboardEvent) => void) | null,

    init() {
        this.refreshCommands();
        this._narrowMq = window.matchMedia(SIDEBAR_NARROW_MQ);
        this.isNarrow = this._narrowMq.matches;
        this._onNarrowChange = () => {
            this.isNarrow = this._narrowMq?.matches ?? false;
            if (!this.isNarrow) {
                this.sidebarOpen = false;
            }
            this.syncSidebarState();
        };
        this._narrowMq.addEventListener('change', this._onNarrowChange);
        this.syncSidebarState();

        this._onKeydown = (event: KeyboardEvent) => {
            if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                this.openPalette();
            }
            if (event.key === 'Escape') {
                this.closePalette();
                this.userOpen = false;
                this.closeSidebar();
            }
        };
        document.addEventListener('keydown', this._onKeydown);

        document.addEventListener('wheel', (e) => {
          const el = (e.target as HTMLElement | null)?.closest('.cms-builder__create-body');
          if (!el) return;
          el.scrollTop += e.deltaY;
          e.preventDefault();
        }, { passive: false });

        return () => {
            if (this._narrowMq && this._onNarrowChange) {
                this._narrowMq.removeEventListener('change', this._onNarrowChange);
            }
            if (this._onKeydown) {
                document.removeEventListener('keydown', this._onKeydown);
            }
            document.documentElement.style.overflow = '';
        };
    },

    syncSidebarState() {
        const railCollapsed = this.collapsed && !this.isNarrow;
        document.body.dataset.sidebar = railCollapsed ? 'collapsed' : 'expanded';
        document.documentElement.style.overflow =
            this.isNarrow && this.sidebarOpen ? 'hidden' : '';
    },

    get sidebarClass(): string {
        const parts: string[] = [];
        if (this.collapsed && !this.isNarrow) {
            parts.push('is-collapsed');
        }
        if (this.sidebarOpen) {
            parts.push('is-open');
        }
        return parts.join(' ');
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
        if (this.isNarrow) {
            this.sidebarOpen = true;
            this.syncSidebarState();
            return;
        }
        if (this.collapsed) {
            this.expandSidebar();
            return;
        }
        this.sidebarOpen = true;
        this.syncSidebarState();
    },

    closeSidebar() {
        this.sidebarOpen = false;
        this.syncSidebarState();
    },

    expandSidebar() {
        this.collapsed = false;
        localStorage.setItem('gereq-sidebar', 'expanded');
        this.syncSidebarState();
    },

    toggleCollapsed() {
        if (this.isNarrow) {
            return;
        }
        this.collapsed = !this.collapsed;
        localStorage.setItem('gereq-sidebar', this.collapsed ? 'collapsed' : 'expanded');
        if (!this.collapsed) {
            this.sidebarOpen = false;
        }
        this.syncSidebarState();
    },

    toggleMenu() {
        if (this.isNarrow) {
            this.sidebarOpen = !this.sidebarOpen;
            this.syncSidebarState();
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
    get groupClass(): string {
        return this.open ? 'is-open' : 'is-closed';
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

type FlyoutHost = {
    flyoutOpen: boolean;
    _flyoutId: number;
    _leaveTimer: ReturnType<typeof setTimeout> | null;
    _unsubFlyout: (() => void) | null;
    get flyoutClass(): string;
    bindFlyoutExclusive(): () => void;
    flyoutEnter(): void;
    flyoutLeave(): void;
    flyoutToggle(): void;
    flyoutMaybeToggle(event: Event): void;
};

/** Only one collapsed flyout open at a time — avoids overlapping panels. */
const navFlyoutBus = {
    seq: 0,
    listeners: new Set<(id: number) => void>(),
    nextId(): number {
        this.seq += 1;
        return this.seq;
    },
    claim(id: number): void {
        for (const listener of this.listeners) {
            listener(id);
        }
    },
    subscribe(listener: (id: number) => void): () => void {
        this.listeners.add(listener);
        return () => {
            this.listeners.delete(listener);
        };
    },
};

function createFlyoutHost(): FlyoutHost {
    return {
        flyoutOpen: false,
        _flyoutId: navFlyoutBus.nextId(),
        _leaveTimer: null,
        _unsubFlyout: null,
        get flyoutClass(): string {
            return this.flyoutOpen ? 'is-flyout-open' : '';
        },
        bindFlyoutExclusive() {
            this._unsubFlyout = navFlyoutBus.subscribe((id) => {
                if (id === this._flyoutId) {
                    return;
                }
                if (this._leaveTimer) {
                    clearTimeout(this._leaveTimer);
                    this._leaveTimer = null;
                }
                this.flyoutOpen = false;
            });
            return () => {
                this._unsubFlyout?.();
                this._unsubFlyout = null;
                if (this._leaveTimer) {
                    clearTimeout(this._leaveTimer);
                    this._leaveTimer = null;
                }
            };
        },
        flyoutEnter() {
            if (this._leaveTimer) {
                clearTimeout(this._leaveTimer);
                this._leaveTimer = null;
            }
            navFlyoutBus.claim(this._flyoutId);
            this.flyoutOpen = true;
        },
        flyoutLeave() {
            if (this._leaveTimer) {
                clearTimeout(this._leaveTimer);
            }
            this._leaveTimer = setTimeout(() => {
                this.flyoutOpen = false;
                this._leaveTimer = null;
            }, 160);
        },
        flyoutToggle() {
            if (this._leaveTimer) {
                clearTimeout(this._leaveTimer);
                this._leaveTimer = null;
            }
            if (!this.flyoutOpen) {
                navFlyoutBus.claim(this._flyoutId);
            }
            this.flyoutOpen = !this.flyoutOpen;
        },
        /** Touch / coarse pointer: first tap opens flyout instead of navigating. */
        flyoutMaybeToggle(event: Event) {
            if (document.body.dataset.sidebar !== 'collapsed') {
                return;
            }
            if (window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
                return;
            }
            if (this.flyoutOpen) {
                return;
            }
            event.preventDefault();
            this.flyoutToggle();
        },
    };
}

Alpine.data('navFlyout', () => ({
    ...createFlyoutHost(),
    init() {
        return this.bindFlyoutExclusive();
    },
}));

Alpine.data('navTree', () => ({
    open: false,
    ...createFlyoutHost(),
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
        return this.bindFlyoutExclusive();
    },
    storageKey(): string {
        return 'gereq-nav-tree:' + ((this.$el as HTMLElement).dataset.treeId || '');
    },
    get treeClass(): string {
        return (this.open ? 'is-open ' : '') + (this.flyoutOpen ? 'is-flyout-open' : '');
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
