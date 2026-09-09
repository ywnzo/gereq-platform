<?php

declare(strict_types=1);

namespace Modules\Ai\Application\Dto;

final class ChatMessage
{
    /**
     * @param list<array{id: string, type: string, function: array{name: string, arguments: string}}>|null $toolCalls
     */
    public function __construct(
        public readonly string $role,
        public readonly string $content,
        public readonly ?string $name = null,
        public readonly ?string $toolCallId = null,
        public readonly ?array $toolCalls = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toOpenAi(): array
    {
        $row = ['role' => $this->role, 'content' => $this->content];
        if ($this->name !== null) {
            $row['name'] = $this->name;
        }
        if ($this->toolCallId !== null) {
            $row['tool_call_id'] = $this->toolCallId;
        }
        if ($this->toolCalls !== null) {
            $row['tool_calls'] = $this->toolCalls;
        }

        return $row;
    }
}
