/**
 * Keeps filter drawer triggers as the first control in `.page-header .actions`.
 * Relocates any leftover `[data-drawer-open*="filter"]` buttons from `.toolbar__toggles`.
 */
export function registerPageHeaderFilters(): void {
    const relocate = (): void => {
        const buttons = Array.from(
            document.querySelectorAll<HTMLElement>('.toolbar__toggles [data-drawer-open]'),
        ).filter((btn) => /filter/i.test(btn.getAttribute('data-drawer-open') ?? ''));

        for (const btn of buttons) {
            const shell = btn.closest('.list-shell') ?? btn.closest('main.page') ?? document.body;
            const header =
                shell.querySelector<HTMLElement>(':scope > .page-header') ??
                document.querySelector<HTMLElement>('main.page > .page-header, main.page > .list-shell > .page-header');
            if (!header) {
                continue;
            }

            let actions = header.querySelector<HTMLElement>(':scope > .actions');
            if (!actions) {
                actions = document.createElement('div');
                actions.className = 'actions';
                header.appendChild(actions);
            }

            const toggles = btn.parentElement;
            actions.insertBefore(btn, actions.firstChild);

            if (
                toggles instanceof HTMLElement &&
                toggles.classList.contains('toolbar__toggles') &&
                toggles.childElementCount === 0
            ) {
                toggles.remove();
            }
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', relocate, { once: true });
    } else {
        relocate();
    }
}
