<?php

declare(strict_types=1);

namespace Modules\Ai\Application\Dto;

final class AiToolResult
{
    /**
     * @param array<string, mixed>|list<mixed>|string $data
     */
    public function __construct(
        public readonly mixed $data,
        public readonly bool $ok = true,
        public readonly ?string $error = null,
    ) {
    }

    public static function ok(mixed $data): self
    {
        return new self($data, true);
    }

    public static function fail(string $error): self
    {
        return new self(['error' => $error], false, $error);
    }
}
