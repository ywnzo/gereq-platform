type ToastTone = 'success' | 'error' | 'info' | 'warning';

type ToastApi = {
    toast: (message: string, tone?: string) => void;
};

type FlashItem = { message?: string; tone?: string };

declare global {
    interface Window {
        __GEREQ_FLASHES__?: FlashItem[];
    }
}

const HIDE_MS = 4200;
const OUT_MS = 240;

const TONE_ICON: Record<ToastTone, string> = {
    success: 'check_circle',
    error: 'error',
    info: 'info',
    warning: 'warning',
};

function host(): HTMLElement | null {
    return document.getElementById('toasts');
}

function normalizeTone(tone: string | undefined): ToastTone {
    if (tone === 'error' || tone === 'info' || tone === 'warning' || tone === 'success') {
        return tone;
    }
    if (tone === 'danger') {
        return 'error';
    }
    return 'success';
}

function closeLabel(root: HTMLElement): string {
    return root.dataset.close || 'Close';
}

function buildToast(root: HTMLElement, message: string, tone: ToastTone): HTMLElement {
    const el = document.createElement('div');
    el.className = `toast toast--${tone}`;
    el.setAttribute('role', tone === 'error' ? 'alert' : 'status');

    const icon = document.createElement('span');
    icon.className = 'material-symbols-outlined g-icon toast__icon';
    icon.setAttribute('aria-hidden', 'true');
    icon.textContent = TONE_ICON[tone];

    const body = document.createElement('div');
    body.className = 'toast__body';
    body.textContent = message;

    const dismiss = document.createElement('button');
    dismiss.type = 'button';
    dismiss.className = 'toast__dismiss';
    dismiss.setAttribute('aria-label', closeLabel(root));
    const dismissIcon = document.createElement('span');
    dismissIcon.className = 'material-symbols-outlined g-icon';
    dismissIcon.setAttribute('aria-hidden', 'true');
    dismissIcon.textContent = 'close';
    dismiss.appendChild(dismissIcon);

    const progress = document.createElement('span');
    progress.className = 'toast__progress';
    progress.setAttribute('aria-hidden', 'true');

    el.append(icon, body, dismiss, progress);
    return el;
}

function showToast(message: string, tone: ToastTone = 'success'): void {
    const text = String(message ?? '').trim();
    if (text === '') {
        return;
    }
    const root = host();
    if (!root) {
        return;
    }

    const resolved = normalizeTone(tone);
    const el = buildToast(root, text, resolved);
    root.prepend(el);

    const progress = el.querySelector<HTMLElement>('.toast__progress');
    if (progress) {
        progress.style.setProperty('--toast-duration', `${HIDE_MS}ms`);
    }

    let hideTimer = 0;
    let leaving = false;

    const leave = () => {
        if (leaving) {
            return;
        }
        leaving = true;
        window.clearTimeout(hideTimer);
        el.classList.remove('is-in');
        el.classList.add('is-out');
        window.setTimeout(() => el.remove(), OUT_MS);
    };

    const armTimer = () => {
        window.clearTimeout(hideTimer);
        hideTimer = window.setTimeout(leave, HIDE_MS);
        if (progress) {
            progress.classList.remove('is-paused', 'is-running');
            // Restart CSS progress so it matches the dismiss timer.
            void progress.offsetWidth;
            progress.classList.add('is-running');
        }
    };

    el.querySelector('.toast__dismiss')?.addEventListener('click', leave);
    el.addEventListener('mouseenter', () => {
        window.clearTimeout(hideTimer);
        progress?.classList.add('is-paused');
    });
    el.addEventListener('mouseleave', () => {
        armTimer();
    });

    requestAnimationFrame(() => {
        el.classList.add('is-in');
        armTimer();
    });
}

function consumeFlashes(): void {
    const items = window.__GEREQ_FLASHES__;
    if (!Array.isArray(items) || items.length === 0) {
        return;
    }
    window.__GEREQ_FLASHES__ = [];
    for (const item of items) {
        const message = String(item?.message ?? '').trim();
        if (message === '') {
            continue;
        }
        showToast(message, normalizeTone(item?.tone));
    }
}

export function registerToast(): void {
    const api: ToastApi = {
        toast(message, tone = 'success') {
            showToast(message, normalizeTone(tone));
        },
    };

    window.gereq = { ...(window.gereq || {}), ...api };
    consumeFlashes();
}
