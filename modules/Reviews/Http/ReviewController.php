<?php

declare(strict_types=1);

namespace Modules\Reviews\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Event\EventBus;
use Gereq\Http\Controller;
use Gereq\Http\ListQuery;
use Gereq\Http\Router;
use Modules\Reviews\Application\ReviewsProvider;
use Modules\Reviews\Domain\ProductReview;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class ReviewController extends Controller
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
        private readonly ReviewsProvider $provider,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $list = ListQuery::fromRequest(
            $request,
            sortable: ['rating', 'createdAt', 'status'],
            defaultSort: 'createdAt',
            defaultDir: 'desc',
            filterKeys: ['status'],
        );
        $status = $list->filter('status');

        $qb = $this->em->getRepository(ProductReview::class)->createQueryBuilder('r');
        if ($list->search !== '') {
            $qb->andWhere('r.authorName LIKE :q OR r.title LIKE :q OR r.body LIKE :q OR r.productId LIKE :q')
                ->setParameter('q', '%' . $list->search . '%');
        }
        if (in_array($status, ProductReview::STATUSES, true)) {
            $qb->andWhere('r.status = :s')->setParameter('s', $status);
        }
        $qb->orderBy('r.' . $list->sort, $list->direction());

        $total = (int) (clone $qb)->select('COUNT(r.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult($list->offset())->setMaxResults($list->perPage)->getQuery()->getResult();

        return $this->page('@reviews/index.html.twig', array_merge($list->viewData($total), [
            'pageTitle' => $this->translator->trans('reviews.title'),
            'items' => $items,
            'status' => $status,
            'statuses' => ProductReview::STATUSES,
        ]));
    }

    public function show(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('reviews.error.not_found'));
        }

        return $this->page('@reviews/show.html.twig', [
            'pageTitle' => $item->title() ?: $item->authorName(),
            'item' => $item,
            'average' => $this->provider->averageRating($item->productId()),
        ]);
    }

    public function approve(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('reviews.error.not_found'));
        }
        $item->approve();
        $this->em->flush();
        $payload = $this->public($item);
        $this->events->emit('review.approved', ['type' => 'product_review', 'id' => $id], $payload);
        $this->auditor->log('reviews.approved', 'product_review', $id);
        $this->flash('success', $this->translator->trans('reviews.flash.approved'));

        return $this->redirect('admin.reviews.show', ['id' => $id]);
    }

    public function reject(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('reviews.error.not_found'));
        }
        $item->reject();
        $this->em->flush();
        $payload = $this->public($item);
        $this->events->emit('review.rejected', ['type' => 'product_review', 'id' => $id], $payload);
        $this->auditor->log('reviews.rejected', 'product_review', $id);
        $this->flash('success', $this->translator->trans('reviews.flash.rejected'));

        return $this->redirect('admin.reviews.show', ['id' => $id]);
    }

    public function destroy(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('reviews.error.not_found'));
        }
        $this->em->remove($item);
        $this->em->flush();
        $this->events->emit('review.deleted', ['type' => 'product_review', 'id' => $id], ['id' => $id]);
        $this->auditor->log('reviews.deleted', 'product_review', $id);
        $this->flash('success', $this->translator->trans('reviews.flash.deleted'));

        return $this->redirect('admin.reviews.index');
    }

    private function find(string $id): ?ProductReview
    {
        return $this->provider->find($id);
    }

    /** @return array<string, mixed> */
    private function public(ProductReview $r): array
    {
        return [
            'id' => $r->id(),
            'product_id' => $r->productId(),
            'customer_id' => $r->customerId(),
            'author_name' => $r->authorName(),
            'author_email' => $r->authorEmail(),
            'rating' => $r->rating(),
            'title' => $r->title(),
            'body' => $r->body(),
            'status' => $r->status(),
            'created_at' => $r->createdAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $r->updatedAt()->format(\DateTimeInterface::ATOM),
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
