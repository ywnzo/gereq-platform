<?php

declare(strict_types=1);

namespace Modules\Webhooks\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'webhook_deliveries')]
#[ORM\Index(name: 'idx_webhook_deliveries_webhook', columns: ['webhook_id'])]
#[ORM\Index(name: 'idx_webhook_deliveries_status', columns: ['status'])]
#[ORM\Index(name: 'idx_webhook_deliveries_retry', columns: ['next_retry_at'])]
class WebhookDelivery
{
    public const STATUSES = ['pending', 'delivered', 'failed', 'retrying'];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'webhook_id', length: 36)]
    private string $webhookId;

    #[ORM\Column(name: 'event_id', length: 64)]
    private string $eventId;

    #[ORM\Column(name: 'event_type', length: 190)]
    private string $eventType;

    #[ORM\Column(name: 'request_url', length: 2048)]
    private string $requestUrl;

    /** @var array<string, string> */
    #[ORM\Column(name: 'request_headers', type: 'json')]
    private array $requestHeaders = [];

    #[ORM\Column(name: 'request_body', type: 'text')]
    private string $requestBody;

    #[ORM\Column(name: 'response_status', nullable: true)]
    private ?int $responseStatus = null;

    /** @var array<string, string>|null */
    #[ORM\Column(name: 'response_headers', type: 'json', nullable: true)]
    private ?array $responseHeaders = null;

    #[ORM\Column(name: 'response_body', type: 'text', nullable: true)]
    private ?string $responseBody = null;

    #[ORM\Column]
    private int $attempt = 1;

    #[ORM\Column(name: 'duration_ms', nullable: true)]
    private ?int $durationMs = null;

    #[ORM\Column(length: 32)]
    private string $status = 'pending';

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'delivered_at', nullable: true)]
    private ?\DateTimeImmutable $deliveredAt = null;

    #[ORM\Column(name: 'next_retry_at', nullable: true)]
    private ?\DateTimeImmutable $nextRetryAt = null;

    /**
     * @param array<string, string> $requestHeaders
     */
    public function __construct(
        string $webhookId,
        string $eventId,
        string $eventType,
        string $requestUrl,
        array $requestHeaders,
        string $requestBody,
        int $attempt = 1,
    ) {
        $this->id = Uuid::uuid7()->toString();
        $this->webhookId = $webhookId;
        $this->eventId = $eventId;
        $this->eventType = $eventType;
        $this->requestUrl = $requestUrl;
        $this->requestHeaders = $requestHeaders;
        $this->requestBody = $requestBody;
        $this->attempt = max(1, $attempt);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function webhookId(): string
    {
        return $this->webhookId;
    }

    public function eventId(): string
    {
        return $this->eventId;
    }

    public function eventType(): string
    {
        return $this->eventType;
    }

    public function requestUrl(): string
    {
        return $this->requestUrl;
    }

    /** @return array<string, string> */
    public function requestHeaders(): array
    {
        return $this->requestHeaders;
    }

    public function requestBody(): string
    {
        return $this->requestBody;
    }

    public function responseStatus(): ?int
    {
        return $this->responseStatus;
    }

    /** @return array<string, string>|null */
    public function responseHeaders(): ?array
    {
        return $this->responseHeaders;
    }

    public function responseBody(): ?string
    {
        return $this->responseBody;
    }

    public function attempt(): int
    {
        return $this->attempt;
    }

    public function durationMs(): ?int
    {
        return $this->durationMs;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function deliveredAt(): ?\DateTimeImmutable
    {
        return $this->deliveredAt;
    }

    public function nextRetryAt(): ?\DateTimeImmutable
    {
        return $this->nextRetryAt;
    }

    /** @param array<string, string> $headers */
    public function setRequestHeaders(array $headers): void
    {
        $this->requestHeaders = $headers;
    }

    /**
     * @param array<string, string> $responseHeaders
     */
    public function markDelivered(int $status, array $responseHeaders, string $responseBody, int $durationMs): void
    {
        $this->responseStatus = $status;
        $this->responseHeaders = $responseHeaders;
        $this->responseBody = $this->truncate($responseBody);
        $this->durationMs = $durationMs;
        $this->status = 'delivered';
        $this->deliveredAt = new \DateTimeImmutable();
        $this->nextRetryAt = null;
    }

    /**
     * @param array<string, string> $responseHeaders
     */
    public function recordResponse(
        ?int $status,
        array $responseHeaders,
        string $responseBody,
        int $durationMs,
    ): void {
        $this->responseStatus = $status;
        $this->responseHeaders = $responseHeaders;
        $this->responseBody = $this->truncate($responseBody);
        $this->durationMs = $durationMs;
    }

    /**
     * @param array<string, string> $responseHeaders
     */
    public function markFailed(
        ?int $status,
        array $responseHeaders,
        string $responseBody,
        int $durationMs,
    ): void {
        $this->recordResponse($status, $responseHeaders, $responseBody, $durationMs);
        $this->status = 'failed';
        $this->nextRetryAt = null;
    }

    public function scheduleRetry(int $delaySeconds): void
    {
        $this->status = 'retrying';
        $this->nextRetryAt = (new \DateTimeImmutable())->modify('+' . max(0, $delaySeconds) . ' seconds');
    }

    public function beginNextAttempt(): void
    {
        $this->attempt++;
        $this->status = 'pending';
        $this->nextRetryAt = null;
        $this->responseStatus = null;
        $this->responseHeaders = null;
        $this->responseBody = null;
        $this->durationMs = null;
        $this->deliveredAt = null;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'pending' => 'webhooks.delivery.status.pending',
            'delivered' => 'webhooks.delivery.status.delivered',
            'failed' => 'webhooks.delivery.status.failed',
            'retrying' => 'webhooks.delivery.status.retrying',
            default => $this->status,
        };
    }

    private function truncate(string $body): string
    {
        if (strlen($body) <= 65535) {
            return $body;
        }

        return substr($body, 0, 65535);
    }
}
