<?php

declare(strict_types=1);

namespace Modules\Media\Application;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Media\Domain\MediaAsset;
use Modules\Media\Domain\MediaLink;

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

    public function attach(string $mediaAssetId, string $entityType, string $entityId, ?string $label = null, int $sortOrder = 0): MediaLink
    {
        if (!$this->find($mediaAssetId) instanceof MediaAsset) {
            throw new \InvalidArgumentException('media.error.not_found');
        }
        $link = new MediaLink($mediaAssetId, $entityType, $entityId, $label, $sortOrder);
        $this->em->persist($link);
        $this->em->flush();

        return $link;
    }

    public function detach(string $linkId): void
    {
        $link = $this->em->find(MediaLink::class, $linkId);
        if ($link instanceof MediaLink) {
            $this->em->remove($link);
            $this->em->flush();
        }
    }

    /**
     * @return list<array{link: MediaLink, asset: MediaAsset}>
     */
    public function forEntity(string $entityType, string $entityId, ?string $label = null, int $limit = 100): array
    {
        $qb = $this->em->getRepository(MediaLink::class)->createQueryBuilder('l')
            ->andWhere('l.entityType = :type')->setParameter('type', $entityType)
            ->andWhere('l.entityId = :id')->setParameter('id', $entityId)
            ->orderBy('l.sortOrder', 'ASC')
            ->addOrderBy('l.createdAt', 'DESC')
            ->setMaxResults($limit);
        if ($label !== null && $label !== '') {
            $qb->andWhere('l.label = :label')->setParameter('label', $label);
        }
        $out = [];
        foreach ($qb->getQuery()->getResult() as $link) {
            if (!$link instanceof MediaLink) {
                continue;
            }
            $asset = $this->find($link->mediaAssetId());
            if ($asset instanceof MediaAsset) {
                $out[] = ['link' => $link, 'asset' => $asset];
            }
        }

        return $out;
    }
}
