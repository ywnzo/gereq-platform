import Alpine from '@alpinejs/csp';
import { registerUi } from './ui';
import '../css/admin.css';

type PaletteCommand = {
    id: string;
    label: string;
    url: string;
    icon?: string | null;
    permission?: string | null;
    keywords?: string[];
    group?: string;
};

declare global {
    interface Window {
        __GEREQ_COMMANDS__: PaletteCommand[];
        __GEREQ_CSRF__: string;
        Alpine: typeof Alpine;
    }
}

Alpine.data('gereqAdmin', () => ({
    sidebarOpen: false,
    collapsed: localStorage.getItem('gereq-sidebar') === 'collapsed',
    paletteOpen: false,
    userOpen: false,
    query: '',
    commands: window.__GEREQ_COMMANDS__ ?? [],
    visibleCommands: [] as PaletteCommand[],

    init() {
        this.refreshCommands();
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
        this.sidebarOpen = true;
    },

    closeSidebar() {
        this.sidebarOpen = false;
    },

    toggleCollapsed() {
        this.collapsed = !this.collapsed;
        localStorage.setItem('gereq-sidebar', this.collapsed ? 'collapsed' : 'expanded');
    },

    openPalette() {
        this.paletteOpen = true;
        this.$nextTick(() => (this.$refs.paletteInput as HTMLInputElement | undefined)?.focus());
    },

    closePalette() {
        this.paletteOpen = false;
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
            this.visibleCommands = this.commands;
            return;
        }

        this.visibleCommands = this.commands.filter((command: PaletteCommand) => {
            const haystack = [command.label, command.group, ...(command.keywords ?? [])].join(' ').toLowerCase();
            return haystack.includes(q);
        });
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
        const key = 'gereq-nav-group:' + ((this.$el as HTMLElement).dataset.group || '');
        const stored = localStorage.getItem(key);
        if (stored === '0') {
            this.open = false;
        } else if (stored === '1') {
            this.open = true;
        }
    },
    get chevronClass(): string {
        return this.open ? 'is-open' : '';
    },
    get expandedAttr(): string {
        return this.open ? 'true' : 'false';
    },
    toggle() {
        this.open = !this.open;
        const key = 'gereq-nav-group:' + ((this.$el as HTMLElement).dataset.group || '');
        localStorage.setItem(key, this.open ? '1' : '0');
    },
}));

Alpine.data('navTree', () => ({
    open: false,
    init() {
        this.open = ((this.$el as HTMLElement).dataset.defaultOpen || '0') === '1';
    },
    get chevronClass(): string {
        return this.open ? 'is-open' : '';
    },
    get expandedAttr(): string {
        return this.open ? 'true' : 'false';
    },
    toggle() {
        this.open = !this.open;
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
    showActions: true,
    selected: [] as string[],
    pageIds: [] as string[],
    bulkAction: '',
    init() {
        const el = this.$el as HTMLElement;
        try {
            this.pageIds = JSON.parse(el.dataset.pageIds || '[]') as string[];
        } catch {
            this.pageIds = [];
        }
        const key = this.storageKey();
        const saved = localStorage.getItem(key);
        if (saved === '0' || saved === '1') {
            this.showActions = saved === '1';
        } else {
            const def = el.dataset.actionsDefault;
            this.showActions = def !== '0';
        }
        this.syncClass();
        this.$watch('selected', () => this.syncSelectAll());
        this.$nextTick(() => this.syncSelectAll());
    },
    storageKey(): string {
        const el = this.$el as HTMLElement;
        return 'gereq-table-actions:' + (el.dataset.tableId || el.id || location.pathname);
    },
    toggleActions() {
        this.showActions = !this.showActions;
        localStorage.setItem(this.storageKey(), this.showActions ? '1' : '0');
        this.syncClass();
    },
    syncClass() {
        const wrap = (this.$el as HTMLElement).querySelector('.table-wrap');
        if (!(wrap instanceof HTMLElement)) {
            return;
        }
        wrap.classList.toggle('is-actions-off', !this.showActions);
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

Alpine.start();
