<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Module;

use Gereq\Module\ModuleSemver;
use Gereq\Testing\TestCase;

final class ModuleSemverTest extends TestCase
{
    public function testBumpPatch(): void
    {
        self::assertSame('1.2.4', ModuleSemver::bumpPatch('1.2.3'));
    }

    public function testBumpPatchRollover(): void
    {
        self::assertSame('1.3.0', ModuleSemver::bumpPatch('1.2.99'));
        self::assertSame('2.0.0', ModuleSemver::bumpPatch('1.99.99'));
    }

    public function testParseStripsVPrefix(): void
    {
        self::assertSame([1, 0, 0], ModuleSemver::parse('v1.0.0'));
    }
}
