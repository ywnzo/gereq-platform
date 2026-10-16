<?php

declare(strict_types=1);

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

$packages = [];
foreach (\Gereq\Module\ModuleDiscovery::discover(dirname(__DIR__)) as $manifest) {
    $packages[] = ['id' => $manifest->id, 'path' => $manifest->path];
}
echo json_encode($packages, JSON_THROW_ON_ERROR);
