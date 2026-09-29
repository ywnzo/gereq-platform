/** Resolve admin UI paths using <html data-admin-path> (ADMIN_PATH). */

export function adminBase(): string {
    const raw = document.documentElement.dataset.adminPath || '/admin';
    const base = raw.replace(/\/+$/, '');

    return base === '' ? '/admin' : base;
}

/**
 * Build an admin URL. Accepts "users", "/users", or canonical "/admin/users".
 * Also applies data-url-prefix when present (subdirectory deploys).
 */
export function adminPath(suffix = ''): string {
    const base = adminBase();
    let path: string;
    if (!suffix || suffix === '/') {
        path = base;
    } else if (suffix === '/admin' || suffix.startsWith('/admin/')) {
        path = base + suffix.slice('/admin'.length);
    } else {
        const clean = suffix.startsWith('/') ? suffix : `/${suffix}`;
        path = `${base}${clean}`;
    }

    const urlPrefix = (document.documentElement.dataset.urlPrefix || '').replace(/\/+$/, '');
    if (urlPrefix !== '' && !path.startsWith(urlPrefix + '/') && path !== urlPrefix) {
        return urlPrefix + path;
    }

    return path;
}
