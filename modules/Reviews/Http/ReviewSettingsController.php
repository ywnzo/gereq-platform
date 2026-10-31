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
use Gereq\User\Entity\User;
use Gereq\Validation\ValidationRegistry;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class ReviewSettingsController extends Controller
{
    /** @var array<string, list<string>> */
    public const SECTIONS = [
        'google' => ['reviews.google_enabled', 'reviews.google_place_id', 'reviews.google_api_key'],
        'facebook' => ['reviews.facebook_enabled', 'reviews.facebook_page_id', 'reviews.facebook_access_token'],
        'general' => ['reviews.replies_enabled', 'reviews.default_locale'],
    ];
    /** @var list<string> */
    private const SECRET_KEYS = ['reviews.google_api_key', 'reviews.facebook_access_token'];
    /** @var list<string> */
    private const BOOLEAN_KEYS = ['reviews.google_enabled', 'reviews.facebook_enabled', 'reviews.replies_enabled'];

    private static function fieldName(string $key): string
    {
        return str_replace('.', '__', $key) . (in_array($key, self::SECRET_KEYS, true) ? '__password' : '');
    }

    public static function registerValidation(ValidationRegistry $validation, TranslatorInterface $translator): void
    {
        $rules = [];
        foreach (self::SECTIONS as $keys) {
            foreach ($keys as $key) {
                $rules[self::fieldName($key)] = 'nullable';
            }
        }
        $validation->ruleset('reviews.settings.save', $rules, static function (array $data, array $errors) use ($translator): array {
            foreach (self::SECTIONS as $keys) {
                foreach ($keys as $key) {
                    $field = self::fieldName($key);
                    if (!isset($data[$field]) || !is_string($data[$field]) || mb_strlen($data[$field]) > 10000) {
                        $errors[$field] = $translator->trans('validation.form_failed');
                    } elseif (in_array($key, self::BOOLEAN_KEYS, true) && !in_array($data[$field], ['0', '1'], true)) {
                        $errors[$field] = $translator->trans('validation.form_failed');
                    }
                }
            }

            return $errors;
        });
    }

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

    /** @return array<string, array{key: string, field: string, definition: array<string, mixed>, value: mixed, secret: bool}> */
    public static function fields(Settings $settings): array
    {
        $definitions = $settings->definitions();
        $fields = [];
        foreach (self::SECTIONS as $keys) {
            foreach ($keys as $key) {
                if (!isset($definitions[$key])) {
                    continue;
                }
                $definition = $definitions[$key];
                $secret = in_array($key, self::SECRET_KEYS, true);
                $value = $settings->get($key, $definition['default']);
                $fields[$key] = [
                    'key' => $key,
                    'field' => self::fieldName($key),
                    'definition' => $definition,
                    'value' => $secret ? (is_string($value) && $value !== '') : $value,
                    'secret' => $secret,
                ];
            }
        }

        return $fields;
    }

    public function edit(): Response
    {
        $user = $this->auth->user();
        if ($user instanceof User && $user->can('reviews.view')) {
            return $this->redirectTo($this->router->url('admin.reviews.index', ['modal' => 'reviews-settings-modal']));
        }

        return $this->page('@reviews/settings.html.twig', [
            'pageTitle' => $this->translator->trans('reviews.settings.title'),
            'fields' => self::fields($this->settings),
            'sections' => self::SECTIONS,
        ]);
    }

    public function update(Request $request): Response
    {
        foreach (self::SECRET_KEYS as $key) {
            $legacy = str_replace('.', '__', $key);
            $field = self::fieldName($key);
            if (!$request->request->has($field) && $request->request->has($legacy)) {
                $request->request->set($field, $request->request->all()[$legacy]);
            }
            $request->request->remove($legacy);
        }
        $this->validate($request, 'reviews.settings.save');
        $data = $request->request->all();
        $definitions = $this->settings->definitions();
        foreach (self::SECTIONS as $keys) {
            foreach ($keys as $key) {
                if (!isset($definitions[$key])) {
                    continue;
                }
                $definition = $definitions[$key];
                $value = $data[self::fieldName($key)];
                if (in_array($key, self::SECRET_KEYS, true) && $value === '') {
                    continue;
                }
                if ($definition['type'] === SettingRecord::TYPE_BOOLEAN) {
                    $value = $value === '1';
                }
                $this->settings->set($key, $value, $definition['type'], $definition['module'] ?? 'reviews');
            }
        }
        $this->auditor->log('reviews.settings.updated', 'settings', 'reviews');
        $this->forgetForm('reviews.settings.save');
        $this->flash('success', $this->translator->trans('reviews.settings.saved'));
        $user = $this->auth->user();
        return $this->redirect($user instanceof User && $user->can('reviews.view') ? 'admin.reviews.index' : 'admin.reviews.settings');
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
