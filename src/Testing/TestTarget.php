<?php

declare(strict_types=1);

namespace Gereq\Testing;

/**
 * A named set of filesystem paths the engine can hand to PHPUnit.
 */
final class TestTarget
{
    /**
     * @param list<string> $paths Absolute directories that contain *Test.php files (may be empty).
     */
    public function __construct(
        public readonly string $id,
        public readonly TestTargetKind $kind,
        public readonly string $label,
        public readonly array $paths,
        public readonly ?string $packageId = null,
    ) {
    }

    public function hasTests(): bool
    {
        return $this->testFileCount() > 0;
    }

    public function testFileCount(): int
    {
        $count = 0;
        foreach ($this->paths as $path) {
            $count += $this->countTestFiles($path);
        }

        return $count;
    }

    private function countTestFiles(string $path): int
    {
        if (is_file($path)) {
            return str_ends_with($path, 'Test.php') ? 1 : 0;
        }

        if (!is_dir($path)) {
            return 0;
        }

        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
        );
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), 'Test.php')) {
                ++$count;
            }
        }

        return $count;
    }
}
