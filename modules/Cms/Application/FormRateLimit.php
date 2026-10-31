<?php

declare(strict_types=1);

namespace Modules\Cms\Application;

use Gereq\Security\RateLimiter;

/** Serializes the shared file limiter's read/update sequence for public forms. */
final class FormRateLimit
{
    public function __construct(private readonly RateLimiter $limiter)
    {
    }

    public function allow(string $ip): bool
    {
        $lock = @fopen(sys_get_temp_dir() . '/gereq-cms-rate-' . substr(hash('sha256', __DIR__ . $ip), 0, 2) . '.lock', 'c');
        if ($lock === false) {
            return false;
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                return false;
            }
            return $this->limiter->hit('cms:form:' . $ip, 10, 600);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
