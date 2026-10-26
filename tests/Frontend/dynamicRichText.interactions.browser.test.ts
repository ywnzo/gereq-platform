import { expect, test } from 'bun:test';
import { withCmsEditor, chooseRichOption } from './cmsBrowserHarness';

const browserTest = test.skipIf(process.env.CMS_EDITOR_BROWSER !== '1');

browserTest('inspecting a variable leaves content and history clean', async () => {
    await withCmsEditor(async ({ page, fresh, settle }) => {
        const frame = await fresh();
        const before = await page.evaluate(() => (window as any).builder.captureHistory());
        await frame.locator('[data-cms-variable="item.body"]').click();
        await page.getByRole('button', { name: 'Save fixture', exact: true }).focus();
        await settle();
        expect(await page.evaluate(() => (window as any).builder.dirty)).toBe(false);
        expect(await page.evaluate(() => (window as any).builder.captureHistory())).toBe(before);
        await frame.locator('[data-rich]').press('End');
        await frame.locator('[data-rich]').press('x');
        await page.getByRole('button', { name: 'Save fixture', exact: true }).focus();
        await settle();
        expect(await page.evaluate(() => (window as any).builder.dirty)).toBe(true);
        expect(await page.evaluate(() => JSON.stringify((window as any).builder.blocks))).toContain('x');
    });
}, 60_000);

browserTest('mouse autocomplete preserves selected block and toolbar', async () => {
    await withCmsEditor(async ({ page, fresh, settle }) => {
        const frame = await fresh();
        await frame.locator('[data-rich]').fill('{{bo');
        await frame.locator('.cms-variable-autocomplete button').filter({ hasText: 'Text recenze' }).click();
        await settle();
        expect(await frame.locator('[data-cms-variable="item.body"]').count()).toBe(1);
        expect(await page.evaluate(() => (window as any).builder.selectedId)).toBe('body');
        expect(await frame.locator('.rich-toolbar').isVisible()).toBe(true);
        expect(await frame.evaluate(() => document.activeElement?.hasAttribute('data-rich'))).toBe(true);
        await frame.locator('[data-rich]').press('x'); await settle();
        expect(await frame.locator('[data-rich]').innerText()).toContain('{{item.body}} x');
    });
}, 60_000);

browserTest('toolbar displays mixed styles and grouped controls without changing size', async () => {
    await withCmsEditor(async ({ page, fresh, select, settle }) => {
        const frame = await fresh();
        await select(frame); await settle();
        expect(await frame.locator('.rich-toolbar__palette-toggle').evaluate(toggle => toggle.classList.contains('is-mixed'))).toBe(false);
        expect(await frame.locator('.rich-toolbar').evaluate(toolbar => {
            const text = document.querySelector('[data-rich]')!.getBoundingClientRect();
            const rect = toolbar.getBoundingClientRect();
            return rect.bottom <= text.top || rect.top >= text.bottom;
        })).toBe(true);
        await frame.evaluate(() => {
            const host = document.querySelector<HTMLElement>('[data-rich]')!;
            host.innerHTML = '<p><span style="font-weight:700;font-style:italic;font-size:20px;color:rgb(255,0,0)">{{item.body}}</span><span style="font-weight:400;font-style:normal;font-size:12px;color:rgb(0,0,255)"> normal text</span></p>';
            (window as any).rich.decorateVariableTokens(host);
            host.dispatchEvent(new InputEvent('input', { bubbles: true, inputType: 'insertText' }));
        });
        await select(frame); await settle();
        expect(await frame.getByTitle('Tučné', { exact: true }).getAttribute('aria-pressed')).toBe('mixed');
        expect(await frame.getByTitle('Kurzíva', { exact: true }).getAttribute('aria-pressed')).toBe('mixed');
        expect(await frame.locator('.rich-toolbar__select--weight').getAttribute('data-value')).toBe('__mixed__');
        expect(await frame.locator('.rich-toolbar__select--size').getAttribute('data-value')).toBe('__mixed__');
        expect(await frame.locator('.rich-toolbar__palette-toggle').getAttribute('aria-label')).toContain('Mixed');
        expect(await frame.locator('.rich-toolbar__more-toggle').count()).toBe(0);
        const height = await frame.locator('.rich-toolbar').evaluate(toolbar => toolbar.getBoundingClientRect().height);
        await frame.getByTitle('Alignment', { exact: true }).click(); await settle();
        expect(await frame.getByRole('menu', { name: 'Alignment', exact: true }).isVisible()).toBe(true);
        expect(await frame.locator('.rich-toolbar').evaluate(toolbar => toolbar.getBoundingClientRect().height)).toBe(height);
        expect(await frame.evaluate(() => getSelection()!.toString())).toContain('normal text');
        await frame.getByRole('menuitemradio').first().press('Escape'); await settle();
        expect(await frame.getByRole('menu').count()).toBe(0);
        expect(await frame.evaluate(() => document.activeElement?.hasAttribute('data-rich'))).toBe(true);
        await chooseRichOption(frame, '.rich-toolbar__select--weight', '500'); await settle();
        expect(await frame.locator('.rich-toolbar__select--weight').getAttribute('data-value')).toBe('500');
        expect(await frame.getByTitle('Tučné', { exact: true }).getAttribute('aria-pressed')).toBe('false');
        await frame.getByTitle('Zrušit formát', { exact: true }).click(); await settle();
        expect(await frame.locator('[data-cms-variable="item.body"]').count()).toBe(1);
        await page.setViewportSize({ width: 300, height: 700 }); await settle();
        const bounds = await frame.locator('.rich-toolbar').evaluate(toolbar => ({ left: toolbar.getBoundingClientRect().left, right: toolbar.getBoundingClientRect().right, viewport: innerWidth }));
        expect(bounds.left).toBeGreaterThanOrEqual(0);
        expect(bounds.right).toBeLessThanOrEqual(bounds.viewport);
        await frame.getByTitle('Lists & blocks', { exact: true }).click(); await settle();
        const menuBounds = await frame.getByRole('menu').evaluate(menu => ({ right: menu.getBoundingClientRect().right, viewport: innerWidth }));
        expect(menuBounds.right).toBeLessThanOrEqual(menuBounds.viewport);
    });
}, 60_000);
