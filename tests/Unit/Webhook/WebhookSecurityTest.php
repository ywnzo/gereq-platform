<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Webhook;

use Gereq\Config\Repository;
use Gereq\Webhooks\Application\SsrfGuard;
use Gereq\Webhooks\Application\WebhookSigner;
use Gereq\Webhooks\Domain\Webhook;
use PHPUnit\Framework\TestCase;

final class WebhookSecurityTest extends TestCase
{
    public function testSignerProducesSha256Prefix(): void
    {
        $signer = new WebhookSigner();
        $sig = $signer->sign('secret', '123', '{"a":1}');
        self::assertStringStartsWith('sha256=', $sig);
        self::assertTrue($signer->verify('secret', '123', '{"a":1}', $sig));
        self::assertFalse($signer->verify('wrong', '123', '{"a":1}', $sig));
    }

    public function testSsrfBlocksLocalhost(): void
    {
        $guard = new SsrfGuard(new Repository(['api' => ['webhooks' => ['allow_private_urls' => false]]]));
        $this->expectException(\InvalidArgumentException::class);
        $guard->assertSafe('http://127.0.0.1/hook');
    }

    public function testWildcardMatching(): void
    {
        $hook = new Webhook('Test', 'https://example.com/hook', ['customer.*']);
        self::assertTrue($hook->matchesEvent('customer.created'));
        self::assertFalse($hook->matchesEvent('invoice.paid'));
        $all = new Webhook('All', 'https://example.com/hook', ['*']);
        self::assertTrue($all->matchesEvent('invoice.paid'));
    }
}
