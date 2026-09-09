<?php

declare(strict_types=1);

namespace Modules\Ai\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'ai_agent_peers')]
#[ORM\UniqueConstraint(name: 'uniq_ai_agent_peers', columns: ['agent_id', 'peer_agent_id'])]
class AiAgentPeer
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'agent_id', length: 36)]
    private string $agentId;

    #[ORM\Column(name: 'peer_agent_id', length: 36)]
    private string $peerAgentId;

    public function __construct(string $agentId, string $peerAgentId)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->agentId = $agentId;
        $this->peerAgentId = $peerAgentId;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function agentId(): string
    {
        return $this->agentId;
    }

    public function peerAgentId(): string
    {
        return $this->peerAgentId;
    }
}
