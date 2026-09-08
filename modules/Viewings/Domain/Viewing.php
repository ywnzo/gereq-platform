<?php

declare(strict_types=1);

namespace Modules\Viewings\Domain;

use Doctrine\ORM\Mapping as ORM;
use Gereq\Admin\Form\FormEntity;
use Gereq\Admin\Form\FormField;
use Gereq\Admin\Form\FormGenerator;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'viewings')]
#[ORM\Index(name: 'idx_viewings_listing', columns: ['listing_id'])]
#[ORM\Index(name: 'idx_viewings_status', columns: ['status'])]
#[ORM\Index(name: 'idx_viewings_scheduled', columns: ['scheduled_at'])]
#[FormEntity('viewings.save')]
#[FormGenerator(
    exclude: ['id', 'number', 'createdAt', 'updatedAt'],
    form: 'viewings.save',
    cols: 2,
)]
class Viewing
{
    public const STATUSES = ['requested', 'scheduled', 'confirmed', 'completed', 'cancelled', 'no_show'];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 64)]
    private string $number;

    #[ORM\Column(name: 'listing_id', length: 36)]
    #[FormField(
        required: true,
        name: 'listing_id',
        label: 'viewings.field.listing',
        type: 'select',
        options: ['searchable' => true],
        extra: 'field--full',
        order: 10,
    )]
    private string $listingId;

    #[ORM\Column(name: 'customer_id', length: 36, nullable: true)]
    #[FormField(
        label: 'viewings.field.customer',
        type: 'select',
        name: 'customer_id',
        options: ['searchable' => true],
        order: 70,
    )]
    private ?string $customerId = null;

    #[ORM\Column(name: 'broker_id', length: 36, nullable: true)]
    #[FormField(
        label: 'viewings.field.broker',
        type: 'select',
        name: 'broker_id',
        options: ['searchable' => true],
        order: 80,
    )]
    private ?string $brokerId = null;

    #[ORM\Column(length: 32)]
    #[FormField(
        label: 'viewings.field.status',
        type: 'select',
        choicesFrom: 'STATUSES',
        choiceLabel: 'viewings.status.',
        order: 60,
    )]
    private string $status = 'requested';

    #[ORM\Column(name: 'scheduled_at', nullable: true)]
    #[FormField(label: 'viewings.field.scheduled_at', type: 'datetime-local', name: 'scheduled_at', order: 50)]
    private ?\DateTimeImmutable $scheduledAt = null;

    #[ORM\Column(name: 'contact_name', length: 190)]
    #[FormField(required: true, name: 'contact_name', label: 'viewings.field.contact_name', order: 20)]
    private string $contactName;

    #[ORM\Column(name: 'contact_email', length: 190, nullable: true)]
    #[FormField(label: 'viewings.field.contact_email', type: 'email', name: 'contact_email', order: 30)]
    private ?string $contactEmail = null;

    #[ORM\Column(name: 'contact_phone', length: 64, nullable: true)]
    #[FormField(label: 'viewings.field.contact_phone', name: 'contact_phone', order: 40)]
    private ?string $contactPhone = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[FormField(label: 'viewings.field.note', type: 'textarea', extra: 'field--full', order: 90)]
    private ?string $note = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $number, string $listingId, string $contactName)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->number = $number;
        $this->listingId = $listingId;
        $this->contactName = $contactName;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function number(): string
    {
        return $this->number;
    }

    public function listingId(): string
    {
        return $this->listingId;
    }

    public function customerId(): ?string
    {
        return $this->customerId;
    }

    public function brokerId(): ?string
    {
        return $this->brokerId;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function scheduledAt(): ?\DateTimeImmutable
    {
        return $this->scheduledAt;
    }

    public function contactName(): string
    {
        return $this->contactName;
    }

    public function contactEmail(): ?string
    {
        return $this->contactEmail;
    }

    public function contactPhone(): ?string
    {
        return $this->contactPhone;
    }

    public function note(): ?string
    {
        return $this->note;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setListingId(string $listingId): void
    {
        $this->listingId = $listingId;
        $this->touch();
    }

    public function setCustomerId(?string $customerId): void
    {
        $this->customerId = $customerId !== null && $customerId !== '' ? $customerId : null;
        $this->touch();
    }

    public function setBrokerId(?string $brokerId): void
    {
        $this->brokerId = $brokerId !== null && $brokerId !== '' ? $brokerId : null;
        $this->touch();
    }

    public function setStatus(string $status): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('viewings.error.invalid_status');
        }
        $this->status = $status;
        $this->touch();
    }

    public function setScheduledAt(?\DateTimeImmutable $scheduledAt): void
    {
        $this->scheduledAt = $scheduledAt;
        $this->touch();
    }

    public function setContactName(string $contactName): void
    {
        $this->contactName = $contactName;
        $this->touch();
    }

    public function setContactEmail(?string $contactEmail): void
    {
        $this->contactEmail = $contactEmail !== null && $contactEmail !== '' ? $contactEmail : null;
        $this->touch();
    }

    public function setContactPhone(?string $contactPhone): void
    {
        $this->contactPhone = $contactPhone !== null && $contactPhone !== '' ? $contactPhone : null;
        $this->touch();
    }

    public function setNote(?string $note): void
    {
        $this->note = $note !== null && $note !== '' ? $note : null;
        $this->touch();
    }

    public function statusLabel(): string
    {
        return 'viewings.status.' . $this->status;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
