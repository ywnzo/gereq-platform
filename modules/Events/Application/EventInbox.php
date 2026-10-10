<?php

declare(strict_types=1);

namespace Modules\Events\Application;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Events\Domain\EventContact;

final class EventInbox
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function submit(array $data): EventContact
    {
        $contact = new EventContact(
            (string) ($data['name'] ?? ''),
            (string) ($data['email'] ?? ''),
            (string) ($data['topic'] ?? 'other'),
            (string) ($data['message'] ?? ''),
        );
        $this->em->persist($contact);
        $this->em->flush();

        return $contact;
    }

    /**
     * @return array{items: list<EventContact>, total: int}
     */
    public function search(string $query, int $page, int $perPage): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('c')
            ->from(EventContact::class, 'c')
            ->orderBy('c.createdAt', 'DESC');
        $term = trim($query);
        if ($term !== '') {
            $qb->andWhere('c.name LIKE :q OR c.email LIKE :q OR c.message LIKE :q')
                ->setParameter('q', '%' . $term . '%');
        }
        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(c.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $items = $qb
            ->setFirstResult(max(0, ($page - 1) * $perPage))
            ->setMaxResults(max(1, $perPage))
            ->getQuery()
            ->getResult();

        return [
            'items' => array_values(array_filter(
                $items,
                static fn ($row): bool => $row instanceof EventContact,
            )),
            'total' => $total,
        ];
    }

    public function find(string $id): ?EventContact
    {
        $item = $this->em->find(EventContact::class, $id);

        return $item instanceof EventContact ? $item : null;
    }

    public function delete(EventContact $item): void
    {
        $this->em->remove($item);
        $this->em->flush();
    }

    public function serialize(EventContact $item): array
    {
        return [
            'id' => $item->id(),
            'name' => $item->name(),
            'email' => $item->email(),
            'topic' => $item->topic(),
            'message' => $item->message(),
            'created_at' => $item->createdAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
