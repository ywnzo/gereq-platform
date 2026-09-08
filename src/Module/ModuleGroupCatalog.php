<?php

declare(strict_types=1);

namespace Gereq\Module;

use Gereq\Config\Repository as Config;

/**
 * Katalog skupin modulů (balíčky typu ECS).
 */
final class ModuleGroupCatalog
{
    public function __construct(
        private readonly Config $config,
        private readonly ModuleManager $modules,
    ) {
    }

    /**
     * @param array<string, array<string, mixed>> $rowsById index řádků z ModuleController::index
     * @param 'packages'|'plugins' $surface
     * @return list<array{
     *   id: string,
     *   name: string,
     *   label: string,
     *   description: string,
     *   surface: string,
     *   modules: list<array{id: string, role: string, row: ?array<string, mixed>, missing: bool}>,
     *   total: int,
     *   enabled: int,
     *   disabled_ids: list<string>,
     *   all_enabled: bool,
     *   complete: bool
     * }>
     */
    public function forIndex(array $rowsById, string $surface = 'packages'): array
    {
        $defs = $this->definitions();
        $membership = $this->membershipFromManifests();

        $groups = [];
        foreach ($defs as $id => $def) {
            if (($def['surface'] ?? 'packages') !== $surface) {
                continue;
            }

            $moduleIds = $def['modules'];
            foreach ($membership[$id] ?? [] as $extraId) {
                if (!in_array($extraId, $moduleIds, true)) {
                    $moduleIds[] = $extraId;
                }
            }

            $required = $def['required'];
            $modules = [];
            $enabled = 0;
            $disabledIds = [];
            $requiredOk = true;

            foreach ($moduleIds as $moduleId) {
                $row = $rowsById[$moduleId] ?? null;
                $isMissing = $row === null;
                $isEnabled = !$isMissing && !empty($row['enabled']);
                if ($isEnabled) {
                    ++$enabled;
                } elseif (!$isMissing) {
                    $disabledIds[] = $moduleId;
                }
                $role = in_array($moduleId, $required, true) ? 'required' : 'recommended';
                if ($role === 'required' && !$isEnabled) {
                    $requiredOk = false;
                }
                $modules[] = [
                    'id' => $moduleId,
                    'role' => $role,
                    'row' => $row,
                    'missing' => $isMissing,
                ];
            }

            $groups[] = [
                'id' => $id,
                'name' => $def['name'],
                'label' => $def['label'],
                'description' => $def['description'],
                'surface' => $def['surface'],
                'modules' => $modules,
                'total' => count($modules),
                'enabled' => $enabled,
                'disabled_ids' => $disabledIds,
                'all_enabled' => $disabledIds === [] && $modules !== [],
                'complete' => $requiredOk,
                'order' => $def['order'],
            ];
        }

        usort($groups, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        return array_map(static function (array $g): array {
            unset($g['order']);

            return $g;
        }, $groups);
    }

    /**
     * ID modulů k zapnutí ve správném pořadí (závislosti dřív).
     *
     * @return list<string>
     */
    public function enableOrder(string $groupId): array
    {
        $defs = $this->definitions();
        if (!isset($defs[$groupId])) {
            throw new \InvalidArgumentException('admin.modules.group.not_found');
        }

        $ids = $defs[$groupId]['modules'];
        foreach ($this->membershipFromManifests()[$groupId] ?? [] as $extraId) {
            if (!in_array($extraId, $ids, true)) {
                $ids[] = $extraId;
            }
        }

        $ids = array_values(array_filter(
            $ids,
            fn (string $id): bool => $this->modules->manifest($id) !== null,
        ));

        return $this->modules->sortForEnable($ids);
    }

    public function has(string $groupId): bool
    {
        return isset($this->definitions()[$groupId]);
    }

    /**
     * @return array<string, array{name: string, label: string, description: string, surface: string, order: int, modules: list<string>, required: list<string>}>
     */
    private function definitions(): array
    {
        $raw = $this->config->get('module_groups', []);
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $id => $def) {
            if (!is_string($id) || $id === '' || !is_array($def)) {
                continue;
            }
            $modules = array_values(array_filter(array_map(
                static fn (mixed $v): string => is_string($v) ? trim($v) : '',
                is_array($def['modules'] ?? null) ? $def['modules'] : [],
            ), static fn (string $v): bool => $v !== ''));
            $requiredRaw = is_array($def['required'] ?? null) ? $def['required'] : null;
            $required = array_values(array_filter(array_map(
                static fn (mixed $v): string => is_string($v) ? trim($v) : '',
                $requiredRaw ?? $modules,
            ), static fn (string $v): bool => $v !== ''));
            // Explicit empty required = nothing mandatory (recommended only).
            if ($requiredRaw === []) {
                $required = [];
            }

            $surface = strtolower(trim((string) ($def['surface'] ?? 'packages')));
            if (!in_array($surface, ['packages', 'plugins'], true)) {
                $surface = 'packages';
            }

            $out[$id] = [
                'name' => (string) ($def['name'] ?? $id),
                'label' => (string) ($def['label'] ?? $def['name'] ?? $id),
                'description' => (string) ($def['description'] ?? ''),
                'surface' => $surface,
                'order' => (int) ($def['order'] ?? 100),
                'modules' => $modules,
                'required' => $required,
            ];
        }

        return $out;
    }

    /** @return array<string, list<string>> groupId => moduleIds */
    private function membershipFromManifests(): array
    {
        $map = [];
        foreach ($this->modules->discover() as $manifest) {
            foreach ($manifest->groups as $groupId) {
                $groupId = trim($groupId);
                if ($groupId === '') {
                    continue;
                }
                $map[$groupId][] = $manifest->id;
            }
        }

        return $map;
    }
}
