<?php

declare(strict_types=1);

namespace Modules\WebPlus\Application\Widgets;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\Dashboard\DashboardWidget;
use Gereq\Admin\Dashboard\TwigDashboardWidget;
use Gereq\Http\Router;
use Modules\WebPlus\Domain\WebMeta;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/** Active SEO/OG meta records and their sitemap coverage. */
final class WebPlusStatsWidget extends TwigDashboardWidget
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
        return 'webplus.stats';
    }

    public function title(): string
    {
        return $this->translator->trans('webplus.widget.stats.title');
    }

    public function type(): string
    {
        return DashboardWidget::TYPE_METRIC;
    }

    public function permission(): ?string
    {
        return 'webplus.view';
    }

    public function order(): int
    {
        return 60;
    }

    protected function template(): string
    {
        return 'admin/dashboard/widgets/metric.html.twig';
    }

    protected function data(): array
    {
        $repo = $this->em->getRepository(WebMeta::class);

        $active = (int) $repo->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.active = :active')
            ->setParameter('active', true)
            ->getQuery()
            ->getSingleScalarResult();

        $noindex = (int) $repo->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.active = :active')
            ->andWhere('m.robots LIKE :noindex')
            ->setParameter('active', true)
            ->setParameter('noindex', 'noindex%')
            ->getQuery()
            ->getSingleScalarResult();

        $inSitemap = (int) $repo->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.active = :active')
            ->andWhere('m.sitemapInclude = :inc')
            ->setParameter('active', true)
            ->setParameter('inc', true)
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'label' => $this->translator->trans('webplus.widget.stats.title'),
            'value' => (string) $active,
            'hint' => $this->translator->trans('webplus.widget.stats.hint', [
                '%sitemap%' => (string) $inSitemap,
                '%noindex%' => (string) $noindex,
            ]),
            'href' => $this->router->url('admin.webplus.metas.index'),
        ];
    }
}
