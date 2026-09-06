type PortalFormState = {
    providerId: string;
    pick(id: string): void;
    cardClass(id: string): string;
    checkedAttr(id: string): string;
    isProvider(id: string): boolean;
};

export function registerPortalForm(alpine: typeof import('@alpinejs/csp').default): void {
    alpine.data(
        'portalAccountForm',
        (initial: { providerId?: string } = {}): PortalFormState => ({
            providerId: initial.providerId || '',

            pick(id: string) {
                this.providerId = id;
            },

            cardClass(id: string): string {
                return this.providerId === id ? 'is-active' : '';
            },

            checkedAttr(id: string): string {
                return this.providerId === id ? 'true' : 'false';
            },

            isProvider(id: string): boolean {
                return this.providerId === id;
            },
        }),
    );
}
