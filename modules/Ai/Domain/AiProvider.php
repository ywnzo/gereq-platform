<?php

declare(strict_types=1);

namespace Modules\Ai\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'ai_providers')]
class AiProvider
{
    public const DRIVERS = [
        'ollama',
        'openai_compat',
        'openai',
        'anthropic',
        'gemini',
        'groq',
        'zai',
        'deepseek',
    ];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 40)]
    private string $driver;

    #[ORM\Column(length: 190)]
    private string $name;

    #[ORM\Column(name: 'base_url', length: 255, nullable: true)]
    private ?string $baseUrl = null;

    #[ORM\Column(name: 'api_key_setting', length: 120, nullable: true)]
    private ?string $apiKeySetting = null;

    #[ORM\Column]
    private bool $enabled = true;

    #[ORM\Column]
    private int $priority = 100;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $driver, string $name, ?string $baseUrl = null, ?string $apiKeySetting = null, int $priority = 100)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->driver = $driver;
        $this->name = $name;
        $this->baseUrl = $baseUrl;
        $this->apiKeySetting = $apiKeySetting;
        $this->priority = $priority;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function baseUrl(): ?string
    {
        return $this->baseUrl;
    }

    public function apiKeySetting(): ?string
    {
        return $this->apiKeySetting;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function priority(): int
    {
        return $this->priority;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
        $this->touch();
    }

    public function setBaseUrl(?string $baseUrl): void
    {
        $this->baseUrl = $baseUrl;
        $this->touch();
    }

    public function setApiKeySetting(?string $apiKeySetting): void
    {
        $this->apiKeySetting = $apiKeySetting;
        $this->touch();
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
        $this->touch();
    }

    public function setPriority(int $priority): void
    {
        $this->priority = $priority;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
