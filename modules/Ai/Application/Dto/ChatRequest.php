<?php

declare(strict_types=1);

namespace Modules\Ai\Application\Dto;

final class ChatRequest
{
    /**
     * @param list<ChatMessage> $messages
     * @param list<array<string, mixed>> $tools
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public readonly string $model,
        public readonly array $messages,
        public readonly float $temperature = 0.3,
        public readonly array $tools = [],
        public readonly array $extra = [],
    ) {
    }
}
