<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Foundation;

use PHPUnit\Framework\TestCase;

final class DesignRoutesDebugOnlyTest extends TestCase
{
    public function testDesignRoutesAreGatedByAppDebug(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Foundation/AdminRoutesRegistrar.php');

        self::assertMatchesRegularExpression(
            '/if\s*\(\s*\(bool\)\s*config\(\s*[\'"]app\.debug[\'"]/',
            $src,
        );
        self::assertStringContainsString('/design.html', $src);
        self::assertStringContainsString('/design/ui.html', $src);
    }
}
