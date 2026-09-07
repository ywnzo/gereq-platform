<?php

declare(strict_types=1);

/**
 * Index GEREQ permissions z module.php + core seeder.
 *
 * Výstup: .cache/permissions/catalog.json
 *
 * Usage:
 *   php tools/gereq-permissions/index-permissions.php
 */

$basePath = dirname(__DIR__, 2);

/** @var array<string, array{name:string,label:string,group:string,module:?string,file:string,line:int,labels:array<string,string>,groups:array<string,string>}> $perms */
$perms = [];

$translations = loadTranslationMaps($basePath);

// —— core from Seeder ——
$seeder = $basePath . '/src/Database/Seeder.php';
if (is_file($seeder)) {
    $lines = file($seeder) ?: [];
    foreach ($lines as $i => $line) {
        if (preg_match("/\\['([a-z0-9_.]+)',\\s*'([^']+)',\\s*'([^']+)'\\]/", $line, $m)) {
            addPerm($perms, $m[1], $m[2], $m[3], 'core', relativePath($basePath, $seeder), $i + 1, $translations);
        }
    }
}

// —— CoreRegistrar: array triples + register() ——
$registrar = $basePath . '/src/Foundation/CoreRegistrar.php';
if (is_file($registrar)) {
    scanArrayTriples($perms, $registrar, 'core', $translations, $basePath);
    scanRegisterCalls($perms, $registrar, 'core', $translations, $basePath);
}

// —— modules + integrations: module.php + *Module.php register() ——
foreach (['modules', 'integrations'] as $rootName) {
    $root = $basePath . '/' . $rootName;
    if (!is_dir($root)) {
        continue;
    }
    foreach (scandir($root) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $file = $root . '/' . $entry . '/module.php';
        if (is_file($file)) {
            /** @var mixed $manifest */
            $manifest = include $file;
            if (is_array($manifest) && !empty($manifest['permissions']) && is_array($manifest['permissions'])) {
                $moduleId = (string) ($manifest['id'] ?? $entry);
                $rel = relativePath($basePath, $file);
                $lineMap = permissionLinesInFile($file);

                foreach ($manifest['permissions'] as $row) {
                    if (!is_array($row) || empty($row['name'])) {
                        continue;
                    }
                    $name = (string) $row['name'];
                    $label = (string) ($row['label'] ?? ('perm.' . $name));
                    $group = (string) ($row['group'] ?? 'perm.group.' . $moduleId);
                    $line = $lineMap[$name] ?? 1;
                    addPerm($perms, $name, $label, $group, $moduleId, $rel, $line, $translations);
                }
            }
        }

        // runtime register() in Module class
        foreach (glob($root . '/' . $entry . '/*Module.php') ?: [] as $modFile) {
            $moduleId = strtolower($entry);
            scanRegisterCalls($perms, $modFile, $moduleId, $translations, $basePath);
        }
    }
}

ksort($perms);

$byGroup = [];
foreach ($perms as $p) {
    $byGroup[$p['group']][] = $p['name'];
}
ksort($byGroup);

$cacheDir = $basePath . '/.cache/permissions';
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0775, true);
}

$outFile = $cacheDir . '/catalog.json';
file_put_contents($outFile, json_encode([
    'generated_at' => date('c'),
    'count' => count($perms),
    'permissions' => $perms,
    'groups' => $byGroup,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

fwrite(STDOUT, 'Indexed ' . count($perms) . ' permissions, ' . count($byGroup) . " groups → {$outFile}\n");

/**
 * @param array<string, array<string, mixed>> $perms
 * @param array<string, array<string, string>> $translations
 */
function scanArrayTriples(
    array &$perms,
    string $file,
    string $module,
    array $translations,
    string $basePath,
): void {
    $lines = file($file) ?: [];
    foreach ($lines as $i => $line) {
        if (preg_match("/\\['([a-z0-9_.]+)',\\s*'([^']+)',\\s*'([^']+)'\\]/", $line, $m)) {
            addPerm($perms, $m[1], $m[2], $m[3], $module, relativePath($basePath, $file), $i + 1, $translations);
        }
    }
}

/**
 * @param array<string, array<string, mixed>> $perms
 * @param array<string, array<string, string>> $translations
 */
function scanRegisterCalls(
    array &$perms,
    string $file,
    string $module,
    array $translations,
    string $basePath,
): void {
    $lines = file($file) ?: [];
    foreach ($lines as $i => $line) {
        // ->register('name', 'label', 'group') or permissions()->register(...)
        if (preg_match(
            "/register\(\s*'([a-z0-9_.]+)'\s*,\s*'([^']+)'\s*,\s*'([^']+)'\s*\)/",
            $line,
            $m,
        )) {
            addPerm(
                $perms,
                $m[1],
                $m[2],
                $m[3],
                $module,
                relativePath($basePath, $file),
                $i + 1,
                $translations,
            );
        }
    }
}

/**
 * @param array<string, array<string, mixed>> $perms
 * @param array<string, array<string, string>> $translations
 */
function addPerm(
    array &$perms,
    string $name,
    string $labelKey,
    string $groupKey,
    ?string $module,
    string $file,
    int $line,
    array $translations,
): void {
    $labels = [];
    $groups = [];
    foreach (['cs', 'en'] as $loc) {
        if (isset($translations[$loc][$labelKey])) {
            $labels[$loc] = $translations[$loc][$labelKey];
        }
        if (isset($translations[$loc][$groupKey])) {
            $groups[$loc] = $translations[$loc][$groupKey];
        }
    }

    $perms[$name] = [
        'name' => $name,
        'label' => $labelKey,
        'group' => $groupKey,
        'module' => $module,
        'file' => $file,
        'line' => $line,
        'labels' => $labels,
        'groups' => $groups,
    ];
}

/** @return array<string, int> */
function permissionLinesInFile(string $file): array
{
    $map = [];
    foreach (file($file) ?: [] as $i => $line) {
        if (preg_match("/'name'\\s*=>\\s*'([a-z0-9_.]+)'/", $line, $m)) {
            $map[$m[1]] = $i + 1;
        }
    }

    return $map;
}

/**
 * @return array<string, array<string, string>> locale => key => value
 */
function loadTranslationMaps(string $basePath): array
{
    $out = ['cs' => [], 'en' => []];
    $files = array_merge(
        glob($basePath . '/resources/translations/messages.*.php') ?: [],
        glob($basePath . '/modules/*/Resources/translations/messages.*.php') ?: [],
        glob($basePath . '/integrations/*/Resources/translations/messages.*.php') ?: [],
    );
    foreach ($files as $file) {
        if (!preg_match('/messages\.([a-z]{2}(?:_[A-Z]{2})?)\.php$/', $file, $m)) {
            continue;
        }
        $locale = $m[1];
        /** @var mixed $data */
        $data = include $file;
        if (!is_array($data)) {
            continue;
        }
        if (!isset($out[$locale])) {
            $out[$locale] = [];
        }
        foreach ($data as $k => $v) {
            if (is_string($k) && is_string($v) && (str_starts_with($k, 'perm.') || str_starts_with($k, 'perm.group.'))) {
                $out[$locale][$k] = $v;
            }
        }
    }

    return $out;
}

function relativePath(string $base, string $abs): string
{
    $base = rtrim(str_replace('\\', '/', $base), '/') . '/';
    $abs = str_replace('\\', '/', $abs);
    if (str_starts_with($abs, $base)) {
        return substr($abs, strlen($base));
    }

    return $abs;
}
