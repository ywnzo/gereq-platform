<?php

declare(strict_types=1);

use Gereq\Support\AppVersion;

return [
    'name' => env_value('APP_NAME', 'GEREQ Platform'),
    'env' => env_value('APP_ENV', 'production'),
    'debug' => (bool) env_value('APP_DEBUG', false),
    'url' => rtrim((string) env_value('APP_URL', 'http://localhost:8080'), '/'),
    'url_prefix' => (static function (): string {
        $explicit = trim((string) env_value('APP_URL_PREFIX', ''));
        if ($explicit !== '') {
            $p = '/' . trim($explicit, '/');

            return $p === '/' ? '' : $p;
        }
        $path = rtrim((string) (parse_url((string) env_value('APP_URL', ''), PHP_URL_PATH) ?? ''), '/');
        if ($path === '' || $path === '/') {
            return '';
        }
        if (str_ends_with($path, '/admin')) {
            $path = substr($path, 0, -strlen('/admin'));
        }

        return ($path === '' || $path === '/') ? '' : $path;
    })(),
    'key' => env_value('APP_KEY', ''),
    'timezone' => 'Europe/Prague',
    'locale' => 'cs',
    /** Semver — source of truth is the root VERSION file. */
    'version' => AppVersion::current(dirname(__DIR__)),
    /**
     * Absolute project path on the machine running the editor (host).
     * In Docker basePath is /var/www/html — set the host path so template clicks open locally.
     */
    'debug_editor_root' => (string) env_value('APP_DEBUG_EDITOR_ROOT', ''),
    /** Minimum repeat count of the same SQL fingerprint before we report N+1 (only when app.debug). */
    'n_plus_one_threshold' => max(2, (int) env_value('APP_N_PLUS_ONE_THRESHOLD', 2)),
    /** Log N+1 to error_log (only when app.debug). */
    'n_plus_one_log' => (bool) env_value('APP_N_PLUS_ONE_LOG', true),
];
