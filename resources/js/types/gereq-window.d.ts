/**
 * Shared Window.gereq surface — modules merge APIs at runtime.
 * Keep every property optional so partial assigns type-check.
 */
type GereqConfirmOptions = {
    title?: string;
    body?: string;
    confirmLabel?: string;
    cancelLabel?: string;
    danger?: boolean;
    input?: boolean;
    inputLabel?: string;
    inputValue?: string;
    inputType?: 'text' | 'password' | 'textarea' | 'editor';
    inputPlaceholder?: string;
    inputRequired?: boolean;
};

type GereqWindowApi = {
    openDrawer?: (id?: string) => void;
    closeDrawer?: (id?: string) => void;
    loadDrawer?: (opts: {
        title?: string;
        html?: string;
        footer?: string;
        id?: string;
    }) => void;
    confirm?: (opts: GereqConfirmOptions | string) => Promise<boolean>;
    prompt?: (opts: GereqConfirmOptions | string) => Promise<string | null>;
    toast?: (message: string, tone?: string) => void;
    pageProgress?: {
        start: () => void;
        done: () => void;
    };
    openImage?: (src: string, opts?: { alt?: string; caption?: string }) => void;
    closeImage?: () => void;
    staging?: {
        refresh?: () => void | Promise<void>;
        refreshCount?: () => void | Promise<void>;
    };
};

declare global {
    interface Window {
        gereq?: GereqWindowApi;
    }
}

export {};
