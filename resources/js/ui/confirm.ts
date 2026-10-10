import type Alpine from '@alpinejs/csp';

type ConfirmOptions = {
    title?: string;
    body?: string;
    confirmLabel?: string;
    cancelLabel?: string;
    danger?: boolean;
    input?: boolean;
    inputLabel?: string;
    inputValue?: string;
    inputType?: 'text' | 'password' | 'textarea' | 'editor';
    inputPlaceholder?: string;
    inputRequired?: boolean;
};

type ConfirmApi = {
    confirm: (opts: ConfirmOptions | string) => Promise<boolean>;
    prompt: (opts: ConfirmOptions | string) => Promise<string | null>;
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

type AskResult = { ok: boolean; value: string };
type AskFn = (opts: ConfirmOptions) => Promise<AskResult>;

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
        showInput: false,
        inputLabel: '',
        inputValue: '',
        inputType: 'text',
        inputPlaceholder: '',
        inputRequired: false,
        _resolve: null as ((value: AskResult) => void) | null,

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

        ask(opts: ConfirmOptions = {}): Promise<AskResult> {
            if (this._resolve) {
                this._resolve({ ok: false, value: '' });
                this._resolve = null;
            }
            this.title = opts.title || defaults.title;
            this.body = opts.body || defaults.body;
            this.confirmLabel = opts.confirmLabel || defaults.confirmLabel;
            this.cancelLabel = opts.cancelLabel || defaults.cancelLabel;
            this.danger = opts.danger !== false;
            this.showInput = Boolean(opts.input);
            this.inputLabel = opts.inputLabel || '';
            this.inputValue = opts.inputValue || '';
            this.inputType = opts.inputType === 'password'
                ? 'password'
                : opts.inputType === 'textarea'
                    ? 'textarea'
                    : opts.inputType === 'editor'
                        ? 'editor'
                        : 'text';
            this.inputPlaceholder = opts.inputPlaceholder || '';
            this.inputRequired = Boolean(opts.inputRequired);
            this.open = true;
            this.$nextTick(() => {
                if (this.inputType === 'editor') {
                    this.seedEditor(this.inputValue);
                    return;
                }
                if (this.showInput) {
                    const field = this.inputType === 'textarea'
                        ? (this.$refs.textareaField as HTMLTextAreaElement | undefined)
                        : (this.$refs.inputField as HTMLInputElement | undefined);
                    field?.focus();
                    field?.select();
                } else {
                    (this.$refs.confirmBtn as HTMLButtonElement | undefined)?.focus();
                }
            });

            return new Promise<AskResult>((resolve) => {
                this._resolve = resolve;
            });
        },

        seedEditor(html: string) {
            const host = this.$refs.editorHost as HTMLElement | undefined;
            const root = host?.querySelector('.g-editor') as HTMLElement | undefined;
            root?.dispatchEvent(new CustomEvent('gereq-editor-set', { detail: { html } }));
        },

        editorHtml(): string {
            const host = this.$refs.editorHost as HTMLElement | undefined;
            const input = host?.querySelector('.g-editor__input') as HTMLTextAreaElement | undefined;
            return input?.value ?? this.inputValue;
        },

        isEmptyHtml(html: string): boolean {
            const text = html.replace(/<[^>]*>/g, ' ').replace(/&nbsp;/gi, ' ').replace(/\s+/g, ' ').trim();
            return text === '';
        },

        accept() {
            const value = this.inputType === 'editor' ? this.editorHtml() : this.inputValue;
            if (this.showInput && this.inputRequired && (this.inputType === 'editor' ? this.isEmptyHtml(value) : value.trim() === '')) {
                if (this.inputType === 'editor') {
                    this.seedEditor(value);
                    return;
                }
                const field = this.inputType === 'textarea'
                    ? (this.$refs.textareaField as HTMLTextAreaElement | undefined)
                    : (this.$refs.inputField as HTMLInputElement | undefined);
                field?.focus();
                return;
            }
            this.open = false;
            this._resolve?.({ ok: true, value });
            this._resolve = null;
        },

        cancel() {
            this.open = false;
            this._resolve?.({ ok: false, value: '' });
            this._resolve = null;
        },

        onEscape() {
            if (this.open) {
                this.cancel();
            }
        },
    }));

    const api: ConfirmApi = {
        async confirm(opts) {
            const options = normalize(opts);
            if (!askImpl) {
                return window.confirm(options.body || defaults.body);
            }
            const result = await askImpl({ ...options, input: false });
            return result.ok;
        },
        async prompt(opts) {
            const options = normalize(opts);
            if (!askImpl) {
                const value = window.prompt(options.body || options.title || '', options.inputValue || '');
                return value;
            }
            const result = await askImpl({
                ...options,
                input: true,
                danger: options.danger === true,
                inputRequired: options.inputRequired !== false,
            });
            return result.ok ? result.value : null;
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
            const promptName = form.dataset.promptName?.trim() || '';
            if (body === '' && promptName === '') {
                return;
            }

            event.preventDefault();
            event.stopPropagation();

            void (async () => {
                if (promptName !== '') {
                    const value = await api.prompt({
                        title: form.dataset.confirmTitle || defaults.title,
                        body,
                        confirmLabel: form.dataset.confirmLabel || undefined,
                        cancelLabel: form.dataset.confirmCancel || undefined,
                        danger: form.dataset.confirmDanger !== '0',
                        inputLabel: form.dataset.promptLabel || undefined,
                        inputPlaceholder: form.dataset.promptPlaceholder || undefined,
                        inputType: form.dataset.promptType === 'editor'
                            ? 'editor'
                            : form.dataset.promptType === 'textarea'
                                ? 'textarea'
                                : 'text',
                    });
                    if (value === null) {
                        return;
                    }
                    const looksEmpty = value.replace(/<[^>]*>/g, ' ').replace(/&nbsp;/gi, ' ').replace(/\s+/g, ' ').trim() === '';
                    if (looksEmpty) {
                        return;
                    }
                    let field = form.querySelector(`[name="${CSS.escape(promptName)}"]`);
                    if (!(field instanceof HTMLInputElement) && !(field instanceof HTMLTextAreaElement)) {
                        const hidden = document.createElement('input');
                        hidden.type = 'hidden';
                        hidden.name = promptName;
                        form.appendChild(hidden);
                        field = hidden;
                    }
                    field.value = value;
                    form.dataset.confirmAccepted = '1';
                    if (typeof form.requestSubmit === 'function') {
                        form.requestSubmit();
                    } else {
                        form.submit();
                    }
                    return;
                }

                const ok = await api.confirm({
                    title: form.dataset.confirmTitle || defaults.title,
                    body,
                    confirmLabel: form.dataset.confirmLabel || undefined,
                    cancelLabel: form.dataset.confirmCancel || undefined,
                    danger: form.dataset.confirmDanger !== '0',
                });
                if (!ok) {
                    return;
                }

                // Optional second question (e.g. module uninstall → also purge media?).
                // Cancel here does not abort the submit — it only sets the follow-up field to 0.
                const followup = form.dataset.confirmFollowup?.trim() || '';
                if (followup !== '') {
                    const followOk = await api.confirm({
                        title: form.dataset.confirmFollowupTitle || defaults.title,
                        body: followup,
                        confirmLabel: form.dataset.confirmFollowupLabel || undefined,
                        cancelLabel: form.dataset.confirmFollowupCancel || undefined,
                        danger: form.dataset.confirmFollowupDanger !== '0',
                    });
                    const field = form.dataset.confirmFollowupInput?.trim() || '';
                    if (field !== '') {
                        const input = form.querySelector(`input[name="${CSS.escape(field)}"]`);
                        if (input instanceof HTMLInputElement) {
                            input.value = followOk ? '1' : '0';
                        }
                    }
                }

                form.dataset.confirmAccepted = '1';
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    form.submit();
                }
            })();
        },
        true,
    );
}
