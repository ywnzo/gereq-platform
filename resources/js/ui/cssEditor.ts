import type Alpine from '@alpinejs/csp';
import { basicSetup } from 'codemirror';
import { EditorView, keymap } from '@codemirror/view';
import { EditorState } from '@codemirror/state';
import { css } from '@codemirror/lang-css';
import { defaultKeymap, indentWithTab } from '@codemirror/commands';

type CssVar = { name: string; value: string; hint: string };

type CssEditorState = {
    vars: CssVar[];
    filter: string;
    filteredVars: CssVar[];
    init(): void;
    insertVar(name: string): void;
    applyFilter(): void;
    swatchStyle(value: string): string;
    view: EditorView | null;
    $el: HTMLElement;
    $refs: { host?: HTMLElement; input?: HTMLTextAreaElement };
};

/** CodeMirror injects <style> tags; CSP requires the page nonce. */
function pageCspNonce(): string {
    const fromData = document.documentElement.dataset.cspNonce;
    if (fromData) {
        return fromData;
    }
    const script = document.querySelector('script[nonce]');
    return script?.nonce || script?.getAttribute('nonce') || '';
}

export function registerCssEditor(alpine: typeof Alpine): void {
    alpine.data('gereqCssEditor', (): CssEditorState => ({
        vars: [],
        filter: '',
        filteredVars: [],
        view: null,

        init() {
            const root = this.$el;
            const raw = root.dataset.vars || '[]';
            try {
                this.vars = JSON.parse(raw) as CssVar[];
            } catch {
                this.vars = [];
            }
            this.applyFilter();
            // @ts-expect-error Alpine magic
            this.$watch?.('filter', () => this.applyFilter());

            const input = this.$refs.input;
            const host = this.$refs.host;
            if (!input || !host) {
                return;
            }

            const sync = (view: EditorView) => {
                input.value = view.state.doc.toString();
                input.dispatchEvent(new Event('input', { bubbles: true }));
            };

            const nonce = pageCspNonce();

            this.view = new EditorView({
                parent: host,
                state: EditorState.create({
                    doc: input.value || '',
                    extensions: [
                        ...(nonce ? [EditorView.cspNonce.of(nonce)] : []),
                        basicSetup,
                        css(),
                        keymap.of([...defaultKeymap, indentWithTab]),
                        EditorView.updateListener.of((update) => {
                            if (update.docChanged) {
                                sync(update.view);
                            }
                        }),
                        EditorView.theme({
                            '&': {
                                fontSize: '13px',
                                border: '1px solid var(--border)',
                                borderRadius: '12px',
                                overflow: 'hidden',
                                background: 'var(--surface)',
                            },
                            '.cm-scroller': {
                                fontFamily: 'var(--gereq-font-mono, ui-monospace, monospace)',
                                minHeight: '220px',
                                maxHeight: '420px',
                            },
                            '.cm-content': {
                                caretColor: 'var(--text)',
                                color: 'var(--text)',
                                padding: '12px 0',
                            },
                            '.cm-gutters': {
                                background: 'var(--surface-2)',
                                color: 'var(--text-soft)',
                                borderRight: '1px solid var(--border-soft)',
                            },
                            '&.cm-focused': {
                                outline: '2px solid color-mix(in srgb, var(--primary) 45%, transparent)',
                                outlineOffset: '1px',
                            },
                        }),
                    ],
                }),
            });

            // Keep hidden textarea in sync for form submit.
            sync(this.view);
        },

        applyFilter() {
            const q = this.filter.trim().toLowerCase();
            this.filteredVars = q === ''
                ? this.vars
                : this.vars.filter((v) =>
                    v.name.toLowerCase().includes(q) || v.hint.toLowerCase().includes(q),
                );
        },

        swatchStyle(value: string): string {
            return value.startsWith('#') ? `background:${value}` : 'background:transparent';
        },

        insertVar(name: string) {
            const view = this.view;
            if (!view) {
                return;
            }
            const snippet = `${name}: `;
            const { from, to } = view.state.selection.main;
            view.dispatch({
                changes: { from, to, insert: snippet },
                selection: { anchor: from + snippet.length },
            });
            view.focus();
            const input = this.$refs.input;
            if (input) {
                input.value = view.state.doc.toString();
            }
        },
    }));
}
