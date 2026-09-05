<?php
declare(strict_types=1);
namespace Modules\Calendar\Domain;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'calendar_events')]
#[ORM\Index(name: 'idx_calendar_starts', columns: ['starts_at'])]
class CalendarEvent
{
    public const TYPES = ['meeting', 'deadline', 'other'];
    #[ORM\Id] #[ORM\Column(length: 36)] private string $id;
    #[ORM\Column(length: 190)] private string $title;
    #[ORM\Column(name: 'starts_at')] private \DateTimeImmutable $startsAt;
    #[ORM\Column(name: 'ends_at')] private \DateTimeImmutable $endsAt;
    #[ORM\Column(name: 'all_day', type: 'boolean')] private bool $allDay = false;
    #[ORM\Column(length: 32)] private string $type = 'other';
    #[ORM\Column(type: 'text', nullable: true)] private ?string $description = null;
    #[ORM\Column(name: 'created_at')] private \DateTimeImmutable $createdAt;
    #[ORM\Column(name: 'updated_at')] private \DateTimeImmutable $updatedAt;

    public function __construct(string $title, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt, string $type = 'other') {
        if (!in_array($type, self::TYPES, true)) throw new \InvalidArgumentException('Invalid type');
        $this->id = Uuid::uuid7()->toString();
        $this->title = $title; $this->startsAt = $startsAt; $this->endsAt = $endsAt; $this->type = $type;
        $this->createdAt = new \DateTimeImmutable(); $this->updatedAt = new \DateTimeImmutable();
    }
    public function id(): string { return $this->id; }
    public function title(): string { return $this->title; }
    public function startsAt(): \DateTimeImmutable { return $this->startsAt; }
    public function endsAt(): \DateTimeImmutable { return $this->endsAt; }
    public function allDay(): bool { return $this->allDay; }
    public function type(): string { return $this->type; }
    public function description(): ?string { return $this->description; }
    public function createdAt(): \DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function setTitle(string $v): void { $this->title = $v; $this->touch(); }
    public function setStartsAt(\DateTimeImmutable $v): void { $this->startsAt = $v; $this->touch(); }
    public function setEndsAt(\DateTimeImmutable $v): void { $this->endsAt = $v; $this->touch(); }
    public function setAllDay(bool $v): void { $this->allDay = $v; $this->touch(); }
    public function setType(string $v): void { if (!in_array($v, self::TYPES, true)) throw new \InvalidArgumentException('Invalid type'); $this->type = $v; $this->touch(); }
    public function setDescription(?string $v): void { $this->description = $v !== '' ? $v : null; $this->touch(); }
    public function typeLabel(): string { return 'calendar.type.' . $this->type; }
    private function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }
}