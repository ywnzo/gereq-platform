<?php

declare(strict_types=1);

namespace Gereq\Webhooks\Application\Widgets;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\Dashboard\DashboardWidget;
use Gereq\Admin\Dashboard\TwigDashboardWidget;
use Gereq\Http\Router;
use Gereq\Webhooks\Domain\Webhook;
use Gereq\Webhooks\Domain\WebhookDelivery;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/** Active webhook endpoints + failed deliveries in the last 24 hours. */
final class WebhookStatsWidget extends TwigDashboardWidget
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
        return 'webhooks.stats';
    }

    public function title(): string
    {
        return $this->translator->trans('webhooks.widget.stats.title');
    }

    public function type(): string
    {
        return DashboardWidget::TYPE_METRIC;
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
        return 'admin/dashboard/widgets/metric.html.twig';
    }

    protected function data(): array
    {
        $webhooks = $this->em->getRepository(Webhook::class);

        /** @var list<array{status: string, cnt: string|int}> $statusRows */
        $statusRows = $webhooks->createQueryBuilder('w')
            ->select('w.status AS status, COUNT(w.id) AS cnt')
            ->where('w.status IN (:statuses)')
            ->setParameter('statuses', ['active', 'failing'])
            ->groupBy('w.status')
            ->getQuery()
            ->getArrayResult();

        $active = 0;
        $failing = 0;
        foreach ($statusRows as $row) {
            $count = (int) ($row['cnt'] ?? 0);
            if (($row['status'] ?? '') === 'active') {
                $active = $count;
            } elseif (($row['status'] ?? '') === 'failing') {
                $failing = $count;
            }
        }

        $failed24h = (int) $this->em->getRepository(WebhookDelivery::class)->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->where('d.status = :status')
            ->andWhere('d.createdAt >= :since')
            ->setParameter('status', 'failed')
            ->setParameter('since', new \DateTimeImmutable('-24 hours'))
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'label' => $this->translator->trans('webhooks.widget.stats.title'),
            'value' => (string) $active,
            'hint' => $this->translator->trans('webhooks.widget.stats.hint', [
                '%failed%' => (string) $failed24h,
                '%failing%' => (string) $failing,
            ]),
            'href' => $this->router->url('admin.developer.webhooks.index'),
        ];
    }
}
