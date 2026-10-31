<?php

declare(strict_types=1);

use Gereq\Audit\Auditor;
use Gereq\Foundation\Application;
use Gereq\Publish\PublishService;
use Gereq\Publish\StagingStore;
use Gereq\Publish\StagingWriter;
use Gereq\Setting\SettingRecord;
use Gereq\Setting\Settings;

$basePath = getenv('GEREQ_INSTANCE_ROOT') ?: '/app';
require $basePath . '/vendor/autoload.php';
ob_start();
try {
    $name = $argv[1] ?? '';
    $site = $argv[2] ?? '';
    $mode = $argv[3] ?? 'initialize';
    if ($name === '' || trim($name) !== $name || mb_strlen($name) > 100
        || preg_match('/[<>\x00-\x1f\x7f]/u', $name)
        || !preg_match('/^[a-z][a-z0-9-]{2,39}$/D', $site)
        || !in_array($mode, ['initialize', 'repair'], true)) {
        throw new InvalidArgumentException('Invalid brand initialization input');
    }
    $container = Application::create($basePath)->container();
    if (parse_url((string) config('app.url'), PHP_URL_HOST) !== $site . '.localhost') {
        throw new RuntimeException('Instance identity mismatch');
    }
    $settings = $container->get(Settings::class);
    $marker = 'provision.brand.initialized';
    $changed = [];
    if ($mode === 'repair' || !$settings->getLive($marker, false)) {
        $store = $container->get(StagingStore::class);
        $writer = $container->get(StagingWriter::class);
        $ids = [];
        foreach (['brand.name', 'brand.site_name'] as $key) {
            $live = (string) $settings->getLive($key, '');
            $staged = $store->findSettingsOverlay($key);
            // Initialization must not replace a name the owner has already customized.
            if ($mode === 'initialize' && (($settings->has($key) && $live !== '' && $live !== $site) || ($staged !== null && ($staged['value'] ?? null) !== $name))) {
                continue;
            }
            if ($live === $name && $staged === null) {
                continue;
            }
            $entry = $writer->stageSetting($key, $name, SettingRecord::TYPE_STRING, 'core');
            $ids[] = $entry->id();
            $changed[] = $key;
        }
        if ($ids !== []) {
            // Publish exactly the name keys; unrelated drafts and palette changes stay staged.
            $container->get(PublishService::class)->publish($ids);
        }
        if (!$settings->has('app.name') || $settings->getLive('app.name') === $site) {
            $settings->setLive('app.name', $name, SettingRecord::TYPE_STRING, 'core');
        }
        $settings->setLive($marker, true, SettingRecord::TYPE_BOOLEAN, 'provision');
        $container->get(Auditor::class)->log('provision.brand.' . $mode, 'settings', 'brand.name');
    }
    ob_end_clean();
    echo json_encode(['ok' => true, 'changed' => $changed], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    ob_end_clean();
    fwrite(STDERR, 'Brand initialization failed: ' . get_class($error)
        . (getenv('GEREQ_PROVISION_TEST_DEBUG') === '1' ? ': ' . $error->getMessage() : '') . PHP_EOL);
    exit(1);
}
