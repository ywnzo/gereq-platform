<?php

declare(strict_types=1);

namespace Modules\Orders\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'invoice_payments')]
#[ORM\Index(name: 'idx_invoice_payments_invoice', columns: ['invoice_id'])]
class InvoicePayment
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'invoice_id', length: 36)]
    private string $invoiceId;

    #[ORM\Column(type: 'decimal', precision: 14, scale: 2)]
    private string $amount;

    #[ORM\Column(name: 'paid_at')]
    private \DateTimeImmutable $paidAt;

    #[ORM\Column(length: 64)]
    private string $method;

    public function __construct(string $invoiceId, string $amount, string $method, ?\DateTimeImmutable $paidAt = null)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->invoiceId = $invoiceId;
        $this->amount = number_format((float) $amount, 2, '.', '');
        $this->method = $method;
        $this->paidAt = $paidAt ?? new \DateTimeImmutable();
    }

    public function id(): string { return $this->id; }
    public function invoiceId(): string { return $this->invoiceId; }
    public function amount(): string { return $this->amount; }
    public function paidAt(): \DateTimeImmutable { return $this->paidAt; }
    public function method(): string { return $this->method; }
}
