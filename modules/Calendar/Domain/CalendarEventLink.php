<?php
declare(strict_types=1);
namespace Modules\Calendar\Domain;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'calendar_event_links')]
#[ORM\Index(name: 'idx_cal_link_entity', columns: ['entity_type', 'entity_id'])]
class CalendarEventLink
{
    #[ORM\Id] #[ORM\Column(length: 36)] private string $id;
    #[ORM\Column(name: 'event_id', length: 36)] private string $eventId;
    #[ORM\Column(name: 'entity_type', length: 64)] private string $entityType;
    #[ORM\Column(name: 'entity_id', length: 36)] private string $entityId;

    public function __construct(string $eventId, string $entityType, string $entityId) {
        $this->id = Uuid::uuid7()->toString();
        $this->eventId = $eventId; $this->entityType = $entityType; $this->entityId = $entityId;
    }
    public function id(): string { return $this->id; }
    public function eventId(): string { return $this->eventId; }
    public function entityType(): string { return $this->entityType; }
    public function entityId(): string { return $this->entityId; }
}