<?php

declare(strict_types=1);

namespace Modules\Ai\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'ai_agent_knowledge')]
#[ORM\UniqueConstraint(name: 'uniq_ai_agent_kb', columns: ['agent_id', 'knowledge_base_id'])]
class AiAgentKnowledge
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'agent_id', length: 36)]
    private string $agentId;

    #[ORM\Column(name: 'knowledge_base_id', length: 36)]
    private string $knowledgeBaseId;

    public function __construct(string $agentId, string $knowledgeBaseId)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->agentId = $agentId;
        $this->knowledgeBaseId = $knowledgeBaseId;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function agentId(): string
    {
        return $this->agentId;
    }

    public function knowledgeBaseId(): string
    {
        return $this->knowledgeBaseId;
    }
}
