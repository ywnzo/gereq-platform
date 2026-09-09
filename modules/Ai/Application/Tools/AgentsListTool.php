<?php

declare(strict_types=1);

namespace Modules\Ai\Application\Tools;

use Modules\Ai\Application\AgentDelegationService;
use Modules\Ai\Application\Contracts\AiTool;
use Modules\Ai\Application\Dto\AiActor;
use Modules\Ai\Application\Dto\AiToolResult;
use Modules\Ai\Application\ToolInvocationContext;

final class AgentsListTool implements AiTool
{
    public function __construct(
        private readonly AgentDelegationService $delegation,
        private readonly ToolInvocationContext $ctx,
    ) {
    }

    public function name(): string
    {
        return 'agents.list';
    }

    public function description(): string
    {
        return 'List peer AI agents you may call in this session (slug, name, specialty).';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [],
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

        return AiToolResult::ok($this->delegation->listPeers($caller, $actor));
    }
}
