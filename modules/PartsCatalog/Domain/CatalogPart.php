<?php

declare(strict_types=1);

namespace Modules\PartsCatalog\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'partscatalog_parts')]
#[ORM\UniqueConstraint(name: 'uniq_pc_part_provider_external', columns: ['provider', 'external_id'])]
#[ORM\Index(name: 'idx_pc_part_oem', columns: ['oem'])]
#[ORM\Index(name: 'idx_pc_part_brand', columns: ['brand'])]
class CatalogPart
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 32)]
    private string $provider;

    #[ORM\Column(name: 'external_id', length: 255)]
    private string $externalId;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $oem = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $brand = null;

    #[ORM\Column(name: 'image_url', length: 500, nullable: true)]
    private ?string $imageUrl = null;

    #[ORM\Column(name: 'source_url', length: 500, nullable: true)]
    private ?string $sourceUrl = null;

    #[ORM\Column(name: 'attrs_json', type: 'text', nullable: true)]
    private ?string $attrsJson = null;

    #[ORM\Column(name: 'synced_at', nullable: true)]
    private ?\DateTimeImmutable $syncedAt = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $provider,
        string $externalId,
        string $name,
        ?string $oem = null,
        ?string $brand = null,
        ?string $imageUrl = null,
        ?string $sourceUrl = null,
        ?string $attrsJson = null,
    ) {
        $this->id = Uuid::uuid7()->toString();
        $this->provider = $provider;
        $this->externalId = mb_substr($externalId, 0, 255);
        $this->name = mb_substr($name, 0, 255);
        $this->oem = $oem !== null && $oem !== '' ? mb_substr(trim($oem), 0, 64) : null;
        $this->brand = $brand !== null && $brand !== '' ? mb_substr(trim($brand), 0, 80) : null;
        $this->imageUrl = $imageUrl !== null && $imageUrl !== '' ? mb_substr($imageUrl, 0, 500) : null;
        $this->sourceUrl = $sourceUrl !== null && $sourceUrl !== '' ? mb_substr($sourceUrl, 0, 500) : null;
        $this->attrsJson = $attrsJson;
        $this->createdAt = new \DateTimeImmutable();
        $this->syncedAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function externalId(): string
    {
        return $this->externalId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function oem(): ?string
    {
        return $this->oem;
    }

    public function brand(): ?string
    {
        return $this->brand;
    }

    public function imageUrl(): ?string
    {
        return $this->imageUrl;
    }

    public function refresh(
        string $name,
        ?string $oem,
        ?string $brand,
        ?string $imageUrl,
        ?string $sourceUrl,
        ?string $attrsJson,
    ): void {
        $this->name = mb_substr($name, 0, 255);
        $this->oem = $oem !== null && $oem !== '' ? mb_substr(trim($oem), 0, 64) : null;
        $this->brand = $brand !== null && $brand !== '' ? mb_substr(trim($brand), 0, 80) : null;
        $this->imageUrl = $imageUrl !== null && $imageUrl !== '' ? mb_substr($imageUrl, 0, 500) : null;
        $this->sourceUrl = $sourceUrl !== null && $sourceUrl !== '' ? mb_substr($sourceUrl, 0, 500) : null;
        $this->attrsJson = $attrsJson;
        $this->syncedAt = new \DateTimeImmutable();
    }
}
