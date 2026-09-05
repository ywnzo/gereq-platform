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
        return $this->em->getRepository(Document::class)
            ->createQueryBuilder('d')
            ->where('d.title LIKE :q OR d.originalName LIKE :q')
            ->setParameter('q', '%' . $query . '%')
            ->orderBy('d.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
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

        $documents = [];
        foreach ($links as $link) {
            $doc = $this->em->find(Document::class, $link->documentId());
            if ($doc instanceof Document) {
                $documents[] = $doc;
            }
        }

        return $documents;
    }
}
