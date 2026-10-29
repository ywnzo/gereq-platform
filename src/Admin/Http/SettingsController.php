<?php

declare(strict_types=1);

namespace Gereq\Admin\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Brand\Branding;
use Gereq\Brand\PaletteResolver;
use Gereq\Cache\StoreFrontCache;
use Gereq\Currency\Domain\Currency;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Http\StorefrontRebuild;
use Gereq\I18n\LocaleCatalog;
use Gereq\Media\Domain\MediaAsset;
use Gereq\Media\Domain\MediaFolder;
use Gereq\Module\CapabilityRegistry;
use Gereq\Setting\SettingRecord;
use Gereq\Setting\Settings;
use Gereq\Support\Json;
use Gereq\Validation\ValidationException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
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
        private readonly StoreFrontCache $storeFrontCache,
        private readonly StorefrontRebuild $storefrontRebuild,
        private readonly CapabilityRegistry $capabilities,
        private readonly EntityManagerInterface $em,
        private readonly string $basePath,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $user = $this->auth->user();
        $canManage = $user !== null && $user->can('settings.manage');
        $canManageCms = $user !== null && $user->can('cms.manage') && $this->capabilities->has('cms');
        if (!$canManage && !$canManageCms) {
            $this->abort(403);
        }
        $definitions = $canManage ? $this->globalSettings() : [];
        $values = [];
        foreach ($definitions as $key => $definition) {
            $values[$key] = $this->settings->get($key, $definition['default']);
        }
        if (isset($definitions['shop.default_currency'])) {
            $options = ['' => $this->translator->trans('settings.shop_currency.use_default')];
            /** @var list<Currency> $currencies */
            $currencies = $this->em->getRepository(Currency::class)->findBy([], ['code' => 'ASC']);
            foreach ($currencies as $currency) {
                $options[$currency->code()] = $currency->code() . ' — ' . $currency->name()
                    . ($currency->isActive() ? '' : ' (' . $this->translator->trans('settings.shop_currency.inactive') . ')');
            }
            $current = (string) $values['shop.default_currency'];
            if ($current !== '' && !isset($options[$current])) {
                $options[$current] = $current . ' (' . $this->translator->trans('settings.shop_currency.unavailable') . ')';
            }
            $definitions['shop.default_currency']['options'] = $options;
        }
        // One-time: CMS site title → Brand public site name.
        if (
            trim((string) ($values['brand.site_name'] ?? '')) === ''
            && !$this->settings->has('brand.site_name')
        ) {
            $legacyTitle = trim((string) $this->settings->get('cms.site_title', ''));
            if ($legacyTitle !== '' && $legacyTitle !== 'GEREQ Site') {
                $values['brand.site_name'] = $legacyTitle;
            }
        }
        $groups = $this->groupDefinitions($definitions);
        $storedPalette = $this->settings->get('brand.palette', []);
        $storedPalette = is_array($storedPalette) ? $storedPalette : [];
        $brand = $this->branding->resolve();
        $expandHex = static fn (string $color): string => strlen($color) === 4
            ? '#' . $color[1] . $color[1] . $color[2] . $color[2] . $color[3] . $color[3]
            : $color;
        $primary = $expandHex($brand['color_signal']);
        $ink = $expandHex($brand['color_ink']);
        $cream = $expandHex($brand['color_cream']);
        $channels = array_map(static function (int $offset) use ($ink): float {
            $channel = hexdec(substr($ink, $offset, 2)) / 255;
            return $channel <= 0.03928 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4;
        }, [1, 3, 5]);
        $paletteDefaults = [
            'primary' => $primary,
            'secondary' => $primary,
            'accent' => $primary,
            'light_background' => $cream,
            'light_surface' => $cream,
            'dark_background' => $ink,
            'dark_surface' => $ink,
            'storefront_mode' => 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2] < 0.45 ? 'dark' : 'light',
        ];
        $resolvedPalette = $this->branding->resolvedPalette() ?? (new PaletteResolver())->resolve($paletteDefaults);
        $paletteDefaults['light_header'] = $resolvedPalette['modes']['light']['header'];
        $paletteDefaults['dark_header'] = $resolvedPalette['modes']['dark']['header'];

        if ($canManageCms) {
            $provider = $this->capabilities->get('cms');
            if ($provider instanceof \Gereq\Cms\StorefrontSettingsProvider) {
                $groups['storefront'] ??= ['label' => 'settings.group.storefront', 'items' => []];
            } else {
                $canManageCms = false;
            }
        }
        $uploadSettings = null;
        if ($canManage && $this->capabilities->has('customer_uploads.settings')) {
            $uploadProvider = $this->capabilities->get('customer_uploads.settings');
            if ($uploadProvider instanceof \Gereq\Setting\CustomerUploadSettingsProvider) {
                $uploadSettings = $uploadProvider->fields();
                $groups['storefront'] ??= ['label' => 'settings.group.storefront', 'items' => []];
            }
        }
        $tab = (string) $request->query->get('tab', '');
        if ($tab === '' || !isset($groups[$tab])) {
            $tab = (string) (array_key_first($groups) ?? 'core');
        }

        $response = $this->page('admin/settings/index.html.twig', [
            'pageTitle' => $this->translator->trans('settings.title'),
            'definitions' => $definitions,
            'groups' => $groups,
            'values' => $values,
            'activeTab' => $tab,
            'cssVariables' => $this->branding->documentedVariables(),
            'adminPath' => \Gereq\Support\AdminPath::base(),
            'libraryImages' => $canManage ? $this->libraryImages() : [],
            'canManageSettings' => $canManage,
            'canManageCmsSettings' => $canManageCms,
            'uploadSettings' => $uploadSettings,
            'originEnvironment' => (string) env_value('FRONTEND_ORIGIN', ''),
            'brandPalette' => array_merge($paletteDefaults, $storedPalette),
            'brandAppearance' => $this->branding->adminAppearance(),
            'adminFont' => (string) $this->settings->get('brand.admin_font', 'default'),
            'adminFonts' => Branding::ADMIN_FONTS,
        ]);
        if ($this->formState()->hasErrors('currency.save')) {
            $this->forgetForm('currency.save');
        }

        return $response;
    }

    public function previewPalette(Request $request): JsonResponse
    {
        $user = $this->auth->user();
        if ($user === null || !$user->can('settings.manage')) {
            $this->abort(403);
        }

        $resolver = new PaletteResolver();
        try {
            $palette = $resolver->resolve($this->paletteInput($request));
            $appearance = $request->request->has('admin_motion')
                ? $this->validate($request, 'settings.brand_appearance')
                : null;
        } catch (ValidationException $exception) {
            return $this->jsonError('validation_failed', $exception->getMessage(), $exception->errors(), 422);
        }
        if ($this->formState()->hasErrors('settings.brand_appearance')
            && $this->formState()->old('appearance_present', null, 'settings.brand_appearance') === null) {
            $this->forgetForm('settings.brand_appearance');
        }
        $opacity = $appearance !== null ? (int) $appearance['admin_panel_opacity'] : $this->branding->adminAppearance()['panel_opacity'];
        $palette['css'] = [
            'light' => $resolver->cssTokens($palette['modes']['light'], $opacity),
            'dark' => $resolver->cssTokens($palette['modes']['dark'], $opacity),
        ];

        return $this->json($palette);
    }

    public function update(Request $request): Response
    {
        $user = $this->auth->user();
        $canManage = $user !== null && $user->can('settings.manage');
        $canManageCms = $user !== null && $user->can('cms.manage') && $this->capabilities->has('cms');
        if (!$canManage && !$canManageCms) {
            $this->abort(403);
        }
        $cmsPosted = $request->request->has('cms_settings_present');
        if (!$canManageCms && ($cmsPosted || $request->request->has('home_page_id') || $request->request->has('cms__custom_css_public'))) {
            $this->abort(403);
        }
        $cmsProvider = null;
        $cmsData = null;
        if ($cmsPosted) {
            $provider = $this->capabilities->get('cms');
            if (!$provider instanceof \Gereq\Cms\StorefrontSettingsProvider) {
                $this->abort(403);
            }
            $cmsData = $this->validate($request, 'cms.storefront.settings');
            $cmsData['home_page_id'] = trim((string) ($cmsData['home_page_id'] ?? ''));
            $cmsChanged = $cmsData['home_page_id'] !== (string) $this->settings->get('cms.home_page_id', '');
            if ($cmsChanged) {
                if (gereq()->make(\Gereq\Module\ModuleUpdater::class)->isUpdateAvailable('cms')) {
                    $this->abort(409, $this->translator->trans('admin.modules.gate.json', ['%module%' => 'CMS']));
                }
                $provider->validateStorefrontHomePage($cmsData['home_page_id']);
                $cmsProvider = $provider;
            }
        }

        $uploadProvider = null;
        $uploadData = null;
        if ($canManage && $request->request->has('uploads_settings_present') && $this->capabilities->has('customer_uploads.settings')) {
            $provider = $this->capabilities->get('customer_uploads.settings');
            if ($provider instanceof \Gereq\Setting\CustomerUploadSettingsProvider) {
                $uploadProvider = $provider;
                $uploadData = $this->validate($request, 'uploads.settings.save');
            }
        }

        $originOverride = '';
        $appearanceInput = $canManage && $request->request->has('appearance_present')
            ? $this->validate($request, 'settings.brand_appearance')
            : null;
        $paletteInput = null;
        $paletteSubmitted = $canManage && $request->request->has('palette_present')
            && ($request->request->get('settings_tab') === 'brand'
                || $request->request->get('palette_dirty') === '1'
                || $this->branding->resolvedPalette() !== null);
        if ($paletteSubmitted) {
            $paletteInput = $this->paletteInput($request);
        }
        if ($canManage) {
            $originInput = $this->validate($request, 'settings.origins');
            $originOverride = ($originInput['origin_mode'] ?? '') === 'custom'
                ? implode(',', \Gereq\Http\StorefrontOrigins::parseOverride((string) ($originInput['origins'] ?? '')) ?? [])
                : '';
        }
        if ($canManage && isset($this->settings->globalDefinitions()['shop.default_currency'])) {
            $currencyInput = $this->validate($request, 'settings.shop_currency');
            $code = (string) ($currencyInput['shop__default_currency'] ?? '');
            $previous = (string) $this->settings->get('shop.default_currency', 'CZK');
            if ($code !== '' && $code !== $previous && !$this->em->find(Currency::class, $code) instanceof Currency) {
                throw new ValidationException(
                    ['shop__default_currency' => $this->translator->trans('settings.shop_currency.invalid')],
                    [],
                    'settings.shop_currency',
                );
            }
        }
        if ($canManage) {
            foreach ($this->globalSettings() as $key => $definition) {
                if ($key === \Gereq\Http\StorefrontOrigins::SETTING) {
                    $this->settings->set($key, $originOverride, SettingRecord::TYPE_STRING);
                    continue;
                }
                // Brand assets are resolved from media box (file / library / clear / URL).
                if ($key === 'brand.logo_url' || $key === 'brand.favicon_url') {
                    continue;
                }
                // Legacy color values are retained for older clients; the palette syncs the primary below.
                if (in_array($key, ['brand.color_signal', 'brand.color_ink', 'brand.color_cream'], true)) {
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

                $oldValue = (string) $this->settings->get($key, '');
                $this->settings->set($key, $value, $definition['type'], $definition['module']);
                $syncBrand = (string) $request->request->get('settings_tab', '') === 'brand' || $value !== $oldValue;
                if ($key === 'brand.name' && $syncBrand) {
                    $this->settings->set('brand.site_name', $this->branding->resolve()['name'], SettingRecord::TYPE_STRING, 'core');
                    if ($value !== $oldValue && isset($this->settings->definitions()['shop.store_name'])) {
                        $this->settings->set('shop.store_name', $this->branding->resolve()['name'], SettingRecord::TYPE_STRING, 'shop');
                    }
                }
            }

            if ($paletteSubmitted) {
                $this->settings->set('brand.palette', $paletteInput, SettingRecord::TYPE_JSON, 'core');
                foreach (['brand.color_signal', 'brand.site_signal', 'brand.site_primary'] as $key) {
                    $this->settings->set($key, $paletteInput['primary'], SettingRecord::TYPE_STRING, 'core');
                }
            }
            if ($appearanceInput !== null) {
                $appearance = $this->branding->adminAppearance();
                $motion = $appearanceInput['admin_motion'] === '1';
                $opacity = (int) $appearanceInput['admin_panel_opacity'];
                if ($motion !== $appearance['motion']) {
                    $this->settings->set('brand.admin_motion', $motion, SettingRecord::TYPE_BOOLEAN, 'core');
                }
                if ($opacity !== $appearance['panel_opacity']) {
                    $this->settings->set('brand.admin_panel_opacity', $opacity, SettingRecord::TYPE_INTEGER, 'core');
                }
                $font = $appearanceInput['admin_font'] ?? null;
                if (is_string($font) && $font !== '' && $font !== $this->settings->get('brand.admin_font', 'default')) {
                    $this->settings->set('brand.admin_font', $font, SettingRecord::TYPE_STRING, 'core');
                }
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
        }
        if ($uploadProvider !== null && $uploadData !== null) {
            $uploadProvider->save($uploadData);
            $this->forgetForm('uploads.settings.save');
        }
        if ($cmsProvider !== null && $cmsData !== null) {
            $cmsProvider->saveStorefrontSettings(
                (string) $cmsData['home_page_id'],
                (string) $this->settings->get('cms.custom_css_public', ''),
            );
            $this->auditor->log('cms.storefront_settings.updated', 'settings', null);
        }

        $tab = (string) $request->request->get('settings_tab', '');
        // Driver/TTL/enable changes must not leave stale public payloads around.
        if ($canManage && $tab === 'storefront') {
            $this->storeFrontCache->flush();
        }

        $this->auditor->log('settings.updated', 'settings', null);
        if ($appearanceInput !== null) {
            $this->forgetForm('settings.brand_appearance');
        }
        if ($paletteSubmitted) {
            $this->forgetForm('settings.palette');
        }
        $this->flash('success', $this->translator->trans('settings.saved'));

        $params = $tab !== '' ? ['tab' => $tab] : [];

        return $this->redirect('admin.settings.index', $params);
    }

    public function rebuildStorefront(Request $request): Response
    {
        // Prefer values from the open settings form so path can be tried before Save.
        $postedPath = trim((string) $request->request->get('storefront__path', ''));
        $postedCommand = trim((string) $request->request->get('storefront__build_command', ''));
        if ($postedPath !== '') {
            $this->settings->set(
                StorefrontRebuild::SETTING_PATH,
                $postedPath,
                SettingRecord::TYPE_STRING,
                'core',
            );
        }
        if ($postedCommand !== '') {
            $this->settings->set(
                StorefrontRebuild::SETTING_COMMAND,
                $postedCommand,
                SettingRecord::TYPE_STRING,
                'core',
            );
        }

        $result = $this->storefrontRebuild->run(
            $postedPath !== '' ? $postedPath : null,
            $postedCommand !== '' ? $postedCommand : null,
        );
        $this->auditor->log(
            $result['ok'] ? 'storefront.rebuild.ok' : 'storefront.rebuild.failed',
            'settings',
            null,
            null,
            [
                'path' => $result['path'],
                'remote' => $result['remote'],
                'exit_code' => $result['exit_code'],
            ],
        );

        if ($result['ok']) {
            $this->storeFrontCache->flush();
            $msg = $this->translator->trans('settings.storefront.rebuild.ok', [
                '%path%' => $result['path'],
            ]);
            if ($result['output'] !== '') {
                $msg .= ' — ' . $this->shortOutput($result['output']);
            }
            $this->flash('success', $msg);
        } else {
            $detail = $result['error'] ?? $this->translator->trans('settings.storefront.rebuild.failed');
            if ($result['output'] !== '') {
                $detail .= ' — ' . $this->shortOutput($result['output']);
            }
            $this->flash('error', $detail);
        }

        return $this->redirect('admin.settings.index', ['tab' => 'storefront']);
    }

    private function shortOutput(string $output): string
    {
        $line = trim(preg_replace('/\s+/', ' ', $output) ?? $output);
        if (mb_strlen($line) > 280) {
            return mb_substr($line, -280);
        }

        return $line;
    }

    /** @return array<string, string> */
    private function paletteInput(Request $request): array
    {
        $validated = $this->validate($request, 'settings.palette');
        $input = [];
        foreach (['primary', 'secondary', 'accent', 'light_background', 'light_surface', 'dark_background', 'dark_surface', 'storefront_mode'] as $key) {
            $input[$key] = (string) $validated['palette_' . $key];
        }
        foreach (['light_header', 'dark_header'] as $key) {
            if (is_string($validated['palette_' . $key] ?? null) && $validated['palette_' . $key] !== '') {
                $input[$key] = $validated['palette_' . $key];
            }
        }

        return $input;
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

    /** @return array<string, array{type: string, label: string, module: ?string, default: mixed, options: array<string, string>, global: bool}> */
    private function globalSettings(): array
    {
        $definitions = $this->settings->globalDefinitions();
        if (!$this->capabilities->has('payments') || $this->capabilities->has('payments.settings')) {
            foreach ([
                'payments.comgate_merchant', 'payments.comgate_secret', 'payments.comgate_test',
                'payments.gopay_goid', 'payments.gopay_client_id', 'payments.gopay_client_secret', 'payments.gopay_sandbox',
                'payments.stripe_secret', 'payments.stripe_webhook_secret',
            ] as $key) {
                unset($definitions[$key]);
            }
        }

        return $definitions;
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
                $key === 'shop.default_currency' => 'core',
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
