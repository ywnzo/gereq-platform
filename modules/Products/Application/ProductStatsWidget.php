<?php

declare(strict_types=1);

namespace Modules\Products\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\Dashboard\DashboardWidget;
use Modules\Products\Domain\Product;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ProductStatsWidget implements DashboardWidget
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function id(): string
    {
        return 'products.stats';
    }

    public function title(): string
    {
        return $this->translator->trans('products.widget.title');
    }

    public function render(): string
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

        return '<div class="metric-card"><span class="metric-card__label">' . htmlspecialchars($this->translator->trans('products.widget.title'), ENT_QUOTES, 'UTF-8')
            . '</span><strong class="metric-card__value">'
            . $active . '</strong><span class="muted">'
            . htmlspecialchars($this->translator->trans('products.widget.active', ['%count%' => (string) $active, '%total%' => (string) $total]), ENT_QUOTES, 'UTF-8')
            . '</span></div>';
    }

    public function permission(): string
    {
        return 'products.view';
    }

    public function order(): int
    {
        return 30;
    }
}
