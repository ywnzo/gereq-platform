import { expect, test } from 'bun:test';
import { withCmsEditor, chooseRichOption } from './cmsBrowserHarness';

const browserTest = test.skipIf(process.env.CMS_EDITOR_BROWSER !== '1');
browserTest('shared toolbar dropdowns apply size, weight, font and block type', async () => {
    await withCmsEditor(async ({ fresh, select, settle }) => {
        const frame = await fresh(); await select(frame, 'token');
        const variable = frame.locator('[data-cms-variable="item.body"]');
        expect(await frame.locator('.rich-toolbar select').count()).toBe(0);
        const change = async (name: string, value: string) => {
            const selector = `.rich-toolbar__select--${name}`;
            await chooseRichOption(frame, selector, value); await settle();
            expect(await frame.locator(selector).getAttribute('data-value')).toBe(value);
        };
        await change('weight', '500');
        expect(await variable.evaluate(element => getComputedStyle(element).fontWeight)).toBe('500');
        await change('font', 'Georgia, serif');
        expect(await variable.evaluate(element => getComputedStyle(element).fontFamily)).toContain('Georgia');
        await change('size', '1.6em');
        const size = await variable.evaluate(element => getComputedStyle(element).fontSize);
        await change('size', '0.85em');
        expect(parseFloat(await variable.evaluate(element => getComputedStyle(element).fontSize))).toBeLessThan(parseFloat(size));
        await change('style', 'h1');
        expect(await frame.locator('[data-rich] h1').count()).toBe(1);
        await change('style', 'p');
        expect(await frame.locator('[data-rich] h1').count()).toBe(0);
    });
}, 60_000);

browserTest('heading paragraph conversion and typography stay stable through live updates and saving', async () => {
    await withCmsEditor(async ({ page, fresh, select, settle }) => {
        await fresh(); await page.goto(new URL('?heading=1', page.url()).href);
        const frame = (await (await page.waitForSelector('#cmsPreview'))!.contentFrame())!;
        await frame.waitForSelector('[data-rich]'); await frame.locator('[data-rich]').click();
        expect(await frame.locator('[data-rich]').evaluate(element => element.tagName)).toBe('DIV');
        expect(await frame.locator('[data-rich] h2').count()).toBe(1);
        await select(frame);
        await chooseRichOption(frame, '.rich-toolbar__select--weight', '400'); await settle();
        expect(await frame.locator('[data-cms-variable]').evaluate(element => getComputedStyle(element).fontWeight)).toBe('400');
        await chooseRichOption(frame, '.rich-toolbar__select--font', 'Georgia, serif'); await settle();
        expect(await frame.locator('[data-cms-variable]').evaluate(element => getComputedStyle(element).fontFamily)).toContain('Georgia');
        let initialSize = "";
        for (let index = 0; index < 4; index++) {
            await select(frame);
            await chooseRichOption(frame, '.rich-toolbar__select--size', '1.35em'); await settle();
            const size = await frame.locator('[data-cms-variable]').evaluate(element => getComputedStyle(element).fontSize);
            if (index) expect(size).toBe(initialSize);
            initialSize = size;
        }
        const typeSizes: number[] = [];
        for (const type of ['h1', 'h2', 'h3', 'p']) {
            await select(frame);
            await chooseRichOption(frame, '.rich-toolbar__select--style', type); await settle();
            typeSizes.push(await frame.locator('[data-cms-variable]').evaluate(element => parseFloat(getComputedStyle(element).fontSize)));
        }
        expect(typeSizes[0]).toBeGreaterThan(typeSizes[1]);
        expect(typeSizes[1]).toBeGreaterThan(typeSizes[2]);
        expect(typeSizes[2]).toBeGreaterThan(typeSizes[3]);
        let paragraphSize = '';
        for (let index = 0; index < 5; index++) {
            await frame.locator('[data-rich]').press('Control+a');
            await chooseRichOption(frame, '.rich-toolbar__select--style', 'p'); await settle();
            const size = await frame.locator('[data-cms-variable]').evaluate(element => getComputedStyle(element).fontSize);
            if (index) expect(size).toBe(paragraphSize);
            paragraphSize = size;
            expect(await frame.locator('[data-rich] h1,[data-rich] h2,[data-rich] h3').count()).toBe(0);
            expect(await frame.locator('[data-rich] p p').count()).toBe(0);
        }
        await page.getByRole('button', { name: 'Save fixture', exact: true }).click();
        await page.getByRole('button', { name: 'Saved fixture', exact: true }).waitFor();
        await frame.goto(frame.url()); await frame.waitForSelector('[data-rich]'); await settle();
        expect(await frame.locator('[data-rich] h2').count()).toBe(0);
        expect(await frame.locator('[data-cms-variable]').evaluate(element => getComputedStyle(element).fontSize)).toBe(paragraphSize);
        expect(await frame.locator('.cms-dynamic__item--ghost h2').count()).toBe(0);
    });
}, 60_000);

browserTest('toolbar dropdowns share theme, support type-ahead and preserve selection on Escape', async () => {
    await withCmsEditor(async ({ page, fresh, select, settle }) => {
        const frame = await fresh(); await select(frame, 'token');
        await frame.locator('.rich-toolbar__select--font').press('ArrowDown');
        const menu = frame.getByRole('menu');
        expect(await menu.evaluate(panel => panel.matches(':popover-open'))).toBe(true);
        expect(await frame.locator('[role="menu"] button:focus').getAttribute('aria-checked')).toBe('true');
        const theme = await menu.evaluate(panel => ({ background: getComputedStyle(panel).backgroundColor, border: getComputedStyle(panel).borderRadius }));
        await frame.locator('[role="menu"] button:focus').press('Home');
        await frame.locator('[role="menu"] button:focus').press('g');
        expect(await frame.locator('[role="menu"] button:focus').innerText()).toBe('Georgia');
        await frame.locator('[role="menu"] button:focus').press('Enter'); await settle();
        expect(await frame.locator('[data-cms-variable="item.body"]').evaluate(token => getComputedStyle(token).fontFamily)).toContain('Georgia');
        expect(await frame.evaluate(() => getSelection()!.toString())).toBe('{{ item.body }}');
        const before = await page.evaluate(() => (window as any).builder.captureHistory());
        await frame.getByTitle('Alignment', { exact: true }).click();
        expect(await menu.evaluate(panel => ({ background: getComputedStyle(panel).backgroundColor, border: getComputedStyle(panel).borderRadius }))).toEqual(theme);
        await frame.locator('[role="menu"] button:focus').press('End');
        await frame.locator('[role="menu"] button:focus').press('Escape'); await settle();
        expect(await menu.count()).toBe(0);
        expect(await frame.evaluate(() => getSelection()!.toString())).toBe('{{ item.body }}');
        expect(await page.evaluate(() => (window as any).builder.captureHistory())).toBe(before);
    });
}, 60_000);

browserTest('heading type overrides whole-block size and undo restores both together', async () => {
    await withCmsEditor(async ({ page, fresh, select, settle }) => {
        await fresh(); await page.goto(new URL('?heading=1', page.url()).href);
        const frame = (await (await page.waitForSelector('#cmsPreview'))!.contentFrame())!;
        await frame.waitForSelector('[data-rich]'); await select(frame, 'caret');
        await chooseRichOption(frame, '.rich-toolbar__select--size', '1.6em'); await settle();
        const sized = await frame.locator('[data-cms-variable]').evaluate(token => getComputedStyle(token).fontSize);
        await select(frame);
        await chooseRichOption(frame, '.rich-toolbar__select--style', 'h1'); await settle();
        const heading = await frame.locator('[data-cms-variable]').evaluate(token => getComputedStyle(token).fontSize);
        expect(parseFloat(heading)).toBeGreaterThan(parseFloat(sized));
        await frame.locator('[data-rich]').press('Control+z'); await settle();
        expect(await frame.locator('[data-rich] h2').count()).toBe(1);
        expect(await frame.locator('[data-cms-variable]').evaluate(token => getComputedStyle(token).fontSize)).toBe(sized);
        await frame.locator('[data-rich]').press('Control+Shift+z'); await settle();
        expect(await frame.locator('[data-rich] h1').count()).toBe(1);
        expect(await frame.locator('[data-cms-variable]').evaluate(token => getComputedStyle(token).fontSize)).toBe(heading);
    });
}, 60_000);

browserTest('every font choice applies and remains selected for text runs and whole dynamic blocks', async () => {
    await withCmsEditor(async ({ fresh, select, settle }) => {
        for (const selection of ['token', 'caret']) {
            const frame = await fresh(); await select(frame, selection);
            const fonts = await frame.evaluate(() => (window as any).rich.FONTS as [string, string][]);
            for (const [value] of fonts.filter(([value]) => value)) {
                await chooseRichOption(frame, '.rich-toolbar__select--font', value); await settle();
                expect(await frame.locator('.rich-toolbar__select--font').getAttribute('data-value')).toBe(value);
                const preferred = value.split(',')[0].replace(/['"]/g, '').trim().toLowerCase();
                expect(await frame.locator('[data-cms-variable="item.body"]').evaluate(token => getComputedStyle(token).fontFamily.split(',')[0].replace(/['"]/g, '').trim().toLowerCase())).toBe(preferred);
            }
        }
    });
}, 60_000);
