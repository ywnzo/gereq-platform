<?php

declare(strict_types=1);

namespace Modules\Ai\Application\Contracts;

use Modules\Ai\Application\Dto\AiActor;
use Modules\Ai\Application\Dto\AiToolResult;

interface AiTool
{
    public function name(): string;

    public function description(): string;

    /** @return array<string, mixed> JSON Schema object */
    public function parametersSchema(): array;

    public function permission(): ?string;

    /** @return list<string> */
    public function surfaces(): array;

    /**
     * @param array<string, mixed> $args
     */
    public function invoke(array $args, AiActor $actor): AiToolResult;
}
