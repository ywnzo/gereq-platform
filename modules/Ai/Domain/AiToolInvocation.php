<?php

declare(strict_types=1);

namespace Modules\Ai\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'ai_tool_invocations')]
#[ORM\Index(name: 'idx_ai_tool_inv_conv', columns: ['conversation_id', 'created_at'])]
class AiToolInvocation
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'conversation_id', length: 36)]
    private string $conversationId;

    #[ORM\Column(name: 'message_id', length: 36, nullable: true)]
    private ?string $messageId = null;

    #[ORM\Column(name: 'tool_name', length: 120)]
    private string $toolName;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'args_json', type: 'json')]
    private array $argsJson = [];

    /** @var array<string, mixed>|list<mixed>|string|null */
    #[ORM\Column(name: 'result_json', type: 'json', nullable: true)]
    private mixed $resultJson = null;

    #[ORM\Column]
    private bool $ok = true;

    #[ORM\Column(name: 'duration_ms')]
    private int $durationMs = 0;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $args
     */
    public function __construct(string $conversationId, string $toolName, array $args = [])
    {
        $this->id = Uuid::uuid7()->toString();
        $this->conversationId = $conversationId;
        $this->toolName = $toolName;
        $this->argsJson = $args;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function conversationId(): string
    {
        return $this->conversationId;
    }

    public function messageId(): ?string
    {
        return $this->messageId;
    }

    public function toolName(): string
    {
        return $this->toolName;
    }

    /** @return array<string, mixed> */
    public function argsJson(): array
    {
        return $this->argsJson;
    }

    public function resultJson(): mixed
    {
        return $this->resultJson;
    }

    public function ok(): bool
    {
        return $this->ok;
    }

    public function durationMs(): int
    {
        return $this->durationMs;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setMessageId(?string $messageId): void
    {
        $this->messageId = $messageId;
    }

    public function complete(mixed $result, bool $ok, int $durationMs): void
    {
        $this->resultJson = $result;
        $this->ok = $ok;
        $this->durationMs = $durationMs;
    }
}
