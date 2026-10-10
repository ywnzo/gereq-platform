import type Alpine from '@alpinejs/csp';

const VIEW_KEY = 'kdeaco-events-view';
const CITY_DIR_KEY = 'kdeaco-events-city-dir';
const CLOSED_KEY = 'kdeaco-events-cities-closed';

type EventsListView = 'table' | 'cities';
type CityDir = 'asc' | 'desc';

function readView(): EventsListView {
    try {
        const saved = localStorage.getItem(VIEW_KEY);
        if (saved === 'table' || saved === 'cities') {
            return saved;
        }
    } catch {
        // ignore
    }

    return 'table';
}

function readCityDir(): CityDir {
    try {
        return localStorage.getItem(CITY_DIR_KEY) === 'desc' ? 'desc' : 'asc';
    } catch {
        return 'asc';
    }
}

function readClosed(): Record<string, boolean> {
    try {
        const raw = localStorage.getItem(CLOSED_KEY);
        if (!raw) {
            return {};
        }
        const parsed = JSON.parse(raw) as unknown;
        if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed)) {
            return {};
        }
        const closed: Record<string, boolean> = {};
        for (const [key, value] of Object.entries(parsed as Record<string, unknown>)) {
            if (value === true) {
                closed[key] = true;
            }
        }

        return closed;
    } catch {
        return {};
    }
}

type EventsListShell = {
    syncSelectAll?: () => void;
    syncFit?: () => void;
};

function syncEventsListShell(alpine: typeof Alpine): void {
    const shell = document.querySelector('[data-table-id="kdeaco-events"]');
    if (!(shell instanceof HTMLElement)) {
        return;
    }
    const data = alpine.$data(shell) as EventsListShell;
    data.syncSelectAll?.();
    data.syncFit?.();
}

export function registerEventsIndex(alpine: typeof Alpine): void {
    alpine.store('eventsList', {
        view: readView(),
        cityDir: readCityDir(),
        closed: readClosed(),

        setView(view: EventsListView) {
            this.view = view;
            try {
                localStorage.setItem(VIEW_KEY, view);
            } catch {
                // ignore
            }
            alpine.nextTick(() => {
                if (view === 'cities') {
                    this.orderCityGroups();
                }
                syncEventsListShell(alpine);
            });
        },

        toggleCityDir() {
            this.cityDir = this.cityDir === 'asc' ? 'desc' : 'asc';
            try {
                localStorage.setItem(CITY_DIR_KEY, this.cityDir);
            } catch {
                // ignore
            }
            this.orderCityGroups();
        },

        cityOpen(city: string): boolean {
            return this.closed[city] !== true;
        },

        toggleCity(city: string) {
            const next = { ...this.closed };
            if (next[city]) {
                delete next[city];
            } else {
                next[city] = true;
            }
            this.closed = next;
            try {
                localStorage.setItem(CLOSED_KEY, JSON.stringify(next));
            } catch {
                // ignore
            }
        },

        orderCityGroups() {
            const root = document.querySelector('[data-city-groups]');
            if (!(root instanceof HTMLElement)) {
                return;
            }
            const bodies = Array.from(root.querySelectorAll('tbody[data-city]'));
            const dir = this.cityDir === 'desc' ? -1 : 1;
            bodies.sort((left, right) => {
                const a = left.getAttribute('data-city') || '';
                const b = right.getAttribute('data-city') || '';

                return a.localeCompare(b, 'cs', { sensitivity: 'base' }) * dir;
            });
            bodies.forEach((body) => root.appendChild(body));
        },
    });

    document.addEventListener('alpine:initialized', () => {
        const store = alpine.store('eventsList') as {
            view: EventsListView;
            orderCityGroups: () => void;
        };
        if (store.view === 'cities') {
            store.orderCityGroups();
        }
        syncEventsListShell(alpine);
    });
}
