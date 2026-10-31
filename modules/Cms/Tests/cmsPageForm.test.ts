import { expect, test } from 'bun:test';
import { registerCmsPageForm } from '../Resources/assets/js/cmsPageForm';

function setup(isNew: boolean, initialSlug = '') {
    let component: any;
    registerCmsPageForm({ data: (_name: string, factory: () => unknown) => { component = factory(); } } as any);
    const title = new EventTarget() as EventTarget & { value: string };
    const slug = new EventTarget() as EventTarget & { value: string };
    title.value = '';
    slug.value = initialSlug;
    const form = { querySelector: (selector: string) => selector.includes('"title"') ? title : selector.includes('"slug"') ? slug : null };
    component.$el = { dataset: { newPage: isNew ? '1' : '0' }, closest: () => form };
    component.init();
    return { title, slug };
}

test('generates a slug as a new page title changes until the slug is edited', () => {
    const { title, slug } = setup(true);
    title.value = 'Příliš žluťoučký kůň!';
    title.dispatchEvent(new Event('input'));
    expect(slug.value).toBe('prilis-zlutoucky-kun');
    slug.value = 'vlastni';
    slug.dispatchEvent(new Event('input'));
    title.value = 'Jiný název';
    title.dispatchEvent(new Event('input'));
    expect(slug.value).toBe('vlastni');
});

test('preserves existing and restored slugs', () => {
    for (const isNew of [true, false]) {
        const { title, slug } = setup(isNew, 'existing');
        title.value = 'Changed';
        title.dispatchEvent(new Event('input'));
        expect(slug.value).toBe('existing');
    }
});
