<?php

declare(strict_types=1);

namespace Modules\Ai\Application\Contracts;

use Modules\Ai\Application\Dto\ChatRequest;
use Modules\Ai\Application\Dto\ChatResponse;

interface StreamableLlm
{
    /**
     * Stream assistant text deltas. Tool-call rounds may still return a full ChatResponse
     * with toolCalls and little/no content (onDelta may not fire).
     *
     * @param callable(string $delta): void $onDelta
     */
    public function chatStream(ChatRequest $request, callable $onDelta): ChatResponse;
}
