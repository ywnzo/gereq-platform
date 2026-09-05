<?php
declare(strict_types=1);
namespace Modules\Calendar\Domain;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'calendar_attendees')]
class CalendarAttendee
{
    #[ORM\Id] #[ORM\Column(length: 36)] private string $id;
    #[ORM\Column(name: 'event_id', length: 36)] private string $eventId;
    #[ORM\Column(name: 'user_id', length: 36, nullable: true)] private ?string $userId = null;
    #[ORM\Column(length: 190, nullable: true)] private ?string $email = null;

    public function __construct(string $eventId, ?string $userId = null, ?string $email = null) {
        $this->id = Uuid::uuid7()->toString();
        $this->eventId = $eventId; $this->userId = $userId; $this->email = $email;
    }
    public function id(): string { return $this->id; }
    public function eventId(): string { return $this->eventId; }
    public function userId(): ?string { return $this->userId; }
    public function email(): ?string { return $this->email; }
}