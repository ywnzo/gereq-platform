import { expect, test } from 'bun:test';
import { registerMediaBox } from '../../resources/js/ui/mediaBox';

function mediaBox() {
    let factory: () => any;
    registerMediaBox({ data(_name: string, callback: () => any) { factory = callback; } } as never);
    const state = factory!();
    const events: CustomEvent[] = [];
    state.$el = { querySelector: () => null, querySelectorAll: () => [], dispatchEvent: (event: CustomEvent) => events.push(event) };
    state.$refs = {
        url: { value: '', dispatchEvent() {} },
        urlVisible: { value: '' }, asset: { value: '' },
    };
    state.$nextTick = (fn: () => void) => fn();
    return { state, events };
}

test('Media Box URL changes notify the builder and replace the library selection', () => {
    const { state, events } = mediaBox();
    state.contextKey = 'image-one'; state.selectedId = 'asset-one';
    state.$refs.urlVisible.value = 'https://example.test/new.jpg';
    state.onUrlVisibleInput();
    expect(state.selectedId).toBe('');
    expect(state.$refs.asset.value).toBe('');
    expect(events[0].detail).toMatchObject({ kind: 'url', context: 'image-one', value: 'https://example.test/new.jpg' });
});

test('Media Box removal undo restores both the asset identity and its URL', () => {
    const { state, events } = mediaBox();
    state.selectedId = 'asset-one'; state.preview = '/media/one/file'; state.$refs.url.value = '/media/one/file';
    state.clearPhoto();
    expect(state.$refs.url.value).toBe('');
    state.undoClear();
    expect(state.selectedId).toBe('asset-one');
    expect(state.$refs.asset.value).toBe('asset-one');
    expect(events.at(-1)!.detail).toMatchObject({ kind: 'undo', selectedId: 'asset-one', value: '/media/one/file' });
});

test('Media Box external sync keeps the variable source separate from its preview', () => {
    const { state, events } = mediaBox();
    state.syncExternal(new CustomEvent('media-box-sync', { detail: {
        context: 'avatar-one', value: '{{item.photo}}', preview: '/media/one/file', selectedId: '',
    } }));
    expect(state.preview).toBe('/media/one/file');
    expect(state.$refs.url.value).toBe('{{item.photo}}');
    expect(events).toHaveLength(0);
    state.syncExternal(new CustomEvent('media-box-sync', { detail: {
        context: 'avatar-two', value: '', preview: '', selectedId: '',
    } }));
    expect(state.preview).toBe('');
    expect(state.contextKey).toBe('avatar-two');
});


test('Media Box refreshes library data and filters newly uploaded images', () => {
    const { state } = mediaBox();
    state.syncExternal(new CustomEvent('media-box-sync', { detail: {
        context: 'image-one', value: '', preview: '', selectedId: '', library: [
            { value: 'photo', label: 'New photo', mime: 'image/png', ext: 'png', width: 1200, height: 800 },
        ],
    } }));
    state.orientFilter = 'landscape'; state.applyFilters();
    expect(state.visibleCount).toBe(1);
    state.orientFilter = 'portrait'; state.applyFilters();
    expect(state.visibleCount).toBe(0);
});
