<?php

declare(strict_types=1);

namespace Gereq\Queue\Http;

use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\ListQuery;
use Gereq\Http\Router;
use Gereq\Queue\Application\QueueHealthChecker;
use Gereq\Queue\JobRun;
use Gereq\Queue\JobRunStore;
use Gereq\Queue\Queue;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class QueueRunController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly JobRunStore $runs,
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
        $list = ListQuery::fromRequest(
            $request,
            sortable: ['startedAt', 'job', 'status', 'durationMs', 'trigger'],
            defaultSort: 'startedAt',
            defaultDir: 'desc',
            perPage: 25,
            filterKeys: ['status', 'trigger', 'job'],
            maxPerPage: 100,
        );

        $qb = $this->runs->createQueryBuilder();
        $list->applySearch($qb, 'r', ['job', 'exception', 'queue']);

        $status = $list->filter('status');
        if ($status !== '' && in_array($status, [
            JobRun::STATUS_SUCCESS,
            JobRun::STATUS_FAILED,
            JobRun::STATUS_SKIPPED,
        ], true)) {
            $qb->andWhere('r.status = :status')->setParameter('status', $status);
        }

        $trigger = $list->filter('trigger');
        if ($trigger !== '' && in_array($trigger, [
            JobRun::TRIGGER_WORKER,
            JobRun::TRIGGER_SYNC,
            JobRun::TRIGGER_SCHEDULE,
            JobRun::TRIGGER_MANUAL,
        ], true)) {
            $qb->andWhere('r.trigger = :trigger')->setParameter('trigger', $trigger);
        }

        $job = $list->filter('job');
        if ($job !== '') {
            $qb->andWhere('r.job LIKE :job')->setParameter('job', '%' . $job . '%');
        }

        $total = (int) (clone $qb)
            ->select('COUNT(r.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();

        $rows = $qb
            ->orderBy('r.' . $list->sort, $list->direction())
            ->setFirstResult($list->offset())
            ->setMaxResults($list->perPage)
            ->getQuery()
            ->getResult();

        $items = array_values(array_filter($rows, static fn (mixed $row): bool => $row instanceof JobRun));

        return $this->page('queues/runs/index.html.twig', array_merge($list->viewData($total), [
            'pageTitle' => $this->translator->trans('queues.runs.title'),
            'items' => $items,
            'health' => $this->health->status(),
            'status' => $status,
            'trigger' => $trigger,
            'job' => $job,
            'pending' => $this->queue->pending(),
            'delayed' => $this->queue->delayed(),
        ]));
    }

    public function show(string $id): Response
    {
        $item = $this->runs->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('queues.runs.error.not_found'));
        }

        return $this->page('queues/runs/show.html.twig', [
            'pageTitle' => $this->translator->trans('queues.runs.detail.title'),
            'item' => $item,
            'health' => $this->health->status(),
        ]);
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
