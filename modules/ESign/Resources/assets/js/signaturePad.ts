import type Alpine from '@alpinejs/csp';

declare global {
    interface Window {
        SignaturePad?: new (
            canvas: HTMLCanvasElement,
            options?: Record<string, unknown>,
        ) => {
            clear: () => void;
            isEmpty: () => boolean;
            toDataURL: (type?: string) => string;
            toData: () => unknown[];
            fromData: (data: unknown[]) => void;
            off: () => void;
        };
    }
}

const CDN = 'https://cdn.jsdelivr.net/npm/signature_pad@4.1.7/dist/signature_pad.umd.min.js';

let loading: Promise<void> | null = null;

function loadSignaturePad(): Promise<void> {
    if (window.SignaturePad) {
        return Promise.resolve();
    }
    if (loading) {
        return loading;
    }
    loading = new Promise((resolve, reject) => {
        const existing = document.querySelector<HTMLScriptElement>(`script[src="${CDN}"]`);
        if (existing) {
            existing.addEventListener('load', () => resolve());
            existing.addEventListener('error', () => reject(new Error('SignaturePad load failed')));
            if (window.SignaturePad) {
                resolve();
            }
            return;
        }
        const script = document.createElement('script');
        script.src = CDN;
        script.async = true;
        const nonce = document.documentElement.dataset.cspNonce;
        if (nonce) {
            script.nonce = nonce;
        }
        script.onload = () => resolve();
        script.onerror = () => reject(new Error('SignaturePad load failed'));
        document.head.appendChild(script);
    });
    return loading;
}

export function registerEsignSignaturePad(alpine: typeof Alpine): void {
    alpine.data('esignSignaturePad', () => ({
        pad: null as null | {
            clear: () => void;
            isEmpty: () => boolean;
            toDataURL: (type?: string) => string;
            toData: () => unknown[];
            fromData: (data: unknown[]) => void;
            off: () => void;
        },
        ready: false,

        async init() {
            const canvas = this.$el.querySelector('[data-esign-canvas]') as HTMLCanvasElement | null;
            if (!canvas) {
                return;
            }
            try {
                await loadSignaturePad();
            } catch {
                return;
            }
            if (!window.SignaturePad) {
                return;
            }

            const resize = () => {
                if (!this.pad) {
                    return;
                }
                const ratio = Math.max(window.devicePixelRatio || 1, 1);
                const rect = canvas.getBoundingClientRect();
                const data = this.pad.toData();
                canvas.width = Math.floor(rect.width * ratio);
                canvas.height = Math.floor(220 * ratio);
                const ctx = canvas.getContext('2d');
                ctx?.scale(ratio, ratio);
                this.pad.clear();
                if (data.length) {
                    this.pad.fromData(data);
                }
            };

            this.pad = new window.SignaturePad(canvas, {
                backgroundColor: 'rgb(255,255,255)',
                penColor: 'rgb(10,11,10)',
                minWidth: 1.2,
                maxWidth: 2.8,
            });
            this.ready = true;
            window.addEventListener('resize', resize);
            resize();

            const clearBtn = this.$el.querySelector('[data-esign-clear]');
            clearBtn?.addEventListener('click', () => {
                this.pad?.clear();
                const input = this.$el.querySelector('[data-esign-input]') as HTMLInputElement | null;
                if (input) {
                    input.value = '';
                }
                const err = this.$el.querySelector('[data-esign-error]') as HTMLElement | null;
                if (err) {
                    err.hidden = true;
                }
            });

            const form = this.$el.querySelector('[data-esign-form]') as HTMLFormElement | null;
            form?.addEventListener('submit', (event) => {
                if (!this.pad || this.pad.isEmpty()) {
                    event.preventDefault();
                    const err = this.$el.querySelector('[data-esign-error]') as HTMLElement | null;
                    if (err) {
                        err.hidden = false;
                    }
                    return;
                }
                const formInput = form.querySelector('[data-esign-form-input]') as HTMLInputElement | null;
                if (formInput) {
                    formInput.value = this.pad.toDataURL('image/png');
                }
            });

            const optionalForm = this.$el.matches('form')
                ? (this.$el as HTMLFormElement)
                : (this.$el.closest('form') as HTMLFormElement | null);
            optionalForm?.addEventListener('submit', () => {
                const input = this.$el.querySelector('[data-esign-input]') as HTMLInputElement | null;
                if (input && this.pad && !this.pad.isEmpty()) {
                    input.value = this.pad.toDataURL('image/png');
                }
            });
        },

        destroy() {
            this.pad?.off();
            this.pad = null;
        },
    }));
}
