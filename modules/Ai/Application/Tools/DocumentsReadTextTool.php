<?php

declare(strict_types=1);

namespace Modules\Ai\Application\Tools;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Ai\Application\Contracts\AiTool;
use Modules\Ai\Application\Dto\AiActor;
use Modules\Ai\Application\Dto\AiToolResult;
use Modules\Documents\Application\DocumentsProvider;
use Modules\Documents\Domain\Document;
use Modules\Listings\Domain\Listing;
use Modules\Ai\Domain\AiChunk;
use Modules\Ai\Domain\AiSource;

final class DocumentsReadTextTool implements AiTool
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function name(): string
    {
        return 'documents.read_text';
    }

    public function description(): string
    {
        return 'Read extracted text chunks for a document already ingested into AI knowledge bases.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'document_id' => ['type' => 'string'],
                'limit' => ['type' => 'integer'],
            ],
            'required' => ['document_id'],
        ];
    }

    public function permission(): ?string
    {
        return 'documents.view';
    }

    public function surfaces(): array
    {
        return ['admin'];
    }

    public function invoke(array $args, AiActor $actor): AiToolResult
    {
        $docId = (string) ($args['document_id'] ?? '');
        $limit = min(20, max(1, (int) ($args['limit'] ?? 5)));
        $sources = $this->em->getRepository(AiSource::class)->findBy([
            'sourceType' => 'document',
            'sourceRef' => $docId,
        ]);
        $out = [];
        foreach ($sources as $source) {
            if (!$source instanceof AiSource) {
                continue;
            }
            $chunks = $this->em->getRepository(AiChunk::class)->findBy(
                ['sourceId' => $source->id()],
                ['ordinal' => 'ASC'],
                $limit,
            );
            foreach ($chunks as $chunk) {
                if ($chunk instanceof AiChunk) {
                    $out[] = [
                        'source_id' => $source->id(),
                        'ordinal' => $chunk->ordinal(),
                        'content' => $chunk->content(),
                    ];
                }
            }
        }
        if ($out === [] && class_exists(Document::class)) {
            $doc = $this->em->find(Document::class, $docId);
            if ($doc instanceof Document) {
                return AiToolResult::ok([
                    'note' => 'Document exists but is not ingested into AI yet.',
                    'title' => $doc->title(),
                    'mime' => $doc->mime(),
                ]);
            }
        }

        return AiToolResult::ok($out);
    }
}
