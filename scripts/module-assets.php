<?php

declare(strict_types=1);

/**
 * Discover module asset roots for the Bun build.
 * Optional CLI arg: module id filter.
 *
 * Output JSON list: [{ id, path, js, css }, ...]
 * js/css are absolute paths or null.
 */

// The frontend CI job has PHP but does not install Composer dependencies.
$basePath = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($basePath): void {
    foreach (['Gereq\\' => 'src', 'Modules\\' => 'modules', 'Integrations\\' => 'integrations'] as $prefix => $directory) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }
        $file = $basePath . '/' . $directory . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

$filter = isset($argv[1]) ? strtolower(trim((string) $argv[1])) : '';

$packages = [];
foreach (\Gereq\Module\ModuleDiscovery::discover($basePath) as $manifest) {
    if ($filter !== '' && $manifest->id !== $filter) {
        continue;
    }
    $js = $manifest->path . '/Resources/assets/js/admin.ts';
    $css = $manifest->path . '/Resources/assets/css/admin.css';
    $packages[] = [
        'id' => $manifest->id,
        'path' => $manifest->path,
        'js' => is_file($js) ? $js : null,
        'css' => is_file($css) ? $css : null,
    ];
}

echo json_encode($packages, JSON_THROW_ON_ERROR);
