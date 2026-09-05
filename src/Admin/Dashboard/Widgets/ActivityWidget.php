<?php

declare(strict_types=1);

namespace Gereq\Admin\Dashboard\Widgets;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\Dashboard\DashboardWidget;
use Gereq\Admin\Dashboard\TwigDashboardWidget;
use Gereq\Audit\AuditLog;
use Gereq\Auth\Authenticator;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class ActivityWidget extends TwigDashboardWidget
{
    public function __construct(
        Environment $twig,
        private readonly EntityManagerInterface $em,
        private readonly Authenticator $auth,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct($twig);
    }

    public function id(): string
    {
        return 'core.activity';
    }

    public function title(): string
    {
        return $this->translator->trans('admin.dashboard.activity');
    }

    public function type(): string
    {
        return DashboardWidget::TYPE_PANEL;
    }

    public function order(): int
    {
        return 10;
    }

    protected function template(): string
    {
        return 'admin/dashboard/widgets/activity.html.twig';
    }

    protected function data(): array
    {
        $user = $this->auth->user();

        return [
            'activity' => $this->em->getRepository(AuditLog::class)->findBy([], ['createdAt' => 'DESC'], 12),
            'show_all' => $user !== null && $user->can('audit.view'),
        ];
    }
}
