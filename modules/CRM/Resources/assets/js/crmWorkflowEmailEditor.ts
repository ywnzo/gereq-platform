import type Alpine from '@alpinejs/csp';

type CanvasLike = {
    nodes: Array<{ id: string; data: Record<string, unknown> }>;
    dirty: boolean;
    setInsertTarget: (target: { nodeId: string; field: string; kind: 'text' | 'html' } | null) => void;
};

function findCanvas(el: HTMLElement): CanvasLike | null {
    let cur: HTMLElement | null = el;
    while (cur) {
        const stack = (cur as HTMLElement & { _x_dataStack?: unknown[] })._x_dataStack;
        if (Array.isArray(stack) && stack[0] && typeof stack[0] === 'object') {
            const data = stack[0] as CanvasLike;
            if (Array.isArray(data.nodes) && typeof data.setInsertTarget === 'function') {
                return data;
            }
        }
        cur = cur.parentElement;
    }
    return null;
}

/**
 * Bridge: seeds/syncs our shared gereqEditor into a workflow send_email node.
 */
export function registerCrmWorkflowEmailEditor(alpine: typeof Alpine): void {
    alpine.data('crmWfGereqEmail', () => ({
        init() {
            const root = this.$el as HTMLElement;
            const canvas = findCanvas(root);
            const nodeId = root.dataset.nodeId
                || root.getAttribute('data-node-id')
                || root.closest('.crm-workflow__node')?.getAttribute('data-node-id')
                || '';
            const node = canvas?.nodes.find((n) => n.id === nodeId);
            const textarea = root.querySelector('[data-wf-body-input], .g-editor__input') as HTMLTextAreaElement | null;

            if (textarea && node) {
                textarea.value = String(node.data.body ?? node.data.body_html ?? '');
            }

            const syncFromTextarea = () => {
                if (!canvas || !node || !textarea) {
                    return;
                }
                const html = textarea.value;
                node.data = { ...node.data, body: html, body_html: html };
                canvas.dirty = true;
            };

            root.addEventListener('input', (event) => {
                const target = event.target as HTMLElement | null;
                if (!target) {
                    return;
                }
                if (
                    target.matches('[data-wf-body-input], .g-editor__input, .g-editor__html')
                    || target.closest('.g-editor')
                ) {
                    // TipTap writes hidden textarea asynchronously via writeTextarea.
                    queueMicrotask(syncFromTextarea);
                }
            }, true);

            root.addEventListener('focusin', () => {
                canvas?.setInsertTarget({ nodeId, field: 'body', kind: 'html' });
            });

            root.addEventListener('crm-wf-insert', ((event: Event) => {
                const detail = (event as CustomEvent<{ token?: string }>).detail;
                if (!detail?.token) {
                    return;
                }
                const editorRoot = root.querySelector('.g-editor');
                editorRoot?.dispatchEvent(new CustomEvent('gereq-editor-insert', {
                    detail: { text: detail.token },
                }));
                queueMicrotask(syncFromTextarea);
            }) as EventListener);
        },
    }));
}
