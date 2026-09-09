<?php

declare(strict_types=1);

namespace Modules\Ai\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'ai_usage_daily')]
#[ORM\UniqueConstraint(name: 'uniq_ai_usage_daily', columns: ['day', 'provider_id', 'model_id', 'surface', 'agent_id'])]
class AiUsageDaily
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $day;

    #[ORM\Column(name: 'provider_id', length: 36)]
    private string $providerId;

    #[ORM\Column(name: 'model_id', length: 36)]
    private string $modelId;

    #[ORM\Column(length: 20)]
    private string $surface;

    #[ORM\Column(name: 'agent_id', length: 36, nullable: true)]
    private ?string $agentId = null;

    #[ORM\Column]
    private int $requests = 0;

    #[ORM\Column(name: 'tokens_in')]
    private int $tokensIn = 0;

    #[ORM\Column(name: 'tokens_out')]
    private int $tokensOut = 0;

    #[ORM\Column(name: 'cost_millis')]
    private int $costMillis = 0;

    public function __construct(
        \DateTimeImmutable $day,
        string $providerId,
        string $modelId,
        string $surface,
        ?string $agentId = null,
    ) {
        $this->id = Uuid::uuid7()->toString();
        $this->day = $day->setTime(0, 0);
        $this->providerId = $providerId;
        $this->modelId = $modelId;
        $this->surface = $surface;
        $this->agentId = $agentId;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function day(): \DateTimeImmutable
    {
        return $this->day;
    }

    public function providerId(): string
    {
        return $this->providerId;
    }

    public function modelId(): string
    {
        return $this->modelId;
    }

    public function surface(): string
    {
        return $this->surface;
    }

    public function agentId(): ?string
    {
        return $this->agentId;
    }

    public function requests(): int
    {
        return $this->requests;
    }

    public function tokensIn(): int
    {
        return $this->tokensIn;
    }

    public function tokensOut(): int
    {
        return $this->tokensOut;
    }

    public function costMillis(): int
    {
        return $this->costMillis;
    }

    public function add(int $requests, int $tokensIn, int $tokensOut, int $costMillis = 0): void
    {
        $this->requests += $requests;
        $this->tokensIn += $tokensIn;
        $this->tokensOut += $tokensOut;
        $this->costMillis += $costMillis;
    }
}
