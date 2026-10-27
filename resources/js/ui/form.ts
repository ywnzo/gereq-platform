import type Alpine from '@alpinejs/csp';

type ValidityTarget = HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement;

type FormDraft = {
    at: number;
    fingerprint?: string;
    data: Record<string, string>;
};

const FP_FIELD = '_gereq_fp';

export function registerForm(alpine: typeof Alpine): void {
    alpine.data('gereqForm', () => ({
        key: '',
        _invalidHandled: false,
        _onInvalid: null as ((event: Event) => void) | null,
        _onSubmit: null as ((event: Event) => void) | null,
        _onSubmitClick: null as ((event: Event) => void) | null,

        init() {
            const form = this.$el as HTMLFormElement;
            const name = form.dataset.form || form.id || form.getAttribute('action') || 'form';
            this.key = 'gereq:form:' + (form.dataset.draftKey || name);
            this.clearIfNeeded(name);
            if (form.dataset.serverState !== '1') {
                this.restore(form);
            }
            const persist = () => this.persist(form);
            form.addEventListener('input', persist);
            form.addEventListener('change', persist);
            form.addEventListener('input', (event) => this.clearFieldError(event));
            form.addEventListener('change', (event) => this.clearFieldError(event));

            // Native bubble + reportValidity() in this handler caused an infinite refocus loop
            // (tabs froze). We suppress the browser UI and paint our own .is-invalid state.
            this._onInvalid = (event: Event) => this.onInvalid(event);
            form.addEventListener('invalid', this._onInvalid, true);

            // Every submit attempt gets a fingerprint + snapshot (also when HTML5 blocks submit).
            this._onSubmit = () => this.stampSubmit(form);
            this._onSubmitClick = (event: Event) => {
                const t = event.target as HTMLElement | null;
                const btn = t?.closest?.('button, input') as HTMLButtonElement | HTMLInputElement | null;
                if (!btn || !form.contains(btn)) {
                    return;
                }
                const type = (btn.getAttribute('type') || (btn.tagName === 'BUTTON' ? 'submit' : '')).toLowerCase();
                if (type === 'submit') {
                    this.stampSubmit(form);
                }
            };
            form.addEventListener('submit', this._onSubmit, true);
            form.addEventListener('click', this._onSubmitClick, true);

            this.$nextTick(() => this.focusInvalid(form));
        },

        destroy() {
            const form = this.$el as HTMLFormElement;
            if (this._onInvalid) {
                form.removeEventListener('invalid', this._onInvalid, true);
            }
            if (this._onSubmit) {
                form.removeEventListener('submit', this._onSubmit, true);
            }
            if (this._onSubmitClick) {
                form.removeEventListener('click', this._onSubmitClick, true);
            }
        },

        clearIfNeeded(name: string) {
            const cleared = window.__GEREQ_FORM_CLEARED__ ?? [];
            const fps = window.__GEREQ_FORM_FP_CLEARED__ ?? [];
            if (!cleared.includes(name)) {
                return;
            }

            const raw = sessionStorage.getItem(this.key);
            if (!raw) {
                return;
            }

            // Success without fingerprint (legacy): clear whole draft.
            if (!fps.length) {
                sessionStorage.removeItem(this.key);
                return;
            }

            try {
                const parsed = JSON.parse(raw) as FormDraft;
                if (!parsed.fingerprint || fps.includes(parsed.fingerprint)) {
                    sessionStorage.removeItem(this.key);
                }
            } catch {
                sessionStorage.removeItem(this.key);
            }
        },

        /** New fingerprint for this request + persist snapshot into sessionStorage. */
        stampSubmit(form: HTMLFormElement) {
            const fingerprint = newFingerprint();
            ensureFingerprintField(form, fingerprint);
            this.persist(form, fingerprint);
        },

        persist(form: HTMLFormElement, fingerprint?: string) {
            const data: Record<string, string> = {};
            const fields = new FormData(form);
            fields.forEach((value, field) => {
                if (
                    field === '_token' ||
                    field === '_csrf' ||
                    field === FP_FIELD ||
                    field.toLowerCase().includes('password')
                ) {
                    return;
                }
                if (typeof value === 'string') {
                    data[field] = value;
                }
            });

            let fp = fingerprint;
            if (!fp) {
                const existing = readDraft(this.key);
                fp = existing?.fingerprint;
            }
            if (!fp) {
                const hidden = form.querySelector<HTMLInputElement>(`input[name="${FP_FIELD}"]`);
                fp = hidden?.value || undefined;
            }

            const draft: FormDraft = {
                at: Date.now(),
                data,
                ...(fp ? { fingerprint: fp } : {}),
            };
            sessionStorage.setItem(this.key, JSON.stringify(draft));
        },

        restore(form: HTMLFormElement) {
            const parsed = readDraft(this.key);
            if (!parsed?.data) {
                return;
            }
            if (parsed.at && Date.now() - parsed.at > 1000 * 60 * 60 * 6) {
                sessionStorage.removeItem(this.key);
                return;
            }

            if (parsed.fingerprint) {
                ensureFingerprintField(form, parsed.fingerprint);
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
        },

        onInvalid(event: Event) {
            const target = event.target;
            if (!(target instanceof HTMLElement) || !isValidityTarget(target)) {
                return;
            }

            // Always suppress native tooltip / "not focusable" console noise.
            event.preventDefault();
            markNativeInvalid(target);

            if (this._invalidHandled) {
                return;
            }
            this._invalidHandled = true;
            // HTML5 blocks submit — snapshot this attempt once (first invalid field).
            this.stampSubmit(this.$el as HTMLFormElement);
            queueMicrotask(() => {
                this._invalidHandled = false;
            });

            this.revealTabFor(target);
            this.$nextTick(() => {
                requestAnimationFrame(() => focusControl(focusableControl(target)));
            });
        },

        focusInvalid(form: HTMLFormElement) {
            const invalid = form.querySelector(
                '.is-invalid .g-control, .is-invalid .textarea, .is-invalid .textarea-mini, .is-invalid .g-select__trigger, [aria-invalid="true"]',
            ) as HTMLElement | null;
            if (!invalid) {
                return;
            }
            this.revealTabFor(invalid);
            this.$nextTick(() => {
                requestAnimationFrame(() => focusControl(invalid));
            });
        },

        revealTabFor(el: HTMLElement) {
            const panel = el.closest<HTMLElement>('.design-panel[data-panel]');
            const tab = panel?.dataset.panel;
            if (!tab || !panel) {
                return;
            }
            if (panel.classList.contains('is-active')) {
                return;
            }
            panel.dispatchEvent(
                new CustomEvent('gereq:activate-tab', {
                    detail: { tab },
                    bubbles: true,
                }),
            );
            if (!panel.classList.contains('is-active')) {
                activateTabDom(panel, tab);
            }
        },

        clearFieldError(event: Event) {
            const target = event.target as HTMLElement | null;
            const field = target?.closest<HTMLElement>('[data-field].is-invalid');
            if (!field) {
                return;
            }
            // Keep red until the control is actually valid again (required empty → still red).
            const control = field.querySelector<ValidityTarget>(
                'input:not([type="hidden"]), textarea, select, input[type="hidden"][name]',
            );
            if (control && isValidityTarget(control) && control.willValidate && !control.validity.valid) {
                return;
            }
            field.classList.remove('is-invalid');
            field.querySelectorAll('[aria-invalid]').forEach((node) => node.removeAttribute('aria-invalid'));
            field.querySelector('.g-error[data-native-error]')?.remove();
            field.querySelector('.textarea-shell')?.classList.remove('is-invalid');
        },
    }));
}

function readDraft(key: string): FormDraft | null {
    const raw = sessionStorage.getItem(key);
    if (!raw) {
        return null;
    }
    try {
        const parsed = JSON.parse(raw) as FormDraft;
        if (!parsed || typeof parsed !== 'object' || !parsed.data) {
            return null;
        }
        return parsed;
    } catch {
        return null;
    }
}

function ensureFingerprintField(form: HTMLFormElement, fingerprint: string) {
    let input = form.querySelector<HTMLInputElement>(`input[name="${FP_FIELD}"]`);
    if (!input) {
        input = document.createElement('input');
        input.type = 'hidden';
        input.name = FP_FIELD;
        form.appendChild(input);
    }
    input.value = fingerprint;
}

function newFingerprint(): string {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID().replace(/-/g, '');
    }
    return `f${Date.now().toString(36)}${Math.random().toString(36).slice(2, 12)}`;
}

function isValidityTarget(el: HTMLElement): el is ValidityTarget {
    return (
        el instanceof HTMLInputElement ||
        el instanceof HTMLTextAreaElement ||
        el instanceof HTMLSelectElement
    );
}

function markNativeInvalid(control: ValidityTarget) {
    const field =
        control.closest<HTMLElement>('[data-field]') ||
        control.closest<HTMLElement>('.g-field, .g-check, .g-field--switch');
    if (!field) {
        return;
    }

    field.classList.add('is-invalid');
    control.setAttribute('aria-invalid', 'true');

    const trigger = field.querySelector<HTMLElement>('.g-select__trigger');
    trigger?.setAttribute('aria-invalid', 'true');

    const shell = field.querySelector<HTMLElement>('.textarea-shell');
    shell?.classList.add('is-invalid');

    const message = (control.validationMessage || '').trim() || 'Vyplňte toto pole.';
    let err = field.querySelector<HTMLElement>('.g-error[data-native-error]');
    if (!err) {
        err = document.createElement('p');
        err.className = 'g-error';
        err.dataset.nativeError = '1';
        field.appendChild(err);
    }
    err.textContent = message;

    if (control.id) {
        err.id = control.id + '-error';
        const described = (control.getAttribute('aria-describedby') || '')
            .split(/\s+/)
            .filter(Boolean);
        if (!described.includes(err.id)) {
            control.setAttribute('aria-describedby', [...described, err.id].join(' '));
        }
    }
}

function focusableControl(control: ValidityTarget): HTMLElement {
    const field = control.closest<HTMLElement>('.g-field, .g-check, .g-field--switch');
    const trigger = field?.querySelector<HTMLElement>('.g-select__trigger');
    const isSelectNative =
        control instanceof HTMLInputElement &&
        (control.type === 'hidden' || control.classList.contains('g-select__native'));
    if (isSelectNative && trigger) {
        return trigger;
    }
    return control;
}

function focusControl(el: HTMLElement) {
    try {
        el.focus({ preventScroll: true });
    } catch {
        /* hidden / not focusable — tab reveal should have fixed this */
    }
    el.closest('.g-field, .g-check, .g-switch, .g-field--switch')?.scrollIntoView({
        block: 'center',
        behavior: 'smooth',
    });
}

function activateTabDom(panel: HTMLElement, tab: string) {
    const root =
        panel.closest<HTMLElement>('.detail-hub, .design-wrap, .profile-hub, .module-hub') ||
        panel.parentElement;
    if (!root) {
        return;
    }
    root.querySelectorAll<HTMLElement>('.design-tab[data-tab]').forEach((el) => {
        const on = el.dataset.tab === tab;
        el.classList.toggle('is-active', on);
        el.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    root.querySelectorAll<HTMLElement>('.design-panel[data-panel]').forEach((el) => {
        el.classList.toggle('is-active', el.dataset.panel === tab);
    });
    try {
        history.replaceState(null, '', '#' + tab);
    } catch {
        /* ignore */
    }
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
        __GEREQ_FORM_FP_CLEARED__?: string[];
    }
}
