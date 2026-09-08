<?php

declare(strict_types=1);

namespace Gereq\Publish;

/**
 * Request-scoped read/write mode for content.
 * Admin → staging (merge overlay); public API → production only.
 */
final class ContentReadContext
{
    public const PRODUCTION = 'production';
    public const STAGING = 'staging';

    private string $mode = self::PRODUCTION;

    public function mode(): string
    {
        return $this->mode;
    }

    public function isStaging(): bool
    {
        return $this->mode === self::STAGING;
    }

    public function isProduction(): bool
    {
        return $this->mode === self::PRODUCTION;
    }

    public function useStaging(): void
    {
        $this->mode = self::STAGING;
    }

    public function useProduction(): void
    {
        $this->mode = self::PRODUCTION;
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function withProduction(callable $callback): mixed
    {
        $previous = $this->mode;
        $this->mode = self::PRODUCTION;
        try {
            return $callback();
        } finally {
            $this->mode = $previous;
        }
    }
}
