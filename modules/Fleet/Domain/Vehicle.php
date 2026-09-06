<?php

declare(strict_types=1);

namespace Modules\Fleet\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'fleet_vehicles')]
#[ORM\Index(name: 'idx_fleet_vehicles_plate', columns: ['plate'])]
#[ORM\Index(name: 'idx_fleet_vehicles_status', columns: ['status'])]
#[ORM\Index(name: 'idx_fleet_vehicles_customer', columns: ['customer_id'])]
class Vehicle
{
    public const STATUSES = ['available', 'rented', 'in_service', 'repair_wait', 'out_of_service'];
    public const OWNERSHIPS = ['owned', 'external'];
    public const FUELS = ['petrol', 'diesel', 'lpg', 'cng', 'hybrid', 'electric', 'other'];
    public const GEARBOXES = ['manual', 'automatic'];
    /** RPS tankValue — textové úrovně nádrže. */
    public const FUEL_LEVELS = ['full', 'three_quarters', 'half', 'quarter', 'empty'];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 32)]
    private string $plate;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $vin = null;

    /** ORV / technický průkaz (dataovozidlech). */
    #[ORM\Column(name: 'orv_id', length: 64, nullable: true)]
    private ?string $orvId = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $brand = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $model = null;

    #[ORM\Column(name: 'brand_id', length: 36, nullable: true)]
    private ?string $brandId = null;

    #[ORM\Column(name: 'model_id', length: 36, nullable: true)]
    private ?string $modelId = null;

    #[ORM\Column(name: 'model_year', type: 'integer', nullable: true)]
    private ?int $modelYear = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $color = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $fuel = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $gearbox = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $motorization = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $mileage = null;

    #[ORM\Column(name: 'fuel_level', length: 16, nullable: true)]
    private ?string $fuelLevel = null;

    #[ORM\Column(name: 'condition_json', type: 'json', nullable: true)]
    private ?array $conditionJson = null;

    #[ORM\Column(length: 32)]
    private string $status = 'available';

    #[ORM\Column(length: 16)]
    private string $ownership = 'owned';

    #[ORM\Column(name: 'customer_id', length: 36, nullable: true)]
    private ?string $customerId = null;

    #[ORM\Column(name: 'branch_id', length: 36, nullable: true)]
    private ?string $branchId = null;

    #[ORM\Column(name: 'stk_at', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $stkAt = null;

    #[ORM\Column(name: 'insurance_until', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $insuranceUntil = null;

    #[ORM\Column(name: 'vignette_until', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $vignetteUntil = null;

    #[ORM\Column(name: 'tires_at', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $tiresAt = null;

    #[ORM\Column(name: 'warranty_until', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $warrantyUntil = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    /** Extra technická data z ORV (karoserie, emise, výkon, …). */
    #[ORM\Column(name: 'tech_json', type: 'json', nullable: true)]
    private ?array $techJson = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $plate, string $ownership = 'owned')
    {
        $this->id = Uuid::uuid7()->toString();
        $this->plate = self::normalizePlate($plate);
        $this->setOwnership($ownership);
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public static function normalizePlate(string $plate): string
    {
        return strtoupper(preg_replace('/\s+/', '', trim($plate)) ?? '');
    }

    public function id(): string
    {
        return $this->id;
    }

    public function plate(): string
    {
        return $this->plate;
    }

    public function vin(): ?string
    {
        return $this->vin;
    }

    public function orvId(): ?string
    {
        return $this->orvId;
    }

    public function brand(): ?string
    {
        return $this->brand;
    }

    public function model(): ?string
    {
        return $this->model;
    }

    public function brandId(): ?string
    {
        return $this->brandId;
    }

    public function modelId(): ?string
    {
        return $this->modelId;
    }

    public function modelYear(): ?int
    {
        return $this->modelYear;
    }

    public function color(): ?string
    {
        return $this->color;
    }

    public function fuel(): ?string
    {
        return $this->fuel;
    }

    public function gearbox(): ?string
    {
        return $this->gearbox;
    }

    public function motorization(): ?string
    {
        return $this->motorization;
    }

    public function mileage(): ?int
    {
        return $this->mileage;
    }

    public function fuelLevel(): ?string
    {
        return $this->fuelLevel;
    }

    /** @return array<string, mixed>|null */
    public function conditionJson(): ?array
    {
        return $this->conditionJson;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function ownership(): string
    {
        return $this->ownership;
    }

    public function customerId(): ?string
    {
        return $this->customerId;
    }

    public function branchId(): ?string
    {
        return $this->branchId;
    }

    public function stkAt(): ?\DateTimeImmutable
    {
        return $this->stkAt;
    }

    public function insuranceUntil(): ?\DateTimeImmutable
    {
        return $this->insuranceUntil;
    }

    public function vignetteUntil(): ?\DateTimeImmutable
    {
        return $this->vignetteUntil;
    }

    public function tiresAt(): ?\DateTimeImmutable
    {
        return $this->tiresAt;
    }

    public function warrantyUntil(): ?\DateTimeImmutable
    {
        return $this->warrantyUntil;
    }

    public function note(): ?string
    {
        return $this->note;
    }

    /** @return array<string, mixed>|null */
    public function techJson(): ?array
    {
        return $this->techJson;
    }

    public function tech(string $key, mixed $default = null): mixed
    {
        return $this->techJson[$key] ?? $default;
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
        $parts = array_filter([$this->brand, $this->model, $this->plate]);

        return implode(' ', $parts) ?: $this->plate;
    }

    public function statusLabel(): string
    {
        return 'fleet.status.' . $this->status;
    }

    public function ownershipLabel(): string
    {
        return 'fleet.ownership.' . $this->ownership;
    }

    public function setPlate(string $v): void
    {
        $this->plate = self::normalizePlate($v);
        $this->touch();
    }

    public function setVin(?string $v): void
    {
        $this->vin = $v !== null && $v !== '' ? strtoupper(trim($v)) : null;
        $this->touch();
    }

    public function setOrvId(?string $v): void
    {
        $this->orvId = $v !== null && $v !== '' ? trim($v) : null;
        $this->touch();
    }

    public function setBrand(?string $v): void
    {
        $this->brand = $v !== null && $v !== '' ? trim($v) : null;
        $this->touch();
    }

    public function setModel(?string $v): void
    {
        $this->model = $v !== null && $v !== '' ? trim($v) : null;
        $this->touch();
    }

    public function setBrandId(?string $v): void
    {
        $this->brandId = $v !== null && $v !== '' ? $v : null;
        $this->touch();
    }

    public function setModelId(?string $v): void
    {
        $this->modelId = $v !== null && $v !== '' ? $v : null;
        $this->touch();
    }

    public function setModelYear(?int $v): void
    {
        $this->modelYear = $v;
        $this->touch();
    }

    public function setColor(?string $v): void
    {
        $this->color = $v !== null && $v !== '' ? trim($v) : null;
        $this->touch();
    }

    public function setFuel(?string $v): void
    {
        if ($v !== null && $v !== '' && !in_array($v, self::FUELS, true)) {
            // allow free-text from ORV lookup when not in enum
            $this->fuel = trim($v);
        } else {
            $this->fuel = $v !== null && $v !== '' ? $v : null;
        }
        $this->touch();
    }

    public function setGearbox(?string $v): void
    {
        if ($v !== null && $v !== '' && !in_array($v, self::GEARBOXES, true)) {
            throw new \InvalidArgumentException('fleet.error.invalid_gearbox');
        }
        $this->gearbox = $v !== null && $v !== '' ? $v : null;
        $this->touch();
    }

    public function setMotorization(?string $v): void
    {
        $this->motorization = $v !== null && $v !== '' ? trim($v) : null;
        $this->touch();
    }

    public function setMileage(?int $v): void
    {
        $this->mileage = $v;
        $this->touch();
    }

    public function setFuelLevel(?string $v): void
    {
        $this->fuelLevel = $v !== null && $v !== '' ? trim($v) : null;
        $this->touch();
    }

    /** @param array<string, mixed>|null $v */
    public function setConditionJson(?array $v): void
    {
        $this->conditionJson = $v !== null && $v !== [] ? $v : null;
        $this->touch();
    }

    public function setStatus(string $v): void
    {
        if (!in_array($v, self::STATUSES, true)) {
            throw new \InvalidArgumentException('fleet.error.invalid_status');
        }
        $this->status = $v;
        $this->touch();
    }

    public function setOwnership(string $v): void
    {
        if (!in_array($v, self::OWNERSHIPS, true)) {
            throw new \InvalidArgumentException('fleet.error.invalid_ownership');
        }
        $this->ownership = $v;
        $this->touch();
    }

    public function setCustomerId(?string $v): void
    {
        $this->customerId = $v !== null && $v !== '' ? $v : null;
        $this->touch();
    }

    public function setBranchId(?string $v): void
    {
        $this->branchId = $v !== null && $v !== '' ? $v : null;
        $this->touch();
    }

    public function setStkAt(?\DateTimeImmutable $v): void
    {
        $this->stkAt = $v;
        $this->touch();
    }

    public function setInsuranceUntil(?\DateTimeImmutable $v): void
    {
        $this->insuranceUntil = $v;
        $this->touch();
    }

    public function setVignetteUntil(?\DateTimeImmutable $v): void
    {
        $this->vignetteUntil = $v;
        $this->touch();
    }

    public function setTiresAt(?\DateTimeImmutable $v): void
    {
        $this->tiresAt = $v;
        $this->touch();
    }

    public function setWarrantyUntil(?\DateTimeImmutable $v): void
    {
        $this->warrantyUntil = $v;
        $this->touch();
    }

    public function setNote(?string $v): void
    {
        $this->note = $v !== null && $v !== '' ? $v : null;
        $this->touch();
    }

    /** @param array<string, mixed>|null $v */
    public function setTechJson(?array $v): void
    {
        if ($v === null || $v === []) {
            $this->techJson = null;
        } else {
            $this->techJson = $v;
        }
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
