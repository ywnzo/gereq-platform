import type Alpine from '@alpinejs/csp';

type MarketItem = {
    id: string | number;
    name: string;
    url: string;
    min_price: number | null;
    max_price: number | null;
    currency: string;
    shop: string | null;
    shop_count?: number | null;
    source: string;
    image: string | null;
    description: string | null;
    brand: string | null;
    parameters: string[];
    rating: number | null;
    rating_count: number;
    availability: string | null;
    price_label?: string | null;
    money?: { localized?: string } | null;
};

type MarketSourceResponse = {
    ok: boolean;
    source: string;
    items: MarketItem[];
    error: string | null;
    sources: string[];
    pending_sources: string[];
    complete: boolean;
    cached_at: string | null;
    has_more?: boolean;
    page?: number;
};

const STAGGER_MS = 55;
const MARKET_LIMIT_BASE = 40;
const MARKET_LIMIT_STEP = 40;
const EUR_TO_CZK = 25;

type PriceRange = { id: string; min: number | null; max: number | null; label: string };

export function registerBsMarket(alpine: typeof Alpine): void {
    alpine.data('bsMarket', () => ({
        open: null as string | null,
        items: [] as MarketItem[],
        sources: [] as string[],
        pending: [] as string[],
        loading: false,
        loadingMore: false,
        page: 1,
        hasMore: true,
        query: '',
        taxonomy: '',
        endpoint: '',
        offersEndpoint: '',
        forceFresh: false,
        filterBrands: [] as string[],
        filterPrice: '',
        brandSelectOpen: false,
        brandSearch: '',
        seedBrands: [] as string[],
        priceRanges: [] as PriceRange[],
        _onBrandPointer: null as ((event: PointerEvent) => void) | null,
        _onBrandKeydown: null as ((event: KeyboardEvent) => void) | null,
        labels: {
            loading: 'Loading offers…',
            empty: 'No results',
            inStock: 'In stock',
            offer: 'Offer',
            detail: 'Product details',
            hide: 'Hide details',
            cached: '',
            hint: '',
            refresh: 'Find new',
            more: 'Find more',
            brandAll: 'All brands',
            brandMore: 'More brands…',
            brandSelected: 'selected',
            brandSearch: 'Search…',
            priceAll: 'Any price',
        },
        currencyCode: 'CZK',
        currencySymbol: 'Kč',
        heurekaHint: false,
        allSources: [] as string[],
        enterKeys: {} as Record<string, boolean>,
        revealQueue: [] as MarketItem[],
        revealing: false,
        reduceMotion: false,
        skeletons: [0, 1, 2] as number[],

        init() {
            const el = this.$el as HTMLElement;
            this.reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            this.query = el.dataset.query || '';
            this.taxonomy = el.dataset.taxonomy || '';
            this.endpoint = el.dataset.endpoint || '';
            this.offersEndpoint = el.dataset.offersEndpoint || '';
            this.forceFresh = el.dataset.forceFresh === '1';
            this.currencyCode = el.dataset.currencyCode || this.currencyCode;
            this.currencySymbol = el.dataset.currencySymbol || this.currencySymbol;
            this.heurekaHint = false;
            this.labels = {
                loading: el.dataset.labelLoading || this.labels.loading,
                empty: el.dataset.labelEmpty || this.labels.empty,
                inStock: el.dataset.labelInStock || this.labels.inStock,
                offer: el.dataset.labelOffer || this.labels.offer,
                detail: el.dataset.labelDetail || this.labels.detail,
                hide: el.dataset.labelHide || this.labels.hide,
                cached: '',
                hint: '',
                refresh: el.dataset.labelRefresh || this.labels.refresh,
                more: el.dataset.labelMore || this.labels.more,
                brandAll: el.dataset.labelBrandAll || this.labels.brandAll,
                brandMore: el.dataset.labelBrandMore || this.labels.brandMore,
                brandSelected: el.dataset.labelBrandSelected || this.labels.brandSelected,
                brandSearch: el.dataset.labelBrandSearch || this.labels.brandSearch,
                priceAll: el.dataset.labelPriceAll || this.labels.priceAll,
            };
            this.priceRanges = [
                { id: '', min: null, max: null, label: this.labels.priceAll },
                { id: '0-500', min: 0, max: 500, label: el.dataset.labelPrice0 || `Up to 500 ${this.currencySymbol}` },
                { id: '500-1500', min: 500, max: 1500, label: el.dataset.labelPrice1 || `500–1 500 ${this.currencySymbol}` },
                { id: '1500-4000', min: 1500, max: 4000, label: el.dataset.labelPrice2 || `1 500–4 000 ${this.currencySymbol}` },
                { id: '4000-10000', min: 4000, max: 10000, label: el.dataset.labelPrice3 || `4 000–10 000 ${this.currencySymbol}` },
                { id: '10000+', min: 10000, max: null, label: el.dataset.labelPrice4 || `10 000 ${this.currencySymbol}+` },
            ];
            try {
                this.seedBrands = JSON.parse(el.dataset.seedBrands || '[]') as string[];
            } catch {
                this.seedBrands = [];
            }
            try {
                this.filterBrands = JSON.parse(el.dataset.selectedBrands || '[]') as string[];
            } catch {
                this.filterBrands = [];
            }
            this.filterPrice = el.dataset.selectedPrice || '';
            try {
                this.items = JSON.parse(el.dataset.items || '[]') as MarketItem[];
            } catch {
                this.items = [];
            }
            try {
                this.sources = JSON.parse(el.dataset.sources || '[]') as string[];
            } catch {
                this.sources = [];
            }
            try {
                this.pending = JSON.parse(el.dataset.pending || '[]') as string[];
            } catch {
                this.pending = [];
            }
            try {
                this.allSources = JSON.parse(el.dataset.allSources || '[]') as string[];
            } catch {
                this.allSources = [];
            }
            this.hasMore = this.allSources.includes('heureka') || this.pending.includes('heureka');
            this.sortItems();
            this.bindBrandSelectClose();
            if (this.pending.length > 0 && this.query && this.endpoint) {
                void this.loadPending(this.forceFresh);
            }
        },

        destroy() {
            this.unbindBrandSelectClose();
        },

        bindBrandSelectClose() {
            this.unbindBrandSelectClose();
            const onPointer = (event: PointerEvent) => {
                if (!this.brandSelectOpen) {
                    return;
                }
                const select = (this.$el as HTMLElement).querySelector('.bs-catalog__brand-select');
                if (select && !select.contains(event.target as Node)) {
                    this.closeBrandSelect();
                }
            };
            const onKeydown = (event: KeyboardEvent) => {
                if (!this.brandSelectOpen) {
                    return;
                }
                if (event.key === 'Escape') {
                    event.preventDefault();
                    this.closeBrandSelect();
                }
            };
            document.addEventListener('pointerdown', onPointer);
            document.addEventListener('keydown', onKeydown);
            this._onBrandPointer = onPointer;
            this._onBrandKeydown = onKeydown;
        },

        unbindBrandSelectClose() {
            if (this._onBrandPointer) {
                document.removeEventListener('pointerdown', this._onBrandPointer);
                this._onBrandPointer = null;
            }
            if (this._onBrandKeydown) {
                document.removeEventListener('keydown', this._onBrandKeydown);
                this._onBrandKeydown = null;
            }
        },

        closeBrandSelect() {
            this.brandSelectOpen = false;
            this.brandSearch = '';
        },

        get hasItems(): boolean {
            return this.items.length > 0;
        },

        get filteredItems(): MarketItem[] {
            return this.items.filter((item) => this.matchesFilters(item));
        },

        get brandOptions(): string[] {
            const seen: Record<string, string> = {};
            for (const brand of this.seedBrands) {
                const key = brand.trim().toLowerCase();
                if (key && !seen[key]) {
                    seen[key] = brand.trim();
                }
            }
            for (const item of this.items) {
                const brand = (item.brand || '').trim();
                if (!brand) {
                    continue;
                }
                const key = brand.toLowerCase();
                if (!seen[key]) {
                    seen[key] = brand;
                }
            }
            return Object.values(seen).sort((a, b) => a.localeCompare(b, 'cs', { sensitivity: 'base' }));
        },

        get topBrands(): string[] {
            return this.brandOptions.slice(0, 3);
        },

        get moreBrands(): string[] {
            return this.brandOptions.slice(3);
        },

        get filteredMoreBrands(): string[] {
            const q = this.brandSearch.trim().toLowerCase();
            if (q === '') {
                return this.moreBrands;
            }

            return this.moreBrands.filter((b) => b.toLowerCase().includes(q));
        },

        get brandSelectLabel(): string {
            const selected = this.filterBrands.filter((b) => this.isMoreBrand(b));
            if (selected.length === 0) {
                return this.labels.brandMore;
            }
            if (selected.length === 1) {
                return selected[0];
            }

            return `${selected.length} ${this.labels.brandSelected}`;
        },

        get brandSelectClass(): string {
            return this.brandSelectOpen ? 'g-select__trigger is-open' : 'g-select__trigger';
        },

        get brandListClass(): string {
            return this.brandSelectOpen ? 'g-select__list is-open' : 'g-select__list';
        },

        get showSkeletons(): boolean {
            return this.loading && !this.hasItems;
        },

        get showEmpty(): boolean {
            return this.filteredItems.length === 0 && !this.loading && !this.revealing && !this.loadingMore;
        },

        get showHeurekaHint(): boolean {
            return false;
        },

        get marketClass(): string {
            return this.loading || this.loadingMore ? 'is-loading' : '';
        },

        get canLoadMore(): boolean {
            return Boolean(this.query && this.endpoint && this.hasMore && !this.loading && !this.loadingMore);
        },

        get marketLimit(): number {
            return MARKET_LIMIT_BASE + Math.max(0, this.page - 1) * MARKET_LIMIT_STEP;
        },

        get noBrandsSelected(): boolean {
            return this.filterBrands.length === 0;
        },

        get hasMoreBrands(): boolean {
            return this.moreBrands.length > 0;
        },

        get brandSelectExpanded(): string {
            return this.brandSelectOpen ? 'true' : 'false';
        },

        get showBrandSelectEmpty(): boolean {
            return this.filteredMoreBrands.length === 0;
        },

        get brandAllClass(): string {
            return this.noBrandsSelected ? 'is-active' : '';
        },

        brandNavClass(brand: string): string {
            return this.isBrandActive(brand) ? 'is-active' : '';
        },

        brandOptionClass(brand: string): string {
            return this.isBrandActive(brand) ? 'is-selected' : '';
        },

        brandCheckClass(brand: string): string {
            return this.isBrandActive(brand) ? 'is-on' : '';
        },

        priceNavClass(id: string): string {
            return this.filterPrice === id ? 'is-active' : '';
        },

        brandsParam(): string {
            return this.filterBrands.join(',');
        },

        syncFiltersToUrl() {
            const url = new URL(window.location.href);
            const brands = this.brandsParam();
            if (brands !== '') {
                url.searchParams.set('brands', brands);
            } else {
                url.searchParams.delete('brands');
            }
            if (this.filterPrice !== '') {
                url.searchParams.set('price', this.filterPrice);
            } else {
                url.searchParams.delete('price');
            }
            window.history.replaceState({}, '', url.toString());

            const root = this.$el as HTMLElement;
            const brandsField = root.querySelector<HTMLInputElement>('input[name="brands"]');
            const priceField = root.querySelector<HTMLInputElement>('input[name="price"]');
            if (brandsField) {
                brandsField.value = brands;
            }
            if (priceField) {
                priceField.value = this.filterPrice;
            }
        },

        keepFiltersNavigate(event: MouseEvent) {
            const target = event.currentTarget;
            if (!(target instanceof HTMLAnchorElement)) {
                return;
            }
            event.preventDefault();
            const url = new URL(target.href, window.location.origin);
            const brands = this.brandsParam();
            if (brands !== '') {
                url.searchParams.set('brands', brands);
            } else {
                url.searchParams.delete('brands');
            }
            if (this.filterPrice !== '') {
                url.searchParams.set('price', this.filterPrice);
            } else {
                url.searchParams.delete('price');
            }
            window.location.assign(url.toString());
        },

        clearBrands() {
            this.filterBrands = [];
            this.syncFiltersToUrl();
        },

        toggleBrand(brand: string) {
            const key = brand.toLowerCase();
            const exists = this.filterBrands.some((b) => b.toLowerCase() === key);
            if (exists) {
                this.filterBrands = this.filterBrands.filter((b) => b.toLowerCase() !== key);
            } else {
                this.filterBrands = [...this.filterBrands, brand];
            }
            this.syncFiltersToUrl();
        },

        setPrice(id: string) {
            this.filterPrice = this.filterPrice === id ? '' : id;
            this.syncFiltersToUrl();
        },

        isBrandActive(brand: string): boolean {
            const key = brand.toLowerCase();

            return this.filterBrands.some((b) => b.toLowerCase() === key);
        },

        isMoreBrand(brand: string): boolean {
            const key = brand.toLowerCase();

            return this.moreBrands.some((b) => b.toLowerCase() === key);
        },

        toggleBrandSelect(event?: Event) {
            if (event instanceof Event) {
                event.preventDefault();
                event.stopPropagation();
            }
            this.brandSelectOpen = !this.brandSelectOpen;
            if (this.brandSelectOpen) {
                this.brandSearch = '';
                queueMicrotask(() => {
                    const input = (this.$el as HTMLElement).querySelector<HTMLInputElement>('.bs-catalog__brand-search');
                    input?.focus();
                });
            } else {
                this.brandSearch = '';
            }
        },

        onBrandSearch(event: Event) {
            const t = event.target;
            if (t instanceof HTMLInputElement) {
                this.brandSearch = t.value;
            }
            if (event instanceof KeyboardEvent && event.key === 'Escape') {
                event.preventDefault();
                this.closeBrandSelect();
            }
        },

        matchesFilters(item: MarketItem): boolean {
            if (this.filterBrands.length > 0) {
                const brand = (item.brand || '').toLowerCase();
                const name = (item.name || '').toLowerCase();
                const hit = this.filterBrands.some((want) => {
                    const w = want.toLowerCase();

                    return brand.includes(w) || name.includes(w);
                });
                if (!hit) {
                    return false;
                }
            }
            if (this.filterPrice) {
                const range = this.priceRanges.find((r) => r.id === this.filterPrice);
                if (range && (range.min !== null || range.max !== null)) {
                    if (item.min_price === null || item.min_price === undefined) {
                        return false;
                    }
                    const czk = item.currency === 'EUR'
                        ? Number(item.min_price) * EUR_TO_CZK
                        : Number(item.min_price);
                    if (range.min !== null && czk < range.min) {
                        return false;
                    }
                    if (range.max !== null && czk >= range.max) {
                        return false;
                    }
                }
            }

            return true;
        },

        itemKey(item: MarketItem, _index = 0): string {
            const name = (item.name || '').trim().toLowerCase();
            const source = item.source || '';
            const id = item.id != null && String(item.id) !== ''
                ? String(item.id)
                : (item.url || '');

            return `${name}|${source}|${id}`;
        },

        isEntering(item: MarketItem, index: number): boolean {
            return Boolean(this.enterKeys[this.itemKey(item, index)]);
        },

        itemClass(item: MarketItem, index: number): string {
            return this.isEntering(item, index) ? 'bs-market__item is-enter' : 'bs-market__item';
        },

        clearEnter(item: MarketItem, index: number) {
            const key = this.itemKey(item, index);
            if (this.enterKeys[key]) {
                const next = { ...this.enterKeys };
                delete next[key];
                this.enterKeys = next;
            }
        },

        isOpen(item: MarketItem, index: number): boolean {
            return this.open === this.itemKey(item, index);
        },

        isClosed(item: MarketItem, index: number): boolean {
            return this.open !== this.itemKey(item, index);
        },

        hasDetail(item: MarketItem): boolean {
            return this.hasDescription(item) || this.hasParameters(item);
        },

        hasImage(item: MarketItem): boolean {
            return Boolean(item.image);
        },

        hasBrand(item: MarketItem): boolean {
            return Boolean(item.brand);
        },

        hasShop(item: MarketItem): boolean {
            return Boolean(item.shop);
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
            const shop = String(item.shop || '').toLowerCase();
            return shop.includes('obchod');
        },

        shopLabel(item: MarketItem): string {
            return (item.shop && String(item.shop).trim()) || item.source || '';
        },

        hasDescription(item: MarketItem): boolean {
            return Boolean(item.description);
        },

        hasParameters(item: MarketItem): boolean {
            return Array.isArray(item.parameters) && item.parameters.length > 0;
        },

        hasMaxPrice(item: MarketItem): boolean {
            return this.formatMaxPrice(item) !== '';
        },

        hasRating(item: MarketItem): boolean {
            return this.formatRating(item) !== '';
        },

        isInStock(item: MarketItem): boolean {
            return item.availability === 'in_stock';
        },

        sourceLabel(item: MarketItem): string {
            return item.source || '—';
        },

        toggle(item: MarketItem, index: number) {
            const id = this.itemKey(item, index);
            this.open = this.open === id ? null : id;
        },

        hideBrokenImage(event: Event) {
            const el = event.target;
            if (el instanceof HTMLElement) {
                el.style.display = 'none';
            }
        },

        formatPrice(item: MarketItem): string {
            if (item.price_label) {
                return item.price_label;
            }
            if (item.money && typeof item.money === 'object' && item.money.localized) {
                return String(item.money.localized);
            }
            if (item.min_price === null || item.min_price === undefined) {
                return '—';
            }
            const n = Number(item.min_price);
            if (item.currency === 'EUR') {
                return `${n.toLocaleString('cs-CZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} €`;
            }
            const label = (item.currency || this.currencyCode) === this.currencyCode
                ? this.currencySymbol
                : (item.currency || this.currencyCode);

            return `${Math.round(n).toLocaleString('cs-CZ')} ${label}`;
        },

        formatMaxPrice(item: MarketItem): string {
            if (
                item.max_price === null
                || item.min_price === null
                || item.max_price <= item.min_price
                || item.currency === 'EUR'
            ) {
                return '';
            }

            return `– ${Math.round(Number(item.max_price)).toLocaleString('cs-CZ')} ${this.currencySymbol}`;
        },

        formatRating(item: MarketItem): string {
            if (item.rating === null || !item.rating_count) {
                return '';
            }
            const stars = (Number(item.rating) / 20).toLocaleString('cs-CZ', {
                minimumFractionDigits: 1,
                maximumFractionDigits: 1,
            });

            return `★ ${stars} (${item.rating_count})`;
        },

        descPreview(item: MarketItem): string {
            const d = item.description || '';
            if (d.length <= 140) {
                return d;
            }

            return `${d.slice(0, 140)}…`;
        },

        initial(item: MarketItem): string {
            const name = (item.name || '?').trim();

            return name ? name.charAt(0).toUpperCase() : '?';
        },

        sortItems() {
            this.items = [...this.items].sort((a, b) => {
                const ca = a.currency === this.currencyCode ? 0 : 1;
                const cb = b.currency === this.currencyCode ? 0 : 1;
                if (ca !== cb) {
                    return ca - cb;
                }
                const pa = a.min_price;
                const pb = b.min_price;
                if (pa === null && pb === null) {
                    return 0;
                }
                if (pa === null) {
                    return 1;
                }
                if (pb === null) {
                    return -1;
                }

                return pa - pb;
            }).slice(0, this.marketLimit);
        },

        seenKeys(): Set<string> {
            return new Set(this.items.map((item) => this.itemKey(item)));
        },

        enqueueItems(incoming: MarketItem[], animate: boolean) {
            const seen = this.seenKeys();
            const queued = new Set(this.revealQueue.map((item) => this.itemKey(item)));
            const sortedIn = [...incoming].sort((a, b) => {
                const ca = a.currency === this.currencyCode ? 0 : 1;
                const cb = b.currency === this.currencyCode ? 0 : 1;
                if (ca !== cb) {
                    return ca - cb;
                }
                const pa = a.min_price;
                const pb = b.min_price;
                if (pa === null && pb === null) {
                    return 0;
                }
                if (pa === null) {
                    return 1;
                }
                if (pb === null) {
                    return -1;
                }

                return pa - pb;
            });
            for (const row of sortedIn) {
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
            if (animate && !this.reduceMotion) {
                this.pumpReveal();
            } else {
                this.flushRevealImmediate();
            }
        },

        mergeItems(incoming: MarketItem[]) {
            this.enqueueItems(incoming, true);
        },

        flushRevealImmediate() {
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
            const step = () => {
                const next = this.revealQueue.shift();
                if (!next) {
                    this.revealing = false;

                    return;
                }
                const key = this.itemKey(next);
                this.enterKeys = { ...this.enterKeys, [key]: true };
                this.items = [...this.items, next];
                this.sortItems();
                if (this.revealQueue.length > 0) {
                    window.setTimeout(step, STAGGER_MS);
                } else {
                    this.revealing = false;
                }
            };
            step();
        },

        async loadPending(fresh = false) {
            const pending = [...this.pending];
            if (pending.length === 0) {
                return;
            }
            this.loading = true;
            try {
                if (fresh) {
                    const [first, ...rest] = pending;
                    await this.fetchSource(first, true, 1);
                    if (rest.length > 0) {
                        await Promise.all(rest.map((source) => this.fetchSource(source, false, 1)));
                    }
                } else {
                    await Promise.all(pending.map((source) => this.fetchSource(source, false, 1)));
                }
                await this.waitForReveal();
            } finally {
                this.loading = false;
                this.pending = [];
            }
        },

        async waitForReveal() {
            for (let i = 0; i < 200; i++) {
                if (!this.revealing && this.revealQueue.length === 0) {
                    return;
                }
                await new Promise((resolve) => window.setTimeout(resolve, 40));
            }
        },

        async refresh() {
            if (!this.query || !this.endpoint || this.loading || this.loadingMore) {
                return;
            }
            const sources = this.allSources.length > 0 ? [...this.allSources] : [...this.pending];
            if (sources.length === 0) {
                return;
            }
            this.items = [];
            this.sources = [];
            this.enterKeys = {};
            this.revealQueue = [];
            this.revealing = false;
            this.page = 1;
            this.hasMore = sources.includes('heureka');
            this.pending = sources;
            await this.loadPending(true);
        },

        async loadMore() {
            if (!this.canLoadMore) {
                return;
            }
            const nextPage = this.page + 1;
            const sources = this.allSources.includes('heureka')
                ? ['heureka']
                : (this.allSources.length > 0 ? [...this.allSources] : []);
            if (sources.length === 0) {
                this.hasMore = false;

                return;
            }
            this.loadingMore = true;
            const before = this.items.length;
            try {
                const results = await Promise.all(sources.map((source) => this.fetchSource(source, false, nextPage)));
                this.page = nextPage;
                const anyMore = results.some((r) => r?.has_more);
                const added = this.items.length + this.revealQueue.length > before;
                await this.waitForReveal();
                this.hasMore = anyMore || (this.items.length > before);
                if (!added && !anyMore) {
                    this.hasMore = false;
                }
            } finally {
                this.loadingMore = false;
            }
        },

        async fetchSource(source: string, fresh = false, page = 1): Promise<MarketSourceResponse | null> {
            try {
                const url = new URL(this.endpoint, window.location.origin);
                url.searchParams.set('q', this.query);
                url.searchParams.set('source', source);
                url.searchParams.set('page', String(Math.max(1, page)));
                if (this.taxonomy) {
                    url.searchParams.set('taxonomy', this.taxonomy);
                }
                if (fresh) {
                    url.searchParams.set('fresh', '1');
                }
                url.searchParams.set('_', String(Date.now()));
                const res = await fetch(url.toString(), {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'Cache-Control': 'no-cache',
                    },
                    credentials: 'same-origin',
                    cache: 'no-store',
                });
                if (!res.ok) {
                    this.pending = this.pending.filter((s) => s !== source);

                    return null;
                }
                const json = (await res.json()) as { data?: MarketSourceResponse } & MarketSourceResponse;
                const data = (json.data && typeof json.data === 'object' ? json.data : json) as MarketSourceResponse;
                if (Array.isArray(data.items) && data.items.length > 0) {
                    this.mergeItems(data.items);
                }
                if (Array.isArray(data.sources) && data.sources.length > 0) {
                    this.sources = Array.from(new Set([...this.sources, ...data.sources]));
                } else if (data.ok && !this.sources.includes(source)) {
                    this.sources = [...this.sources, source];
                }
                this.pending = this.pending.filter((s) => s !== source);
                if (source === 'heureka' && data.has_more === false && page > 1) {
                    this.hasMore = false;
                } else if (source === 'heureka' && data.has_more === true) {
                    this.hasMore = true;
                }

                return data;
            } catch {
                this.pending = this.pending.filter((s) => s !== source);

                return null;
            }
        },
    }));
}
