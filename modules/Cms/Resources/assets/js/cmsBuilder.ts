import type Alpine from '@alpinejs/csp';

type BlockPayload = Record<string, string | number | null>;

type BuilderBlock = {
    id: string;
    type: string;
    sort_order: number;
    payload: BlockPayload;
    _new?: boolean;
};

type MediaItem = {
    id: string;
    title: string;
    url: string;
    mime: string;
    alt: string;
};

type DocItem = {
    id: string;
    title: string;
    mime: string;
    url: string;
};

type BuilderVar = {
    path: string;
    label: string;
    description: string;
    group: string;
    kind: 'text' | 'color' | 'image' | 'link' | 'number';
    example: string;
    token: string;
};

type BuilderBoot = {
    pageId: string;
    title: string;
    slug: string;
    template: string;
    saveUrl: string;
    previewUrl: string;
    exitUrl: string;
    csrf: string;
    blockTypes: Array<{ value: string; label: string }>;
    blocks: BuilderBlock[];
    media: MediaItem[];
    documents: DocItem[];
    variables: BuilderVar[];
    varValues: Record<string, string>;
    labels: Record<string, string>;
};

function csrfToken(boot: BuilderBoot): string {
    return boot.csrf || (window as Window & { __GEREQ_CSRF__?: string }).__GEREQ_CSRF__ || '';
}

function defaultPayload(type: string): BlockPayload {
    switch (type) {
        case 'heading':
            return { text: 'Nadpis', level: 2 };
        case 'text':
            return { body: '<p>Nový text…</p>' };
        case 'html':
            return { html: '<p>HTML blok</p>' };
        case 'image':
            return { media_id: '', url: '', alt: '', caption: '' };
        case 'cta':
            return { label: 'Call to action', url: '#', style: 'primary' };
        case 'columns':
            return { left: '<p>Levý sloupec</p>', right: '<p>Pravý sloupec</p>' };
        case 'divider':
            return { style: 'solid' };
        case 'products':
            return { product_ids: '' };
        case 'gallery':
            return { media_ids: '' };
        default:
            return {};
    }
}

function stripHtml(html: string): string {
    const el = document.createElement('div');
    el.innerHTML = html;
    return (el.textContent || '').trim();
}

function normalizePayload(type: string, raw: Record<string, unknown> | null | undefined): BlockPayload {
    const p = { ...(raw || {}) } as Record<string, unknown>;
    const out: BlockPayload = {};
    for (const [k, v] of Object.entries(p)) {
        if (k === 'images') continue;
        if (Array.isArray(v)) {
            out[k] = v.map(String).join(',');
        } else if (v === null || v === undefined) {
            out[k] = '';
        } else if (typeof v === 'number') {
            out[k] = v;
        } else {
            out[k] = String(v);
        }
    }
    if (type === 'gallery' && out.media_ids == null) {
        out.media_ids = '';
    }
    return out;
}

function normalizeBlock(b: BuilderBlock, i: number): BuilderBlock {
    return {
        id: b.id,
        type: b.type,
        sort_order: b.sort_order ?? i + 1,
        payload: normalizePayload(b.type, b.payload as Record<string, unknown>),
    };
}

function toast(message: string, tone: 'success' | 'error' | 'info' = 'info'): void {
    window.gereq?.toast?.(message, tone);
}

export function registerCmsBuilder(alpine: typeof Alpine): void {
    alpine.data('cmsBuilder', () => ({
        boot: {
            pageId: '',
            title: '',
            slug: '',
            template: 'default',
            saveUrl: '',
            previewUrl: '',
            exitUrl: '',
            csrf: '',
            blockTypes: [],
            blocks: [],
            media: [],
            documents: [],
            variables: [],
            varValues: {},
            labels: {},
        } as BuilderBoot,
        device: 'desktop' as 'desktop' | 'tablet' | 'mobile',
        leftTab: 'layers' as 'layers' | 'add' | 'media' | 'docs' | 'vars',
        rightTab: 'content' as 'content' | 'design',
        selectedId: null as string | null,
        blocks: [] as BuilderBlock[],
        dirty: false,
        saving: false,
        status: '',
        mediaQuery: '',
        docsQuery: '',
        varFilter: '',
        varKind: 'all' as 'all' | 'text' | 'color' | 'image' | 'link' | 'number',
        insertTarget: null as { key: string } | null,
        dragId: null as string | null,
        dropTargetId: null as string | null,
        dragAddType: null as string | null,

        init() {
            const root = this.$el as HTMLElement;
            try {
                this.boot = JSON.parse(root.dataset.boot || '{}') as BuilderBoot;
            } catch {
                /* keep defaults */
            }
            this.blocks = (this.boot.blocks || []).map(normalizeBlock);
            if (this.blocks.length > 0) {
                this.selectedId = this.blocks[0].id;
            }
        },

        get selected(): BuilderBlock | null {
            return this.blocks.find((b) => b.id === this.selectedId) ?? null;
        },

        get filteredMedia(): MediaItem[] {
            const q = this.mediaQuery.trim().toLowerCase();
            if (!q) return this.boot.media || [];
            return (this.boot.media || []).filter(
                (m) => m.title.toLowerCase().includes(q) || m.mime.toLowerCase().includes(q),
            );
        },

        get filteredDocs(): DocItem[] {
            const q = this.docsQuery.trim().toLowerCase();
            if (!q) return this.boot.documents || [];
            return (this.boot.documents || []).filter(
                (d) => d.title.toLowerCase().includes(q) || d.mime.toLowerCase().includes(q),
            );
        },

        get filteredVariables(): BuilderVar[] {
            const q = this.varFilter.trim().toLowerCase();
            return (this.boot.variables || []).filter((v) => {
                if (this.varKind !== 'all' && v.kind !== this.varKind) return false;
                if (!q) return true;
                return (
                    v.path.toLowerCase().includes(q)
                    || v.label.toLowerCase().includes(q)
                    || v.group.toLowerCase().includes(q)
                );
            });
        },

        variableGroups(): Array<{ group: string; items: BuilderVar[] }> {
            const map = new Map<string, BuilderVar[]>();
            for (const item of this.filteredVariables) {
                const list = map.get(item.group) || [];
                list.push(item);
                map.set(item.group, list);
            }
            return Array.from(map.entries()).map(([group, items]) => ({ group, items }));
        },

        select(id: string) {
            this.selectedId = id;
            this.rightTab = 'content';
        },

        markDirty() {
            this.dirty = true;
            this.status = '';
        },

        typeLabel(type: string): string {
            return (this.boot.blockTypes || []).find((t) => t.value === type)?.label || type;
        },

        layerLabel(block: BuilderBlock): string {
            const typeLabel = this.typeLabel(block.type);
            const p = block.payload;
            if (block.type === 'heading' && p.text) return this.expandPreview(String(p.text)).slice(0, 40);
            if (block.type === 'text' && p.body) return stripHtml(this.expandPreview(String(p.body))).slice(0, 40) || typeLabel;
            if (block.type === 'cta' && p.label) return this.expandPreview(String(p.label));
            if (block.type === 'image') return String(p.caption || p.alt || typeLabel);
            return typeLabel;
        },

        expandPreview(text: string): string {
            const values = this.boot.varValues || {};
            return text.replace(/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/g, (full, path: string) => {
                if (Object.prototype.hasOwnProperty.call(values, path)) {
                    return values[path] ?? full;
                }
                return full;
            });
        },

        addBlock(type: string, atIndex: number | null = null) {
            const id = 'tmp_' + Math.random().toString(36).slice(2, 10);
            const block: BuilderBlock = {
                id,
                type,
                sort_order: this.blocks.length + 1,
                payload: defaultPayload(type),
                _new: true,
            };
            if (atIndex === null || atIndex < 0 || atIndex > this.blocks.length) {
                this.blocks.push(block);
            } else {
                this.blocks.splice(atIndex, 0, block);
            }
            this.selectedId = id;
            this.leftTab = 'layers';
            this.reindex();
            this.markDirty();
        },

        removeSelected() {
            if (!this.selectedId) return;
            const idx = this.blocks.findIndex((b) => b.id === this.selectedId);
            if (idx < 0) return;
            this.blocks.splice(idx, 1);
            this.selectedId = this.blocks[idx]?.id ?? this.blocks[idx - 1]?.id ?? null;
            this.reindex();
            this.markDirty();
        },

        moveSelected(dir: number) {
            if (!this.selectedId) return;
            const idx = this.blocks.findIndex((b) => b.id === this.selectedId);
            const next = idx + dir;
            if (idx < 0 || next < 0 || next >= this.blocks.length) return;
            const tmp = this.blocks[idx];
            this.blocks[idx] = this.blocks[next];
            this.blocks[next] = tmp;
            this.reindex();
            this.markDirty();
        },

        moveBlock(fromId: string, toId: string | null) {
            const from = this.blocks.findIndex((b) => b.id === fromId);
            if (from < 0) return;
            const [item] = this.blocks.splice(from, 1);
            if (toId === null) {
                this.blocks.push(item);
            } else {
                let to = this.blocks.findIndex((b) => b.id === toId);
                if (to < 0) to = this.blocks.length;
                this.blocks.splice(to, 0, item);
            }
            this.selectedId = item.id;
            this.reindex();
            this.markDirty();
        },

        reindex() {
            this.blocks.forEach((b, i) => {
                b.sort_order = i + 1;
            });
        },

        setPayload(key: string, value: string | number) {
            const block = this.selected;
            if (!block) return;
            block.payload = { ...block.payload, [key]: value };
            this.markDirty();
        },

        onTextInput(key: string, event: Event) {
            const t = event.target as HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement;
            this.setPayload(key, t.value);
        },

        focusField(key: string) {
            this.insertTarget = { key };
        },

        insertVariable(path: string) {
            const meta = (this.boot.variables || []).find((v) => v.path === path);
            const token = meta?.token || `{{${path}}}`;
            if (!this.selected || !this.insertTarget) {
                toast(this.boot.labels.pickField || 'Nejdřív klikni do pole v inspectoru.', 'info');
                this.leftTab = 'vars';
                return;
            }
            const key = this.insertTarget.key;
            const current = String(this.selected.payload[key] ?? '');
            const el = document.activeElement as HTMLInputElement | HTMLTextAreaElement | null;
            if (el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA') && typeof el.selectionStart === 'number') {
                const start = el.selectionStart ?? current.length;
                const end = el.selectionEnd ?? start;
                const next = current.slice(0, start) + token + current.slice(end);
                this.setPayload(key, next);
                this.$nextTick(() => {
                    el.focus();
                    const pos = start + token.length;
                    el.setSelectionRange(pos, pos);
                });
                return;
            }
            this.setPayload(key, current + token);
        },

        applyMedia(media: MediaItem) {
            if (this.selected?.type === 'image') {
                this.setPayload('media_id', media.id);
                this.setPayload('url', media.url);
                if (!this.selected.payload.alt) {
                    this.setPayload('alt', media.alt || media.title);
                }
                return;
            }
            if (this.selected?.type === 'gallery') {
                const current = String(this.selected.payload.media_ids || '')
                    .split(',')
                    .map((s) => s.trim())
                    .filter(Boolean);
                if (!current.includes(media.id)) current.push(media.id);
                this.setPayload('media_ids', current.join(','));
                return;
            }
            this.addBlock('image');
            this.setPayload('media_id', media.id);
            this.setPayload('url', media.url);
            this.setPayload('alt', media.alt || media.title);
        },

        applyDocument(doc: DocItem) {
            this.addBlock('cta');
            this.setPayload('label', doc.title);
            this.setPayload('url', doc.url);
            this.setPayload('style', 'primary');
        },

        imageSrc(block: BuilderBlock): string {
            const url = String(block.payload.url || '');
            return this.expandPreview(url);
        },

        headingLevel(block: BuilderBlock): number {
            return Number(block.payload.level) || 2;
        },

        plainPreview(html: unknown): string {
            return stripHtml(this.expandPreview(String(html || ''))).slice(0, 220);
        },

        onBlockDragStart(id: string, event: DragEvent) {
            this.dragId = id;
            this.dragAddType = null;
            if (event.dataTransfer) {
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', id);
            }
        },

        onAddDragStart(type: string, event: DragEvent) {
            this.dragAddType = type;
            this.dragId = null;
            if (event.dataTransfer) {
                event.dataTransfer.effectAllowed = 'copy';
                event.dataTransfer.setData('text/plain', 'add:' + type);
            }
        },

        onDragOver(targetId: string | null, event: DragEvent) {
            event.preventDefault();
            this.dropTargetId = targetId;
            if (event.dataTransfer) {
                event.dataTransfer.dropEffect = this.dragAddType ? 'copy' : 'move';
            }
        },

        onDrop(targetId: string | null, event: DragEvent) {
            event.preventDefault();
            const addType = this.dragAddType;
            const fromId = this.dragId;
            this.dragId = null;
            this.dragAddType = null;
            this.dropTargetId = null;
            if (addType) {
                if (targetId === null) {
                    this.addBlock(addType);
                } else {
                    const idx = this.blocks.findIndex((b) => b.id === targetId);
                    this.addBlock(addType, idx < 0 ? null : idx);
                }
                return;
            }
            if (fromId && fromId !== targetId) {
                this.moveBlock(fromId, targetId);
            }
        },

        onDragEnd() {
            this.dragId = null;
            this.dragAddType = null;
            this.dropTargetId = null;
        },

        async save() {
            if (this.saving) return;
            this.saving = true;
            this.status = this.boot.labels.saving || 'Ukládám…';
            try {
                const res = await fetch(this.boot.saveUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrfToken(this.boot),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        blocks: this.blocks.map((b, i) => ({
                            id: b._new || b.id.startsWith('tmp_') ? null : b.id,
                            client_id: b.id,
                            type: b.type,
                            sort_order: i + 1,
                            payload: b.payload,
                        })),
                    }),
                });
                const json = (await res.json()) as {
                    data?: { blocks?: BuilderBlock[]; id_map?: Record<string, string> };
                    error?: { message?: string };
                    message?: string;
                };
                if (!res.ok) {
                    throw new Error(json?.error?.message || json?.message || 'Save failed');
                }
                const saved = json.data?.blocks || [];
                const map = json.data?.id_map || {};
                this.blocks = saved.map(normalizeBlock);
                if (this.selectedId && map[this.selectedId]) {
                    this.selectedId = map[this.selectedId];
                } else if (this.selectedId && !this.blocks.some((b) => b.id === this.selectedId)) {
                    this.selectedId = this.blocks[0]?.id ?? null;
                }
                this.dirty = false;
                this.status = this.boot.labels.saved || 'Uloženo';
            } catch (e) {
                this.status = e instanceof Error ? e.message : 'Chyba uložení';
            } finally {
                this.saving = false;
            }
        },
    }));
}
