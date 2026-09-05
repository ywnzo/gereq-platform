<?php
declare(strict_types=1);
namespace Modules\Calendar\Application;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Calendar\Domain\CalendarEvent;

final class CalendarProvider
{
    public function __construct(private readonly EntityManagerInterface $em) {}
    /** @return list<CalendarEvent> */
    public function between(\DateTimeImmutable $from, \DateTimeImmutable $to): array {
        return $this->em->getRepository(CalendarEvent::class)->createQueryBuilder('e')
            ->where('e.startsAt < :to AND e.endsAt > :from')
            ->setParameter('from', $from)->setParameter('to', $to)
            ->orderBy('e.startsAt', 'ASC')->getQuery()->getResult();
    }
}