import type Alpine from '@alpinejs/csp';

export function registerSetupCompany(alpine: typeof Alpine): void {
    alpine.data('gereqSetupCompany', () => ({
        init() {
            this.syncCompany();
        },
        syncCompany() {
            const form = this.$el as HTMLFormElement;
            const enabled = form.querySelector<HTMLInputElement>('[name="is_company"]')?.value === '1';
            const fields = form.querySelector<HTMLElement>('[data-company-fields]');
            if (!fields) return;
            fields.hidden = !enabled;
            fields.querySelectorAll<HTMLInputElement | HTMLSelectElement | HTMLButtonElement | HTMLTextAreaElement>('input,select,button,textarea')
                .forEach((field) => { field.disabled = !enabled; });
        },
    }));
}
