<?php

declare(strict_types=1);

namespace Modules\Ai\Application\Vector;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Ai\Application\Contracts\VectorStore;
use Modules\Ai\Domain\AiChunk;

final class SqlVectorStore implements VectorStore
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function search(array $vector, array $knowledgeBaseIds, int $topK = 8): array
    {
        if ($knowledgeBaseIds === [] || $vector === []) {
            return [];
        }

        $chunks = $this->em->getRepository(AiChunk::class)->createQueryBuilder('c')
            ->andWhere('c.knowledgeBaseId IN (:ids)')
            ->setParameter('ids', $knowledgeBaseIds)
            ->andWhere('c.embedding IS NOT NULL')
            ->getQuery()
            ->getResult();

        $scored = [];
        foreach ($chunks as $chunk) {
            if (!$chunk instanceof AiChunk) {
                continue;
            }
            $bin = $chunk->embedding();
            if ($bin === null) {
                continue;
            }
            $emb = EmbeddingCodec::unpack($bin);
            $score = EmbeddingCodec::cosine($vector, $emb);
            $scored[] = [
                'chunk_id' => $chunk->id(),
                'score' => $score,
                'content' => $chunk->content(),
                'metadata' => $chunk->metadata(),
                'source_id' => $chunk->sourceId(),
            ];
        }

        usort($scored, static fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, $topK);
    }

    public function deleteBySource(string $sourceId): void
    {
        $this->em->createQuery('DELETE FROM ' . AiChunk::class . ' c WHERE c.sourceId = :id')
            ->setParameter('id', $sourceId)
            ->execute();
    }
}
