<?php

declare(strict_types=1);

namespace Gereq\Webhooks\Application\Widgets;

use Gereq\Admin\Dashboard\DashboardWidget;
use Gereq\Admin\Dashboard\TwigDashboardWidget;
use Gereq\Http\Router;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class WebhooksShortcutWidget extends TwigDashboardWidget
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
        return 'webhooks.shortcut';
    }

    public function title(): string
    {
        return $this->translator->trans('nav.webhooks');
    }

    public function type(): string
    {
        return DashboardWidget::TYPE_SHORTCUT;
    }

    public function permission(): ?string
    {
        return 'webhooks.view';
    }

    public function order(): int
    {
        return 72;
    }

    protected function template(): string
    {
        return 'admin/dashboard/widgets/shortcut.html.twig';
    }

    protected function data(): array
    {
        return [
            'href' => $this->router->url('admin.developer.webhooks.index'),
            'icon' => 'zap',
            'label' => $this->translator->trans('nav.webhooks'),
            'hint' => $this->translator->trans('admin.dashboard.hint.webhooks'),
        ];
    }
}
