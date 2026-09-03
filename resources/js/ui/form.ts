import type Alpine from '@alpinejs/csp';

export function registerForm(alpine: typeof Alpine): void {
    alpine.data('gereqForm', () => ({
        key: '',

        init() {
            const form = this.$el as HTMLFormElement;
            const name = form.dataset.form || form.id || form.getAttribute('action') || 'form';
            this.key = 'gereq:form:' + name;
            this.clearIfNeeded(name);
            if (form.dataset.serverState !== '1') {
                this.restore(form);
            }
            const persist = () => this.persist(form);
            form.addEventListener('input', persist);
            form.addEventListener('change', persist);
            form.addEventListener('input', (event) => this.clearFieldError(event));
            form.addEventListener('change', (event) => this.clearFieldError(event));
            this.$nextTick(() => this.focusInvalid(form));
        },

        clearIfNeeded(name: string) {
            const cleared = window.__GEREQ_FORM_CLEARED__ ?? [];
            if (cleared.includes(name)) {
                sessionStorage.removeItem(this.key);
            }
        },

        persist(form: HTMLFormElement) {
            const data: Record<string, string> = {};
            const fields = new FormData(form);
            fields.forEach((value, field) => {
                if (field === '_token' || field === '_csrf' || field.toLowerCase().includes('password')) {
                    return;
                }
                if (typeof value === 'string') {
                    data[field] = value;
                }
            });
            sessionStorage.setItem(this.key, JSON.stringify({ at: Date.now(), data }));
        },

        restore(form: HTMLFormElement) {
            const raw = sessionStorage.getItem(this.key);
            if (!raw) {
                return;
            }
            try {
                const parsed = JSON.parse(raw) as { at?: number; data?: Record<string, string> };
                if (!parsed.data || (parsed.at && Date.now() - parsed.at > 1000 * 60 * 60 * 6)) {
                    return;
                }
                Object.entries(parsed.data).forEach(([field, value]) => {
                    const input = form.querySelector(`[name="${cssEscape(field)}"]`) as
                        | HTMLInputElement
                        | HTMLTextAreaElement
                        | HTMLSelectElement
                        | null;
                    if (!input || input.type === 'file' || input.type === 'password') {
                        return;
                    }
                    if (input instanceof HTMLInputElement && (input.type === 'checkbox' || input.type === 'radio')) {
                        input.checked = input.value === value;
                        return;
                    }
                    if (input.value === '') {
                        input.value = value;
                        input.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                });
            } catch {
                sessionStorage.removeItem(this.key);
            }
        },

        focusInvalid(form: HTMLFormElement) {
            const invalid = form.querySelector(
                '.is-invalid .g-control, .is-invalid .textarea, .is-invalid .textarea-mini, .is-invalid .g-select__trigger, [aria-invalid="true"]',
            ) as HTMLElement | null;
            invalid?.focus();
            invalid?.closest('.g-field, .g-check')?.scrollIntoView({ block: 'center', behavior: 'smooth' });
        },

        clearFieldError(event: Event) {
            const target = event.target as HTMLElement | null;
            const field = target?.closest<HTMLElement>('[data-field].is-invalid');
            if (!field) {
                return;
            }
            field.classList.remove('is-invalid');
            field.querySelectorAll('[aria-invalid]').forEach((el) => el.removeAttribute('aria-invalid'));
            field.querySelector('.g-error')?.remove();
            field.querySelector('.textarea-shell')?.classList.remove('is-invalid');
        },
    }));
}

function cssEscape(value: string): string {
    if (typeof CSS !== 'undefined' && typeof CSS.escape === 'function') {
        return CSS.escape(value);
    }
    return value.replace(/"/g, '\\"');
}

declare global {
    interface Window {
        __GEREQ_FORM_CLEARED__?: string[];
    }
}
