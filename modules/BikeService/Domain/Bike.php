<?php

declare(strict_types=1);

namespace Modules\BikeService\Domain;

use Doctrine\ORM\Mapping as ORM;
use Gereq\Admin\Form\FormEntity;
use Gereq\Admin\Form\FormField;
use Gereq\Admin\Form\FormGenerator;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'bikeservice_bikes')]
#[ORM\Index(name: 'idx_bikeservice_bikes_customer', columns: ['customer_id'])]
#[ORM\Index(name: 'idx_bikeservice_bikes_serial', columns: ['serial_number'])]
#[ORM\Index(name: 'idx_bikeservice_bikes_status', columns: ['status'])]
#[ORM\Index(name: 'idx_bikeservice_bikes_kind', columns: ['kind'])]
#[FormEntity('bikeservice.bike')]
#[FormGenerator(
    exclude: ['id', 'createdAt', 'updatedAt'],
    form: 'bikeservice.bike',
    cols: 2,
)]
class Bike
{
    public const KINDS = ['bike', 'ebike', 'cargo', 'scooter'];
    public const STATUSES = ['active', 'in_service', 'sold', 'archived'];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 32)]
    #[FormField(
        label: 'bikeservice.field.kind',
        type: 'select',
        group: 'basic',
        choicesFrom: 'KINDS',
        choiceLabel: 'bikeservice.kind.',
        order: 30,
    )]
    private string $kind = 'bike';

    #[ORM\Column(length: 32)]
    #[FormField(
        label: 'bikeservice.field.status',
        type: 'select',
        group: 'basic',
        choicesFrom: 'STATUSES',
        choiceLabel: 'bikeservice.bike_status.',
        order: 40,
    )]
    private string $status = 'active';

    #[ORM\Column(length: 120)]
    #[FormField(required: true, label: 'bikeservice.field.brand', group: 'basic', order: 10)]
    private string $brand;

    #[ORM\Column(length: 120, nullable: true)]
    #[FormField(label: 'bikeservice.field.model', group: 'basic', order: 20)]
    private ?string $model = null;

    #[ORM\Column(name: 'serial_number', length: 120, nullable: true)]
    #[FormField(label: 'bikeservice.field.serial', name: 'serial_number', group: 'basic', order: 50)]
    private ?string $serialNumber = null;

    #[ORM\Column(length: 64, nullable: true)]
    #[FormField(label: 'bikeservice.field.color', group: 'basic', order: 60)]
    private ?string $color = null;

    #[ORM\Column(name: 'frame_size', length: 32, nullable: true)]
    #[FormField(label: 'bikeservice.field.frame_size', name: 'frame_size', group: 'basic', order: 70)]
    private ?string $frameSize = null;

    #[ORM\Column(name: 'battery_wh', type: 'integer', nullable: true)]
    #[FormField(label: 'bikeservice.field.battery_wh', type: 'number', name: 'battery_wh', group: 'ebike', order: 10)]
    private ?int $batteryWh = null;

    #[ORM\Column(name: 'motor_w', type: 'integer', nullable: true)]
    #[FormField(label: 'bikeservice.field.motor_w', type: 'number', name: 'motor_w', group: 'ebike', order: 20)]
    private ?int $motorW = null;

    #[ORM\Column(name: 'odometer_km', type: 'integer', nullable: true)]
    #[FormField(label: 'bikeservice.field.odometer', type: 'number', name: 'odometer_km', group: 'ebike', order: 30)]
    private ?int $odometerKm = null;

    #[ORM\Column(name: 'customer_id', length: 36, nullable: true)]
    #[FormField(label: 'bikeservice.field.customer', type: 'select', name: 'customer_id', group: 'basic', order: 80)]
    private ?string $customerId = null;

    #[ORM\Column(name: 'bike_index_id', type: 'integer', nullable: true)]
    #[FormField(label: 'bikeservice.field.bike_index_id', type: 'number', name: 'bike_index_id', group: 'basic', order: 90)]
    private ?int $bikeIndexId = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[FormField(label: 'bikeservice.field.note', type: 'textarea', group: 'basic', extra: 'field--full', order: 100)]
    private ?string $note = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $brand, string $kind = 'bike')
    {
        $this->id = Uuid::uuid7()->toString();
        $this->brand = trim($brand);
        $this->setKind($kind);
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function brand(): string
    {
        return $this->brand;
    }

    public function model(): ?string
    {
        return $this->model;
    }

    public function serialNumber(): ?string
    {
        return $this->serialNumber;
    }

    public function color(): ?string
    {
        return $this->color;
    }

    public function frameSize(): ?string
    {
        return $this->frameSize;
    }

    public function batteryWh(): ?int
    {
        return $this->batteryWh;
    }

    public function motorW(): ?int
    {
        return $this->motorW;
    }

    public function odometerKm(): ?int
    {
        return $this->odometerKm;
    }

    public function customerId(): ?string
    {
        return $this->customerId;
    }

    public function bikeIndexId(): ?int
    {
        return $this->bikeIndexId;
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

    public function label(): string
    {
        $parts = array_filter([$this->brand, $this->model, $this->serialNumber]);

        return implode(' · ', $parts) ?: $this->id;
    }

    public function setKind(string $kind): void
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('bikeservice.error.invalid_kind');
        }
        $this->kind = $kind;
        $this->touch();
    }

    public function setStatus(string $status): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('bikeservice.error.invalid_status');
        }
        $this->status = $status;
        $this->touch();
    }

    public function setBrand(string $brand): void
    {
        $this->brand = trim($brand);
        $this->touch();
    }

    public function setModel(?string $model): void
    {
        $this->model = $model !== null && trim($model) !== '' ? trim($model) : null;
        $this->touch();
    }

    public function setSerialNumber(?string $serial): void
    {
        $this->serialNumber = $serial !== null && trim($serial) !== '' ? trim($serial) : null;
        $this->touch();
    }

    public function setColor(?string $color): void
    {
        $this->color = $color !== null && trim($color) !== '' ? trim($color) : null;
        $this->touch();
    }

    public function setFrameSize(?string $frameSize): void
    {
        $this->frameSize = $frameSize !== null && trim($frameSize) !== '' ? trim($frameSize) : null;
        $this->touch();
    }

    public function setBatteryWh(?int $wh): void
    {
        $this->batteryWh = $wh;
        $this->touch();
    }

    public function setMotorW(?int $w): void
    {
        $this->motorW = $w;
        $this->touch();
    }

    public function setOdometerKm(?int $km): void
    {
        $this->odometerKm = $km;
        $this->touch();
    }

    public function setCustomerId(?string $customerId): void
    {
        $this->customerId = $customerId !== null && $customerId !== '' ? $customerId : null;
        $this->touch();
    }

    public function setBikeIndexId(?int $id): void
    {
        $this->bikeIndexId = $id;
        $this->touch();
    }

    public function setNote(?string $note): void
    {
        $this->note = $note !== null && trim($note) !== '' ? trim($note) : null;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
