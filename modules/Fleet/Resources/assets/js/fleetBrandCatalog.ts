import type Alpine from '@alpinejs/csp';

export function registerFleetBrandCatalog(alpine: typeof Alpine): void {
    alpine.data('fleetBrandCatalog', () => ({
        query: '',
        items: [] as Array<{ slug: string; name: string; logo: string | null; model_count: number }>,
        loading: false,
        open: false,
        activeSlug: '',
        timer: 0 as number | ReturnType<typeof setTimeout>,
        abort: null as AbortController | null,

        get listClass(): string {
            return this.open && (this.items.length > 0 || this.loading || this.empty) ? 'is-open' : '';
        },

        get empty(): boolean {
            return this.open && !this.loading && this.items.length === 0 && this.query.trim().length >= 1;
        },

        onInput() {
            clearTimeout(this.timer as number);
            const q = this.query.trim();
            if (q.length < 1) {
                this.items = [];
                this.open = false;

                return;
            }
            this.timer = setTimeout(() => {
                void this.search();
            }, 220);
        },

        async search() {
            const q = this.query.trim();
            if (q.length < 1) {
                return;
            }
            this.abort?.abort();
            this.abort = new AbortController();
            this.loading = true;
            this.open = true;
            try {
                const url = (window as unknown as { __GEREQ_FLEET_BRAND_CATALOG__?: string }).__GEREQ_FLEET_BRAND_CATALOG__
                    ?? '/admin/fleet/brands/catalog';
                const res = await fetch(`${url}?q=${encodeURIComponent(q)}`, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    signal: this.abort.signal,
                });
                const json = await res.json();
                this.items = Array.isArray(json.data) ? json.data : [];
                this.activeSlug = this.items[0]?.slug || '';
            } catch (err) {
                if ((err as Error).name !== 'AbortError') {
                    this.items = [];
                }
            } finally {
                this.loading = false;
            }
        },

        pick(item: { slug: string; name: string }) {
            this.query = item.name;
            this.items = [];
            this.open = false;
            const input = this.$refs.query as HTMLInputElement | undefined;
            if (input) {
                input.value = item.name;
                input.dispatchEvent(new Event('input', { bubbles: true }));
            }
        },

        onKeydown(event: KeyboardEvent) {
            if (!this.open || this.items.length === 0) {
                return;
            }
            const index = this.items.findIndex((row) => row.slug === this.activeSlug);
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                this.activeSlug = this.items[(index + 1) % this.items.length].slug;
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                this.activeSlug = this.items[(index - 1 + this.items.length) % this.items.length].slug;
            } else if (event.key === 'Enter' && this.activeSlug !== '') {
                event.preventDefault();
                const item = this.items.find((row) => row.slug === this.activeSlug);
                if (item) {
                    this.pick(item);
                }
            } else if (event.key === 'Escape') {
                this.open = false;
            }
        },
    }));
}
