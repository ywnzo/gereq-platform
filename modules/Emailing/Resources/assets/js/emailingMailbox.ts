import type Alpine from '@alpinejs/csp';

type ComposeDraft = {
    accountId: string;
    to: string;
    cc: string;
    subject: string;
    body: string;
    inReplyTo: string;
    references: string;
};

type MailLayout = 'right' | 'bottom' | 'off';

type LayoutPrefs = {
    layout: MailLayout;
    listWidth: number;
    listHeight: number;
};

type ComposeGeo = {
    left: number;
    top: number;
    width: number;
    height: number;
};

type CtxFolder = {
    id: string;
    name: string;
    system: boolean;
    favorite: boolean;
    unread: number;
    total: number;
    color?: string | null;
    role?: string;
};

type CtxMessage = {
    id: string;
    from_address?: string;
    subject?: string;
    thread_subject?: string;
    message_id?: string;
    references?: string;
    unread?: boolean;
    flagged?: boolean;
    pinned?: boolean;
    keywords?: string[];
};

type CtxLabel = {
    id: string;
    name: string;
    keyword: string;
    color?: string | null;
};

type EmailingMailboxState = {
    composeOpen: boolean;
    composeMinimized: boolean;
    draft: ComposeDraft;
    canCompose: boolean;
    defaultAccountId: string;
    signatures: Record<string, string>;
    pillFallback: string;
    expandedThreads: Record<string, boolean>;
    collapsedDateGroups: Record<string, boolean>;
    layout: MailLayout;
    listWidth: number;
    listHeight: number;
    layoutMenuOpen: boolean;
    resizing: boolean;
    pollUrl: string;
    actionUrl: string;
    currentFolderId: string;
    pollTimer: ReturnType<typeof setInterval> | null;
    pollInFlight: boolean;
    composeLeft: number;
    composeTop: number;
    composeWidth: number;
    composeHeight: number;
    composeGeoReady: boolean;
    selectedIds: Record<string, boolean>;
    bulkIds: string[];
    bulkMoveOpen: boolean;
    bulkSelectedLabel: string;
    filterDialogOpen: boolean;
    filterUnread: boolean;
    filterHasAttachment: boolean;
    filterFrom: string;
    filterUnreadDraft: boolean;
    filterHasAttachmentDraft: boolean;
    filterFromDraft: string;
    ctxOpen: boolean;
    ctxKind: '' | 'folder' | 'message';
    ctxFolder: CtxFolder | null;
    ctxMessage: CtxMessage | null;
    ctxSub: string;
    ctxX: number;
    ctxY: number;
    ctxFolders: CtxFolder[];
    ctxLabels: CtxLabel[];
    folderColors: string[];
    ctxI18n: Record<string, string>;
    init(): void;
    switchAccount(event: Event): void;
    addSearchTag(event: Event): void;
    openFilterDialog(): void;
    closeFilterDialog(): void;
    filterBtnClass(): string;
    applyFilters(): void;
    clearFilters(): void;
    destroy(): void;
    openCompose(event?: Event): void;
    openComposeReply(event: Event): void;
    openComposeForward(event: Event): void;
    minimizeCompose(): void;
    onEscape(): void;
    restoreCompose(): void;
    closeCompose(): void;
    pillLabel(): string;
    applyDraft(partial: Partial<ComposeDraft>): void;
    applyAccountSignature(): void;
    syncUrlComposeFlag(open: boolean): void;
    syncComposeFields(): void;
    onComposeFieldChange(event: Event): void;
    bodyToHtml(raw: string): string;
    toggleThread(threadId: string): void;
    isThreadExpanded(threadId: string): boolean;
    isThreadCollapsed(threadId: string): boolean;
    threadAriaExpanded(threadId: string): string;
    toggleDateGroup(groupKey: string): void;
    isDateGroupExpanded(groupKey: string): boolean;
    isDateGroupCollapsed(groupKey: string): boolean;
    dateGroupAriaExpanded(groupKey: string): string;
    gridClass(): string;
    gridStyle(): Record<string, string>;
    showSplitter(): boolean;
    showReader(): boolean;
    isLayout(mode: MailLayout): boolean;
    layoutBtnClass(mode: MailLayout): string;
    layoutMenuExpanded(): string;
    splitterOrientation(): string;
    setLayout(mode: MailLayout): void;
    toggleLayoutMenu(): void;
    closeLayoutMenu(): void;
    startResize(event: PointerEvent): void;
    persistLayout(): void;
    loadLayout(): void;
    defaultComposeGeo(): ComposeGeo;
    loadComposeGeo(): void;
    persistComposeGeo(): void;
    ensureComposeGeo(): void;
    composeDockStyle(): Record<string, string>;
    startComposeDrag(event: PointerEvent): void;
    startComposeResize(event: PointerEvent): void;
    startMailboxPoll(): void;
    stopMailboxPoll(): void;
    pollMailbox(): Promise<void>;
    applyFolderCounts(folders: Array<{ id: string; unread_count?: number; total_count?: number; local_unread?: number; local_total?: number }>): void;
    openFolderCtx(event: MouseEvent): void;
    openMessageCtx(event: MouseEvent): void;
    closeCtx(): void;
    ctxStyle(): Record<string, string>;
    toggleCtxSub(name: string): void;
    ctxSubIs(name: string): boolean;
    swatchStyle(color: string): Record<string, string>;
    dotStyle(color: string | null | undefined): Record<string, string>;
    ctxFolderMeta(): string;
    ctxFolderFavoriteLabel(): string;
    ctxFolderMarkReadDisabled(): boolean;
    ctxMessageSeenLabel(): string;
    ctxMessagePinLabel(): string;
    ctxMessageFlagLabel(): string;
    ctxMessageHasKeyword(keyword: string): boolean;
    ctxMessageSeenToggle(): void;
    runAction(payload: Record<string, unknown>): Promise<void>;
    ctxFolderCreate(): void;
    ctxFolderRename(): void;
    ctxFolderColor(color: string | null): void;
    ctxFolderDelete(): void;
    ctxFolderEmpty(): void;
    ctxFolderMarkRead(): void;
    ctxFolderFavorite(): void;
    ctxFolderOrder(direction: string): void;
    ctxFolderOpenWindow(): void;
    ctxMessageReply(): void;
    ctxMessageReplyAll(): void;
    ctxMessageForward(): void;
    ctxMessageDelete(): void;
    ctxMessageArchive(): void;
    ctxMessageMove(folderId: string): void;
    ctxMessageCopy(folderId: string): void;
    ctxMessagePin(): void;
    ctxMessageLabel(keyword: string): void;
    ctxMessageLabelCreate(): void;
    ctxMessageFlag(): void;
    prefixSubject(prefix: string, subject: string): string;
    isBulkSelected(id: string): boolean;
    toggleBulk(id: string, event?: Event): void;
    toggleBulkFromEvent(event: Event): void;
    bulkCount(): number;
    bulkCountLabel(): string;
    bulkAllSelected(): boolean;
    bulkSomeSelected(): boolean;
    bulkToggleAll(): void;
    syncBulkSelectAll(): void;
    bulkClear(): void;
    bulkClear(): void;
    toggleBulkMove(): void;
    closeBulkMove(): void;
    bulkRun(op: string, targetFolderId?: string): void;
};

const LAYOUT_KEY = 'gereq.emailing.layout.v1';
const COMPOSE_GEO_KEY = 'gereq.emailing.compose.geo.v1';
const DEFAULT_LIST_WIDTH = 360;
const DEFAULT_LIST_HEIGHT = 320;
const COMPOSE_MIN_W = 520;
const COMPOSE_MIN_H = 380;

function emptyDraft(accountId = ''): ComposeDraft {
    return {
        accountId,
        to: '',
        cc: '',
        subject: '',
        body: '',
        inReplyTo: '',
        references: '',
    };
}

function clamp(n: number, min: number, max: number): number {
    return Math.min(max, Math.max(min, n));
}

function escapeHtml(value: string): string {
    return value
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function bodyToHtml(raw: string): string {
    const text = raw.trim();
    if (text === '') {
        return '';
    }
    if (/<[a-z][\s\S]*>/i.test(text)) {
        return text;
    }
    return text
        .split(/\n{2,}/)
        .map((paragraph) => `<p>${escapeHtml(paragraph).replace(/\n/g, '<br>')}</p>`)
        .join('');
}

const SIGNATURE_ATTR = 'data-gereq-signature';

function stripSignatureHtml(html: string): string {
    return html
        .replace(new RegExp(`<div[^>]*${SIGNATURE_ATTR}[^>]*>[\\s\\S]*?<\\/div>`, 'gi'), '')
        .replace(/(?:<p><br\s*\/?><\/p>\s*)+$/i, '')
        .trim();
}

function wrapSignatureHtml(html: string): string {
    const trimmed = html.trim();
    if (trimmed === '') {
        return '';
    }
    return `<div ${SIGNATURE_ATTR}="1">${trimmed}</div>`;
}

function withAccountSignature(body: string, accountId: string, signatures: Record<string, string>): string {
    const base = stripSignatureHtml(bodyToHtml(body) || body);
    const sig = wrapSignatureHtml(signatures[accountId] || '');
    if (sig === '') {
        return base;
    }
    if (base === '') {
        return `<p></p>${sig}`;
    }
    return `${base}<p><br></p>${sig}`;
}

type AlpineSelectApi = { setValue?: (value: string) => boolean };
type AlpineEditorApi = {
    applyHtmlToEditor?: (html: string) => void;
    htmlSource?: string;
};

export function registerEmailingMailbox(alpine: typeof Alpine): void {
    alpine.data('emailingMailbox', (): EmailingMailboxState => ({
        composeOpen: false,
        composeMinimized: false,
        draft: emptyDraft(),
        canCompose: false,
        defaultAccountId: '',
        signatures: {},
        pillFallback: 'Nová zpráva',
        expandedThreads: {},
        collapsedDateGroups: {},
        layout: 'right',
        listWidth: DEFAULT_LIST_WIDTH,
        listHeight: DEFAULT_LIST_HEIGHT,
        layoutMenuOpen: false,
        resizing: false,
        pollUrl: '',
        actionUrl: '',
        currentFolderId: '',
        pollTimer: null,
        pollInFlight: false,
        composeLeft: 0,
        composeTop: 0,
        composeWidth: 960,
        composeHeight: 720,
        composeGeoReady: false,
        selectedIds: {},
        bulkIds: [],
        bulkMoveOpen: false,
        bulkSelectedLabel: 'Vybráno: %count%',
        filterDialogOpen: false,
        filterUnread: false,
        filterHasAttachment: false,
        filterFrom: '',
        filterUnreadDraft: false,
        filterHasAttachmentDraft: false,
        filterFromDraft: '',
        ctxOpen: false,
        ctxKind: '',
        ctxFolder: null,
        ctxMessage: null,
        ctxSub: '',
        ctxX: 0,
        ctxY: 0,
        ctxFolders: [],
        ctxLabels: [],
        folderColors: ['#c0392b', '#d35400', '#f1c40f', '#27ae60', '#2980b9', '#8e44ad', '#7f8c8d', '#e91e63'],
        ctxI18n: {},

        init() {
            const el = this.$el as HTMLElement;
            this.canCompose = el.dataset.canCompose === '1';
            this.defaultAccountId = el.dataset.defaultAccountId || '';
            try {
                const raw = JSON.parse(el.dataset.signatures || '{}') as Record<string, unknown>;
                const map: Record<string, string> = {};
                for (const [id, html] of Object.entries(raw)) {
                    if (typeof html === 'string' && html.trim() !== '') {
                        map[id] = html;
                    }
                }
                this.signatures = map;
            } catch {
                this.signatures = {};
            }
            this.pollUrl = el.dataset.pollUrl || '';
            this.actionUrl = el.dataset.actionUrl || '';
            this.currentFolderId = el.dataset.folderId || '';
            this.pillFallback = el.dataset.composeFallback || this.pillFallback;
            this.ctxI18n = {
                folderMeta: el.dataset.ctxFolderMeta || '%total% (%unread%)',
                favorite: el.dataset.ctxFav || '',
                unfavorite: el.dataset.ctxUnfav || '',
                markRead: el.dataset.ctxMarkRead || '',
                markUnread: el.dataset.ctxMarkUnread || '',
                pin: el.dataset.ctxPin || '',
                unpin: el.dataset.ctxUnpin || '',
                flag: el.dataset.ctxFlag || '',
                unflag: el.dataset.ctxUnflag || '',
            };
            this.bulkSelectedLabel = el.dataset.bulkSelected || this.bulkSelectedLabel;
            this.filterUnread = el.dataset.filterUnread === '1';
            this.filterHasAttachment = el.dataset.filterAttachment === '1';
            this.filterFrom = el.dataset.filterFrom || '';
            this.filterUnreadDraft = this.filterUnread;
            this.filterHasAttachmentDraft = this.filterHasAttachment;
            this.filterFromDraft = this.filterFrom;
            try {
                this.bulkIds = (JSON.parse(el.dataset.bulkIds || '[]') as unknown[])
                    .filter((id): id is string => typeof id === 'string' && id !== '');
            } catch {
                this.bulkIds = [];
            }
            try {
                this.ctxFolders = JSON.parse(el.dataset.folders || '[]') as CtxFolder[];
            } catch {
                this.ctxFolders = [];
            }
            try {
                this.ctxLabels = JSON.parse(el.dataset.labels || '[]') as CtxLabel[];
            } catch {
                this.ctxLabels = [];
            }
            this.draft = emptyDraft(this.defaultAccountId);
            this.loadLayout();
            this.loadComposeGeo();
            this.startMailboxPoll();

            const params = new URLSearchParams(window.location.search);
            const wantsCompose = params.get('compose') === '1' || params.has('to') || params.has('subject') || params.has('body');
            if (this.canCompose && wantsCompose) {
                this.applyDraft({
                    to: params.get('to') || '',
                    cc: params.get('cc') || '',
                    subject: params.get('subject') || '',
                    body: params.get('body') || '',
                    accountId: params.get('account_id') || this.defaultAccountId,
                });
                this.composeOpen = true;
                this.composeMinimized = false;
                this.syncUrlComposeFlag(true);
                this.$nextTick(() => this.syncComposeFields());
            }
        },

        destroy() {
            this.stopMailboxPoll();
        },

        switchAccount(event) {
            const select = event.target;
            if (!(select instanceof HTMLSelectElement) || !select.form) {
                return;
            }
            // Jen account — folder/message z předchozí schránky neposílat.
            select.form.submit();
        },

        addSearchTag(event) {
            const select = event.target;
            if (!(select instanceof HTMLSelectElement)) {
                return;
            }
            const keyword = select.value.trim();
            select.selectedIndex = 0;
            if (keyword === '') {
                return;
            }
            const url = new URL(window.location.href);
            const existing = [
                ...url.searchParams.getAll('tag[]'),
                ...url.searchParams.getAll('tag'),
            ];
            if (!existing.includes(keyword)) {
                url.searchParams.append('tag[]', keyword);
            }
            url.searchParams.delete('message');
            window.location.assign(url.toString());
        },

        toggleFilterMenu() {
            this.openFilterDialog();
        },

        openFilterDialog() {
            this.filterUnreadDraft = this.filterUnread;
            this.filterHasAttachmentDraft = this.filterHasAttachment;
            this.filterFromDraft = this.filterFrom;
            this.filterDialogOpen = true;
            this.$nextTick(() => {
                const input = this.$refs.filterFromInput as HTMLInputElement | undefined;
                input?.focus();
                input?.select();
            });
        },

        closeFilterDialog() {
            this.filterDialogOpen = false;
        },

        closeFilterMenu() {
            this.closeFilterDialog();
        },

        filterBtnClass() {
            const active = this.filterUnread || this.filterHasAttachment || this.filterFrom.trim() !== '';
            return active ? 'is-active' : '';
        },

        applyFilters() {
            const url = new URL(window.location.href);
            if (this.filterUnreadDraft) {
                url.searchParams.set('unread', '1');
            } else {
                url.searchParams.delete('unread');
            }
            if (this.filterHasAttachmentDraft) {
                url.searchParams.set('has_attachment', '1');
            } else {
                url.searchParams.delete('has_attachment');
            }
            const from = this.filterFromDraft.trim();
            if (from === '') {
                url.searchParams.delete('from');
            } else {
                url.searchParams.set('from', from);
            }
            url.searchParams.delete('message');
            this.filterDialogOpen = false;
            window.location.assign(url.toString());
        },

        clearFilters() {
            this.filterUnreadDraft = false;
            this.filterHasAttachmentDraft = false;
            this.filterFromDraft = '';
            const url = new URL(window.location.href);
            url.searchParams.delete('unread');
            url.searchParams.delete('has_attachment');
            url.searchParams.delete('from');
            url.searchParams.delete('message');
            this.filterDialogOpen = false;
            window.location.assign(url.toString());
        },

        startMailboxPoll() {
            this.stopMailboxPoll();
            if (!this.pollUrl) {
                return;
            }
            this.pollTimer = setInterval(() => {
                void this.pollMailbox();
            }, 5000);
        },

        stopMailboxPoll() {
            if (this.pollTimer !== null) {
                clearInterval(this.pollTimer);
                this.pollTimer = null;
            }
        },

        async pollMailbox() {
            if (!this.pollUrl || this.pollInFlight || document.hidden) {
                return;
            }
            this.pollInFlight = true;
            try {
                const res = await fetch(this.pollUrl, {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                });
                if (!res.ok) {
                    return;
                }
                const payload = (await res.json()) as {
                    data?: {
                        folders?: Array<{
                            id: string;
                            unread_count?: number;
                            total_count?: number;
                            local_unread?: number;
                            local_total?: number;
                        }>;
                        changed_folder_ids?: string[];
                        synced?: boolean;
                    };
                };
                const data = payload.data;
                if (!data) {
                    return;
                }
                if (data.folders) {
                    this.applyFolderCounts(data.folders);
                }
                const changed = data.changed_folder_ids || [];
                if (data.synced && this.currentFolderId && changed.includes(this.currentFolderId)) {
                    window.location.reload();
                }
            } catch {
                // tichý fail — další tick za 5s
            } finally {
                this.pollInFlight = false;
            }
        },

        applyFolderCounts(folders) {
            const root = this.$el as HTMLElement;
            for (const folder of folders) {
                const link = root.querySelector<HTMLElement>(`[data-folder-id="${CSS.escape(folder.id)}"]`);
                if (!link) {
                    continue;
                }
                const unread = folder.unread_count ?? folder.local_unread ?? 0;
                const total = folder.total_count ?? folder.local_total ?? 0;
                link.dataset.unread = String(unread);
                link.dataset.total = String(total);
                link.classList.toggle('has-unread', unread > 0);

                let badge = link.querySelector<HTMLElement>('[data-folder-badge]');
                if (unread > 0) {
                    if (!badge || badge.dataset.folderBadge !== 'unread') {
                        badge?.remove();
                        badge = document.createElement('span');
                        badge.className = 'ol-mail__badge';
                        badge.dataset.folderBadge = 'unread';
                        link.appendChild(badge);
                    }
                    badge.textContent = String(unread);
                } else if (total > 0) {
                    if (!badge || badge.dataset.folderBadge !== 'total') {
                        badge?.remove();
                        badge = document.createElement('span');
                        badge.className = 'ol-mail__count';
                        badge.dataset.folderBadge = 'total';
                        link.appendChild(badge);
                    }
                    badge.textContent = String(total);
                } else {
                    badge?.remove();
                }
            }
        },

        loadLayout() {
            try {
                const raw = localStorage.getItem(LAYOUT_KEY);
                if (!raw) {
                    return;
                }
                const data = JSON.parse(raw) as Partial<LayoutPrefs>;
                if (data.layout === 'right' || data.layout === 'bottom' || data.layout === 'off') {
                    this.layout = data.layout;
                }
                if (typeof data.listWidth === 'number' && Number.isFinite(data.listWidth)) {
                    this.listWidth = clamp(data.listWidth, 240, 900);
                }
                if (typeof data.listHeight === 'number' && Number.isFinite(data.listHeight)) {
                    this.listHeight = clamp(data.listHeight, 160, 800);
                }
            } catch {
                // ignore
            }
        },

        persistLayout() {
            try {
                const payload: LayoutPrefs = {
                    layout: this.layout,
                    listWidth: this.listWidth,
                    listHeight: this.listHeight,
                };
                localStorage.setItem(LAYOUT_KEY, JSON.stringify(payload));
            } catch {
                // ignore
            }
        },

        defaultComposeGeo() {
            const width = clamp(Math.min(1100, window.innerWidth - 48), COMPOSE_MIN_W, window.innerWidth - 24);
            const height = clamp(Math.min(820, window.innerHeight - 64), COMPOSE_MIN_H, window.innerHeight - 24);
            return {
                width,
                height,
                left: Math.round((window.innerWidth - width) / 2),
                top: Math.round((window.innerHeight - height) / 2),
            };
        },

        loadComposeGeo() {
            try {
                const raw = localStorage.getItem(COMPOSE_GEO_KEY);
                if (!raw) {
                    const geo = this.defaultComposeGeo();
                    this.composeLeft = geo.left;
                    this.composeTop = geo.top;
                    this.composeWidth = geo.width;
                    this.composeHeight = geo.height;
                    this.composeGeoReady = true;
                    return;
                }
                const data = JSON.parse(raw) as Partial<ComposeGeo>;
                const fallback = this.defaultComposeGeo();
                this.composeWidth = clamp(typeof data.width === 'number' ? data.width : fallback.width, COMPOSE_MIN_W, window.innerWidth);
                this.composeHeight = clamp(typeof data.height === 'number' ? data.height : fallback.height, COMPOSE_MIN_H, window.innerHeight);
                this.composeLeft = clamp(typeof data.left === 'number' ? data.left : fallback.left, 0, Math.max(0, window.innerWidth - this.composeWidth));
                this.composeTop = clamp(typeof data.top === 'number' ? data.top : fallback.top, 0, Math.max(0, window.innerHeight - this.composeHeight));
                this.composeGeoReady = true;
            } catch {
                const geo = this.defaultComposeGeo();
                this.composeLeft = geo.left;
                this.composeTop = geo.top;
                this.composeWidth = geo.width;
                this.composeHeight = geo.height;
                this.composeGeoReady = true;
            }
        },

        persistComposeGeo() {
            try {
                const payload: ComposeGeo = {
                    left: this.composeLeft,
                    top: this.composeTop,
                    width: this.composeWidth,
                    height: this.composeHeight,
                };
                localStorage.setItem(COMPOSE_GEO_KEY, JSON.stringify(payload));
            } catch {
                // ignore
            }
        },

        ensureComposeGeo() {
            if (!this.composeGeoReady) {
                this.loadComposeGeo();
            }
            this.composeWidth = clamp(this.composeWidth, COMPOSE_MIN_W, window.innerWidth);
            this.composeHeight = clamp(this.composeHeight, COMPOSE_MIN_H, window.innerHeight);
            this.composeLeft = clamp(this.composeLeft, 0, Math.max(0, window.innerWidth - this.composeWidth));
            this.composeTop = clamp(this.composeTop, 0, Math.max(0, window.innerHeight - this.composeHeight));
        },

        composeDockStyle() {
            return {
                left: `${this.composeLeft}px`,
                top: `${this.composeTop}px`,
                width: `${this.composeWidth}px`,
                height: `${this.composeHeight}px`,
            };
        },

        startComposeDrag(event) {
            if (event.button !== 0) {
                return;
            }
            const target = event.target as HTMLElement | null;
            if (target?.closest('button, a, input, select, textarea')) {
                return;
            }
            event.preventDefault();
            this.ensureComposeGeo();
            const startX = event.clientX;
            const startY = event.clientY;
            const originLeft = this.composeLeft;
            const originTop = this.composeTop;

            const onMove = (e: PointerEvent) => {
                this.composeLeft = clamp(originLeft + (e.clientX - startX), 0, Math.max(0, window.innerWidth - this.composeWidth));
                this.composeTop = clamp(originTop + (e.clientY - startY), 0, Math.max(0, window.innerHeight - this.composeHeight));
            };
            const onUp = () => {
                window.removeEventListener('pointermove', onMove);
                window.removeEventListener('pointerup', onUp);
                this.persistComposeGeo();
            };
            window.addEventListener('pointermove', onMove);
            window.addEventListener('pointerup', onUp);
        },

        startComposeResize(event) {
            if (event.button !== 0) {
                return;
            }
            event.preventDefault();
            event.stopPropagation();
            this.ensureComposeGeo();
            const edge = ((event.currentTarget as HTMLElement).dataset.edge || 'se').toLowerCase();
            const startX = event.clientX;
            const startY = event.clientY;
            const origin: ComposeGeo = {
                left: this.composeLeft,
                top: this.composeTop,
                width: this.composeWidth,
                height: this.composeHeight,
            };

            const onMove = (e: PointerEvent) => {
                const dx = e.clientX - startX;
                const dy = e.clientY - startY;
                let left = origin.left;
                let top = origin.top;
                let width = origin.width;
                let height = origin.height;

                if (edge.includes('e')) {
                    width = origin.width + dx;
                }
                if (edge.includes('s')) {
                    height = origin.height + dy;
                }
                if (edge.includes('w')) {
                    width = origin.width - dx;
                    left = origin.left + dx;
                }
                if (edge.includes('n')) {
                    height = origin.height - dy;
                    top = origin.top + dy;
                }

                width = clamp(width, COMPOSE_MIN_W, window.innerWidth);
                height = clamp(height, COMPOSE_MIN_H, window.innerHeight);

                if (edge.includes('w')) {
                    left = clamp(origin.left + (origin.width - width), 0, window.innerWidth - width);
                }
                if (edge.includes('n')) {
                    top = clamp(origin.top + (origin.height - height), 0, window.innerHeight - height);
                }

                this.composeLeft = clamp(left, 0, Math.max(0, window.innerWidth - width));
                this.composeTop = clamp(top, 0, Math.max(0, window.innerHeight - height));
                this.composeWidth = width;
                this.composeHeight = height;
            };
            const onUp = () => {
                window.removeEventListener('pointermove', onMove);
                window.removeEventListener('pointerup', onUp);
                this.persistComposeGeo();
            };
            window.addEventListener('pointermove', onMove);
            window.addEventListener('pointerup', onUp);
        },

        gridClass() {
            return `is-layout-${this.layout}${this.resizing ? ' is-resizing' : ''}`;
        },

        gridStyle() {
            return {
                '--ol-list-w': `${this.listWidth}px`,
                '--ol-list-h': `${this.listHeight}px`,
            };
        },

        showSplitter() {
            return this.layout === 'right' || this.layout === 'bottom';
        },

        showReader() {
            return this.layout !== 'off';
        },

        isLayout(mode) {
            return this.layout === mode;
        },

        layoutBtnClass(mode) {
            return this.layout === mode ? 'is-active' : '';
        },

        layoutMenuExpanded() {
            return this.layoutMenuOpen ? 'true' : 'false';
        },

        splitterOrientation() {
            return this.layout === 'bottom' ? 'horizontal' : 'vertical';
        },

        setLayout(mode) {
            this.layout = mode;
            this.layoutMenuOpen = false;
            this.persistLayout();
        },

        toggleLayoutMenu() {
            this.layoutMenuOpen = !this.layoutMenuOpen;
        },

        closeLayoutMenu() {
            this.layoutMenuOpen = false;
        },

        startResize(event) {
            if (!this.showSplitter()) {
                return;
            }
            event.preventDefault();
            const vertical = this.layout === 'bottom';
            const start = vertical ? event.clientY : event.clientX;
            const startSize = vertical ? this.listHeight : this.listWidth;
            this.resizing = true;

            const onMove = (e: PointerEvent) => {
                const delta = (vertical ? e.clientY : e.clientX) - start;
                if (vertical) {
                    this.listHeight = clamp(startSize + delta, 160, Math.floor(window.innerHeight * 0.7));
                } else {
                    this.listWidth = clamp(startSize + delta, 260, Math.floor(window.innerWidth * 0.55));
                }
            };
            const onUp = () => {
                this.resizing = false;
                window.removeEventListener('pointermove', onMove);
                window.removeEventListener('pointerup', onUp);
                this.persistLayout();
            };
            window.addEventListener('pointermove', onMove);
            window.addEventListener('pointerup', onUp);
        },

        applyDraft(partial) {
            this.draft = {
                ...this.draft,
                ...partial,
                accountId: partial.accountId || this.draft.accountId || this.defaultAccountId,
            };
            this.applyAccountSignature();
        },

        applyAccountSignature() {
            this.draft.body = withAccountSignature(
                this.draft.body || '',
                this.draft.accountId || this.defaultAccountId,
                this.signatures,
            );
        },

        bodyToHtml(raw) {
            return bodyToHtml(raw);
        },

        syncComposeFields() {
            const root = this.$el as HTMLElement;
            const run = (attempt: number) => {
                const selectRoot = root.querySelector<HTMLElement>('[data-field="account_id"] .g-select');
                const editorRoot = root.querySelector<HTMLElement>('[data-field="body_html"] .g-editor');
                if ((!selectRoot || !editorRoot) && attempt < 8) {
                    requestAnimationFrame(() => run(attempt + 1));
                    return;
                }

                if (selectRoot && this.draft.accountId && window.Alpine) {
                    const select = window.Alpine.$data(selectRoot) as AlpineSelectApi;
                    select.setValue?.(this.draft.accountId);
                }

                if (editorRoot && window.Alpine) {
                    const editor = window.Alpine.$data(editorRoot) as AlpineEditorApi;
                    editor.applyHtmlToEditor?.(this.bodyToHtml(this.draft.body));
                }

                const input = root.querySelector<HTMLInputElement>('[data-compose-focus]');
                input?.focus();
            };

            this.$nextTick(() => run(0));
        },

        onComposeFieldChange(event) {
            const target = event.target as HTMLInputElement | null;
            if (!target || target.name !== 'account_id') {
                return;
            }
            const root = this.$el as HTMLElement;
            const textarea = root.querySelector<HTMLTextAreaElement>('textarea[name="body_html"]');
            const editorRoot = root.querySelector<HTMLElement>('[data-field="body_html"] .g-editor');
            let current = textarea?.value || this.draft.body || '';
            if (editorRoot && window.Alpine) {
                const editor = window.Alpine.$data(editorRoot) as AlpineEditorApi;
                if (typeof editor.htmlSource === 'string' && editor.htmlSource !== '') {
                    current = editor.htmlSource;
                }
            }
            this.draft.body = current;
            this.draft.accountId = target.value || this.defaultAccountId;
            this.applyAccountSignature();
            this.$nextTick(() => this.syncComposeFields());
        },

        openCompose(event) {
            if (!this.canCompose) {
                return;
            }
            if (event) {
                event.preventDefault();
            }
            this.ensureComposeGeo();
            if (!this.composeOpen) {
                this.draft = emptyDraft(this.defaultAccountId);
                this.applyAccountSignature();
            }
            this.composeOpen = true;
            this.composeMinimized = false;
            this.syncUrlComposeFlag(true);
            this.$nextTick(() => this.syncComposeFields());
        },

        openComposeReply(event) {
            if (!this.canCompose) {
                return;
            }
            event.preventDefault();
            const el = event.currentTarget as HTMLElement;
            this.applyDraft({
                to: el.dataset.to || '',
                subject: el.dataset.subject || '',
                body: '',
                cc: '',
                accountId: this.defaultAccountId,
                inReplyTo: el.dataset.messageId || '',
                references: el.dataset.references || '',
            });
            this.composeOpen = true;
            this.composeMinimized = false;
            this.syncUrlComposeFlag(true);
            this.$nextTick(() => this.syncComposeFields());
        },

        openComposeForward(event) {
            if (!this.canCompose) {
                return;
            }
            event.preventDefault();
            const el = event.currentTarget as HTMLElement;
            this.applyDraft({
                to: '',
                subject: el.dataset.subject || '',
                body: el.dataset.body || '',
                cc: '',
                accountId: this.defaultAccountId,
                inReplyTo: '',
                references: '',
            });
            this.composeOpen = true;
            this.composeMinimized = false;
            this.syncUrlComposeFlag(true);
            this.$nextTick(() => this.syncComposeFields());
        },

        toggleThread(threadId) {
            if (!threadId) {
                return;
            }
            this.expandedThreads = {
                ...this.expandedThreads,
                [threadId]: !this.expandedThreads[threadId],
            };
        },

        isThreadExpanded(threadId) {
            return !!this.expandedThreads[threadId];
        },

        isThreadCollapsed(threadId) {
            return !this.expandedThreads[threadId];
        },

        threadAriaExpanded(threadId) {
            return this.expandedThreads[threadId] ? 'true' : 'false';
        },

        toggleDateGroup(groupKey) {
            if (!groupKey) {
                return;
            }
            this.collapsedDateGroups = {
                ...this.collapsedDateGroups,
                [groupKey]: !this.collapsedDateGroups[groupKey],
            };
        },

        isDateGroupExpanded(groupKey) {
            return !this.collapsedDateGroups[groupKey];
        },

        isDateGroupCollapsed(groupKey) {
            return !!this.collapsedDateGroups[groupKey];
        },

        dateGroupAriaExpanded(groupKey) {
            return this.collapsedDateGroups[groupKey] ? 'false' : 'true';
        },

        minimizeCompose() {
            if (!this.composeOpen) {
                return;
            }
            this.composeMinimized = true;
        },

        onEscape() {
            if (this.ctxOpen) {
                this.closeCtx();
                return;
            }
            if (this.filterDialogOpen) {
                this.closeFilterDialog();
                return;
            }
            if (this.layoutMenuOpen) {
                this.closeLayoutMenu();
                return;
            }
            if (this.composeOpen && !this.composeMinimized) {
                this.minimizeCompose();
            }
        },

        openFolderCtx(event) {
            const el = event.currentTarget as HTMLElement;
            this.ctxKind = 'folder';
            this.ctxMessage = null;
            this.ctxFolder = {
                id: el.dataset.folderId || '',
                name: el.dataset.name || '',
                system: el.dataset.system === '1',
                favorite: el.dataset.favorite === '1',
                unread: Number(el.dataset.unread || 0),
                total: Number(el.dataset.total || 0),
            };
            this.ctxSub = '';
            this.ctxX = event.clientX;
            this.ctxY = event.clientY;
            this.ctxOpen = true;
        },

        openMessageCtx(event) {
            const el = event.currentTarget as HTMLElement;
            let msg: CtxMessage | null = null;
            try {
                msg = JSON.parse(el.dataset.msg || 'null') as CtxMessage | null;
            } catch {
                msg = null;
            }
            if (!msg || !msg.id) {
                return;
            }
            this.ctxKind = 'message';
            this.ctxFolder = null;
            this.ctxMessage = {
                ...msg,
                keywords: Array.isArray(msg.keywords) ? msg.keywords : [],
            };
            this.ctxSub = '';
            this.ctxX = event.clientX;
            this.ctxY = event.clientY;
            this.ctxOpen = true;
        },

        closeCtx() {
            this.ctxOpen = false;
            this.ctxSub = '';
            this.ctxKind = '';
            this.ctxFolder = null;
            this.ctxMessage = null;
        },

        ctxStyle() {
            const pad = 8;
            const w = 280;
            const h = 420;
            let x = this.ctxX;
            let y = this.ctxY;
            if (x + w > window.innerWidth - pad) {
                x = Math.max(pad, window.innerWidth - w - pad);
            }
            if (y + h > window.innerHeight - pad) {
                y = Math.max(pad, window.innerHeight - h - pad);
            }
            return {
                position: 'fixed',
                left: `${x}px`,
                top: `${y}px`,
                zIndex: '80',
            };
        },

        toggleCtxSub(name) {
            this.ctxSub = this.ctxSub === name ? '' : name;
        },

        ctxSubIs(name) {
            return this.ctxSub === name;
        },

        swatchStyle(color) {
            return { background: color };
        },

        dotStyle(color) {
            return color ? { background: color } : {};
        },

        ctxFolderMeta() {
            const f = this.ctxFolder;
            if (!f) {
                return '';
            }
            return (this.ctxI18n.folderMeta || '')
                .replace('%total%', String(f.total))
                .replace('%unread%', String(f.unread));
        },

        ctxFolderFavoriteLabel() {
            return this.ctxFolder?.favorite ? this.ctxI18n.unfavorite : this.ctxI18n.favorite;
        },

        ctxFolderMarkReadDisabled() {
            return !(this.ctxFolder && this.ctxFolder.unread > 0);
        },

        ctxMessageSeenLabel() {
            return this.ctxMessage?.unread ? this.ctxI18n.markRead : this.ctxI18n.markUnread;
        },

        ctxMessagePinLabel() {
            return this.ctxMessage?.pinned ? this.ctxI18n.unpin : this.ctxI18n.pin;
        },

        ctxMessageFlagLabel() {
            return this.ctxMessage?.flagged ? this.ctxI18n.unflag : this.ctxI18n.flag;
        },

        ctxMessageHasKeyword(keyword) {
            const kws = this.ctxMessage?.keywords || [];
            return kws.includes(keyword);
        },

        async runAction(payload) {
            if (!this.actionUrl || !this.defaultAccountId) {
                return;
            }
            const csrf = (window as Window & { __GEREQ_CSRF__?: string }).__GEREQ_CSRF__ || '';
            try {
                const res = await fetch(this.actionUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrf,
                    },
                    body: JSON.stringify({
                        account_id: this.defaultAccountId,
                        ...payload,
                    }),
                });
                if (!res.ok) {
                    const err = (await res.json().catch(() => ({}))) as { message?: string };
                    window.alert(err.message || `Akce selhala (${res.status})`);
                    return;
                }
                const json = (await res.json()) as {
                    data?: { reload?: boolean; label?: CtxLabel };
                };
                if (json.data?.label) {
                    const label = json.data.label;
                    if (!this.ctxLabels.some((l) => l.keyword === label.keyword)) {
                        this.ctxLabels = [...this.ctxLabels, label];
                    }
                }
                if (json.data?.reload !== false) {
                    window.location.reload();
                }
            } catch (e) {
                window.alert(e instanceof Error ? e.message : 'Akce selhala');
            }
        },

        ctxFolderCreate() {
            const name = window.prompt('Název nové podsložky');
            if (!name || !this.ctxFolder) {
                return;
            }
            void this.runAction({ action: 'folder.create', folder_id: this.ctxFolder.id, name });
            this.closeCtx();
        },

        ctxFolderRename() {
            if (!this.ctxFolder || this.ctxFolder.system) {
                return;
            }
            const name = window.prompt('Nový název', this.ctxFolder.name);
            if (!name) {
                return;
            }
            void this.runAction({ action: 'folder.rename', folder_id: this.ctxFolder.id, name });
            this.closeCtx();
        },

        ctxFolderColor(color) {
            if (!this.ctxFolder) {
                return;
            }
            void this.runAction({ action: 'folder.color', folder_id: this.ctxFolder.id, color });
            this.closeCtx();
        },

        ctxFolderDelete() {
            if (!this.ctxFolder || this.ctxFolder.system) {
                return;
            }
            if (!window.confirm(`Smazat složku „${this.ctxFolder.name}“?`)) {
                return;
            }
            void this.runAction({ action: 'folder.delete', folder_id: this.ctxFolder.id });
            this.closeCtx();
        },

        ctxFolderEmpty() {
            if (!this.ctxFolder) {
                return;
            }
            if (!window.confirm(`Vyprázdnit složku „${this.ctxFolder.name}“?`)) {
                return;
            }
            void this.runAction({ action: 'folder.empty', folder_id: this.ctxFolder.id });
            this.closeCtx();
        },

        ctxFolderMarkRead() {
            if (!this.ctxFolder || this.ctxFolder.unread < 1) {
                return;
            }
            void this.runAction({ action: 'folder.mark_all_read', folder_id: this.ctxFolder.id });
            this.closeCtx();
        },

        ctxFolderFavorite() {
            if (!this.ctxFolder) {
                return;
            }
            void this.runAction({
                action: 'folder.favorite',
                folder_id: this.ctxFolder.id,
                favorite: !this.ctxFolder.favorite,
            });
            this.closeCtx();
        },

        ctxFolderOrder(direction) {
            if (!this.ctxFolder) {
                return;
            }
            void this.runAction({
                action: 'folder.order',
                folder_id: this.ctxFolder.id,
                direction,
            });
            this.closeCtx();
        },

        ctxFolderOpenWindow() {
            if (!this.ctxFolder) {
                return;
            }
            const url = new URL(window.location.href);
            url.searchParams.set('folder', this.ctxFolder.id);
            url.searchParams.delete('message');
            window.open(url.toString(), '_blank', 'noopener');
            this.closeCtx();
        },

        prefixSubject(prefix, subject) {
            const s = (subject || '').trim();
            const re = new RegExp(`^${prefix}\\s*:`, 'i');
            if (re.test(s)) {
                return s;
            }
            return `${prefix}: ${s || '(bez předmětu)'}`;
        },

        ctxMessageReply() {
            if (!this.canCompose || !this.ctxMessage) {
                return;
            }
            this.applyDraft({
                to: this.ctxMessage.from_address || '',
                subject: this.prefixSubject('Re', this.ctxMessage.subject || ''),
                body: '',
                cc: '',
                accountId: this.defaultAccountId,
                inReplyTo: this.ctxMessage.message_id || '',
                references: this.ctxMessage.references || '',
            });
            this.closeCtx();
            this.composeOpen = true;
            this.composeMinimized = false;
            this.syncUrlComposeFlag(true);
            this.$nextTick(() => this.syncComposeFields());
        },

        ctxMessageReplyAll() {
            this.ctxMessageReply();
        },

        ctxMessageForward() {
            if (!this.canCompose || !this.ctxMessage) {
                return;
            }
            this.applyDraft({
                to: '',
                subject: this.prefixSubject('Fwd', this.ctxMessage.subject || ''),
                body: '',
                cc: '',
                accountId: this.defaultAccountId,
                inReplyTo: '',
                references: '',
            });
            this.closeCtx();
            this.composeOpen = true;
            this.composeMinimized = false;
            this.syncUrlComposeFlag(true);
            this.$nextTick(() => this.syncComposeFields());
        },

        ctxMessageDelete() {
            if (!this.ctxMessage) {
                return;
            }
            void this.runAction({ action: 'message.delete', message_id: this.ctxMessage.id });
            this.closeCtx();
        },

        ctxMessageArchive() {
            if (!this.ctxMessage) {
                return;
            }
            void this.runAction({ action: 'message.archive', message_id: this.ctxMessage.id });
            this.closeCtx();
        },

        ctxMessageMove(folderId) {
            if (!this.ctxMessage) {
                return;
            }
            void this.runAction({
                action: 'message.move',
                message_id: this.ctxMessage.id,
                target_folder_id: folderId,
            });
            this.closeCtx();
        },

        ctxMessageCopy(folderId) {
            if (!this.ctxMessage) {
                return;
            }
            void this.runAction({
                action: 'message.copy',
                message_id: this.ctxMessage.id,
                target_folder_id: folderId,
            });
            this.closeCtx();
        },

        ctxMessageSeenToggle() {
            if (!this.ctxMessage) {
                return;
            }
            void this.runAction({
                action: 'message.seen',
                message_id: this.ctxMessage.id,
                seen: !!this.ctxMessage.unread,
            });
            this.closeCtx();
        },

        ctxMessagePin() {
            if (!this.ctxMessage) {
                return;
            }
            void this.runAction({
                action: 'message.pin',
                message_id: this.ctxMessage.id,
                pinned: !this.ctxMessage.pinned,
            });
            this.closeCtx();
        },

        ctxMessageLabel(keyword) {
            if (!this.ctxMessage) {
                return;
            }
            const enable = !this.ctxMessageHasKeyword(keyword);
            void this.runAction({
                action: 'message.keyword',
                message_id: this.ctxMessage.id,
                keyword,
                enable,
            });
            this.closeCtx();
        },

        ctxMessageLabelCreate() {
            if (!this.ctxMessage) {
                return;
            }
            const name = window.prompt('Název štítku (IMAP keyword)');
            if (!name) {
                return;
            }
            void (async () => {
                await this.runAction({ action: 'label.create', name, color: null });
                const created = this.ctxLabels.find((l) => l.name === name || l.keyword === name);
                if (created) {
                    await this.runAction({
                        action: 'message.keyword',
                        message_id: this.ctxMessage!.id,
                        keyword: created.keyword,
                        enable: true,
                    });
                }
                this.closeCtx();
            })();
        },

        ctxMessageFlag() {
            if (!this.ctxMessage) {
                return;
            }
            void this.runAction({
                action: 'message.flag',
                message_id: this.ctxMessage.id,
                flagged: !this.ctxMessage.flagged,
            });
            this.closeCtx();
        },

        isBulkSelected(id) {
            return !!id && !!this.selectedIds[id];
        },

        toggleBulk(id, event) {
            if (!id) {
                return;
            }
            const next = { ...this.selectedIds };
            const force = event && event.target instanceof HTMLInputElement
                ? event.target.checked
                : !next[id];
            if (force) {
                next[id] = true;
            } else {
                delete next[id];
            }
            this.selectedIds = next;
            this.syncBulkSelectAll();
        },

        toggleBulkFromEvent(event) {
            const input = event.target;
            if (!(input instanceof HTMLInputElement)) {
                return;
            }
            this.toggleBulk(input.value, event);
        },

        bulkCount() {
            return Object.keys(this.selectedIds).length;
        },

        bulkCountLabel() {
            return (this.bulkSelectedLabel || 'Vybráno: %count%').replace('%count%', String(this.bulkCount()));
        },

        bulkAllSelected() {
            return this.bulkIds.length > 0 && this.bulkIds.every((id) => this.selectedIds[id]);
        },

        bulkSomeSelected() {
            if (this.bulkIds.length === 0) {
                return false;
            }
            const n = this.bulkIds.filter((id) => this.selectedIds[id]).length;
            return n > 0 && n < this.bulkIds.length;
        },

        bulkToggleAll() {
            if (this.bulkAllSelected()) {
                this.selectedIds = {};
            } else {
                const next: Record<string, boolean> = {};
                for (const id of this.bulkIds) {
                    next[id] = true;
                }
                this.selectedIds = next;
            }
            this.syncBulkSelectAll();
        },

        syncBulkSelectAll() {
            const el = this.$refs.bulkSelectAll;
            if (!(el instanceof HTMLInputElement)) {
                return;
            }
            el.indeterminate = this.bulkSomeSelected();
        },

        bulkClear() {
            this.selectedIds = {};
            this.bulkMoveOpen = false;
            this.syncBulkSelectAll();
        },

        toggleBulkMove() {
            this.bulkMoveOpen = !this.bulkMoveOpen;
        },

        closeBulkMove() {
            this.bulkMoveOpen = false;
        },

        bulkRun(op, targetFolderId) {
            const ids = Object.keys(this.selectedIds);
            if (ids.length === 0) {
                return;
            }
            if (op === 'delete' && !window.confirm(`Odstranit ${ids.length} zpráv?`)) {
                return;
            }
            const payload: Record<string, unknown> = {
                action: 'message.bulk',
                op,
                message_ids: ids,
            };
            if (op === 'move' && targetFolderId) {
                payload.target_folder_id = targetFolderId;
            }
            this.bulkMoveOpen = false;
            void this.runAction(payload);
        },

        restoreCompose() {
            this.ensureComposeGeo();
            this.composeMinimized = false;
            this.composeOpen = true;
            this.$nextTick(() => {
                const input = (this.$el as HTMLElement).querySelector<HTMLInputElement>('[data-compose-focus]');
                input?.focus();
            });
        },

        closeCompose() {
            this.composeOpen = false;
            this.composeMinimized = false;
            this.draft = emptyDraft(this.defaultAccountId);
            this.syncUrlComposeFlag(false);
        },

        pillLabel() {
            const subject = this.draft.subject.trim();
            if (subject !== '') {
                return subject.length > 36 ? subject.slice(0, 34) + '…' : subject;
            }
            const to = this.draft.to.trim();
            if (to !== '') {
                return to.length > 36 ? to.slice(0, 34) + '…' : to;
            }
            return this.pillFallback;
        },

        syncUrlComposeFlag(open) {
            try {
                const url = new URL(window.location.href);
                if (open) {
                    url.searchParams.set('compose', '1');
                } else {
                    url.searchParams.delete('compose');
                    url.searchParams.delete('to');
                    url.searchParams.delete('cc');
                    url.searchParams.delete('subject');
                    url.searchParams.delete('body');
                    url.searchParams.delete('account_id');
                }
                window.history.replaceState({}, '', url.toString());
            } catch {
                // ignore
            }
        },
    }));
}
