import { Extension } from '@tiptap/core';

declare module '@tiptap/core' {
    interface Commands<ReturnType> {
        fontWeight: {
            setFontWeight: (fontWeight: string) => ReturnType;
            unsetFontWeight: () => ReturnType;
        };
    }
}

/** TipTap mark attribute for font-weight via TextStyle. */
export const FontWeight = Extension.create({
    name: 'fontWeight',

    addOptions() {
        return { types: ['textStyle'] };
    },

    addGlobalAttributes() {
        return [
            {
                types: this.options.types,
                attributes: {
                    fontWeight: {
                        default: null,
                        parseHTML: (element: HTMLElement) => {
                            const value = element.style.fontWeight;
                            return value ? value.replace(/['"]+/g, '') : null;
                        },
                        renderHTML: (attributes: { fontWeight?: string | null }) => {
                            if (!attributes.fontWeight) {
                                return {};
                            }
                            return { style: `font-weight: ${attributes.fontWeight}` };
                        },
                    },
                },
            },
        ];
    },

    addCommands() {
        return {
            setFontWeight:
                (fontWeight: string) =>
                ({ chain }) =>
                    chain().setMark('textStyle', { fontWeight }).run(),
            unsetFontWeight:
                () =>
                ({ chain }) =>
                    chain().setMark('textStyle', { fontWeight: null }).removeEmptyTextStyle().run(),
        };
    },
});
