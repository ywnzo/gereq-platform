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
use Gereq\Security\HtmlSanitizer;
use Gereq\Setting\Settings;
use Modules\Reviews\Application\ReviewsProvider;
use Modules\Reviews\Domain\ProductReview;
use Modules\Reviews\Domain\ProductReviewReply;
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
        private readonly Settings $settings,
        private readonly HtmlSanitizer $html,
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
        $list->applySearch($qb, 'r', ['authorName', 'title', 'body', 'productId']);
        if (in_array($status, ProductReview::STATUSES, true)) {
            $qb->andWhere('r.status = :s')->setParameter('s', $status);
        }
        $qb->orderBy('r.' . $list->sort, $list->direction());

        $total = (int) (clone $qb)->select('COUNT(r.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult($list->offset())->setMaxResults($list->perPage)->getQuery()->getResult();

        $user = $this->auth->user();
        $response = $this->page('@reviews/index.html.twig', array_merge($list->viewData($total), [
            'pageTitle' => $this->translator->trans('reviews.title'),
            'items' => $items,
            'status' => $status,
            'statuses' => ProductReview::STATUSES,
            'reviewSettings' => $user !== null && $user->can('reviews.manage') ? ReviewSettingsController::fields($this->settings) : [],
            'reviewSections' => ReviewSettingsController::SECTIONS,
        ]));
        $this->forgetForm('reviews.settings.save');

        return $response;
    }

    public function create(): Response
    {
        return $this->page('@reviews/form.html.twig', [
            'pageTitle' => $this->translator->trans('reviews.new'),
            'siteProductId' => ProductReview::SITE_PRODUCT_ID,
            'statuses' => ProductReview::STATUSES,
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, 'reviews.admin.create');
        $rating = (int) ($data['rating'] ?? 0);
        if ($rating < 1 || $rating > 5) {
            $this->flash('error', $this->translator->trans('reviews.error.invalid_rating'));

            return $this->redirect('admin.reviews.create');
        }

        $productId = trim((string) ($data['product_id'] ?? ''));
        if ($productId === '') {
            $productId = ProductReview::SITE_PRODUCT_ID;
        }

        $review = new ProductReview(
            $productId,
            trim((string) $data['author_name']),
            $rating,
            trim((string) $data['body']),
        );
        if (!empty($data['author_email'])) {
            $review->setAuthorEmail((string) $data['author_email']);
        }
        if (!empty($data['title'])) {
            $review->setTitle((string) $data['title']);
        }
        $locale = trim((string) ($data['locale'] ?? ''));
        if ($locale !== '') {
            $review->setLocale($locale);
        }
        $status = (string) ($data['status'] ?? 'approved');
        if (in_array($status, ProductReview::STATUSES, true)) {
            $review->setStatus($status);
        } else {
            $review->approve();
        }

        $this->em->persist($review);
        $this->em->flush();
        $payload = $this->public($review);
        $this->events->emit('review.created', ['type' => 'product_review', 'id' => $review->id()], $payload);
        if ($review->status() === 'approved') {
            $this->events->emit('review.approved', ['type' => 'product_review', 'id' => $review->id()], $payload);
        }
        $this->auditor->log('reviews.created', 'product_review', $review->id());
        $this->forgetForm('reviews.admin.create');
        $this->flash('success', $this->translator->trans('reviews.flash.created'));

        return $this->redirect('admin.reviews.show', ['id' => $review->id()]);
    }

    public function show(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('reviews.error.not_found'));
        }

        $repliesEnabled = (bool) $this->settings->get('reviews.replies_enabled', true);
        $body = $item->body();
        $bodyHtml = preg_match('/<\/?[a-z][^>]*>/i', $body) !== 1
            ? nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))
            : $this->html->sanitize($body);

        return $this->page('@reviews/show.html.twig', [
            'pageTitle' => $item->title() ?: $item->authorName(),
            'item' => $item,
            'bodyHtml' => $bodyHtml,
            'average' => $this->provider->averageRating($item->productId()),
            'replies' => $this->em->getRepository(ProductReviewReply::class)->findBy(
                ['reviewId' => $item->id()],
                ['createdAt' => 'ASC'],
            ),
            'repliesEnabled' => $repliesEnabled,
            'defaultReplyAuthor' => $this->defaultReplyAuthor(),
        ]);
    }

    public function storeReply(Request $request, string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('reviews.error.not_found'));
        }
        if (!(bool) $this->settings->get('reviews.replies_enabled', true)) {
            $this->flash('error', $this->translator->trans('reviews.replies.disabled'));

            return $this->redirect('admin.reviews.show', ['id' => $id]);
        }

        $data = $this->validate($request, 'reviews.reply.create');
        $authorName = trim((string) ($data['author_name'] ?? ''));
        if ($authorName === '') {
            $authorName = $this->defaultReplyAuthor();
        }

        $reply = new ProductReviewReply($item->id(), $authorName, trim((string) $data['body']), true);
        $this->em->persist($reply);
        $this->em->flush();

        $this->events->emit('review.replied', ['type' => 'product_review', 'id' => $id], [
            'id' => $id,
            'reply_id' => $reply->id(),
            'author_name' => $reply->authorName(),
            'body' => $reply->body(),
            'is_official' => $reply->isOfficial(),
            'created_at' => $reply->createdAt()->format(\DateTimeInterface::ATOM),
        ]);
        $this->auditor->log('reviews.replied', 'product_review', $id, null, ['reply_id' => $reply->id()]);
        $this->forgetForm('reviews.reply.create');
        $this->flash('success', $this->translator->trans('reviews.flash.replied'));

        return $this->redirect('admin.reviews.show', ['id' => $id]);
    }

    public function destroyReply(string $id, string $replyId): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('reviews.error.not_found'));
        }

        $reply = $this->em->getRepository(ProductReviewReply::class)->find($replyId);
        if ($reply === null || $reply->reviewId() !== $item->id()) {
            $this->abort(404, $this->translator->trans('reviews.replies.error.not_found'));
        }

        $this->em->remove($reply);
        $this->em->flush();
        $this->auditor->log('reviews.reply_deleted', 'product_review', $id, null, ['reply_id' => $replyId]);
        $this->flash('success', $this->translator->trans('reviews.flash.reply_deleted'));

        return $this->redirect('admin.reviews.show', ['id' => $id]);
    }

    private function defaultReplyAuthor(): string
    {
        $brand = trim((string) $this->settings->get('brand.name', ''));
        if ($brand !== '') {
            return $brand;
        }
        $user = $this->auth->user();
        if ($user !== null && trim($user->name()) !== '') {
            return $user->name();
        }

        return $this->translator->trans('reviews.replies.default_author');
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
            'source' => $r->source(),
            'is_verified_purchase' => $r->isVerifiedPurchase(),
            'helpful_count' => $r->helpfulCount(),
            'locale' => $r->locale(),
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
