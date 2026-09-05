import type Alpine from '@alpinejs/csp';

type ConfirmOptions = {
    title?: string;
    body?: string;
    confirmLabel?: string;
    cancelLabel?: string;
    danger?: boolean;
};

type ConfirmApi = {
    confirm: (opts: ConfirmOptions | string) => Promise<boolean>;
};

declare global {
    interface Window {
        gereq?: ConfirmApi & Record<string, unknown>;
    }
}

const defaults = {
    title: 'Potvrzení',
    body: 'Opravdu chcete pokračovat?',
    confirmLabel: 'Potvrdit',
    cancelLabel: 'Zrušit',
};

function readHostDefaults(): void {
    const host = document.getElementById('gereq-confirm');
    if (!host) {
        return;
    }
    defaults.title = host.dataset.i18nTitle || defaults.title;
    defaults.body = host.dataset.i18nBody || defaults.body;
    defaults.confirmLabel = host.dataset.i18nConfirm || defaults.confirmLabel;
    defaults.cancelLabel = host.dataset.i18nCancel || defaults.cancelLabel;
}

function normalize(opts: ConfirmOptions | string): ConfirmOptions {
    if (typeof opts === 'string') {
        return { body: opts };
    }
    return opts;
}

type AskFn = (opts: ConfirmOptions) => Promise<boolean>;

let askImpl: AskFn | null = null;

export function registerConfirm(alpine: typeof Alpine): void {
    readHostDefaults();

    alpine.data('gereqConfirm', () => ({
        open: false,
        title: defaults.title,
        body: defaults.body,
        confirmLabel: defaults.confirmLabel,
        cancelLabel: defaults.cancelLabel,
        danger: true,
        _resolve: null as ((value: boolean) => void) | null,

        init() {
            readHostDefaults();
            this.title = defaults.title;
            this.body = defaults.body;
            this.confirmLabel = defaults.confirmLabel;
            this.cancelLabel = defaults.cancelLabel;
            askImpl = (opts) => this.ask(opts);
        },

        destroy() {
            askImpl = null;
        },

        ask(opts: ConfirmOptions = {}): Promise<boolean> {
            if (this._resolve) {
                this._resolve(false);
                this._resolve = null;
            }
            this.title = opts.title || defaults.title;
            this.body = opts.body || defaults.body;
            this.confirmLabel = opts.confirmLabel || defaults.confirmLabel;
            this.cancelLabel = opts.cancelLabel || defaults.cancelLabel;
            this.danger = opts.danger !== false;
            this.open = true;
            this.$nextTick(() => (this.$refs.confirmBtn as HTMLButtonElement | undefined)?.focus());

            return new Promise<boolean>((resolve) => {
                this._resolve = resolve;
            });
        },

        accept() {
            this.open = false;
            this._resolve?.(true);
            this._resolve = null;
        },

        cancel() {
            this.open = false;
            this._resolve?.(false);
            this._resolve = null;
        },

        onEscape() {
            if (this.open) {
                this.cancel();
            }
        },
    }));

    const api: ConfirmApi = {
        confirm(opts) {
            const options = normalize(opts);
            if (!askImpl) {
                return Promise.resolve(window.confirm(options.body || defaults.body));
            }
            return askImpl(options);
        },
    };

    window.gereq = { ...(window.gereq || {}), ...api };

    document.addEventListener(
        'submit',
        (event) => {
            const form = event.target;
            if (!(form instanceof HTMLFormElement)) {
                return;
            }
            if (form.dataset.confirmAccepted === '1') {
                delete form.dataset.confirmAccepted;
                return;
            }

            const body = form.dataset.confirm?.trim() || '';
            if (body === '') {
                return;
            }

            event.preventDefault();
            event.stopPropagation();

            void api
                .confirm({
                    title: form.dataset.confirmTitle || defaults.title,
                    body,
                    confirmLabel: form.dataset.confirmLabel || undefined,
                    cancelLabel: form.dataset.confirmCancel || undefined,
                    danger: form.dataset.confirmDanger !== '0',
                })
                .then((ok) => {
                    if (!ok) {
                        return;
                    }
                    form.dataset.confirmAccepted = '1';
                    if (typeof form.requestSubmit === 'function') {
                        form.requestSubmit();
                    } else {
                        form.submit();
                    }
                });
        },
        true,
    );
}
