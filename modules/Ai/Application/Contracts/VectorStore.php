<?php

declare(strict_types=1);

namespace Modules\Ai\Application\Contracts;

interface VectorStore
{
    /**
     * @param list<float> $vector
     * @param list<string> $knowledgeBaseIds
     * @return list<array{chunk_id: string, score: float, content: string, metadata: array<string, mixed>, source_id: string}>
     */
    public function search(array $vector, array $knowledgeBaseIds, int $topK = 8): array;

    public function deleteBySource(string $sourceId): void;
}
