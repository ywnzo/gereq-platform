<?php

declare(strict_types=1);

namespace Modules\PartsCatalog\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'partscatalog_api_cache')]
#[ORM\UniqueConstraint(name: 'uniq_partscatalog_api_cache_key', columns: ['cache_key'])]
#[ORM\Index(name: 'idx_partscatalog_api_provider', columns: ['provider'])]
class CatalogApiCache
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 32)]
    private string $provider;

    #[ORM\Column(name: 'cache_key', length: 64)]
    private string $cacheKey;

    #[ORM\Column(name: 'logical_key', length: 255)]
    private string $logicalKey;

    #[ORM\Column(name: 'payload_json', type: 'text')]
    private string $payloadJson;

    #[ORM\Column(name: 'fetched_at')]
    private \DateTimeImmutable $fetchedAt;

    public function __construct(string $provider, string $cacheKey, string $logicalKey, string $payloadJson)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->provider = $provider;
        $this->cacheKey = $cacheKey;
        $this->logicalKey = mb_substr($logicalKey, 0, 255);
        $this->payloadJson = $payloadJson;
        $this->fetchedAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function cacheKey(): string
    {
        return $this->cacheKey;
    }

    public function logicalKey(): string
    {
        return $this->logicalKey;
    }

    public function payloadJson(): string
    {
        return $this->payloadJson;
    }

    public function fetchedAt(): \DateTimeImmutable
    {
        return $this->fetchedAt;
    }

    public function refresh(string $payloadJson): void
    {
        $this->payloadJson = $payloadJson;
        $this->fetchedAt = new \DateTimeImmutable();
    }
}
