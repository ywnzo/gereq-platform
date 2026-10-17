<?php

declare(strict_types=1);

namespace Gereq\Setup;

use Gereq\Config\Repository;
use Gereq\Module\ModuleManager;
use Gereq\Module\ModuleManifest;

final class SetupModuleCatalog
{
    public function __construct(private readonly ModuleManager $modules, private readonly Repository $config)
    {
    }

    /** @return array{modules: list<array{manifest: ModuleManifest, categories: list<string>}>, categories: array<string, string>} */
    public function catalog(): array
    {
        $definitions = $this->config->get('module_groups', []);
        $definitions = is_array($definitions) ? $definitions : [];
        $manifests = $this->modules->discover();
        $items = [];
        $categories = [];
        foreach ($manifests as $manifest) {
            $groups = $manifest->groups;
            if ($manifest->parentId !== null) {
                $groups = array_merge($groups, $manifests[$manifest->parentId]->groups ?? []);
            }
            foreach ($definitions as $id => $definition) {
                if (!is_array($definition)) {
                    continue;
                }
                $members = is_array($definition['modules'] ?? null) ? $definition['modules'] : [];
                if (in_array($manifest->id, $members, true) || in_array($manifest->parentId, $members, true)) {
                    $groups[] = (string) $id;
                }
            }
            $groups = array_values(array_unique($groups));
            if ($groups === []) {
                $groups = ['other'];
            }
            foreach ($groups as $id) {
                $definition = $definitions[$id] ?? [];
                $categories[$id] = $id === 'other' ? 'setup.modules.other'
                    : (string) ($definition['label'] ?? $definition['name'] ?? $id);
            }
            $items[] = ['manifest' => $manifest, 'categories' => $groups];
        }

        return ['modules' => $items, 'categories' => $categories];
    }
}
