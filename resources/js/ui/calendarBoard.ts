import type Alpine from '@alpinejs/csp';

type CalEvent = {
    id: string;
    title: string;
    starts_at: string;
    ends_at: string;
    all_day: boolean;
    type: string;
    color: string;
    status?: string;
    timezone?: string | null;
    location?: string | null;
    description?: string | null;
    rrule?: string | null;
    external_provider?: string | null;
    url?: string;
    edit_url?: string;
    is_external?: boolean;
};

type CalDay = {
    date: string;
    label: number;
    inMonth: boolean;
    isToday: boolean;
    events: CalEvent[];
    preview: CalEvent[];
    more: number;
};

function pad(n: number): string {
    return n < 10 ? '0' + n : String(n);
}

function ymd(d: Date): string {
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
}

function startOfWeek(d: Date): Date {
    const x = new Date(d);
    const day = (x.getDay() + 6) % 7; // Monday=0
    x.setDate(x.getDate() - day);
    x.setHours(0, 0, 0, 0);
    return x;
}

function addDays(d: Date, n: number): Date {
    const x = new Date(d);
    x.setDate(x.getDate() + n);
    return x;
}

function parseIso(iso: string): Date {
    return new Date(iso);
}

function sameDay(a: Date, b: Date): boolean {
    return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
}

function toLocalIso(d: Date): string {
    return (
        d.getFullYear() +
        '-' +
        pad(d.getMonth() + 1) +
        '-' +
        pad(d.getDate()) +
        'T' +
        pad(d.getHours()) +
        ':' +
        pad(d.getMinutes()) +
        ':' +
        pad(d.getSeconds())
    );
}

const MONTHS_CS = [
    'leden', 'únor', 'březen', 'duben', 'květen', 'červen',
    'červenec', 'srpen', 'září', 'říjen', 'listopad', 'prosinec',
];
const DOW = ['Po', 'Út', 'St', 'Čt', 'Pá', 'So', 'Ne'];
const HOURS = Array.from({ length: 24 }, (_, i) => i);
const TYPE_LABELS: Record<string, string> = {
    meeting: 'Schůzka',
    deadline: 'Termín',
    call: 'Hovor',
    task: 'Úkol',
    other: 'Jiné',
};
const STATUS_LABELS: Record<string, string> = {
    confirmed: 'Potvrzeno',
    tentative: 'Nejisté',
    cancelled: 'Zrušeno',
};

export function registerCalendarBoard(alpine: typeof Alpine): void {
    alpine.data('gereqCalendar', () => ({
        view: 'month' as 'month' | 'week' | 'day' | 'agenda',
        anchor: new Date(),
        events: [] as CalEvent[],
        loading: false,
        selected: null as CalEvent | null,
        feedUrl: '',
        moveUrl: '',
        createUrl: '',
        canManage: false,
        csrf: '',
        hours: HOURS,
        dow: DOW,
        dragId: null as string | null,
        dropTarget: null as string | null,
        justDragged: false,

        init() {
            const el = this.$el as HTMLElement;
            this.feedUrl = el.dataset.feedUrl || '';
            this.moveUrl = el.dataset.moveUrl || '';
            this.createUrl = el.dataset.createUrl || '';
            this.canManage = el.dataset.canManage === '1';
            this.view = (el.dataset.view as typeof this.view) || 'month';
            const raw = el.dataset.anchor || '';
            if (raw) {
                const d = new Date(raw + 'T12:00:00');
                if (!Number.isNaN(d.getTime())) {
                    this.anchor = d;
                }
            }
            this.csrf = (window as Window & { __GEREQ_CSRF__?: string }).__GEREQ_CSRF__ || '';
            void this.load();
        },

        get title(): string {
            const d = this.anchor;
            if (this.view === 'day') {
                return d.getDate() + '. ' + MONTHS_CS[d.getMonth()] + ' ' + d.getFullYear();
            }
            if (this.view === 'week') {
                const start = startOfWeek(d);
                const end = addDays(start, 6);
                return start.getDate() + '.–' + end.getDate() + '. ' + MONTHS_CS[d.getMonth()] + ' ' + d.getFullYear();
            }
            return MONTHS_CS[d.getMonth()] + ' ' + d.getFullYear();
        },

        get monthCells(): CalDay[] {
            const first = new Date(this.anchor.getFullYear(), this.anchor.getMonth(), 1);
            const gridStart = startOfWeek(first);
            const today = new Date();
            const cells: CalDay[] = [];
            for (let i = 0; i < 42; i++) {
                const day = addDays(gridStart, i);
                const key = ymd(day);
                const events = this.eventsForDay(day);
                cells.push({
                    date: key,
                    label: day.getDate(),
                    inMonth: day.getMonth() === this.anchor.getMonth(),
                    isToday: sameDay(day, today),
                    events,
                    preview: events.slice(0, 3),
                    more: Math.max(0, events.length - 3),
                });
            }
            return cells;
        },

        get weekDays(): CalDay[] {
            const start = startOfWeek(this.anchor);
            const today = new Date();
            return Array.from({ length: 7 }, (_, i) => {
                const day = addDays(start, i);
                const events = this.eventsForDay(day);
                return {
                    date: ymd(day),
                    label: day.getDate(),
                    inMonth: true,
                    isToday: sameDay(day, today),
                    events,
                    preview: events.slice(0, 3),
                    more: Math.max(0, events.length - 3),
                };
            });
        },

        get dayEvents(): CalEvent[] {
            return this.eventsForDay(this.anchor);
        },

        get agendaEvents(): CalEvent[] {
            return [...this.events].sort(
                (a, b) => parseIso(a.starts_at).getTime() - parseIso(b.starts_at).getTime(),
            );
        },

        eventsForDay(day: Date): CalEvent[] {
            return this.events.filter((ev) => {
                const s = parseIso(ev.starts_at);
                const e = parseIso(ev.ends_at);
                const start = new Date(day);
                start.setHours(0, 0, 0, 0);
                const end = addDays(start, 1);
                return s < end && e > start;
            });
        },

        range(): { from: string; to: string } {
            if (this.view === 'day') {
                const d = ymd(this.anchor);
                return { from: d, to: d };
            }
            if (this.view === 'week' || this.view === 'agenda') {
                const start = startOfWeek(this.anchor);
                return { from: ymd(start), to: ymd(addDays(start, 7)) };
            }
            const first = new Date(this.anchor.getFullYear(), this.anchor.getMonth(), 1);
            const from = ymd(startOfWeek(first));
            const to = ymd(addDays(startOfWeek(first), 42));
            return { from, to };
        },

        async load() {
            if (!this.feedUrl) {
                return;
            }
            this.loading = true;
            try {
                const { from, to } = this.range();
                const res = await fetch(this.feedUrl + '?from=' + encodeURIComponent(from) + '&to=' + encodeURIComponent(to), {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (!res.ok) {
                    throw new Error('feed failed');
                }
                const json = (await res.json()) as { data?: CalEvent[] };
                this.events = Array.isArray(json.data) ? json.data : [];
            } catch {
                this.events = [];
                window.gereq?.toast?.('Kalendář se nepodařilo načíst.', 'error');
            } finally {
                this.loading = false;
            }
        },

        setView(view: typeof this.view) {
            this.view = view;
            void this.load();
        },

        prev() {
            if (this.view === 'day') {
                this.anchor = addDays(this.anchor, -1);
            } else if (this.view === 'week' || this.view === 'agenda') {
                this.anchor = addDays(this.anchor, -7);
            } else {
                this.anchor = new Date(this.anchor.getFullYear(), this.anchor.getMonth() - 1, 1);
            }
            void this.load();
        },

        next() {
            if (this.view === 'day') {
                this.anchor = addDays(this.anchor, 1);
            } else if (this.view === 'week' || this.view === 'agenda') {
                this.anchor = addDays(this.anchor, 7);
            } else {
                this.anchor = new Date(this.anchor.getFullYear(), this.anchor.getMonth() + 1, 1);
            }
            void this.load();
        },

        today() {
            this.anchor = new Date();
            void this.load();
        },

        openDay(date: string) {
            this.anchor = new Date(date + 'T12:00:00');
            this.view = 'day';
            void this.load();
        },

        createAt(date: string, hour?: number) {
            if (!this.canManage || !this.createUrl) {
                return;
            }
            let starts = date + 'T09:00';
            if (typeof hour === 'number') {
                starts = date + 'T' + pad(hour) + ':00';
            }
            window.location.href = this.createUrl + '?starts=' + encodeURIComponent(starts);
        },

        openEvent(ev: CalEvent) {
            if (this.justDragged) {
                this.justDragged = false;
                return;
            }
            this.selected = ev;
            document.body.classList.add('has-drawer');
        },

        closeEvent() {
            this.selected = null;
            document.body.classList.remove('has-drawer');
        },

        goEvent() {
            if (this.selected?.url) {
                window.location.href = this.selected.url;
            }
        },

        goEdit() {
            if (this.selected?.edit_url) {
                window.location.href = this.selected.edit_url;
                return;
            }
            if (this.selected?.url) {
                window.location.href = this.selected.url + '/edit';
            }
        },

        timeLabel(iso: string): string {
            const d = parseIso(iso);
            return pad(d.getHours()) + ':' + pad(d.getMinutes());
        },

        dowLabel(date: string): string {
            const d = new Date(date + 'T12:00:00');
            const i = (d.getDay() + 6) % 7;
            return DOW[i] || '';
        },

        dateKey(d: Date): string {
            return ymd(d);
        },

        eventStyle(ev: CalEvent): string {
            return 'cal-event--' + (ev.color || 'blue');
        },

        hourEvents(day: CalDay, hour: number): CalEvent[] {
            return day.events.filter((ev) => {
                if (ev.all_day) {
                    return hour === 0;
                }
                return parseIso(ev.starts_at).getHours() === hour;
            });
        },

        dayHourEvents(hour: number): CalEvent[] {
            return this.eventsForDay(this.anchor).filter((ev) => {
                if (ev.all_day) {
                    return hour === 0;
                }
                return parseIso(ev.starts_at).getHours() === hour;
            });
        },

        hourLabel(hour: number): string {
            return pad(hour) + ':00';
        },

        isView(name: string): boolean {
            return this.view === name;
        },

        viewClass(name: string): string {
            return this.view === name ? 'btn--primary' : 'btn--ghost';
        },

        boardClass(): string {
            return 'cal-board--' + this.view;
        },

        moreLabel(n: number): string {
            return '+' + n;
        },

        eventTimeText(ev: CalEvent): string {
            return ev.all_day ? 'Celý den' : this.timeLabel(ev.starts_at);
        },

        agendaWhen(ev: CalEvent): string {
            return this.timeLabel(ev.starts_at) + ' – ' + this.timeLabel(ev.ends_at);
        },

        locationText(ev: CalEvent): string {
            return ev.location || '';
        },

        hasLocation(ev: CalEvent): boolean {
            return !!(ev.location && ev.location.length > 0);
        },

        selectedTitle(): string {
            return this.selected ? this.selected.title : '';
        },

        selectedWhen(): string {
            if (!this.selected) {
                return '';
            }
            return this.agendaWhen(this.selected);
        },

        selectedLocation(): string {
            return this.selected ? this.locationText(this.selected) : '';
        },

        hasSelectedLocation(): boolean {
            return this.selected ? this.hasLocation(this.selected) : false;
        },

        selectedType(): string {
            if (!this.selected) {
                return '';
            }
            return TYPE_LABELS[this.selected.type] || this.selected.type;
        },

        selectedStatus(): string {
            if (!this.selected?.status) {
                return '';
            }
            return STATUS_LABELS[this.selected.status] || this.selected.status;
        },

        selectedDescription(): string {
            const text = this.selected?.description || '';
            if (text.length <= 180) {
                return text;
            }
            return text.slice(0, 177) + '…';
        },

        hasSelectedDescription(): boolean {
            return !!(this.selected?.description && this.selected.description.length > 0);
        },

        selectedColor(): string {
            const c = this.selected?.color || '';
            const labels: Record<string, string> = {
                blue: 'Modrá',
                green: 'Zelená',
                amber: 'Jantarová',
                red: 'Červená',
                violet: 'Fialová',
                slate: 'Šedá',
            };
            return labels[c] || c;
        },

        selectedTimezone(): string {
            return this.selected?.timezone || '';
        },

        hasSelectedTimezone(): boolean {
            return !!(this.selected?.timezone && this.selected.timezone.length > 0);
        },

        selectedExternal(): string {
            return this.selected?.external_provider || '';
        },

        isSelectedExternal(): boolean {
            return !!this.selected?.is_external;
        },

        selectedRrule(): string {
            return this.selected?.rrule || '';
        },

        hasSelectedRrule(): boolean {
            return !!(this.selected?.rrule && this.selected.rrule.length > 0);
        },

        cellOutClass(cell: CalDay): string {
            return cell.inMonth ? '' : 'is-out';
        },

        cellTodayClass(cell: CalDay): string {
            return cell.isToday ? 'is-today' : '';
        },

        canDrag(): boolean {
            return this.canManage;
        },

        eventDragClass(ev: CalEvent): string {
            return this.dragId === ev.id ? 'is-dragging' : '';
        },

        dayDropClass(date: string): string {
            return this.dropTarget === 'day:' + date ? 'is-drop-target' : '';
        },

        slotDropClass(date: string, hour: number): string {
            return this.dropTarget === 'slot:' + date + ':' + hour ? 'is-drop-target' : '';
        },

        onDragStart(event: DragEvent, ev: CalEvent) {
            if (!this.canManage) {
                event.preventDefault();
                return;
            }
            this.dragId = ev.id;
            this.closeEvent();
            if (event.dataTransfer) {
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', ev.id);
            }
            const target = event.currentTarget as HTMLElement | null;
            target?.classList.add('is-dragging');
        },

        onDragEnd() {
            this.dragId = null;
            this.dropTarget = null;
            this.justDragged = true;
            window.setTimeout(() => {
                this.justDragged = false;
            }, 120);
        },

        onDragOverDay(event: DragEvent, date: string) {
            if (!this.dragId) {
                return;
            }
            event.preventDefault();
            if (event.dataTransfer) {
                event.dataTransfer.dropEffect = 'move';
            }
            this.dropTarget = 'day:' + date;
        },

        onDragOverSlot(event: DragEvent, date: string, hour: number) {
            if (!this.dragId) {
                return;
            }
            event.preventDefault();
            if (event.dataTransfer) {
                event.dataTransfer.dropEffect = 'move';
            }
            this.dropTarget = 'slot:' + date + ':' + hour;
        },

        onDragLeave() {
            this.dropTarget = null;
        },

        async onDropDay(event: DragEvent, date: string) {
            event.preventDefault();
            const id = this.dragId || event.dataTransfer?.getData('text/plain') || '';
            this.dropTarget = null;
            this.dragId = null;
            if (!id) {
                return;
            }
            const ev = this.events.find((e) => e.id === id);
            if (!ev) {
                return;
            }
            const start = parseIso(ev.starts_at);
            const end = parseIso(ev.ends_at);
            const duration = end.getTime() - start.getTime();
            const parts = date.split('-').map((x) => Number(x));
            const nextStart = new Date(start);
            nextStart.setFullYear(parts[0], parts[1] - 1, parts[2]);
            const nextEnd = new Date(nextStart.getTime() + duration);
            await this.moveEvent(ev, nextStart, nextEnd, ev.all_day);
        },

        async onDropSlot(event: DragEvent, date: string, hour: number) {
            event.preventDefault();
            const id = this.dragId || event.dataTransfer?.getData('text/plain') || '';
            this.dropTarget = null;
            this.dragId = null;
            if (!id) {
                return;
            }
            const ev = this.events.find((e) => e.id === id);
            if (!ev) {
                return;
            }
            const start = parseIso(ev.starts_at);
            const end = parseIso(ev.ends_at);
            const duration = Math.max(end.getTime() - start.getTime(), 30 * 60 * 1000);
            const parts = date.split('-').map((x) => Number(x));
            const nextStart = new Date(parts[0], parts[1] - 1, parts[2], hour, start.getMinutes(), 0, 0);
            if (ev.all_day) {
                nextStart.setMinutes(0, 0, 0);
            }
            const nextEnd = new Date(nextStart.getTime() + duration);
            await this.moveEvent(ev, nextStart, nextEnd, false);
        },

        async shiftEvent(ev: CalEvent, days: number) {
            const s = parseIso(ev.starts_at);
            const e = parseIso(ev.ends_at);
            s.setDate(s.getDate() + days);
            e.setDate(e.getDate() + days);
            await this.moveEvent(ev, s, e, ev.all_day);
        },

        async moveEvent(ev: CalEvent, starts: Date, ends: Date, allDay: boolean) {
            if (!this.canManage || !this.moveUrl) {
                return;
            }
            const same =
                parseIso(ev.starts_at).getTime() === starts.getTime() &&
                parseIso(ev.ends_at).getTime() === ends.getTime() &&
                ev.all_day === allDay;
            if (same) {
                return;
            }
            try {
                const res = await fetch(this.moveUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': this.csrf,
                    },
                    body: JSON.stringify({
                        id: ev.id,
                        starts_at: toLocalIso(starts),
                        ends_at: toLocalIso(ends),
                        all_day: allDay,
                    }),
                });
                if (!res.ok) {
                    throw new Error('move failed');
                }
                window.gereq?.toast?.('Událost přesunuta.', 'success');
                this.closeEvent();
                void this.load();
            } catch {
                window.gereq?.toast?.('Přesun se nezdařil.', 'error');
            }
        },
    }));
}
