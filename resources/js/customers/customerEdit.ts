import type Alpine from '@alpinejs/csp';

type CustomerEditState = {
    displayName: string;
    emptyLabel: string;
    emptyInitials: string;
    init(): void;
    onFormInput(event: Event): void;
    initials(): string;
    title(): string;
};

export function registerCustomerEdit(alpine: typeof Alpine): void {
    alpine.data('customerEdit', (): CustomerEditState => ({
        displayName: '',
        emptyLabel: '',
        emptyInitials: '?',

        init() {
            const el = this.$el as HTMLElement;
            this.displayName = el.dataset.name ?? '';
            this.emptyLabel = el.dataset.emptyLabel ?? '';
            this.emptyInitials = el.dataset.emptyInitials || '?';
        },

        onFormInput(event: Event) {
            const target = event.target as HTMLInputElement | null;
            if (target?.name === 'name') {
                this.displayName = target.value;
            }
        },

        initials() {
            const parts = String(this.displayName || '')
                .trim()
                .split(/\s+/)
                .filter(Boolean);
            if (!parts.length) {
                return this.emptyInitials;
            }
            return parts
                .slice(0, 2)
                .map((part) => part.charAt(0))
                .join('')
                .toUpperCase();
        },

        title() {
            const name = String(this.displayName || '').trim();
            return name !== '' ? name : this.emptyLabel;
        },
    }));
}
