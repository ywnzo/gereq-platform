import type Alpine from '@alpinejs/csp';

function normalize(value: string): string {
    return value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase();
}

export function registerSetup(alpine: typeof Alpine): void {
    alpine.data('setupModulePicker', () => ({
        query: '',
        category: '',
        $el: null as unknown as HTMLElement,
        choices: [] as { search: string; categories: string }[],
        init() {
            this.choices = Array.from(this.$el.querySelectorAll<HTMLElement>('[data-module-choice]'))
                .map(el => ({ search: el.dataset.search || '', categories: el.dataset.categories || '[]' }));
        },
        matches(search: string, categories: string): boolean {
            const words = normalize(this.query).trim().split(/\s+/).filter(Boolean);
            return words.every(word => normalize(search).includes(word))
                && (this.category === '' || (JSON.parse(categories) as string[]).includes(this.category));
        },
        get visibleCount(): number {
            return this.choices.filter(item => this.matches(item.search, item.categories)).length;
        },
    }));
    alpine.data('setupOperator', () => ({
        type: 'business',
        $el: null as unknown as HTMLElement,
        init() {
            this.type = (this.$el as HTMLElement).dataset.type === 'person' ? 'person' : 'business';
        },
        get business(): boolean { return this.type === 'business'; },
        get person(): boolean { return this.type === 'person'; },
    }));
}
