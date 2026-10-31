import { describe, expect, test } from 'bun:test';
import { registerCmsBuilder } from '../Resources/assets/js/cmsBuilder';

function builder(payload: Record<string, unknown> = {}): any {
  let factory: (() => unknown) | undefined;
  registerCmsBuilder({ data(_name: string, callback: () => unknown) { factory = callback; } } as never);
  const state = factory!() as any;
  state.queuePreviewState = () => {};
  state.blocks = [{ id: 'layout', type: 'layout', payload }];
  state.selectedId = 'layout';
  return state;
}
const change = (value: string) => ({ target: { value } }) as unknown as Event;

describe('container row sizing', () => {
  test('recognizes saved presets and preserves custom CSS on inspection', () => {
    for (const [payload, mode] of [
      [{}, 'fit'], [{ grid_auto_rows: 'auto' }, 'fit'],
      [{ grid_auto_rows: '1fr' }, 'equal'], [{ grid_auto_rows: '240px' }, 'fixed'],
      [{ grid_rows: 'repeat(2, 1fr)' }, 'custom'],
      [{ grid_auto_rows: 'minmax(80px, auto)' }, 'custom'],
    ] as const) {
      const state = builder({ ...payload });
      const before = JSON.stringify(state.selected.payload);
      expect(state.layoutRowSizing()).toBe(mode);
      expect(JSON.stringify(state.selected.payload)).toBe(before);
    }
  });

  test('switches both CSS properties together and keeps unrelated layout settings', () => {
    const state = builder({ grid_rows: '100px 200px', grid_auto_rows: 'minmax(80px, auto)', gap: 24 });
    state.onRowSizingChange(change('equal'));
    expect(state.selected.payload).toEqual({ grid_rows: '', grid_auto_rows: '1fr', gap: 24 });
    state.onRowSizingChange(change('fixed'));
    expect(state.selected.payload.grid_auto_rows).toBe('120px');
    state.onRowHeightChange(change('237'));
    expect(state.selected.payload.grid_auto_rows).toBe('237px');
    state.onRowHeightChange(change(''));
    expect(state.selected.payload.grid_auto_rows).toBe('237px');
    state.onRowSizingChange(change('fit'));
    expect(state.selected.payload.grid_auto_rows).toBe('auto');
    expect(state.selected.payload.grid_rows).toBe('');
  });

  test('updates the storefront preview immediately when a row preset changes', () => {
    const state = builder({ grid_rows: '100px 200px' });
    state.boot.storefrontUrl = 'https://storefront.test';
    const updates: boolean[] = [];
    state.queuePreviewState = (immediate: boolean) => updates.push(immediate);
    for (const mode of ['equal', 'fit', 'fixed']) state.onRowSizingChange(change(mode));
    expect(updates).toEqual([true, true, true]);
    state.onRowSizingChange(change('custom'));
    expect(updates).toHaveLength(3);
    state.onTextInput('grid_auto_flow', change('column'));
    expect(state.selected.payload.grid_auto_flow).toBe('column');
    expect(updates).toEqual([true, true, true, true]);
  });

  test('opening custom CSS does not mutate values or mark the page dirty', () => {
    const state = builder({ grid_auto_rows: 'auto' });
    state.onRowSizingChange(change('custom'));
    expect(state.isCustomRowSizing()).toBe(true);
    expect(state.selected.payload).toEqual({ grid_auto_rows: 'auto' });
    expect(state.dirty).toBe(false);
    state.blocks.push({ id: 'other', type: 'layout', payload: {} });
    state.selectedId = 'other';
    expect(state.layoutRowSizing()).toBe('fit');
    state.selectedId = 'layout';
    state.onRowSizingChange(change('fit'));
    expect(state.layoutRowSizing()).toBe('fit');
  });
});

describe('item placement presets', () => {
  test('preserves custom placement and basis values until a preset is chosen', () => {
    const state = builder({ grid_column: '1 / -1', grid_row: 'span 2', flex_basis: '18rem' });
    expect(state.itemPresetValue('grid_column')).toBe('custom');
    expect(state.itemPresetValue('grid_row')).toBe('span 2');
    expect(state.itemPresetValue('flex_basis')).toBe('custom');
    expect(state.selected.payload.grid_column).toBe('1 / -1');
    state.onItemPresetChange('grid_column', change('span 3'));
    expect(state.selected.payload.grid_column).toBe('span 3');
    expect(state.selected.payload.flex_basis).toBe('18rem');
    state.onItemPresetChange('flex_basis', change('50%'));
    expect(state.selected.payload.flex_basis).toBe('50%');
  });

  test('custom mode is per item and field, and opening it is not a content change', () => {
    const state = builder({});
    state.onItemPresetChange('grid_column', change('custom'));
    expect(state.isCustomItemValue('grid_column')).toBe(true);
    expect(state.isCustomItemValue('grid_row')).toBe(false);
    expect(state.selected.payload).toEqual({});
    expect(state.dirty).toBe(false);
    state.onItemPresetChange('grid_column', change(''));
    expect(state.itemPresetValue('grid_column')).toBe('');
  });
});

describe('advanced grid flow', () => {
  test('reads every legacy flow without changing saved values', () => {
    for (const [flow, direction, gaps] of [
      ['row', 'row', '0'], ['column', 'column', '0'], ['dense', 'row', '1'],
      ['row dense', 'row', '1'], ['column dense', 'column', '1'],
    ]) {
      const state = builder({ grid_auto_flow: flow });
      expect(state.gridFlowDirection()).toBe(direction);
      expect(state.gridFillGapsValue()).toBe(gaps);
      expect(state.selected.payload.grid_auto_flow).toBe(flow);
      expect(state.dirty).toBe(false);
    }
  });

  test('changing one control preserves the other setting', () => {
    const state = builder({ grid_auto_flow: 'dense' });
    state.onGridFlowDirection(change('column'));
    expect(state.selected.payload.grid_auto_flow).toBe('column dense');
    state.onGridFillGaps(change('0'));
    expect(state.selected.payload.grid_auto_flow).toBe('column');
    state.onGridFillGaps(change('1'));
    expect(state.selected.payload.grid_auto_flow).toBe('column dense');
    state.onGridFlowDirection(change('row'));
    expect(state.selected.payload.grid_auto_flow).toBe('row dense');
  });
});

describe('column filling rows', () => {
  test('creates two rows on explicit opt-in and keeps row count across sizing changes', () => {
    const state = builder({ grid_auto_flow: 'row', grid_auto_rows: 'auto' });
    state.onGridFlowDirection(change('column'));
    expect(state.selected.payload.grid_rows).toBe('repeat(2, auto)');
    expect(state.layoutRowSizing()).toBe('fit');
    state.onGridFlowRowCount(change('3'));
    expect(state.selected.payload.grid_rows).toBe('repeat(3, auto)');
    state.onRowSizingChange(change('equal'));
    expect(state.selected.payload.grid_rows).toBe('repeat(3, 1fr)');
    expect(state.layoutRowSizing()).toBe('equal');
    state.onRowSizingChange(change('fixed'));
    expect(state.selected.payload.grid_rows).toBe('repeat(3, 120px)');
    state.onRowHeightChange(change('240'));
    expect(state.selected.payload.grid_rows).toBe('repeat(3, 240px)');
    state.onGridFlowRowCount(change('0'));
    expect(state.gridFlowRowCount()).toBe('3');
  });

  test('keeps existing explicit custom rows when changing direction', () => {
    const state = builder({ grid_rows: '100px 2fr auto' });
    state.onGridFlowDirection(change('column'));
    expect(state.selected.payload.grid_rows).toBe('100px 2fr auto');
    expect(state.layoutRowSizing()).toBe('custom');
    expect(state.gridFlowRowCount()).toBe('');
  });
});


test('column row count remains usable with custom implicit row sizes and explicit none', () => {
  const state = builder({ grid_rows: 'none', grid_auto_rows: 'minmax(80px, auto)' });
  state.onGridFlowDirection(change('column'));
  expect(state.gridFlowRowCount()).toBe('2');
  expect(state.layoutRowSizing()).toBe('custom');
  state.onGridFlowRowCount(change('4'));
  expect(state.selected.payload.grid_rows).toBe('repeat(4, minmax(80px, auto))');
  expect(state.gridFlowRowCount()).toBe('4');
});

test('row sizing and direction changes undo and redo as single coherent steps', () => {
  const state = builder({ display: 'grid', grid_auto_rows: 'auto', gap: 24 });
  state.historyState = state.captureHistory();
  state.onGridFlowDirection(change('column'));
  expect(state.undoStack).toHaveLength(1);
  state.onRowSizingChange(change('fixed'));
  expect(state.undoStack).toHaveLength(2);
  state.restoreHistory('undo');
  expect(state.selected.payload.grid_rows).toBe('repeat(2, auto)');
  state.restoreHistory('undo');
  expect(state.selected.payload.grid_rows).toBeUndefined();
  expect(state.selected.payload.gap).toBe(24);
  state.restoreHistory('redo');
  state.restoreHistory('redo');
  expect(state.selected.payload.grid_rows).toBe('repeat(2, 120px)');
  expect(state.selected.payload.grid_auto_rows).toBe('120px');
});
