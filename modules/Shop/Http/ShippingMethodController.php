<?php

declare(strict_types=1);

namespace Modules\Shop\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Event\EventBus;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Modules\Shop\Domain\ShippingMethod;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class ShippingMethodController extends Controller
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
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $list = \Gereq\Http\ListQuery::fromRequest(
            $request,
            sortable: ['sortOrder', 'code', 'name', 'price'],
            defaultSort: 'sortOrder',
            defaultDir: 'asc',
            perPage: 100,
            filterKeys: ['active', 'type', 'carrier'],
        );
        $active = $list->filter('active');
        $type = $list->filter('type');
        $carrier = $list->filter('carrier');

        $qb = $this->em->getRepository(ShippingMethod::class)->createQueryBuilder('s');
        $list->applySearch($qb, 's', ['code', 'name', 'carrier']);
        if ($active === '1') {
            $qb->andWhere('s.active = true');
        } elseif ($active === '0') {
            $qb->andWhere('s.active = false');
        }
        if ($type !== '' && in_array($type, ShippingMethod::TYPES, true)) {
            $qb->andWhere('s.type = :type')->setParameter('type', $type);
        }
        if ($carrier !== '') {
            $qb->andWhere('LOWER(s.carrier) = :carrier')->setParameter('carrier', mb_strtolower($carrier));
        }
        $qb->orderBy('s.' . $list->sort, $list->direction());
        if ($list->sort === 'sortOrder') {
            $qb->addOrderBy('s.code', 'ASC');
        }

        $total = (int) (clone $qb)->select('COUNT(s.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        /** @var list<ShippingMethod> $items */
        $items = $qb->setFirstResult($list->offset())->setMaxResults($list->perPage)->getQuery()->getResult();

        $carrierRows = $this->em->createQueryBuilder()
            ->select('DISTINCT s.carrier AS carrier')
            ->from(ShippingMethod::class, 's')
            ->where('s.carrier IS NOT NULL')
            ->andWhere("s.carrier <> ''")
            ->orderBy('s.carrier', 'ASC')
            ->getQuery()
            ->getScalarResult();
        $carriers = array_values(array_filter(array_map(
            static fn (array $row): string => (string) ($row['carrier'] ?? ''),
            $carrierRows,
        )));

        return $this->page('@shop/shipping/index.html.twig', array_merge($list->viewData($total), [
            'pageTitle' => $this->translator->trans('shop.shipping.title'),
            'items' => $items,
            'active' => $active,
            'type' => $type,
            'carrier' => $carrier,
            'types' => ShippingMethod::TYPES,
            'carriers' => $carriers,
        ]));
    }

    public function create(): Response
    {
        return $this->page('@shop/shipping/form.html.twig', [
            'pageTitle' => $this->translator->trans('shop.shipping.new'),
            'item' => null,
            'types' => ShippingMethod::TYPES,
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validated($request);
        $existing = $this->em->getRepository(ShippingMethod::class)->findOneBy(['code' => $data['code']]);
        if ($existing instanceof ShippingMethod) {
            $this->abort(422, $this->translator->trans('shop.error.shipping_code_taken'));
        }

        $method = new ShippingMethod($data['code'], $data['name'], $data['price'], $data['currency']);
        $method->setActive($data['active']);
        $method->setEstimatedDays($data['estimated_days']);
        $method->setCarrier($data['carrier']);
        $method->setType($data['type']);
        $method->setDescription($data['description']);
        $method->setSortOrder($data['sort_order']);
        $this->em->persist($method);
        $this->em->flush();

        $payload = $this->publicMethod($method);
        $this->events->emit('shipping.method_created', ['type' => 'shipping_method', 'id' => $method->id()], $payload);
        $this->auditor->log('shop.shipping.created', 'shipping_method', $method->id(), null, [
            'code' => $method->code(),
            'name' => $method->name(),
        ]);
        $this->forgetForm('shop.shipping.save');
        $this->flash('success', $this->translator->trans('shop.flash.shipping_created', ['%name%' => $method->name()]));

        return $this->redirect('admin.shop.shipping.index');
    }

    public function edit(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('shop.error.shipping_not_found'));
        }

        return $this->page('@shop/shipping/form.html.twig', [
            'pageTitle' => $this->translator->trans('shop.shipping.edit'),
            'item' => $item,
            'types' => ShippingMethod::TYPES,
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('shop.error.shipping_not_found'));
        }

        $data = $this->validated($request);
        $dup = $this->em->getRepository(ShippingMethod::class)->findOneBy(['code' => $data['code']]);
        if ($dup instanceof ShippingMethod && $dup->id() !== $item->id()) {
            $this->abort(422, $this->translator->trans('shop.error.shipping_code_taken'));
        }

        $old = ['code' => $item->code(), 'name' => $item->name()];
        $item->setCode($data['code']);
        $item->setName($data['name']);
        $item->setPrice($data['price']);
        $item->setCurrency($data['currency']);
        $item->setActive($data['active']);
        $item->setEstimatedDays($data['estimated_days']);
        $item->setCarrier($data['carrier']);
        $item->setType($data['type']);
        $item->setDescription($data['description']);
        $item->setSortOrder($data['sort_order']);
        $this->em->flush();

        $payload = $this->publicMethod($item);
        $this->events->emit('shipping.method_updated', ['type' => 'shipping_method', 'id' => $item->id()], $payload);
        $this->auditor->log('shop.shipping.updated', 'shipping_method', $item->id(), $old, [
            'code' => $item->code(),
            'name' => $item->name(),
        ]);
        $this->forgetForm('shop.shipping.save');
        $this->flash('success', $this->translator->trans('shop.flash.shipping_saved'));

        return $this->redirect('admin.shop.shipping.index');
    }

    public function destroy(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('shop.error.shipping_not_found'));
        }

        $name = $item->name();
        $payload = $this->publicMethod($item);
        $this->em->remove($item);
        $this->em->flush();
        $this->events->emit('shipping.method_deleted', ['type' => 'shipping_method', 'id' => $id], $payload);
        $this->auditor->log('shop.shipping.deleted', 'shipping_method', $id);
        $this->flash('success', $this->translator->trans('shop.flash.shipping_deleted', ['%name%' => $name]));

        return $this->redirect('admin.shop.shipping.index');
    }

    public function toggle(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('shop.error.shipping_not_found'));
        }
        $item->setActive(!$item->isActive());
        $this->em->flush();
        $this->events->emit('shipping.method_updated', ['type' => 'shipping_method', 'id' => $item->id()], $this->publicMethod($item));
        $this->auditor->log('shop.shipping.toggled', 'shipping_method', $item->id(), null, [
            'active' => $item->isActive(),
        ]);
        $message = $this->translator->trans(
            $item->isActive() ? 'shop.flash.shipping_activated' : 'shop.flash.shipping_deactivated'
        );
        if (!$this->wantsJson()) {
            $this->flash('success', $message);
        }

        return $this->toggleResponse($item->isActive(), 'admin.shop.shipping.index', [], $message);
    }

    public function bulk(Request $request): Response
    {
        $data = $this->validate($request, 'shop.shipping.bulk');
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
            $this->flash('error', $this->translator->trans('shop.bulk.error.empty'));

            return $this->redirect('admin.shop.shipping.index');
        }
        if ($action === 'delete' && !$request->request->getBoolean('confirmed')) {
            $this->flash('error', $this->translator->trans('shop.bulk.error.confirm'));

            return $this->redirect('admin.shop.shipping.index');
        }

        $affected = 0;
        foreach ($ids as $id) {
            $item = $this->find($id);
            if ($item === null) {
                continue;
            }
            if ($action === 'activate') {
                $item->setActive(true);
                $affected++;
            } elseif ($action === 'deactivate') {
                $item->setActive(false);
                $affected++;
            } elseif ($action === 'delete') {
                $payload = $this->publicMethod($item);
                $this->em->remove($item);
                $this->events->emit('shipping.method_deleted', ['type' => 'shipping_method', 'id' => $id], $payload);
                $this->auditor->log('shop.shipping.deleted', 'shipping_method', $id);
                $affected++;
            }
        }
        $this->em->flush();
        $this->flash('success', $this->translator->trans('shop.shipping.bulk.flash.' . $action, [
            '%count%' => (string) $affected,
        ]));

        return $this->redirect('admin.shop.shipping.index');
    }

    private function find(string $id): ?ShippingMethod
    {
        $item = $this->em->find(ShippingMethod::class, $id);

        return $item instanceof ShippingMethod ? $item : null;
    }

    /** @return array{code: string, name: string, price: string, currency: string, active: bool, estimated_days: ?int, carrier: ?string, type: string, description: ?string, sort_order: int} */
    private function validated(Request $request): array
    {
        $data = $this->validate($request, 'shop.shipping.save');
        $activeRaw = $data['active'] ?? $request->request->get('active');
        $daysRaw = trim((string) ($data['estimated_days'] ?? ''));
        $carrier = trim((string) ($data['carrier'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        $type = trim((string) ($data['type'] ?? 'courier'));

        return [
            'code' => strtoupper(trim((string) $data['code'])),
            'name' => trim((string) $data['name']),
            'price' => number_format((float) ($data['price'] ?? 0), 2, '.', ''),
            'currency' => strtoupper(trim((string) ($data['currency'] ?? 'CZK'))) ?: 'CZK',
            'active' => $activeRaw === true || $activeRaw === '1' || $activeRaw === 1 || $activeRaw === 'on',
            'estimated_days' => $daysRaw === '' ? null : (int) $daysRaw,
            'carrier' => $carrier === '' ? null : $carrier,
            'type' => $type !== '' ? $type : 'courier',
            'description' => $description === '' ? null : $description,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
    }

    /** @return array<string, mixed> */
    private function publicMethod(ShippingMethod $method): array
    {
        return [
            'id' => $method->id(),
            'code' => $method->code(),
            'name' => $method->name(),
            'carrier' => $method->carrier(),
            'type' => $method->type(),
            'description' => $method->description(),
            'price' => $method->price(),
            'currency' => $method->currency(),
            'active' => $method->isActive(),
            'estimated_days' => $method->estimatedDays(),
            'sort_order' => $method->sortOrder(),
            'created_at' => $method->createdAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $method->updatedAt()->format(\DateTimeInterface::ATOM),
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
