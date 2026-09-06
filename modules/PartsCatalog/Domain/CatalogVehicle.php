<?php

declare(strict_types=1);

namespace Modules\PartsCatalog\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'partscatalog_vehicles')]
#[ORM\UniqueConstraint(name: 'uniq_pc_vehicle_external', columns: ['external_id'])]
#[ORM\Index(name: 'idx_pc_vehicle_model', columns: ['model_id'])]
#[ORM\Index(name: 'idx_pc_vehicle_sync', columns: ['sync_status'])]
class CatalogVehicle
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CATEGORIES = 'categories';

    public const STATUS_PARTS = 'parts';

    public const STATUS_DONE = 'done';

    public const STATUS_ERROR = 'error';

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'model_id', length: 36)]
    private string $modelId;

    #[ORM\Column(name: 'external_id', length: 255)]
    private string $externalId;

    #[ORM\Column(name: 'motor_slug', length: 180)]
    private string $motorSlug;

    #[ORM\Column(length: 255)]
    private string $label;

    #[ORM\Column(length: 320)]
    private string $path;

    #[ORM\Column(name: 'sync_status', length: 24)]
    private string $syncStatus = self::STATUS_PENDING;

    #[ORM\Column(name: 'error_message', type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(name: 'synced_at', nullable: true)]
    private ?\DateTimeImmutable $syncedAt = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $modelId,
        string $externalId,
        string $motorSlug,
        string $label,
        string $path,
    ) {
        $this->id = Uuid::uuid7()->toString();
        $this->modelId = $modelId;
        $this->externalId = $externalId;
        $this->motorSlug = $motorSlug;
        $this->label = $label;
        $this->path = $path;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function modelId(): string
    {
        return $this->modelId;
    }

    public function externalId(): string
    {
        return $this->externalId;
    }

    public function motorSlug(): string
    {
        return $this->motorSlug;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function syncStatus(): string
    {
        return $this->syncStatus;
    }

    public function errorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function syncedAt(): ?\DateTimeImmutable
    {
        return $this->syncedAt;
    }

    public function refreshMeta(string $label, string $path): void
    {
        $this->label = $label;
        $this->path = $path;
    }

    public function setSyncStatus(string $status, ?string $error = null): void
    {
        $this->syncStatus = $status;
        $this->errorMessage = $error;
        if ($status === self::STATUS_DONE) {
            $this->syncedAt = new \DateTimeImmutable();
        }
    }
}
