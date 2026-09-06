<?php

declare(strict_types=1);

namespace Modules\Fleet\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Module\CapabilityRegistry;
use Modules\Calendar\Domain\CalendarEvent;
use Modules\Calendar\Domain\CalendarEventLink;
use Modules\Fleet\Domain\Vehicle;

final class VehicleCalendarService
{
    public function __construct(
        private readonly CapabilityRegistry $capabilities,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function available(): bool
    {
        return $this->capabilities->has('calendar') && class_exists(CalendarEvent::class);
    }

    public function syncReminders(Vehicle $vehicle): void
    {
        if (!$this->available()) {
            return;
        }
        if ($vehicle->stkAt() !== null) {
            $this->upsert($vehicle, 'stk', 'STK ' . $vehicle->plate(), $vehicle->stkAt(), 'amber');
        }
        if ($vehicle->insuranceUntil() !== null) {
            $this->upsert($vehicle, 'insurance', 'Pojištění ' . $vehicle->plate(), $vehicle->insuranceUntil(), 'red');
        }
        if ($vehicle->vignetteUntil() !== null) {
            $this->upsert($vehicle, 'vignette', 'Známka ' . $vehicle->plate(), $vehicle->vignetteUntil(), 'blue');
        }
        if ($vehicle->tiresAt() !== null) {
            $this->upsert($vehicle, 'tires', 'Pneu ' . $vehicle->plate(), $vehicle->tiresAt(), 'green');
        }
        if ($vehicle->warrantyUntil() !== null) {
            $this->upsert($vehicle, 'warranty', 'Garanční kontrola ' . $vehicle->plate(), $vehicle->warrantyUntil(), 'purple');
        }
    }

    private function upsert(Vehicle $vehicle, string $kind, string $title, \DateTimeImmutable $day, string $color): void
    {
        $externalId = 'fleet:' . $vehicle->id() . ':' . $kind;
        $existing = $this->em->getRepository(CalendarEvent::class)->findOneBy([
            'externalProvider' => 'fleet',
            'externalId' => $externalId,
        ]);
        $start = $day->setTime(8, 0);
        $end = $day->setTime(9, 0);
        if ($existing instanceof CalendarEvent) {
            $existing->setTitle($title);
            $existing->setStartsAt($start);
            $existing->setEndsAt($end);
            $this->em->flush();

            return;
        }
        $event = new CalendarEvent($title, $start, $end, 'deadline');
        $event->setAllDay(true);
        $event->setColor($color);
        $event->setExternal('fleet', $externalId);
        $this->em->persist($event);
        $this->em->persist(new CalendarEventLink($event->id(), 'fleet_vehicle', $vehicle->id()));
        $this->em->flush();
    }
}
