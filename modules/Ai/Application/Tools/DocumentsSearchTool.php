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

final class DocumentsSearchTool implements AiTool
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function name(): string
    {
        return 'documents.search';
    }

    public function description(): string
    {
        return 'Search documents by title.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string'],
                'limit' => ['type' => 'integer'],
            ],
            'required' => ['query'],
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
        if (!class_exists(DocumentsProvider::class)) {
            return AiToolResult::fail('Documents not available');
        }
        $provider = new DocumentsProvider($this->em);
        $docs = $provider->search((string) ($args['query'] ?? ''), min(20, max(1, (int) ($args['limit'] ?? 10))));
        $out = [];
        foreach ($docs as $d) {
            $out[] = [
                'id' => $d->id(),
                'title' => $d->title(),
                'mime' => $d->mime(),
            ];
        }

        return AiToolResult::ok($out);
    }
}

