<?php

declare(strict_types=1);

namespace Modules\MoneyS3\Http;

use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Setting\SettingRecord;
use Gereq\Setting\Settings;
use Modules\MoneyS3\Application\MoneyS3Service;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class MoneyS3SettingsController extends Controller
{
    /** @var list<string> */
    private const KEYS = [
        'moneys3.enabled',
        'moneys3.api_url',
        'moneys3.app_id',
        'moneys3.client_id',
        'moneys3.client_secret',
        'moneys3.username',
        'moneys3.password',
        'moneys3.default_agenda_id',
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
        private readonly MoneyS3Service $moneyS3,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function edit(): Response
    {
        return $this->page('@moneys3/settings.html.twig', [
            'pageTitle' => $this->translator->trans('moneys3.settings.title'),
            'fields' => $this->fields(),
            'sections' => [
                'api' => [
                    'moneys3.enabled',
                    'moneys3.api_url',
                    'moneys3.app_id',
                    'moneys3.client_id',
                    'moneys3.client_secret',
                    'moneys3.username',
                    'moneys3.password',
                    'moneys3.default_agenda_id',
                ],
            ],
            'cache' => [
                'agendas' => count($this->moneyS3->cached('agendas')),
                'centres' => count($this->moneyS3->cached('centres')),
                'numericalSeries' => count($this->moneyS3->cached('numericalSeries')),
            ],
            'configured' => $this->moneyS3->client()->isConfigured(),
            'enabled' => $this->moneyS3->isEnabled(),
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
                $this->settings->set($key, $posted === '1', $definition['type'], $definition['module'] ?? 'moneys3');
                continue;
            }

            $value = is_scalar($posted) ? (string) $posted : '';
            if ($this->isSecret($key, $definition['type']) && $value === '') {
                continue;
            }

            $this->settings->set($key, $value, $definition['type'], $definition['module'] ?? 'moneys3');
        }

        $this->auditor->log('moneys3.settings.updated', 'settings', 'moneys3');
        $this->flash('success', $this->translator->trans('moneys3.settings.saved'));

        return $this->redirect('admin.moneys3.settings');
    }

    public function sync(Request $request): Response
    {
        $name = (string) $request->request->get('list', '');
        $result = match ($name) {
            'agendas' => $this->moneyS3->syncAgendas(),
            'centres' => $this->moneyS3->syncCentres(),
            'numericalSeries' => $this->moneyS3->syncNumericalSeries(),
            default => ['ok' => false, 'message' => 'moneys3.error.unknown_list'],
        };

        if ($result['ok'] ?? false) {
            $this->auditor->log('moneys3.list.synced', 'moneys3', $name);
            $this->flash('success', $this->translator->trans('moneys3.sync.success', [
                '%list%' => $this->translator->trans('moneys3.list.' . $name),
                '%count%' => (string) ($result['count'] ?? 0),
            ]));
        } else {
            $message = (string) ($result['message'] ?? 'moneys3.sync.failed');
            if (str_starts_with($message, 'moneys3.')) {
                $message = $this->translator->trans($message);
            }
            $this->flash('error', $message);
        }

        return $this->redirect('admin.moneys3.settings');
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
                'secret' => $this->isSecret($key, $definition['type']),
            ];
        }

        return $fields;
    }

    private function isSecret(string $key, string $type): bool
    {
        if ($type === SettingRecord::TYPE_ENCRYPTED) {
            return true;
        }

        $lower = strtolower($key);

        return str_contains($lower, 'secret') || str_contains($lower, 'password');
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
