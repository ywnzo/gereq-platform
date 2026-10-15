<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit;

use Gereq\Config\Repository as Config;
use Gereq\Customers\Application\StoreTokenService;
use Gereq\Customers\Domain\CustomerAccount;
use PHPUnit\Framework\TestCase;

final class StoreTokenServiceTest extends TestCase
{
    public function testIssueIsJwtAndRoundTrips(): void
    {
        $config = new Config(['app' => ['key' => 'test-key-32-chars-minimum!!!!!!']]);
        $tokens = new StoreTokenService($config);
        $account = new CustomerAccount('cust-1', 'user@example.com');

        $issued = $tokens->issue($account, 3600);
        $jwt = $issued['access_token'];
        $parts = explode('.', $jwt);
        self::assertCount(3, $parts);

        $pad = str_repeat('=', (4 - strlen($parts[0]) % 4) % 4);
        $header = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/') . $pad, true), true);
        self::assertSame('JWT', $header['typ'] ?? null);
        self::assertSame('HS256', $header['alg'] ?? null);

        $claims = $tokens->parse($jwt);
        self::assertNotNull($claims);
        self::assertSame($account->id(), $claims['sub']);
        self::assertSame('cust-1', $claims['cid']);
        self::assertSame('user@example.com', $claims['email']);
    }

    public function testParseAcceptsLegacyTwoPartToken(): void
    {
        $config = new Config(['app' => ['key' => 'test-key-32-chars-minimum!!!!!!']]);
        $tokens = new StoreTokenService($config);
        $payload = [
            'sub' => 'acct-legacy',
            'cid' => 'cust',
            'email' => 'legacy@example.com',
            'ver' => 0,
            'exp' => time() + 3600,
            'iat' => time(),
        ];
        $body = rtrim(strtr(base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $sig = hash_hmac('sha256', $body, 'test-key-32-chars-minimum!!!!!!');
        $legacy = $body . '.' . $sig;

        $claims = $tokens->parse($legacy);
        self::assertNotNull($claims);
        self::assertSame('acct-legacy', $claims['sub']);
    }
}
