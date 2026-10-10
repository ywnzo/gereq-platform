import type Alpine from '@alpinejs/csp';

type DashItem = {
    id: string;
    title: string;
    type: string;
    order: number;
    enabled: boolean;
    width: string;
};

type Section = { type: string; label: string; items: DashItem[] };

const WIDTHS = ['full', 'two_thirds', 'half', 'third'] as const;
const TYPE_ORDER = ['metric', 'hero', 'panel', 'shortcut'];

export function registerDashboardSettings(alpine: typeof Alpine): void {
    alpine.data('gereqDashboardSettings', () => ({
        items: [] as DashItem[],
        sections: [] as Section[],
        typeLabels: {} as Record<string, string>,
        widthLabels: {} as Record<string, string>,
        widths: [...WIDTHS],
        query: '',
        draggingId: '' as string,
        dropId: '' as string,
        $el: null as unknown as HTMLElement,
        $refs: {} as { payload?: HTMLInputElement },

        init() {
            const root = this.$el;
            this.typeLabels = {
                metric: root.dataset.labelMetric || 'metric',
                hero: root.dataset.labelHero || 'hero',
                panel: root.dataset.labelPanel || 'panel',
                shortcut: root.dataset.labelShortcut || 'shortcut',
            };
            this.widthLabels = {
                full: root.dataset.widthFull || '100%',
                two_thirds: root.dataset.widthTwoThirds || '66%',
                half: root.dataset.widthHalf || '50%',
                third: root.dataset.widthThird || '33%',
            };

            try {
                this.items = JSON.parse(root.dataset.items || '[]') as DashItem[];
            } catch {
                this.items = [];
            }
            this.items = this.items.map((item, i) => ({
                ...item,
                order: typeof item.order === 'number' ? item.order : (i + 1) * 10,
                enabled: item.enabled !== false,
                width: WIDTHS.includes(item.width as typeof WIDTHS[number]) ? item.width : 'half',
            }));
            this.sync();
        },

        rebuildSections() {
            const sorted = [...this.items].sort((a, b) => a.order - b.order || a.id.localeCompare(b.id));
            const byType = new Map<string, DashItem[]>();
            for (const item of sorted) {
                const list = byType.get(item.type) || [];
                list.push(item);
                byType.set(item.type, list);
            }
            this.sections = TYPE_ORDER
                .filter((type) => byType.has(type))
                .map((type) => ({
                    type,
                    label: this.typeLabels[type] || type,
                    items: byType.get(type) || [],
                }));
        },

        sync() {
            this.rebuildSections();
            const payload = this.$refs.payload;
            if (payload) {
                payload.value = JSON.stringify(
                    this.items.map((item) => ({
                        id: item.id,
                        enabled: item.enabled,
                        order: item.order,
                        width: item.width,
                    })),
                );
            }
        },

        enabledItems(type: string): DashItem[] {
            return this.items
                .filter((item) => item.type === type && item.enabled)
                .sort((a, b) => a.order - b.order || a.id.localeCompare(b.id));
        },

        poolItems(type: string): DashItem[] {
            const q = this.query.trim().toLowerCase();
            return this.items
                .filter((item) => item.type === type && !item.enabled)
                .filter((item) => q === '' || item.title.toLowerCase().includes(q))
                .sort((a, b) => a.title.localeCompare(b.title, undefined, { sensitivity: 'base' }));
        },

        poolCount(type: string): number {
            return this.poolItems(type).length;
        },

        enabledCount(type: string): number {
            return this.items.filter((item) => item.type === type && item.enabled).length;
        },

        totalCount(type: string): number {
            return this.items.filter((item) => item.type === type).length;
        },

        canvasClass(type: string): string {
            const empty = this.enabledCount(type) === 0 ? ' is-empty' : '';

            return `dash-settings__canvas dash-settings__canvas--${type}${empty}`;
        },

        cardClass(item: DashItem): string {
            const parts = ['dash-settings__card'];
            if (item.type === 'panel') {
                parts.push(`dash-settings__card--${item.width}`);
            }
            if (this.draggingId === item.id) {
                parts.push('is-dragging');
            }
            if (this.dropId === item.id && this.draggingId !== item.id) {
                parts.push('is-drop');
            }

            return parts.join(' ');
        },

        widthClass(item: DashItem, width: string): string {
            return item.width === width ? 'dash-settings__width-btn is-on' : 'dash-settings__width-btn';
        },

        widthBarClass(width: string): string {
            return `dash-settings__width-bar dash-settings__width-bar--${width}`;
        },

        isWidth(item: DashItem, width: string): boolean {
            return item.width === width;
        },

        showWidth(type: string): boolean {
            return type === 'panel';
        },

        setEnabled(id: string, enabled: boolean) {
            const item = this.items.find((row) => row.id === id);
            if (!item) {
                return;
            }
            item.enabled = enabled;
            this.sync();
        },

        setWidth(id: string, width: string) {
            if (!WIDTHS.includes(width as typeof WIDTHS[number])) {
                return;
            }
            const item = this.items.find((row) => row.id === id);
            if (!item) {
                return;
            }
            item.width = width;
            this.sync();
        },

        enableAll() {
            this.items.forEach((item) => {
                item.enabled = true;
            });
            this.sync();
        },

        disableAll() {
            this.items.forEach((item) => {
                item.enabled = false;
            });
            this.sync();
        },

        enableSection(type: string) {
            this.items.forEach((item) => {
                if (item.type === type) {
                    item.enabled = true;
                }
            });
            this.sync();
        },

        disableSection(type: string) {
            this.items.forEach((item) => {
                if (item.type === type) {
                    item.enabled = false;
                }
            });
            this.sync();
        },

        canMoveUp(id: string): boolean {
            const list = this.enabledItems(this.items.find((row) => row.id === id)?.type || '');
            return list.findIndex((row) => row.id === id) > 0;
        },

        canMoveDown(id: string): boolean {
            const item = this.items.find((row) => row.id === id);
            if (!item) {
                return false;
            }
            const list = this.enabledItems(item.type);
            const index = list.findIndex((row) => row.id === id);

            return index >= 0 && index < list.length - 1;
        },

        move(id: string, delta: number) {
            const item = this.items.find((row) => row.id === id);
            if (!item || !item.enabled) {
                return;
            }
            const list = this.enabledItems(item.type);
            const index = list.findIndex((row) => row.id === id);
            const target = index + delta;
            if (index < 0 || target < 0 || target >= list.length) {
                return;
            }
            this.placeBefore(id, delta > 0 ? list[target + 1]?.id : list[target].id, item.type);
        },

        placeBefore(id: string, beforeId: string | undefined, type: string) {
            const enabled = this.enabledItems(type).filter((row) => row.id !== id);
            const moving = this.items.find((row) => row.id === id);
            if (!moving) {
                return;
            }
            const at = beforeId ? enabled.findIndex((row) => row.id === beforeId) : -1;
            if (at >= 0) {
                enabled.splice(at, 0, moving);
            } else {
                enabled.push(moving);
            }
            const disabled = this.items
                .filter((row) => row.type === type && !row.enabled && row.id !== id)
                .sort((a, b) => a.order - b.order || a.id.localeCompare(b.id));
            [...enabled, ...disabled].forEach((row, i) => {
                row.order = (i + 1) * 10;
            });
            this.sync();
        },

        onDragStart(id: string, event: DragEvent) {
            const origin = event.target as HTMLElement | null;
            if (origin?.closest('button')) {
                event.preventDefault();
                return;
            }
            const item = this.items.find((row) => row.id === id);
            if (!item?.enabled) {
                event.preventDefault();
                return;
            }
            this.draggingId = id;
            if (event.dataTransfer) {
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', id);
            }
        },

        onDragOver(id: string, event: DragEvent) {
            if (this.draggingId === '' || this.draggingId === id) {
                return;
            }
            const drag = this.items.find((row) => row.id === this.draggingId);
            const over = this.items.find((row) => row.id === id);
            if (!drag || !over || drag.type !== over.type) {
                return;
            }
            event.preventDefault();
            this.dropId = id;
            if (event.dataTransfer) {
                event.dataTransfer.dropEffect = 'move';
            }
        },

        onDrop(id: string, event: DragEvent) {
            event.preventDefault();
            const dragId = this.draggingId;
            const drag = this.items.find((row) => row.id === dragId);
            const over = this.items.find((row) => row.id === id);
            this.draggingId = '';
            this.dropId = '';
            if (!drag || !over || drag.type !== over.type || dragId === id) {
                return;
            }
            this.placeBefore(dragId, id, drag.type);
        },

        onDragEnd() {
            this.draggingId = '';
            this.dropId = '';
        },
    }));
}
