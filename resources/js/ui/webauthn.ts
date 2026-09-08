/** WebAuthn helpers + Alpine components for MFA / passkeys (CSP-safe). */

function b64urlToBuffer(value: string): ArrayBuffer {
    const pad = '='.repeat((4 - (value.length % 4)) % 4);
    const base64 = (value + pad).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(base64);
    const bytes = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; i++) {
        bytes[i] = raw.charCodeAt(i);
    }

    return bytes.buffer;
}

function bufferToB64url(buffer: ArrayBuffer): string {
    const bytes = new Uint8Array(buffer);
    let str = '';
    for (let i = 0; i < bytes.length; i++) {
        str += String.fromCharCode(bytes[i]);
    }

    return btoa(str).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
}

function reviveCreateOptions(publicKey: Record<string, unknown>): PublicKeyCredentialCreationOptions {
    const user = publicKey.user as Record<string, unknown>;
    const exclude = (publicKey.excludeCredentials as Array<Record<string, unknown>> | undefined) ?? [];

    const options = {
        ...publicKey,
        challenge: b64urlToBuffer(String(publicKey.challenge)),
        user: {
            ...user,
            id: b64urlToBuffer(String(user.id)),
        },
    } as PublicKeyCredentialCreationOptions;

    if (exclude.length > 0) {
        options.excludeCredentials = exclude.map((item) => ({
            ...item,
            id: b64urlToBuffer(String(item.id)),
            type: (item.type as PublicKeyCredentialType) || 'public-key',
        }));
    } else {
        delete options.excludeCredentials;
    }

    return options;
}

function reviveRequestOptions(publicKey: Record<string, unknown>): PublicKeyCredentialRequestOptions {
    const allow = (publicKey.allowCredentials as Array<Record<string, unknown>> | undefined) ?? [];

    const options = {
        ...publicKey,
        challenge: b64urlToBuffer(String(publicKey.challenge)),
    } as PublicKeyCredentialRequestOptions;

    if (allow.length > 0) {
        options.allowCredentials = allow.map((item) => ({
            ...item,
            id: b64urlToBuffer(String(item.id)),
            type: (item.type as PublicKeyCredentialType) || 'public-key',
        }));
    } else {
        delete options.allowCredentials;
    }

    return options;
}

function credentialToJson(credential: PublicKeyCredential): Record<string, unknown> {
    const response = credential.response;
    const out: Record<string, unknown> = {
        id: credential.id,
        rawId: bufferToB64url(credential.rawId),
        type: credential.type,
        clientExtensionResults: credential.getClientExtensionResults?.() ?? {},
    };

    if (response instanceof AuthenticatorAttestationResponse) {
        out.response = {
            clientDataJSON: bufferToB64url(response.clientDataJSON),
            attestationObject: bufferToB64url(response.attestationObject),
            transports: typeof response.getTransports === 'function' ? response.getTransports() : [],
        };
    } else if (response instanceof AuthenticatorAssertionResponse) {
        out.response = {
            clientDataJSON: bufferToB64url(response.clientDataJSON),
            authenticatorData: bufferToB64url(response.authenticatorData),
            signature: bufferToB64url(response.signature),
            userHandle: response.userHandle ? bufferToB64url(response.userHandle) : null,
        };
    }

    return out;
}

async function postJson(url: string, body: Record<string, unknown> = {}): Promise<Record<string, unknown>> {
    const res = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': window.__GEREQ_CSRF__ || '',
        },
        body: JSON.stringify(body),
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) {
        const message =
            (json as { error?: { message?: string }; message?: string }).error?.message
            || (json as { message?: string }).message
            || `HTTP ${res.status}`;
        throw new Error(message);
    }

    return ((json as { data?: Record<string, unknown> }).data ?? json) as Record<string, unknown>;
}

function formatWebAuthnError(err: unknown): string | null {
    if (typeof window !== 'undefined' && !window.isSecureContext) {
        return 'Passkeys vyžadují secure context. Na realestates.local použijte HTTPS a v prohlížeči certifikát jednou přijměte (nebo mkcert). Doména *.localhost z hosts na LAN nefunguje — prohlížeč ji vždy pošle na 127.0.0.1.';
    }
    if (err instanceof DOMException) {
        if (err.name === 'AbortError' || err.name === 'NotAllowedError') {
            return 'Passkey byl zrušen, nebo ho prohlížeč nepovolil. Ověřte, že v URL je zelený/zámek HTTPS (ne „Nezabezpečeno“) na hostname z hosts (např. https://realestates.local).';
        }
        if (err.name === 'InvalidStateError') {
            return 'Tento passkey je už zaregistrovaný.';
        }
        if (err.name === 'NotSupportedError') {
            return 'Tento prohlížeč nebo zařízení passkeys nepodporuje.';
        }
        if (err.name === 'SecurityError') {
            return 'Neplatná doména pro passkeys. Použijte hostname (ne IP). Na LAN: hosts → realestates.local a https://realestates.local (cert musí obsahovat toto jméno).';
        }
        return err.message ? `${err.name}: ${err.message}` : err.name;
    }
    if (err instanceof Error) {
        const msg = err.message || err.name;
        if (msg === 'cancelled') {
            return null;
        }
        return msg;
    }

    return String(err);
}

async function askConfirm(opts: {
    title?: string;
    body?: string;
    confirmLabel?: string;
    danger?: boolean;
}): Promise<boolean> {
    if (window.gereq?.confirm) {
        return window.gereq.confirm(opts);
    }
    return window.confirm(opts.body || opts.title || '');
}

async function askPrompt(opts: {
    title?: string;
    body?: string;
    inputLabel?: string;
    inputValue?: string;
    inputType?: 'text' | 'password';
    inputPlaceholder?: string;
    confirmLabel?: string;
    danger?: boolean;
}): Promise<string | null> {
    if (window.gereq?.prompt) {
        return window.gereq.prompt({
            ...opts,
            input: true,
            inputRequired: true,
            danger: opts.danger === true,
        });
    }
    return window.prompt(opts.body || opts.inputLabel || opts.title || '', opts.inputValue || '');
}

/** Password first; if cancelled/empty, optional TOTP code. */
async function askPasswordOrCode(labels: Record<string, string>): Promise<{ password: string; code: string } | null> {
    const password = await askPrompt({
        title: labels.passwordPrompt || '',
        body: '',
        inputLabel: labels.passwordPrompt || '',
        inputType: 'password',
        confirmLabel: labels.continueLabel || undefined,
        danger: false,
    });
    if (password === null) {
        return null;
    }
    if (password.trim() !== '') {
        return { password: password.trim(), code: '' };
    }
    const code = await askPrompt({
        title: labels.codePrompt || '',
        body: '',
        inputLabel: labels.codePrompt || '',
        inputType: 'text',
        inputPlaceholder: '000000',
        confirmLabel: labels.continueLabel || undefined,
        danger: false,
    });
    if (code === null || code.trim() === '') {
        return null;
    }

    return { password: '', code: code.trim() };
}

type SecurityConfig = {
    totpEnabled?: boolean;
    passkeyEnabled?: boolean;
    passkeys?: Array<{ id: string; name: string; created_at: string }>;
    urls?: {
        totpStart: string;
        totpConfirm: string;
        totpDisable: string;
        recovery: string;
        passkeyOptions: string;
        passkeyRegister: string;
        passkeyDelete: string;
    };
    labels?: Record<string, string>;
};

function readConfig(el: HTMLElement): SecurityConfig {
    try {
        return JSON.parse(el.getAttribute('data-config') || '{}') as SecurityConfig;
    } catch {
        return {};
    }
}

export function registerWebauthn(alpine: typeof import('@alpinejs/csp').default): void {
    alpine.data('gereqPasskeyLogin', () => ({
        busy: false,
        error: '',
        optionsUrl: '',
        verifyUrl: '',

        init() {
            const el = this.$el as HTMLElement;
            this.optionsUrl = el.dataset.optionsUrl || '';
            this.verifyUrl = el.dataset.verifyUrl || '';
        },

        async loginWithPasskey() {
            this.error = '';
            this.busy = true;
            try {
                const emailInput = document.querySelector<HTMLInputElement>('input[name="email"]');
                const rememberInput = document.querySelector<HTMLInputElement>('input[name="remember"]');
                const email = emailInput?.value?.trim() || '';
                const data = await postJson(this.optionsUrl, email ? { email } : {});
                const options = (data.options as { publicKey: Record<string, unknown> }).publicKey;
                const cred = (await navigator.credentials.get({
                    publicKey: reviveRequestOptions(options),
                })) as PublicKeyCredential | null;
                if (!cred) {
                    throw new Error('cancelled');
                }
                const verified = await postJson(this.verifyUrl, {
                    credential: credentialToJson(cred),
                    remember: Boolean(rememberInput?.checked),
                });
                window.location.href = String(verified.redirect || '/admin');
            } catch (err) {
                const msg = formatWebAuthnError(err);
                if (msg) {
                    this.error = msg;
                }
            } finally {
                this.busy = false;
            }
        },

        passkeyButtonLabel() {
            const el = this.$el as HTMLElement;
            if (this.busy) {
                return el.dataset.labelWaiting || '…';
            }

            return el.dataset.labelPasskey || 'Passkey';
        },
    }));

    alpine.data('gereqMfaChallenge', () => ({
        busy: false,
        error: '',
        passkeyEnabled: false,
        optionsUrl: '',
        verifyUrl: '',

        init() {
            const el = this.$el as HTMLElement;
            this.passkeyEnabled = el.dataset.passkeyEnabled === '1';
            this.optionsUrl = el.dataset.optionsUrl || '';
            this.verifyUrl = el.dataset.verifyUrl || '';
        },

        async usePasskey() {
            this.error = '';
            this.busy = true;
            try {
                const data = await postJson(this.optionsUrl);
                const options = (data.options as { publicKey: Record<string, unknown> }).publicKey;
                const cred = (await navigator.credentials.get({
                    publicKey: reviveRequestOptions(options),
                })) as PublicKeyCredential | null;
                if (!cred) {
                    throw new Error('cancelled');
                }
                const verified = await postJson(this.verifyUrl, credentialToJson(cred));
                window.location.href = String(verified.redirect || '/admin');
            } catch (err) {
                const msg = formatWebAuthnError(err);
                if (msg) {
                    this.error = msg;
                }
            } finally {
                this.busy = false;
            }
        },

        passkeyButtonLabel() {
            const el = this.$el as HTMLElement;
            if (this.busy) {
                return el.dataset.labelWaiting || '…';
            }

            return el.dataset.labelPasskey || 'Passkey';
        },
    }));

    alpine.data('gereqSecurityPanel', () => ({
        busy: false,
        error: '',
        totpEnabled: false,
        passkeyEnabled: false,
        passkeys: [] as Array<{ id: string; name: string; created_at: string }>,
        setupOpen: false,
        qr: '',
        secret: '',
        setupCode: '',
        recoveryCodes: [] as string[],
        labels: {} as Record<string, string>,
        urls: {
            totpStart: '',
            totpConfirm: '',
            totpDisable: '',
            recovery: '',
            passkeyOptions: '',
            passkeyRegister: '',
            passkeyDelete: '',
        },

        init() {
            const cfg = readConfig(this.$el as HTMLElement);
            this.totpEnabled = Boolean(cfg.totpEnabled);
            this.passkeyEnabled = Boolean(cfg.passkeyEnabled);
            this.passkeys = Array.isArray(cfg.passkeys) ? cfg.passkeys : [];
            this.labels = cfg.labels || {};
            if (cfg.urls) {
                this.urls = cfg.urls;
            }
        },

        showEnableTotp() {
            return !this.totpEnabled && !this.setupOpen;
        },

        showManageTotp() {
            return this.totpEnabled && !this.setupOpen;
        },

        hasRecovery() {
            return this.recoveryCodes.length > 0;
        },

        hasPasskeys() {
            return this.passkeys.length > 0;
        },

        totpBadgeClass() {
            return this.totpEnabled ? 'badge badge--ok' : 'badge badge--muted';
        },

        totpBadgeLabel() {
            const el = this.$el as HTMLElement;
            return this.totpEnabled
                ? (el.dataset.labelEnabled || 'On')
                : (el.dataset.labelDisabled || 'Off');
        },

        passkeyBadgeClass() {
            return this.hasPasskeys() ? 'badge badge--ok' : 'badge badge--muted';
        },

        passkeyBadgeLabel() {
            const el = this.$el as HTMLElement;
            if (this.hasPasskeys()) {
                return String(this.passkeys.length);
            }

            return el.dataset.labelDisabled || 'Off';
        },

        passkeyAddLabel() {
            if (this.busy) {
                return this.labels.waiting || '…';
            }

            return this.labels.addPasskey || 'Add passkey';
        },

        async startTotp() {
            this.error = '';
            this.busy = true;
            try {
                const data = await postJson(this.urls.totpStart);
                this.qr = String(data.qr || '');
                this.secret = String(data.secret || '');
                this.setupCode = '';
                this.setupOpen = true;
            } catch (err) {
                this.error = err instanceof Error ? err.message : String(err);
            } finally {
                this.busy = false;
            }
        },

        cancelSetup() {
            this.setupOpen = false;
            this.qr = '';
            this.secret = '';
            this.setupCode = '';
        },

        async confirmTotp() {
            this.error = '';
            this.busy = true;
            try {
                const data = await postJson(this.urls.totpConfirm, { code: this.setupCode });
                this.totpEnabled = true;
                this.cancelSetup();
                this.recoveryCodes = Array.isArray(data.recovery_codes)
                    ? (data.recovery_codes as string[])
                    : [];
            } catch (err) {
                this.error = err instanceof Error ? err.message : String(err);
            } finally {
                this.busy = false;
            }
        },

        async disableTotp() {
            const ok = await askConfirm({
                title: this.labels.confirmDisable || '',
                body: this.labels.confirmDisable || '',
                danger: true,
            });
            if (!ok) {
                return;
            }
            const auth = await askPasswordOrCode(this.labels);
            if (!auth) {
                return;
            }
            this.error = '';
            this.busy = true;
            try {
                await postJson(this.urls.totpDisable, auth);
                this.totpEnabled = false;
                this.recoveryCodes = [];
            } catch (err) {
                this.error = err instanceof Error ? err.message : String(err);
            } finally {
                this.busy = false;
            }
        },

        async regenerateRecovery() {
            const auth = await askPasswordOrCode(this.labels);
            if (!auth) {
                return;
            }
            this.error = '';
            this.busy = true;
            try {
                const data = await postJson(this.urls.recovery, auth);
                this.recoveryCodes = Array.isArray(data.recovery_codes)
                    ? (data.recovery_codes as string[])
                    : [];
            } catch (err) {
                this.error = err instanceof Error ? err.message : String(err);
            } finally {
                this.busy = false;
            }
        },

        async copyRecovery() {
            const text = this.recoveryCodes.join('\n');
            try {
                await navigator.clipboard.writeText(text);
                this.error = this.labels.copyOk || '';
            } catch {
                this.error = text;
            }
        },

        async addPasskey() {
            this.error = '';
            this.busy = true;
            try {
                if (!window.isSecureContext) {
                    throw new DOMException(
                        'Insecure context',
                        'SecurityError',
                    );
                }
                if (!window.PublicKeyCredential) {
                    throw new DOMException('Passkeys nejsou podporované.', 'NotSupportedError');
                }
                const data = await postJson(this.urls.passkeyOptions);
                const options = (data.options as { publicKey: Record<string, unknown> }).publicKey;
                const cred = (await navigator.credentials.create({
                    publicKey: reviveCreateOptions(options),
                })) as PublicKeyCredential | null;
                if (!cred) {
                    throw new Error('cancelled');
                }
                const named = await askPrompt({
                    title: this.labels.passkeyName || '',
                    body: '',
                    inputLabel: this.labels.passkeyName || '',
                    inputValue: 'Passkey',
                    inputType: 'text',
                    confirmLabel: this.labels.addPasskey || undefined,
                    danger: false,
                });
                if (named === null) {
                    return;
                }
                const name = named.trim() !== '' ? named.trim() : 'Passkey';
                const registered = await postJson(this.urls.passkeyRegister, {
                    name,
                    credential: credentialToJson(cred),
                });
                const item = registered.credential as { id: string; name: string; created_at: string };
                if (item?.id) {
                    this.passkeys = [
                        {
                            id: item.id,
                            name: item.name,
                            created_at: new Date(item.created_at).toLocaleString(),
                        },
                        ...this.passkeys,
                    ];
                    this.passkeyEnabled = true;
                }
            } catch (err) {
                const msg = formatWebAuthnError(err);
                if (msg) {
                    this.error = msg;
                }
            } finally {
                this.busy = false;
            }
        },

        async removePasskey(id: string) {
            const password = await askPrompt({
                title: this.labels.passwordPrompt || '',
                body: '',
                inputLabel: this.labels.passwordPrompt || '',
                inputType: 'password',
                confirmLabel: undefined,
                danger: true,
            });
            if (password === null || password.trim() === '') {
                return;
            }
            this.error = '';
            this.busy = true;
            try {
                const url = this.urls.passkeyDelete.replace('__ID__', encodeURIComponent(id));
                await postJson(url, { password: password.trim() });
                this.passkeys = this.passkeys.filter((item) => item.id !== id);
                this.passkeyEnabled = this.passkeys.length > 0;
            } catch (err) {
                this.error = err instanceof Error ? err.message : String(err);
            } finally {
                this.busy = false;
            }
        },
    }));
}
