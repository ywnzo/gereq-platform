<?php

declare(strict_types=1);

namespace Modules\Shop\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Event\EventBus;
use Gereq\Setting\Settings;
use Modules\Products\Domain\Product;
use Modules\Products\Domain\ProductPrice;
use Modules\Shop\Domain\Cart;
use Modules\Shop\Domain\CartItem;

final class CartService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EventBus $events,
        private readonly Settings $settings,
    ) {
    }

    public function resolveOrCreate(?string $customerId = null, ?string $sessionKey = null, ?string $currency = null): Cart
    {
        $customerId = $customerId !== null && $customerId !== '' ? $customerId : null;
        $sessionKey = $sessionKey !== null && $sessionKey !== '' ? $sessionKey : null;
        $currency = strtoupper($currency ?: (string) $this->settings->get('shop.default_currency', 'CZK')) ?: 'CZK';

        $cart = null;
        if ($customerId !== null) {
            $cart = $this->em->getRepository(Cart::class)->findOneBy([
                'customerId' => $customerId,
                'status' => 'open',
            ], ['updatedAt' => 'DESC']);
        }
        if (!$cart instanceof Cart && $sessionKey !== null) {
            $cart = $this->em->getRepository(Cart::class)->findOneBy([
                'sessionKey' => $sessionKey,
                'status' => 'open',
            ], ['updatedAt' => 'DESC']);
        }

        if ($cart instanceof Cart) {
            if ($customerId !== null && $cart->customerId() === null) {
                $cart->setCustomerId($customerId);
            }
            if ($sessionKey !== null && $cart->sessionKey() === null) {
                $cart->setSessionKey($sessionKey);
            }
            if ($cart->currency() !== $currency && $this->items($cart->id()) === []) {
                $cart->setCurrency($currency);
            }
            $this->em->flush();

            return $cart;
        }

        $cart = new Cart($customerId, $sessionKey, $currency);
        $this->em->persist($cart);
        $this->em->flush();
        $this->emitUpdated($cart);

        return $cart;
    }

    public function find(string $id): ?Cart
    {
        $cart = $this->em->find(Cart::class, $id);

        return $cart instanceof Cart ? $cart : null;
    }

    /** @return list<CartItem> */
    public function items(string $cartId): array
    {
        return $this->em->getRepository(CartItem::class)->findBy(['cartId' => $cartId], ['title' => 'ASC']);
    }

    public function addItem(Cart $cart, string $productId, string $qty = '1'): CartItem
    {
        $this->assertOpen($cart);
        $qtyFloat = (float) $qty;
        if ($qtyFloat <= 0) {
            throw new \InvalidArgumentException('shop.error.invalid_qty');
        }

        $product = $this->em->find(Product::class, $productId);
        if (!$product instanceof Product) {
            throw new \InvalidArgumentException('shop.error.invalid_product');
        }
        if ($product->status() !== 'active') {
            throw new \InvalidArgumentException('shop.error.product_not_active');
        }

        $existing = $this->em->getRepository(CartItem::class)->findOneBy([
            'cartId' => $cart->id(),
            'productId' => $productId,
        ]);
        if ($existing instanceof CartItem) {
            $existing->setQty((string) ((float) $existing->qty() + $qtyFloat));
            $existing->setTitle($product->name());
            $existing->setSku($product->sku());
            $this->em->flush();
            $this->emitUpdated($cart);

            return $existing;
        }

        $unitPrice = $this->resolvePrice($productId, $cart->currency());
        $item = new CartItem(
            $cart->id(),
            $productId,
            $product->name(),
            $product->sku(),
            (string) $qtyFloat,
            $unitPrice,
            $cart->currency(),
        );
        $this->em->persist($item);
        $this->em->flush();
        $this->emitUpdated($cart);

        return $item;
    }

    public function updateItem(Cart $cart, string $itemId, string $qty): ?CartItem
    {
        $this->assertOpen($cart);
        $item = $this->em->find(CartItem::class, $itemId);
        if (!$item instanceof CartItem || $item->cartId() !== $cart->id()) {
            throw new \InvalidArgumentException('shop.error.item_not_found');
        }

        $qtyFloat = (float) $qty;
        if ($qtyFloat <= 0) {
            $this->em->remove($item);
            $this->em->flush();
            $this->emitUpdated($cart);

            return null;
        }

        $item->setQty((string) $qtyFloat);
        $this->em->flush();
        $this->emitUpdated($cart);

        return $item;
    }

    public function removeItem(Cart $cart, string $itemId): void
    {
        $this->assertOpen($cart);
        $item = $this->em->find(CartItem::class, $itemId);
        if (!$item instanceof CartItem || $item->cartId() !== $cart->id()) {
            throw new \InvalidArgumentException('shop.error.item_not_found');
        }
        $this->em->remove($item);
        $this->em->flush();
        $this->emitUpdated($cart);
    }

    /**
     * @return array{subtotal: string, shipping: string, payment_fee: string, total: string, currency: string, item_count: int}
     */
    public function totals(Cart $cart, string $shippingPrice = '0', string $paymentFee = '0'): array
    {
        $items = $this->items($cart->id());
        $subtotal = 0.0;
        foreach ($items as $item) {
            $subtotal += (float) $item->lineTotal();
        }
        $shipping = (float) $shippingPrice;
        $fee = (float) $paymentFee;
        $total = $subtotal + $shipping + $fee;

        return [
            'subtotal' => number_format($subtotal, 2, '.', ''),
            'shipping' => number_format($shipping, 2, '.', ''),
            'payment_fee' => number_format($fee, 2, '.', ''),
            'discount' => '0.00',
            'total' => number_format($total, 2, '.', ''),
            'currency' => $cart->currency(),
            'item_count' => count($items),
        ];
    }

    /** @return array<string, mixed> */
    public function serialize(Cart $cart, bool $withItems = true): array
    {
        $items = $withItems ? $this->items($cart->id()) : [];
        $totals = $this->totals($cart);

        $payload = [
            'id' => $cart->id(),
            'customer_id' => $cart->customerId(),
            'session_key' => $cart->sessionKey(),
            'currency' => $cart->currency(),
            'status' => $cart->status(),
            'meta' => $cart->meta(),
            'totals' => $totals,
            'created_at' => $cart->createdAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $cart->updatedAt()->format(\DateTimeInterface::ATOM),
        ];

        if ($withItems) {
            $payload['items'] = array_map(fn (CartItem $item): array => $this->serializeItem($item), $items);
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    public function serializeItem(CartItem $item): array
    {
        return [
            'id' => $item->id(),
            'cart_id' => $item->cartId(),
            'product_id' => $item->productId(),
            'title' => $item->title(),
            'sku' => $item->sku(),
            'qty' => $item->qty(),
            'unit_price' => $item->unitPrice(),
            'currency' => $item->currency(),
            'line_total' => $item->lineTotal(),
        ];
    }

    private function resolvePrice(string $productId, string $currency): string
    {
        $prices = $this->em->getRepository(ProductPrice::class)->findBy(
            ['productId' => $productId, 'currencyCode' => strtoupper($currency)],
            ['validFrom' => 'DESC'],
        );
        $now = new \DateTimeImmutable('today');
        foreach ($prices as $price) {
            if (!$price instanceof ProductPrice) {
                continue;
            }
            if ($price->validFrom() !== null && $price->validFrom() > $now) {
                continue;
            }
            if ($price->validTo() !== null && $price->validTo() < $now) {
                continue;
            }

            return number_format((float) $price->amount(), 2, '.', '');
        }

        return '0.00';
    }

    private function assertOpen(Cart $cart): void
    {
        if (!$cart->isOpen()) {
            throw new \RuntimeException('shop.error.cart_not_open');
        }
    }

    private function emitUpdated(Cart $cart): void
    {
        $payload = $this->serialize($cart);
        $this->events->emit('cart.updated', ['type' => 'cart', 'id' => $cart->id()], $payload);
    }
}
