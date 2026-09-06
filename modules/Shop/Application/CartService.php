<?php

declare(strict_types=1);

namespace Modules\Shop\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Event\EventBus;
use Gereq\Module\CapabilityRegistry;
use Gereq\Setting\Settings;
use Modules\Currency\Application\CurrencyBuilder;
use Modules\Products\Application\ProductPersonalizationService;
use Modules\Products\Application\ProductVariantOptionsService;
use Modules\Products\Domain\Product;
use Modules\Products\Domain\ProductImage;
use Modules\Products\Domain\ProductPrice;
use Modules\Products\Domain\ProductVariant;
use Modules\Shop\Domain\Cart;
use Modules\Shop\Domain\CartItem;

final class CartService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EventBus $events,
        private readonly Settings $settings,
        private readonly ProductPersonalizationService $personalization,
        private readonly ProductVariantOptionsService $variantOptions,
        private readonly CapabilityRegistry $capabilities,
        private readonly CurrencyBuilder $money,
    ) {
    }

    public function resolveOrCreate(?string $customerId = null, ?string $sessionKey = null, ?string $currency = null): Cart
    {
        $customerId = $customerId !== null && $customerId !== '' ? $customerId : null;
        $sessionKey = $sessionKey !== null && $sessionKey !== '' ? $sessionKey : null;
        $currency = $this->money->code($currency);

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
        return $this->itemsByCartIds([$cartId])[$cartId] ?? [];
    }

    /**
     * @param list<string> $cartIds
     * @return array<string, list<CartItem>>
     */
    public function itemsByCartIds(array $cartIds): array
    {
        $cartIds = array_values(array_unique(array_filter(
            $cartIds,
            static fn (string $id): bool => $id !== '',
        )));
        if ($cartIds === []) {
            return [];
        }

        /** @var list<CartItem> $rows */
        $rows = $this->em->getRepository(CartItem::class)->createQueryBuilder('i')
            ->where('i.cartId IN (:ids)')
            ->setParameter('ids', $cartIds)
            ->orderBy('i.title', 'ASC')
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($cartIds as $id) {
            $map[$id] = [];
        }
        foreach ($rows as $item) {
            $map[$item->cartId()][] = $item;
        }

        return $map;
    }

    /**
     * @param list<string> $uploadIds
     */
    public function addItem(
        Cart $cart,
        string $productId,
        string $qty = '1',
        ?string $note = null,
        array $uploadIds = [],
        ?string $variantId = null,
    ): CartItem {
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

        $variantId = $variantId !== null ? trim($variantId) : '';
        $variant = $this->resolveVariant($productId, $variantId !== '' ? $variantId : null);

        $note = $note !== null ? trim($note) : null;
        $uploadIds = array_values(array_filter(array_map('strval', $uploadIds)));
        $this->personalization->assertLinePersonalization($product, $note, $uploadIds);
        $personalized = ($note !== null && $note !== '') || $uploadIds !== [];

        $resolvedVariantId = $variant?->id() ?? '';
        $title = $product->name();
        if ($variant !== null && $variant->name()) {
            $title .= ' — ' . $variant->name();
        }
        $sku = $variant?->sku() ?? $product->sku();
        $unitPrice = $this->resolveLinePrice($product, $variant, $cart->currency());

        if (!$personalized) {
            $existing = $this->em->getRepository(CartItem::class)->findOneBy([
                'cartId' => $cart->id(),
                'productId' => $productId,
                'variantId' => $resolvedVariantId,
            ]);
            if ($existing instanceof CartItem && !$existing->isPersonalized()) {
                $existing->setQty((string) ((float) $existing->qty() + $qtyFloat));
                $existing->setTitle($title);
                $existing->setSku($sku);
                $existing->setUnitPrice($unitPrice);
                $this->em->flush();
                $this->emitUpdated($cart);

                return $existing;
            }
        }

        $item = new CartItem(
            $cart->id(),
            $productId,
            $title,
            $sku,
            (string) $qtyFloat,
            $unitPrice,
            $cart->currency(),
            $resolvedVariantId !== '' ? $resolvedVariantId : null,
        );
        $item->setItemNote($note);
        $item->setUploadIds($uploadIds);
        $this->em->persist($item);
        $this->em->flush();
        $this->emitUpdated($cart);

        return $item;
    }

    private function resolveVariant(string $productId, ?string $variantId): ?ProductVariant
    {
        $active = $this->em->getRepository(ProductVariant::class)->findBy([
            'productId' => $productId,
            'active' => true,
        ]);
        $active = array_values(array_filter(
            $active,
            static fn ($v): bool => $v instanceof ProductVariant,
        ));

        if ($active === []) {
            if ($variantId !== null && $variantId !== '') {
                throw new \InvalidArgumentException('shop.error.invalid_variant');
            }

            return null;
        }

        if ($variantId === null || $variantId === '') {
            throw new \InvalidArgumentException('shop.error.variant_required');
        }

        foreach ($active as $variant) {
            if ($variant->id() === $variantId) {
                return $variant;
            }
        }

        throw new \InvalidArgumentException('shop.error.invalid_variant');
    }

    public function updateItem(Cart $cart, string $itemId, string $qty, ?string $note = null, bool $updateNote = false): ?CartItem
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

        if ($updateNote) {
            $product = $this->em->find(Product::class, $item->productId());
            if (!$product instanceof Product) {
                throw new \InvalidArgumentException('shop.error.invalid_product');
            }
            $trimmed = $note !== null ? trim($note) : '';
            $this->personalization->assertLinePersonalization($product, $trimmed !== '' ? $trimmed : null, $item->uploadIds());
            $item->setItemNote($trimmed !== '' ? $trimmed : null);
        }

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
     * @param list<CartItem>|null $items
     * @return array{subtotal: string, shipping: string, payment_fee: string, discount: string, total: string, currency: string, item_count: int}
     */
    public function totals(
        Cart $cart,
        string $shippingPrice = '0',
        string $paymentFee = '0',
        ?array $items = null,
    ): array {
        return $this->totalsFromItems(
            $cart,
            $items ?? $this->items($cart->id()),
            $shippingPrice,
            $paymentFee,
        );
    }

    /**
     * @param list<Cart> $carts
     * @return array<string, array{subtotal: string, shipping: string, payment_fee: string, discount: string, total: string, currency: string, item_count: int}>
     */
    public function totalsMap(array $carts): array
    {
        $itemsByCart = $this->itemsByCartIds(array_map(
            static fn (Cart $cart): string => $cart->id(),
            $carts,
        ));
        $map = [];
        foreach ($carts as $cart) {
            $map[$cart->id()] = $this->totalsFromItems(
                $cart,
                $itemsByCart[$cart->id()] ?? [],
            );
        }

        return $map;
    }

    /**
     * @param list<CartItem> $items
     * @return array{subtotal: string, shipping: string, payment_fee: string, discount: string, total: string, currency: string, item_count: int}
     */
    private function totalsFromItems(
        Cart $cart,
        array $items,
        string $shippingPrice = '0',
        string $paymentFee = '0',
    ): array {
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
        $lineItems = $this->items($cart->id());
        $totals = $this->totalsFromItems($cart, $lineItems);

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
            $payload['items'] = array_map(fn (CartItem $item): array => $this->serializeItem($item), $lineItems);
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    public function serializeItem(CartItem $item): array
    {
        $product = $this->em->find(Product::class, $item->productId());

        return [
            'id' => $item->id(),
            'cart_id' => $item->cartId(),
            'product_id' => $item->productId(),
            'variant_id' => $item->variantId(),
            'title' => $item->title(),
            'sku' => $item->sku(),
            'qty' => $item->qty(),
            'unit_price' => $item->unitPrice(),
            'currency' => $item->currency(),
            'line_total' => $item->lineTotal(),
            'note' => $item->itemNote(),
            'upload_ids' => $item->uploadIds(),
            'image_url' => $this->productImageUrl($item->productId()),
            'customer_note_mode' => $product instanceof Product ? $product->customerNoteMode() : 'optional',
        ];
    }

    private function productImageUrl(string $productId): ?string
    {
        $images = $this->em->getRepository(ProductImage::class)->findBy(
            ['productId' => $productId],
            ['isPrimary' => 'DESC', 'sortOrder' => 'ASC'],
            1,
        );
        $img = $images[0] ?? null;

        return $img instanceof ProductImage ? '/media/' . $img->mediaAssetId() . '/file' : null;
    }

    private function resolveLinePrice(Product $product, ?ProductVariant $variant, string $currency): string
    {
        $base = $this->baseProductPrice($product->id(), $currency);
        $stockQty = null;
        if ($variant !== null) {
            $stockQty = $variant->stockQty();
            if ($variant->priceAmount() !== null) {
                $base = number_format((float) $variant->priceAmount(), 2, '.', '');
            } else {
                $base = $this->variantOptions->applyAttributePriceAdjust($product, $variant->attributes(), $base);
            }
        }

        return $this->applyCatalogDiscount($product->id(), $base, $stockQty);
    }

    private function resolvePrice(string $productId, string $currency): string
    {
        return $this->applyCatalogDiscount($productId, $this->baseProductPrice($productId, $currency));
    }

    private function baseProductPrice(string $productId, string $currency): string
    {
        $prices = $this->em->getRepository(ProductPrice::class)->findBy(
            ['productId' => $productId, 'currencyCode' => strtoupper($currency)],
            ['validFrom' => 'DESC'],
        );
        $now = new \DateTimeImmutable('today');
        $base = '0.00';
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

            $base = number_format((float) $price->amount(), 2, '.', '');
            break;
        }

        return $base;
    }

    private function applyCatalogDiscount(string $productId, string $basePrice, ?int $stockQty = null): string
    {
        if (
            !$this->capabilities->has('product_discounts')
            || !class_exists(\Modules\ProductDiscounts\Application\ProductDiscountsProvider::class)
        ) {
            return number_format((float) $basePrice, 2, '.', '');
        }
        /** @var \Modules\ProductDiscounts\Application\ProductDiscountsProvider $provider */
        $provider = $this->capabilities->get('product_discounts');
        $priced = $provider->priceForProduct($productId, $basePrice, null, $stockQty);

        return $priced['price'];
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
