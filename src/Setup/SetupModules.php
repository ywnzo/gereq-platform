<?php

declare(strict_types=1);

namespace Gereq\Setup;

use Gereq\Database\Migrator;
use Gereq\Module\ModuleManager;

final class SetupModules
{
    public function __construct(private readonly ModuleManager $modules, private readonly Migrator $migrator)
    {
    }

    /** @param list<string> $selected
     *  @return list<string>
     */
    public function resolve(array $selected): array
    {
        $ids = [];
        $visiting = [];
        $visit = function (string $id) use (&$visit, &$ids, &$visiting): void {
            if (isset($ids[$id]) || $id === 'core') {
                return;
            }
            $manifest = $this->modules->manifest($id);
            if ($manifest === null) {
                if ($this->modules->isEnabled($id)) {
                    return;
                }
                throw new \InvalidArgumentException('setup.error.modules');
            }
            if (isset($visiting[$id])) {
                throw new \InvalidArgumentException('setup.error.modules');
            }
            $visiting[$id] = true;
            foreach (array_keys($manifest->requires) as $dependency) {
                $visit($dependency);
            }
            unset($visiting[$id]);
            $ids[$id] = true;
        };
        foreach ($selected as $id) {
            $manifest = $this->modules->manifest($id);
            if ($manifest === null) {
                throw new \InvalidArgumentException('setup.error.modules');
            }
            $visit($id);
        }

        return array_keys($ids);
    }

    /** @param list<string> $selected
     *  @return list<string>
     */
    public function apply(array $selected): array
    {
        $ids = $this->resolve($selected);
        $removed = array_values(array_diff($this->modules->enabledIds(), $ids));
        $result = $this->modules->pauseMany($removed);
        if ($result['errors'] !== []) {
            throw new \RuntimeException(implode(' ', $result['errors']));
        }
        foreach ($ids as $id) {
            if (!$this->modules->isEnabled($id)) {
                $this->modules->enable($id);
            }
            $this->migrator->migrateModule($id);
        }

        return $ids;
    }
}
