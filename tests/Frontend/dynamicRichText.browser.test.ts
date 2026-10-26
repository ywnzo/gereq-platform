import { expect, test } from 'bun:test';
import { withCmsEditor, chooseRichOption } from './cmsBrowserHarness';

// CMS_EDITOR_BROWSER=1 bun test tests/Frontend/dynamicRichText.browser.test.ts
// Set CHROMIUM_PATH when Chromium is installed outside Playwright's cache.
test.skipIf(process.env.CMS_EDITOR_BROWSER !== '1')('dynamic rich editor preserves variable layout, styles and toolbar state', async () => {
    await withCmsEditor(async ({ page, fresh, select, settle }) => {
        let frame = await fresh();
        expect(await frame.locator('[data-cms-variable]').evaluateAll(tokens => tokens.map(token => token.getAttribute('title')))).toEqual(['Adresa stránky', 'Text recenze']);
        await select(frame);
        for (const label of ['Tučné', 'Kurzíva', 'Podtržené', 'Přeškrtnuté']) {
            await frame.getByTitle(label, { exact: true }).click(); await settle();
            expect(await frame.getByTitle(label, { exact: true }).getAttribute('aria-pressed')).toBe('true');
        }
        await chooseRichOption(frame, '.rich-toolbar__select--size', '1.35em'); await settle();
        const initialSize = await frame.locator('[data-cms-variable="item.body"]').evaluate(token => getComputedStyle(token).fontSize);
        await chooseRichOption(frame, '.rich-toolbar__select--size', '1.6em'); await settle();
        await chooseRichOption(frame, '.rich-toolbar__select--size', '1.35em'); await settle();
        expect(await frame.locator('[data-cms-variable="item.body"]').evaluate(token => getComputedStyle(token).fontSize)).toBe(initialSize);
        await chooseRichOption(frame, '.rich-toolbar__select--weight', '500'); await settle();
        await frame.evaluate(() => (window as any).rich.createRichActions(() => {}).onColor('#dd2277')); await settle();
        expect(await frame.locator('[data-cms-variable]').evaluateAll(tokens => tokens.map(token => {
            const style = getComputedStyle(token);
            return { weight: style.fontWeight, italic: style.fontStyle, color: style.color };
        }))).toEqual(Array(2).fill({ weight: '500', italic: 'italic', color: 'rgb(221, 34, 119)' }));
        expect(await frame.locator('.rich-toolbar__select--weight').getAttribute('data-value')).toBe('500');
        expect(await frame.locator('.rich-toolbar__select--size').getAttribute('data-value')).toBe('1.35em');
        for (const label of ['Kurzíva', 'Podtržené', 'Přeškrtnuté']) {
            await frame.getByTitle(label, { exact: true }).click(); await settle();
            expect(await frame.getByTitle(label, { exact: true }).getAttribute('aria-pressed')).toBe('false');
        }
        frame = await fresh(); await select(frame, 'caret');
        await frame.getByTitle('Tučné', { exact: true }).click(); await settle();
        await chooseRichOption(frame, '.rich-toolbar__select--size', '1.35em'); await settle();
        expect(await frame.getByTitle('Tučné', { exact: true }).getAttribute('aria-pressed')).toBe('true');
        expect(await frame.locator('[data-cms-variable="item.body"]').evaluate(token => getComputedStyle(token).fontWeight)).toBe('700');
        frame = await fresh(); await select(frame);
        await chooseRichOption(frame, '.rich-toolbar__select--size', '1.35em'); await settle();
        await select(frame, 'token');
        await chooseRichOption(frame, '.rich-toolbar__select--size', '1.6em'); await settle();
        const sizes = await frame.locator('[data-rich]').evaluate(host => {
            const global = host.querySelector('[data-cms-variable="page.slug"]')!;
            const item = host.querySelector('[data-cms-variable="item.body"]')!;
            return [getComputedStyle(global).fontSize, getComputedStyle(item).fontSize, getComputedStyle(global.parentElement!).fontSize];
        });
        expect(sizes[0]).toBe(sizes[2]); expect(sizes[1]).not.toBe(sizes[0]);
        // A selection immediately after another variable must not include that variable.
        frame = await fresh();
        await frame.evaluate(() => {
            const host = document.querySelector<HTMLElement>('[data-rich]')!;
            host.innerHTML = '<p>{{page.slug}}{{item.body}}</p>';
            (window as any).rich.decorateVariableTokens(host);
        });
        await select(frame, 'token');
        await chooseRichOption(frame, '.rich-toolbar__select--size', '1.6em'); await settle();
        const adjacentSizes = await frame.locator('[data-cms-variable]').evaluateAll(tokens => tokens.map(token => getComputedStyle(token).fontSize));
        expect(adjacentSizes[0]).not.toBe(adjacentSizes[1]);
        // Existing semantic bold must yield to the selected weight without losing tokens.
        frame = await fresh();
        await frame.evaluate(() => {
            const host = document.querySelector<HTMLElement>('[data-rich]')!;
            host.innerHTML = '<p><strong>Bold {{item.body}}</strong></p>';
            (window as any).rich.decorateVariableTokens(host);
        });
        await select(frame);
        await chooseRichOption(frame, '.rich-toolbar__select--weight', '400'); await settle();
        expect(await frame.locator('[data-cms-variable="item.body"]').evaluate(token => getComputedStyle(token).fontWeight)).toBe('400');
        frame = await fresh();
        const bounds = await frame.evaluate(() => {
            const host = document.querySelector<HTMLElement>('[data-rich]')!;
            host.style.width = '100px';
            host.innerHTML = '<p>{{item.a_very_very_very_very_very_long_field_name}}</p>';
            (window as any).rich.decorateVariableTokens(host);
            return [host.clientWidth, host.scrollWidth, host.querySelector('[data-cms-variable]')!.getBoundingClientRect().width];
        });
        expect(bounds[1]).toBeLessThanOrEqual(bounds[0]); expect(bounds[2]).toBeLessThanOrEqual(bounds[0]);
        frame = await fresh();
        await frame.locator('[data-cms-variable="item.body"]').click(); await settle();
        expect(await frame.evaluate(() => getSelection()!.toString())).toBe('{{ item.body }}');
        expect(await frame.evaluate(() => document.activeElement?.hasAttribute('data-rich'))).toBe(true);
        expect(await frame.locator('[data-cms-variable="item.body"]').evaluate(token => ({
            selected: token.classList.contains('is-selected'),
            outline: getComputedStyle(token).outlineStyle,
            textSelection: getComputedStyle(token, '::selection').backgroundColor,
        }))).toEqual({ selected: true, outline: 'none', textSelection: 'rgba(0, 0, 0, 0)' });
        await frame.getByTitle('Tučné', { exact: true }).click(); await settle();
        expect(await frame.locator('[data-cms-variable="item.body"]').evaluate(token => getComputedStyle(token).fontWeight)).toBe('700');
        expect(await frame.locator('[data-cms-variable="page.slug"]').evaluate(token => getComputedStyle(token).fontWeight)).not.toBe('700');
        expect(await frame.locator('[data-cms-variable="item.body"]').evaluate(token => token.classList.contains('is-selected'))).toBe(true);
        expect(await frame.evaluate(() => getSelection()!.toString())).toBe('{{ item.body }}');
        await frame.locator('[data-cms-variable="page.slug"]').click(); await settle();
        expect(await frame.locator('[data-cms-variable="item.body"].is-selected').count()).toBe(0);
        expect(await frame.locator('[data-cms-variable="page.slug"].is-selected').count()).toBe(1);
        await select(frame, 'caret'); await settle();
        expect(await frame.locator('.cms-variable-token.is-selected').count()).toBe(0);
        await select(frame); await settle();
        expect(await frame.locator('.cms-variable-token.is-selected').count()).toBe(2);
        expect(await frame.locator('.cms-variable-token').evaluateAll(tokens => tokens.map(token => getComputedStyle(token, '::selection').backgroundColor))).toEqual(['rgba(0, 0, 0, 0)', 'rgba(0, 0, 0, 0)']);
        expect(await frame.evaluate(() => getSelection()!.toString())).toContain('Before');
        await select(frame, 'caret'); await settle();
        expect(await frame.locator('.cms-variable-token.is-selected').count()).toBe(0);
        frame = await fresh();
        await frame.locator('[data-rich]').fill('{{bo');
        const suggestions = frame.locator('.cms-variable-autocomplete');
        await suggestions.waitFor({ state: 'visible' });
        expect(await suggestions.evaluate(popup => popup.matches(':popover-open'))).toBe(true);
        expect(await suggestions.evaluate(popup => {
            const rect = popup.getBoundingClientRect();
            return popup.contains(document.elementFromPoint(rect.left + 15, rect.top + 15));
        })).toBe(true);
        await suggestions.locator('button').filter({ hasText: 'Text recenze' }).click();
        expect(await frame.locator('[data-rich] [data-cms-variable="item.body"]').count()).toBe(1);
        expect(await suggestions.count()).toBe(0);
        frame = await fresh();
        const caretGaps = await frame.evaluate(() => {
            const host = document.querySelector<HTMLElement>('[data-rich]')!;
            host.innerHTML = '<p>a{{item.body}}b</p>';
            (window as any).rich.decorateVariableTokens(host);
            const token = host.querySelector('[data-cms-variable]')!;
            const before = document.createRange();
            before.setStart(token.previousSibling!, 1); before.collapse(true);
            const after = document.createRange();
            after.setStart(token.nextSibling!, 0); after.collapse(true);
            const rect = token.getBoundingClientRect();
            return [rect.left - before.getBoundingClientRect().right, after.getBoundingClientRect().left - rect.right];
        });
        expect(await frame.locator('[data-cms-variable="item.body"]').evaluate(token => getComputedStyle(token).position)).toBe('static');
        expect(caretGaps[0]).toBeGreaterThanOrEqual(1);
        expect(caretGaps[1]).toBeGreaterThanOrEqual(1);
        for (const source of [
            '<p>Before {{item.body}} after</p>',
            '<p>{{item.body}}</p>',
            '<p>Before <strong>{{item.body}}</strong> after</p>',
            '<p>Before {{item.body}}{{page.slug}}</p>',
        ]) {
            frame = await fresh();
            await frame.evaluate(source => {
                const host = document.querySelector<HTMLElement>('[data-rich]')!;
                host.innerHTML = source;
                (window as any).rich.decorateVariableTokens(host);
                host.dispatchEvent(new Event('input', { bubbles: true }));
            }, source);
            await settle();
            await frame.evaluate(() => {
                const host = document.querySelector<HTMLElement>('[data-rich]')!;
                host.focus();
                const range = document.createRange();
                range.setStartBefore(host.querySelector('[data-cms-variable]')!); range.collapse(true);
                getSelection()!.removeAllRanges(); getSelection()!.addRange(range);
            });
            await frame.locator('[data-rich]').press('Enter'); await settle();
            expect(await frame.locator('[data-rich] > p').count()).toBe(2);
            expect(await frame.locator('[data-rich] > p').nth(1).locator('br').count()).toBe(0);
            expect(await frame.evaluate(() => {
                const anchor = getSelection()!.anchorNode!;
                const element = anchor instanceof Element ? anchor : anchor.parentElement!;
                return element.closest('p') === document.querySelectorAll('[data-rich] > p')[1];
            })).toBe(true);
            await frame.locator('[data-rich]').press('x'); await settle();
            expect(await frame.locator('[data-rich] > p').nth(1).textContent()).toStartWith('x{{item.body}}');
            expect(await frame.locator('[data-rich] > p').nth(0).textContent()).not.toContain('x');
        }
        frame = await fresh();
        await frame.evaluate(() => {
            const host = document.querySelector<HTMLElement>('[data-rich]')!;
            host.innerHTML = '<p>{{item.body}}</p>';
            (window as any).rich.decorateVariableTokens(host);
            host.dispatchEvent(new Event('input', { bubbles: true }));
        }); await settle();
        await frame.locator('[data-cms-variable="item.body"]').click();
        await frame.locator('[data-rich]').press('ArrowRight'); await settle();
        expect(await frame.evaluate(() => [...getSelection()!.getRangeAt(0).getClientRects()].some(rect => rect.height > 0))).toBe(true);
        await frame.locator('[data-rich]').press('x'); await settle();
        expect(await frame.locator('[data-rich]').evaluate(host => {
            const copy = host.cloneNode(true) as HTMLElement;
            (window as any).rich.unwrapVariableTokens(copy);
            return copy.textContent;
        })).toBe('{{item.body}}x');
        const persisted = await page.evaluate(() => JSON.stringify((window as any).builder.blocks));
        expect(persisted).not.toContain('data-cms-caret');
        expect(persisted).not.toContain('\u200b');
        // Restoring a trailing-variable caret must land in editable text as well.
        await frame.evaluate(() => {
            const host = document.querySelector<HTMLElement>('[data-rich]')!;
            host.innerHTML = '<p>{{item.body}}</p>';
            (window as any).rich.decorateVariableTokens(host);
            const token = host.querySelector('[data-cms-variable]')!;
            const range = document.createRange(); range.setStartAfter(token); range.collapse(true);
            getSelection()!.removeAllRanges(); getSelection()!.addRange(range);
            const restore = (window as any).rich.captureVariableSelection(host);
            host.innerHTML = host.innerHTML;
            restore(); (window as any).rich.rememberSelection();
        });
        expect(await frame.evaluate(() => [...getSelection()!.getRangeAt(0).getClientRects()].some(rect => rect.height > 0))).toBe(true);
        await frame.locator('[data-rich]').press('Backspace'); await settle();
        expect(await frame.locator('[data-rich] [data-cms-variable]').count()).toBe(0);
        for (const source of ['<p><strong>{{item.body}}</strong></p>', '<p>{{item.body}}{{page.slug}}</p>']) {
            frame = await fresh();
            await frame.evaluate(source => {
                const host = document.querySelector<HTMLElement>('[data-rich]')!;
                host.innerHTML = source;
                (window as any).rich.decorateVariableTokens(host);
                host.dispatchEvent(new Event('input', { bubbles: true }));
            }, source); await settle();
            await frame.locator('[data-cms-variable="item.body"]').click();
            await frame.locator('[data-rich]').press('ArrowRight'); await settle();
            expect(await frame.evaluate(() => [...getSelection()!.getRangeAt(0).getClientRects()].some(rect => rect.height > 0))).toBe(true);
            await frame.locator('[data-rich]').press('Enter'); await settle();
            expect(await frame.locator('[data-rich] > p').count()).toBe(2);
            await frame.locator('[data-rich]').press('x'); await settle();
            expect(await frame.locator('[data-rich] > p').nth(1).textContent()).toStartWith('x');
        }
        // Exact live-editor shape: nested formatting, NBSP between pills, trailing space.
        frame = await fresh();
        await frame.evaluate(() => {
            const host = document.querySelector<HTMLElement>('[data-rich]')!;
            host.innerHTML = '<div style="text-align:left"><span style="background-color:transparent">{{ item.body }}&nbsp;{{page.slug}} </span></div>';
            (window as any).rich.decorateVariableTokens(host);
            host.dispatchEvent(new Event('input', { bubbles: true }));
        }); await settle();
        await frame.locator('[data-rich]').focus();
        await frame.locator('[data-rich]').press('End'); await settle();
        expect(await frame.evaluate(() => [...getSelection()!.getRangeAt(0).getClientRects()].some(rect => rect.height > 0))).toBe(true);
        await frame.locator('[data-rich]').press('ArrowLeft'); await settle();
        expect(await frame.evaluate(() => [...getSelection()!.getRangeAt(0).getClientRects()].some(rect => rect.height > 0))).toBe(true);
        // Removing a real trailing space must repair the anchor without a rerender.
        frame = await fresh();
        await frame.evaluate(() => {
            const host = document.querySelector<HTMLElement>('[data-rich]')!;
            host.innerHTML = '<div style="text-align:left"><span style="background-color:transparent">{{item.body}}&nbsp;{{page.slug}} </span></div>';
            (window as any).rich.decorateVariableTokens(host);
            host.dispatchEvent(new Event('input', { bubbles: true }));
        }); await settle();
        await frame.locator('[data-rich]').focus();
        await frame.locator('[data-rich]').press('End');
        await frame.locator('[data-rich]').press('Backspace'); await settle();
        expect(await frame.locator('[data-cms-variable="page.slug"] + [data-cms-caret]').count()).toBe(1);
        expect(await frame.evaluate(() => [...getSelection()!.getRangeAt(0).getClientRects()].some(rect => rect.height > 0))).toBe(true);
        await frame.locator('[data-rich]').press('End'); await settle();
        expect(await frame.evaluate(() => [...getSelection()!.getRangeAt(0).getClientRects()].some(rect => rect.height > 0))).toBe(true);
        await frame.locator('[data-rich]').press('x'); await settle();
        expect(await frame.locator('[data-rich]').evaluate(host => {
            const copy = host.cloneNode(true) as HTMLElement;
            (window as any).rich.unwrapVariableTokens(copy);
            return copy.textContent;
        })).toBe('{{item.body}}\u00a0{{page.slug}}x');
        frame = await fresh();
        await frame.locator('[data-cms-variable="item.body"]').click();
        await frame.getByTitle('Kurzíva', { exact: true }).click(); await settle();
        expect(await frame.locator('[data-cms-variable="item.body"]').evaluate(token => getComputedStyle(token).fontStyle)).toBe('italic');
        await frame.getByTitle('Podtržené', { exact: true }).click(); await settle();
        expect(await frame.locator('[data-cms-variable="item.body"]').evaluate(token => getComputedStyle(token).textDecorationLine)).toContain('underline');
        frame = await fresh(); await select(frame, 'caret');
        await frame.getByTitle('Kurzíva', { exact: true }).click(); await settle();
        expect(await frame.locator('[data-cms-variable="item.body"]').evaluate(token => getComputedStyle(token).fontStyle)).toBe('italic');
        expect(await frame.getByTitle('Kurzíva', { exact: true }).getAttribute('aria-pressed')).toBe('true');
        await frame.getByTitle('Podtržené', { exact: true }).click(); await settle();
        await frame.getByTitle('Přeškrtnuté', { exact: true }).click(); await settle();
        expect(await frame.locator('[data-cms-variable="item.body"]').evaluate(token => getComputedStyle(token).textDecorationLine)).toBe('underline line-through');
        await frame.locator('[data-rich]').press('Control+i'); await settle();
        expect(await frame.locator('[data-cms-variable="item.body"]').evaluate(token => getComputedStyle(token).fontStyle)).toBe('normal');

    });
}, 60_000);
