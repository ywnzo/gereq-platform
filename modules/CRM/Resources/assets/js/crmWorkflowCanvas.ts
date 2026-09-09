import type Alpine from '@alpinejs/csp';
import {
    type WorkflowVarMeta,
    highlightTemplate,
    varsByPath,
} from './workflowVars';

type NodeData = Record<string, unknown>;

type WfNode = {
    id: string;
    type: string;
    x: number;
    y: number;
    data: NodeData;
};

type WfEdge = {
    id: string;
    from: string;
    to: string;
    from_port?: string;
};

type DragState = {
    id: string;
    ox: number;
    oy: number;
    el: HTMLElement;
    pointerId: number;
};

type InsertTarget = { nodeId: string; field: string; kind: 'text' | 'html' };

type CanvasState = {
    nodes: WfNode[];
    edges: WfEdge[];
    width: number;
    height: number;
    dirty: boolean;
    saving: boolean;
    i18n: Record<string, string>;
    variables: WorkflowVarMeta[];
    varFilter: string;
    insertTarget: InsertTarget | null;
    linkFrom: { id: string; port: string } | null;
    pointer: { x: number; y: number };
    drag: DragState | null;
    init(): void;
    addNode(type: string): void;
    removeNode(id: string): void;
    removeEdge(id: string): void;
    beginLink(id: string, port: string): void;
    finishLink(toId: string): void;
    paint(): void;
    edgePath(edge: WfEdge): string;
    draftPath(): string;
    nodeClass(node: WfNode): string;
    nodeLabel(node: WfNode): string;
    nodeTypeClass(type: string): string;
    triggerHasConditions(node: WfNode): boolean;
    triggerConditionsLabel(node: WfNode): string;
    clearTriggerConditions(id: string): void;
    setInsertTarget(target: InsertTarget | null): void;
    focusTpl(event: Event): void;
    filteredVariables(): WorkflowVarMeta[];
    variableGroups(): Array<{ group: string; items: WorkflowVarMeta[] }>;
    highlightField(value: unknown): string;
    insertVariable(path: string): void;
    save(): Promise<void>;
    nodeCenter(id: string): { x: number; y: number };
};

type AlpineMagics = {
    $el: HTMLElement;
    $refs: {
        wrap?: HTMLElement;
        edgesSvg?: SVGSVGElement;
        nodesRoot?: HTMLElement;
    };
    $nextTick: (fn: () => void) => void;
    $watch: (expr: string, cb: () => void) => void;
};

function uid(prefix: string): string {
    return prefix + '_' + Math.random().toString(36).slice(2, 9);
}

function csrfToken(root: HTMLElement): string {
    return root.dataset.csrf
        || (window as Window & { __GEREQ_CSRF__?: string }).__GEREQ_CSRF__
        || '';
}

function toast(message: string, tone: 'success' | 'error' | 'info' = 'info'): void {
    window.gereq?.toast?.(message, tone);
}

function plainGraph(nodes: WfNode[], edges: WfEdge[]): { nodes: WfNode[]; edges: WfEdge[] } {
    return JSON.parse(JSON.stringify({ nodes, edges })) as { nodes: WfNode[]; edges: WfEdge[] };
}

function asNumber(value: unknown, fallback = 0): number {
    const n = typeof value === 'number' ? value : Number(value);
    return Number.isFinite(n) ? n : fallback;
}

export function registerCrmWorkflowCanvas(alpine: typeof Alpine): void {
    alpine.data('crmWorkflowCanvas', (): CanvasState => ({
        nodes: [],
        edges: [],
        width: 1400,
        height: 800,
        dirty: false,
        saving: false,
        i18n: {},
        variables: [],
        varFilter: '',
        insertTarget: null,
        linkFrom: null,
        pointer: { x: 0, y: 0 },
        drag: null,

        init() {
            const self = this as CanvasState & AlpineMagics;
            const root = self.$el;

            try {
                this.i18n = JSON.parse(root.dataset.i18n || '{}') as Record<string, string>;
            } catch {
                this.i18n = {};
            }

            try {
                this.variables = JSON.parse(root.dataset.variables || '[]') as WorkflowVarMeta[];
            } catch {
                this.variables = [];
            }

            try {
                const graph = JSON.parse(root.dataset.graph || '{"nodes":[],"edges":[]}');
                this.nodes = Array.isArray(graph.nodes) ? graph.nodes.map((n: WfNode) => ({
                    ...n,
                    x: asNumber(n.x, 40),
                    y: asNumber(n.y, 40),
                    data: n.data && typeof n.data === 'object' ? { ...n.data } : {},
                })) : [];
                this.edges = Array.isArray(graph.edges) ? graph.edges : [];
            } catch {
                this.nodes = [];
                this.edges = [];
            }

            const canvasPoint = (event: PointerEvent) => {
                const wrap = self.$refs.wrap || root.querySelector('.crm-workflow__canvas-wrap');
                if (!wrap) return null;
                const rect = wrap.getBoundingClientRect();
                return {
                    x: event.clientX - rect.left + wrap.scrollLeft,
                    y: event.clientY - rect.top + wrap.scrollTop,
                };
            };

            const onPointerDown = (event: PointerEvent) => {
                const target = event.target as HTMLElement | null;
                if (!target) return;

                const port = target.closest('.crm-workflow__port') as HTMLElement | null;
                if (port) {
                    event.preventDefault();
                    event.stopPropagation();
                    const nodeId = port.getAttribute('data-node-id') || '';
                    const portName = port.getAttribute('data-port') || '';
                    if (portName === 'in') this.finishLink(nodeId);
                    else this.beginLink(nodeId, portName);
                    return;
                }

                const removeBtn = target.closest('[data-action="remove"]') as HTMLElement | null;
                if (removeBtn) {
                    event.preventDefault();
                    event.stopPropagation();
                    const nodeId = removeBtn.getAttribute('data-node-id') || '';
                    if (nodeId) this.removeNode(nodeId);
                    return;
                }

                const clearCond = target.closest('[data-action="clear-conditions"]') as HTMLElement | null;
                if (clearCond) {
                    event.preventDefault();
                    event.stopPropagation();
                    const nodeId = clearCond.getAttribute('data-node-id') || '';
                    if (nodeId) this.clearTriggerConditions(nodeId);
                    return;
                }

                if (target.closest('button,input,select,textarea,label,a,[contenteditable],.crm-wf-tpl,.g-editor,.crm-workflow__email-body,.crm-workflow__fields')) return;

                // Přetahování jen za hlavičku uzlu — tělo zůstane editovatelné.
                const handle = target.closest('.crm-workflow__node-handle') as HTMLElement | null;
                if (!handle) return;
                const nodeEl = handle.closest('.crm-workflow__node') as HTMLElement | null;
                if (!nodeEl) return;
                const nodeId = nodeEl.getAttribute('data-node-id') || '';
                const node = this.nodes.find((n) => n.id === nodeId);
                if (!node) return;

                const pt = canvasPoint(event);
                if (!pt) return;

                event.preventDefault();
                nodeEl.classList.add('is-dragging');
                try {
                    nodeEl.setPointerCapture(event.pointerId);
                } catch {
                    /* ignore */
                }
                this.drag = {
                    id: node.id,
                    ox: pt.x - asNumber(node.x),
                    oy: pt.y - asNumber(node.y),
                    el: nodeEl,
                    pointerId: event.pointerId,
                };
            };

            const onPointerMove = (event: PointerEvent) => {
                const pt = canvasPoint(event);
                if (!pt) return;
                this.pointer = pt;

                if (this.drag) {
                    event.preventDefault();
                    const node = this.nodes.find((n) => n.id === this.drag!.id);
                    if (!node) return;
                    const x = Math.max(0, Math.round(pt.x - this.drag.ox));
                    const y = Math.max(0, Math.round(pt.y - this.drag.oy));
                    node.x = x;
                    node.y = y;
                    this.drag.el.style.left = x + 'px';
                    this.drag.el.style.top = y + 'px';
                    this.dirty = true;
                    this.paint();
                    return;
                }

                if (this.linkFrom) this.paint();
            };

            const onPointerUp = () => {
                if (this.drag) {
                    this.drag.el.classList.remove('is-dragging');
                    try {
                        if (this.drag.el.hasPointerCapture(this.drag.pointerId)) {
                            this.drag.el.releasePointerCapture(this.drag.pointerId);
                        }
                    } catch {
                        /* ignore */
                    }
                }
                this.drag = null;
            };

            const onEdgeClick = (event: MouseEvent) => {
                const target = event.target as Element | null;
                if (!target || target.tagName.toLowerCase() !== 'path') return;
                const id = target.getAttribute('data-edge-id');
                if (id) this.removeEdge(id);
            };

            self.$nextTick(() => {
                const nodesRoot = self.$refs.nodesRoot || root.querySelector('.crm-workflow__nodes');
                const edgesSvg = self.$refs.edgesSvg || root.querySelector('.crm-workflow__edges');
                const wrap = self.$refs.wrap || root.querySelector('.crm-workflow__canvas-wrap');

                root.addEventListener('click', (event) => {
                    const target = event.target as HTMLElement | null;
                    if (!target) return;
                    const addBtn = target.closest('[data-wf-add]') as HTMLElement | null;
                    if (addBtn) {
                        const type = addBtn.getAttribute('data-wf-add') || '';
                        if (type) this.addNode(type);
                        return;
                    }
                    if (target.closest('[data-wf-save]')) {
                        void this.save();
                    }
                });

                if (nodesRoot) {
                    nodesRoot.style.width = this.width + 'px';
                    nodesRoot.style.height = this.height + 'px';
                    nodesRoot.addEventListener('pointerdown', onPointerDown);
                }
                if (edgesSvg) {
                    edgesSvg.setAttribute('viewBox', '0 0 ' + this.width + ' ' + this.height);
                    edgesSvg.setAttribute('width', String(this.width));
                    edgesSvg.setAttribute('height', String(this.height));
                    edgesSvg.addEventListener('click', onEdgeClick);
                }
                if (wrap) {
                    wrap.addEventListener('pointermove', onPointerMove, { passive: false });
                    wrap.addEventListener('pointerup', onPointerUp);
                    wrap.addEventListener('pointercancel', onPointerUp);
                }

                window.addEventListener('pointermove', onPointerMove, { passive: false });
                window.addEventListener('pointerup', onPointerUp);
                window.addEventListener('pointercancel', onPointerUp);

                this.paint();

                if (nodesRoot) {
                    const mo = new MutationObserver(() => this.paint());
                    mo.observe(nodesRoot, { childList: true, subtree: true });
                }
            });

            self.$watch('nodes', () => self.$nextTick(() => this.paint()));
            self.$watch('edges', () => this.paint());
        },

        addNode(type: string) {
            const data: NodeData =
                type === 'trigger.event' ? { event: 'deal.stage_changed', conditions: [] } :
                type === 'action.send_email' ? { to: '{{customer.email}}', subject: '', body: '' } :
                type === 'action.create_activity' ? { subject: 'Follow-up', activity_type: 'task' } :
                type === 'action.set_stage' ? { stage: 'qualified' } :
                type === 'logic.if' ? { field: 'deal.stage', op: 'eq', value: 'won' } :
                type === 'logic.delay' ? { seconds: 5 } :
                {};
            this.nodes.push({
                id: uid('node'),
                type,
                x: 120 + this.nodes.length * 40,
                y: 80 + this.nodes.length * 30,
                data,
            });
            this.dirty = true;
        },

        removeNode(id: string) {
            this.nodes = this.nodes.filter((n) => n.id !== id);
            this.edges = this.edges.filter((e) => e.from !== id && e.to !== id);
            this.dirty = true;
        },

        removeEdge(id: string) {
            this.edges = this.edges.filter((e) => e.id !== id);
            this.dirty = true;
        },

        beginLink(id: string, port: string) {
            this.linkFrom = { id, port };
            this.paint();
        },

        finishLink(toId: string) {
            if (!this.linkFrom || this.linkFrom.id === toId) {
                this.linkFrom = null;
                this.paint();
                return;
            }
            this.edges.push({
                id: uid('edge'),
                from: this.linkFrom.id,
                to: toId,
                from_port: this.linkFrom.port,
            });
            this.linkFrom = null;
            this.dirty = true;
            this.paint();
        },

        paint() {
            const self = this as CanvasState & AlpineMagics;
            const root = self.$el;
            const nodesRoot = self.$refs.nodesRoot || root.querySelector('.crm-workflow__nodes');
            const svg = self.$refs.edgesSvg || root.querySelector('.crm-workflow__edges');

            if (nodesRoot) {
                for (const node of this.nodes) {
                    const el = nodesRoot.querySelector('[data-node-id="' + CSS.escape(node.id) + '"].crm-workflow__node') as HTMLElement | null;
                    if (!el) continue;
                    el.style.left = asNumber(node.x) + 'px';
                    el.style.top = asNumber(node.y) + 'px';
                }
            }

            if (!svg) return;

            while (svg.firstChild) {
                svg.removeChild(svg.firstChild);
            }

            const ns = 'http://www.w3.org/2000/svg';
            for (const edge of this.edges) {
                const path = document.createElementNS(ns, 'path');
                path.setAttribute('class', 'crm-workflow__edge');
                path.setAttribute('d', this.edgePath(edge));
                path.setAttribute('data-edge-id', edge.id);
                svg.appendChild(path);
            }

            if (this.linkFrom) {
                const draft = document.createElementNS(ns, 'path');
                draft.setAttribute('class', 'crm-workflow__edge is-draft');
                draft.setAttribute('d', this.draftPath());
                svg.appendChild(draft);
            }
        },

        nodeCenter(id: string) {
            const n = this.nodes.find((x) => x.id === id);
            if (!n) return { x: 0, y: 0 };
            return { x: asNumber(n.x) + 110, y: asNumber(n.y) + 36 };
        },

        edgePath(edge: WfEdge) {
            const a = this.nodeCenter(edge.from);
            const b = this.nodeCenter(edge.to);
            const mx = (a.x + b.x) / 2;
            return 'M ' + a.x + ' ' + a.y + ' C ' + mx + ' ' + a.y + ', ' + mx + ' ' + b.y + ', ' + b.x + ' ' + b.y;
        },

        draftPath() {
            if (!this.linkFrom) return '';
            const a = this.nodeCenter(this.linkFrom.id);
            const mx = (a.x + this.pointer.x) / 2;
            return 'M ' + a.x + ' ' + a.y + ' C ' + mx + ' ' + a.y + ', ' + mx + ' ' + this.pointer.y + ', ' + this.pointer.x + ' ' + this.pointer.y;
        },

        nodeClass(node: WfNode) {
            const classes = ['is-' + this.nodeTypeClass(node.type)];
            if (node.type === 'action.send_email') classes.push('is-email');
            return classes.join(' ');
        },

        nodeLabel(node: WfNode) {
            const fallback: Record<string, string> = {
                'trigger.event': 'Trigger',
                'action.send_email': 'Send email',
                'action.create_activity': 'Create activity',
                'action.set_stage': 'Set stage',
                'logic.if': 'If',
                'logic.delay': 'Delay',
            };
            return this.i18n[node.type] || fallback[node.type] || node.type;
        },

        nodeTypeClass(type: string) {
            if (type.startsWith('trigger')) return 'trigger';
            if (type.startsWith('logic')) return 'logic';
            return 'action';
        },

        triggerHasConditions(node: WfNode) {
            const conditions = node.data?.conditions;
            return Array.isArray(conditions) && conditions.length > 0;
        },

        triggerConditionsLabel(node: WfNode) {
            const conditions = node.data?.conditions;
            if (!Array.isArray(conditions) || conditions.length === 0) return '';
            return conditions.map((rule) => {
                if (!rule || typeof rule !== 'object') return '?';
                const r = rule as { field?: unknown; op?: unknown; value?: unknown };
                return String(r.field ?? '?') + ' ' + String(r.op ?? 'eq') + ' ' + String(r.value ?? '');
            }).join(', ');
        },

        clearTriggerConditions(id: string) {
            const node = this.nodes.find((n) => n.id === id);
            if (!node || node.type !== 'trigger.event') return;
            node.data = { ...node.data, conditions: [] };
            this.dirty = true;
        },

        setInsertTarget(target: InsertTarget | null) {
            this.insertTarget = target;
        },

        focusTpl(event: Event) {
            const el = event.target as HTMLElement | null;
            if (!el) return;
            const nodeEl = el.closest('[data-node-id]') as HTMLElement | null;
            const nodeId = nodeEl?.getAttribute('data-node-id') || '';
            const field = el.getAttribute('data-field') || '';
            const kind = (el.getAttribute('data-kind') || 'text') as 'text' | 'html';
            if (!nodeId || !field) return;
            this.setInsertTarget({ nodeId, field, kind });
        },

        filteredVariables() {
            const q = this.varFilter.trim().toLowerCase();
            if (q === '') return this.variables;
            return this.variables.filter((v) =>
                v.path.toLowerCase().includes(q)
                || v.label.toLowerCase().includes(q)
                || v.description.toLowerCase().includes(q)
                || v.group.toLowerCase().includes(q)
            );
        },

        variableGroups() {
            const groups = new Map<string, WorkflowVarMeta[]>();
            for (const item of this.filteredVariables()) {
                const list = groups.get(item.group) || [];
                list.push(item);
                groups.set(item.group, list);
            }
            return Array.from(groups.entries()).map(([group, items]) => ({ group, items }));
        },

        highlightField(value: unknown) {
            return highlightTemplate(String(value ?? ''), varsByPath(this.variables));
        },

        insertVariable(path: string) {
            const token = '{{' + path + '}}';
            const target = this.insertTarget;
            if (!target) {
                toast(this.i18n['vars.pick_field'] || 'Nejdřív klikni do pole, kam chceš proměnnou vložit.', 'info');
                return;
            }
            const node = this.nodes.find((n) => n.id === target.nodeId);
            if (!node) return;

            if (target.kind === 'html') {
                const host = (this as CanvasState & AlpineMagics).$el.querySelector(
                    `.crm-workflow__email-body[data-node-id="${CSS.escape(target.nodeId)}"]`,
                );
                host?.dispatchEvent(new CustomEvent('crm-wf-insert', {
                    detail: { nodeId: target.nodeId, token },
                    bubbles: false,
                }));
                this.dirty = true;
                return;
            }

            const root = (this as CanvasState & AlpineMagics).$el;
            const tpl = root.querySelector(
                `.crm-wf-tpl[data-node-id="${CSS.escape(target.nodeId)}"][data-field="${CSS.escape(target.field)}"]`,
            ) as HTMLElement | null;
            if (tpl) {
                tpl.dispatchEvent(new CustomEvent('crm-wf-insert', {
                    detail: { token },
                    bubbles: false,
                }));
                this.dirty = true;
                return;
            }

            const current = String(node.data[target.field] ?? '');
            node.data = { ...node.data, [target.field]: current + token };
            this.dirty = true;
        },

        async save() {
            const root = (this as CanvasState & AlpineMagics).$el;
            const url = root.dataset.saveUrl || '';
            if (!url) {
                toast('Chybí URL pro uložení grafu.', 'error');
                return;
            }
            if (this.saving) return;
            this.saving = true;
            const saveBtn = root.querySelector('[data-wf-save]') as HTMLButtonElement | null;
            if (saveBtn) saveBtn.disabled = true;
            try {
                const res = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrfToken(root),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify(plainGraph(this.nodes, this.edges)),
                });
                if (!res.ok) {
                    const payload = await res.json().catch(() => null) as { error?: { message?: string } } | null;
                    throw new Error(payload?.error?.message || ('Uložení selhalo (' + res.status + ')'));
                }
                this.dirty = false;
                toast('Graf uložen.', 'success');
            } catch (err) {
                toast(err instanceof Error ? err.message : 'Uložení selhalo.', 'error');
            } finally {
                this.saving = false;
                if (saveBtn) saveBtn.disabled = false;
            }
        },
    }));
}
