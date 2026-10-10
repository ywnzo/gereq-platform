<?php

declare(strict_types=1);

namespace Modules\Events\Application\Widgets;

use Gereq\Admin\Dashboard\DashboardWidget;
use Gereq\Admin\Dashboard\TwigDashboardWidget;
use Gereq\Http\Router;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class EventsCreateHeroWidget extends TwigDashboardWidget
{
    public function __construct(
        Environment $twig,
        private readonly TranslatorInterface $translator,
        private readonly Router $router,
    ) {
        parent::__construct($twig);
    }

    public function id(): string
    {
        return 'events.create.hero';
    }

    public function title(): string
    {
        return $this->translator->trans('events.new');
    }

    public function type(): string
    {
        return DashboardWidget::TYPE_HERO;
    }

    public function permission(): ?string
    {
        return 'events.manage';
    }

    public function order(): int
    {
        return 24;
    }

    protected function template(): string
    {
        return 'admin/dashboard/widgets/hero_action.html.twig';
    }

    protected function data(): array
    {
        return [
            'href' => $this->router->url('admin.events.create'),
            'label' => $this->translator->trans('events.new'),
            'icon' => 'plus',
            'variant' => 'btn--ghost',
        ];
    }
}
