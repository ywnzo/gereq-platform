<?php

declare(strict_types=1);

namespace Modules\Ai\Application;

use Modules\Ai\Domain\AiAgent;

/**
 * Request-scoped context for tool calls (caller agent + delegation depth).
 */
final class ToolInvocationContext
{
    private ?AiAgent $caller = null;

    private ?string $conversationId = null;

    private int $depth = 0;

    public function set(?AiAgent $caller, ?string $conversationId = null): void
    {
        $this->caller = $caller;
        $this->conversationId = $conversationId;
    }

    public function clear(): void
    {
        $this->caller = null;
        $this->conversationId = null;
    }

    public function caller(): ?AiAgent
    {
        return $this->caller;
    }

    public function conversationId(): ?string
    {
        return $this->conversationId;
    }

    public function depth(): int
    {
        return $this->depth;
    }

    public function enter(): void
    {
        $this->depth++;
    }

    public function leave(): void
    {
        $this->depth = max(0, $this->depth - 1);
    }
}
