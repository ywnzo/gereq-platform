export type UiPreferences = {
    nav_groups?: Record<string, boolean>;
    nav_trees?: Record<string, boolean>;
    modules_expanded?: string[];
};

type GereqWindow = Window & {
    __GEREQ_UI_PREFS__?: UiPreferences;
    __GEREQ_UI_PREFS_URL__?: string;
    __GEREQ_CSRF__?: string;
};

let memory: UiPreferences | null = null;
let flushTimer: ReturnType<typeof setTimeout> | null = null;
let pending: UiPreferences = {};

function win(): GereqWindow {
    return window as GereqWindow;
}

export function readUiPreferences(): UiPreferences {
    if (memory) {
        return memory;
    }
    const seeded = win().__GEREQ_UI_PREFS__;
    memory = seeded && typeof seeded === 'object' ? { ...seeded } : {};
    if (Array.isArray(seeded?.modules_expanded)) {
        memory.modules_expanded = [...seeded.modules_expanded];
    }
    if (seeded?.nav_groups && typeof seeded.nav_groups === 'object') {
        memory.nav_groups = { ...seeded.nav_groups };
    }
    if (seeded?.nav_trees && typeof seeded.nav_trees === 'object') {
        memory.nav_trees = { ...seeded.nav_trees };
    }

    return memory;
}

function mergePending(patch: UiPreferences): void {
    if (patch.nav_groups) {
        pending.nav_groups = { ...(pending.nav_groups || {}), ...patch.nav_groups };
        const current = readUiPreferences();
        current.nav_groups = { ...(current.nav_groups || {}), ...patch.nav_groups };
    }
    if (patch.nav_trees) {
        pending.nav_trees = { ...(pending.nav_trees || {}), ...patch.nav_trees };
        const current = readUiPreferences();
        current.nav_trees = { ...(current.nav_trees || {}), ...patch.nav_trees };
    }
    if (patch.modules_expanded) {
        pending.modules_expanded = [...patch.modules_expanded];
        readUiPreferences().modules_expanded = [...patch.modules_expanded];
    }
}

async function flush(): Promise<void> {
    const url = win().__GEREQ_UI_PREFS_URL__;
    if (!url) {
        pending = {};
        return;
    }
    const body = pending;
    pending = {};
    if (
        !body.nav_groups &&
        !body.nav_trees &&
        body.modules_expanded === undefined
    ) {
        return;
    }
    try {
        await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            keepalive: true,
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': win().__GEREQ_CSRF__ || '',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(body),
        });
    } catch {
        /* keep optimistic local state */
    }
}

/** Optimistic merge + debounced persist to the signed-in user account. */
export function patchUiPreferences(patch: UiPreferences, debounceMs = 200): void {
    mergePending(patch);
    if (flushTimer) {
        clearTimeout(flushTimer);
        flushTimer = null;
    }
    if (debounceMs <= 0) {
        void flush();
        return;
    }
    flushTimer = setTimeout(() => {
        flushTimer = null;
        void flush();
    }, debounceMs);
}

/** Flush pending patches (e.g. before a full page reload). */
export async function flushUiPreferences(): Promise<void> {
    if (flushTimer) {
        clearTimeout(flushTimer);
        flushTimer = null;
    }
    await flush();
}

export function navGroupOpen(group: string, fallback = true): boolean {
    const value = readUiPreferences().nav_groups?.[group];
    return typeof value === 'boolean' ? value : fallback;
}

export function navTreeOpen(treeId: string, fallback = false): boolean {
    const value = readUiPreferences().nav_trees?.[treeId];
    return typeof value === 'boolean' ? value : fallback;
}

export function modulesExpanded(): string[] {
    const list = readUiPreferences().modules_expanded;
    return Array.isArray(list) ? list.filter((id): id is string => typeof id === 'string') : [];
}
