<?php

declare(strict_types=1);

namespace Modules\iDoklad\Http;

use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Setting\SettingRecord;
use Gereq\Setting\Settings;
use Modules\iDoklad\Application\iDokladService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class iDokladSettingsController extends Controller
{
    /** @var list<string> */
    private const KEYS = [
        'idoklad.enabled',
        'idoklad.client_id',
        'idoklad.client_secret',
    ];

    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly Authenticator $auth,
        private readonly Menu $menu,
        private readonly CommandPalette $palette,
        private readonly Settings $settings,
        private readonly Auditor $auditor,
        private readonly TranslatorInterface $translator,
        private readonly iDokladService $iDoklad,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function edit(): Response
    {
        return $this->page('@idoklad/settings.html.twig', [
            'pageTitle' => $this->translator->trans('idoklad.settings.title'),
            'fields' => $this->fields(),
            'sections' => [
                'api' => [
                    'idoklad.enabled',
                    'idoklad.client_id',
                    'idoklad.client_secret',
                ],
            ],
            'lists' => [
                'countries' => count($this->iDoklad->cachedList('countries')),
                'currencies' => count($this->iDoklad->cachedList('currencies')),
                'paymentOptions' => count($this->iDoklad->cachedList('paymentOptions')),
                'cashRegisters' => count($this->iDoklad->cachedList('cashRegisters')),
                'numericSequences' => count($this->iDoklad->cachedList('numericSequences')),
            ],
            'configured' => $this->iDoklad->api()->isConfigured(),
            'enabled' => $this->iDoklad->isEnabled(),
        ]);
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

            if ($definition['type'] === SettingRecord::TYPE_BOOLEAN) {
                $this->settings->set($key, $posted === '1', $definition['type'], $definition['module'] ?? 'idoklad');
                continue;
            }

            $value = is_scalar($posted) ? (string) $posted : '';
            if ($this->isSecret($definition['type']) && $value === '') {
                continue;
            }

            $this->settings->set($key, $value, $definition['type'], $definition['module'] ?? 'idoklad');
        }

        $this->auditor->log('idoklad.settings.updated', 'settings', 'idoklad');
        $this->flash('success', $this->translator->trans('idoklad.settings.saved'));

        return $this->redirect('admin.idoklad.settings');
    }

    public function sync(Request $request): Response
    {
        /** @var list<string> $allowed */
        $allowed = ['countries', 'currencies', 'paymentOptions', 'cashRegisters', 'numericSequences'];
        $name = trim((string) $request->request->get('list', ''));
        $targets = $name === '' || $name === 'all'
            ? $allowed
            : [$name];

        foreach ($targets as $list) {
            if (!in_array($list, $allowed, true)) {
                $this->flash('error', $this->translator->trans('idoklad.error.unknown_list'));

                return $this->redirect('admin.idoklad.settings');
            }
        }

        if (!$this->iDoklad->api()->isConfigured()) {
            $this->flash('error', $this->translator->trans('idoklad.error.not_configured'));

            return $this->redirect('admin.idoklad.settings');
        }

        $ok = 0;
        $failed = [];
        $totalItems = 0;
        foreach ($targets as $list) {
            $response = $this->iDoklad->syncList($list);
            if ($response['isSucceed'] ?? false) {
                ++$ok;
                $totalItems += count($response['items'] ?? []);
                $this->auditor->log('idoklad.list.synced', 'idoklad', $list);
                continue;
            }

            $message = (string) ($response['message'] ?? 'idoklad.sync.failed');
            if (str_starts_with($message, 'idoklad.')) {
                $message = $this->translator->trans($message);
            }
            $failed[] = $this->translator->trans('idoklad.list.' . $list)
                . ($message !== '' ? ': ' . $message : '');
        }

        if ($failed === [] && count($targets) > 1) {
            $this->flash('success', $this->translator->trans('idoklad.sync.all_success', [
                '%count%' => (string) $ok,
                '%items%' => (string) $totalItems,
            ]));
        } elseif ($failed === [] && isset($targets[0])) {
            $this->flash('success', $this->translator->trans('idoklad.sync.success', [
                '%list%' => $this->translator->trans('idoklad.list.' . $targets[0]),
                '%count%' => (string) $totalItems,
            ]));
        } elseif ($ok > 0) {
            $this->flash('error', $this->translator->trans('idoklad.sync.partial', [
                '%ok%' => (string) $ok,
                '%failed%' => implode(', ', $failed),
            ]));
        } else {
            $this->flash('error', $failed[0] ?? $this->translator->trans('idoklad.sync.failed'));
        }

        return $this->redirect('admin.idoklad.settings');
    }

    /** @return array<string, array<string, mixed>> */
    private function fields(): array
    {
        $definitions = $this->settings->definitions();
        $fields = [];
        foreach (self::KEYS as $key) {
            if (!isset($definitions[$key])) {
                continue;
            }
            $definition = $definitions[$key];
            $fields[$key] = [
                'key' => $key,
                'field' => str_replace('.', '__', $key),
                'definition' => $definition,
                'value' => $this->settings->get($key, $definition['default'] ?? null),
                'secret' => $this->isSecret($definition['type']),
            ];
        }

        return $fields;
    }

    private function isSecret(string $type): bool
    {
        return $type === SettingRecord::TYPE_ENCRYPTED;
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
