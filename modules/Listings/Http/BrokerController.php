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
use Gereq\Publish\Adapter\BrokerPublishAdapter;
use Gereq\Publish\ProductionFreeze;
use Gereq\Security\HtmlSanitizer;
use Modules\Listings\Application\BrokersProvider;
use Modules\Listings\Domain\Broker;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class BrokerController extends Controller
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
        private readonly BrokersProvider $provider,
        private readonly HtmlSanitizer $html,
        private readonly ProductionFreeze $freeze,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    private function freezeBroker(Broker $broker): void
    {
        if (!$broker->active() && !$this->freeze->hasFreeze('listings.brokers', $broker->id())) {
            return;
        }
        $this->freeze->freezeIfNeeded(
            'listings.brokers',
            'broker',
            $broker->id(),
            fn (): array => BrokerPublishAdapter::capture($broker),
            $broker->fullName(),
            $this->auth->user()?->id(),
        );
    }

    public function index(Request $request): Response
    {
        $list = ListQuery::fromRequest(
            $request,
            sortable: ['lastName', 'email', 'sortOrder', 'createdAt'],
            defaultSort: 'sortOrder',
            defaultDir: 'asc',
            filterKeys: ['active'],
        );

        $qb = $this->em->getRepository(Broker::class)->createQueryBuilder('b');
        $list->applySearch($qb, 'b', ['firstName', 'lastName', 'email', 'phone', 'position']);
        $active = $list->filter('active');
        if ($active === '1') {
            $qb->andWhere('b.active = true');
        } elseif ($active === '0') {
            $qb->andWhere('b.active = false');
        }
        $deleteKeys = $this->freeze->pendingDeleteKeys('listings.brokers');
        if ($deleteKeys !== []) {
            $qb->andWhere('b.id NOT IN (:pendingDelete)')->setParameter('pendingDelete', $deleteKeys);
        }
        $qb->orderBy('b.' . $list->sort, $list->direction());

        $total = (int) (clone $qb)->select('COUNT(b.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult($list->offset())->setMaxResults($list->perPage)->getQuery()->getResult();

        return $this->page('@listings/brokers/index.html.twig', array_merge($list->viewData($total), [
            'pageTitle' => $this->translator->trans('listings.brokers.title'),
            'items' => $items,
            'active' => $active,
        ]));
    }

    public function create(): Response
    {
        return $this->page('@listings/brokers/form.html.twig', [
            'pageTitle' => $this->translator->trans('listings.brokers.new'),
            'item' => null,
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validated($request);
        $broker = new Broker($data['first_name'], $data['last_name'], $data['email']);
        $this->apply($broker, $data);
        $this->em->persist($broker);
        $this->em->flush();
        if ($broker->active()) {
            $this->freeze->stageCreate(
                'listings.brokers',
                'broker',
                $broker->id(),
                $broker->fullName(),
                $this->auth->user()?->id(),
            );
        }
        $this->forgetForm('listings.brokers.save');
        $this->flash(
            'success',
            $broker->active()
                ? $this->translator->trans('publish.flash.staged')
                : $this->translator->trans('listings.brokers.flash.created'),
        );

        return $this->redirect('admin.listings.brokers.show', ['id' => $broker->id()]);
    }

    public function show(string $id): Response
    {
        $item = $this->provider->find($id);
        if ($item === null || $this->freeze->isPendingDelete('listings.brokers', $id)) {
            $this->abort(404, $this->translator->trans('listings.brokers.error.not_found'));
        }

        return $this->page('@listings/brokers/show.html.twig', [
            'pageTitle' => $item->fullName(),
            'item' => $item,
        ]);
    }

    public function edit(string $id): Response
    {
        $item = $this->provider->find($id);
        if ($item === null || $this->freeze->isPendingDelete('listings.brokers', $id)) {
            $this->abort(404, $this->translator->trans('listings.brokers.error.not_found'));
        }

        return $this->page('@listings/brokers/form.html.twig', [
            'pageTitle' => $this->translator->trans('listings.brokers.edit'),
            'item' => $item,
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $item = $this->provider->find($id);
        if ($item === null || $this->freeze->isPendingDelete('listings.brokers', $id)) {
            $this->abort(404, $this->translator->trans('listings.brokers.error.not_found'));
        }

        $data = $this->validated($request);
        $wasActive = $item->active();
        $this->freezeBroker($item);
        $item->setFirstName($data['first_name']);
        $item->setLastName($data['last_name']);
        $item->setEmail($data['email']);
        $this->apply($item, $data);
        $this->em->flush();
        if ($item->active() && !$wasActive && !$this->freeze->hasFreeze('listings.brokers', $item->id())) {
            $this->freeze->stageCreate(
                'listings.brokers',
                'broker',
                $item->id(),
                $item->fullName(),
                $this->auth->user()?->id(),
            );
        }
        $this->forgetForm('listings.brokers.save');
        $this->flash(
            'success',
            ($wasActive || $item->active())
                ? $this->translator->trans('publish.flash.staged')
                : $this->translator->trans('listings.brokers.flash.saved'),
        );

        return $this->redirect('admin.listings.brokers.show', ['id' => $item->id()]);
    }

    public function destroy(string $id): Response
    {
        $item = $this->provider->find($id);
        if ($item === null || $this->freeze->isPendingDelete('listings.brokers', $id)) {
            $this->abort(404, $this->translator->trans('listings.brokers.error.not_found'));
        }

        if ($item->active() || $this->freeze->hasFreeze('listings.brokers', $id)) {
            $this->freeze->stageDelete(
                'listings.brokers',
                'broker',
                $id,
                fn (): array => BrokerPublishAdapter::capture($item),
                $item->fullName(),
                $this->auth->user()?->id(),
            );
            $this->flash('success', $this->translator->trans('publish.flash.staged'));
        } else {
            $this->em->remove($item);
            $this->em->flush();
            $this->flash('success', $this->translator->trans('listings.brokers.flash.deleted'));
        }

        return $this->redirect('admin.listings.brokers.index');
    }

    public function toggleActive(string $id): Response
    {
        $item = $this->provider->find($id);
        if ($item === null || $this->freeze->isPendingDelete('listings.brokers', $id)) {
            $this->abort(404, $this->translator->trans('listings.brokers.error.not_found'));
        }

        $this->freezeBroker($item);
        if (!$item->active()) {
            $this->freeze->freezeIfNeeded(
                'listings.brokers',
                'broker',
                $item->id(),
                fn (): array => BrokerPublishAdapter::capture($item),
                $item->fullName(),
                $this->auth->user()?->id(),
            );
        }
        $item->setActive(!$item->active());
        $this->em->flush();
        $message = $this->translator->trans('publish.flash.staged');
        if (!$this->wantsJson()) {
            $this->flash('success', $message);
        }

        return $this->toggleResponse($item->active(), 'admin.listings.brokers.index', [], $message);
    }

    /**
     * @return array{
     *     first_name: string,
     *     last_name: string,
     *     email: string,
     *     phone: ?string,
     *     position: ?string,
     *     skills: ?string,
     *     description: ?string,
     *     photo_url: ?string,
     *     sort_order: int,
     *     active: bool
     * }
     */
    private function validated(Request $request): array
    {
        $data = $this->validate($request, 'listings.brokers.save');
        $phone = trim((string) ($data['phone'] ?? ''));
        $position = trim((string) ($data['position'] ?? ''));
        $skills = trim((string) ($data['skills'] ?? ''));
        $description = $this->html->sanitizeNullable((string) ($data['description'] ?? ''));
        $photo = trim((string) ($data['photo_url'] ?? ''));
        $sort = trim((string) ($data['sort_order'] ?? '0'));
        $activeRaw = $data['active'] ?? $request->request->get('active');

        return [
            'first_name' => trim((string) $data['first_name']),
            'last_name' => trim((string) $data['last_name']),
            'email' => trim((string) $data['email']),
            'phone' => $phone !== '' ? $phone : null,
            'position' => $position !== '' ? $position : null,
            'skills' => $skills !== '' ? $skills : null,
            'description' => $description,
            'photo_url' => $photo !== '' ? $photo : null,
            'sort_order' => $sort === '' ? 0 : (int) $sort,
            'active' => $activeRaw === true || $activeRaw === '1' || $activeRaw === 1 || $activeRaw === 'on',
        ];
    }

    /** @param array{phone: ?string, position: ?string, skills: ?string, description: ?string, photo_url: ?string, sort_order: int, active: bool} $data */
    private function apply(Broker $broker, array $data): void
    {
        $broker->setPhone($data['phone']);
        $broker->setPosition($data['position']);
        $broker->setSkills($data['skills']);
        $broker->setDescription($data['description']);
        $broker->setPhotoUrl($data['photo_url']);
        $broker->setSortOrder($data['sort_order']);
        $broker->setActive($data['active']);
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
