type DashboardItem = { id: string; type: string; enabled: boolean; order: number; width: string };

export function registerDashboardEditor(): void {
    const root = document.querySelector<HTMLElement>('[data-dashboard-editor]');
    if (!root) return;
    const payload = root.querySelector<HTMLInputElement>('[data-dash-payload]');
    if (!payload) return;
    let items: DashboardItem[];
    try { items = JSON.parse(root.dataset.items || '[]') as DashboardItem[]; } catch { return; }
    if (!Array.isArray(items)) return;
    const byId = new Map(items.map((item) => [item.id, item]));
    const widgets = Array.from(root.querySelectorAll<HTMLElement>('[data-dash-widget]'));
    let dragging: HTMLElement | null = null;
    let category = 'all';
    const search = root.querySelector<HTMLInputElement>('[data-dash-search]');
    const preview = root.querySelector<HTMLElement>('[data-dash-widget-preview]');
    let hideTimer: ReturnType<typeof setTimeout> | undefined;

    const hidePreview = (): void => {
        if (hideTimer) clearTimeout(hideTimer);
        hideTimer = undefined;
        if (!preview) return;
        preview.hidden = true;
        preview.replaceChildren();
    };
    const scheduleHide = (): void => {
        if (hideTimer) clearTimeout(hideTimer);
        hideTimer = setTimeout(hidePreview, 200);
    };
    const showPreview = (button: HTMLButtonElement): void => {
        if (!preview || button.hidden) return;
        if (hideTimer) clearTimeout(hideTimer);
        hideTimer = undefined;
        const widget = widgets.find((element) => element.dataset.dashWidget === button.dataset.dashAdd);
        const content = widget?.querySelector<HTMLElement>(':scope > .dash-edit__preview, :scope > .dash-edit__cell > .dash-edit__preview, :scope > .dash-edit__cell > .panel');
        if (!content) return;
        const copy = content.cloneNode(true) as HTMLElement;
        copy.inert = true;
        copy.removeAttribute('id');
        copy.querySelectorAll('[data-dash-controls], [id]').forEach((element) => {
            if (element.hasAttribute('data-dash-controls')) element.remove();
            else element.removeAttribute('id');
        });
        copy.querySelectorAll<HTMLElement>('[data-dash-widget]').forEach((element) => {
            element.removeAttribute('draggable');
            element.removeAttribute('data-dash-widget');
            element.removeAttribute('data-dash-type');
        });
        const originalCanvases = content.querySelectorAll('canvas');
        copy.querySelectorAll('canvas').forEach((canvas, index) => {
            const original = originalCanvases[index];
            if (original && original.width && original.height) canvas.getContext('2d')?.drawImage(original, 0, 0);
        });
        preview.replaceChildren(copy);
        preview.hidden = false;
        const rect = button.getBoundingClientRect();
        const width = preview.offsetWidth;
        const below = Math.max(0, window.innerHeight - rect.bottom - 16);
        const above = Math.max(0, rect.top - 16);
        const placeBelow = below >= above;
        preview.style.maxHeight = `${Math.max(0, Math.min(window.innerHeight * 0.7, placeBelow ? below : above))}px`;
        const height = preview.offsetHeight;
        preview.style.left = `${Math.max(8, Math.min(rect.left, window.innerWidth - width - 8))}px`;
        preview.style.top = `${placeBelow ? rect.bottom + 8 : rect.top - height - 8}px`;
    };

    root.addEventListener('pointerover', (event) => {
        const button = event.target instanceof Element ? event.target.closest<HTMLButtonElement>('[data-dash-add]') : null;
        if (button && !(event.relatedTarget instanceof Node && button.contains(event.relatedTarget))) showPreview(button);
    });
    root.addEventListener('pointerout', (event) => {
        const button = event.target instanceof Element ? event.target.closest<HTMLButtonElement>('[data-dash-add]') : null;
        if (button && !(event.relatedTarget instanceof Node && button.contains(event.relatedTarget))) scheduleHide();
    });
    root.addEventListener('focusin', (event) => {
        if (event.target instanceof HTMLButtonElement && event.target.hasAttribute('data-dash-add')) showPreview(event.target);
    });
    root.addEventListener('focusout', (event) => {
        if (event.target instanceof HTMLButtonElement && event.target.hasAttribute('data-dash-add')) scheduleHide();
    });
    preview?.addEventListener('pointerenter', () => { if (hideTimer) clearTimeout(hideTimer); });
    preview?.addEventListener('pointerleave', scheduleHide);
    preview?.addEventListener('focusin', () => { if (hideTimer) clearTimeout(hideTimer); });
    preview?.addEventListener('focusout', scheduleHide);
    window.addEventListener('scroll', (event) => {
        if (event.target !== preview && !(event.target instanceof Node && preview?.contains(event.target))) hidePreview();
    }, true);

    const update = (): void => {
        hidePreview();
        widgets.forEach((widget) => {
            const item = byId.get(widget.dataset.dashWidget || '');
            if (!item) return;
            widget.hidden = !item.enabled;
            if (item.type === 'panel') {
                ['full', 'two_thirds', 'half', 'third'].forEach((width) => widget.classList.toggle(`dash-cell--${width}`, item.width === width));
            }
        });
        const query = (search?.value || '').trim().toLocaleLowerCase();
        let total = 0;
        root.querySelectorAll<HTMLElement>('[data-dash-category]').forEach((section) => {
            let visible = 0;
            section.querySelectorAll<HTMLButtonElement>('[data-dash-add]').forEach((button) => {
                const matches = (button.dataset.dashTitle || '').toLocaleLowerCase().includes(query);
                button.hidden = byId.get(button.dataset.dashAdd || '')?.enabled !== false || !matches;
                if (!button.hidden) visible++;
            });
            total += visible;
            const count = root.querySelector<HTMLElement>(`[data-dash-count="${section.dataset.dashCategory}"]`);
            if (count) count.textContent = String(visible);
            section.hidden = visible === 0 || (category !== 'all' && section.dataset.dashCategory !== category);
        });
        const totalCount = root.querySelector<HTMLElement>('[data-dash-count="all"]');
        if (totalCount) totalCount.textContent = String(total);
        const empty = root.querySelector<HTMLElement>('[data-dash-picker-empty]');
        if (empty) empty.hidden = Array.from(root.querySelectorAll<HTMLElement>('[data-dash-category]')).some((section) => !section.hidden);
        payload.value = JSON.stringify(items.map(({ id, enabled, order, width }) => ({ id, enabled, order, width })));
        window.dispatchEvent(new Event('resize'));
    };
    const reorder = (widget: HTMLElement, before: HTMLElement | null): void => {
        const parent = widget.parentElement;
        if (!parent || (before && before.parentElement !== parent)) return;
        parent.insertBefore(widget, before);
        Array.from(parent.children).forEach((element, index) => {
            if (!(element instanceof HTMLElement)) return;
            const item = byId.get(element.dataset.dashWidget || '');
            if (item) item.order = (index + 1) * 10;
        });
        update();
    };
    root.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof Element)) return;
        const filter = target.closest<HTMLButtonElement>('[data-dash-filter]');
        if (filter) {
            category = filter.dataset.dashFilter || 'all';
            root.querySelectorAll<HTMLButtonElement>('[data-dash-filter]').forEach((button) => {
                const active = button === filter;
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-pressed', String(active));
            });
            update();
            return;
        }
        const add = target.closest<HTMLButtonElement>('[data-dash-add]');
        if (add) {
            const item = byId.get(add.dataset.dashAdd || '');
            if (item) {
                item.enabled = true;
                if (item.type === 'shortcut') {
                    const shell = byId.get('core.shortcuts');
                    if (shell) shell.enabled = true;
                }
                update();
            }
            return;
        }
        const button = target.closest<HTMLButtonElement>('[data-dash-action]');
        const widget = button?.closest<HTMLElement>('[data-dash-widget]');
        if (!button || !widget) return;
        const item = byId.get(widget.dataset.dashWidget || '');
        if (!item) return;
        if (button.dataset.dashAction === 'remove') { item.enabled = false; update(); return; }
        const siblings = Array.from(widget.parentElement?.children || []).filter((element): element is HTMLElement =>
            element instanceof HTMLElement && element.dataset.dashType === item.type && !element.hidden);
        const index = siblings.indexOf(widget);
        if (button.dataset.dashAction === 'up' && index > 0) reorder(widget, siblings[index - 1]);
        if (button.dataset.dashAction === 'down' && index >= 0 && index < siblings.length - 1) reorder(widget, siblings[index + 1].nextElementSibling as HTMLElement | null);
    });
    search?.addEventListener('input', update);
    root.addEventListener('change', (event) => {
        const select = event.target;
        if (!(select instanceof HTMLSelectElement) || !select.hasAttribute('data-dash-width')) return;
        const widget = select.closest<HTMLElement>('[data-dash-widget]');
        const item = byId.get(widget?.dataset.dashWidget || '');
        if (item && ['full', 'two_thirds', 'half', 'third'].includes(select.value)) { item.width = select.value; update(); }
    });
    root.addEventListener('dragstart', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement) || !target.matches('[data-dash-widget]')) return;
        dragging = target;
        event.stopPropagation();
        if (event.dataTransfer) {
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', target.dataset.dashWidget || '');
            const rect = target.getBoundingClientRect();
            const ghost = document.createElement('div');
            const clone = target.cloneNode(true) as HTMLElement;
            ghost.className = 'dash-edit__drag-image';
            ghost.style.width = `${rect.width * 0.8}px`;
            ghost.style.height = `${rect.height * 0.8}px`;
            clone.style.width = `${rect.width}px`;
            clone.style.transform = 'scale(0.8)';
            clone.style.transformOrigin = 'top left';
            ghost.appendChild(clone);
            document.body.appendChild(ghost);
            event.dataTransfer.setDragImage(ghost, 16, 16);
            requestAnimationFrame(() => ghost.remove());
            target.classList.add('is-dragging');
        }
    });
    root.addEventListener('dragover', (event) => {
        const target = event.target;
        const over = target instanceof Element ? target.closest<HTMLElement>('[data-dash-widget]') : null;
        if (dragging && over && dragging !== over && dragging.parentElement === over.parentElement && dragging.dataset.dashType === over.dataset.dashType) event.preventDefault();
    });
    root.addEventListener('drop', (event) => {
        const target = event.target;
        const over = target instanceof Element ? target.closest<HTMLElement>('[data-dash-widget]') : null;
        if (dragging && over && dragging !== over && dragging.parentElement === over.parentElement && dragging.dataset.dashType === over.dataset.dashType) {
            event.preventDefault();
            event.stopPropagation();
            reorder(dragging, over);
        }
        dragging = null;
    });
    root.addEventListener('dragend', (event) => {
        if (event.target instanceof HTMLElement) event.target.classList.remove('is-dragging');
        dragging = null;
    });
    update();
}
