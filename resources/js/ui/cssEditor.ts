import type Alpine from '@alpinejs/csp';
import { basicSetup } from 'codemirror';
import { EditorView, keymap } from '@codemirror/view';
import { Compartment, EditorState } from '@codemirror/state';
import { css } from '@codemirror/lang-css';
import { syntaxTree } from '@codemirror/language';
import { defaultKeymap, indentWithTab } from '@codemirror/commands';
import { linter, lintGutter, type Diagnostic } from '@codemirror/lint';
import { oneDark } from '@codemirror/theme-one-dark';
import { color } from '@uiw/codemirror-extensions-color';

type CssVar = { name: string; value: string; hint: string };

type CssEditorState = {
    vars: CssVar[];
    filter: string;
    filteredVars: CssVar[];
    init(): void | (() => void);
    destroy(): void;
    mountEditor(): void;
    insertVar(name: string): void;
    applyFilter(): void;
    swatchStyle(value: string): string;
    refresh(): void;
    view: EditorView | null;
    themeComp: Compartment | null;
    themeObserver: MutationObserver | null;
};

/** CodeMirror injects <style> tags; CSP requires the page nonce. */
function pageCspNonce(): string {
    const fromData = document.documentElement.dataset.cspNonce;
    if (fromData) {
        return fromData;
    }
    const script = document.querySelector('script[nonce]');
    if (script instanceof HTMLScriptElement) {
        return script.nonce || script.getAttribute('nonce') || '';
    }
    return script?.getAttribute('nonce') || '';
}

function adminIsDark(): boolean {
    return document.documentElement.classList.contains('dark')
        || document.documentElement.dataset.theme === 'gereq-dark';
}

const lightTheme = EditorView.theme({
    '&': {
        fontSize: '13px',
        border: '1px solid var(--border)',
        borderRadius: '12px',
        overflow: 'hidden',
        background: 'var(--surface)',
        color: 'var(--text)',
        width: '100%',
    },
    '.cm-scroller': {
        fontFamily: 'var(--gereq-font-mono, ui-monospace, monospace)',
        minHeight: '160px',
        maxHeight: '280px',
    },
    '.cm-content': {
        caretColor: 'var(--text)',
        color: 'var(--text)',
        padding: '10px 0',
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
}, { dark: false });

const dialogHeightTheme = EditorView.theme({
    '.cm-scroller': {
        minHeight: 'min(42vh, 22rem)',
        maxHeight: 'min(48vh, 26rem)',
    },
});

function cssSyntaxLinter() {
    return linter((view) => {
        const diagnostics: Diagnostic[] = [];
        syntaxTree(view.state).cursor().iterate((node) => {
            if (!node.type.isError) {
                return;
            }
            const from = node.from;
            const to = Math.max(node.to, from + 1);
            diagnostics.push({
                from,
                to,
                severity: 'error',
                message: 'Neplatná CSS syntaxe',
            });
        });
        return diagnostics;
    });
}

function themeExtension(dark: boolean) {
    return dark ? oneDark : lightTheme;
}

function isColorValue(value: string): boolean {
    const v = value.trim();
    return v.startsWith('#')
        || /^rgba?\(/i.test(v)
        || /^hsla?\(/i.test(v)
        || /^var\(--/i.test(v);
}

export function registerCssEditor(alpine: typeof Alpine): void {
    alpine.data('gereqCssEditor', (): Alpine.AlpineComponent<CssEditorState> => ({
        vars: [],
        filter: '',
        filteredVars: [],
        view: null,
        themeComp: null,
        themeObserver: null,

        init() {
            const root = this.$el;
            const raw = root.dataset.vars || '[]';
            try {
                this.vars = JSON.parse(raw) as CssVar[];
            } catch {
                this.vars = [];
            }
            this.applyFilter();
            this.$watch('filter', () => this.applyFilter());

            // Refs are ready after the first tick (also when dialog was display:none).
            this.$nextTick(() => this.mountEditor());

            const onRefresh = () => {
                this.mountEditor();
                this.refresh();
            };
            root.addEventListener('cms-css-refresh', onRefresh);

            return () => {
                root.removeEventListener('cms-css-refresh', onRefresh);
                this.destroy();
            };
        },

        mountEditor() {
            if (this.view) {
                this.refresh();
                return;
            }

            const input = this.$refs.input as HTMLTextAreaElement | undefined;
            const host = this.$refs.host;
            if (!input || !host) {
                return;
            }

            const sync = (view: EditorView) => {
                input.value = view.state.doc.toString();
                input.dispatchEvent(new Event('input', { bubbles: true }));
            };

            const nonce = pageCspNonce();
            const dialog = this.$el.classList.contains('css-editor--dialog');
            this.themeComp = new Compartment();
            const dark = adminIsDark();

            const view = new EditorView({
                parent: host,
                state: EditorState.create({
                    doc: input.value || '',
                    extensions: [
                        ...(nonce ? [EditorView.cspNonce.of(nonce)] : []),
                        basicSetup,
                        css(),
                        color,
                        lintGutter(),
                        cssSyntaxLinter(),
                        keymap.of([...defaultKeymap, indentWithTab]),
                        this.themeComp.of(themeExtension(dark)),
                        ...(dialog ? [dialogHeightTheme] : []),
                        EditorView.updateListener.of((update) => {
                            if (update.docChanged) {
                                sync(update.view);
                            }
                        }),
                    ],
                }),
            });
            this.view = view;

            sync(view);

            this.themeObserver = new MutationObserver(() => {
                if (!this.view || !this.themeComp) {
                    return;
                }
                this.view.dispatch({
                    effects: this.themeComp.reconfigure(themeExtension(adminIsDark())),
                });
            });
            this.themeObserver.observe(document.documentElement, {
                attributes: true,
                attributeFilter: ['class', 'data-theme'],
            });

            this.refresh();
        },

        destroy() {
            this.themeObserver?.disconnect();
            this.themeObserver = null;
            this.view?.destroy();
            this.view = null;
            this.themeComp = null;
        },

        refresh() {
            this.view?.requestMeasure();
            // Remeasure after layout (modal open / flex).
            requestAnimationFrame(() => this.view?.requestMeasure());
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
            const v = value.trim();
            if (v.startsWith('#')) {
                return `background:${v}`;
            }
            if (/^rgba?\(/i.test(v) || /^hsla?\(/i.test(v)) {
                return `background:${v}`;
            }
            if (/^var\(--/i.test(v)) {
                return `background:${v}`;
            }
            return 'background:transparent; border-style:dashed';
        },

        insertVar(name: string) {
            const view = this.view;
            if (!view) {
                this.mountEditor();
            }
            const editor = this.view;
            if (!editor) {
                return;
            }
            const item = this.vars.find((v) => v.name === name);
            const value = item?.value?.trim() ?? '';
            let snippet = `${name}: `;
            if (value !== '' && (isColorValue(value) || /^[\w.#%-]+$/i.test(value))) {
                snippet = `${name}: ${value};`;
            }
            const { from, to } = editor.state.selection.main;
            editor.dispatch({
                changes: { from, to, insert: snippet },
                selection: { anchor: from + snippet.length },
            });
            editor.focus();
            const input = this.$refs.input as HTMLTextAreaElement | undefined;
            if (input) {
                input.value = editor.state.doc.toString();
            }
        },
    }));
}
