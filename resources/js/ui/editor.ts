import type Alpine from '@alpinejs/csp';
import { Editor } from '@tiptap/core';
import Link from '@tiptap/extension-link';
import Placeholder from '@tiptap/extension-placeholder';
import TextAlign from '@tiptap/extension-text-align';
import StarterKit from '@tiptap/starter-kit';

type EditorMode = 'editor' | 'html';

/**
 * TipTap's Editor/ProseMirror must NOT live on Alpine reactive state —
 * Proxy wrapping causes "Applying a mismatched transaction".
 */
export function registerEditor(alpine: typeof Alpine): void {
    alpine.data('gereqEditor', () => {
        let editor: Editor | null = null;

        return {
            mode: 'editor' as EditorMode,
            editorVisible: true,
            htmlVisible: false,
            htmlSource: '',
            heading: 'paragraph',
            tabEditorClass: 'g-editor__mode-btn is-active',
            tabHtmlClass: 'g-editor__mode-btn',
            boldClass: 'g-editor__btn',
            italicClass: 'g-editor__btn',
            underlineClass: 'g-editor__btn',
            strikeClass: 'g-editor__btn',
            bulletClass: 'g-editor__btn',
            orderedClass: 'g-editor__btn',
            alignLeftClass: 'g-editor__btn is-active',
            alignCenterClass: 'g-editor__btn',
            alignRightClass: 'g-editor__btn',
            alignJustifyClass: 'g-editor__btn',

            init() {
                const root = this.$el as HTMLElement;
                const textarea = this.$refs.input as HTMLTextAreaElement;
                const mount = this.$refs.mount as HTMLElement;
                const placeholder = root.dataset.placeholder || 'Pište…';
                const initial = textarea.value || '';
                const self = this;

                this.htmlSource = initial;
                editor = new Editor({
                    element: mount,
                    content: initial,
                    injectCSS: false,
                    extensions: [
                        // StarterKit v3 already ships underline.
                        StarterKit.configure({
                            heading: { levels: [1, 2, 3] },
                            link: false,
                        }),
                        TextAlign.configure({
                            types: ['heading', 'paragraph'],
                            alignments: ['left', 'center', 'right', 'justify'],
                        }),
                        Link.configure({
                            openOnClick: false,
                            autolink: true,
                            protocols: ['http', 'https', 'mailto'],
                            HTMLAttributes: { rel: 'noopener noreferrer', target: '_blank' },
                        }),
                        Placeholder.configure({ placeholder }),
                    ],
                    editorProps: {
                        attributes: {
                            class: 'g-editor__content',
                        },
                    },
                    onUpdate: ({ editor: ed }) => {
                        self.syncFromEditor(ed);
                    },
                    onSelectionUpdate: ({ editor: ed }) => {
                        self.refreshActive(ed);
                    },
                    onTransaction: ({ editor: ed }) => {
                        self.refreshActive(ed);
                    },
                });

                this.refreshModeClasses();
                this.refreshActive(editor);
            },

            destroy() {
                editor?.destroy();
                editor = null;
            },

            refreshModeClasses() {
                this.editorVisible = this.mode === 'editor';
                this.htmlVisible = this.mode === 'html';
                this.tabEditorClass = this.mode === 'editor' ? 'g-editor__mode-btn is-active' : 'g-editor__mode-btn';
                this.tabHtmlClass = this.mode === 'html' ? 'g-editor__mode-btn is-active' : 'g-editor__mode-btn';
            },

            showEditor() {
                if (this.mode === 'editor') {
                    return;
                }

                const html = this.readHtmlSource();
                this.htmlSource = html;
                this.mode = 'editor';
                this.refreshModeClasses();
                this.applyHtmlToEditor(html);
            },

            showHtml() {
                if (this.mode === 'html') {
                    return;
                }

                const html = !editor || editor.isEmpty ? '' : editor.getHTML();
                this.htmlSource = html;
                this.writeTextarea(html);
                this.mode = 'html';
                this.refreshModeClasses();

                this.$nextTick(() => {
                    const htmlBox = this.$refs.html as HTMLTextAreaElement | undefined;
                    if (htmlBox) {
                        htmlBox.value = html;
                        htmlBox.focus();
                    }
                });
            },

            readHtmlSource(): string {
                const htmlBox = this.$refs.html as HTMLTextAreaElement | undefined;
                if (htmlBox && this.mode === 'html') {
                    return htmlBox.value;
                }
                return this.htmlSource;
            },

            applyHtmlToEditor(html: string) {
                if (!editor) {
                    return;
                }

                const content = html.trim() === '' ? '' : html;
                try {
                    editor.commands.setContent(content, { emitUpdate: false });
                } catch {
                    try {
                        editor.commands.setContent(
                            content === '' ? '' : `<p>${escapeHtml(content)}</p>`,
                            { emitUpdate: false },
                        );
                    } catch {
                        editor.commands.clearContent(false);
                    }
                }

                const next = editor.isEmpty ? '' : editor.getHTML();
                this.htmlSource = next;
                this.writeTextarea(next);
                this.refreshActive(editor);
                queueMicrotask(() => {
                    editor?.commands.focus('end');
                });
            },

            onHtmlInput(event: Event) {
                const target = event.target as HTMLTextAreaElement;
                this.htmlSource = target.value;
                this.writeTextarea(target.value);
            },

            onHeadingChange(event: Event) {
                const value = (event.target as HTMLSelectElement).value;
                this.heading = value;
                if (!editor) {
                    return;
                }
                if (value === 'paragraph') {
                    editor.chain().focus().setParagraph().run();
                    return;
                }
                const level = Number(value.replace('h', '')) as 1 | 2 | 3;
                editor.chain().focus().toggleHeading({ level }).run();
            },

            syncFromEditor(ed: Editor) {
                if (this.mode !== 'editor') {
                    return;
                }
                const html = ed.isEmpty ? '' : ed.getHTML();
                this.htmlSource = html;
                this.writeTextarea(html);
                this.refreshActive(ed);
            },

            writeTextarea(html: string) {
                const textarea = this.$refs.input as HTMLTextAreaElement | undefined;
                if (!textarea) {
                    return;
                }
                textarea.value = html;
                textarea.dispatchEvent(new Event('input', { bubbles: true }));
            },

            btnClass(active: boolean, extra = ''): string {
                return `g-editor__btn${extra ? ` ${extra}` : ''}${active ? ' is-active' : ''}`;
            },

            refreshActive(ed: Editor | null) {
                if (!ed) {
                    return;
                }
                this.boldClass = this.btnClass(ed.isActive('bold'));
                this.italicClass = this.btnClass(ed.isActive('italic'));
                this.underlineClass = this.btnClass(ed.isActive('underline'));
                this.strikeClass = this.btnClass(ed.isActive('strike'));
                this.bulletClass = this.btnClass(ed.isActive('bulletList'));
                this.orderedClass = this.btnClass(ed.isActive('orderedList'));

                if (ed.isActive('heading', { level: 1 })) {
                    this.heading = 'h1';
                } else if (ed.isActive('heading', { level: 2 })) {
                    this.heading = 'h2';
                } else if (ed.isActive('heading', { level: 3 })) {
                    this.heading = 'h3';
                } else {
                    this.heading = 'paragraph';
                }

                let align = 'left';
                if (ed.isActive({ textAlign: 'center' })) {
                    align = 'center';
                } else if (ed.isActive({ textAlign: 'right' })) {
                    align = 'right';
                } else if (ed.isActive({ textAlign: 'justify' })) {
                    align = 'justify';
                }
                this.alignLeftClass = this.btnClass(align === 'left');
                this.alignCenterClass = this.btnClass(align === 'center');
                this.alignRightClass = this.btnClass(align === 'right');
                this.alignJustifyClass = this.btnClass(align === 'justify');
            },

            bold() {
                editor?.chain().focus().toggleBold().run();
            },
            italic() {
                editor?.chain().focus().toggleItalic().run();
            },
            underline() {
                editor?.chain().focus().toggleUnderline().run();
            },
            strike() {
                editor?.chain().focus().toggleStrike().run();
            },
            bullet() {
                editor?.chain().focus().toggleBulletList().run();
            },
            ordered() {
                editor?.chain().focus().toggleOrderedList().run();
            },
            blockquote() {
                editor?.chain().focus().toggleBlockquote().run();
            },
            hr() {
                editor?.chain().focus().setHorizontalRule().run();
            },
            alignLeft() {
                editor?.chain().focus().setTextAlign('left').run();
            },
            alignCenter() {
                editor?.chain().focus().setTextAlign('center').run();
            },
            alignRight() {
                editor?.chain().focus().setTextAlign('right').run();
            },
            alignJustify() {
                editor?.chain().focus().setTextAlign('justify').run();
            },
            clearFormat() {
                editor?.chain().focus().unsetAllMarks().clearNodes().run();
            },
            undo() {
                editor?.chain().focus().undo().run();
            },
            redo() {
                editor?.chain().focus().redo().run();
            },
            toggleLink() {
                if (!editor) {
                    return;
                }
                const previous = editor.getAttributes('link').href as string | undefined;
                const next = window.prompt('URL odkazu', previous || 'https://');
                if (next === null) {
                    return;
                }
                if (next.trim() === '') {
                    editor.chain().focus().extendMarkRange('link').unsetLink().run();
                    return;
                }
                editor.chain().focus().extendMarkRange('link').setLink({ href: next.trim() }).run();
            },
        };
    });
}

function escapeHtml(value: string): string {
    return value
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');
}
