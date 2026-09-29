import { adminPath } from './adminPath';

/**
 * Ctrl/Cmd+click on package module chips toggles enable ↔ pause.
 */
export function registerPackageModuleToggle(): void {
    document.addEventListener(
        'click',
        (event: MouseEvent) => {
            void handleClick(event);
        },
        true,
    );
}

async function handleClick(event: MouseEvent): Promise<void> {
    if (event.button !== 0 || !(event.ctrlKey || event.metaKey)) {
        return;
    }

    const target = event.target;
    if (!(target instanceof Element)) {
        return;
    }

    const link = target.closest('a.package-row__mod[data-module-toggle]') as HTMLAnchorElement | null;
    if (!link || link.classList.contains('is-missing')) {
        return;
    }

    event.preventDefault();
    event.stopPropagation();

    if (link.dataset.busy === '1') {
        return;
    }

    const enabled = link.dataset.enabled === '1';
    const name = link.dataset.moduleName || link.dataset.moduleId || '';
    const actionUrl = enabled ? link.dataset.pauseUrl : link.dataset.enableUrl;
    if (!actionUrl) {
        return;
    }

    const title = enabled
        ? link.dataset.confirmPauseTitle || ''
        : link.dataset.confirmEnableTitle || '';
    const body = enabled
        ? (link.dataset.confirmPause || '').replace('%name%', name)
        : (link.dataset.confirmEnable || '').replace('%name%', name);

    const ok = await askConfirm({
        title: title || undefined,
        body: body || undefined,
        danger: enabled,
        confirmLabel: enabled
            ? link.dataset.confirmPauseLabel || undefined
            : link.dataset.confirmEnableLabel || undefined,
    });
    if (!ok) {
        return;
    }

    link.dataset.busy = '1';
    link.classList.add('is-busy');
    submitToggle(actionUrl, link.dataset.returnUrl || adminPath('/modules') + '#packages');
}

function submitToggle(actionUrl: string, returnUrl: string): void {
    const form = document.createElement('form');
    form.method = 'post';
    form.action = actionUrl;
    form.style.display = 'none';

    const csrf = document.createElement('input');
    csrf.type = 'hidden';
    csrf.name = '_csrf';
    csrf.value = window.__GEREQ_CSRF__ || '';
    form.appendChild(csrf);

    const ret = document.createElement('input');
    ret.type = 'hidden';
    ret.name = 'return';
    ret.value = returnUrl;
    form.appendChild(ret);

    document.body.appendChild(form);
    form.submit();
}

async function askConfirm(opts: {
    title?: string;
    body?: string;
    danger?: boolean;
    confirmLabel?: string;
}): Promise<boolean> {
    if (window.gereq?.confirm) {
        return window.gereq.confirm(opts);
    }

    return window.confirm(opts.body || opts.title || '');
}
