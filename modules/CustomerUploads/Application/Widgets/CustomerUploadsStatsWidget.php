<?php

declare(strict_types=1);

namespace Modules\CustomerUploads\Application\Widgets;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\Dashboard\DashboardWidget;
use Gereq\Admin\Dashboard\TwigDashboardWidget;
use Modules\CustomerUploads\Domain\CustomerUpload;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class CustomerUploadsStatsWidget extends TwigDashboardWidget
{
    public function __construct(
        Environment $twig,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct($twig);
    }

    public function id(): string
    {
        return 'customer_uploads.stats';
    }

    public function title(): string
    {
        return $this->translator->trans('uploads.widget.title');
    }

    public function type(): string
    {
        return DashboardWidget::TYPE_METRIC;
    }

    public function permission(): ?string
    {
        return 'uploads.view';
    }

    public function order(): int
    {
        return 55;
    }

    protected function template(): string
    {
        return 'admin/dashboard/widgets/metric.html.twig';
    }

    protected function data(): array
    {
        $total = (int) $this->em->createQueryBuilder()
            ->select('COUNT(u.id)')
            ->from(CustomerUpload::class, 'u')
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'label' => $this->translator->trans('uploads.widget.title'),
            'value' => (string) $total,
            'hint' => $this->translator->trans('uploads.widget.hint'),
        ];
    }
}
