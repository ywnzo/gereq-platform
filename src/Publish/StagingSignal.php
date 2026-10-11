<?php

declare(strict_types=1);

namespace Gereq\Publish;

/**
 * Request-scoped counter of staging mutations — drives live admin badge refresh.
 */
final class StagingSignal
{
    private int $revision = 0;

    private bool $dirty = false;

    public function bump(): void
    {
        $this->revision++;
        $this->dirty = true;
    }

    public function revision(): int
    {
        return $this->revision;
    }

    public function isDirty(): bool
    {
        return $this->dirty;
    }
}
