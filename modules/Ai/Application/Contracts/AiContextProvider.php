<?php

declare(strict_types=1);

namespace Modules\Ai\Application\Contracts;

use Modules\Ai\Application\Dto\AiActor;

interface AiContextProvider
{
    public function supports(string $surface): bool;

    /**
     * @return array<string, mixed>
     */
    public function snapshot(string $entityId, AiActor $actor): array;
}
