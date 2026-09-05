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
use Modules\Orders\Application\NumberSequencer;
use Modules\Orders\Domain\Invoice;
use Modules\Orders\Domain\InvoicePayment;
use Modules\Orders\Domain\Order;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

final class InvoiceApiController extends Controller
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
        $query = ApiQuery::fromRequest($request, sortable: ['number', 'status', 'total', 'createdAt'], filterable: ['status', 'order_id']);
        $qb = $this->em->getRepository(Invoice::class)->createQueryBuilder('i');
        self_repair_search($qb, 'i', ['number'], $query->search);
        if (isset($query->filters['status']) && $query->filters['status'] !== '') {
            $qb->andWhere('i.status = :status')->setParameter('status', $query->filters['status']);
        }
        if (isset($query->filters['order_id']) && $query->filters['order_id'] !== '') {
            $qb->andWhere('i.orderId = :oid')->setParameter('oid', $query->filters['order_id']);
        }
        $sort = $query->sort ?? 'createdAt';
        $qb->orderBy('i.' . $sort, strtoupper($query->direction));
        $total = (int) (clone $qb)->select('COUNT(i.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult($query->offset())->setMaxResults($query->perPage)->getQuery()->getResult();

        return ApiResponse::collection(array_map(fn (Invoice $i): array => $this->serialize($i), $items), $query->meta($total));
    }

    public function store(Request $request): JsonResponse
    {
        $body = $this->body($request);
        $orderId = trim((string) ($body['order_id'] ?? ''));
        $order = $this->em->find(Order::class, $orderId);
        if (!$order instanceof Order) {
            return ApiResponse::error('validation_error', 'Invalid order', ['order_id' => 'not_found'], 422);
        }
        $invoice = new Invoice($orderId, $this->numbers->nextInvoiceNumber(), $order->total());
        $this->em->persist($invoice);
        $this->em->flush();

        return ApiResponse::data($this->serialize($invoice), 201);
    }

    public function show(string $id): JsonResponse
    {
        $invoice = $this->em->find(Invoice::class, $id);
        if (!$invoice instanceof Invoice) {
            return ApiResponse::notFound();
        }

        return ApiResponse::data($this->serialize($invoice));
    }

    public function issue(Request $request, string $id): JsonResponse
    {
        $invoice = $this->em->find(Invoice::class, $id);
        if (!$invoice instanceof Invoice) {
            return ApiResponse::notFound();
        }
        if ($invoice->status() !== 'draft') {
            return ApiResponse::error('conflict', 'Invoice not draft', [], 409);
        }
        $body = $this->body($request);
        $dueRaw = trim((string) ($body['due_at'] ?? ''));
        $dueAt = $dueRaw !== '' ? new \DateTimeImmutable($dueRaw) : (new \DateTimeImmutable())->modify('+14 days');
        $invoice->issue(null, $dueAt);
        $this->em->flush();
        $payload = $this->serialize($invoice);
        $this->events->emit('invoice.issued', ['type' => 'invoice', 'id' => $invoice->id()], $payload);

        return ApiResponse::data($payload);
    }

    public function pay(Request $request, string $id): JsonResponse
    {
        $invoice = $this->em->find(Invoice::class, $id);
        if (!$invoice instanceof Invoice) {
            return ApiResponse::notFound();
        }
        $data = gereq()->make(ValidationRegistry::class)->validate('orders.payment.save', $this->body($request));
        $payment = new InvoicePayment($id, (string) $data['amount'], trim((string) $data['method']));
        $this->em->persist($payment);
        $paid = (float) $this->em->createQueryBuilder()
            ->select('COALESCE(SUM(p.amount), 0)')
            ->from(InvoicePayment::class, 'p')
            ->where('p.invoiceId = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getSingleScalarResult();
        $paid += (float) $payment->amount();
        if ($paid + 0.001 >= (float) $invoice->total()) {
            $invoice->markPaid();
            $this->em->flush();
            $this->events->emit('invoice.paid', ['type' => 'invoice', 'id' => $invoice->id()], $this->serialize($invoice));
        } else {
            $this->em->flush();
        }

        return ApiResponse::data([
            'payment' => [
                'id' => $payment->id(),
                'amount' => $payment->amount(),
                'method' => $payment->method(),
                'paid_at' => $payment->paidAt()->format(\DateTimeInterface::ATOM),
            ],
            'invoice' => $this->serialize($invoice),
        ], 201);
    }

    /** @return array<string, mixed> */
    private function serialize(Invoice $invoice): array
    {
        return [
            'id' => $invoice->id(),
            'order_id' => $invoice->orderId(),
            'number' => $invoice->number(),
            'status' => $invoice->status(),
            'issued_at' => $invoice->issuedAt()?->format(\DateTimeInterface::ATOM),
            'due_at' => $invoice->dueAt()?->format('Y-m-d'),
            'total' => $invoice->total(),
            'created_at' => $invoice->createdAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $invoice->updatedAt()->format(\DateTimeInterface::ATOM),
        ];
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
