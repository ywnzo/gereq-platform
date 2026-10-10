<?php

declare(strict_types=1);

use Gereq\Support\AdminPath;

return [
    'brand' => 'GEREQ',
    'brand_sub' => 'Platform',
    'theme' => 'system',
    /**
     * Public URL path for the platform admin UI (one or more segments).
     * Default "admin" → /admin/…; e.g. gereq-platform/admin → /gereq-platform/admin/….
     * Modules may keep writing /admin/…; the router rewrites at register time.
     */
    'path' => AdminPath::sanitize(trim((string) env_value('ADMIN_PATH', AdminPath::CANONICAL), '/')),
];
