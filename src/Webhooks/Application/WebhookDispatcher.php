<?php

declare(strict_types=1);

namespace Gereq\Webhooks\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Event\EventEnvelope;
use Gereq\Queue\Queue;
use Gereq\Support\Json;
use Gereq\Webhooks\Domain\Webhook;
use Gereq\Webhooks\Domain\WebhookDelivery;

final class WebhookDispatcher
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Queue $queue,
        private readonly SsrfGuard $ssrf,
    ) {
    }

    public function dispatch(EventEnvelope $envelope): void
    {
        $this->processDueRetries();

        $webhooks = $this->em->getRepository(Webhook::class)->createQueryBuilder('w')
            ->where('w.status IN (:statuses)')
            ->setParameter('statuses', ['active', 'failing'])
            ->getQuery()
            ->getResult();

        $body = Json::encode($envelope->webhookPayload());

        foreach ($webhooks as $webhook) {
            /** @var Webhook $webhook */
            if (!$webhook->matchesEvent($envelope->type)) {
                continue;
            }

            try {
                $this->ssrf->assertSafe($webhook->url());
            } catch (\InvalidArgumentException) {
                continue;
            }

            $headers = [
                'Content-Type' => 'application/json',
                'User-Agent' => 'GEREQ-Webhooks/1.0',
                'X-GEREQ-Event' => $envelope->type,
                'X-GEREQ-Delivery' => '',
                'X-GEREQ-Timestamp' => '',
            ];

            $delivery = new WebhookDelivery(
                $webhook->id(),
                $envelope->id,
                $envelope->type,
                $webhook->url(),
                $headers,
                $body,
                1,
            );
            $headers['X-GEREQ-Delivery'] = $delivery->id();
            $delivery->setRequestHeaders($headers);

            $this->em->persist($delivery);
            $this->em->flush();

            $this->queue->dispatch(new DeliverWebhookJob($delivery->id()));
        }
    }

    public function enqueue(WebhookDelivery $delivery): void
    {
        $this->queue->dispatch(new DeliverWebhookJob($delivery->id()));
    }

    public function processDueRetries(): void
    {
        $due = $this->em->getRepository(WebhookDelivery::class)->createQueryBuilder('d')
            ->where('d.status = :status')
            ->andWhere('d.nextRetryAt IS NOT NULL')
            ->andWhere('d.nextRetryAt <= :now')
            ->setParameter('status', 'retrying')
            ->setParameter('now', new \DateTimeImmutable())
            ->setMaxResults(50)
            ->getQuery()
            ->getResult();

        foreach ($due as $delivery) {
            /** @var WebhookDelivery $delivery */
            $delivery->beginNextAttempt();
            $this->em->flush();
            $this->queue->dispatch(new DeliverWebhookJob($delivery->id()));
        }
    }
}
