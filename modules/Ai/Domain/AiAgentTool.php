<?php

declare(strict_types=1);

namespace Modules\Ai\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'ai_agent_tools')]
#[ORM\UniqueConstraint(name: 'uniq_ai_agent_tools', columns: ['agent_id', 'tool_name'])]
class AiAgentTool
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'agent_id', length: 36)]
    private string $agentId;

    #[ORM\Column(name: 'tool_name', length: 120)]
    private string $toolName;

    public function __construct(string $agentId, string $toolName)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->agentId = $agentId;
        $this->toolName = $toolName;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function agentId(): string
    {
        return $this->agentId;
    }

    public function toolName(): string
    {
        return $this->toolName;
    }
}
