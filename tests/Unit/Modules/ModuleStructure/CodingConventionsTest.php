<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Modules\ModuleStructure;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Coding-convention guardrails for platform and module test suites.
 */
final class CodingConventionsTest extends TestCase
{
    /** @return list<array{0: string, 1: string}> [absolutePath, relativeLabel] */
    public static function provideTestFiles(): array
    {
        $root = dirname(__DIR__, 4);
        $out = [];

        foreach (self::phpFiles($root . '/tests') as $path) {
            if (!str_ends_with($path, 'Test.php') && !str_ends_with($path, 'Stub.php')) {
                // Support helpers (e.g. PassthroughTranslator) still must follow conventions.
                if (!str_contains($path, '/Support/')) {
                    continue;
                }
            }
            $out[] = [$path, 'tests/' . substr($path, strlen($root . '/tests/'))];
        }

        $moduleTestDirs = glob($root . '/modules/*/Tests');
        if ($moduleTestDirs === false) {
            $moduleTestDirs = [];
        }
        foreach ($moduleTestDirs as $dir) {
            $module = basename(dirname($dir));
            foreach (self::phpFiles($dir) as $path) {
                $out[] = [$path, 'modules/' . $module . '/Tests/' . basename($path)];
            }
        }

        sort($out);

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function phpFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    #[DataProvider('provideTestFiles')]
    public function testFileFollowsConventions(string $path, string $label): void
    {
        $code = file_get_contents($path);
        self::assertNotFalse($code, $label);

        self::assertMatchesRegularExpression(
            '/^<\?php\s+declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;/s',
            $code,
            $label . ' must start with declare(strict_types=1);',
        );

        if (str_ends_with($path, 'Test.php')) {
            self::assertMatchesRegularExpression(
                '/\bfinal\s+class\s+\w+Test\b/',
                $code,
                $label . ' must be a final *Test class',
            );
        }

        if (str_starts_with($label, 'tests/')) {
            self::assertMatchesRegularExpression(
                '/^namespace\s+Gereq\\\\Tests\\\\/m',
                $code,
                $label . ' must use namespace Gereq\\Tests\\…',
            );
        }

        if (str_starts_with($label, 'modules/')) {
            self::assertMatchesRegularExpression(
                '/^namespace\s+Modules\\\\[A-Za-z0-9_]+\\\\Tests\s*;/m',
                $code,
                $label . ' must use namespace Modules\\<Name>\\Tests',
            );
        }
    }
}
