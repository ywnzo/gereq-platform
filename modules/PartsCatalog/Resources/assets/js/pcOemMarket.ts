import type Alpine from '@alpinejs/csp';

type MarketItem = {
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
    description?: string | null;
    parameters?: Record<string, unknown> | string[] | null;
};

type SearchResponse = {
    ok?: boolean;
    items?: MarketItem[];
    error?: string | null;
};

const TOP_N = 10;

export function registerPcOemMarket(alpine: typeof Alpine): void {
    alpine.data('pcOemMarket', () => ({
        openOem: '',
        query: '',
        oemCode: '',
        endpoint: '',
        offersEndpoint: '',
        orderId: '',
        attachUrl: '',
        canAttach: false,
        attachingKey: '',
        attachedKeys: {} as Record<string, true>,
        attachQty: {} as Record<string, number>,
        sources: [] as string[],
        items: [] as MarketItem[],
        loading: false,
        cache: {} as Record<string, MarketItem[]>,
        currencyCode: 'CZK',
        currencySymbol: 'Kč',
        labels: {
            loading: 'Načítám…',
            empty: 'Nic nenalezeno',
            open: 'Otevřít',
            title: 'Nabídky',
            close: 'Zavřít',
            attach: 'Přidat na zakázku',
            attached: 'Přidáno',
        },

        /** Autodíly e-shopy: čisté OEM (AutoKelly dostane „OEM {kód}“ v queryForSource). */
        shopSources: ['jama', 'autodilypro'] as string[],

        init() {
            const el = this.$el as HTMLElement;
            this.endpoint = el.dataset.endpoint || '';
            this.offersEndpoint = el.dataset.offersEndpoint || '';
            this.orderId = (el.dataset.orderId || '').trim();
            this.attachUrl = (el.dataset.attachUrl || '').trim();
            this.canAttach = el.dataset.canAttach === '1' && this.orderId !== '' && this.attachUrl !== '';
            this.currencyCode = el.dataset.currencyCode || this.currencyCode;
            this.currencySymbol = el.dataset.currencySymbol || this.currencySymbol;
            this.labels = {
                loading: el.dataset.labelLoading || this.labels.loading,
                empty: el.dataset.labelEmpty || this.labels.empty,
                open: el.dataset.labelOpen || this.labels.open,
                title: el.dataset.labelTitle || this.labels.title,
                close: el.dataset.labelClose || this.labels.close,
                attach: el.dataset.labelAttach || this.labels.attach,
                attached: el.dataset.labelAttached || this.labels.attached,
            };
            try {
                this.sources = JSON.parse(el.dataset.sources || '[]') as string[];
            } catch {
                this.sources = [];
            }
        },

        buildQuery(name: string, brand: string, oem: string): string {
            const parts: string[] = [];
            const nameTrim = name.trim();
            const brandTrim = brand.trim();
            const oemTrim = oem.trim();
            const nameLower = nameTrim.toLowerCase();
            if (nameTrim !== '') {
                parts.push(nameTrim);
            }
            if (brandTrim !== '' && !nameLower.includes(brandTrim.toLowerCase())) {
                parts.push(brandTrim);
            }
            if (oemTrim !== '' && !nameLower.includes(oemTrim.toLowerCase())) {
                parts.push(oemTrim);
            }
            return parts.join(' ').trim() || oemTrim;
        },

        queryForSource(source: string): string {
            if (source === 'autokelly' && this.oemCode !== '') {
                return `OEM ${this.oemCode}`;
            }
            if (this.shopSources.includes(source) && this.oemCode !== '') {
                return this.oemCode;
            }
            return this.query;
        },

        isOpen(oem: string): boolean {
            return this.openOem === oem;
        },

        lupaClass(oem: string): string {
            return this.isOpen(oem) ? 'pc-oem-lupa is-open' : 'pc-oem-lupa';
        },

        closeOffers() {
            this.openOem = '';
            this.query = '';
            this.oemCode = '';
        },

        async toggleFromBtn(event: Event) {
            const btn = event.currentTarget as HTMLElement | null;
            const code = (btn?.dataset.oem || '').trim();
            if (code === '') {
                return;
            }
            if (this.openOem === code) {
                this.closeOffers();
                return;
            }
            this.openOem = code;
            this.oemCode = code;
            this.query = this.buildQuery(
                btn?.dataset.name || '',
                btn?.dataset.brand || '',
                code,
            );
            if (this.cache[code]) {
                this.items = this.cache[code];
                return;
            }
            this.items = [];
            await this.load(code);
        },

        async load(oem: string) {
            if (!this.endpoint || this.sources.length === 0) {
                return;
            }
            this.loading = true;
            try {
                const batches = await Promise.all(this.sources.map((source) => this.fetchSource(source)));
                const merged = this.mergeBest(batches.flat());
                this.cache[oem] = merged;
                if (this.openOem === oem) {
                    this.items = merged;
                }
            } finally {
                this.loading = false;
            }
        },

        async fetchSource(source: string): Promise<MarketItem[]> {
            try {
                const url = new URL(this.endpoint, window.location.origin);
                url.searchParams.set('q', this.queryForSource(source));
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
                    return [];
                }
                const json = (await res.json()) as { data?: SearchResponse } & SearchResponse;
                const data = (json.data && typeof json.data === 'object' ? json.data : json) as SearchResponse;
                return Array.isArray(data.items) ? data.items : [];
            } catch {
                return [];
            }
        },

        mergeBest(incoming: MarketItem[]): MarketItem[] {
            const seen = new Set<string>();
            const rows: MarketItem[] = [];
            const oemNorm = this.oemCode.replace(/[\s.\-\/]/g, '').toLowerCase();
            const withName = incoming.filter((row) => !!row.name && String(row.name).trim() !== '');
            const matched = oemNorm
                ? withName.filter((row) => this.matchesOem(row, oemNorm))
                : withName;
            // Když OEM sedí aspoň u některých výsledků, zbytek (nesouvisející) zahodíme.
            const pool = matched.length > 0 ? matched : withName;
            const sorted = [...pool].sort((a, b) => {
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
                // Odfiltruj zjevně rozbitý scrape (např. 2–4 Kč místo 2 682 Kč).
                if (row.min_price != null && Number(row.min_price) > 0 && Number(row.min_price) < 15) {
                    continue;
                }
                const key = [
                    (row.name || '').trim().toLowerCase(),
                    row.source || '',
                    row.url || row.id || '',
                ].join('|');
                if (seen.has(key)) {
                    continue;
                }
                seen.add(key);
                rows.push(row);
                if (rows.length >= TOP_N) {
                    break;
                }
            }
            return rows;
        },

        matchesOem(item: MarketItem, oemNorm: string): boolean {
            if (oemNorm === '') {
                return true;
            }
            const hay = `${item.name || ''} ${item.description || ''} ${JSON.stringify(item.parameters || {})}`
                .replace(/[\s.\-\/]/g, '')
                .toLowerCase();
            return hay.includes(oemNorm);
        },

        get showLoading(): boolean {
            return this.loading && this.items.length === 0;
        },

        get showEmpty(): boolean {
            return !this.loading && this.items.length === 0 && this.openOem !== '';
        },

        get showTable(): boolean {
            return this.items.length > 0;
        },

        get resultsTitle(): string {
            return `${this.labels.title} · ${this.query}${this.items.length ? ` · ${this.items.length}` : ''}`;
        },

        hasImage(item: MarketItem): boolean {
            return !!item.image;
        },

        itemName(item: MarketItem): string {
            return String(item.name || '').trim();
        },

        itemSource(item: MarketItem): string {
            return String(item.source || '').trim() || '—';
        },

        itemBrand(item: MarketItem): string {
            return String(item.brand || '').trim();
        },

        itemImage(item: MarketItem): string {
            return String(item.image || '');
        },

        itemUrl(item: MarketItem): string {
            return String(item.url || '');
        },

        hasNameLink(item: MarketItem): boolean {
            return this.hasUrl(item);
        },

        hasNamePlain(item: MarketItem): boolean {
            return !this.hasUrl(item) && this.itemName(item) !== '';
        },

        hasBrand(item: MarketItem): boolean {
            return this.itemBrand(item) !== '';
        },

        hasPrice(item: MarketItem): boolean {
            return item.min_price != null && !Number.isNaN(Number(item.min_price));
        },

        hasUrl(item: MarketItem): boolean {
            return !!(item.url && String(item.url).trim());
        },

        hasOffersHover(item: MarketItem): boolean {
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

        shopLabel(item: MarketItem): string {
            return (item.shop && String(item.shop).trim()) || item.source || '';
        },

        hasShopPlain(item: MarketItem): boolean {
            const source = String(item.source || '');
            // U e-shopů (jama/autodilypro) zdroj do UI necpeme — jen výrobce + cena.
            if (this.shopSources.includes(source)) {
                return false;
            }
            return !!this.shopLabel(item) && !this.hasOffersHover(item);
        },

        formatPrice(item: MarketItem): string {
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

        itemKey(item: MarketItem, index = 0): string {
            return `${item.name}|${item.source}|${item.url || item.id || index}`;
        },

        isAttached(item: MarketItem, index = 0): boolean {
            return !!this.attachedKeys[this.itemKey(item, index)];
        },

        isAttaching(item: MarketItem, index = 0): boolean {
            return this.attachingKey === this.itemKey(item, index);
        },

        attachLabel(item: MarketItem, index = 0): string {
            return this.isAttached(item, index) ? this.labels.attached : this.labels.attach;
        },

        qtyFor(item: MarketItem, index = 0): number {
            const key = this.itemKey(item, index);
            const n = this.attachQty[key];

            return n != null && n >= 1 ? n : 1;
        },

        setQty(item: MarketItem, index = 0, raw: string | number) {
            const parsed = Math.floor(Number(raw));
            const n = Number.isFinite(parsed) ? Math.max(1, Math.min(9999, parsed)) : 1;
            this.attachQty = { ...this.attachQty, [this.itemKey(item, index)]: n };
        },

        async attachItem(item: MarketItem, index = 0) {
            if (!this.canAttach || !item.name || this.isAttaching(item, index) || this.isAttached(item, index)) {
                return;
            }
            const key = this.itemKey(item, index);
            const csrf =
                (window as Window & { __GEREQ_CSRF__?: string }).__GEREQ_CSRF__ ||
                (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content ||
                '';
            const body = new URLSearchParams();
            body.set('_csrf', csrf);
            body.set('order_id', this.orderId);
            body.set('name', String(item.name).trim());
            body.set('brand', String(item.brand || '').trim());
            body.set('oem', this.oemCode);
            body.set('qty', String(this.qtyFor(item, index)));
            // Zdroj (Heureka / e-shop) záměrně neukládáme — jen výrobce, kusy, cena.
            body.set('source', '');
            if (item.min_price != null && !Number.isNaN(Number(item.min_price))) {
                body.set('unit_price', String(item.min_price));
            }

            this.attachingKey = key;
            try {
                const res = await fetch(this.attachUrl, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrf,
                    },
                    credentials: 'same-origin',
                    body: body.toString(),
                });
                if (!res.ok) {
                    return;
                }
                const json = (await res.json().catch(() => null)) as { ok?: boolean } | null;
                if (json && json.ok === false) {
                    return;
                }
                this.attachedKeys = { ...this.attachedKeys, [key]: true };
            } finally {
                this.attachingKey = '';
            }
        },
    }));
}
