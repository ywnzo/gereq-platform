<?php

declare(strict_types=1);

namespace Modules\BikeService\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'bikeservice_catalog_cache')]
#[ORM\UniqueConstraint(name: 'uniq_bikeservice_catalog_cache_key', columns: ['cache_key'])]
class CatalogCache
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'cache_key', length: 120)]
    private string $cacheKey;

    #[ORM\Column(name: 'payload', type: 'text')]
    private string $payload;

    #[ORM\Column(name: 'fetched_at')]
    private \DateTimeImmutable $fetchedAt;

    public function __construct(string $cacheKey, string $payload)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->cacheKey = mb_substr($cacheKey, 0, 120);
        $this->payload = $payload;
        $this->fetchedAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function cacheKey(): string
    {
        return $this->cacheKey;
    }

    public function payload(): string
    {
        return $this->payload;
    }

    public function fetchedAt(): \DateTimeImmutable
    {
        return $this->fetchedAt;
    }

    public function refresh(string $payload): void
    {
        $this->payload = $payload;
        $this->fetchedAt = new \DateTimeImmutable();
    }
}
