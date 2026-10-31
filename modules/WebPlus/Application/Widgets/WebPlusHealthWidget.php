<?php

declare(strict_types=1);

namespace Modules\WebPlus\Application\Widgets;

use Gereq\Admin\Dashboard\DashboardWidget;
use Gereq\Admin\Dashboard\TwigDashboardWidget;
use Gereq\Http\Router;
use Modules\WebPlus\Application\Health\HealthReport;
use Modules\WebPlus\Application\Health\WebHealthChecker;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/** Website health score (SEO / OG / GEO) without HTTP requests to the frontend. */
final class WebPlusHealthWidget extends TwigDashboardWidget
{
    private const MAX_RECOMMENDATIONS = 4;

    public function __construct(
        Environment $twig,
        private readonly WebHealthChecker $health,
        private readonly TranslatorInterface $translator,
        private readonly Router $router,
    ) {
        parent::__construct($twig);
    }

    public function id(): string
    {
        return 'webplus.health';
    }

    public function title(): string
    {
        return $this->translator->trans('webplus.health.title');
    }

    public function type(): string
    {
        return DashboardWidget::TYPE_PANEL;
    }

    public function permission(): ?string
    {
        return 'webplus.view';
    }

    public function order(): int
    {
        return 22;
    }

    protected function template(): string
    {
        return 'admin/dashboard/widgets/score_panel.html.twig';
    }

    protected function data(): array
    {
        $report = $this->health->runForBadge();

        $counts = ['pass' => 0, 'warn' => 0, 'fail' => 0];
        foreach ($report->checks as $check) {
            if (isset($counts[$check->status])) {
                $counts[$check->status]++;
            }
        }

        return [
            'title' => $this->translator->trans('webplus.health.title'),
            'subtitle' => $this->translator->trans('webplus.widget.health.subtitle'),
            'href' => $this->router->url('admin.webplus.health'),
            'link_label' => $this->translator->trans('nav.webplus.health'),
            'score' => $report->score,
            'color' => HealthReport::scoreColor($report->score),
            'grade' => $this->translator->trans('webplus.health.grade.' . $report->grade),
            'checks' => [
                ['label' => $this->translator->trans('webplus.widget.health.pass'), 'value' => (string) $counts['pass'], 'tone' => 'ok'],
                ['label' => $this->translator->trans('webplus.widget.health.warn'), 'value' => (string) $counts['warn'], 'tone' => 'warn'],
                ['label' => $this->translator->trans('webplus.widget.health.fail'), 'value' => (string) $counts['fail'], 'tone' => 'danger'],
            ],
            'recommendations' => array_slice($report->recommendations, 0, self::MAX_RECOMMENDATIONS),
            'recommendations_label' => $this->translator->trans('webplus.health.recommendations'),
            'all_ok_message' => $this->translator->trans('webplus.health.all_good'),
        ];
    }
}
