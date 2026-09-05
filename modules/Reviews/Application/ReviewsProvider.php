<?php

declare(strict_types=1);

namespace Modules\Reviews\Application;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Reviews\Domain\ProductReview;

final class ReviewsProvider
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @return list<ProductReview> */
    public function forProduct(string $productId, bool $onlyApproved = true, int $limit = 50): array
    {
        $criteria = ['productId' => $productId];
        if ($onlyApproved) {
            $criteria['status'] = 'approved';
        }

        return $this->em->getRepository(ProductReview::class)->findBy(
            $criteria,
            ['createdAt' => 'DESC'],
            $limit,
        );
    }

    public function averageRating(string $productId): ?float
    {
        $avg = $this->em->createQueryBuilder()
            ->select('AVG(r.rating)')
            ->from(ProductReview::class, 'r')
            ->where('r.productId = :pid')
            ->andWhere('r.status = :status')
            ->setParameter('pid', $productId)
            ->setParameter('status', 'approved')
            ->getQuery()
            ->getSingleScalarResult();

        return $avg !== null ? round((float) $avg, 2) : null;
    }

    public function find(string $id): ?ProductReview
    {
        $item = $this->em->find(ProductReview::class, $id);

        return $item instanceof ProductReview ? $item : null;
    }
}
