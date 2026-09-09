<?php

declare(strict_types=1);

namespace Modules\Ai\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'ai_messages')]
#[ORM\Index(name: 'idx_ai_messages_conv', columns: ['conversation_id', 'created_at'])]
class AiMessage
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'conversation_id', length: 36)]
    private string $conversationId;

    #[ORM\Column(length: 20)]
    private string $role;

    #[ORM\Column(type: 'text')]
    private string $content;

    #[ORM\Column(name: 'tool_name', length: 120, nullable: true)]
    private ?string $toolName = null;

    #[ORM\Column(name: 'tool_call_id', length: 80, nullable: true)]
    private ?string $toolCallId = null;

    #[ORM\Column(name: 'token_in')]
    private int $tokenIn = 0;

    #[ORM\Column(name: 'token_out')]
    private int $tokenOut = 0;

    #[ORM\Column(name: 'model_id', length: 36, nullable: true)]
    private ?string $modelId = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $conversationId, string $role, string $content)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->conversationId = $conversationId;
        $this->role = $role;
        $this->content = $content;
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

    public function role(): string
    {
        return $this->role;
    }

    public function content(): string
    {
        return $this->content;
    }

    public function toolName(): ?string
    {
        return $this->toolName;
    }

    public function toolCallId(): ?string
    {
        return $this->toolCallId;
    }

    public function tokenIn(): int
    {
        return $this->tokenIn;
    }

    public function tokenOut(): int
    {
        return $this->tokenOut;
    }

    public function modelId(): ?string
    {
        return $this->modelId;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setTool(string $toolName, ?string $toolCallId = null): void
    {
        $this->toolName = $toolName;
        $this->toolCallId = $toolCallId;
    }

    public function setTokens(int $in, int $out): void
    {
        $this->tokenIn = $in;
        $this->tokenOut = $out;
    }

    public function setModelId(?string $modelId): void
    {
        $this->modelId = $modelId;
    }
}
