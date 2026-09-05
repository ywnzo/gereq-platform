import type Alpine from '@alpinejs/csp';

type DrawerApi = {
    openDrawer: (id?: string) => void;
    closeDrawer: (id?: string) => void;
    loadDrawer: (opts: { title?: string; html?: string; footer?: string; id?: string }) => void;
};

declare global {
    interface Window {
        gereq?: DrawerApi & Record<string, unknown>;
    }
}

function drawerEl(id: string): HTMLElement | null {
    return document.getElementById(id);
}

function syncBodyScroll(): void {
    const anyOpen = document.querySelector('.g-drawer.is-open, .g-drawer[data-open="true"]');
    document.body.classList.toggle('has-drawer', !!anyOpen);
}

function emitDrawer(el: HTMLElement, open: boolean): void {
    el.dispatchEvent(new CustomEvent(open ? 'gereq:drawer-open' : 'gereq:drawer-close', { bubbles: false }));
}

export function registerDrawer(alpine: typeof Alpine): void {
    alpine.data('gereqDrawer', () => ({
        open: false,

        init() {
            const el = this.$el as HTMLElement;
            this.open = el.dataset.open === 'true';
            this.$watch('open', (value: boolean) => {
                el.dataset.open = value ? 'true' : 'false';
                el.classList.toggle('is-open', value);
                syncBodyScroll();
            });
            el.addEventListener('gereq:drawer-open', () => this.show());
            el.addEventListener('gereq:drawer-close', () => this.close());
            if (this.open) {
                syncBodyScroll();
            }
        },

        show() {
            this.open = true;
            this.$nextTick(() => (this.$refs.close as HTMLButtonElement | undefined)?.focus());
        },

        close() {
            this.open = false;
        },

        onEscape() {
            if (this.open) {
                this.close();
            }
        },
    }));

    const api: DrawerApi = {
        openDrawer(id = 'gereq-drawer') {
            const el = drawerEl(id);
            if (el) {
                emitDrawer(el, true);
            }
        },
        closeDrawer(id = 'gereq-drawer') {
            const el = drawerEl(id);
            if (el) {
                emitDrawer(el, false);
            }
        },
        loadDrawer(opts) {
            const id = opts.id || 'gereq-drawer';
            const el = drawerEl(id);
            if (!el) {
                return;
            }
            const title = el.querySelector('[data-drawer-title]');
            const body = el.querySelector('[data-drawer-body]');
            const foot = el.querySelector<HTMLElement>('[data-drawer-foot]');
            if (title && opts.title !== undefined) {
                title.textContent = opts.title;
            }
            if (body && opts.html !== undefined) {
                body.innerHTML = opts.html;
            }
            if (foot) {
                const footer = opts.footer ?? '';
                foot.innerHTML = footer;
                foot.hidden = footer.trim() === '';
                foot.classList.toggle('is-empty', footer.trim() === '');
            }
            emitDrawer(el, true);
        },
    };

    window.gereq = { ...(window.gereq || {}), ...api };

    document.addEventListener('click', (event) => {
        const target = event.target as HTMLElement | null;
        const opener = target?.closest?.('[data-drawer-open]') as HTMLElement | null;
        if (opener) {
            const id = opener.getAttribute('data-drawer-open');
            if (id) {
                event.preventDefault();
                api.openDrawer(id);
            }
            return;
        }
        const closer = target?.closest?.('[data-drawer-close]') as HTMLElement | null;
        if (closer) {
            const host = closer.closest('.g-drawer') as HTMLElement | null;
            if (host?.id) {
                api.closeDrawer(host.id);
            } else {
                api.closeDrawer();
            }
        }
    });
}
