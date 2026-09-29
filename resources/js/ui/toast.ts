type ToastTone = 'success' | 'error' | 'info' | 'warning';

type ToastApi = {
    toast: (message: string, tone?: ToastTone) => void;
};

declare global {
    interface Window {
        gereq?: ToastApi & Record<string, unknown>;
    }
}

const HIDE_MS = 3800;

function host(): HTMLElement | null {
    return document.getElementById('toasts');
}

function normalizeTone(tone: string | undefined): ToastTone {
    if (tone === 'error' || tone === 'info' || tone === 'warning' || tone === 'success') {
        return tone;
    }
    return 'success';
}

export function registerToast(): void {
    const api: ToastApi = {
        toast(message, tone = 'success') {
            const text = String(message ?? '').trim();
            if (text === '') {
                return;
            }
            const root = host();
            if (!root) {
                return;
            }

            const resolved = normalizeTone(tone);
            const el = document.createElement('div');
            el.className = `toast toast--${resolved}`;
            el.setAttribute('role', resolved === 'error' ? 'alert' : 'status');
            el.textContent = text;
            root.appendChild(el);

            requestAnimationFrame(() => el.classList.add('is-in'));

            window.setTimeout(() => {
                el.classList.remove('is-in');
                el.classList.add('is-out');
                window.setTimeout(() => el.remove(), 220);
            }, HIDE_MS);
        },
    };

    window.gereq = { ...(window.gereq || {}), ...api };
}
