import { describe, expect, test } from 'bun:test';
import { registerCmsBuilder } from '../Resources/assets/js/cmsBuilder';

function builder(): any {
  let factory: (() => unknown) | undefined;
  registerCmsBuilder({ data(_name: string, callback: () => unknown) { factory = callback; } } as never);
  const state = factory!() as any;
  state.queuePreviewState = () => {};
  return state;
}

function page() {
  return [
    { id: 'heading', type: 'heading', payload: { text: 'Page title' } },
    { id: 'dynamic', type: 'dynamic', payload: { source: 'reviews', item_blocks: [
      { id: 'body', type: 'text', payload: { body: '<p>{{ item.body }}</p>' } },
    ] } },
  ];
}

describe('inline dynamic templates', () => {
  test('keeps the whole page and edits only the selected template in place', () => {
    const state = builder();
    const blocks = page();
    state.blocks = blocks;
    state.selectedId = 'dynamic';
    state.editDynamicTemplate();
    expect(state.blocks).toBe(blocks);
    expect(state.blocks).toHaveLength(2);
    expect(state.blocks[0].payload.text).toBe('Page title');
    state.findBlock('body').payload.body = '<p>Edited {{ item.body }}</p>';
    expect(blocks[1].payload.item_blocks![0].payload.body).toBe('<p>{{ item.body }}</p>');
    state.finishDynamicTemplate();
    expect(state.blocks[1].payload.item_blocks[0].payload.body).toBe('<p>Edited {{ item.body }}</p>');
    expect(state.blocks[1].payload.children).toBeUndefined();
    expect(state.templateOwner).toBeNull();
    expect(state.dirty).toBe(true);
  });

  test('pencil toggles back to live preview without navigation', () => {
    const state = builder();
    state.blocks = page();
    state.selectedId = 'dynamic';
    state.editDynamicTemplate();
    expect(state.previewBlocks(state.blocks)[1].payload.editing_template).toBe(1);
    state.editDynamicTemplate();
    expect(state.previewBlocks(state.blocks)[1].payload.editing_template).toBe(0);
    expect(state.blocks).toHaveLength(2);
  });

  test('preserves legacy HTML as an editable child of the same block', () => {
    const state = builder();
    state.blocks = [{ id: 'legacy', type: 'dynamic', payload: { source: 'reviews', item_template: '<p>{{ item.body }}</p>' } }];
    state.selectedId = 'legacy';
    state.editDynamicTemplate();
    expect(state.blocks[0].type).toBe('dynamic');
    expect(state.blocks[0].payload.children[0].type).toBe('html');
    state.finishDynamicTemplate();
    expect(state.blocks[0].payload.item_blocks[0].payload.html).toBe('<p>{{ item.body }}</p>');
  });

  test('save without an endpoint keeps the current dynamic editing session', async () => {
    const state = builder();
    const blocks = page();
    state.blocks = blocks;
    state.selectedId = 'dynamic';
    state.editDynamicTemplate();
    state.findBlock('body').payload.text_color = 'brand';
    await state.save();
    expect(state.blocks).toBe(blocks);
    expect(state.persistedBlocks()[1].payload.item_blocks[0].payload.text_color).toBe('brand');
    expect(state.blocks[1].payload.children[0].payload.text_color).toBe('brand');
    expect(state.templateOwner.id).toBe('dynamic');
  });

  test('shows templates only after choosing an entity and clears it on reopening', () => {
    const state = builder();
    state.boot.dynamicSources = [{ id: 'reviews' }, { id: 'products' }];
    state.openLibraryTab('dynamic');
    expect(state.dynamicLibrarySources).toEqual([]);
    state.dynamicLibrarySourceId = 'reviews';
    expect(state.dynamicLibrarySources.map((source: any) => source.id)).toEqual(['reviews']);
    state.openLibraryTab('dynamic');
    expect(state.dynamicLibrarySources).toEqual([]);
  });

  test('selected dynamic entity takes precedence over the library picker', () => {
    const state = builder();
    state.boot.dynamicSources = [{ id: 'reviews' }, { id: 'products' }];
    state.blocks = page();
    state.selectedId = 'dynamic';
    state.dynamicLibrarySourceId = 'products';
    expect(state.dynamicLibrarySources.map((source: any) => source.id)).toEqual(['reviews']);
    state.editDynamicTemplate();
    state.selectedId = 'body';
    expect(state.dynamicLibrarySources.map((source: any) => source.id)).toEqual(['reviews']);
    state.finishDynamicTemplate();
    state.selectedId = 'heading';
    state.openLibraryTab('dynamic');
    expect(state.dynamicLibrarySources).toEqual([]);
  });

  test('stores per-field display limits without truncating the template source', () => {
    const state = builder();
    state.blocks = page();
    state.selectedId = 'dynamic';
    state.editDynamicTemplate();
    state.selectedId = 'body';
    state.onTextLimitInput('body', 'max', { target: { value: '120' } });
    state.onTextLimitInput('body', 'suffix', { target: { value: ' číst dále' } });
    expect(state.findBlock('body').payload.body).toBe('<p>{{ item.body }}</p>');
    state.finishDynamicTemplate();
    expect(state.blocks[1].payload.item_blocks[0].payload.text_limits.body).toEqual({ max: 120, suffix: ' číst dále' });
    state.selectedId = 'heading';
    state.onTextLimitInput('text', 'max', { target: { value: '3' } });
    expect(state.blocks[0].payload.text_limits).toBeUndefined();
  });

  test('creation requires an explicitly registered entity source', () => {
    const state = builder();
    state.boot.dynamicSources = [{ id: 'reviews', fields: ['rating'], label: 'Reviews', items: [] }];
    state.newDynamicTemplate('stars', 'missing');
    expect(state.blocks).toHaveLength(0);
    expect(state.sourceTemplates('missing')).toEqual([]);
  });
});

test('waits for iframe acknowledgement and ignores other windows', () => {
  const state = builder();
  state.blocks = page();
  state.boot = { storefrontUrl: 'http://localhost:5173', dynamicSources: [], labels: {}, variables: [] };
  const posts: any[] = [];
  state.postPreview = (message: unknown) => posts.push(message);
  let booted = 0;
  state.previewBooted = () => { booted++; };
  const oldDocument = globalThis.document;
  const oldFrame = globalThis.HTMLIFrameElement;
  class Frame { contentWindow = {}; }
  const frame = new Frame();
  (globalThis as any).HTMLIFrameElement = Frame;
  (globalThis as any).document = { getElementById: () => frame };
  try {
    state.onPreviewMessage({ origin: 'http://localhost:5173', source: {}, data: { t: 'ready', handshake: 1 } });
    expect(posts).toHaveLength(0);
    state.onPreviewMessage({ origin: 'http://localhost:5173', source: frame.contentWindow, data: { t: 'ready', handshake: 1 } });
    expect(posts[0].t).toBe('boot');
    expect(posts[0].blocks).toHaveLength(2);
    expect(booted).toBe(0);
    state.onPreviewMessage({ origin: 'http://localhost:5173', source: frame.contentWindow, data: { t: 'booted' } });
    expect(booted).toBe(1);
  } finally {
    (globalThis as any).document = oldDocument;
    (globalThis as any).HTMLIFrameElement = oldFrame;
  }
});

const savedPartial = () => ({ id: 'saved-review', name: 'Review card', description: 'Reusable', blocks: [page()[1]], block_count: 1 });

test('preserves field limits when opening and reopening a saved template', () => {
  const state = builder();
  const partial = savedPartial();
  (partial.blocks[0].payload.item_blocks![0].payload as any).text_limits = { body: { max: 12, suffix: ' more' } };
  state.editSavedDynamicTemplate(partial);
  expect(state.findBlock('body').payload.text_limits).toEqual({ body: { max: 12, suffix: ' more' } });
  state.finishDynamicTemplate();
  state.editDynamicTemplate();
  expect(state.findBlock('body').payload.text_limits.body.max).toBe(12);
  expect((partial.blocks[0].payload.item_blocks![0].payload as any).text_limits.body.max).toBe(12);
});

test('undo and redo work across template editing and live preview', () => {
  const state = builder();
  state.blocks = page();
  state.selectedId = 'dynamic';
  state.historyState = state.captureHistory();
  state.blocks[0].payload.text = 'Changed page title';
  state.markDirty();
  state.editDynamicTemplate();
  state.findBlock('body').payload.body = '<p>Changed {{ item.body }}</p>';
  state.markDirty();
  state.finishDynamicTemplate();
  expect(state.undoStack).toHaveLength(2);
  state.restoreHistory('undo');
  expect(state.blocks[1].payload.item_blocks[0].payload.body).toBe('<p>{{ item.body }}</p>');
  state.restoreHistory('undo');
  expect(state.blocks[0].payload.text).toBe('Page title');
  state.restoreHistory('redo');
  state.restoreHistory('redo');
  state.editDynamicTemplate();
  state.restoreHistory('undo');
  expect(state.findBlock('body').payload.body).toBe('<p>{{ item.body }}</p>');
  state.restoreHistory('redo');
  state.finishDynamicTemplate();
  expect(state.blocks[1].payload.item_blocks[0].payload.body).toBe('<p>Changed {{ item.body }}</p>');
});

test('cancelling library editing restores the original page and history', () => {
  const state = builder();
  state.blocks = page();
  const original = state.blocks;
  state.selectedId = 'heading';
  state.dirty = true;
  state.staged = true;
  state.undoStack = ['page-history'];
  state.editSavedDynamicTemplate(savedPartial());
  expect(state.blocks).toHaveLength(1);
  state.findBlock('body').payload.body = 'Library edit';
  state.closeSavedDynamicTemplate();
  expect(state.blocks).toBe(original);
  expect(state.blocks[1].payload.item_blocks[0].payload.body).toBe('<p>{{ item.body }}</p>');
  expect(state.selectedId).toBe('heading');
  expect(state.dirty).toBe(true);
  expect(state.staged).toBe(true);
  expect(state.undoStack).toEqual(['page-history']);
});

test('library saving preserves unsaved page state and updates the existing record', async () => {
  const state = builder();
  state.boot.csrf = 'test-token';
  state.boot.partialsUrl = '/partials';
  state.boot.partialUpdateUrl = '/partials/__ID__';
  state.blocks = page();
  state.selectedId = 'dynamic';
  state.dirty = true;
  state.undoStack = ['page-history'];
  state.partialName = 'New template';
  const original = state.blocks;
  const requests: any[] = [];
  const oldFetch = globalThis.fetch;
  const oldWindow = globalThis.window;
  (globalThis as any).window = { gereq: { toast: () => {} } };
  globalThis.fetch = (async (url: string, options: RequestInit) => {
    const body = JSON.parse(options.body as string);
    requests.push({ url, body, headers: options.headers });
    return Response.json({ data: { ...savedPartial(), name: body.name, blocks: body.blocks } });
  }) as any;
  try {
    await state.saveDynamicTemplate();
    expect(state.dirty).toBe(true);
    expect(state.undoStack).toEqual(['page-history']);
    expect(state.staged).toBe(false);
    state.editSavedDynamicTemplate(savedPartial());
    state.findBlock('body').payload.body = '<p>Updated {{ item.body }}</p>';
    state.finishDynamicTemplate();
    await state.savePartial();
    expect(requests.map((r) => r.url)).toEqual(['/partials', '/partials/saved-review']);
    expect(requests[1].headers['X-CSRF-TOKEN']).toBe('test-token');
    expect(requests[1].body.description).toBe('Reusable');
    expect(requests[1].body.blocks[0].payload.item_blocks[0].payload.body).toContain('Updated');
    expect(state.partials).toHaveLength(1);
    expect(state.partials[0].id).toBe('saved-review');
    expect(state.blocks).toBe(original);
    expect(state.dirty).toBe(true);
    expect(state.undoStack).toEqual(['page-history']);
    expect(state.savedTemplateSession).toBeNull();
  } finally {
    globalThis.fetch = oldFetch;
    (globalThis as any).window = oldWindow;
  }
});

test('failed library updates keep the edited template available for retry', async () => {
  const state = builder();
  state.boot.csrf = 'token';
  state.boot.partialsUrl = '/partials';
  state.boot.partialUpdateUrl = '/partials/__ID__';
  state.editSavedDynamicTemplate(savedPartial());
  state.findBlock('body').payload.body = 'Keep this edit';
  const oldFetch = globalThis.fetch;
  const oldWindow = globalThis.window;
  (globalThis as any).window = { gereq: { toast: () => {} } };
  globalThis.fetch = (async () => Response.json({ error: { message: 'Rejected' } }, { status: 422 })) as any;
  try {
    await state.save();
    expect(state.savedTemplateSession).not.toBeNull();
    expect(state.blocks[0].payload.item_blocks[0].payload.body).toBe('Keep this edit');
    expect(state.status).toBe('Rejected');
    expect(state.partialSaving).toBe(false);
  } finally {
    globalThis.fetch = oldFetch;
    (globalThis as any).window = oldWindow;
  }
});

describe('dynamic repeating containers', () => {
  test('adds blocks directly into an existing container from live preview', () => {
    const state = builder();
    state.blocks = page();
    state.selectedId = 'dynamic';
    state.addBlockAfterSelection('avatar');
    expect(state.blocks).toHaveLength(2);
    expect(state.templateOwner.id).toBe('dynamic');
    expect(state.selected.type).toBe('avatar');
    expect(state.selectedDynamicOwner.id).toBe('dynamic');
    expect(state.blocks[1].payload.children.map((b: any) => b.type)).toEqual(['text', 'avatar']);
    state.finishDynamicTemplate();
    expect(state.blocks[1].payload.item_blocks.map((b: any) => b.type)).toEqual(['text', 'avatar']);
  });

  test('scopes field binding to the entity and supported block fields', () => {
    const state = builder();
    state.boot.dynamicSources = [{ id: 'reviews', fields: ['body', 'author_name'] }, { id: 'other', fields: ['secret'] }];
    state.blocks = page();
    state.selectedId = 'dynamic';
    state.editDynamicTemplate();
    state.selectedId = 'body';
    expect(state.entityBindingFields).toEqual(['body', 'author_name']);
    expect(state.connectionGroups().map((s: any) => s.id)).toEqual(['reviews']);
    state.bindEntityField({ target: { value: 'secret' } });
    expect(state.selected.payload.body).toBe('<p>{{ item.body }}</p>');
    state.bindEntityField({ target: { value: 'author_name' } });
    expect(state.selected.payload.body).toBe('<p>{{ item.author_name }}</p>');
    state.finishDynamicTemplate();
    state.editDynamicTemplate();
    expect(state.selectedId).toBe('body');
    expect(state.selected.payload.body).toBe('<p>{{ item.author_name }}</p>');
  });

  test('filters unsupported additions and rejects them through direct insertion', () => {
    const state = builder();
    state.boot.blockTypes = ['layout', 'text', 'rating', 'products', 'form_field', 'dynamic'].map((value) => ({ value }));
    state.blocks = page();
    state.selectedId = 'dynamic';
    state.editDynamicTemplate();
    expect(state.availableBlockTypes.map((b: any) => b.value)).toEqual(['layout', 'text', 'rating']);
    state.addBlockAfterSelection('products');
    state.addBlockAfterSelection('form_field');
    state.addBlockAfterSelection('dynamic');
    expect(state.blocks[1].payload.children).toHaveLength(1);
    state.selectedId = 'body';
    state.addBlockAfterSelection('form_field');
    expect(state.blocks[1].payload.children).toHaveLength(1);
  });

  test('drags supported blocks and nested layouts inside and outside the container', () => {
    const state = builder();
    state.blocks = page();
    state.moveBlock('heading', 'dynamic', 'inside');
    expect(state.blocks).toHaveLength(1);
    expect(state.blocks[0].payload.children.map((b: any) => b.id)).toEqual(['body', 'heading']);
    state.moveBlock('heading', 'dynamic', 'before');
    expect(state.blocks.map((b: any) => b.id)).toEqual(['heading', 'dynamic']);
    expect(state.blocks[1].payload.children).toHaveLength(1);
  });

  test('rejects unsupported descendants when moving an existing layout', () => {
    const state = builder();
    state.blocks = [...page(), { id: 'layout', type: 'layout', payload: { children: [{ id: 'form', type: 'form_field', payload: {} }] } }];
    state.moveBlock('layout', 'dynamic', 'inside');
    expect(state.blocks).toHaveLength(3);
    expect(state.templateOwner).toBeNull();
    expect(state.blocks[2].payload.children[0].id).toBe('form');
  });

  test('changing the source retains the item design', () => {
    const state = builder();
    state.blocks = page();
    state.boot.dynamicSources = [{ id: 'reviews', fields: ['body'] }, { id: 'other', fields: ['title'] }];
    state.selectedId = 'dynamic';
    state.dynamicSourceChanged({ target: { value: 'other' } });
    expect(state.selected.payload.source).toBe('other');
    expect(state.selected.payload.item_blocks[0].payload.body).toBe('<p>{{ item.body }}</p>');
    state.dynamicSourceChanged({ target: { value: 'missing' } });
    expect(state.selected.payload.source).toBe('other');
  });
});

test('page-level additions remain unrestricted when a dynamic container is first', () => {
  const state = builder();
  state.blocks = [page()[1]];
  state.addBlock('form_field');
  expect(state.blocks.map((b: any) => b.type)).toEqual(['dynamic', 'form_field']);
});

test('image URL bindings replace media-library selection', () => {
  const state = builder();
  state.boot.dynamicSources = [{ id: 'reviews', fields: ['photo'] }];
  state.blocks = page();
  state.selectedId = 'dynamic';
  state.addBlockAfterSelection('image');
  state.selected.payload.media_id = 'existing-media';
  state.entityBindingTarget = 'url';
  state.bindEntityField({ target: { value: 'photo' } });
  expect(state.selected.payload.media_id).toBe('');
  expect(state.selected.payload.url).toBe('{{ item.photo }}');
});

test('new empty containers start as editable items and remain siblings of existing containers', () => {
  const state = builder();
  state.boot.dynamicSources = [{ id: 'reviews', fields: ['body'], label: 'Reviews', items: [] }];
  state.newDynamicTemplate('custom', 'reviews');
  expect(state.blocks).toHaveLength(1);
  expect(state.templateOwner.payload.children).toEqual([]);
  state.newDynamicTemplate('custom', 'reviews');
  expect(state.blocks).toHaveLength(2);
  expect(state.blocks.every((b: any) => b.type === 'dynamic')).toBe(true);
  expect(state.templateOwner.id).toBe(state.blocks[1].id);
});

test('moves an item child between repeating containers without losing it', () => {
  const state = builder();
  state.blocks = [page()[1], { id: 'second', type: 'dynamic', payload: { source: 'reviews', item_blocks: [] } }];
  state.selectedId = 'dynamic';
  state.editDynamicTemplate();
  state.moveBlock('body', 'second', 'inside');
  expect(state.blocks[0].payload.item_blocks).toEqual([]);
  expect(state.blocks[1].payload.children[0].id).toBe('body');
  state.finishDynamicTemplate();
  expect(state.blocks[1].payload.item_blocks[0].payload.body).toBe('<p>{{ item.body }}</p>');
});

test('cloned repeating containers have independent child identities and bindings', () => {
  const state = builder();
  const original = page()[1];
  const first = state.cloneBlockTree(original);
  const second = state.cloneBlockTree(original);
  expect(first.id).not.toBe(second.id);
  expect(first.payload.item_blocks[0].id).not.toBe(second.payload.item_blocks[0].id);
  first.payload.item_blocks[0].payload.body = 'Changed';
  expect(second.payload.item_blocks[0].payload.body).toBe('<p>{{ item.body }}</p>');
  expect(original.payload.item_blocks![0].payload.body).toBe('<p>{{ item.body }}</p>');
});

test('editing previews rebind every source record from the current unsaved children', () => {
  const state = builder();
  state.dynamicItems = () => [];
  state.boot.dynamicSources = [{ id: 'reviews', label: 'Reviews', fields: ['rating'], items: [{ rating: 5 }, { rating: 4 }, { rating: 3 }] }];
  state.blocks = [{ id: 'dynamic', type: 'dynamic', payload: { source: 'reviews', item_blocks: [
    { id: 'rating', type: 'rating', payload: { value: '0' } },
  ] } }];
  state.selectedId = 'dynamic';
  state.editDynamicTemplate();
  state.selectedId = 'rating';
  state.setPayload('value', '{{ item.rating }}');
  state.setDynamicItemStyle('padding', { target: { value: '24' } });
  state.setDynamicItemStyle('max_height', { target: { value: '240' } });
  state.setDynamicItemStyle('bg_color', { target: { value: '#123456' } });
  const preview = state.previewBlocks(state.blocks)[0];
  expect(preview.payload.resolved_items.map((items: any[]) => items[0].payload.value)).toEqual(['5', '4', '3']);
  expect(preview.payload.item_design).toMatchObject({ padding: 24, bg_color: '#123456', max_height: 240 });
  state.finishDynamicTemplate();
  state.editDynamicTemplate();
  expect(state.dynamicItemDesign.padding).toBe(24);
  expect(state.dynamicItemDesign.bg_color).toBe('#123456');
});

test('per-block text overflow persists independently of item height', () => {
  const state = builder();
  state.blocks = page();
  state.selectedId = 'dynamic';
  state.setDynamicItemStyle('max_height', { target: { value: '180' } });
  state.editDynamicTemplate();
  state.selectedId = 'body';
  state.setBlockTextOverflow({ target: { value: 'ellipsis' } });
  state.finishDynamicTemplate();
  expect(state.blocks[1].payload.item_design).toMatchObject({ max_height: 180 });
  expect(state.blocks[1].payload.item_blocks[0].payload.text_overflow).toBe('ellipsis');
  expect(state.dynamicItemDesign.text_overflow).toBeUndefined();
});

test('entity picker reflects saved bindings, formatting, and the selected block field', () => {
  const state = builder();
  state.boot.dynamicSources = [{ id: 'reviews', fields: ['body', 'author_name', 'photo'] }];
  state.blocks = page();
  state.selectedId = 'dynamic';
  state.editDynamicTemplate();
  state.selectedId = 'body';
  expect(state.selectedEntityBindingField).toBe('body');
  state.selected.payload.body = '<p><strong>{{item.author_name}}</strong></p>';
  expect(state.selectedEntityBindingField).toBe('author_name');
  state.bindEntityField({ target: { value: 'body' } });
  expect(state.selectedEntityBindingField).toBe('body');
  state.selected.payload.body = 'Plain text';
  expect(state.selectedEntityBindingField).toBe('');
  state.entityBindingSourceFields = {};
  state.selected.payload.body = '{{ item.body }} {{ item.author_name }}';
  expect(state.selectedEntityBindingField).toBe('');
  state.selected.payload.body = '{{ item.secret }}';
  expect(state.selectedEntityBindingField).toBe('');
  state.selected.type = 'image';
  state.selected.payload = { url: '{{ item.photo }}', alt: '{{ item.author_name }}' };
  state.entityBindingTarget = 'url';
  expect(state.selectedEntityBindingField).toBe('photo');
  state.entityBindingTarget = 'alt';
  expect(state.selectedEntityBindingField).toBe('author_name');
  state.selectedId = 'heading';
  expect(state.selectedEntityBindingField).toBe('');
});

for (const editing of [true, false]) {
  test(`successful page save preserves ${editing ? 'edit' : 'preview'} mode and selection`, async () => {
    const state = builder();
    state.boot.saveUrl = '/save';
    state.boot.csrf = 'token';
    state.blocks = page();
    state.blocks[1].id = 'tmp_dynamic';
    state.selectedId = 'tmp_dynamic';
    if (editing) {
      state.editDynamicTemplate();
      state.selectedId = 'body';
      state.findBlock('body').payload.body = '<p>Latest {{ item.body }}</p>';
    }
    const previousFetch = globalThis.fetch;
    const previousWindow = globalThis.window;
    let sent: any;
    (globalThis as any).window = { gereq: {} };
    globalThis.fetch = (async (_url: string, options: RequestInit) => {
      sent = JSON.parse(options.body as string);
      return Response.json({ data: { blocks: sent.blocks.map((block: any) => ({ ...block, id: block.client_id === 'tmp_dynamic' ? 'saved_dynamic' : block.client_id })), id_map: { tmp_dynamic: 'saved_dynamic' } } });
    }) as any;
    try {
      await state.save();
      expect(sent.blocks[1].payload.children).toBeUndefined();
      expect(state.dirty).toBe(false);
      expect(state.selectedId).toBe(editing ? 'body' : 'saved_dynamic');
      expect(state.templateOwner?.id ?? null).toBe(editing ? 'saved_dynamic' : null);
      expect(state.previewBlocks(state.blocks)[1].payload.editing_template).toBe(editing ? 1 : 0);
      if (editing) {
        expect(sent.blocks[1].payload.item_blocks[0].payload.body).toContain('Latest');
        expect(state.findBlock('body').payload.body).toContain('Latest');
      }
    } finally {
      globalThis.fetch = previousFetch;
      (globalThis as any).window = previousWindow;
    }
  });
}

test('failed page save leaves template editing and child selection intact', async () => {
  const state = builder();
  state.boot.saveUrl = '/save';
  state.boot.csrf = 'token';
  state.blocks = page();
  state.selectedId = 'dynamic';
  state.editDynamicTemplate();
  state.selectedId = 'body';
  state.findBlock('body').payload.body = 'Keep unsaved text';
  const previousFetch = globalThis.fetch;
  const previousWindow = globalThis.window;
  (globalThis as any).window = { gereq: { toast: () => {} } };
  globalThis.fetch = (async () => Response.json({ error: { message: 'Rejected' } }, { status: 422 })) as any;
  try {
    await state.save();
    expect(state.templateOwner.id).toBe('dynamic');
    expect(state.selectedId).toBe('body');
    expect(state.findBlock('body').payload.body).toBe('Keep unsaved text');
    expect(state.status).toBe('Rejected');
  } finally { globalThis.fetch = previousFetch; (globalThis as any).window = previousWindow; }
});

for (const childSelected of [false, true]) {
  test(`opening Library from a dynamic ${childSelected ? 'child' : 'container'} shows Add blocks`, () => {
    const state = builder();
    state.blocks = page();
    state.selectedId = 'dynamic';
    if (childSelected) { state.editDynamicTemplate(); state.selectedId = 'body'; }
    state.toggleLibrary();
    expect(state.createOpen).toBe(true);
    expect(state.createTab).toBe('elements');
    expect(state.modalTab).toBeNull();
    expect(state.templateOwner.id).toBe('dynamic');
    expect(state.insertAfterId).toBe(childSelected ? 'body' : 'dynamic');
    state.toggleLibrary();
    expect(state.createOpen).toBe(false);
    expect(state.modalTab).toBeNull();
  });
}

test('ordinary blocks keep their Variables library default', () => {
  const state = builder();
  state.blocks = page();
  state.selectedId = 'heading';
  state.toggleLibrary();
  expect(state.modalTab).toBe('vars');
  expect(state.createOpen).toBe(false);
});

test('typing forms one undo step and formatting starts a separate step', () => {
  const state = builder();
  state.blocks = page(); state.selectedId = 'dynamic'; state.editDynamicTemplate(); state.selectedId = 'body';
  state.historyState = state.captureHistory();
  state.postPreview = () => {};
  state.applyPatch({ id: 'body', key: 'body', value: 'A', operation: 'typing' });
  state.applyPatch({ id: 'body', key: 'body', value: 'AB', operation: 'typing' });
  state.applyPatch({ id: 'body', key: 'body', value: 'ABC', operation: 'typing', done: true });
  expect(state.undoStack).toHaveLength(1);
  state.applyPatch({ id: 'body', key: 'font_weight', value: 'bold', done: true });
  expect(state.undoStack).toHaveLength(2);
  state.restoreHistory('undo');
  expect(state.findBlock('body').payload.body).toBe('ABC');
  expect(state.findBlock('body').payload.font_weight).toBeUndefined();
  state.restoreHistory('undo');
  expect(state.findBlock('body').payload.body).toBe('<p>{{ item.body }}</p>');
  state.restoreHistory('redo');
  expect(state.findBlock('body').payload.body).toBe('ABC');
});

test('multi-patch formatting transactions produce one undo step', () => {
  const state = builder(); state.blocks = page(); state.selectedId = 'dynamic'; state.editDynamicTemplate();
  state.historyState = state.captureHistory();
  state.postPreview = () => {};
  state.historyTransactionDepth = 1;
  state.applyPatch({ id: 'body', key: 'body', value: '<p>{{ item.body }}</p><br>' });
  state.applyPatch({ id: 'body', key: 'text_color', value: 'brand', done: true });
  state.historyTransactionDepth = 0;
  expect(state.undoStack).toHaveLength(1);
  state.restoreHistory('undo');
  expect(state.findBlock('body').payload.text_color).toBeUndefined();
  expect(state.findBlock('body').payload.body).toBe('<p>{{ item.body }}</p>');
});

test('direct property connections remain independent', () => {
  const state = builder(); state.blocks = page(); state.selectedId = 'dynamic'; state.editDynamicTemplate(); state.selectedId = 'body';
  state.selected.type = 'image'; state.selected.payload = { url: '{{ item.photo }}', alt: '{{ item.author_name }}' };
  state.boot.dynamicSources = [{ id: 'reviews', fields: ['photo', 'author_name', 'title'] }];
  state.bindEntityTarget('alt', { target: { value: 'title' } });
  expect(state.entityBindingValue('url')).toBe('photo');
  expect(state.entityBindingValue('alt')).toBe('title');
});

test('library placement inserts before, after, and inside without closing the library', () => {
  const state = builder(); state.blocks = page(); state.selectedId = 'dynamic'; state.editDynamicTemplate(); state.selectedId = 'body';
  state.createOpen = true; state.createTab = 'elements'; state.insertPlacement = 'before';
  state.addBlockAfterSelection('heading');
  expect(state.blocks[1].payload.children.map((b: any) => b.type)).toEqual(['heading', 'text']);
  expect(state.createOpen).toBe(true);
  state.selectedId = 'body'; state.insertPlacement = 'after'; state.addBlockAfterSelection('rating');
  expect(state.blocks[1].payload.children.map((b: any) => b.type)).toEqual(['heading', 'text', 'rating']);
  state.selectedId = 'dynamic'; state.insertPlacement = 'inside'; state.addBlockAfterSelection('badge');
  expect(state.blocks[1].payload.children.map((b: any) => b.type)).toEqual(['heading', 'text', 'rating', 'badge']);
});

test('selecting an already selected block does not reset inspector or insertion state', () => {
  const state = builder(); state.blocks = page(); state.selectedId = 'heading';
  state.rightTab = 'design'; state.insertPlacement = 'before';
  state.select('heading');
  expect(state.rightTab).toBe('design'); expect(state.insertPlacement).toBe('before');
});


test('changing an entity connection preserves markup and unrelated variables', () => {
  const state = builder(); state.blocks = page(); state.selectedId = 'dynamic';
  state.boot.dynamicSources = [{ id: 'reviews', fields: ['body', 'author_name'] }];
  state.editDynamicTemplate(); state.selectedId = 'body';
  state.selected.payload.body = '<p><strong>{{ item.body }}</strong> on {{page.slug}}</p>';
  state.bindEntityTarget('body', { target: { value: 'author_name' } });
  expect(state.selected.payload.body).toBe('<p><strong>{{ item.author_name }}</strong> on {{page.slug}}</p>');
});

test('disconnect removes the entity variable while preserving unrelated content', () => {
  const state = builder(); state.blocks = page(); state.selectedId = 'dynamic';
  state.boot.varValues = { 'page.slug': 'test-page' };
  state.boot.dynamicSources = [{ id: 'reviews', fields: ['body'], items: [{ body: '<Review>' }, { body: 'Second review' }] }];
  state.editDynamicTemplate(); state.selectedId = 'body';
  state.selected.payload.body = '<p>{{ item.body }} on {{page.slug}}</p>';
  state.disconnectEntityTarget('body');
  expect(state.selected.payload.body).toBe('<p> on {{page.slug}}</p>');
  state.finishDynamicTemplate();
  expect(state.blocks[1].payload.item_blocks[0].payload.body).toBe('<p> on {{page.slug}}</p>');
});

test('disconnect removes a variable even without preview records', () => {
  const state = builder(); state.blocks = page(); state.selectedId = 'dynamic';
  state.boot.dynamicSources = [{ id: 'reviews', fields: ['body'], items: [] }];
  state.editDynamicTemplate(); state.selectedId = 'body';
  state.disconnectEntityTarget('body');
  expect(state.selected.payload.body).toBe('<p></p>');
});


test('changing one of several entity fields preserves the other fields', () => {
  const state = builder(); state.blocks = page(); state.selectedId = 'dynamic';
  state.boot.dynamicSources = [{ id: 'reviews', fields: ['body', 'author_name', 'title'] }];
  state.editDynamicTemplate(); state.selectedId = 'body';
  state.selected.payload.body = '<p>{{ item.author_name }}: {{ item.body }} {{page.slug}}</p>';
  state.entityBindingSourceFields.body = 'body';
  expect(state.entityBindingValue('body')).toBe('body');
  state.bindEntityTarget('body', { target: { value: 'title' } });
  expect(state.selected.payload.body).toBe('<p>{{ item.author_name }}: {{ item.title }} {{page.slug}}</p>');
  expect(state.entityBindingValue('body')).toBe('title');
});

test('Media Box image changes are one history operation and preserve block styling', () => {
  const state = builder(); state.blocks = [{ id: 'image', type: 'image', payload: { url: '{{item.photo}}', media_id: '', radius: 12 } }];
  state.selectedId = 'image'; state.historyState = state.captureHistory();
  state.boot.media = [{ id: 'photo', url: '/media/photo/file', title: 'Photo', alt: 'Portrait' }];
  state.onInspectorMediaChange(new CustomEvent('media-change', { detail: {
    context: 'image', selectedId: 'photo', kind: 'pick', value: '/media/photo/file',
  } }));
  expect(state.selected.payload).toMatchObject({ url: '/media/photo/file', media_id: 'photo', alt: 'Portrait', radius: 12 });
  expect(state.undoStack).toHaveLength(1);
  state.applyInspectorMediaSelection('image', null, '{{item.photo}}');
  expect(state.selected.payload).toMatchObject({ url: '{{item.photo}}', media_id: '', radius: 12 });
});

test('Media Box events cannot modify the newly selected block', () => {
  const state = builder(); state.blocks = [
    { id: 'one', type: 'avatar', payload: { url: '/old' } },
    { id: 'two', type: 'avatar', payload: { url: '/second' } },
  ];
  state.selectedId = 'two';
  state.onInspectorMediaChange(new CustomEvent('media-change', { detail: {
    context: 'one', selectedId: '', kind: 'url', value: '/first-new',
  } }));
  expect(state.blocks[0].payload.url).toBe('/first-new');
  expect(state.blocks[1].payload.url).toBe('/second');
});

test('a delayed Media Box upload retains its original target and respects a newer URL', async () => {
  const originalFetch = globalThis.fetch;
  try {
    for (const replaceWhileUploading of [false, true]) {
      let finish!: (response: Response) => void;
      globalThis.fetch = (() => new Promise<Response>((resolve) => { finish = resolve; })) as typeof fetch;
      const state = builder();
      state.boot.mediaUploadUrl = '/upload'; state.boot.csrf = 'test'; state.boot.media = [];
      state.blocks = [
        { id: 'one', type: 'image', payload: { url: '/old' } },
        { id: 'two', type: 'avatar', payload: { url: '/second' } },
      ];
      state.selectedId = 'one';
      const upload = state.uploadMediaFiles([new File(['image'], 'image.png', { type: 'image/png' })], 'one');
      state.selectedId = 'two';
      if (replaceWhileUploading) state.applyInspectorMediaSelection('one', null, '/newer-url');
      finish(new Response(JSON.stringify({ data: { id: 'uploaded', url: '/media/uploaded/file' } }), { status: 200 }));
      await upload;
      expect(state.blocks[0].payload.url).toBe(replaceWhileUploading ? '/newer-url' : '/media/uploaded/file');
      expect(state.blocks[1].payload.url).toBe('/second');
      expect(state.mediaUploading).toBe(false);
    }
  } finally { globalThis.fetch = originalFetch; }
});


test('disconnecting an image URL also removes media-library fallbacks', () => {
  const state = builder(); state.blocks = page(); state.selectedId = 'dynamic';
  state.boot.dynamicSources = [{ id: 'reviews', fields: ['photo'] }];
  state.editDynamicTemplate(); state.selectedId = 'body';
  state.selected.type = 'image';
  state.selected.payload = { url: '{{item.photo}}', media_id: 'old', media_url: '/old' };
  state.disconnectEntityTarget('url');
  expect(state.selected.payload).toMatchObject({ url: '', media_id: '', media_url: '' });
});

test('disconnect removes only the active entity variable and keeps formatting and other variables', () => {
  const state = builder(); state.blocks = page(); state.selectedId = 'dynamic';
  state.boot.dynamicSources = [{ id: 'reviews', fields: ['body', 'author_name'] }];
  state.editDynamicTemplate(); state.selectedId = 'body';
  state.selected.payload.body = '<p><strong>By {{item.author_name}}</strong>: {{ item.body }} {{page.slug}}</p>';
  state.entityBindingSourceFields.body = 'body';
  state.disconnectEntityTarget('body');
  expect(state.selected.payload.body).toBe('<p><strong>By {{item.author_name}}</strong>:  {{page.slug}}</p>');
});

test('unchanged preview patches keep a saved page clean and finish typing groups', () => {
  const state = builder();
  state.blocks = page();
  state.historyState = state.captureHistory();
  state.historyGroup = { key: 'heading:text', at: Date.now() };
  state.applyPatch({ id: 'heading', key: 'text', value: 'Page title', done: true });
  expect(state.dirty).toBe(false);
  expect(state.undoStack).toHaveLength(0);
  expect(state.historyGroup).toBeNull();
  expect(state.previewPatchOpen).toBe(false);
});
