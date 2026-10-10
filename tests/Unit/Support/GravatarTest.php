<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Support;

use Gereq\Support\Gravatar;
use Gereq\Testing\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class GravatarTest extends TestCase
{
    #[Test]
    public function it_hashes_normalized_email(): void
    {
        self::assertSame(
            md5('jane@example.com'),
            Gravatar::hash('  Jane@Example.com '),
        );
    }

    #[Test]
    public function it_builds_gravatar_url(): void
    {
        $url = Gravatar::url('jane@example.com', 64);

        self::assertSame(
            'https://www.gravatar.com/avatar/' . md5('jane@example.com') . '?s=64&d=identicon&r=g',
            $url,
        );
    }

    #[Test]
    public function it_clamps_size_and_handles_empty_email(): void
    {
        $url = Gravatar::url(null, 0);

        self::assertStringContainsString('/avatar/' . md5('') . '?', $url);
        self::assertStringContainsString('s=1', $url);
    }
}
