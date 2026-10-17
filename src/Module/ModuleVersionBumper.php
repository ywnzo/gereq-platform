<?php

declare(strict_types=1);

namespace Gereq\Module;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Bumps a module's patch version in module.php and Module::version() (semver rollover at 100).
 */
final class ModuleVersionBumper
{
    public function __construct(
        private readonly ModuleManager $modules,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @return string New version
     */
    public function bumpPatch(string $moduleId): string
    {
        $manifest = $this->modules->manifest($moduleId);
        if ($manifest === null) {
            throw new \RuntimeException($this->translator->trans('modules.error.not_found', ['%id%' => $moduleId]));
        }

        $old = $manifest->version;
        $new = ModuleSemver::bumpPatch($old);
        if ($new === $old) {
            throw new \RuntimeException($this->translator->trans('admin.modules.gate.bump_failed', [
                '%module%' => $manifest->name,
            ]));
        }

        $modulePhp = $manifest->path . '/module.php';
        if (!is_file($modulePhp) || !is_writable($modulePhp)) {
            throw new \RuntimeException($this->translator->trans('admin.modules.gate.bump_failed', [
                '%module%' => $manifest->name,
            ]));
        }

        $this->rewriteManifestVersion($modulePhp, $old, $new);
        $classFile = $this->resolveClassFile($manifest);
        if ($classFile !== null) {
            $this->rewriteClassVersion($classFile, $old, $new);
        }

        $this->modules->rediscover(false);
        $fresh = $this->modules->manifest($moduleId);
        if ($fresh === null || $fresh->version !== $new) {
            throw new \RuntimeException($this->translator->trans('admin.modules.gate.bump_failed', [
                '%module%' => $manifest->name,
            ]));
        }

        return $new;
    }

    private function resolveClassFile(ModuleManifest $manifest): ?string
    {
        $short = $manifest->moduleClass;
        if (str_contains($short, '\\')) {
            $parts = explode('\\', $short);
            $short = (string) end($parts);
        }
        $candidate = $manifest->path . '/' . $short . '.php';
        if (is_file($candidate)) {
            return $candidate;
        }

        try {
            if (class_exists($manifest->moduleClass)) {
                $ref = new \ReflectionClass($manifest->moduleClass);
                $file = $ref->getFileName();
                if (is_string($file) && is_file($file)) {
                    return $file;
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    private function rewriteManifestVersion(string $path, string $old, string $new): void
    {
        $raw = file_get_contents($path);
        if (!is_string($raw)) {
            throw new \RuntimeException('Unable to read module.php');
        }
        $updated = preg_replace(
            "/(['\"]version['\"]\\s*=>\\s*)(['\"])" . preg_quote($old, '/') . "\\2/",
            '${1}${2}' . $new . '${2}',
            $raw,
            1,
            $count,
        );
        if (!is_string($updated) || $count !== 1) {
            throw new \RuntimeException('Unable to bump version in module.php');
        }
        if (file_put_contents($path, $updated) === false) {
            throw new \RuntimeException('Unable to write module.php');
        }
    }

    private function rewriteClassVersion(string $path, string $old, string $new): void
    {
        $raw = file_get_contents($path);
        if (!is_string($raw) || !is_writable($path)) {
            return;
        }
        $updated = preg_replace(
            '/(function\\s+version\\s*\\(\\s*\\)\\s*:\\s*string\\s*\\{[[:space:]]*return\\s+)([\'"])'
            . preg_quote($old, '/')
            . '\\2(\\s*;)/',
            '${1}${2}' . $new . '${2}${3}',
            $raw,
            1,
            $count,
        );
        if (is_string($updated) && $count === 1) {
            file_put_contents($path, $updated);
        }
    }
}
