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

    /**
     * Approved reviews for the public storefront.
     *
     * @return list<ProductReview>
     */
    public function approved(?string $productId = null, int $limit = 24, ?string $locale = null): array
    {
        $limit = max(1, min(100, $limit));
        $qb = $this->em->createQueryBuilder()
            ->select('r')
            ->from(ProductReview::class, 'r')
            ->where('r.status = :status')
            ->setParameter('status', 'approved')
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults($limit);

        if ($productId !== null && $productId !== '') {
            $qb->andWhere('r.productId = :pid')->setParameter('pid', $productId);
        }
        if ($locale !== null && $locale !== '') {
            $qb->andWhere('r.locale = :locale')->setParameter('locale', $locale);
        }

        /** @var list<ProductReview> $items */
        $items = $qb->getQuery()->getResult();

        return $items;
    }

    public function averageRating(?string $productId = null): ?float
    {
        $qb = $this->em->createQueryBuilder()
            ->select('AVG(r.rating)')
            ->from(ProductReview::class, 'r')
            ->where('r.status = :status')
            ->setParameter('status', 'approved');

        if ($productId !== null && $productId !== '') {
            $qb->andWhere('r.productId = :pid')->setParameter('pid', $productId);
        }

        $avg = $qb->getQuery()->getSingleScalarResult();

        return $avg !== null ? round((float) $avg, 2) : null;
    }

    public function find(string $id): ?ProductReview
    {
        $item = $this->em->find(ProductReview::class, $id);

        return $item instanceof ProductReview ? $item : null;
    }

    /** @return array<string, mixed> */
    public function serializeCms(ProductReview $review): array
    {
        $data = $this->serializePublic($review);
        $parts = preg_split('/\s+/u', trim($review->authorName())) ?: [];
        $data['author_initials'] = implode('', array_map(
            static fn (string $part): string => mb_substr($part, 0, 1),
            array_slice($parts, 0, 2),
        ));
        return $data;
    }

    /** @return array<string, mixed> */
    public function serializePublic(ProductReview $r): array
    {
        return [
            'id' => $r->id(),
            'product_id' => $r->productId(),
            'author_name' => $r->authorName(),
            'rating' => $r->rating(),
            'title' => $r->title(),
            'body' => $r->body(),
            'source' => $r->source(),
            'external_url' => $r->externalUrl(),
            'locale' => $r->locale(),
            'created_at' => $r->createdAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
