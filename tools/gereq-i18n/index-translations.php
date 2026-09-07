<?php

declare(strict_types=1);

/**
 * Index překladových klíčů z resources/ + modules/ + integrations/.
 *
 * Výstup: .cache/i18n/catalog.json
 *
 * Usage:
 *   php tools/gereq-i18n/index-translations.php
 */

$basePath = dirname(__DIR__, 2);

$dirs = [$basePath . '/resources/translations'];
foreach (['modules', 'integrations'] as $rootName) {
    $root = $basePath . '/' . $rootName;
    if (!is_dir($root)) {
        continue;
    }
    foreach (scandir($root) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $dir = $root . '/' . $entry . '/Resources/translations';
        if (is_dir($dir)) {
            $dirs[] = $dir;
        }
    }
}

/** @var array<string, array<string, array{value: string, file: string, line: int}>> $keys */
$keys = [];
/** @var array<string, true> $locales */
$locales = [];

foreach ($dirs as $dir) {
    foreach (glob($dir . '/*.php') ?: [] as $file) {
        $basename = basename($file, '.php');
        // domain.locale.php → messages.cs.php
        if (!preg_match('/^([a-z0-9_]+)\.([a-z]{2}(?:_[A-Z]{2})?)$/', $basename, $m)) {
            continue;
        }
        $domain = $m[1];
        $locale = $m[2];
        $locales[$locale] = true;

        /** @var mixed $loaded */
        $loaded = include $file;
        if (!is_array($loaded)) {
            continue;
        }

        $lines = lineMapForFile($file);

        foreach ($loaded as $key => $value) {
            if (!is_string($key) || !(is_string($value) || is_numeric($value))) {
                continue;
            }

            $keys[$key][$locale] = [
                'value' => (string) $value,
                'file' => relativePath($basePath, $file),
                'line' => $lines[$key] ?? 1,
                'domain' => $domain,
            ];
        }
    }
}

ksort($keys);
$localeList = array_keys($locales);
sort($localeList);

$cacheDir = $basePath . '/.cache/i18n';
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0775, true);
}

$outFile = $cacheDir . '/catalog.json';
file_put_contents($outFile, json_encode([
    'generated_at' => date('c'),
    'locales' => $localeList,
    'keys' => $keys,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

fwrite(STDOUT, 'Indexed ' . count($keys) . ' keys, locales [' . implode(', ', $localeList) . "] → {$outFile}\n");

/**
 * @return array<string, int> key => 1-based line
 */
function lineMapForFile(string $file): array
{
    $map = [];
    $src = file($file, FILE_IGNORE_NEW_LINES);
    if ($src === false) {
        return $map;
    }
    foreach ($src as $i => $line) {
        if (preg_match("/^\\s*'((?:\\\\'|[^'])*)'\\s*=>/", $line, $m)
            || preg_match('/^\\s*"((?:\\\\"|[^"])*)"\\s*=>/', $line, $m)
        ) {
            $key = stripcslashes($m[1]);
            if (!isset($map[$key])) {
                $map[$key] = $i + 1;
            }
        }
    }

    return $map;
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
