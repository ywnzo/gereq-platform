<?php

declare(strict_types=1);

namespace Modules\Returns\Application;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Returns\Domain\ReturnRequest;

final class ReturnsProvider
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function find(string $id): ?ReturnRequest
    {
        $item = $this->em->find(ReturnRequest::class, $id);

        return $item instanceof ReturnRequest ? $item : null;
    }

    /** @return list<ReturnRequest> */
    public function forOrder(string $orderId, int $limit = 50): array
    {
        return $this->em->getRepository(ReturnRequest::class)->findBy(
            ['orderId' => $orderId],
            ['createdAt' => 'DESC'],
            $limit,
        );
    }

    public function nextNumber(): string
    {
        $year = (new \DateTimeImmutable())->format('Y');
        $pattern = 'RMA-' . $year . '-%';
        $count = (int) $this->em->createQueryBuilder()
            ->select('COUNT(r.id)')
            ->from(ReturnRequest::class, 'r')
            ->where('r.number LIKE :pattern')
            ->setParameter('pattern', $pattern)
            ->getQuery()
            ->getSingleScalarResult();

        return sprintf('RMA-%s-%04d', $year, $count + 1);
    }
}
