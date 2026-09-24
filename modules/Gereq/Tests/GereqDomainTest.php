<?php

declare(strict_types=1);

namespace Modules\Gereq\Tests;

use Modules\Gereq\Application\Security\Captcha;
use Modules\Gereq\Application\Support\GereqCredential;
use Modules\Gereq\Application\Support\HttpOrigin;
use Modules\Gereq\Domain\SecuritySite;
use Modules\Gereq\Domain\Ticket;
use Modules\Gereq\Domain\TicketSite;
use PHPUnit\Framework\TestCase;

final class GereqDomainTest extends TestCase
{
    public function testCredentialPrefixes(): void
    {
        $pk = GereqCredential::publicKey();
        $sk = GereqCredential::secretKey();
        $tpk = GereqCredential::publicKey(true);

        self::assertStringStartsWith('pk_', $pk);
        self::assertStringStartsWith('sk_', $sk);
        self::assertStringStartsWith('pk_test_', $tpk);
        self::assertTrue(GereqCredential::isTest($tpk));
        self::assertFalse(GereqCredential::isTest($pk));
    }

    public function testOriginNormalizeAndLoopback(): void
    {
        self::assertSame('https://example.com', HttpOrigin::normalize('https://example.com/path'));
        self::assertTrue(HttpOrigin::isLoopback('http://localhost:5173'));
        self::assertFalse(HttpOrigin::isLoopback('https://example.com'));
    }

    public function testSecuritySiteAllowsOrigin(): void
    {
        $site = new SecuritySite('Demo');
        $site->setAllowedOrigins("https://shop.example\nhttp://localhost:3000");
        self::assertTrue($site->allowsOrigin('https://shop.example/foo'));
        self::assertFalse($site->allowsOrigin('https://evil.example'));
        self::assertSame(Captcha::DIFFICULTY_DEFAULT, $site->captchaDifficulty());
    }

    public function testTicketSiteAndTicketBasics(): void
    {
        $site = new TicketSite('Support');
        $site->setAllowedOrigins(['https://app.example']);
        self::assertTrue($site->allowsOrigin('https://app.example'));

        $ticket = new Ticket('SF-2026-0001', bin2hex(random_bytes(32)), $site->id());
        $ticket->setVisitorEmail('Visitor@Example.com');
        $ticket->setStatus('pending');
        self::assertSame('visitor@example.com', $ticket->visitorEmail());
        self::assertSame('pending', $ticket->status());
    }
}
