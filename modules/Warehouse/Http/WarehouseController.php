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
use Modules\Warehouse\Domain\Warehouse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class WarehouseController extends Controller
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
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query->get('q', ''));
        $qb = $this->em->getRepository(Warehouse::class)->createQueryBuilder('w')
            ->orderBy('w.code', 'ASC');
        self_repair_search($qb, 'w', ['code', 'name'], $search);

        return $this->page('@warehouse/warehouses/index.html.twig', [
            'pageTitle' => $this->translator->trans('warehouse.title'),
            'items' => $qb->getQuery()->getResult(),
            'search' => $search,
        ]);
    }

    public function create(): Response
    {
        return $this->page('@warehouse/warehouses/form.html.twig', [
            'pageTitle' => $this->translator->trans('warehouse.new'),
            'item' => null,
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validated($request);
        $existing = $this->em->getRepository(Warehouse::class)->findOneBy(['code' => $data['code']]);
        if ($existing instanceof Warehouse) {
            $this->abort(422, $this->translator->trans('warehouse.error.code_taken'));
        }

        $warehouse = new Warehouse($data['code'], $data['name']);
        $warehouse->setActive($data['active']);
        $this->em->persist($warehouse);
        $this->em->flush();
        $this->auditor->log('warehouse.created', 'warehouse', $warehouse->id(), null, [
            'code' => $warehouse->code(),
            'name' => $warehouse->name(),
        ]);
        $this->forgetForm('warehouse.save');
        $this->flash('success', $this->translator->trans('warehouse.flash.created', ['%name%' => $warehouse->name()]));

        return $this->redirect('admin.warehouse.index');
    }

    public function edit(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('warehouse.error.not_found'));
        }

        return $this->page('@warehouse/warehouses/form.html.twig', [
            'pageTitle' => $this->translator->trans('warehouse.edit'),
            'item' => $item,
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('warehouse.error.not_found'));
        }

        $data = $this->validated($request);
        $dup = $this->em->getRepository(Warehouse::class)->findOneBy(['code' => $data['code']]);
        if ($dup instanceof Warehouse && $dup->id() !== $item->id()) {
            $this->abort(422, $this->translator->trans('warehouse.error.code_taken'));
        }

        $old = ['code' => $item->code(), 'name' => $item->name()];
        $item->setCode($data['code']);
        $item->setName($data['name']);
        $item->setActive($data['active']);
        $this->em->flush();
        $this->auditor->log('warehouse.updated', 'warehouse', $item->id(), $old, [
            'code' => $item->code(),
            'name' => $item->name(),
        ]);
        $this->forgetForm('warehouse.save');
        $this->flash('success', $this->translator->trans('warehouse.flash.saved'));

        return $this->redirect('admin.warehouse.index');
    }

    public function destroy(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('warehouse.error.not_found'));
        }

        $name = $item->name();
        $this->em->remove($item);
        $this->em->flush();
        $this->auditor->log('warehouse.deleted', 'warehouse', $id);
        $this->flash('success', $this->translator->trans('warehouse.flash.deleted', ['%name%' => $name]));

        return $this->redirect('admin.warehouse.index');
    }

    private function find(string $id): ?Warehouse
    {
        $item = $this->em->find(Warehouse::class, $id);

        return $item instanceof Warehouse ? $item : null;
    }

    /** @return array{code: string, name: string, active: bool} */
    private function validated(Request $request): array
    {
        $data = $this->validate($request, 'warehouse.save');
        $activeRaw = $data['active'] ?? $request->request->get('active');

        return [
            'code' => strtoupper(trim((string) $data['code'])),
            'name' => trim((string) $data['name']),
            'active' => $activeRaw === true || $activeRaw === '1' || $activeRaw === 1 || $activeRaw === 'on',
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
