<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Api;

use Gereq\Api\Http\ApiAuthMiddleware;
use Gereq\User\Entity\User;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class ApiAuthSessionAllowsTest extends TestCase
{
    public function testCrmDealsReadMapsToCrmView(): void
    {
        $user = $this->createMock(User::class);
        $user->method('can')->willReturnCallback(static fn (string $perm): bool => $perm === 'crm.view');

        $middleware = (new ReflectionClass(ApiAuthMiddleware::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(ApiAuthMiddleware::class, 'sessionAllows');

        self::assertTrue($method->invoke($middleware, $user, ['crm.deals.read'], null));
        self::assertFalse($method->invoke($middleware, $user, ['crm.deals.write'], null));
    }

    public function testCrmDealsWriteMapsToManage(): void
    {
        $user = $this->createMock(User::class);
        $user->method('can')->willReturnCallback(static fn (string $perm): bool => $perm === 'crm.deals.manage');

        $middleware = (new ReflectionClass(ApiAuthMiddleware::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(ApiAuthMiddleware::class, 'sessionAllows');

        self::assertTrue($method->invoke($middleware, $user, ['crm.deals.write'], null));
        self::assertTrue($method->invoke($middleware, $user, ['crm.deals.read'], null));
    }
}
