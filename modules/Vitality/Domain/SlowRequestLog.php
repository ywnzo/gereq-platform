<?php

declare(strict_types=1);

namespace Modules\Vitality\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'vitality_slow_requests')]
#[ORM\Index(name: 'idx_vitality_slow_created', columns: ['created_at'])]
#[ORM\Index(name: 'idx_vitality_slow_endpoint', columns: ['endpoint_key'])]
class SlowRequestLog
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'endpoint_key', length: 255)]
    private string $endpointKey;

    #[ORM\Column(length: 16)]
    private string $method;

    #[ORM\Column(length: 2048)]
    private string $path;

    #[ORM\Column(name: 'route_name', length: 190, nullable: true)]
    private ?string $routeName;

    #[ORM\Column(name: 'status_code')]
    private int $statusCode;

    #[ORM\Column(name: 'duration_ms')]
    private int $durationMs;

    #[ORM\Column(name: 'avg_at_time_ms')]
    private float $avgAtTimeMs;

    #[ORM\Column(name: 'query_count')]
    private int $queryCount;

    #[ORM\Column(name: 'query_total_ms')]
    private float $queryTotalMs;

    /** @var list<array{sql: string, duration: float, origin: string}> */
    #[ORM\Column(name: 'queries_json', type: 'json')]
    private array $queriesJson;

    #[ORM\Column(name: 'memory_peak_bytes')]
    private int $memoryPeakBytes;

    #[ORM\Column(name: 'response_bytes')]
    private int $responseBytes;

    #[ORM\Column(name: 'correlation_id', length: 64, nullable: true)]
    private ?string $correlationId;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'request_query', type: 'json')]
    private array $requestQuery;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    /**
     * @param list<array{sql: string, duration: float, origin: string}> $queries
     * @param array<string, mixed> $requestQuery
     */
    public function __construct(
        string $endpointKey,
        string $method,
        string $path,
        ?string $routeName,
        int $statusCode,
        int $durationMs,
        float $avgAtTimeMs,
        int $queryCount,
        float $queryTotalMs,
        array $queries,
        int $memoryPeakBytes,
        int $responseBytes,
        ?string $correlationId,
        array $requestQuery,
    ) {
        $this->id = Uuid::uuid7()->toString();
        $this->endpointKey = $endpointKey;
        $this->method = strtoupper($method);
        $this->path = $path;
        $this->routeName = $routeName !== '' ? $routeName : null;
        $this->statusCode = $statusCode;
        $this->durationMs = $durationMs;
        $this->avgAtTimeMs = $avgAtTimeMs;
        $this->queryCount = $queryCount;
        $this->queryTotalMs = $queryTotalMs;
        $this->queriesJson = $queries;
        $this->memoryPeakBytes = $memoryPeakBytes;
        $this->responseBytes = $responseBytes;
        $this->correlationId = $correlationId !== '' ? $correlationId : null;
        $this->requestQuery = $requestQuery;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function endpointKey(): string
    {
        return $this->endpointKey;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function routeName(): ?string
    {
        return $this->routeName;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function durationMs(): int
    {
        return $this->durationMs;
    }

    public function avgAtTimeMs(): float
    {
        return $this->avgAtTimeMs;
    }

    public function queryCount(): int
    {
        return $this->queryCount;
    }

    public function queryTotalMs(): float
    {
        return $this->queryTotalMs;
    }

    /** @return list<array{sql: string, duration: float, origin: string}> */
    public function queries(): array
    {
        return $this->queriesJson;
    }

    public function memoryPeakBytes(): int
    {
        return $this->memoryPeakBytes;
    }

    public function responseBytes(): int
    {
        return $this->responseBytes;
    }

    public function correlationId(): ?string
    {
        return $this->correlationId;
    }

    /** @return array<string, mixed> */
    public function requestQuery(): array
    {
        return $this->requestQuery;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
