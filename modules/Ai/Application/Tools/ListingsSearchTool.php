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

final class ListingsSearchTool implements AiTool
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function name(): string
    {
        return 'listings.search';
    }

    public function description(): string
    {
        return 'Search listings by title.';
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
        $q = trim((string) ($args['query'] ?? ''));
        $limit = min(20, max(1, (int) ($args['limit'] ?? 10)));
        $qb = $this->em->getRepository(Listing::class)->createQueryBuilder('l')
            ->setMaxResults($limit)
            ->orderBy('l.createdAt', 'DESC');
        if ($q !== '') {
            $qb->andWhere('LOWER(l.title) LIKE :q')->setParameter('q', '%' . mb_strtolower($q) . '%');
        }
        $out = [];
        foreach ($qb->getQuery()->getResult() as $item) {
            if (!$item instanceof Listing) {
                continue;
            }
            $out[] = ['id' => $item->id(), 'title' => $item->title()];
        }

        return AiToolResult::ok($out);
    }
}

