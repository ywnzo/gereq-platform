<?php

declare(strict_types=1);

namespace Modules\Returns\Http\Api;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\ApiQuery;
use Gereq\Api\ApiResponse;
use Gereq\Event\EventBus;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Validation\ValidationRegistry;
use Modules\Returns\Application\ReturnsProvider;
use Modules\Returns\Domain\ReturnItem;
use Modules\Returns\Domain\ReturnRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

final class ReturnApiController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly EventBus $events,
        private readonly ReturnsProvider $provider,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): JsonResponse
    {
        $query = ApiQuery::fromRequest($request, sortable: ['status', 'number', 'createdAt'], filterable: ['status', 'order_id']);
        $qb = $this->em->getRepository(ReturnRequest::class)->createQueryBuilder('r');
        self_repair_search($qb, 'r', ['number', 'orderId'], $query->search);
        if (!empty($query->filters['status'])) {
            $qb->andWhere('r.status = :s')->setParameter('s', $query->filters['status']);
        }
        if (!empty($query->filters['order_id'])) {
            $qb->andWhere('r.orderId = :o')->setParameter('o', $query->filters['order_id']);
        }
        $sort = $query->sort ?? 'createdAt';
        $qb->orderBy('r.' . $sort, strtoupper($query->direction));
        $total = (int) (clone $qb)->select('COUNT(r.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult($query->offset())->setMaxResults($query->perPage)->getQuery()->getResult();

        return ApiResponse::collection(array_map(fn (ReturnRequest $r): array => $this->ser($r), $items), $query->meta($total));
    }

    public function store(Request $request): JsonResponse
    {
        $data = gereq()->make(ValidationRegistry::class)->validate('returns.save', $this->body($request));
        $ret = new ReturnRequest(
            $this->provider->nextNumber(),
            trim((string) $data['order_id']),
            trim((string) $data['reason']),
        );
        if (!empty($data['customer_id'])) {
            $ret->setCustomerId((string) $data['customer_id']);
        }
        if (!empty($data['resolution'])) {
            $ret->setResolution((string) $data['resolution']);
        }
        if (isset($data['refund_amount'])) {
            $ret->setRefundAmount((string) $data['refund_amount']);
        }
        if (!empty($data['currency'])) {
            $ret->setCurrency((string) $data['currency']);
        }
        if (isset($data['note'])) {
            $ret->setNote((string) $data['note']);
        }
        $this->em->persist($ret);

        $items = $data['items'] ?? [];
        if (is_array($items)) {
            foreach ($items as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $title = trim((string) ($row['title'] ?? ''));
                if ($title === '') {
                    continue;
                }
                $line = new ReturnItem($ret->id(), $title, max(1, (int) ($row['qty'] ?? 1)));
                $line->setProductId(isset($row['product_id']) ? (string) $row['product_id'] : null);
                $line->setReason(isset($row['reason']) ? (string) $row['reason'] : null);
                $this->em->persist($line);
            }
        }

        $this->em->flush();
        $payload = $this->ser($ret, true);
        $this->events->emit('return.created', ['type' => 'return_request', 'id' => $ret->id()], $payload);

        return ApiResponse::data($payload, 201);
    }

    public function show(string $id): JsonResponse
    {
        $ret = $this->provider->find($id);
        if ($ret === null) {
            return ApiResponse::notFound();
        }

        return ApiResponse::data($this->ser($ret, true));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $ret = $this->provider->find($id);
        if ($ret === null) {
            return ApiResponse::notFound();
        }
        $data = gereq()->make(ValidationRegistry::class)->validate('returns.save', $this->body($request));
        $ret->setOrderId(trim((string) $data['order_id']));
        $ret->setReason(trim((string) $data['reason']));
        if (array_key_exists('customer_id', $data)) {
            $ret->setCustomerId(trim((string) $data['customer_id']) ?: null);
        }
        if (array_key_exists('resolution', $data)) {
            $ret->setResolution(trim((string) ($data['resolution'] ?? '')) ?: null);
        }
        if (array_key_exists('refund_amount', $data)) {
            $ret->setRefundAmount(isset($data['refund_amount']) ? (string) $data['refund_amount'] : null);
        }
        if (!empty($data['currency'])) {
            $ret->setCurrency((string) $data['currency']);
        }
        if (array_key_exists('note', $data)) {
            $ret->setNote(trim((string) ($data['note'] ?? '')) ?: null);
        }
        $this->em->flush();
        $payload = $this->ser($ret, true);
        $this->events->emit('return.updated', ['type' => 'return_request', 'id' => $id], $payload);

        return ApiResponse::data($payload);
    }

    public function destroy(string $id): JsonResponse
    {
        $ret = $this->provider->find($id);
        if ($ret === null) {
            return ApiResponse::notFound();
        }
        foreach ($this->em->getRepository(ReturnItem::class)->findBy(['returnId' => $id]) as $line) {
            $this->em->remove($line);
        }
        $this->em->remove($ret);
        $this->em->flush();
        $this->events->emit('return.deleted', ['type' => 'return_request', 'id' => $id], ['id' => $id]);

        return ApiResponse::data(['id' => $id, 'deleted' => true]);
    }

    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $ret = $this->provider->find($id);
        if ($ret === null) {
            return ApiResponse::notFound();
        }
        $data = gereq()->make(ValidationRegistry::class)->validate('returns.status', $this->body($request));
        $ret->setStatus((string) $data['status']);
        $body = $this->body($request);
        if (isset($body['resolution'])) {
            $ret->setResolution(trim((string) $body['resolution']) ?: null);
        }
        if (array_key_exists('refund_amount', $body)) {
            $ret->setRefundAmount($body['refund_amount'] !== null ? (string) $body['refund_amount'] : null);
        }
        $this->em->flush();
        $payload = $this->ser($ret, true);
        $this->events->emit('return.status_changed', ['type' => 'return_request', 'id' => $id], $payload);

        return ApiResponse::data($payload);
    }

    /** @return array<string, mixed> */
    private function ser(ReturnRequest $r, bool $withItems = false): array
    {
        $data = [
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
        if ($withItems) {
            $data['items'] = array_map(static fn (ReturnItem $i): array => [
                'id' => $i->id(),
                'product_id' => $i->productId(),
                'title' => $i->title(),
                'qty' => $i->qty(),
                'reason' => $i->reason(),
            ], $this->em->getRepository(ReturnItem::class)->findBy(['returnId' => $r->id()]));
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function body(Request $r): array
    {
        $d = $r->request->all();
        $raw = $r->getContent();
        if (is_string($raw) && $raw !== '') {
            $j = json_decode($raw, true);
            if (is_array($j)) {
                return array_merge($d, $j);
            }
        }

        return $d;
    }
}
