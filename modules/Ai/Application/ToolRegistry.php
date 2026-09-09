<?php

declare(strict_types=1);

namespace Modules\Ai\Application;

use Modules\Ai\Application\Contracts\AiContextProvider;
use Modules\Ai\Application\Contracts\AiTool;
use Modules\Ai\Application\Dto\AiActor;

final class ToolRegistry
{
    /** @var array<string, AiTool> */
    private array $tools = [];

    /** @var list<AiContextProvider> */
    private array $contexts = [];

    private ?CustomToolCatalog $custom = null;

    public function setCustomCatalog(CustomToolCatalog $catalog): void
    {
        $this->custom = $catalog;
    }

    public function register(AiTool $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    public function registerContext(AiContextProvider $provider): void
    {
        $this->contexts[] = $provider;
    }

    public function get(string $name): ?AiTool
    {
        if (isset($this->tools[$name])) {
            return $this->tools[$name];
        }

        return $this->custom?->runtimeByName($name);
    }

    /** @return list<AiTool> */
    public function all(): array
    {
        $out = $this->tools;
        foreach ($this->custom?->enabledRuntimes() ?? [] as $tool) {
            if (!isset($out[$tool->name()])) {
                $out[$tool->name()] = $tool;
            }
        }

        return array_values($out);
    }

    /**
     * @param list<string>|null $allowlist
     * @return list<AiTool>
     */
    public function forActor(AiActor $actor, ?array $allowlist = null): array
    {
        $out = [];
        foreach ($this->all() as $tool) {
            if ($allowlist !== null && !in_array($tool->name(), $allowlist, true)) {
                continue;
            }
            if (!in_array($actor->surface, $tool->surfaces(), true) && !in_array('*', $tool->surfaces(), true)) {
                continue;
            }
            $perm = $tool->permission();
            if ($perm !== null && $actor->type === 'user' && !$actor->can($perm)) {
                continue;
            }
            $out[] = $tool;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveContext(string $surface, string $entityId, AiActor $actor): array
    {
        foreach ($this->contexts as $ctx) {
            if ($ctx->supports($surface)) {
                return $ctx->snapshot($entityId, $actor);
            }
        }

        return [];
    }
}
