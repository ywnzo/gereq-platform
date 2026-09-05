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

    public function index(): Response
    {
        $values = [];
        foreach ($this->settings->definitions() as $key => $definition) {
            $values[$key] = $this->settings->get($key, $definition['default']);
        }

        return $this->page('admin/settings/index.html.twig', [
            'pageTitle' => $this->translator->trans('settings.title'),
            'definitions' => $this->settings->definitions(),
            'values' => $values,
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

        return $this->redirect('admin.settings.index');
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
