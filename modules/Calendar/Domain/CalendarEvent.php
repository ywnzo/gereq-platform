<?php

declare(strict_types=1);

namespace Modules\Calendar\Domain;

use Doctrine\ORM\Mapping as ORM;
use Gereq\Admin\Form\FormEntity;
use Gereq\Admin\Form\FormField;
use Gereq\Admin\Form\FormGenerator;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'calendar_events')]
#[ORM\Index(name: 'idx_calendar_starts', columns: ['starts_at'])]
#[ORM\Index(name: 'idx_calendar_external', columns: ['external_provider', 'external_id'])]
#[FormEntity('calendar.event.save')]
#[FormGenerator(
    exclude: ['id', 'externalProvider', 'externalId', 'externalEtag', 'connectionId', 'createdBy', 'createdAt', 'updatedAt'],
    form: 'calendar.event.save',
    cols: 2,
)]
class CalendarEvent
{
    public const TYPES = ['meeting', 'deadline', 'call', 'task', 'other'];
    public const COLORS = ['blue', 'green', 'amber', 'red', 'violet', 'slate'];
    public const STATUSES = ['confirmed', 'tentative', 'cancelled'];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 190)]
    #[FormField(required: true, label: 'calendar.field.title', group: 'basic', extra: 'field--full', order: 10)]
    private string $title;

    #[ORM\Column(name: 'starts_at')]
    #[FormField(required: true, name: 'starts_at', label: 'calendar.field.starts', type: 'datetime-local', group: 'schedule', order: 10)]
    private \DateTimeImmutable $startsAt;

    #[ORM\Column(name: 'ends_at')]
    #[FormField(required: true, name: 'ends_at', label: 'calendar.field.ends', type: 'datetime-local', group: 'schedule', order: 20)]
    private \DateTimeImmutable $endsAt;

    #[ORM\Column(name: 'all_day', type: 'boolean')]
    #[FormField(label: 'calendar.field.all_day', type: 'checkbox', group: 'schedule', order: 30)]
    private bool $allDay = false;

    #[ORM\Column(length: 32)]
    #[FormField(
        label: 'calendar.field.type',
        type: 'select',
        group: 'basic',
        choicesFrom: 'TYPES',
        choiceLabel: 'calendar.type.',
        order: 20,
    )]
    private string $type = 'other';

    #[ORM\Column(length: 16)]
    #[FormField(
        label: 'calendar.field.color',
        type: 'select',
        group: 'basic',
        choicesFrom: 'COLORS',
        choiceLabel: 'calendar.color.',
        order: 40,
    )]
    private string $color = 'blue';

    #[ORM\Column(length: 16)]
    #[FormField(
        label: 'calendar.field.status',
        type: 'select',
        group: 'basic',
        choicesFrom: 'STATUSES',
        choiceLabel: 'calendar.status.',
        order: 30,
    )]
    private string $status = 'confirmed';

    #[ORM\Column(length: 64, nullable: true)]
    #[FormField(label: 'calendar.field.timezone', group: 'schedule', order: 40)]
    private ?string $timezone = 'Europe/Prague';

    #[ORM\Column(length: 255, nullable: true)]
    #[FormField(label: 'calendar.field.location', group: 'details', extra: 'field--full', order: 10)]
    private ?string $location = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[FormField(label: 'calendar.field.description', type: 'textarea', group: 'details', extra: 'field--full', order: 20)]
    private ?string $description = null;

    /** RFC 5545 RRULE, e.g. FREQ=WEEKLY;BYDAY=MO */
    #[ORM\Column(length: 255, nullable: true)]
    #[FormField(label: 'calendar.field.rrule', group: 'schedule', hint: 'calendar.field.rrule_hint', extra: 'field--full', order: 50)]
    private ?string $rrule = null;

    #[ORM\Column(name: 'external_provider', length: 32, nullable: true)]
    private ?string $externalProvider = null;

    #[ORM\Column(name: 'external_id', length: 190, nullable: true)]
    private ?string $externalId = null;

    #[ORM\Column(name: 'external_etag', length: 190, nullable: true)]
    private ?string $externalEtag = null;

    #[ORM\Column(name: 'connection_id', length: 36, nullable: true)]
    private ?string $connectionId = null;

    #[ORM\Column(name: 'created_by', length: 36, nullable: true)]
    private ?string $createdBy = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $title, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt, string $type = 'other')
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Invalid type');
        }
        $this->id = Uuid::uuid7()->toString();
        $this->title = $title;
        $this->startsAt = $startsAt;
        $this->endsAt = $endsAt;
        $this->type = $type;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function id(): string { return $this->id; }
    public function title(): string { return $this->title; }
    public function startsAt(): \DateTimeImmutable { return $this->startsAt; }
    public function endsAt(): \DateTimeImmutable { return $this->endsAt; }
    public function allDay(): bool { return $this->allDay; }
    public function type(): string { return $this->type; }
    public function color(): string { return $this->color; }
    public function status(): string { return $this->status; }
    public function timezone(): ?string { return $this->timezone; }
    public function location(): ?string { return $this->location; }
    public function description(): ?string { return $this->description; }
    public function rrule(): ?string { return $this->rrule; }
    public function externalProvider(): ?string { return $this->externalProvider; }
    public function externalId(): ?string { return $this->externalId; }
    public function externalEtag(): ?string { return $this->externalEtag; }
    public function connectionId(): ?string { return $this->connectionId; }
    public function createdBy(): ?string { return $this->createdBy; }
    public function createdAt(): \DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function setTitle(string $v): void { $this->title = $v; $this->touch(); }
    public function setStartsAt(\DateTimeImmutable $v): void { $this->startsAt = $v; $this->touch(); }
    public function setEndsAt(\DateTimeImmutable $v): void { $this->endsAt = $v; $this->touch(); }
    public function setAllDay(bool $v): void { $this->allDay = $v; $this->touch(); }
    public function setType(string $v): void
    {
        if (!in_array($v, self::TYPES, true)) {
            throw new \InvalidArgumentException('Invalid type');
        }
        $this->type = $v;
        $this->touch();
    }
    public function setColor(string $v): void
    {
        $this->color = in_array($v, self::COLORS, true) ? $v : 'blue';
        $this->touch();
    }
    public function setStatus(string $v): void
    {
        $this->status = in_array($v, self::STATUSES, true) ? $v : 'confirmed';
        $this->touch();
    }
    public function setTimezone(?string $v): void { $this->timezone = $v !== null && $v !== '' ? $v : 'Europe/Prague'; $this->touch(); }
    public function setLocation(?string $v): void { $this->location = $v !== null && $v !== '' ? $v : null; $this->touch(); }
    public function setDescription(?string $v): void { $this->description = $v !== null && $v !== '' ? $v : null; $this->touch(); }
    public function setRrule(?string $v): void { $this->rrule = $v !== null && $v !== '' ? $v : null; $this->touch(); }
    public function setExternal(?string $provider, ?string $id, ?string $etag = null): void
    {
        $this->externalProvider = $provider;
        $this->externalId = $id;
        $this->externalEtag = $etag;
        $this->touch();
    }
    public function setConnectionId(?string $v): void { $this->connectionId = $v; $this->touch(); }
    public function setCreatedBy(?string $v): void { $this->createdBy = $v; }

    public function typeLabel(): string { return 'calendar.type.' . $this->type; }
    public function statusLabel(): string { return 'calendar.status.' . $this->status; }
    public function isExternal(): bool { return $this->externalProvider !== null && $this->externalId !== null; }

    private function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }
}
