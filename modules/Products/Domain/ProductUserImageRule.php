<?php

declare(strict_types=1);

namespace Modules\Products\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'product_user_image_rules')]
#[ORM\UniqueConstraint(name: 'uniq_product_user_image_rule', columns: ['product_id'])]
class ProductUserImageRule
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'product_id', length: 36)]
    private string $productId;

    #[ORM\Column(name: 'max_images', type: 'integer', options: ['default' => 1])]
    private int $maxImages = 1;

    #[ORM\Column(name: 'crop_required', type: 'boolean')]
    private bool $cropRequired = false;

    #[ORM\Column(name: 'is_active', type: 'boolean')]
    private bool $active = true;

    #[ORM\Column(name: 'crop_config_json', type: 'text', nullable: true)]
    private ?string $cropConfigJson = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $productId)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->productId = $productId;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->setCropConfig(self::defaultCropConfig());
    }

    /** @return array<string, mixed> */
    public static function defaultCropConfig(): array
    {
        return [
            'preset_id' => 'square-1-1',
            'name' => 'Čtverec 1:1',
            'shape' => 'rectangle',
            'aspect_ratio' => 1,
            'output_width_px' => 1200,
            'output_height_px' => 1200,
        ];
    }

    public function id(): string
    {
        return $this->id;
    }

    public function productId(): string
    {
        return $this->productId;
    }

    public function maxImages(): int
    {
        return $this->maxImages;
    }

    public function cropRequired(): bool
    {
        return $this->cropRequired;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    /** @return array<string, mixed> */
    public function cropConfig(): array
    {
        if ($this->cropConfigJson === null || $this->cropConfigJson === '') {
            return self::defaultCropConfig();
        }
        $decoded = json_decode($this->cropConfigJson, true);

        return is_array($decoded) ? array_merge(self::defaultCropConfig(), $decoded) : self::defaultCropConfig();
    }

    public function setMaxImages(int $max): void
    {
        $this->maxImages = max(0, min(20, $max));
        $this->touch();
    }

    public function setCropRequired(bool $required): void
    {
        $this->cropRequired = $required;
        $this->touch();
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
        $this->touch();
    }

    /** @param array<string, mixed> $config */
    public function setCropConfig(array $config): void
    {
        $this->cropConfigJson = json_encode(array_merge(self::defaultCropConfig(), $config), JSON_UNESCAPED_UNICODE) ?: null;
        $this->touch();
    }

    /** Exact count when max > 0; when 0 + cropRequired → at least 1. */
    public function requiredImageCount(): int
    {
        if (!$this->active) {
            return 0;
        }
        if ($this->maxImages > 0) {
            return $this->maxImages;
        }

        return $this->cropRequired ? 1 : 0;
    }

    public function allowsImages(): bool
    {
        return $this->active && ($this->maxImages > 0 || $this->cropRequired);
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
