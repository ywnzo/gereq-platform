/**
 * CSP blocks inline onchange="this.form.submit()" on toolbar filter selects.
 * Listen for native change and submit GET toolbar forms (and data-autosubmit controls).
 */
export function registerToolbarAutosubmit(): void {
    document.addEventListener('change', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLSelectElement) && !(target instanceof HTMLInputElement)) {
            return;
        }

        if (target instanceof HTMLInputElement && target.type !== 'date') {
            return;
        }

        const form = resolveAutosubmitForm(target);
        if (!form) {
            return;
        }

        window.gereq?.pageProgress?.start?.();
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
        } else {
            form.submit();
        }
    });
}

function resolveAutosubmitForm(control: HTMLSelectElement | HTMLInputElement): HTMLFormElement | null {
    if (control.dataset.autosubmit === '1' || control.dataset.autosubmit === 'true') {
        return control.form;
    }

    const form = control.closest('form.toolbar');
    if (!(form instanceof HTMLFormElement)) {
        return null;
    }

    const method = (form.getAttribute('method') || 'get').toLowerCase();
    if (method !== 'get') {
        return null;
    }

    return form;
}
