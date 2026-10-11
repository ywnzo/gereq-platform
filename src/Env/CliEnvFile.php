<?php

declare(strict_types=1);

namespace Gereq\Env;

/**
 * Resolves which dotenv file to load for CLI via `config.env=<path>`.
 *
 * Example: `php gereq config.env=.env.production about`
 */
final class CliEnvFile
{
    public const OPTION = 'config.env';

    /** @var list<string> */
    public const CLI_OVERLAY_FILES = ['.env.cli', '.env.bin'];

    private static ?string $resolvedPath = null;

    private static bool $explicitEnvFile = false;

    public static function reset(): void
    {
        self::$resolvedPath = null;
        self::$explicitEnvFile = false;
    }

    /** Absolute path last resolved by {@see resolve()}, or null before first resolve. */
    public static function resolvedPath(): ?string
    {
        return self::$resolvedPath;
    }

    /**
     * Resolve the env file path for $basePath, stripping `config.env=` / `--config.env=` from argv.
     *
     * @param list<string>|null $argv Defaults to $_SERVER['argv']; mutated in place when provided or when using globals
     */
    public static function resolve(string $basePath, ?array &$argv = null): string
    {
        $basePath = rtrim($basePath, '/');
        $useGlobals = $argv === null;
        if ($useGlobals) {
            /** @var list<string> $argv */
            $argv = array_values(array_map(static fn (mixed $v): string => (string) $v, $_SERVER['argv'] ?? []));
        }

        $explicit = self::extractOption($argv);
        self::$explicitEnvFile = $explicit !== null;
        $relativeOrAbsolute = $explicit ?? '.env';
        $absolute = self::absolutePath($basePath, $relativeOrAbsolute);

        if ($explicit !== null && !is_file($absolute)) {
            throw new \InvalidArgumentException(
                'Env file not found for ' . self::OPTION . '=' . $explicit . ' (resolved: ' . $absolute . ')',
            );
        }

        if ($useGlobals) {
            $_SERVER['argv'] = $argv;
            if (array_key_exists('argv', $GLOBALS)) {
                $GLOBALS['argv'] = $argv;
            }
        }

        self::$resolvedPath = $absolute;

        return $absolute;
    }

    /**
     * Optional host CLI overlay loaded after the primary env file (see Application::loadEnv()).
     */
    public static function cliOverlayPath(string $basePath): ?string
    {
        if (self::$explicitEnvFile) {
            return null;
        }

        $basePath = rtrim($basePath, '/');
        foreach (self::CLI_OVERLAY_FILES as $name) {
            $path = $basePath . '/' . $name;
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    public static function absolutePath(string $basePath, string $envFile): string
    {
        $basePath = rtrim($basePath, '/');
        $envFile = trim($envFile);
        if ($envFile === '') {
            $envFile = '.env';
        }

        if (str_starts_with($envFile, '/')) {
            return $envFile;
        }
        if (str_starts_with($envFile, './')) {
            $envFile = substr($envFile, 2);
        }

        return $basePath . '/' . $envFile;
    }

    /**
     * @param list<string> $argv
     */
    private static function extractOption(array &$argv): ?string
    {
        $prefix = self::OPTION . '=';
        $longPrefix = '--' . $prefix;
        $value = null;
        $kept = [];

        foreach ($argv as $i => $arg) {
            if ($i === 0) {
                $kept[] = $arg;
                continue;
            }

            if (str_starts_with($arg, $prefix)) {
                $value = substr($arg, strlen($prefix));
                continue;
            }
            if (str_starts_with($arg, $longPrefix)) {
                $value = substr($arg, strlen($longPrefix));
                continue;
            }

            $kept[] = $arg;
        }

        $argv = $kept;

        return $value;
    }
}
