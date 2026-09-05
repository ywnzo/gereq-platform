<?php

declare(strict_types=1);

namespace Modules\Shop\Http\Api;

use Gereq\Api\ApiResponse;
use Gereq\Api\IdempotencyService;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Validation\ValidationRegistry;
use Modules\Shop\Application\CartService;
use Modules\Shop\Application\CheckoutService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

final class CheckoutApiController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly CartService $carts,
        private readonly CheckoutService $checkout,
        private readonly IdempotencyService $idempotency,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function store(Request $request): JsonResponse
    {
        $idempotencyKey = trim((string) $request->headers->get('Idempotency-Key', ''));
        if ($idempotencyKey !== '') {
            $existing = $this->idempotency->find($idempotencyKey);
            if ($existing !== null) {
                return $this->idempotency->replay($existing);
            }
        }

        $data = $this->validateBody($request, 'shop.checkout');
        $body = $this->body($request);

        $cartId = trim((string) ($data['cart_id'] ?? ''));
        if ($cartId === '') {
            return ApiResponse::error('validation_error', 'cart_id required', ['cart_id' => 'required'], 422);
        }

        $cart = $this->carts->find($cartId);
        if ($cart === null) {
            return ApiResponse::notFound('not_found', 'Cart not found');
        }

        $billing = $data['billing_address'] ?? $body['billing_address'] ?? null;
        $shippingAddr = $data['shipping_address'] ?? $body['shipping_address'] ?? null;

        try {
            $result = $this->checkout->checkout(
                $cart,
                isset($data['shipping_method']) ? (string) $data['shipping_method'] : null,
                isset($data['customer_id']) ? (string) $data['customer_id'] : null,
                isset($data['note']) ? trim((string) $data['note']) : null,
                isset($data['warehouse_id']) ? (string) $data['warehouse_id'] : (isset($body['warehouse_id']) ? (string) $body['warehouse_id'] : null),
                isset($data['payment_method']) ? (string) $data['payment_method'] : null,
                isset($data['coupon_code']) ? (string) $data['coupon_code'] : null,
                is_array($billing) ? $billing : null,
                is_array($shippingAddr) ? $shippingAddr : null,
                isset($data['parcel_point_id']) ? (string) $data['parcel_point_id'] : null,
                isset($data['parcel_point_label']) ? (string) $data['parcel_point_label'] : null,
                isset($data['customer_email']) ? (string) $data['customer_email'] : null,
                isset($data['customer_name']) ? (string) $data['customer_name'] : null,
            );
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return ApiResponse::error('checkout_error', $e->getMessage(), [], 422);
        }

        $payload = [
            'cart' => $this->carts->serialize($result['cart']),
            'order_id' => $result['order_id'],
            'order_number' => $result['order_number'],
            'access_token' => $result['access_token'],
            'mode' => $result['mode'],
            'totals' => $result['totals'],
            'shipping' => $result['shipping'],
            'payment' => $result['payment'],
            'payment_intent' => $result['payment_intent'],
            'coupon' => $result['coupon'],
            'idempotency_note' => 'Send Idempotency-Key header on POST /shop/checkout to safely retry.',
        ];

        $response = ApiResponse::data($payload, 201);
        if ($idempotencyKey !== '') {
            $this->idempotency->remember($idempotencyKey, $request, $response);
        }

        return $response;
    }

    /** @param array<string, mixed> $context @return array<string, mixed> */
    private function validateBody(Request $request, string $ruleset, array $context = []): array
    {
        return gereq()->make(ValidationRegistry::class)->validate($ruleset, $this->body($request), $context);
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        $data = $request->request->all();
        $raw = $request->getContent();
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return array_merge($data, $decoded);
            }
        }

        return $data;
    }
}
