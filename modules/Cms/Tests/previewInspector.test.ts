import { describe, expect, test } from 'bun:test';
import { registerCmsBuilder } from '../Resources/assets/js/cmsBuilder';

function builder(): any {
  let factory: (() => unknown) | undefined;
  registerCmsBuilder({ data(_name: string, callback: () => unknown) { factory = callback; } } as never);
  const state = factory!() as any;
  state.boot.storefrontUrl = 'http://127.0.0.1:5174';
  state.blocks = [{ id: 'text', type: 'text', sort_order: 0, payload: { body: '<p>Text</p>' } }];
  state.selectedId = 'text';
  state.messages = [];
  state.postPreview = (message: unknown) => state.messages.push(message);
  return state;
}

const settle = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));

describe('inspector preview updates', () => {
  test('applies palette choices to the canvas and preview payload', async () => {
    const state = builder();
    state.previewColors = { brand: '#112233', secondary: '#445566', accent: '#778899' };
    for (const [token, color] of Object.entries(state.previewColors)) {
      state.setColorToken('bg_color', { target: { value: token } });
      state.setColorToken('text_color', { target: { value: token } });
      expect(state.blockInlineStyle(state.selected)).toContain(`background-color:${color}`);
      expect(state.blockInlineStyle(state.selected)).toContain(`color:${color}`);
      await settle(80);
      expect(state.messages.at(-1).blocks[0].payload).toMatchObject({ bg_color: token, text_color: token });
    }
  });

  test('uses trusted storefront palette colors for picker swatches', () => {
    const state = builder();
    state.boot.varValues = { 'brand.surface': '#eeeeee', 'brand.background': '#ffffff' };
    const oldDocument = globalThis.document;
    const oldFrame = globalThis.HTMLIFrameElement;
    class Frame { contentWindow = {}; }
    const frame = new Frame();
    (globalThis as any).HTMLIFrameElement = Frame;
    (globalThis as any).document = { getElementById: () => frame };
    try {
      state.boot.previewPalette = { version: 1, storefront_mode: 'dark' };
      state.previewTheme = 'dark';
      state.previewBooted = () => {};
      state.onPreviewMessage({ origin: 'http://127.0.0.1:5174', source: frame.contentWindow, data: { t: 'ready' } });
      expect(state.messages[0].palette).toEqual({ version: 1, storefront_mode: 'dark' });
      state.onPreviewMessage({ origin: 'http://evil.test', source: frame.contentWindow, data: { t: 'palette', colors: { surface: '#123456' } } });
      expect(state.colorSwatch('surface', 'ink')).toBe('#eeeeee');
      state.onPreviewMessage({ origin: 'http://127.0.0.1:5174', source: frame.contentWindow, data: { t: 'palette', colors: { surface: '#123456', background: '#abc123', accent: 'invalid' } } });
      expect(state.colorSwatch('surface', 'ink')).toBe('#123456');
      expect(state.colorSwatch('paper', 'ink')).toBe('#abc123');
      expect(state.previewColors.accent).toBeUndefined();
      state.onPreviewMessage({ origin: 'http://127.0.0.1:5174', source: frame.contentWindow, data: { t: 'palette', colors: { surface: '#654321' } } });
      expect(state.colorSwatch('surface', 'ink')).toBe('#654321');
    } finally {
      (globalThis as any).document = oldDocument;
      (globalThis as any).HTMLIFrameElement = oldFrame;
    }
  });

  test('sends inspector color changes without relying on an Alpine watcher', async () => {
    const state = builder();
    state.setColorToken('text_color', { target: { value: 'brand' } });
    await settle(80);
    expect(state.messages).toHaveLength(1);
    expect(state.messages[0].blocks[0].payload.text_color).toBe('brand');
  });

  test('retains a color change across content patches that suppress preview echoes', async () => {
    const state = builder();
    state.applyPatch({ id: 'text', key: 'body', value: '<p>Edited</p>', done: true });
    state.setColorHex('text_color', { target: { value: '#ff0033' } });
    await settle(70);
    expect(state.messages).toHaveLength(0);
    state.applyPatch({ id: 'text', key: 'body', value: '<p>Latest</p>', done: true });
    await settle(210);
    expect(state.messages).toHaveLength(1);
    expect(state.messages[0].blocks[0].payload).toMatchObject({ text_color: '#ff0033', body: '<p>Latest</p>' });
    expect(state.previewInspectorPending).toBe(false);
  });

  test('does not echo content patches when no inspector changes are pending', async () => {
    const state = builder();
    state.applyPatch({ id: 'text', key: 'body', value: '<p>Edited</p>', done: true });
    await settle(210);
    expect(state.messages).toHaveLength(0);
  });
});

test('site theme switch saves the mode and leaves page content alone', async () => {
  const state = builder();
  state.boot.themeSaveUrl = '/theme';
  state.boot.previewPalette = { version: 1, storefront_mode: 'light' };
  const original = JSON.stringify(state.blocks);
  const oldFetch = globalThis.fetch;
  const oldWindow = globalThis.window;
  let body = '';
  (globalThis as any).window = {};
  (globalThis as any).fetch = async (_url: string, init: RequestInit) => { body = String(init.body); return { ok: true }; };
  try {
    await state.togglePreviewTheme();
    expect(body).toBe('mode=dark');
    expect(state.previewTheme).toBe('dark');
    expect(state.messages.at(-1)).toEqual({ t: 'preview-theme', mode: 'dark' });
    expect(state.boot.previewPalette.storefront_mode).toBe('dark');
    expect(JSON.stringify(state.blocks)).toBe(original);
    expect(state.dirty).toBe(false);
    (globalThis as any).fetch = async () => ({ ok: false });
    await state.togglePreviewTheme();
    expect(state.previewTheme).toBe('dark');
    expect(state.boot.previewPalette.storefront_mode).toBe('dark');
  } finally {
    globalThis.fetch = oldFetch;
    (globalThis as any).window = oldWindow;
  }
});

describe('HTML inspector mode', () => {
  test('switches views without changing payload or marking content dirty', () => {
    const state = builder();
    state.blocks = [{ id: 'html', type: 'html', sort_order: 0, payload: { html: '<h2>Content</h2>' } }];
    state.selectedId = 'html';
    const before = JSON.stringify(state.blocks);
    expect(state.htmlEditorMode()).toBe('source');
    state.setHtmlEditorMode('preview');
    expect(state.htmlEditorMode()).toBe('preview');
    expect(state.messages).toEqual([{ t: 'html-editor-mode', id: 'html', mode: 'preview' }]);
    expect(JSON.stringify(state.blocks)).toBe(before);
  });

  test('does not send HTML mode changes for other block types', () => {
    const state = builder();
    state.setHtmlEditorMode('preview');
    expect(state.messages).toHaveLength(0);
  });
});

test('rich text updates refresh all dynamic copies without echoing the active editor', async () => {
  const state = builder();
  const child = { id: 'text', type: 'text', sort_order: 0, payload: { body: '{{ item.body }}' } };
  state.blocks = [{ id: 'reviews', type: 'dynamic', sort_order: 0, payload: { source: 'reviews', children: [child] } }];
  state.templateOwner = state.blocks[0];
  state.boot.dynamicSources = [{ id: 'reviews', label: 'Reviews', items: [{ body: 'First' }, { body: 'Second' }] }];
  state.bindTemplateBlocks = (blocks: any[], item: any) => blocks.map(block => ({ ...block, payload: {
    ...block.payload, body: block.payload.body.replace('{{ item.body }}', item.body),
  } }));
  state.applyPatch({ id: 'text', key: 'body', value: '<strong>{{ item.body }}</strong>', done: true });
  expect(state.messages).toEqual([{ t: 'dynamic-items', id: 'reviews', items: [
    [{ ...child, payload: { body: '<strong>First</strong>' } }],
    [{ ...child, payload: { body: '<strong>Second</strong>' } }],
  ] }]);
  await settle(210);
  expect(state.messages).toHaveLength(1);
  expect(state.blocks[0].payload.children[0].payload.body).toBe('<strong>{{ item.body }}</strong>');
});


test('rich toolbar design patches flush after an editor content patch', async () => {
  const state = builder();
  state.applyPatch({ id: 'text', key: 'body', value: '<p>Editing</p>', done: true });
  state.applyPatch({ id: 'text', key: 'text_color', value: '#123456', done: true });
  await settle(220);
  expect(state.messages).toHaveLength(1);
  expect(state.messages[0].blocks[0].payload.text_color).toBe('#123456');
  expect(state.previewInspectorPending).toBe(false);
});

test('rich toolbar design changes refresh repeated entries even while content echoes are suppressed', () => {
  const state = builder();
  const child = { id: 'text', type: 'text', sort_order: 0, payload: { body: '{{ item.body }}' } };
  state.blocks = [{ id: 'reviews', type: 'dynamic', sort_order: 0, payload: { source: 'reviews', children: [child] } }];
  state.templateOwner = state.blocks[0];
  state.boot.dynamicSources = [{ id: 'reviews', label: 'Reviews', items: [{ body: 'First' }, { body: 'Second' }] }];
  state.bindTemplateBlocks = (blocks: any[], item: any) => blocks.map(block => ({ ...block, payload: {
    ...block.payload, body: block.payload.body.replace('{{ item.body }}', item.body),
  } }));
  state.queuePreviewState = () => {};
  state.applyPatch({ id: 'text', key: 'body', value: '{{ item.body }}', done: true });
  state.messages = [];
  state.applyPatch({ id: 'text', key: 'font_weight', value: '700', done: true });
  expect(state.messages).toHaveLength(1);
  expect(state.messages[0].t).toBe('dynamic-items');
  expect(state.messages[0].items.map((item: any[]) => item[0].payload.font_weight)).toEqual(['700', '700']);
});

test('undo refreshes immediately even while typing echoes are gated', async () => {
  const state = builder();
  state.historyState = state.captureHistory();
  state.applyPatch({ id: 'text', key: 'body', value: '<p>Changed</p>', operation: 'typing' });
  expect(state.previewPatchOpen).toBe(true);
  state.restoreHistory('undo');
  await settle(80);
  expect(state.messages.find((message: any) => message.t === 'state').blocks[0].payload.body).toBe('<p>Text</p>');
});

test('switching dynamic mode still refreshes after a content blur patch', async () => {
  const state = builder();
  const child = { id: 'text', type: 'text', sort_order: 0, payload: { body: 'Text' } };
  state.blocks = [{ id: 'reviews', type: 'dynamic', sort_order: 0, payload: { source: 'reviews', item_blocks: [child] } }];
  state.boot.dynamicSources = [];
  state.selectedId = 'reviews';
  state.editDynamicTemplate();
  state.messages = [];
  state.applyPatch({ id: 'text', key: 'body', value: 'Changed', done: true });
  state.finishDynamicTemplate();
  await settle(210);
  expect(state.messages.filter((message: any) => message.t === 'state').at(-1).blocks[0].payload.editing_template).toBe(0);
});

test('HTML-to-text previews parse into an inert template instead of a live div', () => {
  const previous = globalThis.document;
  const elements: string[] = [];
  const state = builder();
  state.expandPreview = (value: string) => value;
  globalThis.document = {
    createElement(tag: string) {
      elements.push(tag);
      return { innerHTML: '', content: { textContent: 'Safe' } };
    },
  } as unknown as Document;
  try {
    expect(state.plainPreview('<p>Safe</p><img src=x onerror="bad()">')).toBe('Safe');
    expect(elements).toEqual(['template']);
  } finally {
    globalThis.document = previous;
  }
});

test('numeric reset restores concrete and inherited defaults', () => {
  const state = builder();
  const input = { value: '42' };
  const event = { currentTarget: { parentElement: { querySelector: () => input } } };
  state.resetNumberInput('opacity', 100, 'payload', event);
  expect(input.value).toBe('100');
  expect(state.selected.payload.opacity).toBe(100);
  state.resetNumberInput('row_gap', '', 'payload', event);
  expect(input.value).toBe('');
  expect(state.selected.payload.row_gap).toBe('');
});


test('number reset is disabled at numeric and inherited defaults', () => {
  const state = builder();
  expect(state.isNumberDefault('opacity', 100, 'payload')).toBe(true);
  state.selected.payload.opacity = '100';
  expect(state.isNumberDefault('opacity', 100, 'payload')).toBe(true);
  state.selected.payload.opacity = 0;
  expect(state.isNumberDefault('opacity', 100, 'payload')).toBe(false);
  state.selected.payload.row_gap = 0;
  expect(state.isNumberDefault('row_gap', '', 'payload')).toBe(false);
  state.selected.payload.row_gap = '';
  expect(state.isNumberDefault('row_gap', '', 'payload')).toBe(true);
  expect(state.isNumberDefault('max', 0, 'textLimit')).toBe(true);
});
