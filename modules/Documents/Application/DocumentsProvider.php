<?php

declare(strict_types=1);

namespace Modules\Documents\Application;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Documents\Domain\Document;

final class DocumentsProvider
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function find(string $id): ?Document
    {
        $document = $this->em->find(Document::class, $id);

        return $document instanceof Document ? $document : null;
    }

    /** @return list<Document> */
    public function search(string $query, int $limit = 20): array
    {
        $qb = $this->em->getRepository(Document::class)
            ->createQueryBuilder('d')
            ->orderBy('d.createdAt', 'DESC')
            ->setMaxResults($limit);
        self_repair_search($qb, 'd', ['title', 'originalName'], $query);

        return $qb->getQuery()->getResult();
    }

    /** @return list<Document> */
    public function forEntity(string $entityType, string $entityId, int $limit = 50): array
    {
        $links = $this->em->getRepository(\Modules\Documents\Domain\DocumentLink::class)
            ->findBy(
                ['entityType' => $entityType, 'entityId' => $entityId],
                ['createdAt' => 'DESC'],
                $limit,
            );
        if ($links === []) {
            return [];
        }

        $documentIds = array_values(array_unique(array_map(
            static fn ($link): string => $link->documentId(),
            $links,
        )));
        /** @var list<Document> $docs */
        $docs = $this->em->getRepository(Document::class)
            ->createQueryBuilder('d')
            ->andWhere('d.id IN (:ids)')->setParameter('ids', $documentIds)
            ->getQuery()
            ->getResult();
        $byId = [];
        foreach ($docs as $doc) {
            $byId[$doc->id()] = $doc;
        }

        $documents = [];
        foreach ($links as $link) {
            $doc = $byId[$link->documentId()] ?? null;
            if ($doc instanceof Document) {
                $documents[] = $doc;
            }
        }

        return $documents;
    }
}
