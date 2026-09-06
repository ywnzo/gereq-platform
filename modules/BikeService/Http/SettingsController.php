<?php

declare(strict_types=1);

namespace Modules\BikeService\Http;

use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\Router;
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
        private readonly TranslatorInterface $translator,
        private readonly Settings $settings,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function edit(): Response
    {
        return $this->page('@bikeservice/settings.html.twig', [
            'pageTitle' => $this->translator->trans('bikeservice.settings.title'),
            'values' => [
                'default_team' => (string) $this->settings->get('bikeservice.default_team', 'mechanics'),
                'media_phases' => (string) $this->settings->get('bikeservice.media_phases', 'before,progress,after'),
                'default_city' => (string) $this->settings->get('bikeservice.default_city', 'Praha'),
                'default_citybikes_network' => (string) $this->settings->get('bikeservice.default_citybikes_network', 'nextbike-praha'),
                'default_lat' => (string) $this->settings->get('bikeservice.default_lat', '50.087'),
                'default_lon' => (string) $this->settings->get('bikeservice.default_lon', '14.421'),
            ],
        ]);
    }

    public function update(Request $request): Response
    {
        $data = $this->validate($request, 'bikeservice.settings');
        $team = (string) ($data['default_team'] ?? 'mechanics');
        if (!in_array($team, ['mechanics', 'electric'], true)) {
            $team = 'mechanics';
        }
        $this->settings->set('bikeservice.default_team', $team);
        $this->settings->set(
            'bikeservice.media_phases',
            trim((string) ($data['media_phases'] ?? 'before,progress,after')) ?: 'before,progress,after',
        );
        $this->settings->set('bikeservice.default_city', trim((string) ($data['default_city'] ?? 'Praha')) ?: 'Praha');
        $this->settings->set(
            'bikeservice.default_citybikes_network',
            trim((string) ($data['default_citybikes_network'] ?? 'nextbike-praha')) ?: 'nextbike-praha',
        );
        $this->settings->set('bikeservice.default_lat', trim((string) ($data['default_lat'] ?? '50.087')) ?: '50.087');
        $this->settings->set('bikeservice.default_lon', trim((string) ($data['default_lon'] ?? '14.421')) ?: '14.421');
        $this->flash('success', $this->translator->trans('bikeservice.flash.settings_saved'));

        return $this->redirect('admin.bikeservice.settings');
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
