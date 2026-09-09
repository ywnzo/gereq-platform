import type Alpine from '@alpinejs/csp';

type CrmBoardState = {
    canManage: boolean;
    dragId: string | null;
    dragFromStage: string | null;
    dropStage: string | null;
    justDragged: boolean;
    busy: boolean;
    init(): void;
    canDrag(): boolean;
    cardClass(dealId: string): string;
    colClass(stage: string): string;
    onDragStart(event: DragEvent, dealId: string, fromStage: string): void;
    onDragEnd(): void;
    onDragOver(event: DragEvent, stage: string): void;
    onDragLeave(): void;
    onDrop(event: DragEvent, stage: string): Promise<void>;
    guardNav(event: MouseEvent): void;
    moveCardDom(dealId: string, toStage: string): { card: HTMLElement; fromCol: HTMLElement } | null;
    restoreCard(card: HTMLElement, fromCol: HTMLElement): void;
    updateColCounts(): void;
    postStage(url: string, stage: string): Promise<boolean>;
};

function csrfToken(): string {
    return (window as Window & { __GEREQ_CSRF__?: string }).__GEREQ_CSRF__ || '';
}

function toast(message: string, tone: 'success' | 'error' | 'info' = 'error'): void {
    window.gereq?.toast?.(message, tone);
}

export function registerCrmBoard(alpine: typeof Alpine): void {
    alpine.data('crmBoard', (): CrmBoardState => ({
        canManage: false,
        dragId: null,
        dragFromStage: null,
        dropStage: null,
        justDragged: false,
        busy: false,

        init() {
            const el = this.$el as HTMLElement;
            this.canManage = el.dataset.canManage === '1';
        },

        canDrag() {
            return this.canManage && !this.busy;
        },

        cardClass(dealId: string) {
            return this.dragId === dealId ? 'is-dragging' : '';
        },

        colClass(stage: string) {
            return this.dropStage === stage ? 'is-drop-target' : '';
        },

        onDragStart(event: DragEvent, dealId: string, fromStage: string) {
            if (!this.canDrag()) {
                event.preventDefault();
                return;
            }
            this.dragId = dealId;
            this.dragFromStage = fromStage;
            if (event.dataTransfer) {
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', dealId);
            }
        },

        onDragEnd() {
            this.dragId = null;
            this.dragFromStage = null;
            this.dropStage = null;
            this.justDragged = true;
            window.setTimeout(() => {
                this.justDragged = false;
            }, 150);
        },

        onDragOver(event: DragEvent, stage: string) {
            if (!this.dragId) {
                return;
            }
            event.preventDefault();
            if (event.dataTransfer) {
                event.dataTransfer.dropEffect = 'move';
            }
            this.dropStage = stage;
        },

        onDragLeave() {
            this.dropStage = null;
        },

        guardNav(event: MouseEvent) {
            if (this.justDragged) {
                event.preventDefault();
                event.stopPropagation();
            }
        },

        async onDrop(event: DragEvent, stage: string) {
            event.preventDefault();
            const dealId = this.dragId || event.dataTransfer?.getData('text/plain') || '';
            const fromStage = this.dragFromStage;
            this.dropStage = null;
            this.dragId = null;
            this.dragFromStage = null;

            if (!dealId || !this.canManage || this.busy) {
                return;
            }
            if (fromStage === stage) {
                return;
            }

            const cardEl = (this.$el as HTMLElement).querySelector(
                `[data-deal-id="${CSS.escape(dealId)}"]`,
            ) as HTMLElement | null;
            const stageUrl = cardEl?.dataset.stageUrl || '';
            if (!stageUrl || !cardEl) {
                return;
            }

            const moved = this.moveCardDom(dealId, stage);
            if (!moved) {
                return;
            }

            this.busy = true;
            const ok = await this.postStage(stageUrl, stage);
            this.busy = false;

            if (!ok) {
                this.restoreCard(moved.card, moved.fromCol);
                toast('Fázi se nepodařilo změnit.', 'error');
                return;
            }

            this.updateColCounts();
        },

        moveCardDom(dealId: string, toStage: string) {
            const root = this.$el as HTMLElement;
            const card = root.querySelector(`[data-deal-id="${CSS.escape(dealId)}"]`) as HTMLElement | null;
            const toCol = root.querySelector(`[data-stage-cards="${CSS.escape(toStage)}"]`) as HTMLElement | null;
            if (!card || !toCol) {
                return null;
            }
            const fromCol = card.parentElement as HTMLElement;
            const empty = toCol.querySelector('.crm-board__empty');
            empty?.remove();
            toCol.prepend(card);
            card.dataset.stage = toStage;
            if (fromCol && fromCol.children.length === 0) {
                const p = document.createElement('p');
                p.className = 'crm-board__empty muted';
                p.textContent = root.dataset.emptyLabel || '—';
                fromCol.appendChild(p);
            }
            return { card, fromCol };
        },

        restoreCard(card: HTMLElement, fromCol: HTMLElement) {
            const empty = fromCol.querySelector('.crm-board__empty');
            empty?.remove();
            fromCol.prepend(card);
            this.updateColCounts();
        },

        updateColCounts() {
            const root = this.$el as HTMLElement;
            root.querySelectorAll<HTMLElement>('[data-stage]').forEach((col) => {
                const stage = col.dataset.stage || '';
                const cards = root.querySelector(`[data-stage-cards="${CSS.escape(stage)}"]`);
                const countEl = col.querySelector('[data-stage-count]');
                if (!cards || !countEl) {
                    return;
                }
                countEl.textContent = String(cards.querySelectorAll('[data-deal-id]').length);
            });
        },

        async postStage(url: string, stage: string) {
            try {
                const body = new URLSearchParams();
                body.set('_csrf', csrfToken());
                body.set('stage', stage);
                body.set('_redirect', 'index');
                const res = await fetch(url, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    body: body.toString(),
                    credentials: 'same-origin',
                    redirect: 'manual',
                });
                if (res.status >= 200 && res.status < 400) {
                    return true;
                }
                return false;
            } catch {
                return false;
            }
        },
    }));
}
