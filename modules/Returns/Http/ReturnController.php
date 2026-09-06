<?php

declare(strict_types=1);

namespace Modules\Returns\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Event\EventBus;
use Gereq\Http\Controller;
use Gereq\Http\ListQuery;
use Gereq\Http\Router;
use Modules\Currency\Application\CurrencyBuilder;
use Modules\Returns\Application\ReturnsProvider;
use Modules\Returns\Domain\ReturnItem;
use Modules\Returns\Domain\ReturnRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class ReturnController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly Authenticator $auth,
        private readonly Menu $menu,
        private readonly CommandPalette $palette,
        private readonly Auditor $auditor,
        private readonly EventBus $events,
        private readonly TranslatorInterface $translator,
        private readonly ReturnsProvider $provider,
        private readonly CurrencyBuilder $money,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $list = ListQuery::fromRequest(
            $request,
            sortable: ['status', 'number', 'createdAt'],
            defaultSort: 'createdAt',
            defaultDir: 'desc',
            filterKeys: ['status'],
        );
        $status = $list->filter('status');

        $qb = $this->em->getRepository(ReturnRequest::class)->createQueryBuilder('r');
        $list->applySearch($qb, 'r', ['number', 'orderId', 'reason']);
        if (in_array($status, ReturnRequest::STATUSES, true)) {
            $qb->andWhere('r.status = :s')->setParameter('s', $status);
        }
        $qb->orderBy('r.' . $list->sort, $list->direction());

        $total = (int) (clone $qb)->select('COUNT(r.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult($list->offset())->setMaxResults($list->perPage)->getQuery()->getResult();

        return $this->page('@returns/index.html.twig', array_merge($list->viewData($total), [
            'pageTitle' => $this->translator->trans('returns.title'),
            'items' => $items,
            'status' => $status,
            'statuses' => ReturnRequest::STATUSES,
        ]));
    }

    public function create(): Response
    {
        return $this->page('@returns/form.html.twig', [
            'pageTitle' => $this->translator->trans('returns.new'),
            'item' => null,
            'resolutions' => ReturnRequest::RESOLUTIONS,
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validated($request);
        $ret = new ReturnRequest($this->provider->nextNumber(), $data['order_id'], $data['reason']);
        $ret->setCustomerId($data['customer_id']);
        $ret->setResolution($data['resolution']);
        $ret->setRefundAmount($data['refund_amount']);
        $ret->setCurrency($data['currency']);
        $ret->setNote($data['note']);
        $this->em->persist($ret);

        foreach ($this->parseItems($request) as $row) {
            $item = new ReturnItem($ret->id(), $row['title'], $row['qty']);
            $item->setProductId($row['product_id']);
            $item->setReason($row['reason']);
            $this->em->persist($item);
        }

        $this->em->flush();
        $payload = $this->public($ret);
        $this->events->emit('return.created', ['type' => 'return_request', 'id' => $ret->id()], $payload);
        $this->auditor->log('returns.created', 'return_request', $ret->id(), null, ['number' => $ret->number()]);
        $this->forgetForm('returns.save');
        $this->flash('success', $this->translator->trans('returns.flash.created', ['%number%' => $ret->number()]));

        return $this->redirect('admin.returns.show', ['id' => $ret->id()]);
    }

    public function show(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('returns.error.not_found'));
        }
        $lines = $this->em->getRepository(ReturnItem::class)->findBy(['returnId' => $id], ['title' => 'ASC']);

        return $this->page('@returns/show.html.twig', [
            'pageTitle' => $item->number(),
            'item' => $item,
            'lines' => $lines,
            'statuses' => ReturnRequest::STATUSES,
            'resolutions' => ReturnRequest::RESOLUTIONS,
        ]);
    }

    public function updateStatus(Request $request, string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('returns.error.not_found'));
        }
        $status = (string) $request->request->get('status', '');
        if (!in_array($status, ReturnRequest::STATUSES, true)) {
            $this->flash('error', $this->translator->trans('returns.error.invalid_status'));

            return $this->redirect('admin.returns.show', ['id' => $id]);
        }
        $item->setStatus($status);
        $resolution = trim((string) $request->request->get('resolution', ''));
        if ($resolution !== '') {
            $item->setResolution($resolution);
        }
        $refund = trim((string) $request->request->get('refund_amount', ''));
        if ($refund !== '') {
            $item->setRefundAmount($refund);
        }
        $note = trim((string) $request->request->get('note', ''));
        if ($request->request->has('note')) {
            $item->setNote($note);
        }
        $this->em->flush();
        $payload = $this->public($item);
        $this->events->emit('return.status_changed', ['type' => 'return_request', 'id' => $id], $payload);
        $this->events->emit('return.updated', ['type' => 'return_request', 'id' => $id], $payload);
        $this->flash('success', $this->translator->trans('returns.flash.status_changed'));

        return $this->redirect('admin.returns.show', ['id' => $id]);
    }

    public function destroy(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('returns.error.not_found'));
        }
        $number = $item->number();
        foreach ($this->em->getRepository(ReturnItem::class)->findBy(['returnId' => $id]) as $line) {
            $this->em->remove($line);
        }
        $this->em->remove($item);
        $this->em->flush();
        $this->events->emit('return.deleted', ['type' => 'return_request', 'id' => $id], ['id' => $id, 'number' => $number]);
        $this->auditor->log('returns.deleted', 'return_request', $id);
        $this->flash('success', $this->translator->trans('returns.flash.deleted', ['%number%' => $number]));

        return $this->redirect('admin.returns.index');
    }

    private function find(string $id): ?ReturnRequest
    {
        return $this->provider->find($id);
    }

    /**
     * @return array{order_id: string, customer_id: ?string, reason: string, resolution: ?string, refund_amount: ?string, currency: string, note: ?string}
     */
    private function validated(Request $request): array
    {
        $data = $this->validate($request, 'returns.save');

        return [
            'order_id' => trim((string) $data['order_id']),
            'customer_id' => trim((string) ($data['customer_id'] ?? '')) ?: null,
            'reason' => trim((string) $data['reason']),
            'resolution' => in_array(($data['resolution'] ?? ''), ReturnRequest::RESOLUTIONS, true)
                ? (string) $data['resolution']
                : null,
            'refund_amount' => trim((string) ($data['refund_amount'] ?? '')) ?: null,
            'currency' => $this->money->code(isset($data['currency']) ? (string) $data['currency'] : null),
            'note' => trim((string) ($data['note'] ?? '')) ?: null,
        ];
    }

    /** @return list<array{title: string, qty: int, product_id: ?string, reason: ?string}> */
    private function parseItems(Request $request): array
    {
        $all = $request->request->all();
        $titles = is_array($all['item_title'] ?? null) ? $all['item_title'] : [];
        $qtys = is_array($all['item_qty'] ?? null) ? $all['item_qty'] : [];
        $products = is_array($all['item_product_id'] ?? null) ? $all['item_product_id'] : [];
        $reasons = is_array($all['item_reason'] ?? null) ? $all['item_reason'] : [];
        $rows = [];
        foreach ($titles as $i => $title) {
            $title = trim((string) $title);
            if ($title === '') {
                continue;
            }
            $rows[] = [
                'title' => $title,
                'qty' => max(1, (int) ($qtys[$i] ?? 1)),
                'product_id' => trim((string) ($products[$i] ?? '')) ?: null,
                'reason' => trim((string) ($reasons[$i] ?? '')) ?: null,
            ];
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function public(ReturnRequest $r): array
    {
        return [
            'id' => $r->id(),
            'number' => $r->number(),
            'order_id' => $r->orderId(),
            'customer_id' => $r->customerId(),
            'status' => $r->status(),
            'reason' => $r->reason(),
            'resolution' => $r->resolution(),
            'refund_amount' => $r->refundAmount(),
            'currency' => $r->currency(),
            'note' => $r->note(),
            'created_at' => $r->createdAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $r->updatedAt()->format(\DateTimeInterface::ATOM),
        ];
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
