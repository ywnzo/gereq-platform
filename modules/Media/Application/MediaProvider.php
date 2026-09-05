<?php

declare(strict_types=1);

namespace Modules\Media\Application;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Media\Domain\MediaAsset;

final class MediaProvider
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function find(string $id): ?MediaAsset
    {
        $asset = $this->em->find(MediaAsset::class, $id);

        return $asset instanceof MediaAsset ? $asset : null;
    }

    /** @return list<MediaAsset> */
    public function search(string $query, int $limit = 20, ?string $mimePrefix = null): array
    {
        $qb = $this->em->getRepository(MediaAsset::class)
            ->createQueryBuilder('m')
            ->orderBy('m.createdAt', 'DESC')
            ->setMaxResults($limit);

        self_repair_search($qb, 'm', ['title', 'alt', 'path'], $query);

        if ($mimePrefix !== null && $mimePrefix !== '') {
            $qb->andWhere('m.mime LIKE :mime')->setParameter('mime', $mimePrefix . '%');
        }

        return $qb->getQuery()->getResult();
    }

    /** @return list<MediaAsset> */
    public function images(int $limit = 50): array
    {
        return $this->search('', $limit, 'image/');
    }
}
