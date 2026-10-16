import type Alpine from '@alpinejs/csp';

type ContactKind = 'person' | 'company';

type CustomerContactFormState = {
    kind: ContactKind;
    init(): void;
    setPerson(): void;
    setCompany(): void;
    syncNameFields(): void;
    get isPerson(): boolean;
    get isCompany(): boolean;
    get personBtnClass(): string;
    get companyBtnClass(): string;
};

export function registerCustomerContactForm(alpine: typeof Alpine): void {
    alpine.data('customerContactForm', (): Alpine.AlpineComponent<CustomerContactFormState> => ({
        kind: 'person',

        init() {
            const el = this.$el as HTMLElement;
            const initial = el.dataset.kind;
            if (initial === 'company' || initial === 'person') {
                this.kind = initial;
            }
            this.syncNameFields();
        },

        setPerson() {
            this.kind = 'person';
            this.syncNameFields();
        },

        setCompany() {
            this.kind = 'company';
            this.syncNameFields();
        },

        syncNameFields() {
            const root = this.$el as HTMLElement;
            const person = root.querySelector<HTMLInputElement>('[data-contact-name="person"]');
            const company = root.querySelector<HTMLInputElement>('[data-contact-name="company"]');
            if (person) {
                const on = this.kind === 'person';
                person.disabled = !on;
                person.required = on;
            }
            if (company) {
                const on = this.kind === 'company';
                company.disabled = !on;
                company.required = on;
            }
        },

        get isPerson() {
            return this.kind === 'person';
        },

        get isCompany() {
            return this.kind === 'company';
        },

        get personBtnClass() {
            return this.kind === 'person' ? 'is-active' : '';
        },

        get companyBtnClass() {
            return this.kind === 'company' ? 'is-active' : '';
        },
    }));
}
