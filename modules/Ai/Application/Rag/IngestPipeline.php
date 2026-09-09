<?php

declare(strict_types=1);

namespace Modules\Ai\Application\Rag;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\File\FileManager;
use Modules\Ai\Application\ModelRouter;
use Modules\Ai\Application\Vector\EmbeddingCodec;
use Modules\Ai\Application\Vector\PgVectorStore;
use Modules\Ai\Application\Vector\SqlVectorStore;
use Modules\Ai\Application\Extractors\TextExtractor;
use Modules\Ai\Domain\AiChunk;
use Modules\Ai\Domain\AiSource;
use Modules\Documents\Domain\Document;

final class IngestPipeline
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TextExtractor $extractor,
        private readonly TextChunker $chunker,
        private readonly ModelRouter $router,
        private readonly SqlVectorStore $sqlStore,
        private readonly PgVectorStore $pgStore,
        private readonly ?FileManager $files = null,
    ) {
    }

    public function ingestSource(string $sourceId, ?string $embedModelId = null): void
    {
        $source = $this->em->find(AiSource::class, $sourceId);
        if (!$source instanceof AiSource) {
            return;
        }

        try {
            $text = $this->loadText($source);
            if (trim($text) === '') {
                $source->markFailed('Nepodařilo se extrahovat text.');
                $this->em->flush();
                return;
            }

            $this->sqlStore->deleteBySource($source->id());
            $this->pgStore->deleteBySource($source->id());

            $chunks = $this->chunker->chunk($text);
            $resolved = $this->router->resolveEmbedding($embedModelId);
            /** @var \Modules\Ai\Domain\AiModel $model */
            $model = $resolved['model'];
            /** @var \Modules\Ai\Application\Contracts\EmbeddingProvider $emb */
            $emb = $resolved['embedding'];

            $batchSize = 16;
            for ($i = 0; $i < count($chunks); $i += $batchSize) {
                $slice = array_slice($chunks, $i, $batchSize);
                $texts = array_column($slice, 'content');
                $vectors = $emb->embed($texts, $model->modelId());
                foreach ($slice as $j => $meta) {
                    $entity = new AiChunk(
                        $source->id(),
                        $source->knowledgeBaseId(),
                        $meta['ordinal'],
                        $meta['content'],
                        $meta['hash'],
                        $meta['token_estimate'],
                        ['title' => $source->title()],
                    );
                    $vector = $vectors[$j] ?? [];
                    if ($vector !== []) {
                        $entity->setEmbedding(
                            EmbeddingCodec::pack($vector),
                            $model->id(),
                            count($vector),
                        );
                    }
                    $this->em->persist($entity);
                    $this->em->flush();
                    if ($vector !== []) {
                        $this->pgStore->upsertChunkVector($entity->id(), $vector);
                    }
                }
            }

            $source->setChecksum(hash('sha256', $text));
            $source->markReady();
            $this->em->flush();
        } catch (\Throwable $e) {
            $source->markFailed($e->getMessage());
            $this->em->flush();
        }
    }

    private function loadText(AiSource $source): string
    {
        if ($source->sourceType() === 'manual_text') {
            return (string) ($source->acl()['text'] ?? '');
        }

        if ($source->sourceType() === 'document' && class_exists(Document::class)) {
            $doc = $this->em->find(Document::class, $source->sourceRef());
            if (!$doc instanceof Document) {
                return '';
            }
            $source->setMime($doc->mime());
            $path = $this->resolveDocumentPath($doc->path());
            if ($path === null || !is_file($path)) {
                return '';
            }
            $binary = file_get_contents($path);
            if ($binary === false) {
                return '';
            }

            return $this->extractor->extract($binary, $doc->mime(), $doc->originalName());
        }

        return (string) ($source->acl()['text'] ?? '');
    }

    private function resolveDocumentPath(string $relative): ?string
    {
        $candidates = [
            dirname(__DIR__, 4) . '/storage/uploads/' . ltrim($relative, '/'),
            dirname(__DIR__, 4) . '/storage/' . ltrim($relative, '/'),
        ];
        foreach ($candidates as $c) {
            if (is_file($c)) {
                return $c;
            }
        }

        return null;
    }
}
