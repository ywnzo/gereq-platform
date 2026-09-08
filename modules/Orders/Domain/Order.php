<?php

declare(strict_types=1);

namespace Modules\Orders\Domain;

use Doctrine\ORM\Mapping as ORM;
use Gereq\Admin\Form\FormEntity;
use Gereq\Admin\Form\FormField;
use Gereq\Admin\Form\FormGenerator;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'orders')]
#[ORM\Index(name: 'idx_orders_customer', columns: ['customer_id'])]
#[ORM\Index(name: 'idx_orders_status', columns: ['status'])]
#[ORM\UniqueConstraint(name: 'uniq_orders_number', columns: ['number'])]
#[FormEntity('orders.save')]
#[FormGenerator(
    exclude: [
        'id', 'number', 'status', 'subtotal', 'taxTotal', 'discountTotal', 'total',
        'couponCode', 'shippingMethodCode', 'shippingMethodName', 'shippingPrice',
        'parcelPointId', 'parcelPointLabel', 'paymentMethodCode', 'paymentMethodName',
        'paymentStatus', 'trackingNumber', 'billingAddressJson', 'shippingAddressJson',
        'accessTokenHash', 'createdAt', 'updatedAt',
    ],
    form: 'orders.save',
    cols: 2,
)]
class Order
{
    public const STATUSES = ['draft', 'confirmed', 'fulfilled', 'cancelled'];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'customer_id', length: 36)]
    #[FormField(required: true, name: 'customer_id', label: 'orders.field.customer', type: 'select', order: 10)]
    private string $customerId;

    #[ORM\Column(length: 64)]
    private string $number;

    #[ORM\Column(length: 32)]
    private string $status = 'draft';

    #[ORM\Column(length: 3)]
    #[FormField(label: 'orders.field.currency', options: ['maxlength' => 3], order: 20)]
    private string $currency = 'CZK';

    #[ORM\Column(type: 'decimal', precision: 14, scale: 2)]
    private string $subtotal = '0.00';

    #[ORM\Column(name: 'tax_total', type: 'decimal', precision: 14, scale: 2)]
    private string $taxTotal = '0.00';

    #[ORM\Column(name: 'discount_total', type: 'decimal', precision: 14, scale: 2, options: ['default' => '0.00'])]
    private string $discountTotal = '0.00';

    #[ORM\Column(type: 'decimal', precision: 14, scale: 2)]
    private string $total = '0.00';

    #[ORM\Column(name: 'coupon_code', length: 64, nullable: true)]
    private ?string $couponCode = null;

    #[ORM\Column(name: 'shipping_method_code', length: 64, nullable: true)]
    private ?string $shippingMethodCode = null;

    #[ORM\Column(name: 'shipping_method_name', length: 190, nullable: true)]
    private ?string $shippingMethodName = null;

    #[ORM\Column(name: 'shipping_price', type: 'decimal', precision: 14, scale: 2, options: ['default' => '0.00'])]
    private string $shippingPrice = '0.00';

    #[ORM\Column(name: 'parcel_point_id', length: 64, nullable: true)]
    private ?string $parcelPointId = null;

    #[ORM\Column(name: 'parcel_point_label', length: 190, nullable: true)]
    private ?string $parcelPointLabel = null;

    #[ORM\Column(name: 'payment_method_code', length: 64, nullable: true)]
    private ?string $paymentMethodCode = null;

    #[ORM\Column(name: 'payment_method_name', length: 190, nullable: true)]
    private ?string $paymentMethodName = null;

    #[ORM\Column(name: 'payment_status', length: 32, options: ['default' => 'unpaid'])]
    private string $paymentStatus = 'unpaid';

    #[ORM\Column(name: 'tracking_number', length: 128, nullable: true)]
    private ?string $trackingNumber = null;

    #[ORM\Column(name: 'billing_address_json', type: 'text', nullable: true)]
    private ?string $billingAddressJson = null;

    #[ORM\Column(name: 'shipping_address_json', type: 'text', nullable: true)]
    private ?string $shippingAddressJson = null;

    #[ORM\Column(name: 'access_token_hash', length: 64, nullable: true)]
    private ?string $accessTokenHash = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[FormField(label: 'orders.field.note', type: 'textarea', extra: 'field--full', order: 30)]
    private ?string $note = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $customerId, string $number, string $currency = 'CZK')
    {
        $this->id = Uuid::uuid7()->toString();
        $this->customerId = $customerId;
        $this->number = $number;
        $this->currency = strtoupper($currency);
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function customerId(): string
    {
        return $this->customerId;
    }

    public function number(): string
    {
        return $this->number;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function subtotal(): string
    {
        return $this->subtotal;
    }

    public function taxTotal(): string
    {
        return $this->taxTotal;
    }

    public function discountTotal(): string
    {
        return $this->discountTotal;
    }

    public function total(): string
    {
        return $this->total;
    }

    public function couponCode(): ?string
    {
        return $this->couponCode;
    }

    public function shippingMethodCode(): ?string
    {
        return $this->shippingMethodCode;
    }

    public function shippingMethodName(): ?string
    {
        return $this->shippingMethodName;
    }

    public function shippingPrice(): string
    {
        return $this->shippingPrice;
    }

    public function parcelPointId(): ?string
    {
        return $this->parcelPointId;
    }

    public function parcelPointLabel(): ?string
    {
        return $this->parcelPointLabel;
    }

    public function paymentMethodCode(): ?string
    {
        return $this->paymentMethodCode;
    }

    public function paymentMethodName(): ?string
    {
        return $this->paymentMethodName;
    }

    public function paymentStatus(): string
    {
        return $this->paymentStatus;
    }

    public function trackingNumber(): ?string
    {
        return $this->trackingNumber;
    }

    /** @return array<string, mixed>|null */
    public function billingAddress(): ?array
    {
        return $this->decodeAddress($this->billingAddressJson);
    }

    /** @return array<string, mixed>|null */
    public function shippingAddress(): ?array
    {
        return $this->decodeAddress($this->shippingAddressJson);
    }

    public function accessTokenHash(): ?string
    {
        return $this->accessTokenHash;
    }

    public function note(): ?string
    {
        return $this->note;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setCustomerId(string $customerId): void
    {
        $this->customerId = $customerId;
        $this->touch();
    }

    public function setCurrency(string $currency): void
    {
        $this->currency = strtoupper($currency);
        $this->touch();
    }

    public function setNote(?string $note): void
    {
        $this->note = $note !== '' ? $note : null;
        $this->touch();
    }

    public function setStatus(string $status): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('orders.error.invalid_status');
        }
        $this->status = $status;
        $this->touch();
    }

    public function setTotals(string $subtotal, string $taxTotal, string $total, string $discountTotal = '0'): void
    {
        $this->subtotal = number_format((float) $subtotal, 2, '.', '');
        $this->taxTotal = number_format((float) $taxTotal, 2, '.', '');
        $this->discountTotal = number_format((float) $discountTotal, 2, '.', '');
        $this->total = number_format((float) $total, 2, '.', '');
        $this->touch();
    }

    public function setCouponCode(?string $code): void
    {
        $code = $code !== null ? strtoupper(trim($code)) : null;
        $this->couponCode = $code !== '' ? $code : null;
        $this->touch();
    }

    public function setShipping(?string $code, ?string $name, string $price = '0', ?string $parcelPointId = null, ?string $parcelPointLabel = null): void
    {
        $this->shippingMethodCode = $code;
        $this->shippingMethodName = $name;
        $this->shippingPrice = number_format((float) $price, 2, '.', '');
        $this->parcelPointId = $parcelPointId !== '' ? $parcelPointId : null;
        $this->parcelPointLabel = $parcelPointLabel !== '' ? $parcelPointLabel : null;
        $this->touch();
    }

    public function setPayment(?string $code, ?string $name, string $status = 'unpaid'): void
    {
        $this->paymentMethodCode = $code;
        $this->paymentMethodName = $name;
        $this->paymentStatus = $status !== '' ? $status : 'unpaid';
        $this->touch();
    }

    public function setPaymentStatus(string $status): void
    {
        $this->paymentStatus = $status;
        $this->touch();
    }

    public function setTrackingNumber(?string $trackingNumber): void
    {
        $this->trackingNumber = $trackingNumber !== '' ? $trackingNumber : null;
        $this->touch();
    }

    /** @param array<string, mixed>|null $address */
    public function setBillingAddress(?array $address): void
    {
        $this->billingAddressJson = $this->encodeAddress($address);
        $this->touch();
    }

    /** @param array<string, mixed>|null $address */
    public function setShippingAddress(?array $address): void
    {
        $this->shippingAddressJson = $this->encodeAddress($address);
        $this->touch();
    }

    public function setAccessTokenHash(?string $hash): void
    {
        $this->accessTokenHash = $hash;
        $this->touch();
    }

    public function statusLabel(): string
    {
        return 'orders.status.' . $this->status;
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function canEdit(): bool
    {
        return $this->status === 'draft';
    }

    /** @param array<string, mixed>|null $address */
    private function encodeAddress(?array $address): ?string
    {
        if ($address === null || $address === []) {
            return null;
        }

        return json_encode($address, JSON_UNESCAPED_UNICODE) ?: null;
    }

    /** @return array<string, mixed>|null */
    private function decodeAddress(?string $json): ?array
    {
        if ($json === null || $json === '') {
            return null;
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
