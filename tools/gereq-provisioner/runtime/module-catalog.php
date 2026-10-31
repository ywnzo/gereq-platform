<?php

declare(strict_types=1);

use Gereq\Module\ModuleDiscovery;

// Read the actual backend discovery catalog without booting an application or database.
try {
    $root = realpath($argv[1] ?? '');
    if ($root === false || !is_file($root . '/vendor/autoload.php')) {
        throw new RuntimeException('Backend source unavailable');
    }
    require $root . '/vendor/autoload.php';
    $catalog = ModuleDiscovery::discover($root);
    $modules = [];
    foreach ($catalog as $manifest) {
        $requires = array_values(array_filter(array_keys($manifest->requires), static fn (string $id): bool => isset($catalog[$id])));
        if ($manifest->parentId !== null && !in_array($manifest->parentId, $requires, true)) {
            $requires[] = $manifest->parentId;
        }
        $description = $manifest->description;
        $translationsFile = $manifest->path . '/Resources/translations/messages.cs.php';
        if (is_file($translationsFile)) {
            $translations = require $translationsFile;
            $description = is_array($translations) && is_string($translations[$description] ?? null) ? $translations[$description] : $description;
        }
        $modules[] = [
            'id' => $manifest->id, 'name' => $manifest->name, 'description' => $description,
            'version' => $manifest->version, 'kind' => $manifest->kind, 'parent_id' => $manifest->parentId,
            'requires' => $requires,
        ];
    }
    usort($modules, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));
    echo json_encode(['modules' => $modules], JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable) {
    fwrite(STDERR, 'Backend module catalog unavailable.' . PHP_EOL);
    exit(1);
}
