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

final class ListingsGetTool implements AiTool
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function name(): string
    {
        return 'listings.get';
    }

    public function description(): string
    {
        return 'Get a listing by id.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'string']],
            'required' => ['id'],
        ];
    }

    public function permission(): ?string
    {
        return 'listings.view';
    }

    public function surfaces(): array
    {
        return ['admin', 'api', 'public'];
    }

    public function invoke(array $args, AiActor $actor): AiToolResult
    {
        if (!class_exists(Listing::class)) {
            return AiToolResult::fail('Listings not available');
        }
        $item = $this->em->find(Listing::class, (string) ($args['id'] ?? ''));
        if (!$item instanceof Listing) {
            return AiToolResult::fail('Listing not found');
        }

        return AiToolResult::ok([
            'id' => $item->id(),
            'title' => $item->title(),
            'status' => method_exists($item, 'status') ? $item->status() : null,
            'price' => method_exists($item, 'price') ? $item->price() : null,
        ]);
    }
}

