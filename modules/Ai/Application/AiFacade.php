<?php

declare(strict_types=1);

namespace Modules\Ai\Application;

use Modules\Ai\Application\Dto\AiActor;
use Modules\Ai\Domain\AiAgent;

/**
 * Capability facade registered as `ai`.
 */
final class AiFacade
{
    public function __construct(
        private readonly AiOrchestrator $orchestrator,
        private readonly AgentService $agents,
        private readonly ToolRegistry $tools,
        private readonly LlmProviderRegistry $providers,
    ) {
    }

    public function orchestrator(): AiOrchestrator
    {
        return $this->orchestrator;
    }

    public function agents(): AgentService
    {
        return $this->agents;
    }

    public function tools(): ToolRegistry
    {
        return $this->tools;
    }

    public function providers(): LlmProviderRegistry
    {
        return $this->providers;
    }

    /**
     * @return array<string, mixed>
     */
    public function chatBySlug(string $slug, AiActor $actor, string $message, ?string $conversationId = null): array
    {
        $agent = $this->agents->findBySlug($slug);
        if (!$agent instanceof AiAgent) {
            throw new \RuntimeException('Agent not found', 404);
        }

        return $this->orchestrator->chat(
            $agent,
            $actor,
            $message,
            $conversationId,
            requirePublished: in_array($actor->surface, ['api', 'public'], true),
        );
    }
}
