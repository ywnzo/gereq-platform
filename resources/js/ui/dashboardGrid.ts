/**
 * Masonry layout dashboardu: buňky gridu dostanou `grid-row: span N` podle skutečné výšky
 * panelu, takže se karty neroztahují na výšku nejvyššího sousedu a kratší se doskládají
 * do volného místa (grid-auto-flow: dense).
 */
export function registerDashboardGrid(): void {
    const NARROW = '(max-width: 960px)';
    const FALLBACK_ROW = 8;
    const FALLBACK_GAP = 14;

    const px = (value: string, fallback: number): number => {
        const parsed = Number.parseFloat(value);

        return Number.isFinite(parsed) && parsed > 0 ? parsed : fallback;
    };

    const layout = (grid: HTMLElement): void => {
        const cells = Array.from(grid.querySelectorAll<HTMLElement>(':scope > .dash-cell'));
        if (window.matchMedia(NARROW).matches) {
            grid.classList.remove('is-masonry');
            cells.forEach((cell) => cell.style.removeProperty('grid-row-end'));

            return;
        }

        grid.classList.add('is-masonry');
        const styles = getComputedStyle(grid);
        const row = px(styles.gridAutoRows, FALLBACK_ROW);
        const gap = px(styles.rowGap, FALLBACK_GAP);

        cells.forEach((cell) => {
            const panel = cell.firstElementChild as HTMLElement | null;
            const height = panel ? panel.getBoundingClientRect().height : cell.scrollHeight;
            const span = Math.max(1, Math.ceil((height + gap) / (row + gap)));
            cell.style.gridRowEnd = `span ${span}`;
        });
    };

    const grids = (): HTMLElement[] => Array.from(document.querySelectorAll<HTMLElement>('.dash-grid'));

    let frame = 0;
    const schedule = (): void => {
        if (frame !== 0) {
            return;
        }
        frame = window.requestAnimationFrame(() => {
            frame = 0;
            grids().forEach(layout);
        });
    };

    const observe = (): void => {
        const found = grids();
        if (found.length === 0) {
            return;
        }
        found.forEach(layout);

        if (typeof ResizeObserver === 'undefined') {
            return;
        }
        const ro = new ResizeObserver(() => schedule());
        found.forEach((grid) => {
            grid.querySelectorAll<HTMLElement>(':scope > .dash-cell > *').forEach((panel) => ro.observe(panel));
        });

        // Ikonový font / grafy se dotahují později než DOMContentLoaded.
        window.addEventListener('load', schedule, { once: true });
        if ('fonts' in document) {
            document.fonts.ready.then(schedule).catch(() => undefined);
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', observe, { once: true });
    } else {
        observe();
    }

    window.addEventListener('resize', schedule);
}
