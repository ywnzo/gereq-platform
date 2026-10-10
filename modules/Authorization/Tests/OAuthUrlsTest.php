<?php

declare(strict_types=1);

namespace Modules\Authorization\Tests;

use Modules\Authorization\Application\OAuthUrls;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class OAuthUrlsTest extends TestCase
{
    private ?string $prevApp = null;
    private ?string $prevFe = null;

    protected function setUp(): void
    {
        $this->prevApp = $_ENV['APP_URL'] ?? null;
        $this->prevFe = $_ENV['FRONTEND_ORIGIN'] ?? null;
    }

    protected function tearDown(): void
    {
        $this->restore('APP_URL', $this->prevApp);
        $this->restore('FRONTEND_ORIGIN', $this->prevFe);
        parent::tearDown();
    }

    public function testPrefersHttpsAppUrlOverLoopbackRequest(): void
    {
        $this->setEnv('APP_URL', 'https://kdeaco.gereq-platform.dev');
        $this->setEnv('FRONTEND_ORIGIN', 'http://127.0.0.1:5155');
        $url = (new OAuthUrls())->callbackUrl(
            Request::create('http://127.0.0.1:8088/admin/authorization', 'GET'),
            'google',
        );
        self::assertSame('https://kdeaco.gereq-platform.dev/api/public/auth/google/callback', $url);
    }

    public function testSkipsLoopbackAppUrlWhenRequestIsPublic(): void
    {
        $this->setEnv('APP_URL', 'http://127.0.0.1:8088');
        $this->setEnv('FRONTEND_ORIGIN', 'http://127.0.0.1:5155');
        $url = (new OAuthUrls())->callbackUrl(
            Request::create('https://kdeaco.gereq-platform.dev/admin/authorization', 'GET'),
            'google',
        );
        self::assertSame('https://kdeaco.gereq-platform.dev/api/public/auth/google/callback', $url);
    }

    public function testFallsBackToHttpsFrontendWhenAppAndRequestAreLoopback(): void
    {
        $this->setEnv('APP_URL', 'http://127.0.0.1:8088');
        $this->setEnv('FRONTEND_ORIGIN', 'https://kdeaco.gereq-platform.dev,http://127.0.0.1:5155');
        $url = (new OAuthUrls())->callbackUrl(
            Request::create('http://127.0.0.1:8088/admin/authorization', 'GET'),
            'google',
        );
        self::assertSame('https://kdeaco.gereq-platform.dev/api/public/auth/google/callback', $url);
    }

    private function setEnv(string $key, string $value): void
    {
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    private function restore(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);

            return;
        }
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}
