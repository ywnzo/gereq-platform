<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit;

use Gereq\Config\Repository as Config;
use Gereq\Customers\Application\StoreCookieBridge;
use Gereq\Customers\Application\StoreTokenService;
use Gereq\Customers\Domain\CustomerAccount;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class StoreCookieBridgeTest extends TestCase
{
    public function testTokenFromBearerAndCookie(): void
    {
        $config = new Config(['app' => ['url' => 'http://localhost', 'key' => 'test-key-32-chars-minimum!!!!!!']]);
        $tokens = new StoreTokenService($config);
        $bridge = new StoreCookieBridge($tokens, $config);

        $bearerReq = Request::create('/me', 'GET', server: ['HTTP_AUTHORIZATION' => 'Bearer abc.def']);
        self::assertSame('abc.def', $bridge->tokenFromRequest($bearerReq));

        $cookieReq = Request::create('/me', 'GET', cookies: [StoreCookieBridge::COOKIE => 'cookie-token']);
        self::assertSame('cookie-token', $bridge->tokenFromRequest($cookieReq));
    }

    public function testAttachSetsHttpOnlyJwtAndReadableHint(): void
    {
        $config = new Config(['app' => ['url' => 'https://shop.example', 'key' => 'test-key-32-chars-minimum!!!!!!']]);
        $tokens = new StoreTokenService($config);
        $bridge = new StoreCookieBridge($tokens, $config);
        $account = new CustomerAccount('cust-1', 'user@example.com');

        $response = $bridge->attach(new Response('ok'), $account);
        $cookies = $response->headers->getCookies();
        self::assertNotEmpty($cookies);
        $jwt = null;
        $hint = null;
        foreach ($cookies as $c) {
            if ($c->getName() === StoreCookieBridge::COOKIE) {
                $jwt = $c;
            }
            if ($c->getName() === StoreCookieBridge::HINT_COOKIE) {
                $hint = $c;
            }
        }
        self::assertNotNull($jwt);
        self::assertTrue($jwt->isHttpOnly());
        self::assertSame('lax', strtolower((string) $jwt->getSameSite()));
        self::assertCount(3, explode('.', (string) $jwt->getValue()));

        self::assertNotNull($hint);
        self::assertFalse($hint->isHttpOnly());
        self::assertSame('1', $hint->getValue());
    }
}
