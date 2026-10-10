<?php

declare(strict_types=1);

namespace Modules\Events\Application\Widgets;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\Dashboard\DashboardWidget;
use Gereq\Admin\Dashboard\TwigDashboardWidget;
use Gereq\Http\Router;
use Modules\Events\Domain\CatalogEvent;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/** Next published events on the calendar. */
final class UpcomingEventsWidget extends TwigDashboardWidget
{
    private const LIMIT = 8;

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
        return 'events.upcoming';
    }

    public function title(): string
    {
        return $this->translator->trans('events.widget.upcoming.title');
    }

    public function type(): string
    {
        return DashboardWidget::TYPE_PANEL;
    }

    public function permission(): ?string
    {
        return 'events.view';
    }

    public function order(): int
    {
        return 19;
    }

    protected function template(): string
    {
        return 'admin/dashboard/widgets/list_panel.html.twig';
    }

    protected function data(): array
    {
        $now = new \DateTimeImmutable('today');

        /** @var list<CatalogEvent> $events */
        $events = $this->em->getRepository(CatalogEvent::class)->createQueryBuilder('e')
            ->where('e.status = :status')
            ->andWhere('e.startsAt >= :now')
            ->setParameter('status', 'published')
            ->setParameter('now', $now)
            ->orderBy('e.startsAt', 'ASC')
            ->setMaxResults(self::LIMIT)
            ->getQuery()
            ->getResult();

        $items = [];
        foreach ($events as $event) {
            $badge = match ($event->spotlight()) {
                'week' => ['label' => $this->translator->trans('events.spotlight.week'), 'tone' => 'info'],
                'month' => ['label' => $this->translator->trans('events.spotlight.month'), 'tone' => 'info'],
                default => ['label' => $event->region(), 'tone' => 'muted'],
            };
            $items[] = [
                'title' => $event->title(),
                'meta' => $event->place(),
                'icon' => 'calendar',
                'href' => $this->router->url('admin.events.show', ['id' => $event->id()]),
                'aside' => $event->startsAt()->format('j. n.'),
                'aside_hint' => $event->startsAt()->format('H:i'),
                'badge' => $badge,
            ];
        }

        return [
            'title' => $this->translator->trans('events.widget.upcoming.title'),
            'subtitle' => $this->translator->trans('events.widget.upcoming.subtitle'),
            'href' => $this->router->url('admin.events.index', ['status' => 'published']),
            'link_label' => $this->translator->trans('events.title'),
            'items' => $items,
            'empty_message' => $this->translator->trans('events.widget.upcoming.empty'),
        ];
    }
}
