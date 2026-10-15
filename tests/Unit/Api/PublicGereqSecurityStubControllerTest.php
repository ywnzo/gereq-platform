<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Api;

use Gereq\Api\PublicGereqSecurityStubController;
use Gereq\Testing\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;

final class PublicGereqSecurityStubControllerTest extends TestCase
{
    #[Test]
    public function securityReturnsDisabledPayloadWithCors(): void
    {
        $controller = new PublicGereqSecurityStubController();
        $request = Request::create('/api/public/gereq/security', 'GET');
        $response = $controller->security($request);

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertFalse($payload['enabled']);
        self::assertSame('', $payload['public_key']);
        self::assertSame('https://gereq.cz/js/gereq-security.js', $payload['script_url']);
        self::assertSame('https://gereq.cz/js/gereq-monitor.js', $payload['monitor_script_url']);
        self::assertIsArray($payload['monitor']);
        self::assertNotSame('', (string) $response->headers->get('Access-Control-Allow-Origin'));
    }

    #[Test]
    public function optionsReturnsNoContent(): void
    {
        $controller = new PublicGereqSecurityStubController();
        $response = $controller->options(Request::create('/api/public/gereq/security', 'OPTIONS'));

        self::assertSame(204, $response->getStatusCode());
    }
}
