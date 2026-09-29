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

/** Most recent failed / retrying webhook deliveries. */
final class FailedDeliveriesWidget extends TwigDashboardWidget
{
    private const LIMIT = 8;

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
        return 'webhooks.failed_deliveries';
    }

    public function title(): string
    {
        return $this->translator->trans('webhooks.widget.failed.title');
    }

    public function type(): string
    {
        return DashboardWidget::TYPE_PANEL;
    }

    public function permission(): ?string
    {
        return 'webhooks.view';
    }

    public function order(): int
    {
        return 36;
    }

    protected function template(): string
    {
        return 'admin/dashboard/widgets/list_panel.html.twig';
    }

    protected function data(): array
    {
        /** @var list<WebhookDelivery> $deliveries */
        $deliveries = $this->em->getRepository(WebhookDelivery::class)->createQueryBuilder('d')
            ->where('d.status IN (:statuses)')
            ->setParameter('statuses', ['failed', 'retrying'])
            ->orderBy('d.createdAt', 'DESC')
            ->setMaxResults(self::LIMIT)
            ->getQuery()
            ->getResult();

        $names = $this->webhookNames(array_values(array_unique(array_map(
            static fn (WebhookDelivery $d): string => $d->webhookId(),
            $deliveries,
        ))));

        $items = [];
        foreach ($deliveries as $delivery) {
            $metaParts = [$names[$delivery->webhookId()] ?? $delivery->requestUrl()];
            if ($delivery->responseStatus() !== null) {
                $metaParts[] = 'HTTP ' . $delivery->responseStatus();
            }
            $metaParts[] = $this->translator->trans('webhooks.delivery.field.attempt') . ' ' . $delivery->attempt();

            $items[] = [
                'title' => $delivery->eventType(),
                'meta' => implode(' · ', $metaParts),
                'icon' => $delivery->status() === 'retrying' ? 'refresh' : 'alert',
                'href' => $this->router->url('admin.developer.webhook-deliveries.show', ['id' => $delivery->id()]),
                'aside' => $delivery->createdAt()->format('j. n.'),
                'aside_hint' => $delivery->createdAt()->format('H:i'),
                'badge' => [
                    'label' => $this->translator->trans($delivery->statusLabel()),
                    'tone' => $delivery->status() === 'retrying' ? 'warn' : 'danger',
                ],
            ];
        }

        return [
            'title' => $this->translator->trans('webhooks.widget.failed.title'),
            'subtitle' => $this->translator->trans('webhooks.widget.failed.subtitle'),
            'href' => $this->router->url('admin.developer.webhook-deliveries.index'),
            'link_label' => $this->translator->trans('nav.webhook_deliveries'),
            'items' => $items,
            'empty_message' => $this->translator->trans('webhooks.widget.failed.empty'),
        ];
    }

    /**
     * @param list<string> $ids
     * @return array<string, string>
     */
    private function webhookNames(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $names = [];
        foreach ($this->em->getRepository(Webhook::class)->findBy(['id' => $ids]) as $webhook) {
            $names[$webhook->id()] = $webhook->name();
        }

        return $names;
    }
}
