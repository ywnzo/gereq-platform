<?php

declare(strict_types=1);

namespace Modules\Orders\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Event\EventBus;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Modules\Customers\Domain\Customer;
use Modules\Orders\Application\NumberSequencer;
use Modules\Orders\Application\OrderTotals;
use Modules\Orders\Domain\Order;
use Modules\Orders\Domain\OrderLine;
use Modules\Products\Domain\Product;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class OrderController extends Controller
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
        private readonly NumberSequencer $numbers,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $list = \Gereq\Http\ListQuery::fromRequest(
            $request,
            sortable: ['number', 'status', 'total', 'createdAt', 'updatedAt'],
            defaultSort: 'createdAt',
            defaultDir: 'desc',
            filterKeys: ['status', 'customer', 'payment_status', 'currency'],
        );
        $status = $list->filter('status');
        $customerId = $list->filter('customer');
        $paymentStatus = $list->filter('payment_status');
        $currency = strtoupper($list->filter('currency'));

        $qb = $this->em->getRepository(Order::class)->createQueryBuilder('o');
        $list->applySearch($qb, 'o', ['number', 'note', 'shippingMethodName', 'paymentMethodName']);
        if (in_array($status, Order::STATUSES, true)) {
            $qb->andWhere('o.status = :status')->setParameter('status', $status);
        }
        if ($customerId !== '') {
            $qb->andWhere('o.customerId = :cid')->setParameter('cid', $customerId);
        }
        if ($paymentStatus !== '') {
            $qb->andWhere('o.paymentStatus = :pay')->setParameter('pay', $paymentStatus);
        }
        if ($currency !== '' && strlen($currency) === 3) {
            $qb->andWhere('o.currency = :currency')->setParameter('currency', $currency);
        }
        $qb->orderBy('o.' . $list->sort, $list->direction());

        $total = (int) (clone $qb)->select('COUNT(o.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        /** @var list<Order> $items */
        $items = $qb->setFirstResult($list->offset())->setMaxResults($list->perPage)->getQuery()->getResult();

        $currencyRows = $this->em->createQueryBuilder()
            ->select('DISTINCT o.currency AS currency')
            ->from(Order::class, 'o')
            ->orderBy('o.currency', 'ASC')
            ->getQuery()
            ->getScalarResult();
        $currencies = array_values(array_filter(array_map(
            static fn (array $row): string => (string) ($row['currency'] ?? ''),
            $currencyRows,
        )));

        $paymentRows = $this->em->createQueryBuilder()
            ->select('DISTINCT o.paymentStatus AS paymentStatus')
            ->from(Order::class, 'o')
            ->orderBy('o.paymentStatus', 'ASC')
            ->getQuery()
            ->getScalarResult();
        $paymentStatuses = array_values(array_unique(array_filter(array_merge(
            ['unpaid', 'paid', 'pending'],
            array_map(static fn (array $row): string => (string) ($row['paymentStatus'] ?? ''), $paymentRows),
        ))));

        return $this->page('@orders/orders/index.html.twig', array_merge($list->viewData($total), [
            'pageTitle' => $this->translator->trans('orders.title'),
            'items' => $items,
            'customers' => $this->customerMap($items),
            'status' => $status,
            'customerId' => $customerId,
            'payment_status' => $paymentStatus,
            'currency' => $currency,
            'statuses' => Order::STATUSES,
            'currencies' => $currencies !== [] ? $currencies : ['CZK', 'EUR'],
            'paymentStatuses' => $paymentStatuses,
            'customerFilterOptions' => $this->customerOptions(),
        ]));
    }

    public function create(Request $request): Response
    {
        return $this->page('@orders/orders/form.html.twig', [
            'pageTitle' => $this->translator->trans('orders.new'),
            'item' => null,
            'customerId' => (string) $request->query->get('customer', ''),
            'customerOptions' => $this->customerOptions(),
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validated($request);
        $order = new Order($data['customer_id'], $this->numbers->nextOrderNumber(), $data['currency']);
        $order->setNote($data['note']);
        $this->em->persist($order);
        $this->em->flush();
        $payload = $this->publicOrder($order);
        $this->events->emit('order.created', ['type' => 'order', 'id' => $order->id()], $payload);
        $this->auditor->log('orders.created', 'order', $order->id(), null, ['number' => $order->number()]);
        $this->forgetForm('orders.save');
        $this->flash('success', $this->translator->trans('orders.flash.created', ['%number%' => $order->number()]));

        return $this->redirect('admin.orders.show', ['id' => $order->id()]);
    }

    public function show(string $id): Response
    {
        $order = $this->find($id);
        if ($order === null) {
            $this->abort(404, $this->translator->trans('orders.error.not_found'));
        }
        $lines = $this->em->getRepository(OrderLine::class)->findBy(['orderId' => $id], ['title' => 'ASC']);
        $customer = $this->em->find(Customer::class, $order->customerId());
        $invoices = $this->em->getRepository(\Modules\Orders\Domain\Invoice::class)->findBy(['orderId' => $id], ['createdAt' => 'DESC']);
        $shipments = class_exists(\Modules\Shop\Domain\Shipment::class)
            ? $this->em->getRepository(\Modules\Shop\Domain\Shipment::class)->findBy(['orderId' => $id], ['createdAt' => 'DESC'])
            : [];

        return $this->page('@orders/orders/show.html.twig', [
            'pageTitle' => $order->number(),
            'item' => $order,
            'lines' => $lines,
            'customer' => $customer instanceof Customer ? $customer : null,
            'invoices' => $invoices,
            'shipments' => $shipments,
            'productOptions' => $this->productOptions(),
        ]);
    }

    public function edit(string $id): Response
    {
        $order = $this->find($id);
        if ($order === null) {
            $this->abort(404, $this->translator->trans('orders.error.not_found'));
        }
        if (!$order->canEdit()) {
            $this->flash('error', $this->translator->trans('orders.error.not_editable'));

            return $this->redirect('admin.orders.show', ['id' => $id]);
        }

        return $this->page('@orders/orders/form.html.twig', [
            'pageTitle' => $this->translator->trans('orders.edit'),
            'item' => $order,
            'customerId' => $order->customerId(),
            'customerOptions' => $this->customerOptions(),
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $order = $this->find($id);
        if ($order === null) {
            $this->abort(404, $this->translator->trans('orders.error.not_found'));
        }
        if (!$order->canEdit()) {
            $this->flash('error', $this->translator->trans('orders.error.not_editable'));

            return $this->redirect('admin.orders.show', ['id' => $id]);
        }
        $data = $this->validated($request);
        $order->setCustomerId($data['customer_id']);
        $order->setCurrency($data['currency']);
        $order->setNote($data['note']);
        $this->em->flush();
        $this->events->emit('order.updated', ['type' => 'order', 'id' => $order->id()], $this->publicOrder($order));
        $this->auditor->log('orders.updated', 'order', $order->id());
        $this->forgetForm('orders.save');
        $this->flash('success', $this->translator->trans('orders.flash.saved', ['%number%' => $order->number()]));

        return $this->redirect('admin.orders.show', ['id' => $id]);
    }

    public function destroy(string $id): Response
    {
        $order = $this->find($id);
        if ($order === null) {
            $this->abort(404, $this->translator->trans('orders.error.not_found'));
        }
        if ($order->status() !== 'draft' && $order->status() !== 'cancelled') {
            $this->flash('error', $this->translator->trans('orders.error.cannot_delete'));

            return $this->redirect('admin.orders.show', ['id' => $id]);
        }
        $number = $order->number();
        foreach ($this->em->getRepository(OrderLine::class)->findBy(['orderId' => $id]) as $line) {
            $this->em->remove($line);
        }
        $this->em->remove($order);
        $this->em->flush();
        $this->auditor->log('orders.deleted', 'order', $id);
        $this->flash('success', $this->translator->trans('orders.flash.deleted', ['%number%' => $number]));

        return $this->redirect('admin.orders.index');
    }

    public function confirm(string $id): Response
    {
        return $this->transition($id, 'confirmed', 'order.confirmed', 'orders.flash.confirmed');
    }

    public function fulfill(string $id): Response
    {
        return $this->transition($id, 'fulfilled', 'order.fulfilled', 'orders.flash.fulfilled');
    }

    public function cancel(string $id): Response
    {
        return $this->transition($id, 'cancelled', 'order.cancelled', 'orders.flash.cancelled');
    }

    public function bulk(Request $request): Response
    {
        $data = $this->validate($request, 'orders.bulk');
        $action = (string) $data['action'];
        $ids = $request->request->all('ids');
        if (!is_array($ids)) {
            $ids = [];
        }
        $ids = array_values(array_unique(array_filter(array_map(
            static fn ($id): string => trim((string) $id),
            $ids,
        ), static fn (string $id): bool => $id !== '')));

        if ($ids === []) {
            $this->flash('error', $this->translator->trans('orders.bulk.error.empty'));

            return $this->redirect('admin.orders.index');
        }
        if ($action === 'delete' && !$request->request->getBoolean('confirmed')) {
            $this->flash('error', $this->translator->trans('orders.bulk.error.confirm'));

            return $this->redirect('admin.orders.index');
        }

        $affected = 0;
        foreach ($ids as $id) {
            $order = $this->find($id);
            if ($order === null) {
                continue;
            }

            try {
                if ($action === 'confirm') {
                    $order->setStatus('confirmed');
                    $this->events->emit('order.confirmed', ['type' => 'order', 'id' => $order->id()], $this->publicOrder($order));
                    $this->auditor->log('orders.confirmed', 'order', $order->id());
                    $affected++;
                } elseif ($action === 'fulfill') {
                    $order->setStatus('fulfilled');
                    $this->events->emit('order.fulfilled', ['type' => 'order', 'id' => $order->id()], $this->publicOrder($order));
                    $this->auditor->log('orders.fulfilled', 'order', $order->id());
                    $affected++;
                } elseif ($action === 'cancel') {
                    $order->setStatus('cancelled');
                    $this->events->emit('order.cancelled', ['type' => 'order', 'id' => $order->id()], $this->publicOrder($order));
                    $this->auditor->log('orders.cancelled', 'order', $order->id());
                    $affected++;
                } elseif ($action === 'delete') {
                    if ($order->status() !== 'draft' && $order->status() !== 'cancelled') {
                        continue;
                    }
                    foreach ($this->em->getRepository(OrderLine::class)->findBy(['orderId' => $id]) as $line) {
                        $this->em->remove($line);
                    }
                    $this->em->remove($order);
                    $this->auditor->log('orders.deleted', 'order', $id);
                    $affected++;
                }
            } catch (\InvalidArgumentException) {
                continue;
            }
        }

        $this->em->flush();
        $this->flash('success', $this->translator->trans('orders.bulk.flash.' . $action, [
            '%count%' => (string) $affected,
        ]));

        return $this->redirect('admin.orders.index');
    }

    public function addLine(Request $request, string $id): Response
    {
        $order = $this->find($id);
        if ($order === null) {
            $this->abort(404, $this->translator->trans('orders.error.not_found'));
        }
        if (!$order->canEdit()) {
            $this->flash('error', $this->translator->trans('orders.error.not_editable'));

            return $this->redirect('admin.orders.show', ['id' => $id]);
        }
        $data = $this->validate($request, 'orders.line.save');
        $title = trim((string) $data['title']);
        $productId = trim((string) ($data['product_id'] ?? ''));
        if ($productId !== '' && $this->em->find(Product::class, $productId) instanceof Product) {
            $product = $this->em->find(Product::class, $productId);
            if ($title === '') {
                $title = $product->name();
            }
        } else {
            $productId = '';
        }
        if ($title === '') {
            $this->abort(422, $this->translator->trans('orders.error.line_title'));
        }
        $line = new OrderLine(
            $id,
            $title,
            (string) $data['qty'],
            (string) $data['unit_price'],
            (string) ($data['tax_rate'] ?? '0'),
        );
        if ($productId !== '') {
            $line->setProductId($productId);
        }
        $this->em->persist($line);
        $lines = $this->em->getRepository(OrderLine::class)->findBy(['orderId' => $id]);
        $lines[] = $line;
        OrderTotals::recalculate($order, $lines);
        $this->em->flush();
        $this->events->emit('order.updated', ['type' => 'order', 'id' => $order->id()], $this->publicOrder($order));
        $this->forgetForm('orders.line.save');
        $this->flash('success', $this->translator->trans('orders.flash.line_added'));

        return $this->redirect('admin.orders.show', ['id' => $id]);
    }

    private function transition(string $id, string $status, string $event, string $flash): Response
    {
        $order = $this->find($id);
        if ($order === null) {
            $this->abort(404, $this->translator->trans('orders.error.not_found'));
        }
        try {
            $order->setStatus($status);
        } catch (\InvalidArgumentException) {
            $this->flash('error', $this->translator->trans('orders.error.invalid_status'));

            return $this->redirect('admin.orders.show', ['id' => $id]);
        }
        $this->em->flush();
        $payload = $this->publicOrder($order);
        $this->events->emit($event, ['type' => 'order', 'id' => $order->id()], $payload);
        $this->events->emit('order.updated', ['type' => 'order', 'id' => $order->id()], $payload);
        $this->auditor->log('orders.status', 'order', $id, null, ['status' => $status]);
        $this->flash('success', $this->translator->trans($flash, ['%number%' => $order->number()]));

        return $this->redirect('admin.orders.show', ['id' => $id]);
    }

    private function find(string $id): ?Order
    {
        $o = $this->em->find(Order::class, $id);

        return $o instanceof Order ? $o : null;
    }

    /** @return array<string, mixed> */
    private function publicOrder(Order $order): array
    {
        return [
            'id' => $order->id(),
            'customer_id' => $order->customerId(),
            'number' => $order->number(),
            'status' => $order->status(),
            'currency' => $order->currency(),
            'subtotal' => $order->subtotal(),
            'tax_total' => $order->taxTotal(),
            'total' => $order->total(),
            'note' => $order->note(),
            'created_at' => $order->createdAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $order->updatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @return array{customer_id: string, currency: string, note: ?string} */
    private function validated(Request $request): array
    {
        $data = $this->validate($request, 'orders.save');
        $customerId = trim((string) $data['customer_id']);
        if (!$this->em->find(Customer::class, $customerId) instanceof Customer) {
            $this->abort(422, $this->translator->trans('orders.error.invalid_customer'));
        }

        return [
            'customer_id' => $customerId,
            'currency' => strtoupper(trim((string) ($data['currency'] ?? 'CZK'))) ?: 'CZK',
            'note' => trim((string) ($data['note'] ?? '')) ?: null,
        ];
    }

    /** @param list<Order> $orders @return array<string, Customer> */
    private function customerMap(array $orders): array
    {
        $ids = array_values(array_unique(array_map(static fn (Order $o): string => $o->customerId(), $orders)));
        if ($ids === []) {
            return [];
        }
        $map = [];
        foreach ($this->em->getRepository(Customer::class)->createQueryBuilder('c')->where('c.id IN (:ids)')->setParameter('ids', $ids)->getQuery()->getResult() as $c) {
            if ($c instanceof Customer) {
                $map[$c->id()] = $c;
            }
        }

        return $map;
    }

    /** @return list<array{value: string, label: string}> */
    private function customerOptions(): array
    {
        return array_map(
            static fn (Customer $c): array => ['value' => $c->id(), 'label' => $c->name() . ($c->company() ? ' · ' . $c->company() : '')],
            $this->em->getRepository(Customer::class)->createQueryBuilder('c')->orderBy('c.name', 'ASC')->getQuery()->getResult(),
        );
    }

    /** @return list<array{value: string, label: string}> */
    private function productOptions(): array
    {
        if (!class_exists(Product::class)) {
            return [];
        }

        return array_map(
            static fn (Product $p): array => ['value' => $p->id(), 'label' => $p->sku() . ' — ' . $p->name()],
            $this->em->getRepository(Product::class)->createQueryBuilder('p')->orderBy('p.name', 'ASC')->setMaxResults(100)->getQuery()->getResult(),
        );
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
