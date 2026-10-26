import { createApp, h } from 'vue';
import { CmsBlocks, useEditBridge } from '../../../../cms-lib/packages/vue/src/index.ts';
import * as rich from '../../../../cms-lib/packages/core/src/index.ts';
import { registerCmsBuilder } from '../../../modules/Cms/Resources/assets/js/cmsBuilder.ts';
import '../../../../cms-lib/packages/core/src/edit.css';

(window as any).rich = rich;
if (location.search.includes('edit=1')) {
    createApp({ setup() {
        const bridge = useEditBridge();
        return () => h(CmsBlocks, { blocks: bridge.blocks.value, selectedId: bridge.selected.value });
    } }).mount('#app');
} else {
    let factory: () => any;
    registerCmsBuilder({ data(_name: string, callback: () => any) { factory = callback; } } as never);
    const state = factory!();
    state.boot = {
        storefrontUrl: location.origin,
        pageId: 'fixture',
        labels: {
            'entitySourceField.body': 'Text recenze',
            richSizePlaceholder: 'Size', richFontDefault: 'Default font', richFontSystem: 'System font',
            richBold: 'Tučné', richItalic: 'Kurzíva', richUnderline: 'Podtržené', richStrike: 'Přeškrtnuté',
        },
        variables: [{ path: 'page.slug', label: 'Adresa stránky' }],
        varValues: { 'page.slug': 'test-page' },
        dynamicSources: [{
            id: 'reviews', label: 'Reviews', fields: ['body'],
            items: [{ body: 'First review text' }, { body: 'Second review text' }, { body: 'Third review text' }],
        }],
    };
    const child = {
        id: 'body', type: 'text', sort_order: 0,
        payload: { body: '<p>Before {{page.slug}}: {{ item.body }} after</p>' },
    };
    if (new URLSearchParams(location.search).has('heading')) {
        child.type = 'heading';
        child.payload = { text: '{{ item.body }}', level: 2 } as any;
    }
    const owner = {
        id: 'reviews', type: 'dynamic', sort_order: 0,
        payload: { source: 'reviews', variant: 'carousel', children: [child], item_blocks: [child] },
    };
    const plain = new URLSearchParams(location.search).has('plain');
    state.blocks = plain ? [child] : [owner];
    state.templateOwner = plain ? null : owner;
    state.selectedId = 'body';
    state.historyState = state.captureHistory();
    state.$el = document.body;
    state.$nextTick = (callback: () => void) => queueMicrotask(callback);
    (window as any).builder = state;
    state.boot.saveUrl = '/fixture-save';
    state.boot.csrf = 'fixture-token';
    (window as any).savedRequests = [];
    const originalFetch = window.fetch.bind(window);
    window.fetch = (async (input: RequestInfo | URL, init?: RequestInit) => {
        if (input !== '/fixture-save') return originalFetch(input, init);
        const payload = JSON.parse(String(init?.body));
        (window as any).savedRequests.push(payload);
        return Response.json({ data: { blocks: payload.blocks, id_map: {} } });
    }) as typeof fetch;
    const save = document.createElement('button');
    save.textContent = 'Save fixture';
    save.onclick = async () => { await state.save(); save.textContent = 'Saved fixture'; };
    document.body.prepend(save);
    window.addEventListener('message', event => state.onPreviewMessage(event));
    const frame = document.createElement('iframe');
    frame.id = 'cmsPreview';
    frame.style.cssText = 'width:100%;height:900px;border:0';
    frame.src = location.pathname + '?edit=1';
    document.querySelector('#app')!.append(frame);
}
