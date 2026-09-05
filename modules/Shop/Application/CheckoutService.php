<?php

declare(strict_types=1);

namespace Modules\Shop\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Event\EventBus;
use Gereq\Module\CapabilityRegistry;
use Gereq\Setting\Settings;
use Modules\Coupons\Application\CouponsProvider;
use Modules\Coupons\Domain\Coupon;
use Modules\Customers\Application\StoreAuthService;
use Modules\Orders\Application\NumberSequencer;
use Modules\Orders\Application\OrderTotals;
use Modules\Orders\Domain\Order;
use Modules\Orders\Domain\OrderLine;
use Modules\Payments\Application\PaymentService;
use Modules\Payments\Domain\PaymentMethod;
use Modules\Shop\Domain\Cart;
use Modules\Shop\Domain\CartItem;
use Modules\Shop\Domain\Shipment;
use Modules\Shop\Domain\ShippingMethod;
use Modules\Warehouse\Application\StockService;
use Modules\Warehouse\Domain\Warehouse;
use Psr\Container\ContainerInterface;

final class CheckoutService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EventBus $events,
        private readonly Settings $settings,
        private readonly CapabilityRegistry $capabilities,
        private readonly CartService $carts,
        private readonly ContainerInterface $container,
    ) {
    }

    /**
     * @param array<string, mixed>|null $billingAddress
     * @param array<string, mixed>|null $shippingAddress
     * @return array{
     *   cart: Cart,
     *   order_id: ?string,
     *   order_number: ?string,
     *   access_token: ?string,
     *   totals: array{subtotal: string, shipping: string, payment_fee: string, discount: string, total: string, currency: string, item_count: int},
     *   shipping: array<string, mixed>,
     *   payment: array<string, mixed>,
     *   payment_intent: ?array<string, mixed>,
     *   coupon: ?array<string, mixed>,
     *   mode: 'order'|'cart'
     * }
     */
    public function checkout(
        Cart $cart,
        ?string $shippingMethodCode = null,
        ?string $customerId = null,
        ?string $note = null,
        ?string $warehouseId = null,
        ?string $paymentMethodCode = null,
        ?string $couponCode = null,
        ?array $billingAddress = null,
        ?array $shippingAddress = null,
        ?string $parcelPointId = null,
        ?string $parcelPointLabel = null,
        ?string $customerEmail = null,
        ?string $customerName = null,
        bool $createPaymentIntent = true,
    ): array {
        if (!(bool) $this->settings->get('shop.checkout_enabled', true)) {
            throw new \RuntimeException('shop.error.checkout_disabled');
        }
        if (!$cart->isOpen()) {
            throw new \RuntimeException('shop.error.cart_not_open');
        }

        $items = $this->carts->items($cart->id());
        if ($items === []) {
            throw new \RuntimeException('shop.error.cart_empty');
        }

        if ($customerId !== null && $customerId !== '') {
            $cart->setCustomerId($customerId);
        } elseif (($customerEmail ?? '') !== '' && class_exists(StoreAuthService::class) && $this->container->has(StoreAuthService::class)) {
            /** @var StoreAuthService $storeAuth */
            $storeAuth = $this->container->get(StoreAuthService::class);
            $account = $storeAuth->softRegister(
                $customerEmail,
                $customerName ?: $customerEmail,
                is_array($billingAddress) ? (string) ($billingAddress['phone'] ?? '') : null,
            );
            $cart->setCustomerId($account->customerId());
        }

        $baseTotals = $this->carts->totals($cart);
        $coupon = $this->resolveCoupon($couponCode, $baseTotals['subtotal']);
        $shipping = $this->resolveShipping($shippingMethodCode, $cart->currency(), $parcelPointId, $parcelPointLabel);
        $subtotalAfterDiscount = max(0, (float) $baseTotals['subtotal'] - (float) $coupon['discount']);
        $payment = $this->resolvePayment($paymentMethodCode, number_format($subtotalAfterDiscount, 2, '.', ''));
        $totals = $this->carts->totals($cart, $shipping['price'], $payment['fee']);
        $totals['discount'] = $coupon['discount'];
        $totals['total'] = number_format(max(0, (float) $totals['total'] - (float) $coupon['discount']), 2, '.', '');

        $orderId = null;
        $orderNumber = null;
        $accessToken = null;
        $paymentIntentPayload = null;
        $mode = 'cart';

        if ($this->capabilities->has('orders') && class_exists(Order::class)) {
            $result = $this->createOrder($cart, $items, $shipping, $payment, $coupon, $note, $billingAddress, $shippingAddress, $totals);
            $orderId = $result['order_id'];
            $orderNumber = $result['order_number'];
            $accessToken = $result['access_token'];
            $mode = 'order';

            if ($createPaymentIntent && $payment['code'] !== null && class_exists(PaymentService::class) && $this->container->has(PaymentService::class)) {
                $order = $this->em->find(Order::class, $orderId);
                if ($order instanceof Order) {
                    /** @var PaymentService $payments */
                    $payments = $this->container->get(PaymentService::class);
                    $intent = $payments->createForOrder($order, (string) $payment['code'], [
                        'order_number' => $orderNumber,
                        'customer_email' => $customerEmail,
                    ]);
                    $paymentIntentPayload = $payments->serialize($intent);
                }
            }

            if ($shipping['code'] !== null) {
                $this->createShipmentDraft($orderId, $shipping);
            }

            $cart->mergeMeta([
                'order_id' => $orderId,
                'order_number' => $orderNumber,
                'shipping' => $shipping,
                'payment' => $payment,
                'coupon' => $coupon,
                'totals' => $totals,
                'billing_address' => $billingAddress,
                'shipping_address' => $shippingAddress,
                'note' => $note,
            ]);
        } else {
            $cart->mergeMeta([
                'checkout' => [
                    'completed_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                    'note' => $note,
                ],
                'shipping' => $shipping,
                'payment' => $payment,
                'coupon' => $coupon,
                'totals' => $totals,
                'items' => array_map(fn (CartItem $i): array => $this->carts->serializeItem($i), $items),
            ]);
        }

        $this->decrementStock($items, $warehouseId);
        $cart->setStatus('converted');
        $this->em->flush();

        $this->events->emit('cart.converted', ['type' => 'cart', 'id' => $cart->id()], $this->carts->serialize($cart));
        $this->events->emit('checkout.completed', ['type' => 'checkout', 'id' => $cart->id()], [
            'cart_id' => $cart->id(),
            'order_id' => $orderId,
            'order_number' => $orderNumber,
            'mode' => $mode,
            'totals' => $totals,
            'shipping' => $shipping,
            'payment' => $payment,
            'coupon' => $coupon,
            'customer_id' => $cart->customerId(),
        ]);

        return [
            'cart' => $cart,
            'order_id' => $orderId,
            'order_number' => $orderNumber,
            'access_token' => $accessToken,
            'totals' => $totals,
            'shipping' => $shipping,
            'payment' => $payment,
            'payment_intent' => $paymentIntentPayload,
            'coupon' => $coupon['code'] !== null ? $coupon : null,
            'mode' => $mode,
        ];
    }

    /**
     * @param list<CartItem> $items
     * @param array<string, mixed> $shipping
     * @param array<string, mixed> $payment
     * @param array{code: ?string, discount: string, message: string} $coupon
     * @param array<string, mixed>|null $billingAddress
     * @param array<string, mixed>|null $shippingAddress
     * @param array{subtotal: string, shipping: string, payment_fee: string, discount: string, total: string, currency: string, item_count: int} $totals
     * @return array{order_id: string, order_number: string, access_token: string}
     */
    private function createOrder(
        Cart $cart,
        array $items,
        array $shipping,
        array $payment,
        array $coupon,
        ?string $note,
        ?array $billingAddress,
        ?array $shippingAddress,
        array $totals,
    ): array {
        $customerId = $cart->customerId();
        if ($customerId === null || $customerId === '') {
            throw new \RuntimeException('shop.error.customer_required');
        }

        /** @var NumberSequencer $numbers */
        $numbers = $this->container->get(NumberSequencer::class);
        $order = new Order($customerId, $numbers->nextOrderNumber(), $cart->currency());
        $order->setNote($note);
        $order->setCouponCode($coupon['code']);
        $order->setShipping(
            $shipping['code'],
            $shipping['name'],
            $shipping['price'],
            $shipping['parcel_point_id'] ?? null,
            $shipping['parcel_point_label'] ?? null,
        );
        $order->setPayment($payment['code'], $payment['name'], 'unpaid');
        $order->setBillingAddress($billingAddress);
        $order->setShippingAddress($shippingAddress ?? $billingAddress);

        $accessPlain = '';
        if (class_exists(StoreAuthService::class) && $this->container->has(StoreAuthService::class)) {
            /** @var StoreAuthService $storeAuth */
            $storeAuth = $this->container->get(StoreAuthService::class);
            $token = $storeAuth->issueOrderAccessToken();
            $accessPlain = $token['token'];
            $order->setAccessTokenHash($token['hash']);
        }

        $this->em->persist($order);

        $lines = [];
        foreach ($items as $item) {
            $line = new OrderLine($order->id(), $item->title(), $item->qty(), $item->unitPrice(), '0');
            $line->setProductId($item->productId());
            $line->setVariantId($item->variantId());
            $line->setSku($item->sku());
            $this->em->persist($line);
            $lines[] = $line;
        }

        if ((float) $shipping['price'] > 0) {
            $shipLine = new OrderLine($order->id(), $shipping['name'] ?? ('Shipping ' . ($shipping['code'] ?? '')), '1', $shipping['price'], '0');
            $this->em->persist($shipLine);
            $lines[] = $shipLine;
        }
        if ((float) $payment['fee'] > 0) {
            $feeLine = new OrderLine($order->id(), 'Payment fee: ' . ($payment['name'] ?? $payment['code'] ?? ''), '1', $payment['fee'], '0');
            $this->em->persist($feeLine);
            $lines[] = $feeLine;
        }

        OrderTotals::recalculate($order, $lines, $coupon['discount']);

        if ($coupon['code'] !== null && class_exists(CouponsProvider::class) && $this->container->has(CouponsProvider::class)) {
            /** @var CouponsProvider $coupons */
            $coupons = $this->container->get(CouponsProvider::class);
            $entity = $coupons->findByCode($coupon['code']);
            if ($entity instanceof Coupon) {
                $entity->incrementUsedCount();
            }
        }

        $this->em->flush();

        $this->events->emit('order.created', ['type' => 'order', 'id' => $order->id()], [
            'id' => $order->id(),
            'customer_id' => $order->customerId(),
            'number' => $order->number(),
            'status' => $order->status(),
            'currency' => $order->currency(),
            'subtotal' => $order->subtotal(),
            'discount_total' => $order->discountTotal(),
            'tax_total' => $order->taxTotal(),
            'total' => $order->total(),
            'shipping' => $shipping,
            'payment' => $payment,
            'coupon' => $coupon,
            'source' => 'shop.checkout',
        ]);

        return [
            'order_id' => $order->id(),
            'order_number' => $order->number(),
            'access_token' => $accessPlain,
        ];
    }

    /** @param array<string, mixed> $shipping */
    private function createShipmentDraft(string $orderId, array $shipping): void
    {
        $carrier = strtolower((string) ($shipping['carrier'] ?? 'manual')) ?: 'manual';
        $shipment = new Shipment($orderId, $carrier);
        $shipment->setShippingMethodCode($shipping['code'] ?? null);
        $shipment->setParcelPointId($shipping['parcel_point_id'] ?? null);
        $shipment->setStatus('draft');
        $this->em->persist($shipment);

        if ($this->container->has(ShippingCarrierRegistry::class)) {
            /** @var ShippingCarrierRegistry $registry */
            $registry = $this->container->get(ShippingCarrierRegistry::class);
            $driver = $registry->getOrManual($carrier);
            $result = $driver->createShipment($shipment, $shipping);
            $shipment->setStatus($result['status'] ?? 'created');
            $shipment->setTrackingNumber($result['tracking_number'] ?? null);
            $shipment->setExternalId($result['external_id'] ?? null);
            $shipment->setLabelUrl($result['label_url'] ?? null);
            $shipment->setMeta($result['meta'] ?? []);
            $order = $this->em->find(Order::class, $orderId);
            if ($order instanceof Order && !empty($result['tracking_number'])) {
                $order->setTrackingNumber($result['tracking_number']);
            }
        }
    }

    /** @return array{code: ?string, discount: string, message: string} */
    private function resolveCoupon(?string $code, string $subtotal): array
    {
        if ($code === null || trim($code) === '') {
            return ['code' => null, 'discount' => '0.00', 'message' => ''];
        }
        if (!$this->capabilities->has('coupons') || !class_exists(CouponsProvider::class) || !$this->container->has(CouponsProvider::class)) {
            throw new \InvalidArgumentException('shop.error.invalid_coupon');
        }
        /** @var CouponsProvider $coupons */
        $coupons = $this->container->get(CouponsProvider::class);
        $result = $coupons->validateForCart($code, $subtotal);
        if (!$result['ok']) {
            throw new \InvalidArgumentException($result['message'] !== '' ? $result['message'] : 'shop.error.invalid_coupon');
        }

        return [
            'code' => strtoupper(trim($code)),
            'discount' => $result['discount'],
            'message' => $result['message'],
        ];
    }

    /**
     * @return array{code: ?string, name: ?string, price: string, currency: string, estimated_days: ?int, carrier: ?string, type: ?string, parcel_point_id: ?string, parcel_point_label: ?string}
     */
    private function resolveShipping(?string $code, string $currency, ?string $parcelPointId, ?string $parcelPointLabel): array
    {
        if ($code === null || $code === '') {
            return [
                'code' => null,
                'name' => null,
                'price' => '0.00',
                'currency' => $currency,
                'estimated_days' => null,
                'carrier' => null,
                'type' => null,
                'parcel_point_id' => null,
                'parcel_point_label' => null,
            ];
        }

        $method = $this->em->getRepository(ShippingMethod::class)->findOneBy(['code' => strtoupper(trim($code))]);
        if (!$method instanceof ShippingMethod || !$method->isActive()) {
            throw new \InvalidArgumentException('shop.error.invalid_shipping');
        }

        if (in_array($method->type(), ['parcel_shop'], true) && ($parcelPointId === null || $parcelPointId === '')) {
            throw new \InvalidArgumentException('shop.error.parcel_point_required');
        }

        return [
            'code' => $method->code(),
            'name' => $method->name(),
            'price' => $method->price(),
            'currency' => $method->currency(),
            'estimated_days' => $method->estimatedDays(),
            'carrier' => $method->carrier(),
            'type' => $method->type(),
            'parcel_point_id' => $parcelPointId,
            'parcel_point_label' => $parcelPointLabel,
        ];
    }

    /**
     * @return array{code: ?string, name: ?string, type: ?string, provider: ?string, fee: string, currency: string, instructions: ?string}
     */
    private function resolvePayment(?string $code, string $subtotal): array
    {
        if ($code === null || trim($code) === '') {
            return [
                'code' => null,
                'name' => null,
                'type' => null,
                'provider' => null,
                'fee' => '0.00',
                'currency' => 'CZK',
                'instructions' => null,
            ];
        }

        if (!$this->capabilities->has('payments') || !class_exists(PaymentMethod::class)) {
            throw new \InvalidArgumentException('shop.error.invalid_payment');
        }

        /** @var \Modules\Payments\Application\PaymentsProvider $payments */
        $payments = $this->container->get(\Modules\Payments\Application\PaymentsProvider::class);
        $method = $payments->findByCode($code);
        if (!$method instanceof PaymentMethod || !$method->isActive()) {
            throw new \InvalidArgumentException('shop.error.invalid_payment');
        }

        return [
            'code' => $method->code(),
            'name' => $method->name(),
            'type' => $method->type(),
            'provider' => $method->provider(),
            'fee' => $method->feeForAmount($subtotal),
            'currency' => $method->currency(),
            'instructions' => $method->instructions(),
        ];
    }

    /** @param list<CartItem> $items */
    private function decrementStock(array $items, ?string $warehouseId): void
    {
        if ($warehouseId === null || $warehouseId === '') {
            return;
        }
        if (!class_exists(StockService::class) || !$this->container->has(StockService::class)) {
            return;
        }
        if (!class_exists(Warehouse::class)) {
            return;
        }

        $warehouse = $this->em->find(Warehouse::class, $warehouseId);
        if (!$warehouse instanceof Warehouse || !$warehouse->isActive()) {
            throw new \InvalidArgumentException('shop.error.invalid_warehouse');
        }

        /** @var StockService $stock */
        $stock = $this->container->get(StockService::class);
        foreach ($items as $item) {
            $stock->createMovement(
                $warehouseId,
                $item->productId(),
                'out',
                $item->qty(),
                null,
                'Shop checkout cart ' . $item->cartId(),
            );
        }
    }
}
