<?php

declare(strict_types=1);

namespace Modules\Calendar\Application;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Calendar\Domain\CalendarConnection;
use Modules\Calendar\Domain\CalendarEvent;

final class CalendarProvider
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    /** @return list<CalendarEvent> */
    public function between(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->em->getRepository(CalendarEvent::class)->createQueryBuilder('e')
            ->where('e.startsAt < :to AND e.endsAt > :from')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('e.startsAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function find(string $id): ?CalendarEvent
    {
        $e = $this->em->find(CalendarEvent::class, $id);

        return $e instanceof CalendarEvent ? $e : null;
    }

    /** @return list<CalendarConnection> */
    public function connections(?string $userId = null): array
    {
        $qb = $this->em->getRepository(CalendarConnection::class)->createQueryBuilder('c')
            ->orderBy('c.provider', 'ASC')
            ->addOrderBy('c.accountEmail', 'ASC');
        if ($userId !== null) {
            $qb->andWhere('c.userId = :uid OR c.userId IS NULL')->setParameter('uid', $userId);
        }

        return $qb->getQuery()->getResult();
    }

    /** @return array<string, mixed> */
    public function serialize(CalendarEvent $e): array
    {
        return [
            'id' => $e->id(),
            'title' => $e->title(),
            // Wall-clock local time (no Z/+00:00) — calendar stores civil time in event TZ.
            'starts_at' => $e->startsAt()->format('Y-m-d\TH:i:s'),
            'ends_at' => $e->endsAt()->format('Y-m-d\TH:i:s'),
            'all_day' => $e->allDay(),
            'type' => $e->type(),
            'color' => $e->color(),
            'status' => $e->status(),
            'timezone' => $e->timezone(),
            'location' => $e->location(),
            'description' => $e->description(),
            'rrule' => $e->rrule(),
            'external_provider' => $e->externalProvider(),
            'external_id' => $e->externalId(),
            'is_external' => $e->isExternal(),
            'created_at' => $e->createdAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $e->updatedAt()->format(\DateTimeInterface::ATOM),
            'url' => '/admin/calendar/' . $e->id(),
            'edit_url' => '/admin/calendar/' . $e->id() . '/edit',
        ];
    }
}
