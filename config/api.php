<?php

declare(strict_types=1);

return [
    'version' => 'v1',
    'prefix' => '/api/v1',
    'public_key' => (string) env_value('API_PUBLIC_KEY', ''),
    'rate_limit' => [
        'standard' => (int) env_value('API_RATE_LIMIT', 600),
        'write' => (int) env_value('API_RATE_LIMIT_WRITE', 120),
        'store' => (int) env_value('API_RATE_LIMIT_STORE', 60),
        // Stricter defaults for /api/public/* (external FE)
        'public' => (int) env_value('API_RATE_LIMIT_PUBLIC', 120),
        'public_write' => (int) env_value('API_RATE_LIMIT_PUBLIC_WRITE', 30),
        'window' => 60,
        // Non-empty + matching X-Perf-Secret header skips rate limiting (load tests only).
        'perf_secret' => (string) env_value('API_PERF_SECRET', ''),
    ],
    'token_prefix_live' => 'grq_live_',
    'token_prefix_test' => 'grq_test_',
    'token_prefix_public' => 'grq_pub_',
    'webhooks' => [
        'allow_private_urls' => (bool) env_value('WEBHOOKS_ALLOW_PRIVATE_URLS', false),
        'timeout_seconds' => 10,
        'max_consecutive_failures' => (int) env_value('WEBHOOKS_MAX_FAILURES', 50),
        'replay_tolerance_seconds' => 300,
    ],
];
