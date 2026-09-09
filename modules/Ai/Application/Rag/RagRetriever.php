<?php

declare(strict_types=1);

namespace Modules\Ai\Application\Rag;

use Modules\Ai\Application\ModelRouter;
use Modules\Ai\Application\Vector\PgVectorStore;
use Modules\Ai\Application\Vector\SqlVectorStore;

final class RagRetriever
{
    public function __construct(
        private readonly ModelRouter $router,
        private readonly SqlVectorStore $sqlStore,
        private readonly PgVectorStore $pgStore,
    ) {
    }

    /**
     * @param list<string> $knowledgeBaseIds
     * @return list<array{chunk_id: string, score: float, content: string, metadata: array<string, mixed>, source_id: string}>
     */
    public function retrieve(string $query, array $knowledgeBaseIds, ?string $embedModelId = null, int $topK = 8): array
    {
        if ($knowledgeBaseIds === [] || trim($query) === '') {
            return [];
        }
        $resolved = $this->router->resolveEmbedding($embedModelId);
        $vector = $resolved['embedding']->embed([$query], $resolved['model']->modelId())[0] ?? [];
        if ($vector === []) {
            return [];
        }

        $store = $this->pgStore->available() ? $this->pgStore : $this->sqlStore;

        return $store->search($vector, $knowledgeBaseIds, $topK);
    }

    /**
     * @param list<array{chunk_id: string, score: float, content: string, metadata: array<string, mixed>, source_id: string}> $hits
     */
    public function formatCitations(array $hits): string
    {
        if ($hits === []) {
            return '';
        }
        $lines = ['Relevantní znalosti:'];
        foreach ($hits as $i => $hit) {
            $n = $i + 1;
            $lines[] = sprintf('[#%d score=%.3f] %s', $n, $hit['score'], mb_substr($hit['content'], 0, 1200));
        }

        return implode("\n\n", $lines);
    }
}
