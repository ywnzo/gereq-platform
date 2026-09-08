import type Alpine from '@alpinejs/csp';

export function registerPassword(alpine: typeof Alpine): void {
    alpine.data('gereqPassword', () => ({
        revealed: false,

        get toggleLabel(): string {
            return this.revealed ? 'Skrýt heslo' : 'Zobrazit heslo';
        },

        toggle() {
            this.revealed = !this.revealed;
            const input = this.$refs.input as HTMLInputElement | undefined;
            if (input) {
                input.type = this.revealed ? 'text' : 'password';
            }
        },
    }));
}

export function registerSwitch(alpine: typeof Alpine): void {
    alpine.data('gereqSwitch', () => ({
        on: false,
        onClass: '',
        checkedAttr: 'false',

        init() {
            this.on = (this.$el as HTMLElement).dataset.on === '1';
            this.refresh();
        },

        refresh() {
            this.onClass = this.on ? 'is-on' : '';
            this.checkedAttr = this.on ? 'true' : 'false';
            const btn = (this.$el as HTMLElement).querySelector('.table-switch');
            if (btn) {
                btn.classList.toggle('is-on', this.on);
            }
        },

        toggle() {
            this.on = !this.on;
            this.refresh();
            const input = this.$refs.value as HTMLInputElement | undefined;
            if (input) {
                input.value = this.on ? '1' : '0';
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }
        },
    }));

    /** Checkbox „stejné jako…“ — CSP-safe náhrada za x-data="{ same: … }". */
    alpine.data('gereqSameAs', () => ({
        same: false,

        init() {
            this.same = (this.$el as HTMLElement).dataset.on === '1';
        },

        get shippingVisible(): boolean {
            return !this.same;
        },
    }));
}

/** Row publish / active / featured toggle (POST + CSRF, no full reload). */
export function registerTableToggle(alpine: typeof Alpine): void {
    alpine.data('gereqTableToggle', () => ({
        on: false,
        busy: false,

        init() {
            this.on = (this.$el as HTMLElement).dataset.on === '1';
        },

        get btnClass(): string {
            return [
                this.on ? 'is-on' : 'is-off',
                this.busy ? 'is-busy' : '',
            ].filter(Boolean).join(' ');
        },

        get pressedAttr(): string {
            return this.on ? 'true' : 'false';
        },

        get titleAttr(): string {
            const el = this.$el as HTMLElement;
            if (this.busy) {
                return el.dataset.busy || '…';
            }
            return this.on
                ? (el.dataset.titleOn || '')
                : (el.dataset.titleOff || '');
        },

        async toggle() {
            if (this.busy) {
                return;
            }
            const el = this.$el as HTMLElement;
            const action = el.dataset.action || '';
            if (!action) {
                return;
            }

            this.busy = true;
            const prev = this.on;
            this.on = !prev;

            try {
                const csrf = (window as Window & { __GEREQ_CSRF__?: string }).__GEREQ_CSRF__ || '';
                const res = await fetch(action, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrf,
                        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                    },
                    body: '_csrf=' + encodeURIComponent(csrf),
                });
                if (!res.ok) {
                    throw new Error('toggle failed');
                }
                const payload = await res.json() as { data?: { on?: boolean; message?: string } };
                if (typeof payload?.data?.on === 'boolean') {
                    this.on = payload.data.on;
                }
                el.dataset.on = this.on ? '1' : '0';
                const message =
                    (typeof payload?.data?.message === 'string' && payload.data.message.trim()) ||
                    (this.on ? el.dataset.toastOn : el.dataset.toastOff) ||
                    '';
                if (message) {
                    window.gereq?.toast?.(message, 'success');
                }
                el.dispatchEvent(new CustomEvent('gereq:table-toggle', {
                    bubbles: true,
                    detail: { on: this.on, action, message },
                }));
            } catch {
                this.on = prev;
                window.gereq?.toast?.(el.dataset.toastError || 'Uložení se nezdařilo.', 'error');
            } finally {
                this.busy = false;
            }
        },
    }));
}

export function registerTextarea(alpine: typeof Alpine): void {
    alpine.data('gereqTextarea', () => ({
        count: 0,
        max: 0,
        selected: 0,

        init() {
            const input = this.$refs.input as HTMLTextAreaElement | undefined;
            this.max = Number(input?.getAttribute('maxlength') || 0);
            this.sync();
        },

        sync() {
            const input = this.$refs.input as HTMLTextAreaElement | undefined;
            if (!input) {
                return;
            }
            this.count = input.value.length;
            const start = input.selectionStart ?? 0;
            const end = input.selectionEnd ?? 0;
            this.selected = Math.max(0, end - start);
        },

        selectAll() {
            const input = this.$refs.input as HTMLTextAreaElement | undefined;
            if (!input) {
                return;
            }
            input.focus();
            input.select();
            this.sync();
        },

        get selectedLabel(): string {
            return this.selected > 0 ? 'Označeno ' + this.selected + ' znaků' : 'Nic neoznačeno';
        },

        get countLabel(): string {
            return this.max > 0 ? this.count + ' / ' + this.max : this.count + ' znaků';
        },
    }));
}
