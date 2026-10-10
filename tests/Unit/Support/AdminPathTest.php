<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Support;

use Gereq\Http\Router;
use Gereq\Support\AdminPath;
use Gereq\Support\UrlPrefix;
use PHPUnit\Framework\TestCase;

final class AdminPathTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AdminPath::reset();
        UrlPrefix::reset();
        putenv('ADMIN_PATH');
        unset($_ENV['ADMIN_PATH'], $_SERVER['ADMIN_PATH']);
        putenv('APP_URL_PREFIX');
        unset($_ENV['APP_URL_PREFIX'], $_SERVER['APP_URL_PREFIX']);
    }

    protected function tearDown(): void
    {
        AdminPath::reset();
        UrlPrefix::reset();
        putenv('ADMIN_PATH');
        unset($_ENV['ADMIN_PATH'], $_SERVER['ADMIN_PATH']);
        putenv('APP_URL_PREFIX');
        unset($_ENV['APP_URL_PREFIX'], $_SERVER['APP_URL_PREFIX']);
        parent::tearDown();
    }

    public function testDefaultSegmentIsAdmin(): void
    {
        self::assertSame('admin', AdminPath::segment());
        self::assertSame('/admin', AdminPath::base());
        self::assertSame('/admin/users', AdminPath::path('users'));
        self::assertSame('/admin/users', AdminPath::rewrite('/admin/users'));
    }

    public function testCustomSegmentRewritesCanonicalPaths(): void
    {
        $_ENV['ADMIN_PATH'] = 'panel';
        AdminPath::reset();

        self::assertSame('panel', AdminPath::segment());
        self::assertSame('/panel', AdminPath::base());
        self::assertSame('/panel', AdminPath::rewrite('/admin'));
        self::assertSame('/panel/users', AdminPath::rewrite('/admin/users'));
        self::assertSame('/panel/users', AdminPath::path('/admin/users'));
        self::assertSame('/panel/users', AdminPath::path('users'));
        self::assertSame('/api/v1', AdminPath::rewrite('/api/v1'));
        self::assertTrue(AdminPath::matches('/panel/login'));
        self::assertFalse(AdminPath::matches('/admin/login'));
    }

    public function testSanitizeRejectsInvalidAndReserved(): void
    {
        self::assertSame('admin', AdminPath::sanitize(''));
        self::assertSame('admin', AdminPath::sanitize('api'));
        self::assertSame('admin', AdminPath::sanitize('foo bar'));
        self::assertSame('admin', AdminPath::sanitize('admin/nested'));
        self::assertSame('my-panel', AdminPath::sanitize('my-panel'));
        self::assertSame('foo/bar', AdminPath::sanitize('foo/bar'));
    }

    public function testUrlPrefixAppliesRewrite(): void
    {
        $_ENV['ADMIN_PATH'] = 'panel';
        AdminPath::reset();
        UrlPrefix::reset();

        self::assertSame('/panel/settings', UrlPrefix::path('/admin/settings'));
    }

    public function testRouterRewritesAdminGroup(): void
    {
        $_ENV['ADMIN_PATH'] = 'panel';
        AdminPath::reset();

        $router = new Router();
        $router->group('/admin', static function (Router $r): void {
            $r->get('/login', static fn () => 'ok')->name('admin.login');
        });

        $route = $router->all()[0];
        self::assertSame('/panel/login', $route->getPath());
    }
}
