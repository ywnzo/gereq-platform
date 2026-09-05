<?php

declare(strict_types=1);

namespace Modules\CarService\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Module\CapabilityRegistry;
use Modules\Calendar\Domain\CalendarEvent;
use Modules\Calendar\Domain\CalendarEventLink;
use Modules\CarService\Domain\ServiceOrder;
use Modules\Fleet\Domain\Vehicle;

final class OrderCalendarService
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

    public function syncIntakeHandover(ServiceOrder $order, ?\DateTimeImmutable $intakeAt, ?\DateTimeImmutable $handoverAt): void
    {
        if (!$this->available()) {
            return;
        }
        $vehicle = $this->em->find(Vehicle::class, $order->vehicleId());
        $label = $vehicle instanceof Vehicle ? $vehicle->plate() : substr($order->id(), 0, 8);
        if ($intakeAt !== null) {
            $this->upsertLinkedEvent($order->id(), 'intake', 'Příjem ' . $label, $intakeAt, 'green');
        }
        if ($handoverAt !== null) {
            $this->upsertLinkedEvent($order->id(), 'handover', 'Předání ' . $label, $handoverAt, 'amber');
        }
    }

    private function upsertLinkedEvent(string $orderId, string $kind, string $title, \DateTimeImmutable $day, string $color): void
    {
        $externalId = 'carservice:' . $orderId . ':' . $kind;
        $existing = $this->em->getRepository(CalendarEvent::class)->findOneBy([
            'externalProvider' => 'carservice',
            'externalId' => $externalId,
        ]);
        $start = $day->setTime(9, 0);
        $end = $day->setTime(10, 0);
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
        $event->setExternal('carservice', $externalId);
        $this->em->persist($event);
        $this->em->persist(new CalendarEventLink($event->id(), 'carservice_order', $orderId));
        $this->em->flush();
    }
}
