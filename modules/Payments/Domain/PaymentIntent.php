<?php

declare(strict_types=1);

namespace Modules\Payments\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'payment_intents')]
#[ORM\Index(name: 'idx_payment_intents_order', columns: ['order_id'])]
#[ORM\Index(name: 'idx_payment_intents_status', columns: ['status'])]
#[ORM\Index(name: 'idx_payment_intents_external', columns: ['provider', 'external_id'])]
class PaymentIntent
{
    public const STATUSES = ['pending', 'redirect', 'paid', 'failed', 'cancelled', 'refunded'];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'order_id', length: 36)]
    private string $orderId;

    #[ORM\Column(name: 'payment_method_id', length: 36, nullable: true)]
    private ?string $paymentMethodId = null;

    #[ORM\Column(name: 'method_code', length: 64)]
    private string $methodCode;

    #[ORM\Column(length: 64)]
    private string $provider = 'manual';

    #[ORM\Column(type: 'decimal', precision: 14, scale: 2)]
    private string $amount = '0.00';

    #[ORM\Column(length: 3)]
    private string $currency = 'CZK';

    #[ORM\Column(length: 32)]
    private string $status = 'pending';

    #[ORM\Column(name: 'external_id', length: 190, nullable: true)]
    private ?string $externalId = null;

    #[ORM\Column(name: 'redirect_url', type: 'text', nullable: true)]
    private ?string $redirectUrl = null;

    #[ORM\Column(name: 'payment_reference', length: 64, nullable: true)]
    private ?string $paymentReference = null;

    #[ORM\Column(name: 'spayd_payload', type: 'text', nullable: true)]
    private ?string $spaydPayload = null;

    #[ORM\Column(name: 'meta_json', type: 'text', nullable: true)]
    private ?string $metaJson = null;

    #[ORM\Column(name: 'paid_at', nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $orderId, string $methodCode, string $amount, string $currency = 'CZK', string $provider = 'manual')
    {
        $this->id = Uuid::uuid7()->toString();
        $this->orderId = $orderId;
        $this->methodCode = strtoupper(trim($methodCode));
        $this->amount = number_format((float) $amount, 2, '.', '');
        $this->currency = strtoupper($currency);
        $this->provider = strtolower(trim($provider)) ?: 'manual';
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function orderId(): string
    {
        return $this->orderId;
    }

    public function paymentMethodId(): ?string
    {
        return $this->paymentMethodId;
    }

    public function methodCode(): string
    {
        return $this->methodCode;
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function amount(): string
    {
        return $this->amount;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function externalId(): ?string
    {
        return $this->externalId;
    }

    public function redirectUrl(): ?string
    {
        return $this->redirectUrl;
    }

    public function paymentReference(): ?string
    {
        return $this->paymentReference;
    }

    public function spaydPayload(): ?string
    {
        return $this->spaydPayload;
    }

    /** @return array<string, mixed> */
    public function meta(): array
    {
        if ($this->metaJson === null || $this->metaJson === '') {
            return [];
        }
        $decoded = json_decode($this->metaJson, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function paidAt(): ?\DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setPaymentMethodId(?string $id): void
    {
        $this->paymentMethodId = $id;
        $this->touch();
    }

    public function setStatus(string $status): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('payments.error.invalid_intent_status');
        }
        $this->status = $status;
        if ($status === 'paid' && $this->paidAt === null) {
            $this->paidAt = new \DateTimeImmutable();
        }
        $this->touch();
    }

    public function setExternalId(?string $externalId): void
    {
        $this->externalId = $externalId !== '' ? $externalId : null;
        $this->touch();
    }

    public function setRedirectUrl(?string $url): void
    {
        $this->redirectUrl = $url !== '' ? $url : null;
        $this->touch();
    }

    public function setPaymentReference(?string $ref): void
    {
        $this->paymentReference = $ref !== '' ? $ref : null;
        $this->touch();
    }

    public function setSpaydPayload(?string $payload): void
    {
        $this->spaydPayload = $payload !== '' ? $payload : null;
        $this->touch();
    }

    /** @param array<string, mixed> $meta */
    public function setMeta(array $meta): void
    {
        $this->metaJson = $meta === [] ? null : (json_encode($meta, JSON_UNESCAPED_UNICODE) ?: null);
        $this->touch();
    }

    public function markPaid(?string $externalId = null): void
    {
        if ($externalId !== null) {
            $this->setExternalId($externalId);
        }
        $this->setStatus('paid');
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
