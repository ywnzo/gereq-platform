import { expect, test } from 'bun:test';
import { registerCmsPagePreview } from '../Resources/assets/js/cmsPagePreview';

test('sends the staged palette only to the trusted preview frame', () => {
  let factory: (() => unknown) | undefined;
  registerCmsPagePreview({ data(_name: string, callback: () => unknown) { factory = callback; } } as never);
  const state = factory!() as any;
  const messages: unknown[] = [];
  const oldDocument = globalThis.document;
  const oldFrame = globalThis.HTMLIFrameElement;
  class Frame { contentWindow = { postMessage: (message: unknown, origin: string) => messages.push({ message, origin }) }; }
  const frame = new Frame();
  (globalThis as any).HTMLIFrameElement = Frame;
  (globalThis as any).document = { getElementById: () => frame };
  try {
    state.boot = { pageId: 'page', blocks: [], storefrontUrl: 'http://localhost:5174', previewPalette: { storefront_mode: 'light' } };
    state.onFrameMessage({ origin: 'http://evil.test', source: frame.contentWindow, data: { t: 'ready' } });
    state.onFrameMessage({ origin: 'http://localhost:5174', source: {}, data: { t: 'ready' } });
    expect(messages).toHaveLength(0);
    state.onFrameMessage({ origin: 'http://localhost:5174', source: frame.contentWindow, data: { t: 'ready' } });
    expect(messages).toEqual([{ origin: 'http://localhost:5174', message: { t: 'boot', pageId: 'page', blocks: [], palette: { storefront_mode: 'light' } } }]);
  } finally {
    (globalThis as any).document = oldDocument;
    (globalThis as any).HTMLIFrameElement = oldFrame;
  }
});
