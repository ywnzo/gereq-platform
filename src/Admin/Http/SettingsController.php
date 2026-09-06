<?php

declare(strict_types=1);

namespace Gereq\Admin\Http;

use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Dashboard\Dashboard;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Brand\Branding;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\I18n\LocaleCatalog;
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
        // Migrate legacy shared CSS into split fields when empty.
        $legacy = trim((string) ($values['cms.custom_css'] ?? ''));
        if ($legacy !== '') {
            if (trim((string) ($values['cms.custom_css_admin'] ?? '')) === '') {
                $values['cms.custom_css_admin'] = $legacy;
            }
            if (trim((string) ($values['cms.custom_css_public'] ?? '')) === '') {
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

        $this->storeBrandUpload($request, 'brand_logo_file', 'brand.logo_url', 'logo');
        $this->storeBrandUpload($request, 'brand_favicon_file', 'brand.favicon_url', 'favicon');

        $this->auditor->log('settings.updated', 'settings', null);
        $this->flash('success', $this->translator->trans('settings.saved'));

        $tab = (string) $request->request->get('settings_tab', '');
        $params = $tab !== '' ? ['tab' => $tab] : [];

        return $this->redirect('admin.settings.index', $params);
    }

    private function storeBrandUpload(Request $request, string $fileKey, string $settingKey, string $basename): void
    {
        $uploaded = $request->files->get($fileKey);
        if (!$uploaded instanceof UploadedFile) {
            return;
        }
        if (!$uploaded->isValid()) {
            $this->flash('error', $this->translator->trans('settings.brand.upload_failed') . ' (' . $uploaded->getErrorMessage() . ')');

            return;
        }

        $mime = (string) ($uploaded->getMimeType() ?? '');
        $allowed = [
            'image/png', 'image/jpeg', 'image/webp', 'image/gif', 'image/svg+xml',
            'image/x-icon', 'image/vnd.microsoft.icon',
        ];
        if (!in_array($mime, $allowed, true) && !str_starts_with($mime, 'image/')) {
            $this->flash('error', $this->translator->trans('settings.brand.upload_image_only'));

            return;
        }

        $ext = strtolower((string) ($uploaded->guessExtension() ?: pathinfo($uploaded->getClientOriginalName(), PATHINFO_EXTENSION) ?: 'png'));
        if ($ext === 'jpeg') {
            $ext = 'jpg';
        }
        if (!in_array($ext, ['png', 'jpg', 'webp', 'svg', 'gif', 'ico'], true)) {
            $ext = 'png';
        }

        $dir = $this->basePath . '/storage/uploads/brand';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            $this->flash('error', $this->translator->trans('settings.brand.upload_failed'));

            return;
        }

        foreach (glob($dir . '/' . $basename . '.*') ?: [] as $old) {
            @unlink($old);
        }

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
        $url = '/brand/asset/' . $filename . '?v=' . time();
        $this->settings->set($settingKey, $url, SettingRecord::TYPE_STRING, 'core');
        $this->flash('success', $this->translator->trans('settings.brand.upload_ok', ['%file%' => $filename]));
    }

    /**
     * @param array<string, array{type: string, label: string, module: ?string, default: mixed, options: array<string, string>}> $definitions
     * @return array<string, array{label: string, items: list<array{key: string, definition: array<string, mixed>, secret: bool}>}>
     */
    private function groupDefinitions(array $definitions): array
    {
        $order = ['core', 'dashboard', 'brand', 'orders', 'shop', 'payments', 'cms', 'customer_uploads', 'reviews', 'calendar', 'fleet', 'other'];
        $groups = [];
        foreach ($definitions as $key => $definition) {
            $module = $definition['module'] ?? null;
            $groupId = match (true) {
                str_starts_with($key, 'admin.dashboard.') => 'dashboard',
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
