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

export function registerPublishBadge(alpine: typeof Alpine): void {
    alpine.data('publishBadge', () => ({
        count: 0,
        pendingUrl: '',
        publishUrl: '',
        labelPending: '',
        labelCta: '',
        labelPublishing: '',
        labelSubmit: '',
        hovered: false,
        popoverOpen: false,
        pinned: false,
        loading: false,
        publishing: false,
        loaded: false,
        version: '',
        categories: [] as PendingCategory[],
        selected: [] as string[],
        leaveTimer: null as number | null,

        init() {
            const el = this.$el as HTMLElement;
            this.pendingUrl = el.dataset.pendingUrl || '';
            this.publishUrl = el.dataset.publishUrl || '';
            this.count = Number(el.dataset.count || '0') || 0;
            this.labelPending = el.dataset.labelPending || '';
            this.labelCta = el.dataset.labelCta || '';
            this.labelPublishing = el.dataset.labelPublishing || '';
            this.labelSubmit = el.dataset.labelSubmit || '';
            window.addEventListener('publish:pending-changed', () => {
                this.loaded = false;
                void this.ensureLoaded();
            });
        },

        get chipLabel(): string {
            return this.hovered || this.pinned ? this.labelCta : this.labelPending;
        },

        get chipClass(): string {
            return this.hovered || this.pinned
                ? 'badge--warn publish-badge__chip--solid'
                : 'badge--ghost-warn';
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
            this.popoverOpen = true;
            void this.ensureLoaded();
        },

        onLeave() {
            this.leaveTimer = window.setTimeout(() => {
                this.hovered = false;
                if (!this.pinned) {
                    this.popoverOpen = false;
                }
            }, 180);
        },

        togglePanel() {
            this.pinned = !this.pinned;
            this.popoverOpen = this.pinned || this.hovered;
            if (this.popoverOpen) {
                void this.ensureLoaded();
            }
        },

        async ensureLoaded() {
            if (this.loaded) {
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
                this.loaded = true;
            } finally {
                this.loading = false;
            }
        },

        isCategoryChecked(cat: PendingCategory): boolean {
            return cat.items.length > 0 && cat.items.every((item) => this.selected.includes(item.id));
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

        async publishSelected() {
            if (this.selected.length === 0 || this.publishing) {
                return;
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
                    body: JSON.stringify({ entry_ids: this.selected }),
                });
                let json: unknown = null;
                try {
                    json = await res.json();
                } catch {
                    json = null;
                }
                if (res.status === 419) {
                    window.gereq?.toast?.(
                        errorMessage(json, 'Platnost formuláře vypršela. Obnovuji stránku…'),
                        'error',
                    );
                    window.setTimeout(() => window.location.reload(), 700);
                    return;
                }
                const payload = json as { ok?: boolean; data?: { version?: string } };
                if (!payload?.ok) {
                    window.gereq?.toast?.(errorMessage(json, 'Publish failed'), 'error');
                    return;
                }
                const version = payload.data?.version ?? '';
                window.gereq?.toast?.(
                    version
                        ? `Publikováno (${version})`
                        : 'Publikováno',
                    'success',
                );
                this.count = 0;
                this.categories = [];
                this.selected = [];
                this.popoverOpen = false;
                this.pinned = false;
                window.setTimeout(() => window.location.reload(), 450);
            } catch (e) {
                window.gereq?.toast?.(e instanceof Error ? e.message : 'Publish failed', 'error');
            } finally {
                this.publishing = false;
            }
        },
    }));
}
