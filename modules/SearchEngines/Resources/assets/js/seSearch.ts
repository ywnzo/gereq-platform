import type Alpine from '@alpinejs/csp';

type SearchItem = {
    id?: string | number | null;
    name?: string | null;
    brand?: string | null;
    shop?: string | null;
    shop_count?: number | null;
    source?: string | null;
    url?: string | null;
    image?: string | null;
    min_price?: number | null;
    currency?: string | null;
    price_label?: string | null;
    money?: { localized?: string } | null;
};

type SearchResponse = {
    ok?: boolean;
    items?: SearchItem[];
    error?: string | null;
    has_more?: boolean;
};

const REVEAL_MS = 42;

export function registerSeSearch(alpine: typeof Alpine): void {
    alpine.data('seSearch', () => ({
        query: '',
        sourceFilter: 'all',
        endpoint: '',
        offersEndpoint: '',
        pending: [] as string[],
        items: [] as SearchItem[],
        loading: false,
        revealing: false,
        revealQueue: [] as SearchItem[],
        enterKeys: {} as Record<string, boolean>,
        reduceMotion: false,
        currencyCode: 'CZK',
        currencySymbol: 'Kč',
        labels: {
            loading: 'Načítám…',
            empty: 'Nic nenalezeno',
            open: 'Otevřít',
            results: 'Výsledky',
        },

        init() {
            const el = this.$el as HTMLElement;
            this.reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            this.query = el.dataset.query || '';
            this.sourceFilter = el.dataset.source || 'all';
            this.endpoint = el.dataset.endpoint || '';
            this.offersEndpoint = el.dataset.offersEndpoint || '';
            this.currencyCode = el.dataset.currencyCode || this.currencyCode;
            this.currencySymbol = el.dataset.currencySymbol || this.currencySymbol;
            this.labels = {
                loading: el.dataset.labelLoading || this.labels.loading,
                empty: el.dataset.labelEmpty || this.labels.empty,
                open: el.dataset.labelOpen || this.labels.open,
                results: el.dataset.labelResults || this.labels.results,
            };
            try {
                this.pending = JSON.parse(el.dataset.pending || '[]') as string[];
            } catch {
                this.pending = [];
            }
            if (this.query && this.pending.length > 0 && this.endpoint) {
                void this.loadAll();
            }
        },

        get count(): number {
            return this.items.length;
        },

        get showLoading(): boolean {
            return this.loading && this.items.length === 0 && !this.revealing;
        },

        get showEmpty(): boolean {
            return !this.loading && !this.revealing && this.items.length === 0 && this.query !== '';
        },

        get showTable(): boolean {
            return this.items.length > 0;
        },

        get resultsTitle(): string {
            return `${this.labels.results} · ${this.count}`;
        },

        get panelClass(): string {
            return this.loading || this.revealing ? 'is-loading' : '';
        },

        itemKey(item: SearchItem, index = 0): string {
            const name = (item.name || '').trim().toLowerCase();
            const source = item.source || '';
            const id =
                item.id != null && String(item.id) !== ''
                    ? String(item.id)
                    : item.url || String(index);
            return `${name}|${source}|${id}`;
        },

        isEntering(item: SearchItem, index: number): boolean {
            return !!this.enterKeys[this.itemKey(item, index)];
        },

        rowClass(item: SearchItem, index: number): string {
            return this.isEntering(item, index) ? 'se-search__row is-enter' : 'se-search__row';
        },

        clearEnter(item: SearchItem, index: number) {
            const key = this.itemKey(item, index);
            if (this.enterKeys[key]) {
                const next = { ...this.enterKeys };
                delete next[key];
                this.enterKeys = next;
            }
        },

        hasImage(item: SearchItem): boolean {
            return !!item.image;
        },

        hasBrand(item: SearchItem): boolean {
            return !!(item.brand && String(item.brand).trim());
        },

        hasPrice(item: SearchItem): boolean {
            return item.min_price != null && !Number.isNaN(Number(item.min_price));
        },

        hasUrl(item: SearchItem): boolean {
            return !!(item.url && String(item.url).trim());
        },

        hasOffersHover(item: SearchItem): boolean {
            if (!this.offersEndpoint || !item.url) {
                return false;
            }
            if (item.source !== 'heureka' && item.source !== 'seznam') {
                return false;
            }
            const count = item.shop_count != null ? Number(item.shop_count) : 0;
            if (count > 1) {
                return true;
            }
            return String(item.shop || '')
                .toLowerCase()
                .includes('obchod');
        },

        shopLabel(item: SearchItem): string {
            return (item.shop && String(item.shop).trim()) || item.source || '';
        },

        hasShopPlain(item: SearchItem): boolean {
            return !!this.shopLabel(item) && !this.hasOffersHover(item);
        },

        formatPrice(item: SearchItem): string {
            if (item.price_label) {
                return item.price_label;
            }
            if (item.money && typeof item.money === 'object' && item.money.localized) {
                return String(item.money.localized);
            }
            if (!this.hasPrice(item)) {
                return '—';
            }
            const price = Number(item.min_price);
            const currency = item.currency || this.currencyCode;
            if (currency === 'EUR') {
                return `${price.toLocaleString('cs-CZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} €`;
            }
            const label = currency === this.currencyCode ? this.currencySymbol : currency;
            return `${Math.round(price).toLocaleString('cs-CZ')} ${label}`;
        },

        sourceLabel(item: SearchItem): string {
            return item.source || '—';
        },

        async loadAll() {
            const sources = [...this.pending];
            if (sources.length === 0) {
                return;
            }
            this.loading = true;
            try {
                await Promise.all(sources.map((source) => this.fetchSource(source)));
                await this.waitForReveal();
            } finally {
                this.loading = false;
                this.pending = [];
            }
        },

        async waitForReveal() {
            for (let i = 0; i < 250; i++) {
                if (!this.revealing && this.revealQueue.length === 0) {
                    return;
                }
                await new Promise((r) => window.setTimeout(r, 40));
            }
        },

        async fetchSource(source: string) {
            try {
                const url = new URL(this.endpoint, window.location.origin);
                url.searchParams.set('q', this.query);
                url.searchParams.set('source', source);
                url.searchParams.set('limit', source === 'heureka' ? '16' : '12');
                url.searchParams.set('page', '1');
                const res = await fetch(url.toString(), {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                });
                if (!res.ok) {
                    return;
                }
                const json = (await res.json()) as { data?: SearchResponse } & SearchResponse;
                const data = (json.data && typeof json.data === 'object' ? json.data : json) as SearchResponse;
                if (Array.isArray(data.items) && data.items.length > 0) {
                    this.enqueueItems(data.items);
                }
            } catch {
                // ignore per-source failures
            }
        },

        enqueueItems(incoming: SearchItem[]) {
            const seen = new Set(this.items.map((item) => this.itemKey(item)));
            const queued = new Set(this.revealQueue.map((item) => this.itemKey(item)));
            const sorted = [...incoming].sort((a, b) => {
                const ap = a.min_price;
                const bp = b.min_price;
                if (ap == null && bp == null) {
                    return 0;
                }
                if (ap == null) {
                    return 1;
                }
                if (bp == null) {
                    return -1;
                }
                return Number(ap) - Number(bp);
            });
            for (const row of sorted) {
                if (!row.name) {
                    continue;
                }
                const key = this.itemKey(row);
                if (seen.has(key) || queued.has(key)) {
                    continue;
                }
                seen.add(key);
                queued.add(key);
                this.revealQueue.push(row);
            }
            if (this.reduceMotion) {
                this.flushReveal();
            } else {
                this.pumpReveal();
            }
        },

        flushReveal() {
            if (this.revealQueue.length === 0) {
                return;
            }
            this.items = [...this.items, ...this.revealQueue];
            this.revealQueue = [];
            this.revealing = false;
            this.sortItems();
        },

        pumpReveal() {
            if (this.revealing) {
                return;
            }
            this.revealing = true;
            const tick = () => {
                const next = this.revealQueue.shift();
                if (!next) {
                    this.revealing = false;
                    this.sortItems();
                    return;
                }
                const key = this.itemKey(next);
                this.enterKeys = { ...this.enterKeys, [key]: true };
                this.items = [...this.items, next];
                this.sortItems();
                if (this.revealQueue.length > 0) {
                    window.setTimeout(tick, REVEAL_MS);
                } else {
                    this.revealing = false;
                }
            };
            tick();
        },

        sortItems() {
            this.items = [...this.items].sort((a, b) => {
                const ap = a.min_price;
                const bp = b.min_price;
                if (ap == null && bp == null) {
                    return 0;
                }
                if (ap == null) {
                    return 1;
                }
                if (bp == null) {
                    return -1;
                }
                return Number(ap) - Number(bp);
            });
        },
    }));
}
