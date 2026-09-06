import type Alpine from '@alpinejs/csp';

type OfferRow = {
    shop: string;
    price: number | null;
    currency?: string;
    availability?: string | null;
    shipping?: string | null;
    free_shipping?: boolean;
    url?: string | null;
    price_label?: string;
    money?: { localized?: string } | null;
    open_href?: string;
    open_label?: string;
};

type OffersResponse = {
    ok: boolean;
    offers: OfferRow[];
    error: string | null;
};

const POPOVER_WIDTH = 360;
const cache = new Map<string, OfferRow[]>();

export function registerSeOffersHover(alpine: typeof Alpine): void {
    alpine.data('seOffersHover', () => ({
        visible: false,
        loading: false,
        offers: [] as OfferRow[],
        error: null as string | null,
        left: '0px',
        top: '0px',
        endpoint: '',
        source: '',
        url: '',
        label: '',
        emptyLabel: 'Žádné nabídky',
        loadingLabel: 'Načítám…',
        priceLabel: 'Kč',
        openLabel: 'Otevřít',
        _token: 0,
        _hideTimer: 0 as number | ReturnType<typeof setTimeout>,

        init() {
            this.syncFromDataset();
        },

        syncFromDataset() {
            const el = this.$el as HTMLElement;
            this.endpoint = el.dataset.endpoint || this.endpoint || '';
            this.source = el.dataset.source || this.source || '';
            this.url = el.dataset.url || this.url || '';
            this.label = el.dataset.label || this.label || '';
            this.emptyLabel = el.dataset.labelEmpty || this.emptyLabel;
            this.loadingLabel = el.dataset.labelLoading || this.loadingLabel;
            this.priceLabel = el.dataset.labelCurrency || this.priceLabel;
            this.openLabel = el.dataset.labelOpen || this.openLabel;
        },

        canHover(): boolean {
            this.syncFromDataset();
            return this.endpoint !== '' && this.source !== '' && this.url !== '';
        },

        cacheKey(): string {
            return `${this.source}|${this.url}`;
        },

        showFor(item: { source?: string; url?: string; shop?: string } | null, endpoint: string) {
            if (!item) {
                return;
            }
            this.endpoint = endpoint || this.endpoint || '';
            this.source = String(item.source || '');
            this.url = String(item.url || '');
            this.label = (item.shop && String(item.shop).trim()) || this.source;
            this.show();
        },

        show() {
            if (!this.canHover()) {
                return;
            }
            if (this._hideTimer) {
                clearTimeout(this._hideTimer);
                this._hideTimer = 0;
            }
            this.visible = true;
            this.$nextTick(() => {
                this.ensurePanelInBody();
                this.updatePosition();
                void this.load();
            });
        },

        hide() {
            this._hideTimer = setTimeout(() => {
                this.visible = false;
                this._token += 1;
                this.loading = false;
            }, 120);
        },

        keep() {
            if (this._hideTimer) {
                clearTimeout(this._hideTimer);
                this._hideTimer = 0;
            }
            this.visible = true;
        },

        ensurePanelInBody() {
            const panel = this.$refs.panel as HTMLElement | undefined;
            if (!panel) {
                return;
            }
            const root = this.$el as HTMLElement & { _x_dataStack?: unknown[] };
            // Keep Alpine scope after reparenting (otherwise methods/labels break).
            if (root._x_dataStack) {
                (panel as HTMLElement & { _x_dataStack?: unknown[] })._x_dataStack = root._x_dataStack;
            }
            if (panel.parentElement !== document.body) {
                document.body.appendChild(panel);
            }
        },

        updatePosition() {
            const trigger = this.$refs.trigger as HTMLElement | undefined;
            const panel = this.$refs.panel as HTMLElement | undefined;
            if (!trigger) {
                return;
            }
            const rect = trigger.getBoundingClientRect();
            const height = panel?.offsetHeight ?? 280;
            let left = rect.left + rect.width / 2 - POPOVER_WIDTH / 2;
            left = Math.max(12, Math.min(left, window.innerWidth - POPOVER_WIDTH - 12));
            let top = rect.bottom + 8;
            if (top + height > window.innerHeight - 12) {
                top = Math.max(12, rect.top - height - 8);
            }
            this.left = `${left}px`;
            this.top = `${top}px`;
        },

        async load() {
            const key = this.cacheKey();
            const cached = cache.get(key);
            if (cached) {
                this.offers = cached.map((row) => this.normalizeOffer(row));
                this.error = null;
                this.loading = false;
                this.$nextTick(() => this.updatePosition());
                return;
            }
            const token = ++this._token;
            this.loading = true;
            this.error = null;
            try {
                const qs = new URLSearchParams({
                    source: this.source,
                    url: this.url,
                });
                const res = await fetch(`${this.endpoint}?${qs.toString()}`, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });
                const json = (await res.json()) as { data?: OffersResponse } & OffersResponse;
                const data = (json.data && typeof json.data === 'object' ? json.data : json) as OffersResponse;
                if (token !== this._token) {
                    return;
                }
                if (data.ok && Array.isArray(data.offers) && data.offers.length > 0) {
                    const offers = data.offers.map((row) => this.normalizeOffer(row));
                    this.offers = offers;
                    cache.set(key, offers);
                    this.error = null;
                } else {
                    this.offers = [];
                    const err = data.error && !String(data.error).startsWith('searchengines.')
                        ? data.error
                        : this.emptyLabel;
                    this.error = err;
                }
            } catch {
                if (token !== this._token) {
                    return;
                }
                this.offers = [];
                this.error = this.emptyLabel;
            } finally {
                if (token === this._token) {
                    this.loading = false;
                    this.$nextTick(() => this.updatePosition());
                }
            }
        },

        normalizeOffer(offer: OfferRow): OfferRow {
            const shipping = String(offer.shipping || '');
            const free =
                !!offer.free_shipping ||
                offer.price === 0 ||
                /zdarma|\bfree\b/i.test(shipping);
            const href = offer.url || this.url || '';
            return {
                ...offer,
                free_shipping: free,
                price_label: this.formatPrice(offer),
                open_href: href,
                open_label: this.openLabel || 'Otevřít',
            };
        },

        formatPrice(offer: OfferRow): string {
            if (offer.price_label) {
                return offer.price_label;
            }
            if (offer.money && typeof offer.money === 'object' && offer.money.localized) {
                return String(offer.money.localized);
            }
            if (offer.price == null || Number.isNaN(Number(offer.price))) {
                return '—';
            }
            const currency = offer.currency || this.priceLabel || 'CZK';
            return `${Math.round(Number(offer.price)).toLocaleString('cs-CZ')} ${currency}`;
        },

        offerKey(offer: OfferRow): string {
            return `${offer.shop}|${offer.price ?? ''}|${offer.url ?? ''}`;
        },

        offerUrl(offer: OfferRow): string {
            return offer.open_href || offer.url || this.url;
        },

        hasAvailability(offer: OfferRow): boolean {
            return !!(offer.availability && String(offer.availability).trim() !== '');
        },

        isFree(offer: OfferRow): boolean {
            return !!offer.free_shipping;
        },

        showLoading(): boolean {
            return this.loading;
        },

        showError(): boolean {
            return !this.loading && !!this.error;
        },

        showList(): boolean {
            return !this.loading && !this.error;
        },

        panelStyle(): Record<string, string> {
            return {
                left: this.left,
                top: this.top,
                width: `${POPOVER_WIDTH}px`,
            };
        },
    }));
}
