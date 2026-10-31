<?php

declare(strict_types=1);

namespace Modules\Cms\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Publish\ProductionFreeze;
use Modules\Cms\Domain\NavLink;
use Modules\Cms\Publish\CmsLinkPublishAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class LinkController extends Controller
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
        private readonly ProductionFreeze $freeze,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query->get('q', ''));
        $status = (string) $request->query->get('status', '');
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = min(100, max(1, (int) $request->query->get('per_page', 20)));

        $qb = $this->em->getRepository(NavLink::class)->createQueryBuilder('l')
            ->orderBy('l.updatedAt', 'DESC');

        self_repair_search($qb, 'l', ['title', 'url'], $search);
        if (in_array($status, NavLink::STATUSES, true)) {
            $qb->andWhere('l.status = :status')->setParameter('status', $status);
        }

        $deleteKeys = $this->freeze->pendingDeleteKeys('cms.links');
        if ($deleteKeys !== []) {
            $qb->andWhere('l.id NOT IN (:pendingDelete)')->setParameter('pendingDelete', $deleteKeys);
        }

        $total = (int) (clone $qb)->select('COUNT(l.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        /** @var list<NavLink> $items */
        $items = $qb->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage)->getQuery()->getResult();

        $drawer = trim((string) $request->query->get('drawer', ''));
        if ($this->formState()->hasErrors('cms.link.save')) {
            $failedDrawer = $this->formState()->old('_drawer', $drawer, 'cms.link.save');
            if (is_string($failedDrawer)) {
                $drawer = $failedDrawer;
            }
        }
        $drawerItem = null;
        $editPrefix = str_starts_with($drawer, 'cms-link-edit-') ? 'cms-link-edit-' : 'edit-';
        if (str_starts_with($drawer, $editPrefix)) {
            $candidate = $this->find(substr($drawer, strlen($editPrefix)));
            if ($candidate instanceof NavLink && !$this->freeze->isPendingDelete('cms.links', $candidate->id())) {
                $onPage = false;
                foreach ($items as $row) {
                    if ($row->id() === $candidate->id()) {
                        $onPage = true;
                        break;
                    }
                }
                if (!$onPage) {
                    $drawerItem = $candidate;
                }
            }
        }

        $response = $this->page('@cms/links/index.html.twig', [
            'pageTitle' => $this->translator->trans('cms.links.title'),
            'items' => $items,
            'search' => $search,
            'status' => $status,
            'statuses' => NavLink::STATUSES,
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'pages' => (int) ceil(max(1, $total) / $perPage),
            'open_drawer' => $drawer,
            'drawer_item' => $drawerItem,
        ]);
        if ($this->formState()->hasErrors('cms.link.save')) {
            $this->forgetForm('cms.link.save');
        }

        return $response;
    }

    public function create(): Response
    {
        return $this->redirectTo($this->router->url('admin.cms.links.index') . '?drawer=create');
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, 'cms.link.save');
        $link = new NavLink(trim((string) $data['title']), (string) $data['url']);
        $link->setMenuLocations($this->menuLocationsFromRequest($request));
        $wantPublished = (string) $data['status'] === 'published';
        $link->setStatus($wantPublished ? 'published' : (string) $data['status']);
        $this->em->persist($link);
        $this->em->flush();

        if ($wantPublished) {
            $this->freeze->stageCreate(
                'cms.links',
                'link',
                $link->id(),
                $link->title(),
                $this->auth->user()?->id(),
            );
        }

        $this->auditor->log('link.created', 'cms_link', $link->id(), null, ['url' => $link->url()]);
        $this->forgetForm('cms.link.save');
        $this->flash(
            'success',
            $wantPublished
                ? $this->translator->trans('publish.flash.staged')
                : $this->translator->trans('cms.flash.link_created', ['%title%' => $link->title()]),
        );

        return $this->redirect('admin.cms.links.index');
    }

    public function edit(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null || $this->freeze->isPendingDelete('cms.links', $id)) {
            $this->abort(404, $this->translator->trans('cms.error.link_not_found'));
        }

        return $this->redirectTo($this->router->url('admin.cms.links.index') . '?drawer=edit-' . rawurlencode($id));
    }

    public function update(Request $request, string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('cms.error.link_not_found'));
        }

        $data = $this->validate($request, 'cms.link.save');
        $wasPublished = $item->isPublished();
        $wantPublished = (string) $data['status'] === 'published';

        if ($wasPublished || $this->freeze->hasFreeze('cms.links', $item->id())) {
            $this->freeze->freezeIfNeeded(
                'cms.links',
                'link',
                $item->id(),
                fn (): array => CmsLinkPublishAdapter::capture($item),
                $item->title(),
                $this->auth->user()?->id(),
            );
        }

        $item->setTitle(trim((string) $data['title']));
        $item->setUrl((string) $data['url']);
        $item->setMenuLocations($this->menuLocationsFromRequest($request));
        $item->setStatus((string) $data['status']);
        $this->em->flush();

        if ($wantPublished && !$wasPublished && !$this->freeze->hasFreeze('cms.links', $item->id())) {
            $this->freeze->stageCreate(
                'cms.links',
                'link',
                $item->id(),
                $item->title(),
                $this->auth->user()?->id(),
            );
        }

        $this->auditor->log('link.updated', 'cms_link', $item->id());
        $this->forgetForm('cms.link.save');
        $message = ($wasPublished || $wantPublished)
            ? $this->translator->trans('publish.flash.staged')
            : $this->translator->trans('cms.flash.link_updated', ['%title%' => $item->title()]);
        $this->flash('success', $message);

        return $this->redirect('admin.cms.links.index');
    }

    public function publish(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('cms.error.link_not_found'));
        }

        if ($item->isPublished()) {
            $this->freeze->freezeIfNeeded(
                'cms.links',
                'link',
                $item->id(),
                fn (): array => CmsLinkPublishAdapter::capture($item),
                $item->title(),
                $this->auth->user()?->id(),
            );
            $item->setStatus('draft');
            $this->em->flush();
            $this->auditor->log('link.unpublished', 'cms_link', $item->id());
            $message = $this->translator->trans('publish.flash.staged');
            if (!$this->wantsJson()) {
                $this->flash('success', $message);
            }

            return $this->toggleResponse(false, 'admin.cms.links.index', [], $message);
        }

        $item->setStatus('published');
        $this->em->flush();
        if (!$this->freeze->hasFreeze('cms.links', $item->id())) {
            $this->freeze->stageCreate(
                'cms.links',
                'link',
                $item->id(),
                $item->title(),
                $this->auth->user()?->id(),
            );
        }
        $this->auditor->log('link.published', 'cms_link', $item->id());
        $message = $this->translator->trans('publish.flash.staged');
        if (!$this->wantsJson()) {
            $this->flash('success', $message);
        }

        return $this->toggleResponse(true, 'admin.cms.links.index', [], $message);
    }

    public function destroy(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null || $this->freeze->isPendingDelete('cms.links', $id)) {
            $this->abort(404, $this->translator->trans('cms.error.link_not_found'));
        }

        $title = $item->title();
        if ($item->isPublished() || $this->freeze->hasFreeze('cms.links', $id)) {
            $this->freeze->stageDelete(
                'cms.links',
                'link',
                $id,
                fn (): array => CmsLinkPublishAdapter::capture($item),
                $title,
                $this->auth->user()?->id(),
            );
            $this->auditor->log('link.deleted', 'cms_link', $id);
            $this->flash('success', $this->translator->trans('publish.flash.staged'));
        } else {
            $this->em->remove($item);
            $this->em->flush();
            $this->auditor->log('link.deleted', 'cms_link', $id);
            $this->flash('success', $this->translator->trans('cms.flash.link_deleted', ['%title%' => $title]));
        }

        return $this->redirect('admin.cms.links.index');
    }

    private function find(string $id): ?NavLink
    {
        $item = $this->em->find(NavLink::class, $id);

        return $item instanceof NavLink ? $item : null;
    }

    /** @return list<string> */
    private function menuLocationsFromRequest(Request $request): array
    {
        $raw = $request->request->all('menu_locations');
        if ($raw === []) {
            $single = $request->request->all()['menu_locations'] ?? null;
            $raw = is_array($single) ? $single : [];
        }

        $out = [];
        foreach ($raw as $value) {
            if (is_string($value) || is_int($value)) {
                $out[] = (string) $value;
            }
        }

        return $out;
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
