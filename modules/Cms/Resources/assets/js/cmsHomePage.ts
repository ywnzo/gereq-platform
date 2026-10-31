import type Alpine from '@alpinejs/csp';

export function registerCmsHomePage(alpine: typeof Alpine): void {
    alpine.data('cmsHomePage', () => ({
        isHome: false,
        busy: false,
        confirmMessage: '',
        label: '',
        error: '',
        init() {
            const root = this.$el as HTMLElement;
            this.isHome = root.dataset.isHome === '1';
            this.confirmMessage = root.dataset.confirm ?? '';
            this.label = root.dataset.label ?? '';
            this.error = root.dataset.error ?? '';
        },
        async setHome() {
            if (this.busy || this.isHome || !window.gereq?.confirm) return;
            this.busy = true;
            try {
                const confirmed = await window.gereq.confirm({ title: this.label, body: this.confirmMessage, confirmLabel: this.label, danger: false });
                if (!confirmed) return;
                const form = this.$refs.form as HTMLFormElement;
                const response = await fetch(form.action, {
                    method: 'POST',
                    body: new FormData(form),
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (!response.ok) throw new Error();
                const result = await response.json() as { message?: string };
                this.isHome = true;
                window.gereq?.toast?.(result.message ?? '', 'success');
                void window.gereq?.staging?.refreshCount?.();
            } catch {
                window.gereq?.toast?.(this.error, 'error');
            } finally {
                this.busy = false;
            }
        },
    }));
}
