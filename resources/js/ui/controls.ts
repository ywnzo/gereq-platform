import type Alpine from '@alpinejs/csp';

export function registerPassword(alpine: typeof Alpine): void {
    alpine.data('gereqPassword', () => ({
        revealed: false,

        get toggleLabel(): string {
            return this.revealed ? 'Skrýt heslo' : 'Zobrazit heslo';
        },

        toggle() {
            this.revealed = !this.revealed;
            const input = this.$refs.input as HTMLInputElement | undefined;
            if (input) {
                input.type = this.revealed ? 'text' : 'password';
            }
        },
    }));
}

export function registerSwitch(alpine: typeof Alpine): void {
    alpine.data('gereqSwitch', () => ({
        on: false,

        init() {
            this.on = (this.$el as HTMLElement).dataset.on === '1';
        },

        get trackClass(): string {
            return this.on ? 'is-on' : '';
        },

        get checkedAttr(): string {
            return this.on ? 'true' : 'false';
        },

        toggle() {
            this.on = !this.on;
            const input = this.$refs.value as HTMLInputElement | undefined;
            if (input) {
                input.value = this.on ? '1' : '0';
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }
        },
    }));
}

export function registerTextarea(alpine: typeof Alpine): void {
    alpine.data('gereqTextarea', () => ({
        count: 0,
        max: 0,
        selected: 0,

        init() {
            const input = this.$refs.input as HTMLTextAreaElement | undefined;
            this.max = Number(input?.getAttribute('maxlength') || 0);
            this.sync();
        },

        sync() {
            const input = this.$refs.input as HTMLTextAreaElement | undefined;
            if (!input) {
                return;
            }
            this.count = input.value.length;
            const start = input.selectionStart ?? 0;
            const end = input.selectionEnd ?? 0;
            this.selected = Math.max(0, end - start);
        },

        selectAll() {
            const input = this.$refs.input as HTMLTextAreaElement | undefined;
            if (!input) {
                return;
            }
            input.focus();
            input.select();
            this.sync();
        },

        get selectedLabel(): string {
            return this.selected > 0 ? 'Označeno ' + this.selected + ' znaků' : 'Nic neoznačeno';
        },

        get countLabel(): string {
            return this.max > 0 ? this.count + ' / ' + this.max : this.count + ' znaků';
        },
    }));
}
