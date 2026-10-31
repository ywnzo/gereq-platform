<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Database\Migrator;
use Gereq\Database\Seeder;
use Gereq\Foundation\Application;
use Gereq\Module\ModuleManager;
use Modules\Cms\Domain\Page;
use Modules\Cms\Http\PageController;
use Symfony\Component\HttpFoundation\Request;

$basePath = getenv('GEREQ_INSTANCE_ROOT') ?: '/app';
require $basePath . '/vendor/autoload.php';
require_once __DIR__ . '/modules.php';

// Bootstrap diagnostics may include credentials; never emit platform output.
ob_start();
try {
    $app = Application::create($basePath);
    $container = $app->container();
    $em = $container->get(EntityManagerInterface::class);
    $db = $em->getConnection();
    $db->executeStatement('PRAGMA busy_timeout=5000');
    $db->executeStatement('PRAGMA journal_mode=WAL');
    $db->executeStatement('PRAGMA foreign_keys=ON');
    $operation = $argv[1] ?? '';
    $moduleManager = $container->get(ModuleManager::class);
    $selectedModules = provisionModuleSelection($moduleManager);
    $result = ['ok' => true];
    $marker = $basePath . '/storage/.provision-bootstrap.json';
    $saveMarker = static function (array $value) use ($marker): void {
        $temp = tempnam(dirname($marker), '.bootstrap-');
        if ($temp === false) {
            throw new RuntimeException('Cannot write bootstrap marker');
        }
        chmod($temp, 0600);
        file_put_contents($temp, json_encode($value, JSON_THROW_ON_ERROR), LOCK_EX);
        if (!rename($temp, $marker)) {
            throw new RuntimeException('Cannot persist bootstrap marker');
        }
    };
    $secureAccess = static function () use ($marker, $em, $container, $saveMarker, $basePath): array {
        $data = json_decode(file_get_contents($marker), true, 16, JSON_THROW_ON_ERROR);
        if (($data['version'] ?? 0) === 1) {
            $user = $em->getRepository(Gereq\User\Entity\User::class)->findOneBy(['email' => 'operator@example.invalid']);
            if (!$user instanceof Gereq\User\Entity\User || $container->get(Gereq\Setup\SetupState::class)->isComplete()) {
                throw new RuntimeException('Legacy operator needs manual reconciliation');
            }
            // Invalidate the previously retained bootstrap password before deleting its file.
            $user->setPasswordHash($container->get(Gereq\Auth\PasswordHasher::class)->hash(bin2hex(random_bytes(32))));
            $container->get(Gereq\Auth\Authenticator::class)->revokeRememberTokens($user);
            $em->flush();
            foreach (glob($basePath . '/storage/sessions/*') ?: [] as $session) {
                if (is_link($session) || !is_file($session) || !unlink($session)) {
                    throw new RuntimeException('Cannot revoke legacy sessions');
                }
            }
            $data = ['version' => 2, 'user_id' => $user->id()];
            $saveMarker($data);
        }
        if (($data['version'] ?? 0) !== 2 || !is_string($data['user_id'] ?? null)) {
            throw new RuntimeException('Invalid bootstrap ownership marker');
        }
        return $data;
    };
    if ($operation === 'bootstrap') {
        if (!is_file($marker)) {
            $tables = $db->createSchemaManager()->listTableNames();
            if ($tables === []) {
                $container->get(Migrator::class)->migrate();
            }
            $users = $em->getRepository(Gereq\User\Entity\User::class)->findAll();
            if (count($users) > 1 || ($users !== [] && $users[0]->email() !== 'operator@example.invalid')) {
                throw new RuntimeException('Bootstrap ownership needs manual reconciliation');
            }
            // An unguessable password exists only in process memory; no login secret is exported.
            $container->get(Seeder::class)->seedBare([
                'email' => 'operator@example.invalid', 'name' => 'Administrator',
                'password' => bin2hex(random_bytes(32)), 'role' => 'superadmin', 'generated' => false,
            ]);
            $user = $em->getRepository(Gereq\User\Entity\User::class)->findOneBy(['email' => 'operator@example.invalid']);
            // A crash before the marker write may have left a legacy password hash.
            $user->setPasswordHash($container->get(Gereq\Auth\PasswordHasher::class)->hash(bin2hex(random_bytes(32))));
            $container->get(Gereq\Auth\Authenticator::class)->revokeRememberTokens($user);
            $em->flush();
            // The approved funnel uses semantic colors against this initial palette.
            // Apply it only when no palette exists during first bootstrap.
            $settings = $container->get(Gereq\Setting\Settings::class);
            if ($tables === [] && !$settings->has('brand.palette')) {
                $settings->setLive('brand.palette', [
                    'primary' => '#f5c518', 'secondary' => '#101211', 'accent' => '#a88400',
                    'light_background' => '#fff6c8', 'light_surface' => '#fff6c8', 'light_header' => '#fff6c8',
                    'dark_background' => '#101211', 'dark_surface' => '#161816', 'dark_header' => '#101211',
                    'storefront_mode' => 'light',
                ], Gereq\Setting\SettingRecord::TYPE_JSON, 'core');
                $container->get(Gereq\Cache\StoreFrontCache::class)->forget('branding');
            }
            $container->get(Gereq\Setup\SetupState::class)->markIncomplete();
            $saveMarker(['version' => 2, 'user_id' => $user->id()]);
        }
        $secureAccess();
    } elseif ($operation === 'capabilities') {
        $secureAccess();
        $result['generated_palette'] = true;
        $result['module_selection'] = true;
        $result['selected_modules'] = $selectedModules;
    } elseif ($operation === 'editorial-media') {
        $data = $secureAccess();
        $owner = $em->find(Gereq\User\Entity\User::class, $data['user_id']);
        if (!$owner instanceof Gereq\User\Entity\User) {
            throw new RuntimeException('Editorial media owner unavailable');
        }
        $settings = $container->get(Gereq\Setting\Settings::class);
        $files = $container->get(Gereq\File\FileManager::class);
        $existingId = $settings->get('provision.editorial_illustration', '');
        if (is_string($existingId) && $existingId !== '') {
            $asset = $em->find(Gereq\Media\Domain\MediaAsset::class, $existingId);
            if (!$asset instanceof Gereq\Media\Domain\MediaAsset || !$asset->isPublic() || !$files->filesystem()->fileExists($asset->path())) {
                throw new RuntimeException('Editorial illustration was removed or made private; select another image');
            }
        } else {
            $name = $argv[2] ?? '';
            if (!is_string($name) || !preg_match('/^\.editorial-upload-[A-Za-z0-9]+$/D', $name)) {
                throw new RuntimeException('Invalid editorial media input');
            }
            $input = $basePath . '/storage/' . $name;
            if (!is_file($input) || is_link($input)) {
                throw new RuntimeException('Editorial media input unavailable');
            }
            $uploaded = new Symfony\Component\HttpFoundation\File\UploadedFile($input, 'editorial-path.png', 'image/png', null, true);
            $stored = $files->store($uploaded, $owner);
            $dimensions = $stored->metadata();
            $asset = new Gereq\Media\Domain\MediaAsset('Editorial illustration', $stored->path(), $stored->mime(), $stored->size(), $owner->id(), null, $dimensions['width'] ?? null, $dimensions['height'] ?? null);
            $em->persist($asset);
            $em->flush();
            $settings->setLive('provision.editorial_illustration', $asset->id(), Gereq\Setting\SettingRecord::TYPE_STRING, 'provision');
            $container->get(Gereq\Event\EventBus::class)->dispatch(Gereq\Media\Domain\Events\MediaUploaded::make($asset->id(), ['id' => $asset->id(), 'title' => $asset->title(), 'mime' => $asset->mime()]));
            $container->get(Gereq\Audit\Auditor::class)->log('media.uploaded', 'media_asset', $asset->id());
        }
        $result['url'] = '/media/' . $asset->id() . '/file';
    } elseif ($operation === 'secure-access') {
        $secureAccess();
    } elseif ($operation === 'dashboard-catalog' || $operation === 'dashboard-preset') {
        $data = $secureAccess();
        $owner = $em->find(Gereq\User\Entity\User::class, $data['user_id']);
        if (!$owner instanceof Gereq\User\Entity\User || !$owner->can('settings.manage')) {
            throw new RuntimeException('Dashboard owner permission unavailable');
        }
        $dashboard = $container->get(Gereq\Admin\Dashboard\Dashboard::class);
        $settings = $container->get(Gereq\Setting\Settings::class);
        $catalog = $dashboard->catalog($owner);
        if ($operation === 'dashboard-catalog') {
            $result['widgets'] = $catalog;
        } else {
            $package = (string) config('app.package', '');
            if ($package !== 'funnel-v1') {
                throw new RuntimeException('Unsupported dashboard package');
            }
            $preset = json_decode(file_get_contents(__DIR__ . '/dashboard-presets/funnel-v1.json'), true, 16, JSON_THROW_ON_ERROR);
            if (($preset['schema_version'] ?? null) !== 2 || ($preset['id'] ?? null) !== $package || !is_array($preset['widgets'] ?? null)) {
                throw new RuntimeException('Invalid dashboard preset');
            }
            $desired = [];
            $available = array_column($catalog, null, 'id');
            foreach ($preset['widgets'] as $item) {
                $id = $item['id'] ?? null;
                if (is_string($id) && !isset($available[$id]) && in_array(explode('.', $id)[0], ['cms', 'site_inquiries', 'analytics'], true) && !in_array(explode('.', $id)[0], $selectedModules, true)) {
                    continue;
                }
                if (!is_string($id) || !isset($available[$id]) || isset($desired[$id])) {
                    throw new RuntimeException('Unavailable or duplicate dashboard widget');
                }
                $desired[$id] = $item;
            }
            $items = [];
            foreach ($catalog as $index => $item) {
                $items[] = $desired[$item['id']] ?? [
                    'id' => $item['id'], 'enabled' => false, 'order' => 1000 + $index, 'width' => $item['width'],
                ];
            }
            $result['applied'] = $em->wrapInTransaction(static function () use ($settings, $dashboard, $items, $owner, $preset, $container): bool {
                if ($settings->has(Gereq\Admin\Dashboard\Dashboard::LAYOUT_SETTING)) {
                    $marker = $settings->get('provision.dashboard_preset', []);
                    $current = $settings->get(Gereq\Admin\Dashboard\Dashboard::LAYOUT_SETTING, []);
                    $previous = json_decode(file_get_contents(__DIR__ . '/dashboard-presets/funnel-v1.previous.json'), true);
                    $previousById = array_column($previous['widgets'], null, 'id');
                    $untouched = ($marker['schema_version'] ?? null) === 1;
                    foreach ($current as $row) {
                        if (isset($previousById[$row['id']])) {
                            $untouched = $untouched && $row === $previousById[$row['id']];
                        } else {
                            $untouched = $untouched && $row['enabled'] === false;
                        }
                    }
                    if (!$untouched) {
                        return false; }
                }
                $dashboard->saveVisibleLayout($items, $owner);
                $settings->set('provision.dashboard_preset', [
                    'id' => $preset['id'], 'schema_version' => $preset['schema_version'],
                    'sha256' => hash('sha256', json_encode($preset, JSON_THROW_ON_ERROR)),
                ], Gereq\Setting\SettingRecord::TYPE_JSON, 'core');
                $container->get(Gereq\Audit\Auditor::class)->log('provision.dashboard_preset.applied', 'setup');

                return true;
            });
            $result['preset_id'] = $preset['id'];
            if (!$result['applied']) {
                $result['reason'] = 'existing_layout_preserved';
            }
        }
    } elseif ($operation === 'analytics-config') {
        if (!in_array('analytics', $selectedModules, true)) {
            throw new RuntimeException('Analytics is not selected for this package');
        }
        $sites = $container->get(Modules\Analytics\Application\Repositories\SiteRepository::class);
        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        if ($sites->findEnabledByDomain($host) === null) {
            $sites->create(['name' => (string) config('app.name'), 'domains' => [$host], 'enabled' => true, 'respect_dnt' => true]);
        }
    } elseif ($operation === 'storage-check') {
        $db->executeQuery('PRAGMA wal_checkpoint(TRUNCATE)')->fetchAllAssociative();
        if ($db->fetchOne('PRAGMA integrity_check') !== 'ok') {
            throw new RuntimeException('Database integrity check failed');
        }
    } elseif ($operation === 'setup-link') {
        $data = $secureAccess();
        if ($container->get(Gereq\Setup\SetupState::class)->isComplete()) {
            throw new RuntimeException('Customer setup already complete');
        }
        $email = strtolower(trim($argv[2] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 180) {
            throw new RuntimeException('Valid owner email required');
        }
        $user = $em->find(Gereq\User\Entity\User::class, $data['user_id']);
        if (!$user instanceof Gereq\User\Entity\User) {
            throw new RuntimeException('Bootstrap owner unavailable');
        }
        $existing = $em->getRepository(Gereq\User\Entity\User::class)->findOneBy(['email' => $email]);
        if ($existing !== null && $existing->id() !== $user->id()) {
            throw new RuntimeException('Email already belongs to another user');
        }
        $plain = bin2hex(random_bytes(32));
        $expires = new DateTimeImmutable('+1 hour');
        $em->wrapInTransaction(static function () use ($em, $user, $email, $plain, $expires, $container): void {
            foreach ($em->getRepository(Gereq\Auth\Entity\PasswordResetToken::class)->findBy(['user' => $user]) as $token) {
                $em->remove($token);
            }
            $user->setEmail($email);
            $user->setPasswordHash($container->get(Gereq\Auth\PasswordHasher::class)->hash(bin2hex(random_bytes(32))));
            $container->get(Gereq\Auth\Authenticator::class)->revokeRememberTokens($user);
            $em->persist(new Gereq\Auth\Entity\PasswordResetToken($user, hash('sha256', $plain), $expires));
        });
        $container->get(Gereq\Audit\Auditor::class)->log('provision.setup_link.issued', 'user', $user->id());
        $result['setup_url'] = rtrim((string) config('app.url'), '/') . '/admin/reset-password/' . $plain;
        $result['email'] = $email;
        $result['expires_at'] = $expires->format(DATE_ATOM);
    } elseif ($operation === 'upgrade') {
        $container->get(Migrator::class)->migrate();
        provisionAssertModuleSelection($moduleManager, $selectedModules);
        foreach ($selectedModules as $module) {
            $container->get(Gereq\Module\ModuleUpdater::class)->update($module);
        }
        if ($db->fetchOne('PRAGMA integrity_check') !== 'ok') {
            throw new RuntimeException('Upgraded database integrity failed');
        }
    } elseif ($operation === 'modules') {
        if (array_diff(provisionPersistedModuleIds($moduleManager), $selectedModules) !== []) {
            throw new RuntimeException('Unselected modules are already enabled');
        }
        foreach ($selectedModules as $module) {
            if (!$moduleManager->isEnabled($module)) {
                $moduleManager->enable($module);
            }
            $container->get(Gereq\Module\ModuleUpdater::class)->update($module);
        }
        provisionAssertModuleSelection($moduleManager, $selectedModules);
        $result['enabled_modules'] = $moduleManager->enabledIds();
    } elseif ($operation === 'health') {
        $db->fetchOne('SELECT 1');
        provisionAssertModuleSelection($moduleManager, $selectedModules);
        $result['enabled_modules'] = $moduleManager->enabledIds();
        if (!is_writable($basePath . '/storage') || !is_file('/frontend/index.html')) {
            throw new RuntimeException('Storage or frontend unavailable');
        }
        $body = file_get_contents('http://127.0.0.1:8080/_provision/health');
        if ($body !== 'ok') {
            throw new RuntimeException('Runtime unavailable');
        }
        $api = json_decode(file_get_contents('http://127.0.0.1:8080/api/v1/health', false, stream_context_create(['http' => ['timeout' => 5]])), true, 16, JSON_THROW_ON_ERROR);
        if (($api['data']['status'] ?? null) !== 'ok') {
            throw new RuntimeException('Platform HTTP unavailable');
        }
        $result['setup_required'] = $container->get(Gereq\Setup\SetupState::class)->isPending();
    } elseif ($operation === 'import') {
        $raw = file_get_contents($argv[2] ?? '');
        if ($raw === false || strlen($raw) > 1048576) {
            throw new RuntimeException('Invalid artifact size');
        }
        $artifact = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($artifact) || ($artifact['schema_version'] ?? null) !== 1
            || !is_string($artifact['artifact_id'] ?? null) || !preg_match('/^[a-zA-Z0-9-]{8,80}$/', $artifact['artifact_id'])
            || !is_string($artifact['title'] ?? null) || mb_strlen($artifact['title']) > 190
            || !preg_match('/^[a-z][a-z0-9-]{2,79}$/', $artifact['slug'] ?? '')
            || !is_array($artifact['blocks'] ?? null) || $artifact['blocks'] === []) {
            throw new RuntimeException('Invalid artifact');
        }
        $count = 0;
        $check = function (array $blocks, int $depth = 0) use (&$check, &$count): void {
            if ($depth > 8) {
                throw new RuntimeException('Block tree too deep');
            }
            foreach ($blocks as $block) {
                if (++$count > 300 || !is_array($block)
                    || !in_array($block['type'] ?? '', ['layout', 'heading', 'text', 'cta', 'image', 'divider', 'form', 'form_field', 'icon', 'spacer'], true)
                    || !is_array($block['payload'] ?? null)) {
                    throw new RuntimeException('Unsupported block');
                }
                $p = $block['payload'];
                foreach ($p as $key => $value) {
                    if (preg_match('/css|script|^html$|^gereq_|^bindings$|^data_/', (string) $key)) {
                        throw new RuntimeException('Unsupported property');
                    }
                    if (in_array($key, ['body', 'text', 'label'], true) && (!is_string($value) || str_contains(preg_replace('~</?em>|<br\s*/?>~i', '', $value) ?? $value, '<'))) {
                        throw new RuntimeException('Only plain text is allowed');
                    }
                    if (in_array($key, ['bg_color', 'text_color', 'border_color', 'hover_color', 'input_bg_color', 'input_text_color', 'input_border_color', 'input_focus_color', 'submit_bg_color', 'submit_text_color'], true)
                        && !in_array($value, ['none', 'inherit', 'paper', 'background', 'surface', 'header', 'ink', 'muted', 'brand', 'secondary', 'accent', 'brand-soft', 'light-background', 'light-surface', 'light-header', 'dark-background', 'dark-surface', 'dark-header'], true)) {
                        throw new RuntimeException('Unsupported color token');
                    }
                }
                if (($block['type'] ?? '') === 'form' && ($p['action'] ?? '') !== 'site_inquiries.create') {
                    throw new RuntimeException('Unsupported form action');
                }
                if (isset($p['children'])) {
                    if (!is_array($p['children'])) {
                        throw new RuntimeException('Invalid children');
                    }
                    $check($p['children'], $depth + 1);
                }
            }
        };
        $check($artifact['blocks']);
        $palette = $artifact['palette'] ?? null;
        if ($palette !== null) {
            if (!is_array($palette) || count($palette) !== 8 || ($palette['storefront_mode'] ?? null) !== 'light') {
                throw new RuntimeException('Invalid generated palette');
            }
            foreach (['primary', 'secondary', 'accent', 'light_background', 'light_surface', 'dark_background', 'dark_surface'] as $color) {
                if (!is_string($palette[$color] ?? null) || !preg_match('/^#[0-9a-fA-F]{6}$/D', $palette[$color])) {
                    throw new RuntimeException('Invalid generated palette color');
                }
            }
            (new Gereq\Brand\PaletteResolver())->resolve($palette);
        }
        $settings = $container->get(Gereq\Setting\Settings::class);
        $artifactKey = 'provision.artifact.' . $artifact['artifact_id'];
        $lock = fopen($basePath . '/storage/.provision-import.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('Import lock unavailable');
        }
        $saved = $settings->get($artifactKey);
        $hash = hash('sha256', $raw);
        if (is_array($saved)) {
            if ($saved['hash'] !== $hash) {
                throw new RuntimeException('Artifact ID reused with different content');
            }
            $result = ['ok' => true, 'page_id' => $saved['page_id'], 'draft' => true, 'preview_path' => '/admin/cms/pages/' . $saved['page_id'] . '/preview'];
        } else {
            if ($em->getRepository(Page::class)->findOneBy(['slug' => $artifact['slug']]) !== null) {
                throw new RuntimeException('Slug already exists; existing customer content is preserved');
            }
            $existingPalette = $container->get(Gereq\Publish\StagingStore::class)->findSettingsOverlay('brand.palette');
            if ($palette !== null && $existingPalette !== null && ($existingPalette['value'] ?? null) !== $palette) {
                throw new RuntimeException('A different brand palette is already staged; publish or discard it before importing');
            }
            $db->beginTransaction();
            try {
                if ($palette !== null) {
                    $container->get(Gereq\Publish\ContentReadContext::class)->useStaging();
                    $settings->set('brand.palette', $palette, Gereq\Setting\SettingRecord::TYPE_JSON, 'core');
                }
                $page = new Page($artifact['slug'], $artifact['title']);
                $em->persist($page);
                $em->flush();
                $request = new Request(content: json_encode(['blocks' => $artifact['blocks']], JSON_THROW_ON_ERROR));
                $container->get(PageController::class)->builderSave($request, $page->id());
                $settings->setLive($artifactKey, ['hash' => $hash, 'page_id' => $page->id()], Gereq\Setting\SettingRecord::TYPE_JSON, 'provision');
                $db->commit();
            } catch (Throwable $error) {
                $db->rollBack();
                throw $error;
            }
            $result = ['ok' => true, 'page_id' => $page->id(), 'draft' => true, 'preview_path' => '/admin/cms/pages/' . $page->id() . '/preview'];
        }
        flock($lock, LOCK_UN);
        fclose($lock);
    } else {
        throw new RuntimeException('Unknown operation');
    }
    ob_end_clean();
    echo json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    ob_end_clean();
    fwrite(STDERR, 'Provisioning step failed: ' . get_class($error) . (getenv('GEREQ_PROVISION_TEST_DEBUG') === '1' ? ': ' . $error->getMessage() : '') . PHP_EOL);
    exit(1);
}
