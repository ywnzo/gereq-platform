<?php

declare(strict_types=1);

namespace Modules\Warehouse\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Modules\Products\Domain\Product;
use Modules\Warehouse\Application\StockService;
use Modules\Warehouse\Domain\StockItem;
use Modules\Warehouse\Domain\StockMovement;
use Modules\Warehouse\Domain\Warehouse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class StockController extends Controller
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
        private readonly StockService $stock,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $warehouseId = (string) $request->query->get('warehouse', '');
        $productId = (string) $request->query->get('product', '');
        $qb = $this->em->getRepository(StockItem::class)->createQueryBuilder('s')
            ->orderBy('s.updatedAt', 'DESC');
        if ($warehouseId !== '') {
            $qb->andWhere('s.warehouseId = :wid')->setParameter('wid', $warehouseId);
        }
        if ($productId !== '') {
            $qb->andWhere('s.productId = :pid')->setParameter('pid', $productId);
        }

        /** @var list<StockItem> $items */
        $items = $qb->getQuery()->getResult();

        return $this->page('@warehouse/stock/index.html.twig', [
            'pageTitle' => $this->translator->trans('warehouse.stock.title'),
            'items' => $items,
            'warehouses' => $this->warehouseMap(),
            'products' => $this->productMap(array_map(static fn (StockItem $i): string => $i->productId(), $items)),
            'warehouseId' => $warehouseId,
            'productId' => $productId,
            'warehouseOptions' => $this->warehouseOptions(),
            'productOptions' => $this->productOptions(),
        ]);
    }

    public function createMovement(): Response
    {
        return $this->page('@warehouse/stock/movement_form.html.twig', [
            'pageTitle' => $this->translator->trans('warehouse.movement.new'),
            'warehouseOptions' => $this->warehouseOptions(),
            'productOptions' => $this->productOptions(),
            'types' => StockMovement::TYPES,
        ]);
    }

    public function storeMovement(Request $request): Response
    {
        $data = $this->validate($request, 'warehouse.movement.save');
        $user = $this->auth->user();

        try {
            $result = $this->stock->createMovement(
                trim((string) $data['warehouse_id']),
                trim((string) $data['product_id']),
                (string) $data['type'],
                (string) $data['qty'],
                isset($data['target_warehouse_id']) ? trim((string) $data['target_warehouse_id']) : null,
                isset($data['note']) ? trim((string) $data['note']) : null,
                $user?->id(),
            );
        } catch (\RuntimeException $e) {
            $this->flash('error', $this->translator->trans($e->getMessage()));

            return $this->redirect('admin.warehouse.stock.movement.create');
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $this->translator->trans($e->getMessage()));

            return $this->redirect('admin.warehouse.stock.movement.create');
        }

        $movement = $result['movement'];
        $this->auditor->log('warehouse.movement.created', 'stock_movement', $movement->id(), null, [
            'type' => $movement->type(),
            'qty' => $movement->qty(),
            'product_id' => $movement->productId(),
        ]);
        $this->forgetForm('warehouse.movement.save');
        $this->flash('success', $this->translator->trans('warehouse.flash.movement_created'));

        return $this->redirect('admin.warehouse.stock.index');
    }

    /** @return array<string, Warehouse> */
    private function warehouseMap(): array
    {
        $rows = $this->em->getRepository(Warehouse::class)->findAll();
        $map = [];
        foreach ($rows as $w) {
            if ($w instanceof Warehouse) {
                $map[$w->id()] = $w;
            }
        }

        return $map;
    }

    /**
     * @param list<string> $ids
     * @return array<string, Product>
     */
    private function productMap(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            return [];
        }
        $rows = $this->em->getRepository(Product::class)->createQueryBuilder('p')
            ->where('p.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
        $map = [];
        foreach ($rows as $p) {
            if ($p instanceof Product) {
                $map[$p->id()] = $p;
            }
        }

        return $map;
    }

    /** @return list<array{value: string, label: string}> */
    private function warehouseOptions(): array
    {
        $rows = $this->em->getRepository(Warehouse::class)->findBy(['active' => true], ['code' => 'ASC']);

        return array_map(
            static fn (Warehouse $w): array => ['value' => $w->id(), 'label' => $w->code() . ' — ' . $w->name()],
            $rows,
        );
    }

    /** @return list<array{value: string, label: string}> */
    private function productOptions(): array
    {
        if (!class_exists(Product::class)) {
            return [];
        }
        $rows = $this->em->getRepository(Product::class)->createQueryBuilder('p')
            ->orderBy('p.name', 'ASC')
            ->setMaxResults(500)
            ->getQuery()
            ->getResult();

        return array_map(
            static fn (Product $p): array => ['value' => $p->id(), 'label' => $p->sku() . ' — ' . $p->name()],
            $rows,
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
