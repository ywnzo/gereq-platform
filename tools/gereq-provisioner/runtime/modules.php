<?php

declare(strict_types=1);

use Gereq\Module\ModuleManager;

/** @return list<string> */
function provisionModuleSelection(ModuleManager $manager): array
{
    $raw = env_value('GEREQ_PACKAGE_MODULES', null);
    $selected = $raw === null ? ['cms', 'site_inquiries', 'analytics'] : explode(',', (string) $raw);
    if ($selected === [] || !in_array('cms', $selected, true) || count($selected) !== count(array_unique($selected))) {
        throw new RuntimeException('Invalid package module selection');
    }
    foreach ($selected as $id) {
        $manifest = $manager->manifest($id);
        if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $id) || $manifest === null) {
            throw new RuntimeException('Selected module unavailable in image');
        }
        foreach (array_keys($manifest->requires) as $dependency) {
            if ($dependency !== 'core' && !in_array($dependency, $selected, true) && !$manager->isEnabled($dependency)) {
                throw new RuntimeException('Package module dependency missing');
            }
        }
    }
    return $manager->sortForEnable($selected);
}

/** @return list<string> */
function provisionPersistedModuleIds(ModuleManager $manager): array
{
    $enabled = [];
    foreach ($manager->installedById() as $id => $row) {
        if ($row->enabled() && $manager->manifest($id) !== null) {
            $enabled[] = $id;
        }
    }
    return $enabled;
}

/** @param list<string> $selected */
function provisionAssertModuleSelection(ModuleManager $manager, array $selected): void
{
    $enabled = provisionPersistedModuleIds($manager);
    if (array_diff($selected, $enabled) !== [] || array_diff($enabled, $selected) !== []) {
        throw new RuntimeException('Enabled modules do not match package selection');
    }
}
