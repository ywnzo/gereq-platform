<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Support;

use Gereq\Support\Gravatar;
use Gereq\Testing\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class GravatarTest extends TestCase
{
    #[Test]
    public function itHashesNormalizedEmail(): void
    {
        self::assertSame(
            md5('jane@example.com'),
            Gravatar::hash('  Jane@Example.com '),
        );
    }

    #[Test]
    public function itBuildsGravatarUrl(): void
    {
        $url = Gravatar::url('jane@example.com', 64);

        self::assertSame(
            'https://www.gravatar.com/avatar/' . md5('jane@example.com') . '?s=64&d=identicon&r=g',
            $url,
        );
    }

    #[Test]
    public function itClampsSizeAndHandlesEmptyEmail(): void
    {
        $url = Gravatar::url(null, 0);

        self::assertStringContainsString('/avatar/' . md5('') . '?', $url);
        self::assertStringContainsString('s=1', $url);
    }
}
