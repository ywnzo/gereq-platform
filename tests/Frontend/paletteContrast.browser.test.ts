import { expect, test } from 'bun:test';
import { chromium } from 'playwright-core';
import { readFile } from 'node:fs/promises';
import { resolve } from 'node:path';

const browserTest = test.skipIf(process.env.PALETTE_BROWSER !== '1');

browserTest('palette controls remain readable and retain accent borders', async () => {
    const root = resolve(import.meta.dir, '../..');
    const manifest = JSON.parse(await readFile(resolve(root, 'public/build/manifest.json'), 'utf8'));
    const adminCss = await readFile(resolve(root, 'public/build', manifest['resources/js/admin.ts'].css[0]), 'utf8');
    const storefrontRoot = resolve(root, '../front-vue/dist');
    const storefrontHtml = await readFile(resolve(storefrontRoot, 'index.html'), 'utf8');
    const storefrontAsset = storefrontHtml.match(/href="\/(assets\/[^" ]+\.css)"/)?.[1];
    if (!storefrontAsset) throw new Error('Build front-vue before running palette browser tests');
    const storefrontCss = await readFile(resolve(storefrontRoot, storefrontAsset), 'utf8');
    const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH, headless: true });
    try {
        const page = await browser.newPage();
        await page.setContent(`<style>${storefrontCss}</style>
            <div class="cms-page"><h1>Heading</h1><form class="cms-form">
            <input class="cms-form-field__control" value="Example">
            <button type="button" class="cms-form__submit cms-form__submit--primary">Submit</button>
            </form><div class="cms-border-secondary" style="border-width:2px;border-style:solid">Secondary</div>
            <div class="cms-border-accent" style="border-width:2px;border-style:solid">Accent</div></div>
            <footer class="footer-links"><a href="#">Link</a></footer>`);
        await page.evaluate(() => { document.documentElement.dataset.gereqPalette = '1'; });
        for (const theme of [
            { background: '#fff', text: '#000', surface: '#111', onSurface: '#fff', primary: '#fff', onPrimary: '#000' },
            { background: '#111', text: '#fff', surface: '#fff', onSurface: '#000', primary: '#111', onPrimary: '#fff' },
        ]) {
            await page.evaluate(theme => {
                const root = document.documentElement;
                for (const [name, value] of Object.entries({
                    '--black': theme.background, '--color-paper': theme.background, '--color-ink-900': theme.text,
                    '--surface': theme.surface, '--color-surface': theme.surface, '--color-on-surface': theme.onSurface,
                    '--color-brand-700': theme.primary, '--color-on-primary': theme.onPrimary,
                    '--color-secondary': '#f35', '--color-accent': '#18a',
                })) root.style.setProperty(name, value);
            }, theme);
            const colors = await page.evaluate(() => {
                const style = (selector: string) => getComputedStyle(document.querySelector(selector)!);
                return {
                    heading: style('h1').color, input: style('input').color, inputBg: style('input').backgroundColor,
                    submit: style('button').color, submitBg: style('button').backgroundColor,
                    secondary: style('.cms-border-secondary').borderTopColor, accent: style('.cms-border-accent').borderTopColor,
                    link: style('.footer-links a').color,
                };
            });
            expect(colors.heading).toBe(theme.text === '#000' ? 'rgb(0, 0, 0)' : 'rgb(255, 255, 255)');
            expect(colors.link).toBe(colors.heading);
            expect(colors.input).toBe(theme.onSurface === '#000' ? 'rgb(0, 0, 0)' : 'rgb(255, 255, 255)');
            expect(colors.inputBg).toBe(theme.surface === '#111' ? 'rgb(17, 17, 17)' : 'rgb(255, 255, 255)');
            expect(colors.submit).toBe(theme.onPrimary === '#000' ? 'rgb(0, 0, 0)' : 'rgb(255, 255, 255)');
            expect(colors.submitBg).toBe(theme.primary === '#111' ? 'rgb(17, 17, 17)' : 'rgb(255, 255, 255)');
            expect(colors.secondary).toBe('rgb(255, 51, 85)');
            expect(colors.accent).toBe('rgb(17, 136, 170)');
        }

        await page.setContent(`<style>${adminCss}\n* { transition: none !important; }</style>
            <button class="btn btn--primary">Primary</button><button class="btn btn--secondary">Secondary</button>
            <button class="btn btn--accent">Accent</button>`);
        await page.evaluate(() => {
            const root = document.documentElement;
            root.dataset.brandPalette = '1';
            root.style.setProperty('--primary', '#737373');
            root.style.setProperty('--primary-fg', '#fff');
            root.style.setProperty('--color-secondary', '#737373');
            root.style.setProperty('--color-secondary-content', '#fff');
            root.style.setProperty('--color-accent', '#737373');
            root.style.setProperty('--color-accent-content', '#fff');
        });
        for (const button of await page.locator('button').all()) {
            const before = await button.evaluate(el => getComputedStyle(el).backgroundColor);
            await button.hover();
            const hover = await button.evaluate(el => ({ background: getComputedStyle(el).backgroundColor, color: getComputedStyle(el).color }));
            expect(hover.background).toBe(before);
            expect(hover.color).toBe('rgb(255, 255, 255)');
        }
    } finally {
        await browser.close();
    }
});
