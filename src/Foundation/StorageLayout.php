<?php

declare(strict_types=1);

namespace Gereq\Foundation;

/**
 * Ensures runtime directories under storage/ exist.
 *
 * Git tracks only storage/.gitkeep; everything else is created at boot,
 * by cache:clear after a wipe, or by Docker entrypoint.
 */
final class StorageLayout
{
    /** @var list<string> Core cache pools recreated after cache:clear. */
    public const CACHE_SUBDIRS = [
        'data',
        'storefront',
        'twig',
        'doctrine',
        'rate',
        'media',
    ];

    /** @var list<string> Relative paths from the project root. */
    private const DIRECTORIES = [
        'storage/cache',
        'storage/cache/data',
        'storage/cache/storefront',
        'storage/cache/twig',
        'storage/cache/doctrine',
        'storage/cache/rate',
        'storage/cache/media',
        'storage/logs',
        'storage/sessions',
        'storage/tmp',
        'storage/tmp/queue',
        'storage/tmp/queue-delayed',
        'storage/tmp/schedule-locks',
        'storage/uploads',
        'storage/uploads/brand',
        'storage/framework',
        'storage/invoices',
        'storage/modules',
    ];

    public static function ensure(string $basePath): void
    {
        $basePath = rtrim($basePath, '/');
        foreach (self::DIRECTORIES as $relative) {
            self::ensureDirectory($basePath . '/' . $relative);
        }
    }

    /**
     * Recreate cache pool directories after a filesystem wipe.
     */
    public static function ensureCache(string $basePath): void
    {
        $basePath = rtrim($basePath, '/');
        self::ensureDirectory($basePath . '/storage/cache');
        foreach (self::CACHE_SUBDIRS as $subdir) {
            self::ensureDirectory($basePath . '/storage/cache/' . $subdir);
        }
    }

    private static function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !@mkdir($path, 0775, true) && !is_dir($path)) {
            return;
        }

        if (is_dir($path) && !is_writable($path)) {
            @chmod($path, 0775);
        }
    }
}
