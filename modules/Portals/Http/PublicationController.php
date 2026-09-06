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
use Modules\Portals\Domain\PortalPublication;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class PublicationController extends Controller
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
            sortable: ['status', 'updatedAt', 'createdAt'],
            defaultSort: 'updatedAt',
            defaultDir: 'desc',
            filterKeys: ['status'],
        );
        $status = $list->filter('status');

        $qb = $this->em->getRepository(PortalPublication::class)->createQueryBuilder('p');
        $list->applySearch($qb, 'p', ['listingId', 'externalId', 'portalAccountId']);
        if (in_array($status, PortalPublication::STATUSES, true)) {
            $qb->andWhere('p.status = :s')->setParameter('s', $status);
        }
        $qb->orderBy('p.' . $list->sort, $list->direction());

        $total = (int) (clone $qb)->select('COUNT(p.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        /** @var list<PortalPublication> $items */
        $items = $qb->setFirstResult($list->offset())->setMaxResults($list->perPage)->getQuery()->getResult();

        $listingIds = array_values(array_unique(array_map(
            static fn (PortalPublication $p): string => $p->listingId(),
            $items,
        )));
        $accountIds = array_values(array_unique(array_map(
            static fn (PortalPublication $p): string => $p->portalAccountId(),
            $items,
        )));

        return $this->page('@portals/publications/index.html.twig', array_merge($list->viewData($total), [
            'pageTitle' => $this->translator->trans('portals.publications.title'),
            'items' => $items,
            'status' => $status,
            'statuses' => PortalPublication::STATUSES,
            'listingTitles' => $this->listingTitles($listingIds),
            'accountNames' => $this->accountNames($accountIds),
        ]));
    }

    public function show(string $id): Response
    {
        $item = $this->provider->findPublication($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('portals.error.pub_not_found'));
        }
        $account = $this->provider->findAccount($item->portalAccountId());
        $catalogProvider = $account !== null
            ? $this->provider->findProvider($account->providerId())
            : null;
        $listing = class_exists(Listing::class)
            ? $this->em->find(Listing::class, $item->listingId())
            : null;

        return $this->page('@portals/publications/show.html.twig', [
            'pageTitle' => $this->translator->trans('portals.publications.detail'),
            'item' => $item,
            'account' => $account,
            'catalogProvider' => $catalogProvider,
            'listing' => $listing instanceof Listing ? $listing : null,
        ]);
    }

    public function queue(Request $request, string $id): Response
    {
        $account = $this->provider->findAccount($id);
        if ($account === null) {
            $this->abort(404, $this->translator->trans('portals.error.not_found'));
        }
        if (!$account->active()) {
            $this->flash('error', $this->translator->trans('portals.error.inactive'));

            return $this->redirect('admin.portals.show', ['id' => $id]);
        }

        $listingId = trim((string) $request->request->get('listing_id', ''));
        $listing = $this->em->find(Listing::class, $listingId);
        if (!$listing instanceof Listing) {
            $this->flash('error', $this->translator->trans('portals.error.listing_not_found'));

            return $this->redirect('admin.portals.show', ['id' => $id]);
        }

        $pub = $this->provider->queuePublication($account, $listing);
        $payload = $this->provider->serializePublication($pub, true);
        $this->events->emit('portal.queued', ['type' => 'portal_publication', 'id' => $pub->id()], $payload);
        $this->auditor->log('portals.queued', 'portal_publication', $pub->id(), null, [
            'listing_id' => $listingId,
            'portal' => $account->code(),
        ]);
        $this->flash('success', $this->translator->trans('portals.flash.queued'));

        return $this->redirect('admin.portals.publications.show', ['id' => $pub->id()]);
    }

    public function markPublished(Request $request, string $id): Response
    {
        $item = $this->provider->findPublication($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('portals.error.pub_not_found'));
        }
        $externalId = trim((string) $request->request->get('external_id', '')) ?: null;
        $this->provider->markPublished($item, $externalId);
        $payload = $this->provider->serializePublication($item, true);
        $this->events->emit('portal.published', ['type' => 'portal_publication', 'id' => $id], $payload);
        $this->flash('success', $this->translator->trans('portals.flash.published'));

        return $this->redirect('admin.portals.publications.show', ['id' => $id]);
    }

    public function destroy(string $id): Response
    {
        $item = $this->provider->findPublication($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('portals.error.pub_not_found'));
        }
        $this->em->remove($item);
        $this->em->flush();
        $this->events->emit('portal.publication_deleted', ['type' => 'portal_publication', 'id' => $id], ['id' => $id]);
        $this->auditor->log('portals.publication_deleted', 'portal_publication', $id);
        $this->flash('success', $this->translator->trans('portals.flash.pub_deleted'));

        return $this->redirect('admin.portals.publications.index');
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

    /**
     * @param list<string> $ids
     * @return array<string, string>
     */
    private function accountNames(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var list<PortalAccount> $rows */
        $rows = $this->em->getRepository(PortalAccount::class)->createQueryBuilder('a')
            ->where('a.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $account) {
            $map[$account->id()] = $account->name();
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
