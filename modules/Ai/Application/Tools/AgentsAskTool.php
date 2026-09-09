<?php

declare(strict_types=1);

namespace Modules\Ai\Application\Tools;

use Modules\Ai\Application\AgentDelegationService;
use Modules\Ai\Application\Contracts\AiTool;
use Modules\Ai\Application\Dto\AiActor;
use Modules\Ai\Application\Dto\AiToolResult;
use Modules\Ai\Application\ToolInvocationContext;

final class AgentsAskTool implements AiTool
{
    public function __construct(
        private readonly AgentDelegationService $delegation,
        private readonly ToolInvocationContext $ctx,
    ) {
    }

    public function name(): string
    {
        return 'agents.ask';
    }

    public function description(): string
    {
        return 'Ask a peer AI agent (by slug) a question and get their answer. Use agents.list first.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'slug' => [
                    'type' => 'string',
                    'description' => 'Peer agent slug',
                ],
                'question' => [
                    'type' => 'string',
                    'description' => 'Clear question / task for the peer',
                ],
            ],
            'required' => ['slug', 'question'],
        ];
    }

    public function permission(): ?string
    {
        return 'ai.use';
    }

    public function surfaces(): array
    {
        return ['admin', 'api', 'public'];
    }

    public function invoke(array $args, AiActor $actor): AiToolResult
    {
        $caller = $this->ctx->caller();
        if ($caller === null) {
            return AiToolResult::fail('No caller agent in context');
        }

        return $this->delegation->ask(
            $caller,
            (string) ($args['slug'] ?? ''),
            (string) ($args['question'] ?? ''),
            $actor,
        );
    }
}
