<?php

declare(strict_types=1);

namespace Modules\Bundles\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Event\EventBus;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Modules\Bundles\Domain\ProductBundle;
use Modules\Bundles\Domain\ProductBundleItem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class BundleController extends Controller
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
        $search = trim((string) $request->query->get('q', ''));
        $qb = $this->em->getRepository(ProductBundle::class)->createQueryBuilder('b')
            ->orderBy('b.code', 'ASC');
        if ($search !== '') {
            $qb->andWhere('b.code LIKE :q OR b.name LIKE :q')
                ->setParameter('q', '%' . $search . '%');
        }

        return $this->page('@bundles/index.html.twig', [
            'pageTitle' => $this->translator->trans('bundles.title'),
            'items' => $qb->getQuery()->getResult(),
            'search' => $search,
        ]);
    }

    public function create(): Response
    {
        return $this->page('@bundles/form.html.twig', [
            'pageTitle' => $this->translator->trans('bundles.new'),
            'item' => null,
            'itemsJson' => '[]',
            'discountTypes' => ProductBundle::DISCOUNT_TYPES,
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validated($request);
        $existing = $this->em->getRepository(ProductBundle::class)->findOneBy(['code' => $data['code']]);
        if ($existing instanceof ProductBundle) {
            $this->abort(422, $this->translator->trans('bundles.error.code_taken'));
        }

        $bundle = new ProductBundle($data['code'], $data['name']);
        $this->applyData($bundle, $data);
        $this->em->persist($bundle);
        $this->syncItems($bundle, $data['items']);
        $this->em->flush();

        $payload = $this->publicBundle($bundle);
        $this->events->emit('bundle.created', ['type' => 'product_bundle', 'id' => $bundle->id()], $payload);
        $this->auditor->log('bundles.created', 'product_bundle', $bundle->id(), null, [
            'code' => $bundle->code(),
            'name' => $bundle->name(),
        ]);
        $this->forgetForm('bundles.save');
        $this->flash('success', $this->translator->trans('bundles.flash.created', ['%name%' => $bundle->name()]));

        return $this->redirect('admin.bundles.index');
    }

    public function edit(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('bundles.error.not_found'));
        }

        return $this->page('@bundles/form.html.twig', [
            'pageTitle' => $this->translator->trans('bundles.edit'),
            'item' => $item,
            'itemsJson' => json_encode($this->itemsPayload($item), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            'discountTypes' => ProductBundle::DISCOUNT_TYPES,
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('bundles.error.not_found'));
        }

        $data = $this->validated($request);
        $dup = $this->em->getRepository(ProductBundle::class)->findOneBy(['code' => $data['code']]);
        if ($dup instanceof ProductBundle && $dup->id() !== $item->id()) {
            $this->abort(422, $this->translator->trans('bundles.error.code_taken'));
        }

        $old = ['code' => $item->code(), 'name' => $item->name()];
        $item->setCode($data['code']);
        $item->setName($data['name']);
        $this->applyData($item, $data);
        $this->syncItems($item, $data['items']);
        $this->em->flush();

        $payload = $this->publicBundle($item);
        $this->events->emit('bundle.updated', ['type' => 'product_bundle', 'id' => $item->id()], $payload);
        $this->auditor->log('bundles.updated', 'product_bundle', $item->id(), $old, [
            'code' => $item->code(),
            'name' => $item->name(),
        ]);
        $this->forgetForm('bundles.save');
        $this->flash('success', $this->translator->trans('bundles.flash.saved'));

        return $this->redirect('admin.bundles.index');
    }

    public function destroy(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('bundles.error.not_found'));
        }

        $name = $item->name();
        $payload = $this->publicBundle($item);
        foreach ($this->em->getRepository(ProductBundleItem::class)->findBy(['bundleId' => $id]) as $row) {
            $this->em->remove($row);
        }
        $this->em->remove($item);
        $this->em->flush();
        $this->events->emit('bundle.deleted', ['type' => 'product_bundle', 'id' => $id], $payload);
        $this->auditor->log('bundles.deleted', 'product_bundle', $id);
        $this->flash('success', $this->translator->trans('bundles.flash.deleted', ['%name%' => $name]));

        return $this->redirect('admin.bundles.index');
    }

    private function find(string $id): ?ProductBundle
    {
        $item = $this->em->find(ProductBundle::class, $id);

        return $item instanceof ProductBundle ? $item : null;
    }

    /**
     * @return array{
     *     code: string,
     *     name: string,
     *     description: ?string,
     *     discount_type: string,
     *     discount_value: string,
     *     currency: ?string,
     *     min_items: int,
     *     max_items: ?int,
     *     max_same_product: ?int,
     *     items: list<array{product_id: string, title: string, qty: int}>,
     *     active: bool
     * }
     */
    private function validated(Request $request): array
    {
        $data = $this->validate($request, 'bundles.save');
        $activeRaw = $data['active'] ?? $request->request->get('active');
        $currency = trim((string) ($data['currency'] ?? ''));
        $maxItems = trim((string) ($data['max_items'] ?? ''));
        $maxSame = trim((string) ($data['max_same_product'] ?? ''));

        return [
            'code' => strtoupper(trim((string) $data['code'])),
            'name' => trim((string) $data['name']),
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'discount_type' => strtolower(trim((string) ($data['discount_type'] ?? 'none'))),
            'discount_value' => number_format((float) ($data['discount_value'] ?? 0), 2, '.', ''),
            'currency' => $currency === '' ? null : strtoupper($currency),
            'min_items' => max(1, (int) ($data['min_items'] ?? 1)),
            'max_items' => $maxItems === '' ? null : (int) $maxItems,
            'max_same_product' => $maxSame === '' ? null : (int) $maxSame,
            'items' => $this->parseItems((string) ($data['items_json'] ?? $request->request->get('items_json', '[]'))),
            'active' => $activeRaw === true || $activeRaw === '1' || $activeRaw === 1 || $activeRaw === 'on',
        ];
    }

    /**
     * @return list<array{product_id: string, title: string, qty: int}>
     */
    private function parseItems(string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $items = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }
            $productId = trim((string) ($row['product_id'] ?? ''));
            $title = trim((string) ($row['title'] ?? ''));
            if ($productId === '' || $title === '') {
                continue;
            }
            $items[] = [
                'product_id' => $productId,
                'title' => $title,
                'qty' => max(1, (int) ($row['qty'] ?? 1)),
            ];
        }

        return $items;
    }

    /**
     * @param array{
     *     description: ?string,
     *     discount_type: string,
     *     discount_value: string,
     *     currency: ?string,
     *     min_items: int,
     *     max_items: ?int,
     *     max_same_product: ?int,
     *     active: bool
     * } $data
     */
    private function applyData(ProductBundle $bundle, array $data): void
    {
        $bundle->setDescription($data['description']);
        $bundle->setDiscountType($data['discount_type']);
        $bundle->setDiscountValue($data['discount_value']);
        $bundle->setCurrency($data['currency']);
        $bundle->setMinItems($data['min_items']);
        $bundle->setMaxItems($data['max_items']);
        $bundle->setMaxSameProduct($data['max_same_product']);
        $bundle->setActive($data['active']);
    }

    /** @param list<array{product_id: string, title: string, qty: int}> $items */
    private function syncItems(ProductBundle $bundle, array $items): void
    {
        foreach ($this->em->getRepository(ProductBundleItem::class)->findBy(['bundleId' => $bundle->id()]) as $row) {
            $this->em->remove($row);
        }
        foreach ($items as $i => $row) {
            $this->em->persist(new ProductBundleItem(
                $bundle->id(),
                $row['product_id'],
                $row['title'],
                $row['qty'],
                $i,
            ));
        }
    }

    /** @return list<array{product_id: string, title: string, qty: int}> */
    private function itemsPayload(ProductBundle $bundle): array
    {
        /** @var list<ProductBundleItem> $rows */
        $rows = $this->em->getRepository(ProductBundleItem::class)->findBy(
            ['bundleId' => $bundle->id()],
            ['sortOrder' => 'ASC'],
        );

        return array_map(static fn (ProductBundleItem $item): array => [
            'product_id' => $item->productId(),
            'title' => $item->title(),
            'qty' => $item->qty(),
        ], $rows);
    }

    /** @return array<string, mixed> */
    private function publicBundle(ProductBundle $bundle): array
    {
        return [
            'id' => $bundle->id(),
            'code' => $bundle->code(),
            'name' => $bundle->name(),
            'description' => $bundle->description(),
            'discount_type' => $bundle->discountType(),
            'discount_value' => $bundle->discountValue(),
            'currency' => $bundle->currency(),
            'min_items' => $bundle->minItems(),
            'max_items' => $bundle->maxItems(),
            'max_same_product' => $bundle->maxSameProduct(),
            'active' => $bundle->isActive(),
            'items' => $this->itemsPayload($bundle),
            'created_at' => $bundle->createdAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $bundle->updatedAt()->format(\DateTimeInterface::ATOM),
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
