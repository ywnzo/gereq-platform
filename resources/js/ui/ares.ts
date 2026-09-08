import type Alpine from '@alpinejs/csp';

type AresSubject = {
    ico: string;
    dic: string | null;
    name: string;
    address_text: string | null;
    label: string;
};

type AresAddress = {
    key: string;
    text: string;
    label: string;
    meta: string;
    street: string | null;
    city: string | null;
    city_part: string | null;
    zip: string | null;
};

type AresComponent = {
    rootEl: HTMLElement | null;
    $el: HTMLElement;
    $refs: Record<string, HTMLElement | undefined>;
};

function componentRoot(component: AresComponent): HTMLElement {
    return component.rootEl ?? component.$el;
}

export function registerAres(alpine: typeof Alpine): void {
    alpine.data('gereqAresSubject', () => ({
        rootEl: null as HTMLElement | null,
        open: false,
        loading: false,
        query: '',
        items: [] as AresSubject[],
        error: '',
        activeIco: '',
        applying: false,
        timer: 0 as number | ReturnType<typeof setTimeout>,
        abort: null as AbortController | null,
        _onPointer: null as ((event: PointerEvent) => void) | null,

        init() {
            // $el při init = kořen x-data; při kliku na návrh by $el byl button.
            this.rootEl = this.$el as HTMLElement;
            const root = this.rootEl;
            const input = this.inputEl();
            if (input) {
                this.query = input.value;
            }
            const onPointer = (event: PointerEvent) => {
                if (!root.contains(event.target as Node)) {
                    this.close();
                }
            };
            document.addEventListener('pointerdown', onPointer);
            this._onPointer = onPointer;
        },

        destroy() {
            if (this._onPointer) {
                document.removeEventListener('pointerdown', this._onPointer);
            }
            this.abort?.abort();
            clearTimeout(this.timer as number);
        },

        get listClass(): string {
            return this.open && (this.items.length > 0 || this.loading || this.error !== '' || this.empty)
                ? 'is-open'
                : '';
        },

        get empty(): boolean {
            return this.open && !this.loading && this.error === '' && this.items.length === 0 && this.query.trim().length >= 2;
        },

        inputEl(): HTMLInputElement | null {
            const root = componentRoot(this as unknown as AresComponent);
            return (this.$refs.query as HTMLInputElement | undefined)
                ?? root.querySelector<HTMLInputElement>('[data-ares-query]');
        },

        highlight(event: Event) {
            const el = event.currentTarget as HTMLElement;
            this.activeIco = el.dataset.ico || '';
            const root = componentRoot(this as unknown as AresComponent);
            root.querySelectorAll('.ares-suggest__item').forEach((node) => node.classList.remove('is-active'));
            el.classList.add('is-active');
        },

        onInput() {
            if (this.applying) {
                return;
            }
            const input = this.inputEl();
            this.query = input?.value ?? '';
            this.schedule();
        },

        schedule() {
            clearTimeout(this.timer as number);
            const q = this.query.trim();
            if (q.length < 2) {
                this.items = [];
                this.open = false;
                this.error = '';
                return;
            }
            this.timer = setTimeout(() => {
                void this.search();
            }, 280);
        },

        async search() {
            const q = this.query.trim();
            if (q.length < 2) {
                return;
            }
            this.abort?.abort();
            this.abort = new AbortController();
            this.loading = true;
            this.error = '';
            this.open = true;
            try {
                const res = await fetch(`/admin/ares/subjects?q=${encodeURIComponent(q)}&limit=8`, {
                    headers: { Accept: 'application/json' },
                    signal: this.abort.signal,
                    credentials: 'same-origin',
                });
                const json = await res.json();
                if (!res.ok) {
                    throw new Error(json?.error?.message || `HTTP ${res.status}`);
                }
                this.items = Array.isArray(json.data) ? json.data : [];
                this.activeIco = this.items[0]?.ico ?? '';
            } catch (err) {
                if ((err as Error).name === 'AbortError') {
                    return;
                }
                this.items = [];
                this.error = (err as Error).message || 'Vyhledávání selhalo';
            } finally {
                this.loading = false;
            }
        },

        async lookupIco() {
            const root = componentRoot(this as unknown as AresComponent);
            const icoInput = root.querySelector<HTMLInputElement>('[data-ares-ico]');
            const value = (icoInput?.value || this.query).trim();
            if (value === '') {
                this.error = 'Zadejte IČO';
                this.open = true;
                return;
            }
            this.query = value;
            if (icoInput) {
                icoInput.value = value;
            }
            await this.search();
            if (this.items.length === 1) {
                this.applySubject(this.items[0]);
            }
        },

        pick(event: Event) {
            event.preventDefault();
            const el = event.currentTarget as HTMLElement;
            const ico = el.dataset.ico || '';
            const item = this.items.find((row) => row.ico === ico);
            if (!item) {
                return;
            }
            this.applySubject(item);
        },

        applySubject(item: AresSubject) {
            this.applying = true;
            clearTimeout(this.timer as number);
            this.abort?.abort();

            const root = componentRoot(this as unknown as AresComponent);
            const scope = (root.closest('form') as HTMLElement | null) ?? root;
            fill(scope, 'name', item.name);
            fill(scope, 'company', item.name);
            fill(scope, 'ico', item.ico);
            if (item.dic) {
                fill(scope, 'dic', item.dic);
            }
            if (item.address_text) {
                fill(scope, 'address', item.address_text);
            }

            const query = this.inputEl();
            if (query) {
                if (query.name === 'company' || query.dataset.aresFill === 'company') {
                    query.value = item.name;
                } else if (query.name === 'seller_name' || query.dataset.aresFill === 'name') {
                    query.value = item.name;
                } else if (query.dataset.aresIco !== undefined || query.name?.includes('ico')) {
                    query.value = item.ico;
                }
                query.dispatchEvent(new Event('change', { bubbles: true }));
            }

            this.query = query?.value || item.name;
            this.items = [];
            this.close();
            this.applying = false;
        },

        onKeydown(event: KeyboardEvent) {
            if (!this.open || this.items.length === 0) {
                return;
            }
            const index = this.items.findIndex((item) => item.ico === this.activeIco);
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                const next = this.items[(index + 1 + this.items.length) % this.items.length];
                this.activeIco = next.ico;
                this.syncActiveClass('ico', next.ico);
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                const prev = this.items[(index - 1 + this.items.length) % this.items.length];
                this.activeIco = prev.ico;
                this.syncActiveClass('ico', prev.ico);
            } else if (event.key === 'Enter' && this.activeIco !== '') {
                event.preventDefault();
                const item = this.items.find((row) => row.ico === this.activeIco);
                if (item) {
                    this.applySubject(item);
                }
            } else if (event.key === 'Escape') {
                this.close();
            }
        },

        syncActiveClass(attr: string, value: string) {
            const root = componentRoot(this as unknown as AresComponent);
            root.querySelectorAll('.ares-suggest__item').forEach((node) => {
                const el = node as HTMLElement;
                el.classList.toggle('is-active', el.dataset[attr] === value);
            });
        },

        close() {
            this.open = false;
            this.activeIco = '';
        },
    }));

    alpine.data('gereqAresAddress', () => ({
        rootEl: null as HTMLElement | null,
        open: false,
        loading: false,
        query: '',
        items: [] as AresAddress[],
        error: '',
        activeKey: '',
        applying: false,
        timer: 0 as number | ReturnType<typeof setTimeout>,
        abort: null as AbortController | null,
        _onPointer: null as ((event: PointerEvent) => void) | null,

        init() {
            this.rootEl = this.$el as HTMLElement;
            const root = this.rootEl;
            const input = this.inputEl();
            if (input) {
                this.query = input.value;
            }
            const onPointer = (event: PointerEvent) => {
                if (!root.contains(event.target as Node)) {
                    this.close();
                }
            };
            document.addEventListener('pointerdown', onPointer);
            this._onPointer = onPointer;
        },

        destroy() {
            if (this._onPointer) {
                document.removeEventListener('pointerdown', this._onPointer);
            }
            this.abort?.abort();
            clearTimeout(this.timer as number);
        },

        get listClass(): string {
            return this.open && (this.items.length > 0 || this.loading || this.error !== '' || this.empty)
                ? 'is-open'
                : '';
        },

        get empty(): boolean {
            return this.open && !this.loading && this.error === '' && this.items.length === 0 && this.query.trim().length >= 2;
        },

        inputEl(): HTMLInputElement | null {
            const root = componentRoot(this as unknown as AresComponent);
            return (this.$refs.query as HTMLInputElement | undefined)
                ?? root.querySelector<HTMLInputElement>('[data-ares-query]');
        },

        highlight(event: Event) {
            const el = event.currentTarget as HTMLElement;
            this.activeKey = el.dataset.key || '';
            const root = componentRoot(this as unknown as AresComponent);
            root.querySelectorAll('.ares-suggest__item').forEach((node) => node.classList.remove('is-active'));
            el.classList.add('is-active');
        },

        onInput() {
            if (this.applying) {
                return;
            }
            const input = this.inputEl();
            this.query = input?.value ?? '';
            this.schedule();
        },

        schedule() {
            clearTimeout(this.timer as number);
            const q = this.query.trim();
            if (q.length < 2) {
                this.items = [];
                this.open = false;
                this.error = '';
                return;
            }
            this.timer = setTimeout(() => {
                void this.search();
            }, 280);
        },

        async search() {
            const q = this.query.trim();
            if (q.length < 2 || this.applying) {
                return;
            }
            this.abort?.abort();
            this.abort = new AbortController();
            this.loading = true;
            this.error = '';
            this.open = true;
            try {
                const res = await fetch(`/admin/ares/addresses?q=${encodeURIComponent(q)}&limit=8`, {
                    headers: { Accept: 'application/json' },
                    signal: this.abort.signal,
                    credentials: 'same-origin',
                });
                const json = await res.json();
                if (!res.ok) {
                    throw new Error(json?.error?.message || `HTTP ${res.status}`);
                }
                const rows = Array.isArray(json.data) ? json.data : [];
                this.items = rows.map((row: Record<string, unknown>, index: number) => {
                    const city = typeof row.city === 'string' ? row.city : null;
                    const cityPart = typeof row.city_part === 'string' ? row.city_part : null;
                    const zip = typeof row.zip === 'string' ? row.zip : null;
                    const text = typeof row.text === 'string' ? row.text : '';
                    const meta = [cityPart, city, zip].filter((part): part is string => !!part).join(' · ');
                    const key = String(row.ruian_code ?? `addr-${index}`);
                    return {
                        key,
                        text,
                        label: typeof row.label === 'string' ? row.label : text,
                        meta,
                        street: typeof row.street === 'string' ? row.street : null,
                        city,
                        city_part: cityPart,
                        zip,
                    } satisfies AresAddress;
                });
                this.activeKey = this.items[0]?.key ?? '';
            } catch (err) {
                if ((err as Error).name === 'AbortError') {
                    return;
                }
                this.items = [];
                this.error = (err as Error).message || 'Vyhledávání selhalo';
            } finally {
                this.loading = false;
            }
        },

        pick(event: Event) {
            event.preventDefault();
            const el = event.currentTarget as HTMLElement;
            const key = el.dataset.key || '';
            const item = this.items.find((row) => row.key === key);
            if (!item) {
                return;
            }
            this.applyAddress(item);
        },

        applyAddress(item: AresAddress) {
            this.applying = true;
            clearTimeout(this.timer as number);
            this.abort?.abort();

            const root = componentRoot(this as unknown as AresComponent);
            fill(root, 'street', item.street || '');
            fill(root, 'city', item.city || '');
            fill(root, 'city_part', item.city_part || '');
            fill(root, 'zip', item.zip || '');
            fill(root, 'address', item.text || '');
            fill(root, 'country', 'CZ');

            const query = this.inputEl();
            if (query) {
                const fillKey = query.dataset.aresFill;
                if (fillKey === 'city' || query.name === 'city') {
                    query.value = item.city || item.text || '';
                } else if (fillKey === 'street' || query.name === 'street') {
                    query.value = item.street || item.text || '';
                } else {
                    query.value = item.text || '';
                }
                query.dispatchEvent(new Event('change', { bubbles: true }));
            }

            this.query = query?.value || item.text;
            this.items = [];
            this.close();
            this.applying = false;
        },

        onKeydown(event: KeyboardEvent) {
            if (!this.open || this.items.length === 0) {
                return;
            }
            const index = this.items.findIndex((item) => item.key === this.activeKey);
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                const next = this.items[(index + 1 + this.items.length) % this.items.length];
                this.activeKey = next.key;
                this.syncActiveClass('key', next.key);
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                const prev = this.items[(index - 1 + this.items.length) % this.items.length];
                this.activeKey = prev.key;
                this.syncActiveClass('key', prev.key);
            } else if (event.key === 'Enter' && this.activeKey !== '') {
                event.preventDefault();
                const item = this.items.find((row) => row.key === this.activeKey);
                if (item) {
                    this.applyAddress(item);
                }
            } else if (event.key === 'Escape') {
                this.close();
            }
        },

        syncActiveClass(attr: string, value: string) {
            const root = componentRoot(this as unknown as AresComponent);
            root.querySelectorAll('.ares-suggest__item').forEach((node) => {
                const el = node as HTMLElement;
                el.classList.toggle('is-active', el.dataset[attr] === value);
            });
        },

        close() {
            this.open = false;
            this.activeKey = '';
        },
    }));
}

function fill(root: HTMLElement, key: string, value: string): void {
    root.querySelectorAll<HTMLInputElement | HTMLTextAreaElement>(`[data-ares-fill="${cssEscape(key)}"]`).forEach((el) => {
        el.value = value;
        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));
    });
}

function cssEscape(value: string): string {
    if (typeof CSS !== 'undefined' && typeof CSS.escape === 'function') {
        return CSS.escape(value);
    }
    return value.replace(/"/g, '\\"');
}
