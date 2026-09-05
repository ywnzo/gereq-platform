<?php

declare(strict_types=1);

return [
    'uploads.widget.title' => 'Customer uploads',
    'uploads.widget.hint' => 'Uploaded customer files',
    'module.customer_uploads.description' => 'Customer session uploads (product photos) with retention.',
    'uploads.settings.max_bytes' => 'Upload — max file size (bytes)',
    'uploads.settings.session_ttl' => 'Upload — session TTL (seconds)',
    'uploads.settings.retention_days' => 'Upload — retention (days)',
    'uploads.settings.max_bytes.help' => 'API /api/v1/uploads — default 25 MB.',
    'uploads.settings.retention_days.help' => 'CLI: php gereq uploads:purge',
];
