<?php

declare(strict_types=1);

namespace Gereq\Admin\Dashboard\Widgets;

use Gereq\Admin\Dashboard\DashboardWidget;
use Gereq\Admin\Dashboard\TwigDashboardWidget;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class SearchHeroWidget extends TwigDashboardWidget
{
    public function __construct(
        Environment $twig,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct($twig);
    }

    public function id(): string
    {
        return 'core.search.hero';
    }

    public function title(): string
    {
        return $this->translator->trans('common.search');
    }

    public function type(): string
    {
        return DashboardWidget::TYPE_HERO;
    }

    public function order(): int
    {
        return 100;
    }

    protected function template(): string
    {
        return 'admin/dashboard/widgets/hero_search.html.twig';
    }

    protected function data(): array
    {
        return [
            'label' => $this->translator->trans('common.search'),
        ];
    }
}
