<?php

declare(strict_types=1);

return [
    'module.customer_uploads.description' => 'Session uploady zákazníků (fotky k produktu) s retention.',
    'uploads.settings.max_bytes' => 'Upload — max. velikost souboru (B)',
    'uploads.settings.session_ttl' => 'Upload — TTL session (s)',
    'uploads.settings.retention_days' => 'Upload — retence (dny)',
    'uploads.settings.max_bytes.help' => 'API /api/v1/uploads — výchozí 25 MB.',
    'uploads.settings.retention_days.help' => 'CLI: php gereq uploads:purge',
];
