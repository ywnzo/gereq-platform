<?php

declare(strict_types=1);

namespace Gereq\Admin\Dashboard\Widgets;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\Dashboard\DashboardWidget;
use Gereq\Admin\Dashboard\TwigDashboardWidget;
use Gereq\Audit\AuditLog;
use Gereq\Http\Router;
use Gereq\View\TwigRuntime;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/** Latest audit-log entries rendered through the shared list panel. */
final class ActivityWidget extends TwigDashboardWidget
{
    private const LIMIT = 8;

    public function __construct(
        Environment $twig,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
        private readonly Router $router,
        private readonly TwigRuntime $runtime,
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

    public function permission(): ?string
    {
        return 'audit.view';
    }

    public function order(): int
    {
        return 10;
    }

    protected function template(): string
    {
        return 'admin/dashboard/widgets/list_panel.html.twig';
    }

    protected function data(): array
    {
        /** @var list<AuditLog> $logs */
        $logs = $this->em->getRepository(AuditLog::class)->findBy([], ['createdAt' => 'DESC'], self::LIMIT);
        $today = (new \DateTimeImmutable())->format('Y-m-d');

        $items = [];
        foreach ($logs as $log) {
            $action = $log->action();
            $tone = $this->runtime->auditActionTone($action);
            $resource = trim((string) $log->resource());
            $actionLabel = $this->runtime->auditAction($action);
            $createdAt = $log->createdAt();

            $items[] = [
                'title' => $log->userName() ?? $this->translator->trans('common.system'),
                'meta' => $resource !== '' ? $actionLabel . ' · ' . $resource : $actionLabel,
                'icon' => self::iconFor($action, $tone),
                'aside' => $createdAt->format('Y-m-d') === $today
                    ? $this->translator->trans('admin.dashboard.today')
                    : $createdAt->format('j. n.'),
                'aside_hint' => $createdAt->format('H:i'),
            ];
        }

        return [
            'title' => $this->translator->trans('admin.dashboard.activity'),
            'subtitle' => $this->translator->trans('admin.dashboard.activity_subtitle'),
            'href' => $this->router->url('admin.audit.index'),
            'link_label' => $this->translator->trans('common.all'),
            'items' => $items,
            'empty_message' => $this->translator->trans('empty.activity'),
        ];
    }

    private static function iconFor(string $action, string $tone): string
    {
        if ($action === 'auth.login' || $action === 'auth.logout') {
            return 'key';
        }
        if (str_ends_with($action, '.uploaded')) {
            return 'upload';
        }

        return match ($tone) {
            'danger' => 'trash',
            'ok' => 'plus',
            'warn' => 'edit',
            default => 'activity',
        };
    }
}
