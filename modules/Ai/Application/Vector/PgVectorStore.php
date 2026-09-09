<?php

declare(strict_types=1);

namespace Modules\Ai\Application\Vector;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Ai\Application\Contracts\VectorStore;
use Modules\Ai\Domain\AiChunk;

/**
 * Optional pgvector backend. Falls back to SqlVectorStore when extension missing.
 */
final class PgVectorStore implements VectorStore
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SqlVectorStore $fallback,
    ) {
    }

    public function available(): bool
    {
        try {
            $conn = $this->em->getConnection();
            if (!$conn->getDatabasePlatform() instanceof PostgreSQLPlatform) {
                return false;
            }
            $v = $conn->fetchOne("SELECT 1 FROM pg_extension WHERE extname = 'vector'");

            return (int) $v === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    public function search(array $vector, array $knowledgeBaseIds, int $topK = 8): array
    {
        if (!$this->available()) {
            return $this->fallback->search($vector, $knowledgeBaseIds, $topK);
        }
        if ($knowledgeBaseIds === [] || $vector === []) {
            return [];
        }

        $conn = $this->em->getConnection();
        $literal = '[' . implode(',', array_map(static fn ($f) => sprintf('%.8F', $f), $vector)) . ']';
        $placeholders = [];
        $params = ['top' => $topK];
        foreach (array_values($knowledgeBaseIds) as $i => $id) {
            $key = 'kb' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }
        $sql = sprintf(
            'SELECT c.id AS chunk_id, c.content, c.metadata, c.source_id,
                    1 - (p.embedding <=> \'%s\'::vector) AS score
             FROM ai_chunk_pgvector p
             INNER JOIN ai_chunks c ON c.id = p.chunk_id
             WHERE c.knowledge_base_id IN (%s)
             ORDER BY p.embedding <=> \'%s\'::vector
             LIMIT :top',
            $literal,
            implode(',', $placeholders),
            $literal,
        );

        try {
            $rows = $conn->fetchAllAssociative($sql, $params);
        } catch (\Throwable) {
            return $this->fallback->search($vector, $knowledgeBaseIds, $topK);
        }

        $out = [];
        foreach ($rows as $row) {
            $meta = $row['metadata'] ?? [];
            if (is_string($meta)) {
                $decoded = json_decode($meta, true);
                $meta = is_array($decoded) ? $decoded : [];
            }
            $out[] = [
                'chunk_id' => (string) $row['chunk_id'],
                'score' => (float) $row['score'],
                'content' => (string) $row['content'],
                'metadata' => $meta,
                'source_id' => (string) $row['source_id'],
            ];
        }

        return $out;
    }

    public function deleteBySource(string $sourceId): void
    {
        if ($this->available()) {
            $conn = $this->em->getConnection();
            $conn->executeStatement(
                'DELETE FROM ai_chunk_pgvector WHERE chunk_id IN (SELECT id FROM ai_chunks WHERE source_id = ?)',
                [$sourceId],
            );
        }
        $this->fallback->deleteBySource($sourceId);
    }

    /**
     * @param list<float> $vector
     */
    public function upsertChunkVector(string $chunkId, array $vector): void
    {
        if (!$this->available()) {
            return;
        }
        $literal = '[' . implode(',', array_map(static fn ($f) => sprintf('%.8F', $f), $vector)) . ']';
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            'INSERT INTO ai_chunk_pgvector (chunk_id, embedding) VALUES (?, ?::vector)
             ON CONFLICT (chunk_id) DO UPDATE SET embedding = EXCLUDED.embedding',
            [$chunkId, $literal],
        );
    }
}
