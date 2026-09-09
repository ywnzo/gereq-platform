<?php

declare(strict_types=1);

namespace Modules\Ai\Application;

use Modules\Ai\Application\Contracts\EmbeddingProvider;
use Modules\Ai\Application\Contracts\LlmProvider;

final class LlmProviderRegistry
{
    /** @var array<string, LlmProvider> */
    private array $providers = [];

    /** @var array<string, EmbeddingProvider> */
    private array $embeddings = [];

    public function registerLlm(LlmProvider $provider): void
    {
        $this->providers[$provider->driver()] = $provider;
    }

    public function registerEmbedding(EmbeddingProvider $provider): void
    {
        $this->embeddings[$provider->driver()] = $provider;
    }

    public function llm(string $driver): ?LlmProvider
    {
        return $this->providers[$driver] ?? null;
    }

    public function embedding(string $driver): ?EmbeddingProvider
    {
        return $this->embeddings[$driver] ?? null;
    }

    /** @return list<LlmProvider> */
    public function allLlm(): array
    {
        return array_values($this->providers);
    }

    /** @return list<EmbeddingProvider> */
    public function allEmbedding(): array
    {
        return array_values($this->embeddings);
    }
}
