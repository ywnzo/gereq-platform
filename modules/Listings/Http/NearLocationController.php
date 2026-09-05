<?php

declare(strict_types=1);

namespace Modules\Listings\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\ListQuery;
use Gereq\Http\Router;
use Gereq\Security\HtmlSanitizer;
use Modules\Listings\Application\NearLocationsProvider;
use Modules\Listings\Domain\NearLocation;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class NearLocationController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly Authenticator $auth,
        private readonly Menu $menu,
        private readonly CommandPalette $palette,
        private readonly TranslatorInterface $translator,
        private readonly NearLocationsProvider $provider,
        private readonly HtmlSanitizer $html,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $list = ListQuery::fromRequest(
            $request,
            sortable: ['name', 'category', 'city', 'sortOrder', 'createdAt'],
            defaultSort: 'sortOrder',
            defaultDir: 'asc',
            filterKeys: ['active', 'category'],
        );

        $qb = $this->em->getRepository(NearLocation::class)->createQueryBuilder('n');
        $list->applySearch($qb, 'n', ['name', 'address', 'city', 'description']);
        $active = $list->filter('active');
        if ($active === '1') {
            $qb->andWhere('n.active = true');
        } elseif ($active === '0') {
            $qb->andWhere('n.active = false');
        }
        $category = $list->filter('category');
        if (in_array($category, NearLocation::CATEGORIES, true)) {
            $qb->andWhere('n.category = :category')->setParameter('category', $category);
        }
        $qb->orderBy('n.' . $list->sort, $list->direction());

        $total = (int) (clone $qb)->select('COUNT(n.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult($list->offset())->setMaxResults($list->perPage)->getQuery()->getResult();

        return $this->page('@listings/near_locations/index.html.twig', array_merge($list->viewData($total), [
            'pageTitle' => $this->translator->trans('listings.near.title'),
            'items' => $items,
            'active' => $active,
            'category' => $category,
            'categories' => NearLocation::CATEGORIES,
        ]));
    }

    public function create(): Response
    {
        return $this->page('@listings/near_locations/form.html.twig', [
            'pageTitle' => $this->translator->trans('listings.near.new'),
            'item' => null,
            'categories' => NearLocation::CATEGORIES,
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validated($request);
        $item = new NearLocation($data['name'], $data['category']);
        $this->apply($item, $data);
        $this->em->persist($item);
        $this->em->flush();
        $this->forgetForm('listings.near.save');
        $this->flash('success', $this->translator->trans('listings.near.flash.created'));

        return $this->redirect('admin.listings.near.show', ['id' => $item->id()]);
    }

    public function show(string $id): Response
    {
        $item = $this->provider->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('listings.near.error.not_found'));
        }

        return $this->page('@listings/near_locations/show.html.twig', [
            'pageTitle' => $item->name(),
            'item' => $item,
        ]);
    }

    public function edit(string $id): Response
    {
        $item = $this->provider->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('listings.near.error.not_found'));
        }

        return $this->page('@listings/near_locations/form.html.twig', [
            'pageTitle' => $this->translator->trans('listings.near.edit'),
            'item' => $item,
            'categories' => NearLocation::CATEGORIES,
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $item = $this->provider->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('listings.near.error.not_found'));
        }

        $data = $this->validated($request);
        $item->setName($data['name']);
        $item->setCategory($data['category']);
        $this->apply($item, $data);
        $this->em->flush();
        $this->forgetForm('listings.near.save');
        $this->flash('success', $this->translator->trans('listings.near.flash.saved'));

        return $this->redirect('admin.listings.near.show', ['id' => $item->id()]);
    }

    public function destroy(string $id): Response
    {
        $item = $this->provider->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('listings.near.error.not_found'));
        }

        $links = $this->em->getRepository(\Modules\Listings\Domain\ListingNearLocation::class)
            ->findBy(['nearLocationId' => $id]);
        foreach ($links as $link) {
            $this->em->remove($link);
        }
        $this->em->remove($item);
        $this->em->flush();
        $this->flash('success', $this->translator->trans('listings.near.flash.deleted'));

        return $this->redirect('admin.listings.near.index');
    }

    public function toggleActive(string $id): Response
    {
        $item = $this->provider->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('listings.near.error.not_found'));
        }

        $item->setActive(!$item->active());
        $this->em->flush();
        $message = $this->translator->trans(
            $item->active() ? 'listings.near.flash.activated' : 'listings.near.flash.deactivated'
        );
        if (!$this->wantsJson()) {
            $this->flash('success', $message);
        }

        return $this->toggleResponse($item->active(), 'admin.listings.near.index', [], $message);
    }

    /**
     * @return array{
     *     name: string,
     *     category: string,
     *     address: ?string,
     *     city: ?string,
     *     description: ?string,
     *     latitude: ?float,
     *     longitude: ?float,
     *     sort_order: int,
     *     active: bool
     * }
     */
    private function validated(Request $request): array
    {
        $data = $this->validate($request, 'listings.near.save');
        $address = trim((string) ($data['address'] ?? ''));
        $city = trim((string) ($data['city'] ?? ''));
        $description = $this->html->sanitizeNullable((string) ($data['description'] ?? ''));
        $lat = trim((string) ($data['latitude'] ?? ''));
        $lng = trim((string) ($data['longitude'] ?? ''));
        $sort = trim((string) ($data['sort_order'] ?? '0'));
        $activeRaw = $data['active'] ?? $request->request->get('active');

        return [
            'name' => trim((string) $data['name']),
            'category' => trim((string) ($data['category'] ?? 'other')),
            'address' => $address !== '' ? $address : null,
            'city' => $city !== '' ? $city : null,
            'description' => $description,
            'latitude' => $lat === '' ? null : (float) $lat,
            'longitude' => $lng === '' ? null : (float) $lng,
            'sort_order' => $sort === '' ? 0 : (int) $sort,
            'active' => $activeRaw === true || $activeRaw === '1' || $activeRaw === 1 || $activeRaw === 'on',
        ];
    }

    /** @param array{address: ?string, city: ?string, description: ?string, latitude: ?float, longitude: ?float, sort_order: int, active: bool} $data */
    private function apply(NearLocation $item, array $data): void
    {
        $item->setAddress($data['address']);
        $item->setCity($data['city']);
        $item->setDescription($data['description']);
        $item->setLatitude($data['latitude']);
        $item->setLongitude($data['longitude']);
        $item->setSortOrder($data['sort_order']);
        $item->setActive($data['active']);
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
