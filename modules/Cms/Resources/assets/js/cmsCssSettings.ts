import type Alpine from '@alpinejs/csp';

/** Site-wide CSS dialog in the page builder. */
export function registerCmsCssSettings(alpine: typeof Alpine): void {
    alpine.data('gereqCmsCssSettings', () => ({
        open: false,
        saving: false,
        savedCss: '',
        openSettings() {
            const input = (this.$refs.form as HTMLFormElement).querySelector<HTMLTextAreaElement>('textarea[name="cms__custom_css_public"]');
            this.savedCss = input?.value ?? '';
            this.open = true;
            this.$nextTick(() => {
                (this.$refs.form as HTMLFormElement).querySelector<HTMLElement>('.css-editor')?.dispatchEvent(new CustomEvent('cms-css-refresh'));
            });
        },
        closeSettings() {
            if (this.saving) return;
            (this.$refs.form as HTMLFormElement).querySelector<HTMLElement>('.css-editor')?.dispatchEvent(new CustomEvent('cms-css-reset', { detail: this.savedCss }));
            this.open = false;
        },
        async saveSettings() {
            if (this.saving) return;
            const form = this.$refs.form as HTMLFormElement | undefined;
            if (!form) return;
            this.saving = true;
            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    body: new FormData(form),
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (!response.ok) throw new Error();
                const result = await response.json() as { message?: string };
                this.savedCss = form.querySelector<HTMLTextAreaElement>('textarea[name="cms__custom_css_public"]')?.value ?? '';
                this.open = false;
                window.gereq?.toast?.(result.message ?? '', 'success');
                void window.gereq?.staging?.refreshCount?.();
            } catch {
                window.gereq?.toast?.(form.dataset.error ?? '', 'error');
            } finally {
                this.saving = false;
            }
        },
        onEscape() {
            if (this.open) this.closeSettings();
        },
    }));
}
