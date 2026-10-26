import { expect, test } from 'bun:test';
import { withCmsEditor } from './cmsBrowserHarness';

const browserTest = test.skipIf(process.env.CMS_EDITOR_BROWSER !== '1');

browserTest('link popover preserves styled variables, validates URLs and supports undo', async () => {
    await withCmsEditor(async ({ page, fresh, select, settle }) => {
        const frame = await fresh();
        await select(frame, 'token');
        await frame.getByTitle('Kurzíva', { exact: true }).click();
        await frame.getByTitle('Podtržené', { exact: true }).click(); await settle();
        const before = await page.evaluate(() => (window as any).builder.captureHistory());
        await frame.getByTitle('Odkaz', { exact: true }).click();
        const dialog = frame.getByRole('dialog', { name: 'Link', exact: true });
        await dialog.waitFor();
        expect(await dialog.getByLabel('URL', { exact: true }).inputValue()).toBe('https://');
        await frame.evaluate(() => document.documentElement.style.setProperty('--cms-chrome-brand', '#142332'));
        expect(await dialog.getByRole('button', { name: 'Apply', exact: true }).evaluate(button => {
            const luminance = (color: string) => {
                const rgb = color.match(/[0-9.]+/g)!.slice(0, 3).map(value => Number(value) / 255).map(value => value <= .04045 ? value / 12.92 : ((value + .055) / 1.055) ** 2.4);
                return rgb[0] * .2126 + rgb[1] * .7152 + rgb[2] * .0722;
            };
            const style = getComputedStyle(button);
            const a = luminance(style.color), b = luminance(style.backgroundColor);
            return (Math.max(a, b) + .05) / (Math.min(a, b) + .05);
        })).toBeGreaterThanOrEqual(4.5);
        expect(await dialog.getByLabel('Link text', { exact: true }).count()).toBe(0);
        await dialog.getByLabel('URL', { exact: true }).fill('javascript:alert(1)');
        await dialog.getByLabel('URL', { exact: true }).press('Enter');
        expect(await dialog.getByRole('alert').isVisible()).toBe(true);
        expect(await frame.locator('[data-cms-variable="item.body"]').evaluate(token => token.classList.contains('is-selected'))).toBe(true);
        expect(await page.evaluate(() => (window as any).builder.captureHistory())).toBe(before);
        await dialog.getByLabel('URL', { exact: true }).fill('https://example.test/review');
        await dialog.getByRole('button', { name: 'Apply', exact: true }).click(); await settle();
        const link = frame.locator('[data-rich] a');
        expect(await link.getAttribute('href')).toBe('https://example.test/review');
        expect(await link.locator('[data-cms-variable="item.body"]').count()).toBe(1);
        expect(await link.locator('[data-cms-variable]').evaluate(token => getComputedStyle(token).fontStyle)).toBe('italic');
        expect(await link.locator('[data-cms-variable]').evaluate(token => getComputedStyle(token).textDecorationLine)).toContain('underline');
        expect(await page.evaluate(() => (window as any).builder.selectedId)).toBe('body');
        await frame.locator('[data-rich]').press('Control+z'); await settle();
        expect(await frame.locator('[data-rich] a').count()).toBe(0);
        expect(await frame.locator('[data-cms-variable="item.body"]').count()).toBe(1);
        await frame.locator('[data-rich]').press('Control+Shift+z'); await settle();
        expect(await frame.locator('[data-rich] a').getAttribute('href')).toBe('https://example.test/review');
    });
}, 60_000);

browserTest('keyboard links insert text, edit existing targets, open and remove links', async () => {
    await withCmsEditor(async ({ fresh, select, settle }) => {
        const frame = await fresh(); await select(frame, 'caret');
        await frame.locator('[data-rich]').press('Control+k');
        let dialog = frame.getByRole('dialog', { name: 'Link', exact: true });
        await dialog.getByLabel('URL', { exact: true }).fill('/details');
        await dialog.getByLabel('Link text', { exact: true }).fill('<Details>');
        await dialog.getByLabel('Link text', { exact: true }).press('Enter'); await settle();
        expect(await frame.locator('[data-rich] a').innerText()).toBe('<Details>');
        expect(await frame.locator('[data-rich] details').count()).toBe(0);
        await frame.locator('[data-rich] a').click();
        await frame.getByTitle('Odkaz', { exact: true }).click();
        dialog = frame.getByRole('dialog', { name: 'Link', exact: true });
        expect(await dialog.getByLabel('URL', { exact: true }).inputValue()).toBe('/details');
        expect(await dialog.getByLabel('Link text', { exact: true }).count()).toBe(0);
        await dialog.getByLabel('URL', { exact: true }).fill('https://example.test/updated');
        await dialog.getByRole('button', { name: 'Apply', exact: true }).click(); await settle();
        expect(await frame.locator('[data-rich] a').getAttribute('href')).toBe('https://example.test/updated');
        expect(await frame.locator('[data-rich] a').innerText()).toBe('<Details>');
        await frame.locator('[data-rich] a').click();
        await frame.getByTitle('Odkaz', { exact: true }).click();
        await frame.evaluate(() => { (window as any).openedLinks = []; window.open = ((...args: unknown[]) => { (window as any).openedLinks.push(args); return null; }) as typeof window.open; });
        await dialog.getByRole('button', { name: 'Open link', exact: true }).click();
        expect(await frame.evaluate(() => (window as any).openedLinks)).toEqual([['https://example.test/updated', '_blank', 'noopener,noreferrer']]);
        await dialog.getByRole('button', { name: 'Remove link', exact: true }).click(); await settle();
        expect(await frame.locator('[data-rich] a').count()).toBe(0);
        expect(await frame.locator('[data-rich]').innerText()).toContain('<Details>');
    });
}, 60_000);

browserTest('canceling link editing preserves selection and leaves the page clean', async () => {
    await withCmsEditor(async ({ page, fresh, select, settle }) => {
        const frame = await fresh(); await select(frame, 'token');
        const before = await page.evaluate(() => (window as any).builder.captureHistory());
        await frame.locator('[data-rich]').press('Meta+k');
        const dialog = frame.getByRole('dialog', { name: 'Link', exact: true });
        await dialog.getByLabel('URL', { exact: true }).fill('https://example.test/cancel');
        await dialog.getByLabel('URL', { exact: true }).press('Escape'); await settle();
        expect(await dialog.count()).toBe(0);
        expect(await frame.evaluate(() => getSelection()!.toString())).toBe('{{ item.body }}');
        expect(await page.evaluate(() => (window as any).builder.captureHistory())).toBe(before);
        expect(await page.evaluate(() => (window as any).builder.dirty)).toBe(false);
    });
}, 60_000);

browserTest('pasting a URL links selected text and saves clean template markup', async () => {
    await withCmsEditor(async ({ page, fresh, select, settle }) => {
        const frame = await fresh(); await select(frame, 'token');
        await page.context().grantPermissions(['clipboard-read', 'clipboard-write'], { origin: new URL(page.url()).origin });
        await page.evaluate(() => navigator.clipboard.writeText('https://example.test/pasted'));
        await frame.locator('[data-rich]').press('Control+v'); await settle();
        expect(await frame.locator('[data-rich] a').getAttribute('href')).toBe('https://example.test/pasted');
        expect(await frame.locator('[data-rich] a [data-cms-variable="item.body"]').count()).toBe(1);
        expect(await frame.locator('[data-rich]').innerText()).toContain('Before {{page.slug}}');
        await page.getByRole('button', { name: 'Save fixture', exact: true }).click();
        await page.getByRole('button', { name: 'Saved fixture', exact: true }).waitFor();
        const saved = await page.evaluate(() => JSON.stringify((window as any).savedRequests));
        expect(saved).toContain('https://example.test/pasted');
        expect(saved).toContain('{{ item.body }}');
        expect(saved).not.toContain('data-cms-');
        expect(saved).not.toContain('\u200b');
    });
}, 60_000);

browserTest('link popover works outside dynamic templates and fits narrow viewports', async () => {
    await withCmsEditor(async ({ page, fresh, select, settle }) => {
        await fresh();
        await page.goto(new URL('?plain=1', page.url()).href);
        const frame = await (await page.waitForSelector('#cmsPreview'))!.contentFrame();
        await frame!.waitForSelector('[data-rich]'); await select(frame!, 'token');
        await page.setViewportSize({ width: 300, height: 650 }); await settle();
        await frame!.getByTitle('Odkaz', { exact: true }).click();
        const dialog = frame!.getByRole('dialog', { name: 'Link', exact: true });
        const bounds = await dialog.evaluate(panel => ({ left: panel.getBoundingClientRect().left, right: panel.getBoundingClientRect().right, viewport: innerWidth }));
        expect(bounds.left).toBeGreaterThanOrEqual(0);
        expect(bounds.right).toBeLessThanOrEqual(bounds.viewport);
        await dialog.getByLabel('URL', { exact: true }).fill('/ordinary-text');
        await dialog.getByRole('button', { name: 'Apply', exact: true }).click(); await settle();
        expect(await frame!.locator('[data-rich] a').getAttribute('href')).toBe('/ordinary-text');
        expect(await frame!.locator('[data-rich] a [data-cms-variable="item.body"]').count()).toBe(1);
        expect(await frame!.locator('.rich-toolbar').isVisible()).toBe(true);
        expect(await page.evaluate(() => (window as any).builder.selectedId)).toBe('body');
    });
}, 60_000);
