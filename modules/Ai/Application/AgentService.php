<?php

declare(strict_types=1);

namespace Modules\Ai\Application;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Ai\Application\Dto\AiActor;
use Modules\Ai\Domain\AiAgent;
use Modules\Ai\Domain\AiAgentKnowledge;
use Modules\Ai\Domain\AiAgentPeer;
use Modules\Ai\Domain\AiAgentTool;
use Modules\Ai\Domain\AiKnowledgeBase;

final class AgentService
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function findBySlug(string $slug): ?AiAgent
    {
        return $this->em->getRepository(AiAgent::class)->findOneBy(['slug' => $slug]);
    }

    public function find(string $id): ?AiAgent
    {
        return $this->em->find(AiAgent::class, $id);
    }

    /**
     * @return list<string>
     */
    public function toolAllowlist(string $agentId): array
    {
        $rows = $this->em->getRepository(AiAgentTool::class)->findBy(['agentId' => $agentId]);

        return array_map(static fn (AiAgentTool $t) => $t->toolName(), $rows);
    }

    /**
     * @return list<string>
     */
    public function knowledgeBaseIds(string $agentId, bool $publicOnly = false): array
    {
        $links = $this->em->getRepository(AiAgentKnowledge::class)->findBy(['agentId' => $agentId]);
        $ids = [];
        foreach ($links as $link) {
            $kb = $this->em->find(AiKnowledgeBase::class, $link->knowledgeBaseId());
            if (!$kb instanceof AiKnowledgeBase) {
                continue;
            }
            if ($publicOnly && $kb->visibility() !== 'public') {
                continue;
            }
            $ids[] = $kb->id();
        }

        return $ids;
    }

    public function assertAccessible(AiAgent $agent, AiActor $actor, bool $requirePublished = false): void
    {
        if ($requirePublished && !$agent->published()) {
            throw new \RuntimeException('Agent není publikovaný.', 404);
        }
        if (!$agent->allowsSurface($actor->surface)) {
            throw new \RuntimeException('Agent není dostupný pro tento surface.', 403);
        }
        if ($actor->type === 'api_token') {
            $scope = 'ai.agent.' . $agent->slug();
            if (
                !$actor->hasScope($scope)
                && !$actor->hasScope('ai.admin')
                && !$actor->hasScope('ai.agent.*')
            ) {
                throw new \RuntimeException('Chybí API scope ' . $scope, 403);
            }
        }
        if ($actor->type === 'user' && $actor->surface !== 'admin' && !$actor->can('ai.use') && !$actor->can('ai.manage')) {
            throw new \RuntimeException('Chybí oprávnění ai.use', 403);
        }
    }

    /**
     * @param list<string> $toolNames
     */
    public function syncTools(AiAgent $agent, array $toolNames): void
    {
        $existing = $this->em->getRepository(AiAgentTool::class)->findBy(['agentId' => $agent->id()]);
        foreach ($existing as $row) {
            $this->em->remove($row);
        }
        $this->em->flush();
        foreach (array_unique($toolNames) as $name) {
            $name = trim($name);
            if ($name === '') {
                continue;
            }
            $this->em->persist(new AiAgentTool($agent->id(), $name));
        }
        $this->em->flush();
    }

    /**
     * @param list<string> $kbIds
     */
    public function syncKnowledge(AiAgent $agent, array $kbIds): void
    {
        $existing = $this->em->getRepository(AiAgentKnowledge::class)->findBy(['agentId' => $agent->id()]);
        foreach ($existing as $row) {
            $this->em->remove($row);
        }
        $this->em->flush();
        foreach (array_unique($kbIds) as $id) {
            if ($id === '') {
                continue;
            }
            $this->em->persist(new AiAgentKnowledge($agent->id(), $id));
        }
        $this->em->flush();
    }

    /**
     * @return list<string>
     */
    public function peerIds(string $agentId): array
    {
        $rows = $this->em->getRepository(AiAgentPeer::class)->findBy(['agentId' => $agentId]);

        return array_map(static fn (AiAgentPeer $p) => $p->peerAgentId(), $rows);
    }

    /**
     * @return list<AiAgent>
     */
    public function peers(string $agentId): array
    {
        $out = [];
        foreach ($this->peerIds($agentId) as $peerId) {
            $peer = $this->em->find(AiAgent::class, $peerId);
            if ($peer instanceof AiAgent) {
                $out[] = $peer;
            }
        }

        return $out;
    }

    public function canCallPeer(string $agentId, string $peerAgentId): bool
    {
        $row = $this->em->getRepository(AiAgentPeer::class)->findOneBy([
            'agentId' => $agentId,
            'peerAgentId' => $peerAgentId,
        ]);

        return $row instanceof AiAgentPeer;
    }

    /**
     * @param list<string> $peerAgentIds
     */
    public function syncPeers(AiAgent $agent, array $peerAgentIds): void
    {
        $existing = $this->em->getRepository(AiAgentPeer::class)->findBy(['agentId' => $agent->id()]);
        foreach ($existing as $row) {
            $this->em->remove($row);
        }
        $this->em->flush();
        foreach (array_unique($peerAgentIds) as $peerId) {
            $peerId = trim($peerId);
            if ($peerId === '' || $peerId === $agent->id()) {
                continue;
            }
            if (!$this->em->find(AiAgent::class, $peerId) instanceof AiAgent) {
                continue;
            }
            $this->em->persist(new AiAgentPeer($agent->id(), $peerId));
        }
        $this->em->flush();
    }

    public function ensureKnowledgeLink(AiAgent $agent, string $kbId): void
    {
        $existing = $this->em->getRepository(AiAgentKnowledge::class)->findOneBy([
            'agentId' => $agent->id(),
            'knowledgeBaseId' => $kbId,
        ]);
        if ($existing instanceof AiAgentKnowledge) {
            return;
        }
        $this->em->persist(new AiAgentKnowledge($agent->id(), $kbId));
        $this->em->flush();
    }

    /**
     * KB IDs for RAG including shared learning corpus when present.
     *
     * @return list<string>
     */
    public function knowledgeBaseIdsForChat(string $agentId, bool $publicOnly = false): array
    {
        $ids = $this->knowledgeBaseIds($agentId, $publicOnly);
        if ($publicOnly) {
            return $ids;
        }
        $shared = $this->em->getRepository(AiKnowledgeBase::class)->findOneBy([
            'slug' => FeedbackLearningService::SHARED_KB_SLUG,
        ]);
        if ($shared instanceof AiKnowledgeBase && !in_array($shared->id(), $ids, true)) {
            $ids[] = $shared->id();
        }

        return $ids;
    }
}
