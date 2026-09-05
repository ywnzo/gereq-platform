import type Alpine from '@alpinejs/csp';

const SEP_SVG =
    '<svg width="12" height="12" viewBox="0 0 12 12" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M4.5 2.5L8 6L4.5 9.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>';

function tabLabel(btn: HTMLElement): string {
    const clone = btn.cloneNode(true) as HTMLElement;
    clone.querySelectorAll('.module-tab-count').forEach((el) => el.remove());

    return (clone.textContent || '').replace(/\s+/g, ' ').trim();
}

function ensureSeparator(item: HTMLElement): void {
    if (item.querySelector(':scope > .breadcrumbs__sep')) {
        return;
    }

    const sep = document.createElement('span');
    sep.className = 'breadcrumbs__sep';
    sep.setAttribute('aria-hidden', 'true');
    sep.innerHTML = SEP_SVG;
    item.appendChild(sep);
}

function promoteToLink(item: HTMLElement, href: string): void {
    const current = item.querySelector(':scope > .breadcrumbs__current');
    if (current) {
        const a = document.createElement('a');
        a.className = 'breadcrumbs__link breadcrumbs__link--tab-parent';
        a.href = href;
        a.textContent = current.textContent;
        current.replaceWith(a);
    } else {
        const existing = item.querySelector(':scope > .breadcrumbs__link--tab-parent') as HTMLAnchorElement | null;
        if (existing) {
            existing.href = href;
        }
    }

    item.classList.remove('is-current');
    ensureSeparator(item);
}

function demoteToCurrent(item: HTMLElement): void {
    const link = item.querySelector(':scope > .breadcrumbs__link--tab-parent');
    if (link) {
        const span = document.createElement('span');
        span.className = 'breadcrumbs__current';
        span.setAttribute('aria-current', 'page');
        span.textContent = link.textContent;
        link.replaceWith(span);
    }

    item.querySelector(':scope > .breadcrumbs__sep')?.remove();
    item.classList.add('is-current');
    const current = item.querySelector(':scope > .breadcrumbs__current');
    current?.setAttribute('aria-current', 'page');
}

function syncBreadcrumbs(root: HTMLElement, current: string): void {
    if (root.dataset.breadcrumbs === '0') {
        return;
    }

    const list = document.querySelector('.topbar .breadcrumbs__list') as HTMLOListElement | null;
    if (!list) {
        return;
    }

    list.querySelectorAll('.breadcrumbs__item--tab').forEach((el) => el.remove());

    const baseItems = [...list.querySelectorAll<HTMLElement>(':scope > .breadcrumbs__item')];
    if (!baseItems.length) {
        return;
    }

    const lastBase = baseItems[baseItems.length - 1];
    const initial = root.dataset.initial || '';
    const firstTab =
        (root.querySelector('.design-tab[data-tab]') as HTMLElement | null)?.dataset.tab || '';
    const homeTab = initial || firstTab;
    const btn = root.querySelector<HTMLElement>(`.design-tab[data-tab="${CSS.escape(current)}"]`);
    const label = btn ? tabLabel(btn) : '';
    const showTab = Boolean(current && label && homeTab && current !== homeTab);

    if (!showTab) {
        demoteToCurrent(lastBase);
        return;
    }

    promoteToLink(lastBase, '#' + homeTab);

    const li = document.createElement('li');
    li.className = 'breadcrumbs__item breadcrumbs__item--tab is-current';
    const span = document.createElement('span');
    span.className = 'breadcrumbs__current';
    span.setAttribute('aria-current', 'page');
    span.textContent = label;
    li.appendChild(span);
    list.appendChild(li);
}

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
            const tabInput = root.querySelector<HTMLInputElement>('input[name="settings_tab"]');
            if (tabInput) {
                tabInput.value = this.current;
            }
            syncBreadcrumbs(root, this.current);
        },
    }));
}
