import '../css/admin.css';

/** Details are fetched only after an authorized admin opens an inquiry. */
export default function register(): void {
    const modal = document.getElementById('site-inquiry-modal');
    const body = modal?.querySelector<HTMLElement>('[data-inquiry-modal-body]');
    const dialog = modal?.querySelector<HTMLElement>('[role="dialog"]');
    if (!modal || !body || !dialog) return;
    let request: AbortController | null = null;
    let trigger: HTMLElement | null = null;
    let currentUrl = '';
    let previousOverflow = '';
    let locked = false;
    const template = (id: string) => {
        const source = document.getElementById(id) as HTMLTemplateElement | null;
        body.replaceChildren(...(source ? [source.content.cloneNode(true)] : []));
    };
    const load = async (url: string) => {
        request?.abort();
        const controller = new AbortController();
        request = controller;
        template('site-inquiry-loading');
        body.setAttribute('aria-busy', 'true');
        try {
            const response = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, signal: controller.signal });
            if (!response.ok || response.redirected) throw new Error('Inquiry unavailable');
            const html = await response.text();
            if (!html.includes('data-inquiry-detail')) throw new Error('Invalid inquiry response');
            if (!controller.signal.aborted) body.innerHTML = html;
        } catch {
            if (!controller.signal.aborted) template('site-inquiry-error');
        } finally {
            if (request === controller) body.removeAttribute('aria-busy');
        }
    };
    document.addEventListener('click', (event) => {
        const link = (event.target as Element | null)?.closest<HTMLAnchorElement>('a[href]');
        if (!link || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        const url = new URL(link.href);
        const base = modal.dataset.detailBase ?? '';
        if (!base || url.origin !== window.location.origin || !url.pathname.startsWith(base)
            || !/^[0-9a-f-]{36}$/i.test(url.pathname.slice(base.length))) return;
        event.preventDefault();
        trigger = link;
        currentUrl = link.href;
        window.dispatchEvent(new CustomEvent('site-inquiry-open'));
        void load(currentUrl);
    });
    new MutationObserver(() => {
        const open = dialog.classList.contains('is-open');
        if (open && !locked) {
            previousOverflow = document.body.style.overflow;
            document.body.style.overflow = 'hidden';
            locked = true;
        } else if (!open && locked) {
            request?.abort();
            document.body.style.overflow = previousOverflow;
            locked = false;
            trigger?.focus();
        }
    }).observe(dialog, { attributes: true, attributeFilter: ['class'] });
    dialog.addEventListener('keydown', (event) => {
        if (event.key !== 'Tab') return;
        const targets = [...dialog.querySelectorAll<HTMLElement>('a[href], button:not([disabled]), input:not([type="hidden"]):not([disabled]), [tabindex="0"]')].filter(el => el.getClientRects().length > 0);
        const first = targets[0];
        const last = targets.at(-1);
        if ((event.shiftKey && document.activeElement === first) || (!event.shiftKey && document.activeElement === last)) {
            event.preventDefault();
            (event.shiftKey ? last : first)?.focus();
        }
    });
    body.addEventListener('submit', async (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('[data-inquiry-notification-retry]')) return;
        event.preventDefault();
        const button = form.querySelector<HTMLButtonElement>('button[type="submit"]');
        if (!button || button.disabled) return;
        button.disabled = true;
        try {
            const response = await fetch(form.action, { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
            if (!response.ok || response.redirected) throw new Error('Retry unavailable');
            const data = await response.json() as { message: string };
            window.gereq?.toast?.(data.message, 'success');
            if (dialog.classList.contains('is-open')) await load(currentUrl);
        } catch {
            window.gereq?.toast?.(modal.dataset.retryError ?? '', 'error');
            button.disabled = false;
        }
    });
}
