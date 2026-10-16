/**
 * Thin progress bar at the top during full-page navigation / slow loads.
 */
type PageProgressApi = {
    start: () => void;
    done: () => void;
};

const BAR_ID = 'g-page-progress';

function ensureBar(): HTMLElement {
    let el = document.getElementById(BAR_ID);
    if (el) {
        return el;
    }
    el = document.createElement('div');
    el.id = BAR_ID;
    el.className = 'g-page-progress';
    el.setAttribute('role', 'progressbar');
    el.setAttribute('aria-hidden', 'true');
    el.setAttribute('aria-valuemin', '0');
    el.setAttribute('aria-valuemax', '100');
    document.body.prepend(el);

    return el;
}

export function registerPageProgress(): void {
    const el = ensureBar();
    let active = false;
    let trickleTimer: ReturnType<typeof setInterval> | null = null;
    let safetyTimer: ReturnType<typeof setTimeout> | null = null;
    let width = 0;

    const setWidth = (value: number) => {
        width = Math.max(0, Math.min(100, value));
        el.style.width = `${width}%`;
        el.setAttribute('aria-valuenow', String(Math.round(width)));
    };

    const clearTimers = () => {
        if (trickleTimer !== null) {
            clearInterval(trickleTimer);
            trickleTimer = null;
        }
        if (safetyTimer !== null) {
            clearTimeout(safetyTimer);
            safetyTimer = null;
        }
    };

    const start = () => {
        if (active) {
            return;
        }
        active = true;
        clearTimers();
        el.classList.add('is-active');
        el.setAttribute('aria-hidden', 'false');
        setWidth(0);
        requestAnimationFrame(() => setWidth(18));
        trickleTimer = setInterval(() => {
            if (width >= 92) {
                return;
            }
            const step = width < 50 ? 8 : width < 80 ? 3 : 0.8;
            setWidth(width + step);
        }, 320);
        safetyTimer = setTimeout(() => done(), 45_000);
    };

    const done = () => {
        clearTimers();
        if (!active && !el.classList.contains('is-active')) {
            setWidth(0);
            return;
        }
        active = false;
        setWidth(100);
        window.setTimeout(() => {
            el.classList.remove('is-active');
            el.setAttribute('aria-hidden', 'true');
            window.setTimeout(() => setWidth(0), 220);
        }, 180);
    };

    const shouldIgnoreLink = (anchor: HTMLAnchorElement, event: MouseEvent): boolean => {
        if (event.defaultPrevented) {
            return true;
        }
        if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return true;
        }
        if (anchor.hasAttribute('download')) {
            return true;
        }
        const target = (anchor.getAttribute('target') || '_self').toLowerCase();
        if (target !== '_self' && target !== '') {
            return true;
        }
        const href = anchor.getAttribute('href');
        if (!href || href.startsWith('#') || href.startsWith('javascript:') || href.startsWith('mailto:') || href.startsWith('tel:')) {
            return true;
        }
        try {
            const url = new URL(href, window.location.href);
            if (url.origin !== window.location.origin) {
                return true;
            }
            if (url.pathname === window.location.pathname && url.search === window.location.search && url.hash !== '') {
                return true;
            }
            if (url.href === window.location.href) {
                return true;
            }
        } catch {
            return true;
        }

        return false;
    };

    document.addEventListener(
        'click',
        (event) => {
            const target = event.target;
            if (!(target instanceof Element)) {
                return;
            }
            const anchor = target.closest('a[href]');
            if (!(anchor instanceof HTMLAnchorElement)) {
                return;
            }
            if (shouldIgnoreLink(anchor, event)) {
                return;
            }
            start();
        },
        true,
    );

    document.addEventListener(
        'submit',
        (event) => {
            if (event.defaultPrevented) {
                return;
            }
            const form = event.target;
            if (!(form instanceof HTMLFormElement)) {
                return;
            }
            const target = (form.getAttribute('target') || '_self').toLowerCase();
            if (target !== '_self' && target !== '') {
                return;
            }
            start();
        },
        true,
    );

    window.addEventListener('pageshow', () => done());
    window.addEventListener('pagehide', () => {
        // Keep the bar running on leave — the next page clears it.
    });

    // Delay first paint: show the bar only when load takes longer than ~120 ms.
    let bootTimer: ReturnType<typeof setTimeout> | null = null;
    if (document.readyState !== 'complete') {
        bootTimer = setTimeout(() => start(), 120);
        window.addEventListener(
            'load',
            () => {
                if (bootTimer !== null) {
                    clearTimeout(bootTimer);
                    bootTimer = null;
                }
                done();
            },
            { once: true },
        );
    }

    const api: PageProgressApi = { start, done };
    window.gereq = { ...(window.gereq ?? {}), pageProgress: api };
}
