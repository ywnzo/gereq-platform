import { Node, mergeAttributes } from '@tiptap/core';

/** Minimal video block for chat composer embeds. */
export const ChatVideo = Node.create({
    name: 'chatVideo',
    group: 'block',
    atom: true,
    selectable: true,
    draggable: true,

    addAttributes() {
        return {
            src: { default: null },
            controls: { default: true },
            title: { default: null },
        };
    },

    parseHTML() {
        return [{ tag: 'video[src]' }];
    },

    renderHTML({ HTMLAttributes }) {
        return [
            'video',
            mergeAttributes(HTMLAttributes, {
                controls: HTMLAttributes.controls ? 'controls' : undefined,
                class: 'ai-chat__embed-video',
            }),
        ];
    },
});
