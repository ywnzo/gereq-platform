<?php

declare(strict_types=1);

namespace Modules\Cms\Application\Widgets;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\Dashboard\DashboardWidget;
use Gereq\Admin\Dashboard\TwigDashboardWidget;
use Gereq\Http\Router;
use Modules\Cms\Domain\Page;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/** Published pages plus draft concepts in progress. */
final class CmsStatsWidget extends TwigDashboardWidget
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
        return 'cms.stats';
    }

    public function title(): string
    {
        return $this->translator->trans('cms.widget.stats.title');
    }

    public function type(): string
    {
        return DashboardWidget::TYPE_METRIC;
    }

    public function permission(): ?string
    {
        return 'cms.view';
    }

    public function order(): int
    {
        return 50;
    }

    protected function template(): string
    {
        return 'admin/dashboard/widgets/metric.html.twig';
    }

    protected function data(): array
    {
        $rows = $this->em->getRepository(Page::class)->createQueryBuilder('p')
            ->select('p.status AS status, COUNT(p.id) AS cnt')
            ->groupBy('p.status')
            ->getQuery()
            ->getArrayResult();

        $counts = ['published' => 0, 'draft' => 0, 'archived' => 0];
        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');
            if (isset($counts[$status])) {
                $counts[$status] = (int) ($row['cnt'] ?? 0);
            }
        }

        return [
            'label' => $this->translator->trans('cms.widget.stats.title'),
            'value' => (string) $counts['published'],
            'hint' => $this->translator->trans('cms.widget.stats.hint', ['%count%' => (string) $counts['draft']]),
            'href' => $this->router->url('admin.cms.pages.index'),
        ];
    }
}
