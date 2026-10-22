<?php

declare(strict_types=1);

/**
 * Frontend render engine — asset URLs, origins, and watch reload.
 * Empty asset_origin = same-origin (browser host). Set RENDER_ASSET_ORIGIN for a CDN.
 */
return [
    'asset_origin' => rtrim((string) env_value('RENDER_ASSET_ORIGIN', ''), '/'),
    'url_prefix' => (static function (): string {
        $explicit = trim((string) env_value('RENDER_URL_PREFIX', ''));
        if ($explicit === '') {
            return '';
        }
        $p = '/' . trim($explicit, '/');

        return $p === '/' ? '' : $p;
    })(),

    'public_path' => '/build',
    'manifest' => 'public/build/manifest.json',
    'dev_marker' => 'public/build/.dev',
    /** Browser poll target while `bun run dev` (non-dot twin of `.dev`). */
    'watch_state' => 'public/build/watch.json',

    'watch' => [
        'reload' => true,
        'poll_ms' => 1200,
        /** When true + APP_DEBUG, keep probing existing watch.json (idle/active) without 404. */
        'probe_when_debug' => true,
    ],

    'entries' => [
        'admin' => 'resources/js/admin.ts',
        'css' => 'resources/css/kernel.css',
    ],

    'csp_origins' => array_values(array_filter(array_map(
        static fn (string $v): string => rtrim(trim($v), '/'),
        explode(',', (string) env_value('RENDER_CSP_ORIGINS', '')),
    ))),
];
