import { expect, test } from 'bun:test';
import { registerSelect } from '../../../resources/js/ui/select';

test('native select sync applies reactive values without overwriting user change events', () => {
  let factory: (() => any) | undefined;
  registerSelect({ data(_name: string, callback: () => any) { factory = callback; } } as never);
  const state = factory!();
  const native = { value: 'old', options: [], selectedOptions: [] };
  const root = {
    dataset: { nativeValue: 'saved' }, hasAttribute: () => true,
    querySelector(selector: string) {
      if (selector === 'select[data-select-native]') return native;
      if (selector === '.g-select__options') return { replaceChildren() {} };
      return null;
    },
  };
  const previous = globalThis.document;
  globalThis.document = { createDocumentFragment() { return {}; } } as unknown as Document;
  try {
    state.$el = root;
    state.syncNativeOptions();
    expect(native.value).toBe('saved');
    native.value = 'user-choice';
    state.syncNativeOptions(false);
    expect(native.value).toBe('user-choice');
  } finally { globalThis.document = previous; }
});
