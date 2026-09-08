<?php

declare(strict_types=1);

namespace Modules\BikeService\Domain;

use Doctrine\ORM\Mapping as ORM;
use Gereq\Admin\Form\FormEntity;
use Gereq\Admin\Form\FormField;
use Gereq\Admin\Form\FormGenerator;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'bikeservice_orders')]
#[ORM\Index(name: 'idx_bikeservice_orders_bike', columns: ['bike_id'])]
#[ORM\Index(name: 'idx_bikeservice_orders_customer', columns: ['customer_id'])]
#[ORM\Index(name: 'idx_bikeservice_orders_status', columns: ['status'])]
#[ORM\Index(name: 'idx_bikeservice_orders_team', columns: ['team'])]
#[ORM\UniqueConstraint(name: 'uniq_bikeservice_orders_tracker', columns: ['tracker_token'])]
#[FormEntity('bikeservice.save')]
#[FormGenerator(
    exclude: [
        'id',
        'trackerToken',
        'laborCost',
        'partsCost',
        'paymentType',
        'intakeOdometerKm',
        'intakeBatteryPct',
        'intakeAt',
        'handoverAt',
        'finishedAt',
        'createdAt',
        'updatedAt',
    ],
    form: 'bikeservice.save',
    cols: 2,
)]
class BikeOrder
{
    public const STATUSES = ['intake', 'in_progress', 'waiting', 'ready', 'done', 'cancelled'];
    public const TEAMS = ['mechanics', 'electric'];
    public const PAYMENT_TYPES = ['online', 'cash'];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 32)]
    #[FormField(
        label: 'bikeservice.field.status',
        type: 'select',
        group: 'order',
        choicesFrom: 'STATUSES',
        choiceLabel: 'bikeservice.status.',
        order: 30,
    )]
    private string $status = 'intake';

    #[ORM\Column(length: 32)]
    #[FormField(
        label: 'bikeservice.field.team',
        type: 'select',
        group: 'order',
        choicesFrom: 'TEAMS',
        choiceLabel: 'bikeservice.team.',
        order: 40,
    )]
    private string $team = 'mechanics';

    #[ORM\Column(name: 'bike_id', length: 36)]
    #[FormField(
        required: true,
        name: 'bike_id',
        label: 'bikeservice.field.bike',
        type: 'select',
        group: 'order',
        extra: 'field--full',
        order: 10,
    )]
    private string $bikeId;

    #[ORM\Column(name: 'customer_id', length: 36, nullable: true)]
    #[FormField(
        name: 'customer_id',
        label: 'bikeservice.field.customer',
        type: 'select',
        group: 'order',
        order: 20,
    )]
    private ?string $customerId = null;

    #[ORM\Column(name: 'tracker_token', length: 64)]
    private string $trackerToken;

    #[ORM\Column(name: 'service_info', type: 'text', nullable: true)]
    #[FormField(label: 'bikeservice.field.service_info', type: 'textarea', group: 'work', extra: 'field--full', order: 10)]
    private ?string $serviceInfo = null;

    #[ORM\Column(name: 'payment_type', length: 16, nullable: true)]
    private ?string $paymentType = null;

    #[ORM\Column(name: 'labor_cost', type: 'decimal', precision: 12, scale: 2, nullable: true)]
    private ?string $laborCost = null;

    #[ORM\Column(name: 'parts_cost', type: 'decimal', precision: 12, scale: 2, nullable: true)]
    private ?string $partsCost = null;

    #[ORM\Column(name: 'intake_odometer_km', type: 'integer', nullable: true)]
    private ?int $intakeOdometerKm = null;

    #[ORM\Column(name: 'intake_battery_pct', type: 'integer', nullable: true)]
    private ?int $intakeBatteryPct = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[FormField(label: 'bikeservice.field.note', type: 'textarea', group: 'work', extra: 'field--full', order: 20)]
    private ?string $note = null;

    #[ORM\Column(name: 'finished_at', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(name: 'intake_at', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $intakeAt = null;

    #[ORM\Column(name: 'handover_at', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $handoverAt = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $bikeId)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->bikeId = $bikeId;
        $this->trackerToken = bin2hex(random_bytes(16));
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function team(): string
    {
        return $this->team;
    }

    public function bikeId(): string
    {
        return $this->bikeId;
    }

    public function customerId(): ?string
    {
        return $this->customerId;
    }

    public function trackerToken(): string
    {
        return $this->trackerToken;
    }

    public function serviceInfo(): ?string
    {
        return $this->serviceInfo;
    }

    public function paymentType(): ?string
    {
        return $this->paymentType;
    }

    public function laborCost(): ?string
    {
        return $this->laborCost;
    }

    public function partsCost(): ?string
    {
        return $this->partsCost;
    }

    public function intakeOdometerKm(): ?int
    {
        return $this->intakeOdometerKm;
    }

    public function intakeBatteryPct(): ?int
    {
        return $this->intakeBatteryPct;
    }

    public function note(): ?string
    {
        return $this->note;
    }

    public function finishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function intakeAt(): ?\DateTimeImmutable
    {
        return $this->intakeAt;
    }

    public function handoverAt(): ?\DateTimeImmutable
    {
        return $this->handoverAt;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isOpen(): bool
    {
        return !in_array($this->status, ['done', 'cancelled'], true);
    }

    public function statusLabel(): string
    {
        return 'bikeservice.status.' . $this->status;
    }

    public function teamLabel(): string
    {
        return 'bikeservice.team.' . $this->team;
    }

    public function setBikeId(string $bikeId): void
    {
        $this->bikeId = $bikeId;
        $this->touch();
    }

    public function setCustomerId(?string $customerId): void
    {
        $this->customerId = $customerId !== null && $customerId !== '' ? $customerId : null;
        $this->touch();
    }

    public function setStatus(string $status): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('bikeservice.error.invalid_status');
        }
        $this->status = $status;
        if ($status === 'done' && $this->finishedAt === null) {
            $this->finishedAt = new \DateTimeImmutable();
        }
        $this->touch();
    }

    public function setTeam(string $team): void
    {
        if (!in_array($team, self::TEAMS, true)) {
            throw new \InvalidArgumentException('bikeservice.error.invalid_team');
        }
        $this->team = $team;
        $this->touch();
    }

    public function setServiceInfo(?string $info): void
    {
        $this->serviceInfo = $info !== null && trim($info) !== '' ? trim($info) : null;
        $this->touch();
    }

    public function setPaymentType(?string $type): void
    {
        if ($type !== null && $type !== '' && !in_array($type, self::PAYMENT_TYPES, true)) {
            throw new \InvalidArgumentException('bikeservice.error.invalid_payment');
        }
        $this->paymentType = $type !== null && $type !== '' ? $type : null;
        $this->touch();
    }

    public function setLaborCost(null|float|string $cost): void
    {
        $this->laborCost = $this->normalizeMoney($cost);
        $this->touch();
    }

    public function setPartsCost(null|float|string $cost): void
    {
        $this->partsCost = $this->normalizeMoney($cost);
        $this->touch();
    }

    public function setIntakeOdometerKm(?int $km): void
    {
        $this->intakeOdometerKm = $km;
        $this->touch();
    }

    public function setIntakeBatteryPct(?int $pct): void
    {
        if ($pct !== null) {
            $pct = max(0, min(100, $pct));
        }
        $this->intakeBatteryPct = $pct;
        $this->touch();
    }

    public function setNote(?string $note): void
    {
        $this->note = $note !== null && trim($note) !== '' ? trim($note) : null;
        $this->touch();
    }

    public function setIntakeAt(?\DateTimeImmutable $at): void
    {
        $this->intakeAt = $at;
        $this->touch();
    }

    public function setHandoverAt(?\DateTimeImmutable $at): void
    {
        $this->handoverAt = $at;
        $this->touch();
    }

    public function finish(): void
    {
        $this->status = 'done';
        $this->finishedAt = new \DateTimeImmutable();
        $this->touch();
    }

    private function normalizeMoney(null|float|string $cost): ?string
    {
        if ($cost === null || $cost === '') {
            return null;
        }

        return number_format((float) $cost, 2, '.', '');
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
