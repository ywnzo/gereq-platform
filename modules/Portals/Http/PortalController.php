<?php

declare(strict_types=1);

namespace Modules\Portals\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Event\EventBus;
use Gereq\Http\Controller;
use Gereq\Http\ListQuery;
use Gereq\Http\Router;
use Modules\Listings\Domain\Listing;
use Modules\Portals\Application\PortalsProvider;
use Modules\Portals\Domain\PortalAccount;
use Modules\Portals\Domain\PortalProvider;
use Modules\Portals\Domain\PortalPublication;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class PortalController extends Controller
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
        private readonly PortalsProvider $provider,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $list = ListQuery::fromRequest(
            $request,
            sortable: ['name', 'code', 'createdAt'],
            defaultSort: 'name',
            defaultDir: 'asc',
            filterKeys: ['provider_id'],
        );
        $providerId = $list->filter('provider_id');

        $qb = $this->em->getRepository(PortalAccount::class)->createQueryBuilder('a');
        $list->applySearch($qb, 'a', ['name', 'code']);
        if ($providerId !== '') {
            $qb->andWhere('a.providerId = :p')->setParameter('p', $providerId);
        }
        $qb->orderBy('a.' . $list->sort, $list->direction());

        $total = (int) (clone $qb)->select('COUNT(a.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        /** @var list<PortalAccount> $items */
        $items = $qb->setFirstResult($list->offset())->setMaxResults($list->perPage)->getQuery()->getResult();

        return $this->page('@portals/index.html.twig', array_merge($list->viewData($total), [
            'pageTitle' => $this->translator->trans('portals.title'),
            'items' => $items,
            'provider_id' => $providerId,
            'catalog' => $this->activeCatalog(),
            'providerNames' => $this->providerNames(array_map(static fn (PortalAccount $a): string => $a->providerId(), $items)),
            'stats' => $this->publicationStats(),
        ]));
    }

    public function create(): Response
    {
        $catalog = $this->activeCatalog();
        $selected = $catalog[0] ?? null;

        return $this->page('@portals/form.html.twig', [
            'pageTitle' => $this->translator->trans('portals.new'),
            'item' => null,
            'catalog' => $catalog,
            'selectedProviderId' => $selected?->id() ?? '',
            'fieldValues' => [],
        ]);
    }

    public function store(Request $request): Response
    {
        try {
            $data = $this->validatedAccount($request);
        } catch (\InvalidArgumentException) {
            return $this->redirect('admin.portals.create');
        }
        $existing = $this->em->getRepository(PortalAccount::class)->findOneBy(['code' => $data['code']]);
        if ($existing instanceof PortalAccount) {
            $this->flash('error', $this->translator->trans('portals.error.code_exists'));

            return $this->redirect('admin.portals.create');
        }

        $item = new PortalAccount($data['code'], $data['name'], $data['provider_id']);
        $item->setActive($data['active']);
        $item->setConfig($data['config']);
        $this->em->persist($item);
        $this->em->flush();

        $payload = $this->provider->serializeAccount($item);
        $this->events->emit('portal.account_created', ['type' => 'portal_account', 'id' => $item->id()], $payload);
        $this->auditor->log('portals.account_created', 'portal_account', $item->id(), null, ['code' => $item->code()]);
        $this->forgetForm('portals.account.save');
        $this->flash('success', $this->translator->trans('portals.flash.created'));

        return $this->redirect('admin.portals.show', ['id' => $item->id()]);
    }

    public function show(string $id): Response
    {
        $item = $this->provider->findAccount($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('portals.error.not_found'));
        }
        $catalogProvider = $this->provider->findProvider($item->providerId());
        $pubs = $this->em->getRepository(PortalPublication::class)->findBy(
            ['portalAccountId' => $id],
            ['updatedAt' => 'DESC'],
            50,
        );
        $listingIds = array_values(array_unique(array_map(
            static fn (PortalPublication $p): string => $p->listingId(),
            $pubs,
        )));

        return $this->page('@portals/show.html.twig', [
            'pageTitle' => $item->name(),
            'item' => $item,
            'catalogProvider' => $catalogProvider,
            'publications' => $pubs,
            'listingTitles' => $this->listingTitles($listingIds),
            'listingOptions' => $this->listingOptions(),
        ]);
    }

    public function edit(string $id): Response
    {
        $item = $this->provider->findAccount($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('portals.error.not_found'));
        }

        return $this->page('@portals/form.html.twig', [
            'pageTitle' => $this->translator->trans('portals.edit', ['%name%' => $item->name()]),
            'item' => $item,
            'catalog' => $this->activeCatalog(),
            'selectedProviderId' => $item->providerId(),
            'fieldValues' => $item->config(),
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $item = $this->provider->findAccount($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('portals.error.not_found'));
        }
        try {
            $data = $this->validatedAccount($request, allowCodeChange: false);
        } catch (\InvalidArgumentException) {
            return $this->redirect('admin.portals.edit', ['id' => $id]);
        }
        $item->setName($data['name']);
        $item->setProviderId($data['provider_id']);
        $item->setActive($data['active']);
        $item->setConfig($data['config']);
        $this->em->flush();

        $payload = $this->provider->serializeAccount($item);
        $this->events->emit('portal.account_updated', ['type' => 'portal_account', 'id' => $id], $payload);
        $this->auditor->log('portals.account_updated', 'portal_account', $id);
        $this->forgetForm('portals.account.save');
        $this->flash('success', $this->translator->trans('portals.flash.updated'));

        return $this->redirect('admin.portals.show', ['id' => $id]);
    }

    public function toggle(string $id): Response
    {
        $item = $this->provider->findAccount($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('portals.error.not_found'));
        }
        $item->setActive(!$item->active());
        $this->em->flush();
        $this->flash('success', $this->translator->trans('portals.flash.toggled'));

        return $this->redirect('admin.portals.show', ['id' => $id]);
    }

    public function destroy(string $id): Response
    {
        $item = $this->provider->findAccount($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('portals.error.not_found'));
        }
        $this->deleteAccount($item);
        $this->em->flush();
        $this->auditor->log('portals.account_deleted', 'portal_account', $id);
        $this->flash('success', $this->translator->trans('portals.flash.deleted'));

        return $this->redirect('admin.portals.index');
    }

    public function bulk(Request $request): Response
    {
        $data = $this->validate($request, 'portals.bulk');
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
            $this->flash('error', $this->translator->trans('portals.bulk.error.empty'));

            return $this->redirect('admin.portals.index');
        }

        if ($action === 'delete' && !$request->request->getBoolean('confirmed')) {
            $this->flash('error', $this->translator->trans('portals.bulk.error.confirm'));

            return $this->redirect('admin.portals.index');
        }

        $affected = 0;
        foreach ($ids as $id) {
            $item = $this->provider->findAccount($id);
            if ($item === null) {
                continue;
            }
            match ($action) {
                'activate' => $item->setActive(true),
                'deactivate' => $item->setActive(false),
                'delete' => $this->deleteAccount($item),
                default => null,
            };
            if (in_array($action, ['activate', 'deactivate', 'delete'], true)) {
                $affected++;
            }
        }

        $this->em->flush();
        $this->auditor->log('portals.accounts_bulk', 'portal_account', null, null, [
            'action' => $action,
            'count' => $affected,
        ]);
        $this->flash('success', $this->translator->trans('portals.bulk.flash.' . $action, [
            '%count%' => (string) $affected,
        ]));

        return $this->redirect('admin.portals.index');
    }

    private function deleteAccount(PortalAccount $item): void
    {
        foreach ($this->em->getRepository(PortalPublication::class)->findBy(['portalAccountId' => $item->id()]) as $pub) {
            $this->em->remove($pub);
        }
        $this->em->remove($item);
    }

    /**
     * @return array{code: string, name: string, provider_id: string, active: bool, config: array<string, mixed>}
     */
    private function validatedAccount(Request $request, bool $allowCodeChange = true): array
    {
        $data = $this->validate($request, 'portals.account.save');
        $providerId = trim((string) $data['provider_id']);
        $catalogProvider = $this->provider->findProvider($providerId);
        if ($catalogProvider === null || !$catalogProvider->active()) {
            $this->flash('error', $this->translator->trans('portals.error.provider_missing'));
            throw new \InvalidArgumentException('portals.error.provider_missing');
        }

        $rawFields = $request->request->all('fields');
        if (!is_array($rawFields)) {
            $rawFields = [];
        }
        $config = [];
        foreach ($catalogProvider->fields() as $field) {
            $key = $field['key'];
            $value = trim((string) ($rawFields[$key] ?? ''));
            if ($field['required'] && $value === '') {
                $this->flash('error', $this->translator->trans('portals.error.field_required', ['%field%' => $field['label']]));
                throw new \InvalidArgumentException('portals.error.field_required');
            }
            if ($value !== '') {
                $config[$key] = $value;
            }
        }

        $activeRaw = $data['active'] ?? null;
        $active = $activeRaw === true || $activeRaw === 1 || $activeRaw === '1' || $activeRaw === 'on';

        return [
            'code' => $allowCodeChange ? strtolower(trim((string) $data['code'])) : '',
            'name' => trim((string) $data['name']),
            'provider_id' => $providerId,
            'active' => $active,
            'config' => $config,
        ];
    }

    /**
     * @return array{published: int, prepared: int, total: int}
     */
    private function publicationStats(): array
    {
        $rows = $this->em->createQueryBuilder()
            ->select('p.status AS status, COUNT(p.id) AS cnt')
            ->from(PortalPublication::class, 'p')
            ->groupBy('p.status')
            ->getQuery()
            ->getArrayResult();

        $byStatus = [];
        $total = 0;
        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');
            $count = (int) ($row['cnt'] ?? 0);
            $byStatus[$status] = $count;
            $total += $count;
        }

        return [
            'published' => $byStatus['published'] ?? 0,
            'prepared' => ($byStatus['draft'] ?? 0) + ($byStatus['queued'] ?? 0),
            'total' => $total,
        ];
    }

    /** @return list<PortalProvider> */
    private function activeCatalog(): array
    {
        return $this->em->getRepository(PortalProvider::class)->findBy(
            ['active' => true],
            ['sortOrder' => 'ASC', 'name' => 'ASC'],
        );
    }

    /**
     * @param list<string> $ids
     * @return array<string, string>
     */
    private function providerNames(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        /** @var list<PortalProvider> $rows */
        $rows = $this->em->getRepository(PortalProvider::class)->createQueryBuilder('p')
            ->where('p.id IN (:ids)')
            ->setParameter('ids', array_values(array_unique($ids)))
            ->getQuery()
            ->getResult();
        $map = [];
        foreach ($rows as $p) {
            $map[$p->id()] = $p->name();
        }

        return $map;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function listingOptions(): array
    {
        if (!class_exists(Listing::class)) {
            return [];
        }
        $items = $this->em->getRepository(Listing::class)->findBy([], ['updatedAt' => 'DESC'], 100);
        $options = [['value' => '', 'label' => '—']];
        foreach ($items as $listing) {
            if (!$listing instanceof Listing) {
                continue;
            }
            $options[] = [
                'value' => $listing->id(),
                'label' => sprintf('%s (%s)', $listing->title(), $listing->slug()),
            ];
        }

        return $options;
    }

    /**
     * @param list<string> $ids
     * @return array<string, string>
     */
    private function listingTitles(array $ids): array
    {
        if ($ids === [] || !class_exists(Listing::class)) {
            return [];
        }
        /** @var list<Listing> $rows */
        $rows = $this->em->getRepository(Listing::class)->createQueryBuilder('l')
            ->where('l.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
        $map = [];
        foreach ($rows as $listing) {
            $map[$listing->id()] = $listing->title();
        }

        return $map;
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
