<?php

declare(strict_types=1);

namespace Modules\Translate\Http;

use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Setting\SettingRecord;
use Gereq\Setting\Settings;
use Modules\Translate\Application\TranslateDebug;
use Modules\Translate\Application\TranslateService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class SettingsController extends Controller
{
    /** @var list<string> */
    private const KEYS = [
        'translate.ms_api_key',
        'translate.ms_region',
        'translate.ms_endpoint',
        'translate.source_locale',
        'translate.target_locale',
    ];

    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly Authenticator $auth,
        private readonly Menu $menu,
        private readonly CommandPalette $palette,
        private readonly Settings $settings,
        private readonly TranslatorInterface $translator,
        private readonly TranslateService $translate,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function edit(): Response
    {
        return $this->settingsPage();
    }

    public function update(Request $request): Response
    {
        $definitions = $this->settings->definitions();
        foreach (self::KEYS as $key) {
            if (!isset($definitions[$key])) {
                continue;
            }
            $definition = $definitions[$key];
            $posted = $request->request->get(str_replace('.', '__', $key));
            $value = is_scalar($posted) ? (string) $posted : '';
            if ($definition['type'] === SettingRecord::TYPE_ENCRYPTED && $value === '') {
                continue;
            }
            $this->settings->set($key, $value, $definition['type'], $definition['module'] ?? 'translate');
        }
        $this->flash('success', $this->translator->trans('translate.flash.saved'));

        return $this->redirect('admin.translate.settings');
    }

    public function test(Request $request): Response
    {
        $input = trim((string) $request->request->get('sample', ''));
        bdump([
            'input' => $input,
            'configured' => $this->translate->isConfigured(),
            'apiKeySet' => trim((string) $this->settings->get('translate.ms_api_key', '')) !== '',
        ], 'translate.settings.test.start');

        $result = null;
        $error = null;
        try {
            if ($input === '') {
                throw new \InvalidArgumentException($this->translator->trans('translate.error.empty_sample'));
            }
            $result = $this->translate->translate($input);
            bdump($result, 'translate.settings.test.ok');
            $this->flash('success', $this->translator->trans('translate.flash.test_ok'));
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            bdump([
                'class' => $e::class,
                'message' => $error,
                'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 12),
            ], 'translate.settings.test.error');
            $this->flash('error', $error);
        }

        return $this->settingsPage([
            'testResult' => $result,
            'testInput' => $input,
            'testError' => $error,
        ]);
    }

    public function ping(): Response
    {
        $ping = $this->translate->ping();
        bdump($ping, 'translate.settings.ping');
        if ($ping['ok']) {
            $this->flash('success', $ping['message']);
        } else {
            $this->flash('error', $ping['message']);
        }

        return $this->settingsPage(['pingResult' => $ping]);
    }

    public function clearCache(): Response
    {
        $n = $this->translate->clearCache();
        $this->flash('success', $this->translator->trans('translate.flash.cache_cleared', ['%count%' => (string) $n]));

        return $this->redirect('admin.translate.settings');
    }

    /** @param array<string, mixed> $extra */
    private function settingsPage(array $extra = []): Response
    {
        return $this->page('@translate/settings.html.twig', array_merge([
            'pageTitle' => $this->translator->trans('translate.settings.title'),
            'configured' => $this->translate->isConfigured(),
            'cacheCount' => $this->translate->cacheCount(),
            'region' => (string) $this->settings->get('translate.ms_region', ''),
            'endpoint' => (string) $this->settings->get('translate.ms_endpoint', ''),
            'sourceLocale' => $this->translate->sourceLocale(),
            'targetLocale' => $this->translate->targetLocale(),
            'apiKeySet' => trim((string) $this->settings->get('translate.ms_api_key', '')) !== '',
            'testResult' => null,
            'testInput' => '',
            'pingResult' => null,
            'debugLog' => TranslateDebug::tail(50),
        ], $extra));
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
