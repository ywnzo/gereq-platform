<?php

declare(strict_types=1);

namespace Modules\Cookie\Http;

use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Security\ContentSecurityPolicy;
use Gereq\Setting\Settings;
use Gereq\Validation\Validator;
use Modules\Cookie\Application\CookieConfig;
use Modules\Cookie\Application\CookieImageStore;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class CookieSettingsController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly Authenticator $auth,
        private readonly Menu $menu,
        private readonly CommandPalette $palette,
        private readonly Settings $settings,
        private readonly CookieConfig $config,
        private readonly CookieImageStore $images,
        private readonly Validator $validator,
        private readonly Auditor $auditor,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(): Response
    {
        $sections = [];
        foreach (CookieConfig::definitions() as $key => $definition) {
            $sections[$definition['section']][] = [
                'name' => substr($key, 7), 'key' => $key, 'definition' => $definition,
                'value' => $this->settings->get($key, $definition['default']),
            ];
        }
        $user = $this->auth->user();

        return $this->render('@cookie/settings.html.twig', [
            'pageTitle' => $this->translator->trans('cookie.settings.title'),
            'sections' => $sections, 'user' => $user,
            'imageUrl' => $this->config->payload()['image_url'],
            'menu' => $this->menu->tree($user, $this->router, $this->request()),
            'commands' => $this->palette->all($user),
        ]);
    }

    public function save(Request $request): Response
    {
        $image = trim((string) $request->request->get('image', ''));
        $prefix = '/api/public/cookie/image/';
        if (str_starts_with($image, $prefix)) {
            $request->request->set('image', substr($image, strlen($prefix)));
        }
        $values = $this->validator->validateRequest($request, 'cookie.settings');
        $upload = $request->files->get('image_upload');
        if ($upload instanceof UploadedFile) {
            try {
                $values['image'] = $this->images->store($upload);
            } catch (\RuntimeException $exception) {
                $this->flash('error', $this->translator->trans($exception->getMessage()));

                return $this->redirect('admin.cookie.index');
            }
        } elseif ($request->request->get('clear_image') === '1') {
            $values['image'] = '';
        }
        foreach (CookieConfig::definitions() as $key => $definition) {
            $field = substr($key, 7);
            $value = match ($definition['type']) {
                'boolean' => (string) $values[$field] === '1',
                'integer' => (int) $values[$field],
                default => trim((string) ($values[$field] ?? '')),
            };
            if ($this->settings->get($key, $definition['default']) !== $value) {
                $this->settings->set($key, $value, $definition['type'], 'cookie');
            }
        }
        $this->auditor->log('cookie.settings.updated', 'settings', 'cookie');
        $this->forgetForm('cookie.settings');
        $this->flash('success', $this->translator->trans('cookie.settings.saved'));

        return $this->redirect('admin.cookie.index');
    }

    public function previewConfig(): Response
    {
        return new JsonResponse($this->config->payload() + ['preview' => true], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function preview(): Response
    {
        $response = $this->render('@cookie/preview.html.twig');
        $response->headers->set(ContentSecurityPolicy::HEADER_ALLOW_FRAME, '1');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
