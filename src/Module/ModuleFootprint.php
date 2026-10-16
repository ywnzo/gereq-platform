<?php

declare(strict_types=1);

namespace Gereq\Module;

/**
 * Immutable content fingerprint of a module tree (SHA-256 over sorted path+file digests).
 * Excludes VCS metadata ({@see SKIP_NAMES}, including a submodule `.git` gitfile) and {@see META_FILE}.
 */
final class ModuleFootprint
{
    public const META_FILE = '.gereq-version.json';

    /** @var list<string> */
    private const SKIP_NAMES = ['.git', '.svn', '.hg', 'node_modules', 'vendor'];

    /**
     * Hex SHA-256 of the module directory contents. Empty / missing tree → empty string.
     *
     * @param bool $legacy When true, hash the pre-fix tree (a submodule `.git` *file* is included).
     *                     Used only to recognize fingerprints already stored in the database.
     */
    public static function ofDirectory(string $root, bool $legacy = false): string
    {
        $real = realpath($root);
        if ($real === false || !is_dir($real)) {
            return '';
        }

        $entries = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator(
                    $real,
                    \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO,
                ),
                static function (\SplFileInfo $current) use ($legacy): bool {
                    $name = $current->getFilename();
                    if ($name === self::META_FILE) {
                        return false;
                    }
                    if (in_array($name, self::SKIP_NAMES, true)) {
                        // Submodules keep a `.git` gitfile in the work tree. Its gitdir path
                        // changes when the parent repo is moved or re-linked, without any
                        // change to module source. Legacy fingerprints hashed that file.
                        return $legacy && !$current->isDir();
                    }

                    return true;
                },
            ),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile() || $file->isLink()) {
                continue;
            }
            $absolute = $file->getPathname();
            $relative = substr($absolute, strlen($real) + 1);
            if ($relative === false || $relative === '') {
                continue;
            }
            $relative = str_replace('\\', '/', $relative);
            $digest = hash_file('sha256', $absolute);
            if (!is_string($digest) || $digest === '') {
                continue;
            }
            $entries[$relative] = $digest;
        }

        if ($entries === []) {
            return hash('sha256', '');
        }

        ksort($entries, SORT_STRING);
        $lines = [];
        foreach ($entries as $path => $digest) {
            $lines[] = $digest . '  ' . $path;
        }

        return hash('sha256', implode("\n", $lines) . "\n");
    }

    /**
     * Short display form (first 12 hex chars).
     */
    public static function short(string $footprint): string
    {
        if ($footprint === '') {
            return '—';
        }

        return substr($footprint, 0, 12);
    }
}
