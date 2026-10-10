<?php

declare(strict_types=1);

namespace Modules\Authorization\Tests;

use Modules\Authorization\Application\OAuthProviderInterface;
use Modules\Authorization\Application\OAuthProviderRegistry;
use PHPUnit\Framework\TestCase;

final class OAuthRegistryTest extends TestCase
{
    public function testPublicStatusListsOnlyConfiguredProviders(): void
    {
        $registry = new OAuthProviderRegistry();
        $registry->register($this->stub('google', true));
        $registry->register($this->stub('facebook', false));

        $status = $registry->publicStatus();
        self::assertTrue($status['google']);
        self::assertFalse($status['facebook']);
        self::assertSame([['id' => 'google', 'label' => 'Google']], $status['providers']);
    }

    private function stub(string $id, bool $configured): OAuthProviderInterface
    {
        return new class ($id, $configured) implements OAuthProviderInterface {
            public function __construct(private readonly string $pid, private readonly bool $ok)
            {
            }

            public function id(): string
            {
                return $this->pid;
            }

            public function label(): string
            {
                return ucfirst($this->pid);
            }

            public function authorizeUrl(string $redirectUri, string $state): string
            {
                return 'https://example.test/' . $this->pid;
            }

            public function profile(string $code, string $redirectUri): array
            {
                return ['id' => '1', 'email' => 'a@b.c', 'name' => 'A'];
            }

            public function configured(): bool
            {
                return $this->ok;
            }
        };
    }
}
