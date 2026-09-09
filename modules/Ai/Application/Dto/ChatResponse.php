<?php

declare(strict_types=1);

namespace Modules\Ai\Application\Dto;

final class ChatResponse
{
    /**
     * @param list<array{id: string, type: string, function: array{name: string, arguments: string}}> $toolCalls
     */
    public function __construct(
        public readonly string $content,
        public readonly array $toolCalls = [],
        public readonly int $tokensIn = 0,
        public readonly int $tokensOut = 0,
        public readonly ?string $finishReason = null,
        public readonly ?string $rawModel = null,
    ) {
    }

    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
    }
}
