<?php

declare(strict_types=1);

namespace Modules\Viewings\Application;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Viewings\Domain\Viewing;

final class ViewingsProvider
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function find(string $id): ?Viewing
    {
        $item = $this->em->find(Viewing::class, $id);

        return $item instanceof Viewing ? $item : null;
    }

    /** @return list<Viewing> */
    public function forListing(string $listingId, int $limit = 50): array
    {
        return $this->em->getRepository(Viewing::class)->findBy(
            ['listingId' => $listingId],
            ['scheduledAt' => 'DESC', 'createdAt' => 'DESC'],
            $limit,
        );
    }

    public function nextNumber(): string
    {
        $year = (new \DateTimeImmutable())->format('Y');
        $pattern = 'VW-' . $year . '-%';
        $count = (int) $this->em->createQueryBuilder()
            ->select('COUNT(v.id)')
            ->from(Viewing::class, 'v')
            ->where('v.number LIKE :pattern')
            ->setParameter('pattern', $pattern)
            ->getQuery()
            ->getSingleScalarResult();

        return sprintf('VW-%s-%04d', $year, $count + 1);
    }

    /** @return array<string, mixed> */
    public function serialize(Viewing $v, bool $detail = false): array
    {
        $data = [
            'id' => $v->id(),
            'number' => $v->number(),
            'listing_id' => $v->listingId(),
            'customer_id' => $v->customerId(),
            'broker_id' => $v->brokerId(),
            'status' => $v->status(),
            'scheduled_at' => $v->scheduledAt()?->format(\DateTimeInterface::ATOM),
            'contact_name' => $v->contactName(),
            'contact_email' => $v->contactEmail(),
            'contact_phone' => $v->contactPhone(),
            'created_at' => $v->createdAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $v->updatedAt()->format(\DateTimeInterface::ATOM),
        ];
        if ($detail) {
            $data['note'] = $v->note();
        }

        return $data;
    }
}
