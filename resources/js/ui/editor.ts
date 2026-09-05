import type Alpine from '@alpinejs/csp';
import { Editor } from '@tiptap/core';
import Link from '@tiptap/extension-link';
import Placeholder from '@tiptap/extension-placeholder';
import TextAlign from '@tiptap/extension-text-align';
import Underline from '@tiptap/extension-underline';
import StarterKit from '@tiptap/starter-kit';

type EditorMode = 'editor' | 'html';

export function registerEditor(alpine: typeof Alpine): void {
    alpine.data('gereqEditor', () => ({
        editor: null as Editor | null,
        mode: 'editor' as EditorMode,
        editorVisible: true,
        htmlVisible: false,
        htmlSource: '',
        heading: 'paragraph',
        isBold: false,
        isItalic: false,
        isUnderline: false,
        isStrike: false,
        isBullet: false,
        isOrdered: false,
        align: 'left',
        tabEditorClass: 'g-editor__tab is-active',
        tabHtmlClass: 'g-editor__tab',
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

            this.htmlSource = initial;
            this.editor = new Editor({
                element: mount,
                content: initial,
                injectCSS: false,
                extensions: [
                    StarterKit.configure({
                        heading: { levels: [1, 2, 3] },
                        link: false,
                    }),
                    Underline,
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
                onUpdate: ({ editor }) => {
                    this.syncFromEditor(editor);
                },
                onSelectionUpdate: ({ editor }) => {
                    this.refreshActive(editor);
                },
                onTransaction: ({ editor }) => {
                    this.refreshActive(editor);
                },
            });

            this.refreshModeClasses();
            this.refreshActive(this.editor);
        },

        destroy() {
            this.editor?.destroy();
            this.editor = null;
        },

        refreshModeClasses() {
            this.editorVisible = this.mode === 'editor';
            this.htmlVisible = this.mode === 'html';
            this.tabEditorClass = this.mode === 'editor' ? 'g-editor__tab is-active' : 'g-editor__tab';
            this.tabHtmlClass = this.mode === 'html' ? 'g-editor__tab is-active' : 'g-editor__tab';
        },

        showEditor() {
            if (this.mode === 'editor') {
                return;
            }
            const html = this.htmlSource;
            this.editor?.commands.setContent(html || '', { emitUpdate: false });
            this.writeTextarea(this.editor?.isEmpty ? '' : this.editor?.getHTML() || html);
            this.mode = 'editor';
            this.refreshModeClasses();
            this.refreshActive(this.editor);
        },

        showHtml() {
            if (this.mode === 'html') {
                return;
            }
            this.htmlSource = this.editor?.isEmpty ? '' : this.editor?.getHTML() || '';
            this.mode = 'html';
            this.refreshModeClasses();
        },

        onHtmlInput(event: Event) {
            const target = event.target as HTMLTextAreaElement;
            this.htmlSource = target.value;
            this.writeTextarea(target.value);
        },

        onHeadingChange(event: Event) {
            const value = (event.target as HTMLSelectElement).value;
            this.heading = value;
            const editor = this.editor;
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

        syncFromEditor(editor: Editor) {
            const html = editor.isEmpty ? '' : editor.getHTML();
            this.htmlSource = html;
            this.writeTextarea(html);
            this.refreshActive(editor);
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

        refreshActive(editor: Editor | null) {
            if (!editor) {
                return;
            }
            this.isBold = editor.isActive('bold');
            this.isItalic = editor.isActive('italic');
            this.isUnderline = editor.isActive('underline');
            this.isStrike = editor.isActive('strike');
            this.isBullet = editor.isActive('bulletList');
            this.isOrdered = editor.isActive('orderedList');
            this.boldClass = this.btnClass(this.isBold);
            this.italicClass = this.btnClass(this.isItalic);
            this.underlineClass = this.btnClass(this.isUnderline);
            this.strikeClass = this.btnClass(this.isStrike);
            this.bulletClass = this.btnClass(this.isBullet);
            this.orderedClass = this.btnClass(this.isOrdered);

            if (editor.isActive('heading', { level: 1 })) {
                this.heading = 'h1';
            } else if (editor.isActive('heading', { level: 2 })) {
                this.heading = 'h2';
            } else if (editor.isActive('heading', { level: 3 })) {
                this.heading = 'h3';
            } else {
                this.heading = 'paragraph';
            }

            if (editor.isActive({ textAlign: 'center' })) {
                this.align = 'center';
            } else if (editor.isActive({ textAlign: 'right' })) {
                this.align = 'right';
            } else if (editor.isActive({ textAlign: 'justify' })) {
                this.align = 'justify';
            } else {
                this.align = 'left';
            }
            this.alignLeftClass = this.btnClass(this.align === 'left');
            this.alignCenterClass = this.btnClass(this.align === 'center');
            this.alignRightClass = this.btnClass(this.align === 'right');
            this.alignJustifyClass = this.btnClass(this.align === 'justify');
        },

        bold() {
            this.editor?.chain().focus().toggleBold().run();
        },
        italic() {
            this.editor?.chain().focus().toggleItalic().run();
        },
        underline() {
            this.editor?.chain().focus().toggleUnderline().run();
        },
        strike() {
            this.editor?.chain().focus().toggleStrike().run();
        },
        bullet() {
            this.editor?.chain().focus().toggleBulletList().run();
        },
        ordered() {
            this.editor?.chain().focus().toggleOrderedList().run();
        },
        blockquote() {
            this.editor?.chain().focus().toggleBlockquote().run();
        },
        hr() {
            this.editor?.chain().focus().setHorizontalRule().run();
        },
        alignLeft() {
            this.editor?.chain().focus().setTextAlign('left').run();
        },
        alignCenter() {
            this.editor?.chain().focus().setTextAlign('center').run();
        },
        alignRight() {
            this.editor?.chain().focus().setTextAlign('right').run();
        },
        alignJustify() {
            this.editor?.chain().focus().setTextAlign('justify').run();
        },
        clearFormat() {
            this.editor?.chain().focus().unsetAllMarks().clearNodes().run();
        },
        undo() {
            this.editor?.chain().focus().undo().run();
        },
        redo() {
            this.editor?.chain().focus().redo().run();
        },
        toggleLink() {
            const editor = this.editor;
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
    }));
}
