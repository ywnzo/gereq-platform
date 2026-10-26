<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Api;

use Gereq\Api\Http\ApiRateLimitMiddleware;
use Gereq\Config\Repository;
use Gereq\Security\RateLimiter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class AnalyticsRateLimitTest extends TestCase
{
    public function testReplayTrafficHasItsOwnBoundedBucketAndDoesNotBlockInquiries(): void
    {
        $directory = sys_get_temp_dir() . '/gereq-analytics-limit-' . bin2hex(random_bytes(6));
        $middleware = new ApiRateLimitMiddleware(new RateLimiter($directory), new Repository([
            'api' => ['rate_limit' => ['analytics' => 2, 'public_write' => 1]],
        ]));
        $next = static fn (): Response => new Response('ok');
        try {
            self::assertSame(200, $middleware->process(Request::create('/api/public/analytics/collect', 'POST'), $next)->getStatusCode());
            self::assertSame(200, $middleware->process(Request::create('/api/public/analytics/collect', 'POST'), $next)->getStatusCode());
            self::assertSame(429, $middleware->process(Request::create('/api/public/analytics/collect', 'POST'), $next)->getStatusCode());
            self::assertSame(200, $middleware->process(Request::create('/api/public/site/inquiries', 'POST'), $next)->getStatusCode());
            self::assertSame(429, $middleware->process(Request::create('/api/public/site/inquiries', 'POST'), $next)->getStatusCode());
        } finally {
            $files = glob($directory . '/*');
            foreach ($files === false ? [] : $files as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
