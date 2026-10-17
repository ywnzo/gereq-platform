<?php

declare(strict_types=1);

namespace Gereq\Module;

/**
 * File-level drift between the installed snapshot (or git) and the live module tree.
 *
 * @phpstan-type ChangeRow array{
 *   path: string,
 *   status: 'added'|'modified'|'deleted',
 *   additions: int,
 *   deletions: int
 * }
 */
final class ModuleChangeSet
{
    /**
     * @param list<ChangeRow> $files
     */
    public function __construct(
        public readonly string $moduleId,
        public readonly string $fileVersion,
        public readonly string $installedVersion,
        public readonly bool $sameVersion,
        public readonly bool $footprintDrift,
        public readonly array $files,
        public readonly int $additions,
        public readonly int $deletions,
        public readonly string $source,
    ) {
    }

    public function hasFiles(): bool
    {
        return $this->files !== [];
    }

    public function canBump(): bool
    {
        return $this->sameVersion && ($this->footprintDrift || $this->hasFiles());
    }
}
