<?php

declare(strict_types=1);

namespace Modules\CarService\Http;

use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Setting\Settings;
use Modules\CarService\Domain\ServiceOrder;
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
        return $this->page('@carservice/settings.html.twig', [
            'pageTitle' => $this->translator->trans('carservice.settings.title'),
            'jobSoftLimitHours' => (string) $this->settings->get('carservice.job_soft_limit_hours', '8'),
            'defaultTeam' => (string) $this->settings->get('carservice.default_team', 'body_shop'),
            'mediaPhases' => (string) $this->settings->get('carservice.media_phases', 'before,progress,paint,after'),
            'autoIdoklad' => (bool) $this->settings->get('carservice.auto_idoklad', false),
            'teams' => ServiceOrder::TEAMS,
        ]);
    }

    public function update(Request $request): Response
    {
        $data = $this->validate($request, 'carservice.settings');
        $hours = (string) $data['job_soft_limit_hours'];
        if (!is_numeric($hours) || (float) $hours < 0) {
            $this->flash('error', $this->translator->trans('carservice.error.invalid_limit'));

            return $this->redirect('admin.carservice.settings');
        }
        $team = (string) $data['default_team'];
        if (!in_array($team, ServiceOrder::TEAMS, true)) {
            $this->flash('error', $this->translator->trans('carservice.error.invalid_team'));

            return $this->redirect('admin.carservice.settings');
        }
        $phases = trim((string) ($data['media_phases'] ?? ''));
        if ($phases === '') {
            $phases = 'before,progress,paint,after';
        }
        $this->settings->set('carservice.job_soft_limit_hours', $hours);
        $this->settings->set('carservice.default_team', $team);
        $this->settings->set('carservice.media_phases', $phases);
        $this->settings->set('carservice.auto_idoklad', !empty($data['auto_idoklad']));
        $this->forgetForm('carservice.settings');
        $this->flash('success', $this->translator->trans('carservice.flash.settings_saved'));

        return $this->redirect('admin.carservice.settings');
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
