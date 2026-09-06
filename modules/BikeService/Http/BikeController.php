<?php

declare(strict_types=1);

namespace Modules\BikeService\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Event\EventBus;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Modules\BikeService\Application\BikeIndexClient;
use Modules\BikeService\Application\BikeServiceProvider;
use Modules\BikeService\Domain\Bike;
use Modules\BikeService\Domain\BikeOrder;
use Modules\Customers\Domain\Customer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class BikeController extends Controller
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
        private readonly BikeServiceProvider $bikeservice,
        private readonly BikeIndexClient $bikeIndex,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $list = \Gereq\Http\ListQuery::fromRequest(
            $request,
            sortable: ['brand', 'status', 'kind', 'updatedAt', 'createdAt'],
            defaultSort: 'updatedAt',
            defaultDir: 'desc',
            filterKeys: ['status', 'kind'],
        );
        $status = $list->filter('status');
        $kind = $list->filter('kind');

        $qb = $this->em->getRepository(Bike::class)->createQueryBuilder('b');
        $list->applySearch($qb, 'b', ['brand', 'model', 'serialNumber', 'color']);
        if (in_array($status, Bike::STATUSES, true)) {
            $qb->andWhere('b.status = :s')->setParameter('s', $status);
        }
        if (in_array($kind, Bike::KINDS, true)) {
            $qb->andWhere('b.kind = :k')->setParameter('k', $kind);
        }
        $qb->orderBy('b.' . $list->sort, $list->direction());

        $total = (int) (clone $qb)->select('COUNT(b.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult($list->offset())->setMaxResults($list->perPage)->getQuery()->getResult();

        return $this->page('@bikeservice/bikes/index.html.twig', array_merge($list->viewData($total), [
            'pageTitle' => $this->translator->trans('bikeservice.bikes.title'),
            'items' => $items,
            'customers' => $this->customerMap($items),
            'status' => $status,
            'kind' => $kind,
            'statuses' => Bike::STATUSES,
            'kinds' => Bike::KINDS,
        ]));
    }

    public function create(Request $request): Response
    {
        return $this->page('@bikeservice/bikes/form.html.twig', [
            'pageTitle' => $this->translator->trans('bikeservice.bikes.new'),
            'item' => null,
            'customerId' => (string) $request->query->get('customer', ''),
            'brandPrefill' => trim((string) $request->query->get('brand', '')),
            'customerOptions' => $this->customerOptions(),
            'kinds' => Bike::KINDS,
            'statuses' => Bike::STATUSES,
            'serialLookup' => null,
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validated($request);
        try {
            $bike = $this->bikeservice->createBike($data);
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $this->translator->trans($e->getMessage()));

            return $this->redirectBack('admin.bikeservice.bikes.create');
        }
        $this->auditor->log('bikeservice.bike_created', 'bikeservice_bike', $bike->id());
        $this->events->emit('bikeservice.bike_created', ['type' => 'bikeservice_bike', 'id' => $bike->id()], $this->bikeservice->serializeBike($bike));
        $this->flash('success', $this->translator->trans('bikeservice.flash.bike_created'));

        return $this->redirect('admin.bikeservice.bikes.show', ['id' => $bike->id()]);
    }

    public function show(string $id): Response
    {
        $bike = $this->bikeservice->getBike($id);
        if ($bike === null) {
            $this->abort(404);
        }
        $orders = $this->bikeservice->listForBike($bike->id());
        $customer = $bike->customerId() !== null
            ? $this->em->find(Customer::class, $bike->customerId())
            : null;

        $serialHits = null;
        if ($bike->serialNumber() !== null && $bike->serialNumber() !== '') {
            $serialHits = $this->bikeIndex->searchBySerial($bike->serialNumber(), 5);
        }

        return $this->page('@bikeservice/bikes/show.html.twig', [
            'pageTitle' => $bike->label(),
            'item' => $bike,
            'customer' => $customer instanceof Customer ? $customer : null,
            'orders' => $orders,
            'serialHits' => $serialHits,
        ]);
    }

    public function edit(string $id): Response
    {
        $bike = $this->bikeservice->getBike($id);
        if ($bike === null) {
            $this->abort(404);
        }

        return $this->page('@bikeservice/bikes/form.html.twig', [
            'pageTitle' => $this->translator->trans('bikeservice.bikes.edit'),
            'item' => $bike,
            'customerId' => $bike->customerId() ?? '',
            'customerOptions' => $this->customerOptions(),
            'kinds' => Bike::KINDS,
            'statuses' => Bike::STATUSES,
            'serialLookup' => null,
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $bike = $this->bikeservice->getBike($id);
        if ($bike === null) {
            $this->abort(404);
        }
        $data = $this->validated($request);
        try {
            $this->bikeservice->updateBike($bike, $data);
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $this->translator->trans($e->getMessage()));

            return $this->redirect('admin.bikeservice.bikes.edit', ['id' => $id]);
        }
        $this->auditor->log('bikeservice.bike_updated', 'bikeservice_bike', $bike->id());
        $this->events->emit('bikeservice.bike_updated', ['type' => 'bikeservice_bike', 'id' => $bike->id()], $this->bikeservice->serializeBike($bike));
        $this->flash('success', $this->translator->trans('bikeservice.flash.bike_updated'));

        return $this->redirect('admin.bikeservice.bikes.show', ['id' => $bike->id()]);
    }

    public function destroy(string $id): Response
    {
        $bike = $this->bikeservice->getBike($id);
        if ($bike === null) {
            $this->abort(404);
        }
        $open = $this->em->getRepository(BikeOrder::class)->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->where('o.bikeId = :b')
            ->andWhere('o.status NOT IN (:done)')
            ->setParameter('b', $id)
            ->setParameter('done', ['done', 'cancelled'])
            ->getQuery()
            ->getSingleScalarResult();
        if ((int) $open > 0) {
            $this->flash('error', $this->translator->trans('bikeservice.error.bike_has_orders'));

            return $this->redirect('admin.bikeservice.bikes.show', ['id' => $id]);
        }
        $this->em->remove($bike);
        $this->em->flush();
        $this->auditor->log('bikeservice.bike_deleted', 'bikeservice_bike', $id);
        $this->flash('success', $this->translator->trans('bikeservice.flash.bike_deleted'));

        return $this->redirect('admin.bikeservice.bikes');
    }

    public function lookupSerial(Request $request): Response
    {
        $serial = trim((string) $request->request->get('serial', $request->query->get('serial', '')));
        $result = $this->bikeIndex->searchBySerial($serial, 10);
        if ($this->wantsJson($request)) {
            return $this->json($result);
        }
        $this->flash(
            $result['ok'] ? 'success' : 'error',
            $result['ok']
                ? $this->translator->trans('bikeservice.explore.serial_found', ['%count%' => (string) count($result['bikes'])])
                : $this->translator->trans((string) ($result['error'] ?? 'bikeservice.error.api_request_failed')),
        );

        return $this->redirectTo($this->router->url('admin.bikeservice.explore') . '?tab=serial&serial=' . rawurlencode($serial));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $this->validate($request, 'bikeservice.bike');
        $customerId = trim((string) ($data['customer_id'] ?? ''));
        if ($customerId !== '' && !$this->em->find(Customer::class, $customerId) instanceof Customer) {
            $this->abort(422, $this->translator->trans('bikeservice.error.invalid_customer'));
        }

        return [
            'brand' => trim((string) $data['brand']),
            'model' => trim((string) ($data['model'] ?? '')) ?: null,
            'kind' => in_array(($data['kind'] ?? 'bike'), Bike::KINDS, true) ? (string) $data['kind'] : 'bike',
            'status' => in_array(($data['status'] ?? 'active'), Bike::STATUSES, true) ? (string) $data['status'] : 'active',
            'serial_number' => trim((string) ($data['serial_number'] ?? '')) ?: null,
            'color' => trim((string) ($data['color'] ?? '')) ?: null,
            'frame_size' => trim((string) ($data['frame_size'] ?? '')) ?: null,
            'battery_wh' => trim((string) ($data['battery_wh'] ?? '')) !== '' ? (int) $data['battery_wh'] : null,
            'motor_w' => trim((string) ($data['motor_w'] ?? '')) !== '' ? (int) $data['motor_w'] : null,
            'odometer_km' => trim((string) ($data['odometer_km'] ?? '')) !== '' ? (int) $data['odometer_km'] : null,
            'customer_id' => $customerId !== '' ? $customerId : null,
            'bike_index_id' => trim((string) ($data['bike_index_id'] ?? '')) !== '' ? (int) $data['bike_index_id'] : null,
            'note' => trim((string) ($data['note'] ?? '')) ?: null,
        ];
    }

    /** @param list<Bike> $items @return array<string, Customer> */
    private function customerMap(array $items): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn (Bike $b): ?string => $b->customerId(), $items))));
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
        $opts = [['value' => '', 'label' => '—']];
        foreach ($this->em->getRepository(Customer::class)->createQueryBuilder('c')->orderBy('c.name', 'ASC')->getQuery()->getResult() as $c) {
            if ($c instanceof Customer) {
                $opts[] = ['value' => $c->id(), 'label' => $c->name()];
            }
        }

        return $opts;
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
