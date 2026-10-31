<?php

declare(strict_types=1);

namespace Modules\Reviews\Application\Widgets;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\Dashboard\DashboardWidget;
use Gereq\Admin\Dashboard\TwigDashboardWidget;
use Gereq\Http\Router;
use Modules\Reviews\Domain\ProductReview;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/** Reviews awaiting moderation (oldest first). */
final class PendingReviewsWidget extends TwigDashboardWidget
{
    private const LIMIT = 6;

    public function __construct(
        Environment $twig,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
        private readonly Router $router,
    ) {
        parent::__construct($twig);
    }

    public function id(): string
    {
        return 'reviews.pending';
    }

    public function title(): string
    {
        return $this->translator->trans('reviews.widget.pending.title');
    }

    public function type(): string
    {
        return DashboardWidget::TYPE_PANEL;
    }

    public function permission(): ?string
    {
        return 'reviews.view';
    }

    public function order(): int
    {
        return 26;
    }

    protected function template(): string
    {
        return 'admin/dashboard/widgets/list_panel.html.twig';
    }

    protected function data(): array
    {
        $repo = $this->em->getRepository(ProductReview::class);

        /** @var list<ProductReview> $reviews */
        $reviews = $repo->findBy(['status' => 'pending'], ['createdAt' => 'ASC'], self::LIMIT);

        $items = [];
        foreach ($reviews as $review) {
            $excerpt = trim((string) ($review->title() ?? ''));
            if ($excerpt === '') {
                $excerpt = mb_strimwidth(trim(preg_replace('/\s+/', ' ', $review->body()) ?? ''), 0, 90, '…');
            }
            $items[] = [
                'title' => $review->authorName(),
                'meta' => $excerpt,
                'icon' => 'star',
                'href' => $this->router->url('admin.reviews.show', ['id' => $review->id()]),
                'aside' => str_repeat('★', max(0, min(5, $review->rating()))) . str_repeat('☆', max(0, 5 - $review->rating())),
                'aside_hint' => $review->createdAt()->format('j. n.'),
                'badge' => $review->source() !== 'local'
                    ? ['label' => ucfirst($review->source()), 'tone' => 'info']
                    : null,
            ];
        }

        return [
            'title' => $this->translator->trans('reviews.widget.pending.title'),
            'subtitle' => $this->translator->trans('reviews.widget.pending.subtitle'),
            'href' => $this->router->url('admin.reviews.index'),
            'link_label' => $this->translator->trans('nav.reviews'),
            'items' => $items,
            'empty_message' => $this->translator->trans('reviews.widget.pending.empty'),
        ];
    }
}
