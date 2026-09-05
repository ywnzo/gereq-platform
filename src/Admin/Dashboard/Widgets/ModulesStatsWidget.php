<?php

declare(strict_types=1);

namespace Gereq\Admin\Dashboard\Widgets;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\Dashboard\DashboardWidget;
use Gereq\Admin\Dashboard\TwigDashboardWidget;
use Gereq\Module\ModuleManager;
use Gereq\Http\Router;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class ModulesStatsWidget extends TwigDashboardWidget
{
    public function __construct(
        Environment $twig,
        private readonly ModuleManager $modules,
        private readonly TranslatorInterface $translator,
        private readonly Router $router,
    ) {
        parent::__construct($twig);
    }

    public function id(): string
    {
        return 'core.modules';
    }

    public function title(): string
    {
        return $this->translator->trans('nav.modules');
    }

    public function type(): string
    {
        return DashboardWidget::TYPE_METRIC;
    }

    public function permission(): ?string
    {
        return 'modules.manage';
    }

    public function order(): int
    {
        return 10;
    }

    protected function template(): string
    {
        return 'admin/dashboard/widgets/metric.html.twig';
    }

    protected function data(): array
    {
        $count = count($this->modules->enabledIds());

        return [
            'label' => $this->translator->trans('nav.modules'),
            'value' => (string) $count,
            'hint' => $this->translator->trans('admin.dashboard.modules_active'),
            'href' => $this->router->url('admin.modules.index'),
        ];
    }
}
