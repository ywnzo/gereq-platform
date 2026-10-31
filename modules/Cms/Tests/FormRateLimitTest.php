<?php

declare(strict_types=1);

namespace Modules\Cms\Tests;

use Gereq\Security\RateLimiter;
use Gereq\Testing\TestCase;
use Modules\Cms\Application\FormRateLimit;

final class FormRateLimitTest extends TestCase
{
    public function testPublicIntakeFailsClosedWhenTheRateLimitStoreCannotBeUsed(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cms-rate-file-');
        try {
            // A regular file cannot be used as the limiter's storage directory.
            $limiter = new RateLimiter($path);
            self::assertFalse((new FormRateLimit($limiter))->allow('127.0.0.1'));
            self::assertFalse($limiter->hit('site:inquiry:127.0.0.1', 5, 600));
        } finally {
            unlink($path);
        }
    }
}
