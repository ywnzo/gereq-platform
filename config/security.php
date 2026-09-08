<?php

declare(strict_types=1);

return [
    'login_max_attempts' => 5,
    'login_decay_seconds' => 60,
    'password_min_length' => 10,
    'cookie_samesite' => 'lax',
    'mfa_challenge_ttl' => 600,
    'webauthn' => [
        'rp_name' => env_value('WEBAUTHN_RP_NAME', env_value('APP_NAME', 'GEREQ Platform')),
        /** Hostname only (no port). Empty = current request host. Must not be an IP. */
        'rp_id' => env_value('WEBAUTHN_RP_ID', ''),
        /** Suggested hosts entry for LAN IP (prefer *.localhost — secure over HTTP). */
        'local_hostname' => env_value('WEBAUTHN_LOCAL_HOSTNAME', ''),
    ],
];