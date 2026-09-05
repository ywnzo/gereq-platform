<?php

declare(strict_types=1);

namespace Modules\Vitality\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'vitality_endpoint_stats')]
#[ORM\Index(name: 'idx_vitality_endpoint_avg', columns: ['avg_duration_ms'])]
class EndpointStat
{
    #[ORM\Id]
    #[ORM\Column(name: 'endpoint_key', length: 255)]
    private string $endpointKey;

    #[ORM\Column(length: 16)]
    private string $method;

    #[ORM\Column(length: 255)]
    private string $path;

    #[ORM\Column(name: 'route_name', length: 190, nullable: true)]
    private ?string $routeName;

    #[ORM\Column(name: 'sample_count')]
    private int $sampleCount = 0;

    #[ORM\Column(name: 'total_duration_ms')]
    private float $totalDurationMs = 0.0;

    #[ORM\Column(name: 'avg_duration_ms')]
    private float $avgDurationMs = 0.0;

    #[ORM\Column(name: 'last_duration_ms')]
    private int $lastDurationMs = 0;

    #[ORM\Column(name: 'max_duration_ms')]
    private int $maxDurationMs = 0;

    #[ORM\Column(name: 'last_seen_at')]
    private \DateTimeImmutable $lastSeenAt;

    public function __construct(string $endpointKey, string $method, string $path, ?string $routeName = null)
    {
        $this->endpointKey = $endpointKey;
        $this->method = strtoupper($method);
        $this->path = $path;
        $this->routeName = $routeName !== '' ? $routeName : null;
        $this->lastSeenAt = new \DateTimeImmutable();
    }

    public function recordSample(int $durationMs): void
    {
        $durationMs = max(0, $durationMs);
        $this->sampleCount++;
        $this->totalDurationMs += $durationMs;
        $this->avgDurationMs = round($this->totalDurationMs / $this->sampleCount, 2);
        $this->lastDurationMs = $durationMs;
        $this->maxDurationMs = max($this->maxDurationMs, $durationMs);
        $this->lastSeenAt = new \DateTimeImmutable();
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

    public function setRouteName(?string $routeName): void
    {
        if ($routeName !== null && $routeName !== '') {
            $this->routeName = $routeName;
        }
    }

    public function sampleCount(): int
    {
        return $this->sampleCount;
    }

    public function avgDurationMs(): float
    {
        return $this->avgDurationMs;
    }

    public function lastDurationMs(): int
    {
        return $this->lastDurationMs;
    }

    public function maxDurationMs(): int
    {
        return $this->maxDurationMs;
    }

    public function lastSeenAt(): \DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function status(): string
    {
        if ($this->avgDurationMs >= 500) {
            return 'red';
        }
        if ($this->avgDurationMs >= 300) {
            return 'orange';
        }

        return 'green';
    }
}
