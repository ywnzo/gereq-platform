import type Alpine from '@alpinejs/csp';

export function registerTabs(alpine: typeof Alpine): void {
    alpine.data('gereqTabs', () => ({
        current: '',
        _onHash: null as (() => void) | null,
        _onClick: null as ((event: Event) => void) | null,

        init() {
            const root = this.$el as HTMLElement;
            const hash = window.location.hash.replace('#', '');
            const initial = root.dataset.initial || '';
            const first = (root.querySelector('.design-tab[data-tab]') as HTMLElement | null)?.dataset.tab || '';
            this.current = hash || initial || first;

            this._onClick = (event: Event) => {
                const btn = (event.target as HTMLElement | null)?.closest?.('.design-tab[data-tab]') as HTMLElement | null;
                if (!btn || !root.contains(btn)) {
                    return;
                }

                const tab = btn.dataset.tab || '';
                if (!tab) {
                    return;
                }

                this.current = tab;
                history.replaceState(null, '', '#' + tab);
                this.sync();
            };

            this._onHash = () => {
                const next = window.location.hash.replace('#', '');
                if (next && next !== this.current) {
                    this.current = next;
                    this.sync();
                }
            };

            root.addEventListener('click', this._onClick);
            window.addEventListener('hashchange', this._onHash);
            this.sync();
        },

        destroy() {
            const root = this.$el as HTMLElement;
            if (this._onClick) {
                root.removeEventListener('click', this._onClick);
            }
            if (this._onHash) {
                window.removeEventListener('hashchange', this._onHash);
            }
        },

        sync() {
            const root = this.$el as HTMLElement;
            root.querySelectorAll<HTMLElement>('.design-tab[data-tab]').forEach((el) => {
                const on = el.dataset.tab === this.current;
                el.classList.toggle('is-active', on);
                el.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            root.querySelectorAll<HTMLElement>('.design-panel[data-panel]').forEach((el) => {
                el.classList.toggle('is-active', el.dataset.panel === this.current);
            });
        },
    }));
}
