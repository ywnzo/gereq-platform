<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Http;

use Gereq\Http\Storefront;
use Gereq\Support\AdminPath;
use PHPUnit\Framework\TestCase;

final class StorefrontAdminPathTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AdminPath::reset();
        putenv('ADMIN_PATH');
        unset($_ENV['ADMIN_PATH'], $_SERVER['ADMIN_PATH']);
    }

    protected function tearDown(): void
    {
        AdminPath::reset();
        putenv('ADMIN_PATH');
        unset($_ENV['ADMIN_PATH'], $_SERVER['ADMIN_PATH']);
        parent::tearDown();
    }

    public function testCanonicalAdminIsNotSpaWhenRelocated(): void
    {
        $_ENV['ADMIN_PATH'] = 'panel';
        AdminPath::reset();

        self::assertFalse(Storefront::isSpaPath('/admin'));
        self::assertFalse(Storefront::isSpaPath('/admin/login'));
        self::assertFalse(Storefront::isSpaPath('/panel'));
        self::assertFalse(Storefront::isSpaPath('/panel/users'));
        self::assertTrue(Storefront::isSpaPath('/shop'));
    }
}
