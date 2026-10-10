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

/** Published catalog events plus items waiting for approval. */
final class EventsStatsWidget extends TwigDashboardWidget
{
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
        return 'events.stats';
    }

    public function title(): string
    {
        return $this->translator->trans('events.widget.stats.title');
    }

    public function type(): string
    {
        return DashboardWidget::TYPE_METRIC;
    }

    public function permission(): ?string
    {
        return 'events.view';
    }

    public function order(): int
    {
        return 24;
    }

    protected function template(): string
    {
        return 'admin/dashboard/widgets/metric.html.twig';
    }

    protected function data(): array
    {
        $rows = $this->em->getRepository(CatalogEvent::class)->createQueryBuilder('e')
            ->select('e.status AS status, COUNT(e.id) AS cnt')
            ->groupBy('e.status')
            ->getQuery()
            ->getArrayResult();

        $counts = ['published' => 0, 'pending' => 0, 'archived' => 0, 'rejected' => 0];
        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');
            if (isset($counts[$status])) {
                $counts[$status] = (int) ($row['cnt'] ?? 0);
            }
        }

        return [
            'label' => $this->translator->trans('events.widget.stats.title'),
            'value' => (string) $counts['published'],
            'hint' => $this->translator->trans('events.widget.stats.hint', [
                '%count%' => (string) $counts['pending'],
            ]),
            'href' => $this->router->url('admin.events.index'),
        ];
    }
}
