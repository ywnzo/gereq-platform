<?php

declare(strict_types=1);

namespace Modules\Products\Application\Widgets;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\Dashboard\DashboardWidget;
use Gereq\Admin\Dashboard\TwigDashboardWidget;
use Gereq\Http\Router;
use Modules\Products\Domain\Product;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class ProductStatsWidget extends TwigDashboardWidget
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
        return 'products.stats';
    }

    public function title(): string
    {
        return $this->translator->trans('products.widget.title');
    }

    public function type(): string
    {
        return DashboardWidget::TYPE_METRIC;
    }

    public function permission(): ?string
    {
        return 'products.view';
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
        $active = (int) $this->em->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from(Product::class, 'p')
            ->where('p.status = :status')
            ->setParameter('status', 'active')
            ->getQuery()
            ->getSingleScalarResult();

        $total = (int) $this->em->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from(Product::class, 'p')
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'label' => $this->translator->trans('products.widget.title'),
            'value' => (string) $active,
            'hint' => $this->translator->trans('products.widget.active', [
                '%count%' => (string) $active,
                '%total%' => (string) $total,
            ]),
            'href' => $this->router->url('admin.products.index'),
        ];
    }
}
