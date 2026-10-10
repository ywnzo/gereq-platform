<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

final class ProductAdminApiRemovedTest extends TestCase
{
    public function testVueAdminApiControllerClassesAreGone(): void
    {
        self::assertFalse(
            class_exists(\Gereq\Admin\Http\Api\AdminAuthApiController::class, false),
            'AdminAuthApiController must remain removed with ProductAdmin SPA',
        );
        self::assertFalse(
            class_exists(\Gereq\Admin\Http\Api\AdminDashboardApiController::class, false),
            'AdminDashboardApiController must remain removed with ProductAdmin SPA',
        );

        $root = dirname(__DIR__, 3);
        self::assertFileDoesNotExist($root . '/src/Admin/Http/Api/AdminAuthApiController.php');
        self::assertFileDoesNotExist($root . '/src/Admin/Http/Api/AdminDashboardApiController.php');
    }

    public function testCoreRegistrarDoesNotRegisterVueAdminRoutes(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Foundation/AdminRoutesRegistrar.php');

        self::assertStringNotContainsString('/api/v1/admin/auth/login', $src);
        self::assertStringNotContainsString('/api/v1/admin/auth/me', $src);
        self::assertStringNotContainsString('/api/v1/admin/auth/logout', $src);
        self::assertStringNotContainsString('/api/v1/admin/dashboard', $src);
        self::assertStringNotContainsString('api.admin.auth.login', $src);
        self::assertStringNotContainsString('api.admin.dashboard', $src);
    }

    public function testSessionMiddlewareHasNoVueAdminAuthSpecialCase(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Http/Middleware/StartSessionMiddleware.php');

        self::assertStringNotContainsString('/api/v1/admin/auth/', $src);
    }
}
