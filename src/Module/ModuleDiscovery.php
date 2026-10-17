<?php

declare(strict_types=1);

namespace Gereq\Module;

/** Reads bundled packages without requiring a database or container. */
final class ModuleDiscovery
{
    /** @var array<string, array<string, ModuleManifest>> */
    private static array $memo = [];

    /** @return array<string, ModuleManifest> */
    public static function discover(string $basePath, bool $validateDeclarations = true): array
    {
        $basePath = rtrim($basePath, '/');
        $key = $basePath . "\0" . ($validateDeclarations ? '1' : '0');
        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }

        $manifests = [];
        foreach (['modules', 'integrations'] as $directory) {
            foreach (glob($basePath . '/' . $directory . '/*/module.php') ?: [] as $file) {
                $parent = self::read($file);
                self::add($manifests, $parent);
                $hasClass = $validateDeclarations && is_a($parent->moduleClass, ModuleInterface::class, true);
                if (
                    $validateDeclarations && (($hasClass && $parent->moduleClass::SUBMODULES !== $parent->submodules)
                    || (!$hasClass && $parent->submodules !== []))
                ) {
                    throw new \InvalidArgumentException('Module SUBMODULES must match manifest submodules: ' . $parent->id);
                }
                foreach ($parent->submodules as $relative) {
                    $root = realpath($parent->path);
                    $childPath = realpath($parent->path . '/' . $relative);
                    if ($root === false || $childPath === false || !str_starts_with($childPath, $root . '/')) {
                        throw new \InvalidArgumentException('Submodule must live inside its parent: ' . $relative);
                    }
                    if (file_exists($childPath . '/.git')) {
                        throw new \InvalidArgumentException('Bundled submodules cannot have their own Git repository.');
                    }
                    $child = self::read($childPath . '/module.php', $parent->id);
                    if (in_array($child->id, ['core', 'notifications', 'webhooks', 'media', 'currency', 'customers'], true)) {
                        throw new \InvalidArgumentException('Submodule id conflicts with a core feature: ' . $child->id);
                    }
                    if ($child->isIntegration() !== $parent->isIntegration()) {
                        throw new \InvalidArgumentException('Submodule kind must match its parent.');
                    }
                    if ($child->repository !== null) {
                        throw new \InvalidArgumentException('Submodules are distributed through their parent repository.');
                    }
                    $namespace = $parent->phpNamespace();
                    if (!str_starts_with($child->moduleClass, $namespace)) {
                        throw new \InvalidArgumentException('Submodule class must use its parent namespace.');
                    }
                    self::add($manifests, $child);
                }
            }
        }

        return self::$memo[$key] = $manifests;
    }

    /** Drop process-local discovery memo (after tree swap / enable tests). */
    public static function clearMemo(): void
    {
        self::$memo = [];
    }

    private static function read(string $file, ?string $parentId = null): ModuleManifest
    {
        if (!is_file($file)) {
            throw new \InvalidArgumentException('Missing module manifest: ' . $file);
        }
        $data = require $file;
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Module manifest must return an array: ' . $file);
        }

        return ModuleManifest::fromArray($data, dirname($file), $parentId);
    }

    /** @param array<string, ModuleManifest> $manifests */
    private static function add(array &$manifests, ModuleManifest $manifest): void
    {
        if (isset($manifests[$manifest->id])) {
            throw new \InvalidArgumentException('Duplicate package id: ' . $manifest->id);
        }
        $manifests[$manifest->id] = $manifest;
    }
}
