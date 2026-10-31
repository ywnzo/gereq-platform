<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Audit\Auditor;
use Gereq\Foundation\Application;
use Gereq\Hook\HookManager;
use Gereq\Module\ModuleManager;
use Gereq\Module\ModuleUpdater;
use Gereq\User\PermissionRegistry;
use Symfony\Contracts\Translation\TranslatorInterface;

// This script is embedded in the central worker and executed in the owned image.
$basePath = getenv('GEREQ_INSTANCE_ROOT') ?: '/app';
require $basePath . '/vendor/autoload.php';
ob_start();
try {
    $container = Application::create($basePath)->container();
    $manager = $container->get(ModuleManager::class);
    $target = json_decode($argv[2] ?? '', true, 32, JSON_THROW_ON_ERROR);
    $expected = json_decode($argv[3] ?? '', true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($target) || !array_is_list($target) || !in_array('cms', $target, true)
        || count($target) !== count(array_unique($target)) || !is_array($expected)) {
        throw new RuntimeException('Invalid module transition');
    }
    $actual = $manager->enabledIds();
    sort($actual);
    sort($expected);
    if ($actual !== $expected) {
        throw new RuntimeException('Source module set changed');
    }
    foreach ($target as $id) {
        if (!is_string($id) || !preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $id)) {
            throw new RuntimeException('Invalid module identifier');
        }
        $manifest = $manager->manifest($id);
        if ($manifest === null) {
            throw new RuntimeException('Module is not available in this image');
        }
        foreach (array_keys($manifest->requires) as $dependency) {
            if ($dependency !== 'core' && !in_array($dependency, $target, true)
                && ($manager->manifest($dependency) !== null || !$manager->isEnabled($dependency))) {
                throw new RuntimeException('Selected module dependency is missing');
            }
        }
    }
    $added = array_values(array_diff($target, $actual));
    $removed = array_values(array_diff($actual, $target));
    if (($argv[1] ?? '') === 'apply') {
        // Disable through the module manager, retaining immutable prebuilt assets in the image.
        $disabler = new ModuleManager(
            $basePath,
            $container->get(EntityManagerInterface::class),
            $container->get(PermissionRegistry::class),
            $container->get(Auditor::class),
            $container->get(TranslatorInterface::class),
            $container->get(HookManager::class),
        );
        foreach ($disabler->sortForDisable($removed) as $id) {
            $disabler->disable($id, $removed);
        }
        // This CLI-only transition is invoked by the central worker with its validated target.
        $_ENV['GEREQ_PACKAGE_MODULES'] = implode(',', $target);
        $manager->rediscover();
        foreach ($manager->sortForEnable($target) as $id) {
            if (in_array($id, $added, true)) {
                $manager->enable($id);
                $container->get(ModuleUpdater::class)->update($id);
            }
        }
        $enabled = $manager->enabledIds();
        sort($enabled);
        $wanted = $target;
        sort($wanted);
        if ($enabled !== $wanted) {
            throw new RuntimeException('Module transition did not produce the requested set');
        }
        $container->get(Auditor::class)->log('provision.package_modules.changed', 'setup', null, [
            'added' => $added, 'removed' => $removed,
        ]);
    } elseif (($argv[1] ?? '') !== 'preflight') {
        throw new RuntimeException('Unsupported module transition operation');
    }
    ob_end_clean();
    echo json_encode(['ok' => true, 'added' => $added, 'removed' => $removed], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    ob_end_clean();
    fwrite(STDERR, "Package module transition failed.\n");
    exit(1);
}
