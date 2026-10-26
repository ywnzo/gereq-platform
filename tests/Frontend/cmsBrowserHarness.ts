import { expect } from 'bun:test';
import { chromium, type Frame, type Page } from 'playwright-core';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';

type CmsBrowser = {
    page: Page;
    fresh: () => Promise<Frame>;
    select: (frame: Frame, target?: string) => Promise<void>;
    settle: () => Promise<void>;
};

/** Real parent/iframe bridge, isolated from the running development environment. */
export async function withCmsEditor(run: (editor: CmsBrowser) => Promise<void>): Promise<void> {
    const output = await mkdtemp(join(tmpdir(), 'cms-rich-editor-'));
    let server: ReturnType<typeof Bun.serve> | undefined;
    let browser: Awaited<ReturnType<typeof chromium.launch>> | undefined;
    try {
        const cmsLib = resolve(import.meta.dir, '../../../cms-lib');
        const vue = resolve(import.meta.dir, '../../node_modules/vue/dist/vue.esm-bundler.js');
        const build = await Bun.build({
            entrypoints: [join(import.meta.dir, 'fixtures/dynamicRichText.ts')], outdir: output, target: 'browser',
            define: { __VUE_OPTIONS_API__: 'true', __VUE_PROD_DEVTOOLS__: 'false', __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: 'false' },
            plugins: [{ name: 'cms-fixture', setup(build) {
                build.onResolve({ filter: /^(@gereq\/cms-core|vue)$/ }, ({ path }) => ({
                    path: path === 'vue' ? vue : join(cmsLib, 'packages/core/src/index.ts'),
                }));
            } }],
        });
        expect(build.logs).toEqual([]);
        expect(build.success).toBe(true);
        server = Bun.serve({ hostname: '127.0.0.1', port: 0, fetch(request) {
            const path = new URL(request.url).pathname;
            if (path === '/dynamicRichText.js' || path === '/dynamicRichText.css') return new Response(Bun.file(join(output, path.slice(1))));
            return new Response('<!doctype html><link rel="stylesheet" href="/dynamicRichText.css"><style>:root{--ink-soft:#222;--brand-deep:#222}</style><div id="app"></div><script type="module" src="/dynamicRichText.js"></script>', { headers: { 'Content-Type': 'text/html' } });
        } });
        browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH, headless: true });
        const page = await browser.newPage({ viewport: { width: 1050, height: 1000 } });
        const errors: string[] = [];
        page.on('pageerror', error => errors.push(error.message));
        const origin = server.url.href;
        const fresh = async () => {
            await page.goto(origin);
            const frame = await (await page.waitForSelector('#cmsPreview'))!.contentFrame();
            await frame!.waitForSelector('[data-rich]');
            await frame!.locator('[data-rich]').click();
            return frame!;
        };
        const select = async (frame: Frame, target = 'all') => frame.evaluate(target => {
            const host = document.querySelector<HTMLElement>('[data-rich]')!;
            host.focus();
            const range = document.createRange();
            if (target === 'token') range.selectNode(host.querySelector('[data-cms-variable="item.body"]')!);
            else { range.selectNodeContents(host); if (target === 'caret') range.collapse(true); }
            getSelection()!.removeAllRanges(); getSelection()!.addRange(range);
            (window as any).rich.rememberSelection();
        }, target);
        // Parent/iframe state updates are debounced by 50ms.
        await run({ page, fresh, select, settle: () => page.waitForTimeout(150) });
        expect(errors).toEqual([]);
    } finally {
        await browser?.close(); server?.stop(true); await rm(output, { recursive: true, force: true });
    }
}

/** Exercise the shared toolbar popup rather than bypassing it through DOM values. */
export async function chooseRichOption(frame: Frame, selector: string, value: string): Promise<void> {
    await frame.locator(selector).click();
    await frame.locator(`.rich-toolbar-popover button[data-value=${JSON.stringify(value)}]`).click();
}
