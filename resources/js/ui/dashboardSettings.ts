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

export function registerDashboardSettings(alpine: typeof Alpine): void {
    alpine.data('gereqDashboardSettings', () => ({
        items: [] as DashItem[],
        sections: [] as Section[],
        typeLabels: {} as Record<string, string>,
        widthLabels: {} as Record<string, string>,
        widths: [...WIDTHS],
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
            const order = ['metric', 'hero', 'panel', 'shortcut'];
            this.sections = order
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

        toggle(id: string) {
            const item = this.items.find((row) => row.id === id);
            if (!item) {
                return;
            }
            item.enabled = !item.enabled;
            this.sync();
        },

        showWidth(type: string): boolean {
            return type === 'panel';
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

        siblings(id: string): DashItem[] {
            const item = this.items.find((row) => row.id === id);
            if (!item) {
                return [];
            }

            return this.items
                .filter((row) => row.type === item.type)
                .sort((a, b) => a.order - b.order || a.id.localeCompare(b.id));
        },

        canMoveUp(id: string): boolean {
            return this.siblings(id).findIndex((row) => row.id === id) > 0;
        },

        canMoveDown(id: string): boolean {
            const list = this.siblings(id);
            const index = list.findIndex((row) => row.id === id);

            return index >= 0 && index < list.length - 1;
        },

        move(id: string, delta: number) {
            const list = this.siblings(id);
            const index = list.findIndex((row) => row.id === id);
            const target = index + delta;
            if (index < 0 || target < 0 || target >= list.length) {
                return;
            }
            const a = list[index];
            const b = list[target];
            const tmp = a.order;
            a.order = b.order;
            b.order = tmp;
            if (a.order === b.order) {
                a.order += delta;
            }
            this.sync();
        },
    }));
}
