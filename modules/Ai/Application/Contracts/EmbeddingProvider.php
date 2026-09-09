<?php

declare(strict_types=1);

namespace Modules\Ai\Application\Contracts;

interface EmbeddingProvider
{
    public function driver(): string;

    public function isConfigured(): bool;

    /**
     * @param list<string> $texts
     * @return list<list<float>>
     */
    public function embed(array $texts, string $modelId): array;

    public function dimensions(string $modelId): ?int;
}
