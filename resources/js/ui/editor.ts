import type Alpine from '@alpinejs/csp';
import { Editor } from '@tiptap/core';
import Placeholder from '@tiptap/extension-placeholder';
import StarterKit from '@tiptap/starter-kit';

export function registerEditor(alpine: typeof Alpine): void {
    alpine.data('gereqEditor', () => ({
        editor: null as Editor | null,

        init() {
            const root = this.$el as HTMLElement;
            const textarea = this.$refs.input as HTMLTextAreaElement;
            const mount = this.$refs.mount as HTMLElement;
            const placeholder = root.dataset.placeholder || 'Pište…';

            this.editor = new Editor({
                element: mount,
                content: textarea.value || '',
                injectCSS: false,
                extensions: [
                    StarterKit.configure({
                        heading: { levels: [2, 3] },
                        link: {
                            openOnClick: false,
                            autolink: true,
                            protocols: ['http', 'https', 'mailto'],
                            HTMLAttributes: { rel: 'noopener noreferrer', target: '_blank' },
                        },
                    }),
                    Placeholder.configure({ placeholder }),
                ],
                editorProps: {
                    attributes: {
                        class: 'g-editor__content',
                    },
                },
                onUpdate: ({ editor }) => {
                    textarea.value = editor.isEmpty ? '' : editor.getHTML();
                    textarea.dispatchEvent(new Event('input', { bubbles: true }));
                },
            });
        },

        destroy() {
            this.editor?.destroy();
            this.editor = null;
        },

        bold() {
            this.editor?.chain().focus().toggleBold().run();
        },

        italic() {
            this.editor?.chain().focus().toggleItalic().run();
        },

        bullet() {
            this.editor?.chain().focus().toggleBulletList().run();
        },

        ordered() {
            this.editor?.chain().focus().toggleOrderedList().run();
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
