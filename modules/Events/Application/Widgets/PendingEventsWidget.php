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

/** Events submitted from the site and waiting for approval. */
final class PendingEventsWidget extends TwigDashboardWidget
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
        return 'events.pending';
    }

    public function title(): string
    {
        return $this->translator->trans('events.widget.pending.title');
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
        return 18;
    }

    protected function template(): string
    {
        return 'admin/dashboard/widgets/list_panel.html.twig';
    }

    protected function data(): array
    {
        /** @var list<CatalogEvent> $events */
        $events = $this->em->getRepository(CatalogEvent::class)->createQueryBuilder('e')
            ->where('e.status = :status')
            ->setParameter('status', 'pending')
            ->orderBy('e.createdAt', 'DESC')
            ->setMaxResults(self::LIMIT)
            ->getQuery()
            ->getResult();

        $pending = (int) $this->em->getRepository(CatalogEvent::class)->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->where('e.status = :status')
            ->setParameter('status', 'pending')
            ->getQuery()
            ->getSingleScalarResult();

        $items = [];
        foreach ($events as $event) {
            $items[] = [
                'title' => $event->title(),
                'meta' => $event->place() . ' · ' . $event->startsAt()->format('j. n. Y'),
                'icon' => 'inbox',
                'href' => $this->router->url('admin.events.show', ['id' => $event->id()]),
                'aside' => $event->createdAt()->format('j. n.'),
                'aside_hint' => $event->createdAt()->format('H:i'),
                'badge' => [
                    'label' => $this->translator->trans($event->statusLabel()),
                    'tone' => 'warn',
                ],
            ];
        }

        return [
            'title' => $this->translator->trans('events.widget.pending.title'),
            'subtitle' => $this->translator->trans('events.widget.pending.subtitle'),
            'href' => $this->router->url('admin.events.index', ['status' => 'pending']),
            'link_label' => $this->translator->trans('events.title'),
            'items' => $items,
            'stats' => [
                ['label' => $this->translator->trans('events.status.pending'), 'value' => (string) $pending],
            ],
            'empty_message' => $this->translator->trans('events.widget.pending.empty'),
        ];
    }
}
