<?php

declare(strict_types=1);

namespace Modules\Vitality\Http;

use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Modules\Vitality\Application\VitalityMonitor;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class VitalityController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly Authenticator $auth,
        private readonly Menu $menu,
        private readonly CommandPalette $palette,
        private readonly TranslatorInterface $translator,
        private readonly VitalityMonitor $monitor,
        private readonly Auditor $auditor,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(): Response
    {
        $status = $this->monitor->overallStatus();

        return $this->page('@vitality/index.html.twig', [
            'pageTitle' => $this->translator->trans('vitality.title'),
            'status' => $status,
            'endpoints' => $this->monitor->endpointStats(150),
            'slowLogs' => $this->monitor->recentSlowLogs(40),
            'slowThreshold' => VitalityMonitor::SLOW_AVG_MS,
            'warnThreshold' => VitalityMonitor::WARN_AVG_MS,
        ]);
    }

    public function show(string $id): Response
    {
        $log = $this->monitor->findSlowLog($id);
        if ($log === null) {
            $this->abort(404, $this->translator->trans('vitality.error.not_found'));
        }

        return $this->page('@vitality/show.html.twig', [
            'pageTitle' => $this->translator->trans('vitality.log.title'),
            'log' => $log,
        ]);
    }

    public function reset(): Response
    {
        $this->monitor->reset();
        $this->auditor->log('vitality.reset', 'vitality', null);
        $this->flash('success', $this->translator->trans('vitality.flash.reset'));

        return $this->redirect('admin.vitality.index');
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
