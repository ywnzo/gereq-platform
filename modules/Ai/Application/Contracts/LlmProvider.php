<?php

declare(strict_types=1);

namespace Modules\Ai\Application\Contracts;

use Modules\Ai\Application\Dto\ChatRequest;
use Modules\Ai\Application\Dto\ChatResponse;

interface LlmProvider
{
    public function driver(): string;

    public function isConfigured(): bool;

    public function chat(ChatRequest $request): ChatResponse;

    /**
     * @return array{ok: bool, message: string, details?: array<string, mixed>}
     */
    public function ping(): array;
}
