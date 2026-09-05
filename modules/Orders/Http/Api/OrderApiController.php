<?php

declare(strict_types=1);

namespace Modules\Orders\Http\Api;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\ApiQuery;
use Gereq\Api\ApiResponse;
use Gereq\Event\EventBus;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Validation\ValidationRegistry;
use Modules\Customers\Domain\Customer;
use Modules\Orders\Application\NumberSequencer;
use Modules\Orders\Application\OrderTotals;
use Modules\Orders\Domain\Order;
use Modules\Orders\Domain\OrderLine;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

final class OrderApiController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly EventBus $events,
        private readonly NumberSequencer $numbers,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): JsonResponse
    {
        $query = ApiQuery::fromRequest($request, sortable: ['number', 'status', 'total', 'createdAt'], filterable: ['status', 'customer_id', 'currency']);
        $qb = $this->em->getRepository(Order::class)->createQueryBuilder('o');
        self_repair_search($qb, 'o', ['number', 'note'], $query->search);
        foreach (['status' => 'status', 'customer_id' => 'customerId', 'currency' => 'currency'] as $filter => $field) {
            if (isset($query->filters[$filter]) && $query->filters[$filter] !== '') {
                $qb->andWhere('o.' . $field . ' = :' . $filter)->setParameter($filter, $query->filters[$filter]);
            }
        }
        $sort = $query->sort ?? 'createdAt';
        $qb->orderBy('o.' . $sort, strtoupper($query->direction));
        $total = (int) (clone $qb)->select('COUNT(o.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult($query->offset())->setMaxResults($query->perPage)->getQuery()->getResult();

        return ApiResponse::collection(array_map(fn (Order $o): array => $this->serialize($o), $items), $query->meta($total));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateBody($request, 'orders.save');
        $customerId = trim((string) $data['customer_id']);
        if (!$this->em->find(Customer::class, $customerId) instanceof Customer) {
            return ApiResponse::error('validation_error', 'Invalid customer', ['customer_id' => 'not_found'], 422);
        }
        $order = new Order($customerId, $this->numbers->nextOrderNumber(), strtoupper(trim((string) ($data['currency'] ?? 'CZK'))) ?: 'CZK');
        $order->setNote(trim((string) ($data['note'] ?? '')) ?: null);
        $this->em->persist($order);
        $this->em->flush();
        $payload = $this->serialize($order);
        $this->events->emit('order.created', ['type' => 'order', 'id' => $order->id()], $payload);

        return ApiResponse::data($payload, 201);
    }

    public function show(string $id): JsonResponse
    {
        $order = $this->em->find(Order::class, $id);
        if (!$order instanceof Order) {
            return ApiResponse::notFound();
        }
        $payload = $this->serialize($order);
        $payload['lines'] = array_map(fn (OrderLine $l): array => $this->serializeLine($l), $this->em->getRepository(OrderLine::class)->findBy(['orderId' => $id]));

        return ApiResponse::data($payload);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $order = $this->em->find(Order::class, $id);
        if (!$order instanceof Order) {
            return ApiResponse::notFound();
        }
        if (!$order->canEdit()) {
            return ApiResponse::error('conflict', 'Order not editable', [], 409);
        }
        $data = $this->validateBody($request, 'orders.save');
        $customerId = trim((string) $data['customer_id']);
        if (!$this->em->find(Customer::class, $customerId) instanceof Customer) {
            return ApiResponse::error('validation_error', 'Invalid customer', ['customer_id' => 'not_found'], 422);
        }
        $order->setCustomerId($customerId);
        $order->setCurrency(strtoupper(trim((string) ($data['currency'] ?? 'CZK'))) ?: 'CZK');
        $order->setNote(trim((string) ($data['note'] ?? '')) ?: null);
        $this->em->flush();
        $payload = $this->serialize($order);
        $this->events->emit('order.updated', ['type' => 'order', 'id' => $order->id()], $payload);

        return ApiResponse::data($payload);
    }

    public function destroy(string $id): JsonResponse
    {
        $order = $this->em->find(Order::class, $id);
        if (!$order instanceof Order) {
            return ApiResponse::notFound();
        }
        foreach ($this->em->getRepository(OrderLine::class)->findBy(['orderId' => $id]) as $line) {
            $this->em->remove($line);
        }
        $this->em->remove($order);
        $this->em->flush();

        return ApiResponse::data(['id' => $id, 'deleted' => true]);
    }

    public function confirm(string $id): JsonResponse
    {
        return $this->transition($id, 'confirmed', 'order.confirmed');
    }

    public function fulfill(string $id): JsonResponse
    {
        return $this->transition($id, 'fulfilled', 'order.fulfilled');
    }

    public function cancel(string $id): JsonResponse
    {
        return $this->transition($id, 'cancelled', 'order.cancelled');
    }

    public function addLine(Request $request, string $id): JsonResponse
    {
        $order = $this->em->find(Order::class, $id);
        if (!$order instanceof Order) {
            return ApiResponse::notFound();
        }
        if (!$order->canEdit()) {
            return ApiResponse::error('conflict', 'Order not editable', [], 409);
        }
        $data = $this->validateBody($request, 'orders.line.save');
        $line = new OrderLine($id, trim((string) $data['title']), (string) $data['qty'], (string) $data['unit_price'], (string) ($data['tax_rate'] ?? '0'));
        if (!empty($data['product_id'])) {
            $line->setProductId((string) $data['product_id']);
        }
        $this->em->persist($line);
        $lines = $this->em->getRepository(OrderLine::class)->findBy(['orderId' => $id]);
        $lines[] = $line;
        OrderTotals::recalculate($order, $lines);
        $this->em->flush();
        $this->events->emit('order.updated', ['type' => 'order', 'id' => $order->id()], $this->serialize($order));

        return ApiResponse::data($this->serializeLine($line), 201);
    }

    private function transition(string $id, string $status, string $event): JsonResponse
    {
        $order = $this->em->find(Order::class, $id);
        if (!$order instanceof Order) {
            return ApiResponse::notFound();
        }
        $order->setStatus($status);
        $this->em->flush();
        $payload = $this->serialize($order);
        $this->events->emit($event, ['type' => 'order', 'id' => $order->id()], $payload);
        $this->events->emit('order.updated', ['type' => 'order', 'id' => $order->id()], $payload);

        return ApiResponse::data($payload);
    }

    /** @return array<string, mixed> */
    private function serialize(Order $order): array
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

    /** @return array<string, mixed> */
    private function serializeLine(OrderLine $line): array
    {
        return [
            'id' => $line->id(),
            'order_id' => $line->orderId(),
            'product_id' => $line->productId(),
            'title' => $line->title(),
            'qty' => $line->qty(),
            'unit_price' => $line->unitPrice(),
            'tax_rate' => $line->taxRate(),
            'line_total' => $line->lineTotal(),
        ];
    }

    /** @param array<string, mixed> $context @return array<string, mixed> */
    private function validateBody(Request $request, string $ruleset, array $context = []): array
    {
        return gereq()->make(ValidationRegistry::class)->validate($ruleset, $this->body($request), $context);
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        $data = $request->request->all();
        $raw = $request->getContent();
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return array_merge($data, $decoded);
            }
        }

        return $data;
    }
}
