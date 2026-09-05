<?php

declare(strict_types=1);

namespace Modules\Orders\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'invoices')]
#[ORM\Index(name: 'idx_invoices_order', columns: ['order_id'])]
#[ORM\UniqueConstraint(name: 'uniq_invoices_number', columns: ['number'])]
class Invoice
{
    public const STATUSES = ['draft', 'issued', 'paid', 'cancelled'];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'order_id', length: 36)]
    private string $orderId;

    #[ORM\Column(length: 64)]
    private string $number;

    #[ORM\Column(length: 32)]
    private string $status = 'draft';

    #[ORM\Column(name: 'issued_at', nullable: true)]
    private ?\DateTimeImmutable $issuedAt = null;

    #[ORM\Column(name: 'due_at', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $dueAt = null;

    #[ORM\Column(type: 'decimal', precision: 14, scale: 2)]
    private string $total = '0.00';

    #[ORM\Column(length: 3, options: ['default' => 'CZK'])]
    private string $currency = 'CZK';

    #[ORM\Column(name: 'seller_json', type: 'text', nullable: true)]
    private ?string $sellerJson = null;

    #[ORM\Column(name: 'buyer_json', type: 'text', nullable: true)]
    private ?string $buyerJson = null;

    #[ORM\Column(name: 'payment_reference', length: 64, nullable: true)]
    private ?string $paymentReference = null;

    #[ORM\Column(name: 'spayd_payload', type: 'text', nullable: true)]
    private ?string $spaydPayload = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $orderId, string $number, string $total)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->orderId = $orderId;
        $this->number = $number;
        $this->total = number_format((float) $total, 2, '.', '');
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function id(): string { return $this->id; }
    public function orderId(): string { return $this->orderId; }
    public function number(): string { return $this->number; }
    public function status(): string { return $this->status; }
    public function issuedAt(): ?\DateTimeImmutable { return $this->issuedAt; }
    public function dueAt(): ?\DateTimeImmutable { return $this->dueAt; }
    public function total(): string { return $this->total; }
    public function currency(): string { return $this->currency; }
    /** @return array<string, mixed> */
    public function seller(): array { return $this->decodeParty($this->sellerJson); }
    /** @return array<string, mixed> */
    public function buyer(): array { return $this->decodeParty($this->buyerJson); }
    public function paymentReference(): ?string { return $this->paymentReference; }
    public function spaydPayload(): ?string { return $this->spaydPayload; }
    public function createdAt(): \DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function setTotal(string $total): void { $this->total = number_format((float) $total, 2, '.', ''); $this->touch(); }
    public function setCurrency(string $currency): void { $this->currency = strtoupper($currency); $this->touch(); }
    public function setDueAt(?\DateTimeImmutable $dueAt): void { $this->dueAt = $dueAt; $this->touch(); }

    /** @param array<string, mixed> $seller keys: name, ico, dic, address, email, bank_account… */
    public function setSeller(array $seller): void
    {
        $this->sellerJson = $seller === [] ? null : (json_encode($seller, JSON_UNESCAPED_UNICODE) ?: null);
        $this->touch();
    }

    /** @param array<string, mixed> $buyer */
    public function setBuyer(array $buyer): void
    {
        $this->buyerJson = $buyer === [] ? null : (json_encode($buyer, JSON_UNESCAPED_UNICODE) ?: null);
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

    public function setStatus(string $status): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('orders.error.invalid_invoice_status');
        }
        $this->status = $status;
        $this->touch();
    }

    public function issue(?\DateTimeImmutable $issuedAt = null, ?\DateTimeImmutable $dueAt = null): void
    {
        $this->status = 'issued';
        $this->issuedAt = $issuedAt ?? new \DateTimeImmutable();
        $this->dueAt = $dueAt;
        $this->touch();
    }

    public function markPaid(): void
    {
        $this->status = 'paid';
        $this->touch();
    }

    public function statusLabel(): string { return 'orders.invoice.status.' . $this->status; }

    /** @return array<string, mixed> */
    private function decodeParty(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }
}
