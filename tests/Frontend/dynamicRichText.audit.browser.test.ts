import { expect, test } from 'bun:test';
import { withCmsEditor } from './cmsBrowserHarness';

const browserTest = test.skipIf(process.env.CMS_EDITOR_BROWSER !== '1');

browserTest('variable clipboard keeps formatting and excludes editor chrome', async () => {
    await withCmsEditor(async ({ page, fresh, select, settle }) => {
        const frame = await fresh();
        await page.context().grantPermissions(['clipboard-read', 'clipboard-write'], { origin: new URL(page.url()).origin });
        await frame.evaluate(() => {
            const host = document.querySelector<HTMLElement>('[data-rich]')!;
            host.innerHTML = '<p><em><u>Before {{item.body}}</u></em></p>';
            (window as any).rich.decorateVariableTokens(host);
            host.dispatchEvent(new Event('input', { bubbles: true }));
        }); await settle();
        await select(frame);
        await frame.locator('[data-rich]').press('Control+c');
        const text = await page.evaluate(() => navigator.clipboard.readText());
        expect(text).toContain('Before {{item.body}}');
        expect(text).not.toContain('\u200b');
        const html = await page.evaluate(async () => {
            const items = await navigator.clipboard.read();
            return (await items[0].getType('text/html')).text();
        });
        expect(html).not.toContain('data-cms-');
        await select(frame, 'token');
        await frame.locator('[data-rich]').press('Control+c');
        const selectedHtml = await page.evaluate(async () => (await (await navigator.clipboard.read())[0].getType('text/html')).text());
        expect(selectedHtml).toMatch(/font-style:\s*italic|<em|<i\b/);
        expect(selectedHtml).toMatch(/text-decoration[^;"<>]*underline|<u\b/);
        await select(frame);
        await frame.locator('[data-rich]').press('Control+x'); await settle();
        expect(await frame.locator('[data-cms-variable]').count()).toBe(0);
        await frame.locator('[data-rich]').press('Control+z'); await settle();
        expect(await frame.locator('[data-cms-variable="item.body"]').count()).toBe(1);
        await select(frame);
        await frame.locator('[data-rich]').press('Control+v'); await settle();
        expect(await frame.locator('[data-cms-variable="item.body"]').count()).toBe(1);
        expect(await frame.locator('[data-cms-variable="item.body"]').evaluate(token => getComputedStyle(token).fontStyle)).toBe('italic');
        expect(await frame.locator('[data-cms-variable="item.body"]').evaluate(token => getComputedStyle(token).textDecorationLine)).toContain('underline');
        expect(await frame.locator('[data-rich]').innerText()).toContain('{{item.body}}');
    });
}, 60_000);

browserTest('autocomplete preserves rich styles and remains inside narrow viewports', async () => {
    await withCmsEditor(async ({ page, fresh, settle }) => {
        let frame = await fresh();
        await frame.evaluate(() => {
            const host = document.querySelector<HTMLElement>('[data-rich]')!;
            host.innerHTML = '<p><em><u>{{bo</u></em></p>';
            const text = host.querySelector('u')!.firstChild!;
            host.focus();
            const range = document.createRange(); range.setStart(text, text.textContent!.length); range.collapse(true);
            getSelection()!.removeAllRanges(); getSelection()!.addRange(range);
            host.dispatchEvent(new InputEvent('input', { bubbles: true, inputType: 'insertText' }));
        });
        await frame.locator('.cms-variable-autocomplete button').filter({ hasText: 'Text recenze' }).click(); await settle();
        expect(await frame.locator('[data-cms-variable="item.body"]').evaluate(token => getComputedStyle(token).fontStyle)).toBe('italic');
        expect(await frame.locator('[data-cms-variable="item.body"]').evaluate(token => getComputedStyle(token).textDecorationLine)).toContain('underline');
        await page.setViewportSize({ width: 300, height: 600 });
        frame = await fresh();
        await frame.locator('[data-rich]').fill('{{');
        const popup = frame.locator('.cms-variable-autocomplete');
        await popup.waitFor({ state: 'visible' });
        const bounds = await popup.evaluate(element => ({ left: element.getBoundingClientRect().left, right: element.getBoundingClientRect().right, width: innerWidth }));
        expect(bounds.left).toBeGreaterThanOrEqual(0);
        expect(bounds.right).toBeLessThanOrEqual(bounds.width);
        await frame.locator('[data-rich]').press('Escape');
        expect(await popup.count()).toBe(0);
    });
}, 60_000);

browserTest('composition and typing do not break variable boundaries', async () => {
    await withCmsEditor(async ({ fresh, settle }) => {
        const frame = await fresh();
        await frame.locator('[data-rich]').fill('{{item.body}}'); await settle();
        expect(await frame.locator('[data-cms-variable="item.body"]').count()).toBe(1);
        await frame.locator('[data-cms-variable="item.body"]').click();
        await frame.locator('[data-rich]').press('ArrowRight');
        await frame.evaluate(() => {
            document.querySelector('[data-rich]')!.dispatchEvent(new KeyboardEvent('keydown', { key: 'Backspace', isComposing: true, bubbles: true }));
        }); await settle();
        expect(await frame.locator('[data-cms-variable="item.body"]').count()).toBe(1);
    });
}, 60_000);

browserTest('rich styles repeat live and survive undo, save and iframe reconnection', async () => {
    await withCmsEditor(async ({ page, fresh, settle }) => {
        const frame = await fresh();
        await frame.locator('[data-cms-variable="item.body"]').click();
        await frame.getByTitle('Podtržené', { exact: true }).click(); await settle();
        const decoration = () => frame.locator('[data-cms-variable="item.body"]').evaluate(token => getComputedStyle(token).textDecorationLine);
        expect(await decoration()).toContain('underline');
        const copies = await frame.locator('.cms-dynamic__item--ghost .cms-prose').evaluateAll(hosts => hosts.map(host => [...host.querySelectorAll('*')].some(element => getComputedStyle(element).textDecorationLine.includes('underline'))));
        expect(copies).toEqual([true, true]);
        await frame.locator('[data-rich]').press('Control+z'); await settle();
        expect(await decoration()).not.toContain('underline');
        await frame.locator('[data-rich]').press('Control+Shift+z'); await settle();
        expect(await decoration()).toContain('underline');
        await page.getByRole('button', { name: 'Save fixture', exact: true }).click();
        await page.getByRole('button', { name: 'Saved fixture', exact: true }).waitFor(); await settle();
        const saved = await page.evaluate(() => ({
            requests: (window as any).savedRequests,
            editing: (window as any).builder.templateOwner?.id,
            dirty: (window as any).builder.dirty,
        }));
        expect(saved.requests).toHaveLength(1);
        expect(saved.editing).toBe('reviews');
        expect(saved.dirty).toBe(false);
        expect(JSON.stringify(saved.requests)).not.toContain('data-cms-');
        expect(JSON.stringify(saved.requests)).not.toContain('\u200b');
        expect(JSON.stringify(saved.requests)).toContain('{{ item.body }}');
        expect(JSON.stringify(saved.requests)).toContain('{{page.slug}}');
        await frame.goto(frame.url());
        await frame.waitForSelector('[data-rich]'); await settle();
        expect(await decoration()).toContain('underline');
        expect(await page.evaluate(() => (window as any).builder.previewLoading)).toBe(false);
    });
}, 60_000);
