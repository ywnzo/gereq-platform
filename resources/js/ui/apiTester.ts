import type Alpine from '@alpinejs/csp';

type KvRow = { key: string; value: string };
type PathParam = { name: string; value: string };

type OpenPayload = {
    method?: string;
    path?: string;
    scopes?: string[];
    summary?: string;
    ruleset?: string | null;
};

const GEO_KEY = 'gereq.api.tester.geo.v1';
const VARS_KEY = 'gereq.api.tester.vars.v1';
const MIN_W = 640;
const MIN_H = 480;

function clamp(n: number, min: number, max: number): number {
    return Math.min(max, Math.max(min, n));
}

function extractPathParams(path: string): PathParam[] {
    const names = path.match(/\{(\w+)\}/g) ?? [];
    const seen = new Set<string>();
    const out: PathParam[] = [];
    for (const raw of names) {
        const name = raw.slice(1, -1);
        if (seen.has(name)) {
            continue;
        }
        seen.add(name);
        out.push({ name, value: '' });
    }
    return out;
}

function applyPathParams(path: string, params: PathParam[]): string {
    let out = path;
    for (const p of params) {
        const raw = p.value.trim();
        out = out.replaceAll(`{${p.name}}`, raw === '' ? `{${p.name}}` : encodeURIComponent(raw));
    }
    return out;
}

/** Postman-style {{name}} substitution. */
export function interpolate(text: string, vars: Record<string, string>): string {
    return text.replace(/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/g, (full, name: string) => {
        if (Object.prototype.hasOwnProperty.call(vars, name)) {
            return vars[name] ?? '';
        }
        return full;
    });
}

function escHtml(value: string): string {
    return value
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function jsonLeaf(value: unknown): string {
    if (value === null) {
        return '<span class="gdb-dump gdb-dump--null">null</span>';
    }
    if (typeof value === 'boolean') {
        return `<span class="gdb-dump gdb-dump--bool">${value ? 'true' : 'false'}</span>`;
    }
    if (typeof value === 'number') {
        return `<span class="gdb-dump gdb-dump--num">${escHtml(String(value))}</span>`;
    }
    if (typeof value === 'string') {
        return `<span class="gdb-dump gdb-dump--str">"${escHtml(value)}"</span>`;
    }
    return `<span class="gdb-dump gdb-dump--muted">${escHtml(String(value))}</span>`;
}

/** Interactive collapsible JSON tree — same pattern as debugbar User / Dumps. */
export function renderJsonTree(value: unknown, key: string | null = null, depth = 0): string {
    const isArr = Array.isArray(value);
    const isObj = value !== null && typeof value === 'object';
    if (!isObj) {
        const leaf = jsonLeaf(value);
        if (key === null) {
            return leaf;
        }
        return `<div class="gdb-tree__row"><span class="gdb-tree__key">${escHtml(key)}</span><span class="gdb-tree__sep">:</span>${leaf}</div>`;
    }

    const entries = isArr
        ? (value as unknown[]).map((item, i) => [String(i), item] as const)
        : Object.keys(value as object).map((k) => [k, (value as Record<string, unknown>)[k]] as const);
    const hint = isArr ? `Array(${entries.length})` : `Object(${entries.length})`;
    const open = depth < 2 ? ' open' : '';
    const children = entries.map(([k, v]) => renderJsonTree(v, k, depth + 1)).join('');
    const summaryKey =
        key === null
            ? `<span class="gdb-tree__hint">${escHtml(hint)}</span>`
            : `<span class="gdb-tree__key">${escHtml(key)}</span><span class="gdb-tree__sep">:</span><span class="gdb-tree__hint">${escHtml(hint)}</span>`;

    return `<details class="gdb-tree"${open}><summary class="gdb-tree__sum">${summaryKey}</summary><div class="gdb-tree__kids">${
        children || `<div class="gdb-tree__row"><span class="gdb-dump gdb-dump--muted">${isArr ? '[]' : '{}'}</span></div>`
    }</div></details>`;
}

function csrfHeader(): string {
    return (window as Window & { __GEREQ_CSRF__?: string }).__GEREQ_CSRF__ || '';
}

export function registerApiTester(alpine: typeof Alpine): void {
    alpine.data('apiTester', () => ({
        open: false,
        minimized: false,
        sending: false,
        ensuringToken: false,
        method: 'GET',
        path: '/api/v1/',
        summary: '',
        scopes: [] as string[],
        pathParams: [] as PathParam[],
        query: [{ key: '', value: '' }] as KvRow[],
        headers: [
            { key: 'Accept', value: 'application/json' },
            { key: 'Authorization', value: 'Bearer {{token}}' },
        ] as KvRow[],
        variables: [
            { key: 'token', value: '' },
            { key: 'base_url', value: '' },
            { key: 'user_id', value: '' },
        ] as KvRow[],
        body: '{\n  \n}',
        responseText: '',
        responseIsJson: false,
        statusCode: 0,
        durationMs: 0,
        hasResponse: false,
        showToken: false,
        copyOk: false,
        left: 0,
        top: 0,
        width: 860,
        height: 640,
        geoReady: false,
        baseUrl: '',
        testerTokenUrl: '',
        testerUserId: '',
        labelCopy: 'Copy',
        labelCopied: 'Copied',

        init() {
            const root = this.$el as HTMLElement;
            this.baseUrl = (root.dataset.apiBase || window.location.origin).replace(/\/$/, '');
            this.testerTokenUrl = root.dataset.testerTokenUrl || '';
            this.testerUserId = root.dataset.testerUserId || '';
            this.labelCopy = root.dataset.labelCopy || 'Copy';
            this.labelCopied = root.dataset.labelCopied || 'Copied';
            this.loadVariables();
            this.setVar('base_url', this.baseUrl);
            if (this.testerUserId) {
                this.setVar('user_id', this.testerUserId);
            }
        },

        paintResponseTree(parsed: unknown | null, fallbackText: string) {
            this.$nextTick(() => {
                const el = (this as unknown as { $refs: Record<string, HTMLElement | undefined> }).$refs.responseTree;
                if (!el) {
                    return;
                }
                if (parsed !== null) {
                    el.innerHTML = `<div class="gdb-tree-wrap">${renderJsonTree(parsed)}</div>`;
                } else {
                    el.innerHTML = `<pre class="api-tester__raw">${escHtml(fallbackText)}</pre>`;
                }
            });
        },

        clearResponseTree() {
            this.$nextTick(() => {
                const el = (this as unknown as { $refs: Record<string, HTMLElement | undefined> }).$refs.responseTree;
                if (el) {
                    el.innerHTML = '';
                }
            });
        },

        async copyResponse() {
            try {
                await navigator.clipboard.writeText(this.responseText);
                this.copyOk = true;
                window.setTimeout(() => {
                    this.copyOk = false;
                }, 1200);
            } catch {
                /* ignore */
            }
        },

        get needsBody(): boolean {
            return ['POST', 'PUT', 'PATCH'].includes(this.method.toUpperCase());
        },

        get scopesLabel(): string {
            if (!this.scopes.length) {
                return '';
            }
            return `Scopes: ${this.scopes.join(', ')}`;
        },

        get statusLabel(): string {
            return this.statusCode ? String(this.statusCode) : '';
        },

        get timingLabel(): string {
            return this.durationMs ? `${this.durationMs} ms` : '';
        },

        get statusClass(): string {
            if (this.statusCode >= 200 && this.statusCode < 300) {
                return 'is-ok';
            }
            if (this.statusCode >= 400 || this.statusCode === 0) {
                return 'is-err';
            }
            return '';
        },

        get copyLabel(): string {
            return this.copyOk ? 'copied' : 'copy';
        },

        get pillLabel(): string {
            return `${this.method} ${this.path}`;
        },

        get hasToken(): boolean {
            return this.getVar('token').trim() !== '';
        },

        methodPillClass(): string {
            return `method-pill--${this.method.toLowerCase()}`;
        },

        dockPanelClass(): string {
            return this.hasResponse ? 'has-response' : '';
        },

        copyButtonLabel(): string {
            return this.copyOk ? this.labelCopied : this.labelCopy;
        },

        varMap(): Record<string, string> {
            const map: Record<string, string> = {};
            for (const row of this.variables) {
                const key = row.key.trim();
                if (!key) {
                    continue;
                }
                map[key] = row.value;
            }
            return map;
        },

        getVar(name: string): string {
            const row = this.variables.find((r) => r.key === name);
            return row?.value ?? '';
        },

        setVar(name: string, value: string) {
            const row = this.variables.find((r) => r.key === name);
            if (row) {
                row.value = value;
            } else {
                this.variables.push({ key: name, value });
            }
            this.persistVariables();
        },

        loadVariables() {
            try {
                const raw = localStorage.getItem(VARS_KEY);
                if (!raw) {
                    return;
                }
                const parsed = JSON.parse(raw) as KvRow[];
                if (!Array.isArray(parsed)) {
                    return;
                }
                const custom = parsed.filter((r) => r && typeof r.key === 'string' && !['token', 'base_url', 'user_id'].includes(r.key));
                this.variables = [
                    { key: 'token', value: '' },
                    { key: 'base_url', value: this.baseUrl },
                    { key: 'user_id', value: this.testerUserId },
                    ...custom.map((r) => ({ key: r.key, value: String(r.value ?? '') })),
                ];
            } catch {
                /* ignore */
            }
        },

        persistVariables() {
            try {
                const custom = this.variables.filter((r) => !['token', 'base_url', 'user_id'].includes(r.key) && r.key.trim() !== '');
                localStorage.setItem(VARS_KEY, JSON.stringify(custom));
            } catch {
                /* ignore */
            }
        },

        addVariableRow() {
            this.variables.push({ key: '', value: '' });
        },

        removeVariableRow(idx: number) {
            const row = this.variables[idx];
            if (row && ['token', 'base_url', 'user_id'].includes(row.key)) {
                return;
            }
            this.variables.splice(idx, 1);
            this.persistVariables();
        },

        isBuiltInVar(key: string): boolean {
            return ['token', 'base_url', 'user_id'].includes(key);
        },

        isTokenVar(key: string): boolean {
            return key === 'token';
        },

        varInputType(key: string): string {
            if (key === 'token' && !this.showToken) {
                return 'password';
            }
            return 'text';
        },

        toggleShowToken() {
            this.showToken = !this.showToken;
        },

        openTestFromButton(event: Event) {
            const btn = event.currentTarget as HTMLElement | null;
            if (!btn) {
                return;
            }
            let scopes: string[] = [];
            try {
                const raw = btn.dataset.scopes || '[]';
                const parsed = JSON.parse(raw) as unknown;
                if (Array.isArray(parsed)) {
                    scopes = parsed.map(String);
                }
            } catch {
                scopes = [];
            }
            void this.openTest({
                method: btn.dataset.method || 'GET',
                path: btn.dataset.path || '/api/v1/',
                summary: btn.dataset.summary || '',
                scopes,
            });
        },

        async openTest(payload: OpenPayload | string) {
            const data = typeof payload === 'string' ? (JSON.parse(payload) as OpenPayload) : payload;
            this.method = String(data.method || 'GET').toUpperCase();
            this.path = String(data.path || '/api/v1/');
            this.summary = String(data.summary || '');
            this.scopes = Array.isArray(data.scopes) ? data.scopes.map(String) : [];
            this.pathParams = extractPathParams(this.path);
            this.query = this.method === 'GET' && !this.pathParams.length
                ? [
                    { key: 'page', value: '1' },
                    { key: 'per_page', value: '25' },
                    { key: 'q', value: '' },
                ]
                : [{ key: '', value: '' }];
            this.body = '{\n  \n}';
            this.hasResponse = false;
            this.responseText = '';
            this.responseIsJson = false;
            this.statusCode = 0;
            this.durationMs = 0;
            this.copyOk = false;
            this.clearResponseTree();
            this.minimized = false;
            this.open = true;
            this.ensureGeo();
            if (!this.hasToken) {
                await this.ensureUserToken(false);
            }
            this.$nextTick(() => {
                const focus = (this.$el as HTMLElement).querySelector<HTMLInputElement>('#api-tester-path');
                focus?.focus();
                focus?.select();
            });
        },

        close() {
            this.open = false;
            this.minimized = false;
        },

        minimize() {
            this.minimized = true;
        },

        restore() {
            this.minimized = false;
            this.ensureGeo();
        },

        onEscape() {
            if (!this.open) {
                return;
            }
            if (!this.minimized) {
                this.minimize();
            }
        },

        addQueryRow() {
            this.query.push({ key: '', value: '' });
        },

        removeQueryRow(idx: number) {
            this.query.splice(idx, 1);
            if (!this.query.length) {
                this.query.push({ key: '', value: '' });
            }
        },

        addHeaderRow() {
            this.headers.push({ key: '', value: '' });
        },

        removeHeaderRow(idx: number) {
            this.headers.splice(idx, 1);
            if (!this.headers.length) {
                this.headers.push({ key: 'Accept', value: 'application/json' });
            }
        },

        async ensureUserToken(rotate: boolean) {
            if (!this.testerTokenUrl || this.ensuringToken) {
                return;
            }
            this.ensuringToken = true;
            try {
                const body = new URLSearchParams();
                if (rotate) {
                    body.set('rotate', '1');
                }
                const res = await fetch(this.testerTokenUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrfHeader(),
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: body.toString(),
                });
                if (!res.ok) {
                    throw new Error(`HTTP ${res.status}`);
                }
                const data = (await res.json()) as { token?: string };
                if (data.token) {
                    this.setVar('token', data.token);
                }
            } catch (err) {
                console.warn('API tester token', err);
            } finally {
                this.ensuringToken = false;
            }
        },

        useMyToken() {
            void this.ensureUserToken(true);
        },

        buildUrl(vars: Record<string, string>): string {
            const pathRaw = interpolate(this.path.trim(), vars);
            const resolved = applyPathParams(
                pathRaw,
                this.pathParams.map((p) => ({ name: p.name, value: interpolate(p.value, vars) })),
            );
            const base = (vars.base_url || this.baseUrl).replace(/\/$/, '');
            const url = new URL(resolved.startsWith('http') ? resolved : `${base}${resolved.startsWith('/') ? '' : '/'}${resolved}`);
            for (const row of this.query) {
                const key = interpolate(row.key.trim(), vars);
                if (!key) {
                    continue;
                }
                url.searchParams.append(key, interpolate(row.value, vars));
            }
            return url.toString();
        },

        async send() {
            if (this.sending) {
                return;
            }
            const authUsesToken = this.headers.some(
                (h) => h.key.trim().toLowerCase() === 'authorization' && h.value.includes('{{token}}'),
            );
            if (authUsesToken && !this.hasToken) {
                await this.ensureUserToken(false);
            }

            this.sending = true;
            this.hasResponse = true;
            this.responseText = '';
            this.responseIsJson = false;
            this.copyOk = false;
            this.clearResponseTree();
            const started = performance.now();
            try {
                const vars = this.varMap();
                const headers = new Headers();
                for (const row of this.headers) {
                    const key = interpolate(row.key.trim(), vars);
                    const value = interpolate(row.value, vars).trim();
                    if (!key || value === '') {
                        continue;
                    }
                    headers.set(key, value);
                }
                if (this.needsBody && !headers.has('Content-Type')) {
                    headers.set('Content-Type', 'application/json');
                }

                const init: RequestInit = {
                    method: this.method.toUpperCase(),
                    headers,
                    credentials: 'same-origin',
                };
                if (this.needsBody) {
                    init.body = interpolate(this.body, vars);
                }

                const res = await fetch(this.buildUrl(vars), init);
                this.statusCode = res.status;
                const text = await res.text();
                this.durationMs = Math.round(performance.now() - started);
                try {
                    const parsed = JSON.parse(text) as unknown;
                    this.responseIsJson = true;
                    this.responseText = JSON.stringify(parsed, null, 2);
                    this.paintResponseTree(parsed, text);
                } catch {
                    this.responseIsJson = false;
                    this.responseText = text || `(empty body, HTTP ${res.status})`;
                    this.paintResponseTree(null, this.responseText);
                }
            } catch (err) {
                this.statusCode = 0;
                this.durationMs = Math.round(performance.now() - started);
                this.responseIsJson = false;
                this.responseText = err instanceof Error ? err.message : String(err);
                this.paintResponseTree(null, this.responseText);
            } finally {
                this.sending = false;
            }
        },

        ensureGeo() {
            if (this.geoReady) {
                this.clampGeo();
                return;
            }
            try {
                const raw = localStorage.getItem(GEO_KEY);
                if (raw) {
                    const parsed = JSON.parse(raw) as { left?: number; top?: number; width?: number; height?: number };
                    this.left = Number(parsed.left) || 0;
                    this.top = Number(parsed.top) || 0;
                    this.width = Number(parsed.width) || 860;
                    this.height = Number(parsed.height) || 640;
                } else {
                    this.width = clamp(860, MIN_W, window.innerWidth - 24);
                    this.height = clamp(640, MIN_H, window.innerHeight - 24);
                    this.left = Math.max(12, Math.round((window.innerWidth - this.width) / 2));
                    this.top = Math.max(12, Math.round((window.innerHeight - this.height) / 2));
                }
            } catch {
                this.width = 860;
                this.height = 640;
                this.left = 40;
                this.top = 40;
            }
            this.geoReady = true;
            this.clampGeo();
        },

        clampGeo() {
            this.width = clamp(this.width, MIN_W, window.innerWidth);
            this.height = clamp(this.height, MIN_H, window.innerHeight);
            this.left = clamp(this.left, 0, Math.max(0, window.innerWidth - this.width));
            this.top = clamp(this.top, 0, Math.max(0, window.innerHeight - this.height));
        },

        persistGeo() {
            try {
                localStorage.setItem(
                    GEO_KEY,
                    JSON.stringify({
                        left: this.left,
                        top: this.top,
                        width: this.width,
                        height: this.height,
                    }),
                );
            } catch {
                /* ignore */
            }
        },

        dockStyle() {
            return {
                left: `${this.left}px`,
                top: `${this.top}px`,
                width: `${this.width}px`,
                height: `${this.height}px`,
            };
        },

        startDrag(event: PointerEvent) {
            if (event.button !== 0) {
                return;
            }
            const target = event.target as HTMLElement | null;
            if (target?.closest('button, a, input, select, textarea')) {
                return;
            }
            event.preventDefault();
            this.ensureGeo();
            const startX = event.clientX;
            const startY = event.clientY;
            const originLeft = this.left;
            const originTop = this.top;
            const onMove = (e: PointerEvent) => {
                this.left = clamp(originLeft + (e.clientX - startX), 0, Math.max(0, window.innerWidth - this.width));
                this.top = clamp(originTop + (e.clientY - startY), 0, Math.max(0, window.innerHeight - this.height));
            };
            const onUp = () => {
                window.removeEventListener('pointermove', onMove);
                window.removeEventListener('pointerup', onUp);
                this.persistGeo();
            };
            window.addEventListener('pointermove', onMove);
            window.addEventListener('pointerup', onUp);
        },

        startResize(event: PointerEvent) {
            if (event.button !== 0) {
                return;
            }
            event.preventDefault();
            event.stopPropagation();
            this.ensureGeo();
            const edge = ((event.currentTarget as HTMLElement).dataset.edge || 'se').toLowerCase();
            const startX = event.clientX;
            const startY = event.clientY;
            const origin = { left: this.left, top: this.top, width: this.width, height: this.height };
            const onMove = (e: PointerEvent) => {
                const dx = e.clientX - startX;
                const dy = e.clientY - startY;
                let left = origin.left;
                let top = origin.top;
                let width = origin.width;
                let height = origin.height;
                if (edge.includes('e')) {
                    width = origin.width + dx;
                }
                if (edge.includes('s')) {
                    height = origin.height + dy;
                }
                if (edge.includes('w')) {
                    width = origin.width - dx;
                    left = origin.left + dx;
                }
                if (edge.includes('n')) {
                    height = origin.height - dy;
                    top = origin.top + dy;
                }
                width = clamp(width, MIN_W, window.innerWidth);
                height = clamp(height, MIN_H, window.innerHeight);
                if (edge.includes('w')) {
                    left = clamp(origin.left + (origin.width - width), 0, window.innerWidth - width);
                }
                if (edge.includes('n')) {
                    top = clamp(origin.top + (origin.height - height), 0, window.innerHeight - height);
                }
                this.left = clamp(left, 0, Math.max(0, window.innerWidth - width));
                this.top = clamp(top, 0, Math.max(0, window.innerHeight - height));
                this.width = width;
                this.height = height;
            };
            const onUp = () => {
                window.removeEventListener('pointermove', onMove);
                window.removeEventListener('pointerup', onUp);
                this.persistGeo();
            };
            window.addEventListener('pointermove', onMove);
            window.addEventListener('pointerup', onUp);
        },
    }));
}
