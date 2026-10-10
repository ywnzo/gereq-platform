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
use Gereq\Queue\FailedJob;
use Gereq\Queue\FailedJobStore;
use Gereq\Queue\Queue;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Read and act on the core failed_jobs audit trail. All data access goes through
 * FailedJobStore / Queue — this controller never queries the failed_jobs table.
 */
final class QueueFailedController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly FailedJobStore $failed,
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
            sortable: ['failedAt', 'job', 'queue', 'attempts'],
            defaultSort: 'failedAt',
            defaultDir: 'desc',
            perPage: 25,
            filterKeys: ['queue', 'attempts'],
            maxPerPage: 100,
        );

        $failedJobs = $this->failed;

        $qb = $failedJobs->createQueryBuilder();
        $list->applySearch($qb, 'f', ['job', 'queue', 'exception']);

        $queue = $list->filter('queue');
        if ($queue !== '') {
            $qb->andWhere('f.queue = :queue')->setParameter('queue', $queue);
        }

        $attempts = $list->filter('attempts');
        if (preg_match('/^\d{1,3}$/', $attempts) === 1) {
            $qb->andWhere('f.attempts >= :attempts')->setParameter('attempts', (int) $attempts);
        }

        $total = (int) (clone $qb)
            ->select('COUNT(f.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();

        // Counted on the same builder as $total so the header counts add up within the view
        // the admin is actually looking at. health.failedCount is a global figure — mixing it
        // with a filtered total would produce nonsense as soon as a search or filter is set.
        $maxAttempts = $this->queue->maxAttempts();
        $exhausted = (int) (clone $qb)
            ->select('COUNT(f.id)')
            ->resetDQLPart('orderBy')
            ->andWhere('f.attempts >= :exhaustedAt')
            ->setParameter('exhaustedAt', $maxAttempts)
            ->getQuery()
            ->getSingleScalarResult();

        $rows = $qb
            ->orderBy('f.' . $list->sort, $list->direction())
            ->setFirstResult($list->offset())
            ->setMaxResults($list->perPage)
            ->getQuery()
            ->getResult();

        $items = array_values(array_filter($rows, static fn (mixed $row): bool => $row instanceof FailedJob));

        return $this->page('queues/failed/index.html.twig', [
            'pageTitle' => $this->translator->trans('queues.title'),
            'items' => $items,
            'health' => $this->health->status(),
            'list' => $list->viewData($total),
            'maxAttempts' => $maxAttempts,
            'exhausted' => $exhausted,
            'queue' => $list->filter('queue'),
            'attempts' => $list->filter('attempts'),
        ]);
    }

    public function show(string $id): Response
    {
        $failedJob = $this->failed->find($id);
        if ($failedJob === null) {
            $this->abort(404, 'Záznam selhané úlohy nebyl nalezen.');
        }

        return $this->page('queues/failed/show.html.twig', [
            'pageTitle' => $this->translator->trans('queues.detail.title'),
            'item' => $failedJob,
            'health' => $this->health->status(),
            'maxAttempts' => $this->queue->maxAttempts(),
        ]);
    }

    /**
     * Render an admin page with the shared chrome.
     *
     * Controller::render() is a pass-through and Twig runs with strict_variables off, so a
     * forgotten variable does not fail loudly — an undefined `menu` just draws an empty
     * sidebar, and an undefined `user` silently hides everything behind
     * `user.can(...)`, including the retry / forget / prune controls. Every admin render
     * in the queue admin therefore goes through here.
     *
     * @param array<string, mixed> $data
     */
    private function page(string $view, array $data): Response
    {
        $user = $this->auth->user();

        return $this->render($view, array_merge($data, [
            'menu' => $this->menu->tree($user, $this->router, $this->request()),
            'commands' => $this->palette->all($user),
            'user' => $user,
        ]));
    }

    public function retry(string $id): Response
    {
        $failedJob = $this->failed->find($id);
        if ($failedJob === null) {
            $this->abort(404, 'Záznam selhané úlohy nebyl nalezen.');
        }

        // A job that still has attempts left is already scheduled on the queue. Requeueing it
        // here would leave the worker to run it once more on top of this one. The button in the
        // list and on the detail page is disabled in the same situation, but a POST can be
        // crafted by hand, so the check belongs here too.
        $maxAttempts = $this->queue->maxAttempts();
        if ($failedJob->attempts() < $maxAttempts) {
            $this->flash('error', $this->translator->trans('queues.flash.retry_still_retrying', [
                '%attempts%' => (string) $failedJob->attempts(),
                '%max%' => (string) $maxAttempts,
            ]));

            return $this->redirect('admin.queues.show', ['id' => $id]);
        }

        // Requeue deserialises a payload taken from the database, so it stays behind
        // queues.manage and is reported in the audit log like any other mutation.
        if (!$this->failed->requeue($failedJob, $this->queue)) {
            $this->flash('error', $this->translator->trans('queues.flash.retry_failed_payload'));

            return $this->redirect('admin.queues.index');
        }

        $this->failed->forget($failedJob);
        $this->health->forget();
        $this->flash('success', $this->translator->trans('queues.flash.requeued', [
            '%job%' => $failedJob->job(),
        ]));

        return $this->redirect('admin.queues.index');
    }

    public function forget(string $id): Response
    {
        $failedJob = $this->failed->find($id);
        if ($failedJob === null) {
            $this->abort(404, 'Záznam selhané úlohy nebyl nalezen.');
        }

        $this->failed->forget($failedJob);
        $this->health->forget();
        $this->flash('success', $this->translator->trans('queues.flash.forgotten'));

        return $this->redirect('admin.queues.index');
    }

    public function prune(Request $request): Response
    {
        $days = (int) $request->request->get('days', 30);
        $removed = $days > 0 ? $this->failed->prune($days) : 0;
        $this->health->forget();

        $this->flash(
            'success',
            $this->translator->trans('queues.flash.pruned', ['%count%' => (string) $removed]),
        );

        return $this->redirect('admin.queues.index');
    }
}
