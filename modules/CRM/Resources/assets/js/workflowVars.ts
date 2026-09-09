import { Extension } from '@tiptap/core';
import { Plugin, PluginKey } from '@tiptap/pm/state';
import { Decoration, DecorationSet } from '@tiptap/pm/view';

export type WorkflowVarMeta = {
    path: string;
    label: string;
    description: string;
    group: string;
    example: string;
    token: string;
    module: string;
};

const varDecoKey = new PluginKey('workflowVarDecorations');

export function workflowVarDecorations(
    getVars: () => Record<string, WorkflowVarMeta>,
): Extension {
    return Extension.create({
        name: 'workflowVarDecorations',
        addProseMirrorPlugins() {
            return [
                new Plugin({
                    key: varDecoKey,
                    props: {
                        decorations(state) {
                            const vars = getVars();
                            const list: ReturnType<typeof Decoration.inline>[] = [];
                            const re = /\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/g;
                            state.doc.descendants((node, pos) => {
                                if (!node.isText || !node.text) {
                                    return;
                                }
                                re.lastIndex = 0;
                                let match: RegExpExecArray | null;
                                while ((match = re.exec(node.text)) !== null) {
                                    const from = pos + match.index;
                                    const to = from + match[0].length;
                                    const path = match[1];
                                    const meta = vars[path];
                                    const title = meta?.description || meta?.label || path;
                                    list.push(
                                        Decoration.inline(from, to, {
                                            class: 'crm-wf-var',
                                            title,
                                            'data-path': path,
                                            style: 'font-weight:700',
                                        }),
                                    );
                                }
                            });
                            return DecorationSet.create(state.doc, list);
                        },
                    },
                }),
            ];
        },
    });
}

export function varsByPath(list: WorkflowVarMeta[]): Record<string, WorkflowVarMeta> {
    const map: Record<string, WorkflowVarMeta> = {};
    for (const item of list) {
        map[item.path] = item;
    }
    return map;
}

export function escapeHtml(value: string): string {
    return value
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');
}

export function highlightTemplate(text: string, vars: Record<string, WorkflowVarMeta>): string {
    const escaped = escapeHtml(text ?? '');
    return escaped.replace(/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/g, (_full, path: string) => {
        const meta = vars[path];
        const title = escapeHtml(meta?.description || meta?.label || path);
        return `<b class="crm-wf-var" title="${title}" data-path="${escapeHtml(path)}">{{${escapeHtml(path)}}}</b>`;
    });
}
