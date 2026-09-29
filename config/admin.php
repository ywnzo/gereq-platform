<?php

declare(strict_types=1);

use Gereq\Support\AdminPath;

return [
    'brand' => 'GEREQ',
    'brand_sub' => 'Platform',
    'theme' => 'system',
    /**
     * Public URL path segment for the admin UI (no slashes).
     * Default "admin" → /admin/…; set ADMIN_PATH=panel → /panel/….
     * Modules may keep writing /admin/…; the router rewrites at register time.
     */
    'path' => AdminPath::sanitize(trim((string) env_value('ADMIN_PATH', AdminPath::CANONICAL), '/')),
];
