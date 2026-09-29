import type Alpine from '@alpinejs/csp';

type CustomerNotesState = {
    composing: boolean;
    toggle(): void;
    open(): void;
    close(): void;
};

export function registerCustomerNotes(alpine: typeof Alpine): void {
    alpine.data('customerNotes', (): CustomerNotesState => ({
        composing: false,
        toggle() {
            this.composing = !this.composing;
        },
        open() {
            this.composing = true;
        },
        close() {
            this.composing = false;
        },
    }));
}
