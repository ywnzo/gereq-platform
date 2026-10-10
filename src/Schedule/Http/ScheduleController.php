<?php

declare(strict_types=1);

namespace Gereq\Schedule\Http;

use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Queue\Application\QueueHealthChecker;
use Gereq\Queue\Queue;
use Gereq\Schedule\Schedule;
use Gereq\Schedule\ScheduleRunner;
use Gereq\Schedule\ScheduleState;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class ScheduleController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly Schedule $schedule,
        private readonly ScheduleRunner $runner,
        private readonly ScheduleState $state,
        private readonly Queue $queue,
        private readonly QueueHealthChecker $health,
        private readonly TranslatorInterface $translator,
        private readonly Menu $menu,
        private readonly CommandPalette $palette,
        private readonly Authenticator $auth,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $rows = [];
        $pausedCount = 0;
        foreach ($this->schedule->events() as $event) {
            $name = $event->mutexName();
            $paused = $this->state->isPaused($name);
            if ($paused) {
                $pausedCount++;
            }
            $next = $event->nextRun();
            $rows[] = [
                'id' => $name,
                'name' => $event->displayName(),
                'description' => $event->descriptionText(),
                'expression' => $event->expression(),
                'type' => $event->type(),
                'async' => $event->runsAsync(),
                'timezone' => $event->timezoneName(),
                'next' => $next?->format('Y-m-d H:i T'),
                'due' => !$paused && $event->isDue(),
                'paused' => $paused,
                'runnable' => $event->type() !== 'call',
            ];
        }

        return $this->page('queues/schedule.html.twig', [
            'pageTitle' => $this->translator->trans('queues.schedule.title'),
            'events' => $rows,
            'health' => $this->health->status(),
            'pending' => $this->queue->pending(),
            'delayed' => $this->queue->delayed(),
            'pausedCount' => $pausedCount,
        ]);
    }

    public function run(string $name): Response
    {
        if (!$this->validName($name)) {
            $this->abort(404, $this->translator->trans('queues.schedule.error.not_found'));
        }

        $event = $this->schedule->findByName($name);
        if ($event === null || $event->type() === 'call') {
            $this->abort(404, $this->translator->trans('queues.schedule.error.not_found'));
        }

        $result = $this->runner->runNow($name);
        if ($result['failed'] > 0) {
            $this->flash('error', $this->translator->trans('queues.schedule.flash.run_failed', [
                '%name%' => $name,
            ]));
        } elseif ($result['skipped'] > 0) {
            $this->flash('warning', $this->translator->trans('queues.schedule.flash.run_skipped', [
                '%name%' => $name,
            ]));
        } else {
            $this->flash('success', $this->translator->trans(
                $event->runsAsync()
                    ? 'queues.schedule.flash.run_queued'
                    : 'queues.schedule.flash.run_ok',
                ['%name%' => $name],
            ));
        }

        return $this->redirect('admin.queues.schedule');
    }

    public function pause(string $name): Response
    {
        if (!$this->validName($name) || $this->schedule->findByName($name) === null) {
            $this->abort(404, $this->translator->trans('queues.schedule.error.not_found'));
        }

        $this->state->pause($name);
        $this->flash('success', $this->translator->trans('queues.schedule.flash.paused', [
            '%name%' => $name,
        ]));

        return $this->redirect('admin.queues.schedule');
    }

    public function resume(string $name): Response
    {
        if (!$this->validName($name) || $this->schedule->findByName($name) === null) {
            $this->abort(404, $this->translator->trans('queues.schedule.error.not_found'));
        }

        $this->state->resume($name);
        $this->flash('success', $this->translator->trans('queues.schedule.flash.resumed', [
            '%name%' => $name,
        ]));

        return $this->redirect('admin.queues.schedule');
    }

    private function validName(string $name): bool
    {
        return preg_match('/^[a-zA-Z0-9._-]{1,120}$/', $name) === 1;
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
