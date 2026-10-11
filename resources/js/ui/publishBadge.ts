import type Alpine from '@alpinejs/csp';

type PendingItem = {
    id: string;
    label: string;
    title?: string;
    summary?: string;
    display: string;
    action: string;
    resource_key: string;
};

type StagingEntryDetail = {
    id: string;
    category: string;
    category_label: string;
    resource_key: string;
    resource_type: string;
    action: string;
    label: string;
    title: string;
    summary: string;
    href: string | null;
    production_snapshot: Record<string, unknown> | null;
    payload: Record<string, unknown>;
    updated_at: string;
    created_by: string | null;
};

type PendingCategory = {
    category: string;
    label: string;
    countLabel: string;
    items: PendingItem[];
};

type PendingResponse = {
    ok: boolean;
    data: {
        count: number;
        version: string;
        categories: Array<{
            category: string;
            label: string;
            items: Array<{
                id: string;
                label: string;
                title?: string;
                summary?: string;
                action: string;
                resource_key: string;
            }>;
        }>;
    };
};

type StagingStore = {
    count: number;
    version: string;
    categories: PendingCategory[];
    selected: string[];
    loading: boolean;
    publishing: boolean;
    booted: boolean;
    pendingUrl: string;
    publishUrl: string;
    discardUrl: string;
    countUrl: string;
    workspaceUrl: string;
    boot(el: HTMLElement): void;
    refresh(): Promise<void>;
    refreshCount(): Promise<void>;
    toggleCategory(cat: PendingCategory): void;
    toggleItem(item: PendingItem): void;
    isCategoryChecked(cat: PendingCategory): boolean;
    isItemChecked(item: PendingItem): boolean;
    publishSelected(): Promise<void>;
    discardSelected(): Promise<void>;
    publishIds(ids: string[]): Promise<boolean>;
    discardIds(ids: string[]): Promise<boolean>;
};

function csrfToken(): string {
    return (window as Window & { __GEREQ_CSRF__?: string }).__GEREQ_CSRF__ || '';
}

function errorMessage(json: unknown, fallback: string): string {
    if (!json || typeof json !== 'object') {
        return fallback;
    }
    const err = (json as { error?: unknown }).error;
    if (typeof err === 'string' && err !== '') {
        return err;
    }
    if (err && typeof err === 'object') {
        const message = (err as { message?: unknown }).message;
        if (typeof message === 'string' && message !== '') {
            return message;
        }
    }
    return fallback;
}

function normalizeCategories(
    categories: PendingResponse['data']['categories'],
): PendingCategory[] {
    return categories.map((cat) => ({
        category: cat.category,
        label: cat.label,
        countLabel: `(${cat.items.length})`,
        items: cat.items.map((item) => ({
            ...item,
            display: item.title || item.label,
            summary: item.summary || '',
        })),
    }));
}

type GereqUi = {
    toast?: (message: string, tone?: string) => void;
    confirm?: (opts: string | { title?: string; body?: string }) => Promise<boolean>;
    staging?: {
        refresh: () => Promise<void>;
        refreshCount: () => Promise<void>;
    };
};

function gereqUi(): GereqUi {
    return ((window as Window & { gereq?: GereqUi }).gereq ?? {}) as GereqUi;
}

function toast(message: string, tone: string = 'success'): void {
    gereqUi().toast?.(message, tone);
}

async function confirmDiscard(body: string): Promise<boolean> {
    const api = gereqUi();
    if (api.confirm) {
        return api.confirm({ body, title: 'Zahodit změny?' });
    }
    return true;
}

function storeOf(alpine: typeof Alpine): StagingStore {
    return alpine.store('staging') as StagingStore;
}

export function registerPublishBadge(alpine: typeof Alpine): void {
    alpine.store('staging', {
        count: 0,
        version: '',
        categories: [] as PendingCategory[],
        selected: [] as string[],
        loading: false,
        publishing: false,
        booted: false,
        pendingUrl: '',
        publishUrl: '',
        discardUrl: '',
        countUrl: '',
        workspaceUrl: '',

        boot(el: HTMLElement) {
            // Merge URLs from any mount point (badge / workspace / detail).
            this.pendingUrl = this.pendingUrl || el.dataset.pendingUrl || '';
            this.publishUrl = this.publishUrl || el.dataset.publishUrl || '';
            this.discardUrl = this.discardUrl || el.dataset.discardUrl || '';
            this.countUrl = this.countUrl || el.dataset.countUrl || '';
            this.workspaceUrl = this.workspaceUrl || el.dataset.workspaceUrl || '';

            if (this.booted) {
                return;
            }
            this.count = Number(el.dataset.count || '0') || 0;
            this.booted = true;

            const w = window as unknown as { gereq?: Record<string, unknown> };
            w.gereq = {
                ...(w.gereq || {}),
                staging: {
                    refresh: () => this.refresh(),
                    refreshCount: () => this.refreshCount(),
                },
            };
        },

        async refresh() {
            if (!this.pendingUrl || this.loading) {
                return;
            }
            this.loading = true;
            try {
                const res = await fetch(this.pendingUrl, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });
                const json = (await res.json()) as PendingResponse;
                if (!json.ok) {
                    return;
                }
                this.count = json.data.count;
                this.version = json.data.version;
                const normalized = normalizeCategories(json.data.categories);
                this.categories = normalized;
                this.selected = normalized.flatMap((cat) => cat.items.map((item) => item.id));
            } finally {
                this.loading = false;
            }
        },

        async refreshCount() {
            if (!this.countUrl) {
                return;
            }
            try {
                const res = await fetch(this.countUrl, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });
                const json = (await res.json()) as { ok?: boolean; data?: { count?: number } };
                if (json.ok && typeof json.data?.count === 'number') {
                    this.count = json.data.count;
                }
            } catch {
                // ignore
            }
        },

        isCategoryChecked(cat: PendingCategory): boolean {
            return cat.items.length > 0 && cat.items.every((item) => this.selected.includes(item.id));
        },

        isItemChecked(item: PendingItem): boolean {
            return this.selected.includes(item.id);
        },

        toggleCategory(cat: PendingCategory) {
            const ids = cat.items.map((item) => item.id);
            const checked = !this.isCategoryChecked(cat);
            if (checked) {
                this.selected = Array.from(new Set([...this.selected, ...ids]));
            } else {
                this.selected = this.selected.filter((id) => !ids.includes(id));
            }
        },

        toggleItem(item: PendingItem) {
            if (this.selected.includes(item.id)) {
                this.selected = this.selected.filter((id) => id !== item.id);
            } else {
                this.selected = [...this.selected, item.id];
            }
        },

        async publishIds(ids: string[]): Promise<boolean> {
            if (ids.length === 0 || this.publishing || !this.publishUrl) {
                return false;
            }
            this.publishing = true;
            try {
                const res = await fetch(this.publishUrl, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ entry_ids: ids }),
                });
                let json: unknown = null;
                try {
                    json = await res.json();
                } catch {
                    json = null;
                }
                if (res.status === 419) {
                    toast(errorMessage(json, 'Platnost formuláře vypršela. Obnovuji stránku…'), 'error');
                    window.setTimeout(() => window.location.reload(), 700);
                    return false;
                }
                const payload = json as { ok?: boolean; data?: { version?: string } };
                if (!payload?.ok) {
                    toast(errorMessage(json, 'Publish failed'), 'error');
                    return false;
                }
                const version = payload.data?.version ?? '';
                toast(version ? `Publikováno (${version})` : 'Publikováno', 'success');
                await this.refresh();
                return true;
            } catch (e) {
                toast(e instanceof Error ? e.message : 'Publish failed', 'error');
                return false;
            } finally {
                this.publishing = false;
            }
        },

        async discardIds(ids: string[]): Promise<boolean> {
            if (ids.length === 0 || this.publishing || !this.discardUrl) {
                return false;
            }
            const ok = await confirmDiscard(
                'Zahodit vybrané změny? Pracovní kopie se vrátí na produkční snapshot.',
            );
            if (!ok) {
                return false;
            }
            this.publishing = true;
            try {
                const res = await fetch(this.discardUrl, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ entry_ids: ids }),
                });
                const json = await res.json();
                if (!json?.ok) {
                    toast(errorMessage(json, 'Discard failed'), 'error');
                    return false;
                }
                toast('Změny zahozeny', 'success');
                await this.refresh();
                return true;
            } catch (e) {
                toast(e instanceof Error ? e.message : 'Discard failed', 'error');
                return false;
            } finally {
                this.publishing = false;
            }
        },

        async publishSelected() {
            await this.publishIds(this.selected);
        },

        async discardSelected() {
            await this.discardIds(this.selected);
        },
    } satisfies StagingStore);

    // Thin CSP-safe views over the shared store (no local fetch state).
    alpine.data('publishBadge', () => ({
        hovered: false,
        popoverOpen: false,
        pinned: false,
        leaveTimer: null as number | null,
        labelPending: '',
        labelCta: '',
        labelIdle: '',
        labelPublishing: '',
        labelSubmit: '',

        init() {
            const el = this.$el as HTMLElement;
            storeOf(alpine).boot(el);
            this.labelPending = el.dataset.labelPending || '';
            this.labelCta = el.dataset.labelCta || '';
            this.labelIdle = el.dataset.labelIdle || this.labelPending;
            this.labelPublishing = el.dataset.labelPublishing || '';
            this.labelSubmit = el.dataset.labelSubmit || '';
        },

        get staging(): StagingStore {
            return storeOf(alpine);
        },

        get count(): number {
            return this.staging.count;
        },

        get categories(): PendingCategory[] {
            return this.staging.categories;
        },

        get selected(): string[] {
            return this.staging.selected;
        },

        get loading(): boolean {
            return this.staging.loading;
        },

        get publishing(): boolean {
            return this.staging.publishing;
        },

        get workspaceUrl(): string {
            return this.staging.workspaceUrl;
        },

        get chipLabel(): string {
            if (this.count <= 0) {
                return this.labelIdle;
            }
            return this.hovered || this.pinned ? this.labelCta : this.labelPending;
        },

        get isIdle(): boolean {
            return this.count <= 0;
        },

        get chipClass(): string {
            if (this.isIdle) {
                return 'badge--ok publish-badge__chip--idle';
            }
            // Always solid warn — ghost-warn looked washed out until hover.
            return 'badge--warn publish-badge__chip--solid';
        },

        get expandedAttr(): string {
            return this.popoverOpen ? 'true' : 'false';
        },

        get showEmpty(): boolean {
            return !this.loading && this.categories.length === 0;
        },

        get cannotPublish(): boolean {
            return this.publishing || this.selected.length === 0;
        },

        get submitLabel(): string {
            return this.publishing ? this.labelPublishing : this.labelSubmit;
        },

        onEnter() {
            if (this.leaveTimer) {
                window.clearTimeout(this.leaveTimer);
                this.leaveTimer = null;
            }
            this.hovered = true;
            const firstOpen = !this.popoverOpen;
            this.popoverOpen = true;
            if (firstOpen) {
                void this.staging.refresh();
            }
        },

        onLeave() {
            this.leaveTimer = window.setTimeout(() => {
                this.hovered = false;
                if (!this.pinned) {
                    this.popoverOpen = false;
                }
            }, 220);
        },

        togglePanel() {
            this.pinned = !this.pinned;
            this.popoverOpen = this.pinned || this.hovered;
            if (this.popoverOpen) {
                void this.staging.refresh();
            }
        },

        isCategoryChecked(cat: PendingCategory): boolean {
            return this.staging.isCategoryChecked(cat);
        },

        isItemChecked(item: PendingItem): boolean {
            return this.staging.isItemChecked(item);
        },

        toggleCategory(cat: PendingCategory) {
            this.staging.toggleCategory(cat);
        },

        toggleItem(item: PendingItem) {
            this.staging.toggleItem(item);
        },

        async publishSelected() {
            const ok = await this.staging.publishIds(this.staging.selected);
            if (ok) {
                this.popoverOpen = false;
                this.pinned = false;
                window.setTimeout(() => window.location.reload(), 450);
            }
        },
    }));

    alpine.data('publishWorkspace', () => ({
        detailBase: '',
        rollbackUrl: '',
        labelRollbackOk: '',
        labelRollbackPartial: '',
        labelRollbackFailed: '',
        detailOpen: false,
        detailLoading: false,
        detail: null as StagingEntryDetail | null,
        releases: [] as Array<{
            id: string;
            version: string;
            change_count: number;
            created_at: string;
            can_rollback?: boolean;
            kind?: string;
        }>,

        init() {
            const el = this.$el as HTMLElement;
            // Workspace may render before/without badge — still seed URLs from data-*.
            storeOf(alpine).boot(el);
            this.detailBase = (el.dataset.detailBase || el.dataset.workspaceUrl || '').replace(/\/$/, '');
            this.rollbackUrl = el.dataset.rollbackUrl || '';
            this.labelRollbackOk = el.dataset.labelRollbackOk || 'Rollback hotov (%version%) — obnoveno %restored% změn.';
            this.labelRollbackPartial =
                el.dataset.labelRollbackPartial ||
                'Rollback částečný (%version%) — obnoveno %restored% z %expected% (přeskočeno %skipped%).';
            this.labelRollbackFailed = el.dataset.labelRollbackFailed || 'Rollback selhal.';
            try {
                const raw = el.dataset.releases || '[]';
                const parsed = JSON.parse(raw) as typeof this.releases;
                this.releases = Array.isArray(parsed) ? parsed : [];
            } catch {
                this.releases = [];
            }
            // One load into the shared store — badge reads the same reactive data.
            void storeOf(alpine).refresh();
        },

        get staging(): StagingStore {
            return storeOf(alpine);
        },

        get categories(): PendingCategory[] {
            return this.staging.categories;
        },

        get selected(): string[] {
            return this.staging.selected;
        },

        set selected(value: string[]) {
            this.staging.selected = value;
        },

        get loading(): boolean {
            return this.staging.loading;
        },

        get busy(): boolean {
            return this.staging.publishing;
        },

        get cannotAct(): boolean {
            return this.busy || this.selected.length === 0;
        },

        itemHref(item: PendingItem): string {
            const base = this.detailBase || this.staging.workspaceUrl.replace(/\/$/, '');
            return `${base}/${encodeURIComponent(item.id)}`;
        },

        formatJson(value: unknown): string {
            try {
                return JSON.stringify(value ?? null, null, 2);
            } catch {
                return String(value);
            }
        },

        async openDetail(item: PendingItem) {
            const href = this.itemHref(item);
            this.detailOpen = true;
            this.detailLoading = true;
            this.detail = null;
            try {
                const res = await fetch(href, {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                });
                const json = (await res.json()) as { ok?: boolean; data?: StagingEntryDetail; error?: string };
                if (!res.ok || !json?.ok || !json.data) {
                    toast(errorMessage(json, 'Detail se nepodařilo načíst'), 'error');
                    this.detailOpen = false;
                    return;
                }
                this.detail = json.data;
            } catch (e) {
                toast(e instanceof Error ? e.message : 'Detail se nepodařilo načíst', 'error');
                this.detailOpen = false;
            } finally {
                this.detailLoading = false;
            }
        },

        closeDetail() {
            if (!this.detailOpen) {
                return;
            }
            this.detailOpen = false;
            this.detail = null;
            this.detailLoading = false;
        },

        async publishDetail() {
            const id = this.detail?.id;
            if (!id) {
                return;
            }
            const ok = await this.staging.publishIds([id]);
            if (ok) {
                this.closeDetail();
            }
        },

        async discardDetail() {
            const id = this.detail?.id;
            if (!id) {
                return;
            }
            const ok = await this.staging.discardIds([id]);
            if (ok) {
                this.closeDetail();
            }
        },

        isCategoryChecked(cat: PendingCategory): boolean {
            return this.staging.isCategoryChecked(cat);
        },

        isItemChecked(item: PendingItem): boolean {
            return this.staging.isItemChecked(item);
        },

        toggleCategory(cat: PendingCategory) {
            this.staging.toggleCategory(cat);
        },

        toggleItem(item: PendingItem) {
            this.staging.toggleItem(item);
        },

        refresh() {
            return this.staging.refresh();
        },

        publishSelected() {
            return this.staging.publishSelected();
        },

        discardSelected() {
            return this.staging.discardSelected();
        },

        async rollbackRelease(release: { id: string; version: string }) {
            if (!this.rollbackUrl || !release?.id) {
                return;
            }
            const api = gereqUi();
            const ok = api.confirm
                ? await api.confirm({
                    title: 'Nouzový rollback',
                    body: `Vrátit produkci na stav před verzí ${release.version}? Vytvoří se nový release typu rollback.`,
                })
                : true;
            if (!ok) {
                return;
            }
            try {
                const res = await fetch(this.rollbackUrl, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ release_id: release.id }),
                });
                let json: unknown = null;
                try {
                    json = await res.json();
                } catch {
                    json = null;
                }
                const payload = json as {
                    ok?: boolean;
                    data?: {
                        version?: string;
                        restored?: number;
                        expected?: number;
                        skipped?: number;
                        status?: 'ok' | 'partial';
                    };
                };
                if (!res.ok || !payload?.ok || !payload.data) {
                    toast(errorMessage(json, this.labelRollbackFailed), 'danger');
                    return;
                }
                const data = payload.data;
                const vars: Record<string, string> = {
                    '%version%': data.version ?? '',
                    '%restored%': String(data.restored ?? 0),
                    '%expected%': String(data.expected ?? data.restored ?? 0),
                    '%skipped%': String(data.skipped ?? 0),
                };
                const fill = (tpl: string): string =>
                    Object.entries(vars).reduce((msg, [key, value]) => msg.replaceAll(key, value), tpl);

                if (data.status === 'partial') {
                    toast(fill(this.labelRollbackPartial), 'warning');
                } else {
                    toast(fill(this.labelRollbackOk), 'success');
                }
                window.setTimeout(() => window.location.reload(), 700);
            } catch (e) {
                toast(e instanceof Error ? e.message : this.labelRollbackFailed, 'danger');
            }
        },
    }));

    alpine.data('publishEntryActions', () => ({
        entryId: '',
        workspaceUrl: '',

        init() {
            const el = this.$el as HTMLElement;
            storeOf(alpine).boot(el);
            this.entryId = el.dataset.entryId || '';
            this.workspaceUrl = el.dataset.workspaceUrl || storeOf(alpine).workspaceUrl;
        },

        get busy(): boolean {
            return storeOf(alpine).publishing;
        },

        async publish() {
            if (!this.entryId) {
                return;
            }
            const ok = await storeOf(alpine).publishIds([this.entryId]);
            if (ok) {
                window.location.href = this.workspaceUrl;
            }
        },

        async discard() {
            if (!this.entryId) {
                return;
            }
            const ok = await storeOf(alpine).discardIds([this.entryId]);
            if (ok) {
                window.location.href = this.workspaceUrl;
            }
        },
    }));
}
