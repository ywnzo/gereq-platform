<?php

declare(strict_types=1);

namespace Gereq\Admin\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Dashboard\Dashboard;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Brand\Branding;
use Gereq\Cache\StoreFrontCache;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\I18n\LocaleCatalog;
use Gereq\Media\Domain\MediaAsset;
use Gereq\Media\Domain\MediaFolder;
use Gereq\Module\CapabilityRegistry;
use Gereq\Setting\SettingRecord;
use Gereq\Setting\Settings;
use Gereq\Support\Json;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class SettingsController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly Authenticator $auth,
        private readonly Menu $menu,
        private readonly CommandPalette $palette,
        private readonly Settings $settings,
        private readonly Auditor $auditor,
        private readonly LocaleCatalog $locales,
        private readonly TranslatorInterface $translator,
        private readonly Branding $branding,
        private readonly Dashboard $dashboard,
        private readonly StoreFrontCache $storeFrontCache,
        private readonly CapabilityRegistry $capabilities,
        private readonly EntityManagerInterface $em,
        private readonly string $basePath,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $definitions = $this->settings->globalDefinitions();
        $values = [];
        foreach ($definitions as $key => $definition) {
            $values[$key] = $this->settings->get($key, $definition['default']);
        }
        // Migrate legacy admin CSS into brand.custom_css when not yet stored.
        if (!$this->settings->has('brand.custom_css')) {
            $fromCmsAdmin = trim((string) $this->settings->get('cms.custom_css_admin', ''));
            $fromLegacy = trim((string) $this->settings->get('cms.custom_css', ''));
            if ($fromCmsAdmin !== '') {
                $values['brand.custom_css'] = $fromCmsAdmin;
            } elseif ($fromLegacy !== '') {
                $values['brand.custom_css'] = $fromLegacy;
            }
        }
        if (
            !$this->settings->has('cms.custom_css_public')
            && trim((string) ($values['cms.custom_css_public'] ?? '')) === ''
        ) {
            $legacy = trim((string) $this->settings->get('cms.custom_css', ''));
            if ($legacy !== '') {
                $values['cms.custom_css_public'] = $legacy;
            }
        }
        $groups = $this->groupDefinitions($definitions);
        $tab = (string) $request->query->get('tab', '');
        if ($tab === '' || !isset($groups[$tab])) {
            $tab = (string) (array_key_first($groups) ?? 'core');
        }

        return $this->page('admin/settings/index.html.twig', [
            'pageTitle' => $this->translator->trans('settings.title'),
            'definitions' => $definitions,
            'groups' => $groups,
            'values' => $values,
            'activeTab' => $tab,
            'cssVariables' => $this->branding->documentedVariables(),
            'dashboardCatalog' => $this->dashboard->catalog(null),
            'adminPath' => \Gereq\Support\AdminPath::base(),
            'libraryImages' => $this->libraryImages(),
        ]);
    }

    public function update(Request $request): Response
    {
        foreach ($this->settings->globalDefinitions() as $key => $definition) {
            if ($key === Dashboard::LAYOUT_SETTING) {
                $posted = $request->request->get(str_replace('.', '__', $key));
                $items = [];
                if (is_string($posted) && $posted !== '') {
                    $decoded = Json::decode($posted);
                    $items = is_array($decoded) ? $decoded : [];
                }
                $this->dashboard->saveLayout($items);
                continue;
            }

            // Brand assets are resolved from media box (file / library / clear / URL).
            if ($key === 'brand.logo_url' || $key === 'brand.favicon_url') {
                continue;
            }

            $posted = $request->request->get(str_replace('.', '__', $key));
            if ($definition['type'] === 'boolean') {
                $this->settings->set($key, $posted === '1', $definition['type'], $definition['module']);
                continue;
            }

            if ($definition['type'] === SettingRecord::TYPE_JSON) {
                $decoded = is_string($posted) && $posted !== '' ? Json::decode($posted) : [];
                $this->settings->set($key, is_array($decoded) ? $decoded : [], $definition['type'], $definition['module']);
                continue;
            }

            $value = (string) $posted;
            if ($key === 'app.locale' && !$this->locales->isSupported($value)) {
                continue;
            }

            $this->settings->set($key, $value, $definition['type'], $definition['module']);
        }

        $this->resolveBrandAsset(
            $request,
            'logo',
            'brand.logo_url',
            'brand_logo_file',
            'brand_logo_media_asset_id',
            'clear_brand_logo',
            'brand__logo_url',
        );
        $this->resolveBrandAsset(
            $request,
            'favicon',
            'brand.favicon_url',
            'brand_favicon_file',
            'brand_favicon_media_asset_id',
            'clear_brand_favicon',
            'brand__favicon_url',
        );

        $tab = (string) $request->request->get('settings_tab', '');
        // Driver/TTL/enable changes must not leave stale public payloads around.
        if ($tab === 'storefront') {
            $this->storeFrontCache->flush();
        }

        $this->auditor->log('settings.updated', 'settings', null);
        $this->flash('success', $this->translator->trans('settings.saved'));

        $params = $tab !== '' ? ['tab' => $tab] : [];

        return $this->redirect('admin.settings.index', $params);
    }

    private function resolveBrandAsset(
        Request $request,
        string $basename,
        string $settingKey,
        string $fileKey,
        string $libraryKey,
        string $clearKey,
        string $urlField,
    ): void {
        $clear = $request->request->get($clearKey);
        if ($clear === '1' || $clear === 'on') {
            $this->settings->set($settingKey, '', SettingRecord::TYPE_STRING, 'core');
            $this->deleteBrandFiles($basename);

            return;
        }

        $uploaded = $request->files->get($fileKey);
        if ($uploaded instanceof UploadedFile) {
            $this->storeBrandUpload($uploaded, $settingKey, $basename);

            return;
        }

        $mediaId = trim((string) $request->request->get($libraryKey, ''));
        if ($mediaId !== '') {
            $this->storeBrandFromMedia($mediaId, $settingKey, $basename);

            return;
        }

        $posted = trim((string) $request->request->get($urlField, ''));
        $this->settings->set($settingKey, $this->normalizeBrandUrl($posted), SettingRecord::TYPE_STRING, 'core');
    }

    private function storeBrandUpload(UploadedFile $uploaded, string $settingKey, string $basename): void
    {
        if (!$uploaded->isValid()) {
            $this->flash('error', $this->translator->trans('settings.brand.upload_failed') . ' (' . $uploaded->getErrorMessage() . ')');

            return;
        }

        $mime = strtolower((string) ($uploaded->getMimeType() ?? ''));
        $originalExt = strtolower((string) pathinfo($uploaded->getClientOriginalName(), PATHINFO_EXTENSION));
        // SVG is rejected: served as image/svg+xml it can execute script when opened directly.
        if ($originalExt === 'svg' || str_contains($mime, 'svg')) {
            $this->flash('error', $this->translator->trans('settings.brand.upload_svg_blocked'));

            return;
        }
        $allowed = [
            'image/png', 'image/jpeg', 'image/webp', 'image/gif',
            'image/x-icon', 'image/vnd.microsoft.icon',
        ];
        if (!in_array($mime, $allowed, true)) {
            $this->flash('error', $this->translator->trans('settings.brand.upload_image_only'));

            return;
        }

        $ext = strtolower((string) ($uploaded->guessExtension() ?: $originalExt ?: 'png'));
        if ($ext === 'jpeg') {
            $ext = 'jpg';
        }
        if (!in_array($ext, ['png', 'jpg', 'webp', 'gif', 'ico'], true)) {
            $this->flash('error', $this->translator->trans('settings.brand.upload_image_only'));

            return;
        }

        $dir = $this->brandUploadDir();
        if ($dir === null) {
            return;
        }

        $this->deleteBrandFiles($basename);

        $filename = $basename . '.' . $ext;
        try {
            $uploaded->move($dir, $filename);
        } catch (\Throwable $e) {
            $this->flash('error', $this->translator->trans('settings.brand.upload_failed') . ': ' . $e->getMessage());

            return;
        }

        if (!is_file($dir . '/' . $filename)) {
            $this->flash('error', $this->translator->trans('settings.brand.upload_failed'));

            return;
        }

        @chmod($dir . '/' . $filename, 0664);
        $url = \Gereq\Support\UrlPrefix::path('/brand/asset/' . $filename) . '?v=' . time();
        $this->settings->set($settingKey, $url, SettingRecord::TYPE_STRING, 'core');
        $this->flash('success', $this->translator->trans('settings.brand.upload_ok', ['%file%' => $filename]));
    }

    private function storeBrandFromMedia(string $mediaId, string $settingKey, string $basename): void
    {
        if (!$this->mediaAvailable()) {
            $this->flash('error', $this->translator->trans('settings.brand.media_required'));

            return;
        }

        $asset = $this->em->find(MediaAsset::class, $mediaId);
        if (!$asset instanceof MediaAsset || !$asset->isImage()) {
            $this->flash('error', $this->translator->trans('settings.brand.media_not_found'));

            return;
        }

        $mime = strtolower($asset->mime());
        if (str_contains($mime, 'svg')) {
            $this->flash('error', $this->translator->trans('settings.brand.upload_svg_blocked'));

            return;
        }

        $ext = $this->mediaExt($mime, $asset->path());
        if ($ext === 'jpeg') {
            $ext = 'jpg';
        }
        if (!in_array($ext, ['png', 'jpg', 'webp', 'gif', 'ico'], true)) {
            $this->flash('error', $this->translator->trans('settings.brand.upload_image_only'));

            return;
        }

        $source = $this->absoluteMediaPath($asset->path());
        if ($source === null || !is_file($source)) {
            $this->flash('error', $this->translator->trans('settings.brand.media_not_found'));

            return;
        }

        $dir = $this->brandUploadDir();
        if ($dir === null) {
            return;
        }

        $this->deleteBrandFiles($basename);
        $filename = $basename . '.' . $ext;
        if (!@copy($source, $dir . '/' . $filename)) {
            $this->flash('error', $this->translator->trans('settings.brand.upload_failed'));

            return;
        }

        @chmod($dir . '/' . $filename, 0664);
        $url = \Gereq\Support\UrlPrefix::path('/brand/asset/' . $filename) . '?v=' . time();
        $this->settings->set($settingKey, $url, SettingRecord::TYPE_STRING, 'core');
        $this->flash('success', $this->translator->trans('settings.brand.upload_ok', ['%file%' => $filename]));
    }

    private function brandUploadDir(): ?string
    {
        $dir = $this->basePath . '/storage/uploads/brand';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            $this->flash('error', $this->translator->trans('settings.brand.upload_failed'));

            return null;
        }

        return $dir;
    }

    private function deleteBrandFiles(string $basename): void
    {
        $dir = $this->basePath . '/storage/uploads/brand';
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/' . $basename . '.*') ?: [] as $old) {
            @unlink($old);
        }
    }

    private function normalizeBrandUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $path = parse_url($url, PHP_URL_PATH);
        if (is_string($path) && (str_starts_with($path, '/media/') || str_starts_with($path, '/brand/'))) {
            return $path;
        }
        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        return str_starts_with($url, '/') ? $url : '/' . $url;
    }

    private function mediaAvailable(): bool
    {
        return $this->capabilities->has('media') && class_exists(MediaAsset::class);
    }

    /**
     * @return list<array{value: string, label: string, thumb: string, mime: string, ext: string, width: ?int, height: ?int, created: int, folder_id: string, folder: string}>
     */
    private function libraryImages(): array
    {
        if (!$this->mediaAvailable()) {
            return [];
        }

        $provider = $this->capabilities->get('media');
        if (!is_object($provider) || !method_exists($provider, 'images')) {
            return [];
        }

        $folderNames = $this->mediaFolderNames();
        $options = [];
        foreach ($provider->images(250) as $asset) {
            if (!$asset instanceof MediaAsset) {
                continue;
            }
            $folderId = $asset->folderId() ?? '';
            $mime = $asset->mime();
            $options[] = [
                'value' => $asset->id(),
                'label' => $asset->title(),
                'thumb' => '/media/' . $asset->id() . '/file',
                'mime' => $mime,
                'ext' => $this->mediaExt($mime, $asset->path()),
                'width' => $asset->width(),
                'height' => $asset->height(),
                'created' => $asset->createdAt()->getTimestamp(),
                'folder_id' => $folderId,
                'folder' => $folderId !== '' ? ($folderNames[$folderId] ?? '') : '',
            ];
        }

        return $options;
    }

    /** @return array<string, string> */
    private function mediaFolderNames(): array
    {
        if (!class_exists(MediaFolder::class)) {
            return [];
        }

        $out = [];
        foreach ($this->em->getRepository(MediaFolder::class)->findAll() as $folder) {
            if ($folder instanceof MediaFolder) {
                $out[$folder->id()] = $folder->name();
            }
        }

        return $out;
    }

    private function mediaExt(string $mime, string $path): string
    {
        $map = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'image/avif' => 'avif',
            'image/svg+xml' => 'svg',
            'image/x-icon' => 'ico',
            'image/vnd.microsoft.icon' => 'ico',
        ];
        if (isset($map[$mime])) {
            return $map[$mime];
        }

        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        if ($ext !== '') {
            return $ext;
        }

        $pos = strrpos($mime, '/');

        return $pos === false ? '' : substr($mime, $pos + 1);
    }

    private function absoluteMediaPath(string $relative): ?string
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        if ($relative === '' || str_contains($relative, '..')) {
            return null;
        }
        $root = realpath($this->basePath . '/storage/uploads');
        if ($root === false) {
            return null;
        }
        $absolute = $root . '/' . $relative;
        $real = realpath($absolute);
        if ($real === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            return is_file($absolute) ? $absolute : null;
        }

        return $real;
    }

    /**
     * @param array<string, array{type: string, label: string, module: ?string, default: mixed, options: array<string, string>}> $definitions
     * @return array<string, array{label: string, items: list<array{key: string, definition: array<string, mixed>, secret: bool}>}>
     */
    private function groupDefinitions(array $definitions): array
    {
        $order = ['core', 'storefront', 'dashboard', 'brand', 'orders', 'shop', 'payments', 'cms', 'customer_uploads', 'reviews', 'calendar', 'fleet', 'other'];
        $groups = [];
        foreach ($definitions as $key => $definition) {
            $module = $definition['module'] ?? null;
            $groupId = match (true) {
                str_starts_with($key, 'admin.dashboard.') => 'dashboard',
                str_starts_with($key, 'storefront.') => 'storefront',
                $module === null || in_array($module, ['core', 'app', 'admin'], true) => str_starts_with($key, 'brand.') ? 'brand' : 'core',
                $module === 'orders' => 'orders',
                $module === 'shop' => 'shop',
                $module === 'payments' => 'payments',
                $module === 'cms' => 'cms',
                $module === 'customer_uploads' => 'customer_uploads',
                $module === 'reviews' => 'reviews',
                $module === 'calendar' || str_starts_with($key, 'calendar.') => 'calendar',
                $module === 'fleet' || str_starts_with($key, 'fleet.') => 'fleet',
                default => 'other',
            };
            if (!isset($groups[$groupId])) {
                $groups[$groupId] = [
                    'label' => 'settings.group.' . $groupId,
                    'items' => [],
                ];
            }
            $groups[$groupId]['items'][] = [
                'key' => $key,
                'definition' => $definition,
                'secret' => $this->isSecretKey($key),
            ];
        }

        $sorted = [];
        foreach ($order as $id) {
            if (isset($groups[$id])) {
                $sorted[$id] = $groups[$id];
                unset($groups[$id]);
            }
        }
        foreach ($groups as $id => $group) {
            $sorted[$id] = $group;
        }

        return $sorted;
    }

    private function isSecretKey(string $key): bool
    {
        $lower = strtolower($key);

        return str_contains($lower, 'password')
            || str_contains($lower, 'secret')
            || str_contains($lower, 'token')
            || str_contains($lower, 'api_key');
    }

    /** @param array<string, mixed> $data */
    private function page(string $view, array $data): Response
    {
        $user = $this->auth->user();

        return $this->render($view, array_merge($data, [
            'menu' => $this->menu->tree($user, $this->router, $this->request()),
            'commands' => $this->palette->all($user),
            'user' => $user,
        ]));
    }
}
