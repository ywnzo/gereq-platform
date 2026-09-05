<?php

declare(strict_types=1);

namespace Modules\Reviews\Http;

use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Setting\SettingRecord;
use Gereq\Setting\Settings;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class ReviewSettingsController extends Controller
{
    /** @var list<string> */
    private const KEYS = [
        'reviews.google_enabled',
        'reviews.google_place_id',
        'reviews.google_api_key',
        'reviews.facebook_enabled',
        'reviews.facebook_page_id',
        'reviews.facebook_access_token',
        'reviews.replies_enabled',
        'reviews.default_locale',
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
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function edit(): Response
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

        return $this->page('@reviews/settings.html.twig', [
            'pageTitle' => $this->translator->trans('reviews.settings.title'),
            'fields' => $fields,
            'sections' => [
                'google' => [
                    'reviews.google_enabled',
                    'reviews.google_place_id',
                    'reviews.google_api_key',
                ],
                'facebook' => [
                    'reviews.facebook_enabled',
                    'reviews.facebook_page_id',
                    'reviews.facebook_access_token',
                ],
                'general' => [
                    'reviews.replies_enabled',
                    'reviews.default_locale',
                ],
            ],
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
                $this->settings->set($key, $posted === '1', $definition['type'], $definition['module'] ?? 'reviews');
                continue;
            }

            $value = is_scalar($posted) ? (string) $posted : '';
            if ($this->isSecret($key, $definition['type']) && $value === '') {
                continue;
            }

            $this->settings->set($key, $value, $definition['type'], $definition['module'] ?? 'reviews');
        }

        $this->auditor->log('reviews.settings.updated', 'settings', 'reviews');
        $this->flash('success', $this->translator->trans('reviews.settings.saved'));

        return $this->redirect('admin.reviews.settings');
    }

    private function isSecret(string $key, string $type): bool
    {
        if ($type === SettingRecord::TYPE_ENCRYPTED) {
            return true;
        }

        $lower = strtolower($key);

        return str_contains($lower, 'token')
            || str_contains($lower, 'api_key')
            || str_contains($lower, 'secret')
            || str_contains($lower, 'password');
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
