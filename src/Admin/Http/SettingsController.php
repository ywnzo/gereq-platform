<?php

declare(strict_types=1);

namespace Gereq\Admin\Http;

use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\I18n\LocaleCatalog;
use Gereq\Setting\Settings;
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
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $definitions = $this->settings->definitions();
        $values = [];
        foreach ($definitions as $key => $definition) {
            $values[$key] = $this->settings->get($key, $definition['default']);
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
        ]);
    }

    public function update(Request $request): Response
    {
        foreach ($this->settings->definitions() as $key => $definition) {
            $posted = $request->request->get(str_replace('.', '__', $key));
            if ($definition['type'] === 'boolean') {
                $this->settings->set($key, $posted === '1', $definition['type'], $definition['module']);
                continue;
            }

            $value = (string) $posted;
            if ($key === 'app.locale' && !$this->locales->isSupported($value)) {
                continue;
            }

            $this->settings->set($key, $value, $definition['type'], $definition['module']);
        }

        $this->auditor->log('settings.updated', 'settings', null);
        $this->flash('success', $this->translator->trans('settings.saved'));

        $tab = (string) $request->request->get('settings_tab', '');
        $params = $tab !== '' ? ['tab' => $tab] : [];

        return $this->redirect('admin.settings.index', $params);
    }

    /**
     * @param array<string, array{type: string, label: string, module: ?string, default: mixed, options: array<string, string>}> $definitions
     * @return array<string, array{label: string, items: list<array{key: string, definition: array<string, mixed>, secret: bool}>}>
     */
    private function groupDefinitions(array $definitions): array
    {
        $order = ['core', 'orders', 'shop', 'payments', 'cms', 'customer_uploads', 'other'];
        $groups = [];
        foreach ($definitions as $key => $definition) {
            $module = $definition['module'] ?? null;
            $groupId = match (true) {
                $module === null || in_array($module, ['core', 'app', 'admin'], true) => 'core',
                $module === 'orders' => 'orders',
                $module === 'shop' => 'shop',
                $module === 'payments' => 'payments',
                $module === 'cms' => 'cms',
                $module === 'customer_uploads' => 'customer_uploads',
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
