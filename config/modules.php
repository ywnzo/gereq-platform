<?php

declare(strict_types=1);

return [
    'gitlab' => [
        'url' => rtrim((string) env_value('GITLAB_URL', 'https://git.gereq.cz'), '/'),
        'token' => (string) env_value('GITLAB_TOKEN', ''),
        'group' => (string) env_value('GITLAB_GROUP', 'gereq'),
        'repo_prefix' => (string) env_value('MODULE_REPO_PREFIX', 'module-'),
        'tags_cache_ttl' => (int) env_value('MODULE_TAGS_CACHE_TTL', 900),
    ],
    'versions_path' => 'storage/modules',
];
