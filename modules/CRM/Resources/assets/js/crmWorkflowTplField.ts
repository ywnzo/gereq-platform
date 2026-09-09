import type Alpine from '@alpinejs/csp';
import {
    type WorkflowVarMeta,
    highlightTemplate,
    varsByPath,
} from './workflowVars';

type CanvasLike = {
    nodes: Array<{ id: string; data: Record<string, unknown> }>;
    variables: WorkflowVarMeta[];
    dirty: boolean;
    setInsertTarget: (target: { nodeId: string; field: string; kind: 'text' | 'html' } | null) => void;
};

function findCanvas(el: HTMLElement): CanvasLike | null {
    let cur: HTMLElement | null = el;
    while (cur) {
        const stack = (cur as HTMLElement & { _x_dataStack?: unknown[] })._x_dataStack;
        if (Array.isArray(stack) && stack[0] && typeof stack[0] === 'object') {
            const data = stack[0] as CanvasLike;
            if (Array.isArray(data.nodes) && Array.isArray(data.variables)) {
                return data;
            }
        }
        cur = cur.parentElement;
    }
    return null;
}

function insertIntoInput(input: HTMLInputElement, token: string): void {
    const start = input.selectionStart ?? input.value.length;
    const end = input.selectionEnd ?? input.value.length;
    const next = input.value.slice(0, start) + token + input.value.slice(end);
    input.value = next;
    const caret = start + token.length;
    input.setSelectionRange(caret, caret);
}

/**
 * Reliable template field: native <input> while editing, highlighted view when idle.
 * Avoids contenteditable + Alpine remount fights on the canvas.
 */
export function registerCrmWorkflowTplField(alpine: typeof Alpine): void {
    alpine.data('crmWfTplField', () => ({
        init() {
            const root = this.$el as HTMLElement;
            const nodeId = root.dataset.nodeId
                || root.getAttribute('data-node-id')
                || root.closest('.crm-workflow__node')?.getAttribute('data-node-id')
                || '';
            const field = root.dataset.field || root.getAttribute('data-field') || '';
            const placeholder = root.dataset.placeholder || '';
            const canvas = findCanvas(root);

            root.replaceChildren();
            root.classList.add('crm-wf-tpl');
            root.removeAttribute('contenteditable');

            const view = document.createElement('div');
            view.className = 'crm-wf-tpl__view';
            view.tabIndex = 0;

            const input = document.createElement('input');
            input.type = 'text';
            input.className = 'crm-wf-tpl__input';
            input.spellcheck = false;
            input.autocomplete = 'off';
            input.placeholder = placeholder;
            input.setAttribute('data-field', field);
            input.setAttribute('data-kind', 'text');

            root.append(view, input);

            let editing = false;

            const readValue = (): string => {
                const node = canvas?.nodes.find((n) => n.id === nodeId);
                return String(node?.data?.[field] ?? '');
            };

            const writeValue = (value: string): void => {
                if (!canvas) return;
                const node = canvas.nodes.find((n) => n.id === nodeId);
                if (!node) return;
                if (String(node.data[field] ?? '') === value) return;
                // Mutate in place — replacing the whole data object remounts Alpine children.
                node.data[field] = value;
                canvas.dirty = true;
            };

            const renderView = (): void => {
                const value = readValue();
                root.classList.toggle('is-empty', value === '');
                if (value === '') {
                    view.textContent = '';
                    view.dataset.placeholder = placeholder;
                } else {
                    delete view.dataset.placeholder;
                    view.innerHTML = highlightTemplate(value, varsByPath(canvas?.variables ?? []));
                }
            };

            const showView = (): void => {
                editing = false;
                root.classList.remove('is-editing');
                renderView();
            };

            const showEdit = (): void => {
                editing = true;
                root.classList.add('is-editing');
                input.value = readValue();
                canvas?.setInsertTarget({ nodeId, field, kind: 'text' });
                queueMicrotask(() => {
                    input.focus();
                    const len = input.value.length;
                    input.setSelectionRange(len, len);
                });
            };

            const commit = (): void => {
                writeValue(input.value);
            };

            renderView();

            view.addEventListener('pointerdown', (event) => {
                event.stopPropagation();
            });
            view.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                showEdit();
            });
            view.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    showEdit();
                }
            });

            input.addEventListener('pointerdown', (event) => {
                event.stopPropagation();
            });
            input.addEventListener('focus', () => {
                canvas?.setInsertTarget({ nodeId, field, kind: 'text' });
            });
            input.addEventListener('input', () => {
                // Keep model in sync without remounting view while typing.
                writeValue(input.value);
            });
            input.addEventListener('keydown', (event) => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    input.blur();
                }
                if (event.key === 'Escape') {
                    event.preventDefault();
                    input.value = readValue();
                    input.blur();
                }
            });
            input.addEventListener('blur', () => {
                commit();
                showView();
            });

            root.addEventListener('crm-wf-insert', ((event: Event) => {
                const detail = (event as CustomEvent<{ token?: string }>).detail;
                if (!detail?.token) return;
                if (!editing) showEdit();
                queueMicrotask(() => {
                    insertIntoInput(input, detail.token!);
                    writeValue(input.value);
                    input.focus();
                });
            }) as EventListener);

            root.addEventListener('crm-wf-refresh', (() => {
                if (!editing) renderView();
                else input.value = readValue();
            }) as EventListener);
        },
    }));
}
