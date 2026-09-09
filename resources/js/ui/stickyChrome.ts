/** Keeps stacked sticky page-header + detail tabs aligned (no gap under topbar). */
export function registerStickyChrome(): void {
    const sync = (): void => {
        const page = document.querySelector<HTMLElement>('main.page');
        if (!page) {
            return;
        }

        const header = page.querySelector<HTMLElement>(':scope > .page-header, :scope > form > .page-header');
        const height = header ? Math.ceil(header.getBoundingClientRect().height) : 0;
        page.style.setProperty('--page-header-sticky-h', `${height}px`);
    };

    const observe = (): void => {
        sync();
        const page = document.querySelector<HTMLElement>('main.page');
        const header = page?.querySelector<HTMLElement>(':scope > .page-header, :scope > form > .page-header');
        if (!header || typeof ResizeObserver === 'undefined') {
            return;
        }

        const ro = new ResizeObserver(() => sync());
        ro.observe(header);
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', observe, { once: true });
    } else {
        observe();
    }

    window.addEventListener('resize', sync);
}
