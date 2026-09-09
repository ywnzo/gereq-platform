<?php

declare(strict_types=1);

namespace Modules\Ai\Application;

use Modules\Ai\Application\Dto\AiActor;
use Modules\Ai\Application\Dto\AiToolResult;
use Modules\Ai\Domain\AiAgent;

final class AgentDelegationService
{
    public const MAX_DEPTH = 2;

    public function __construct(
        private readonly AgentService $agents,
        private readonly ToolInvocationContext $ctx,
    ) {
    }

    /**
     * @return list<array{slug: string, name: string, description: ?string}>
     */
    public function listPeers(AiAgent $caller, AiActor $actor): array
    {
        $out = [];
        foreach ($this->agents->peers($caller->id()) as $peer) {
            if (!$peer->allowsSurface($actor->surface)) {
                continue;
            }
            $out[] = [
                'slug' => $peer->slug(),
                'name' => $peer->name(),
                'description' => $peer->description(),
            ];
        }

        return $out;
    }

    public function ask(AiAgent $caller, string $peerSlug, string $question, AiActor $actor): AiToolResult
    {
        $peerSlug = trim($peerSlug);
        $question = trim($question);
        if ($peerSlug === '' || $question === '') {
            return AiToolResult::fail('slug and question are required');
        }
        if ($this->ctx->depth() >= self::MAX_DEPTH) {
            return AiToolResult::fail('Max agent delegation depth reached');
        }
        if ($peerSlug === $caller->slug()) {
            return AiToolResult::fail('Cannot call self');
        }

        $peer = $this->agents->findBySlug($peerSlug);
        if (!$peer instanceof AiAgent) {
            return AiToolResult::fail('Peer agent not found');
        }
        if (!$this->agents->canCallPeer($caller->id(), $peer->id())) {
            return AiToolResult::fail('Peer agent is not on allowlist');
        }
        if (!$peer->allowsSurface($actor->surface)) {
            return AiToolResult::fail('Peer agent unavailable on this surface');
        }

        $this->ctx->enter();
        try {
            /** @var AiOrchestrator $orch */
            $orch = gereq()->make(AiOrchestrator::class);
            $result = $orch->chat(
                $peer,
                $actor,
                $question,
                null,
                null,
                null,
                false,
            );

            return AiToolResult::ok([
                'peer' => $peer->slug(),
                'peer_name' => $peer->name(),
                'answer' => $result['message'],
                'model' => $result['model'],
                'conversation_id' => $result['conversation_id'],
            ]);
        } catch (\Throwable $e) {
            return AiToolResult::fail($e->getMessage());
        } finally {
            $this->ctx->leave();
        }
    }

    /**
     * Catalog text for system prompt.
     */
    public function promptBlock(AiAgent $caller, AiActor $actor): string
    {
        $peers = $this->listPeers($caller, $actor);
        if ($peers === []) {
            return '';
        }
        $lines = ["Dostupní kolegové-agenti (zavolej přes tools agents.list / agents.ask):"];
        foreach ($peers as $p) {
            $desc = $p['description'] ? ' — ' . $p['description'] : '';
            $lines[] = sprintf('- `%s` (%s)%s', $p['slug'], $p['name'], $desc);
        }
        $lines[] = 'Když úkol spadá do jejich specializace, zeptej se jich přes agents.ask a shrň výsledek uživateli.';

        return implode("\n", $lines);
    }
}
