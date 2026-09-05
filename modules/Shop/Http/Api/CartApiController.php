<?php

declare(strict_types=1);

namespace Modules\Shop\Http\Api;

use Gereq\Api\ApiResponse;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Validation\ValidationRegistry;
use Modules\Shop\Application\CartService;
use Modules\Shop\Domain\Cart;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

final class CartApiController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly CartService $carts,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function show(Request $request): JsonResponse
    {
        try {
            $cart = $this->resolveCart($request);
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error('validation_error', $e->getMessage(), [], 422);
        }

        return ApiResponse::data($this->carts->serialize($cart));
    }

    public function store(Request $request): JsonResponse
    {
        $body = $this->body($request);
        try {
            $cart = $this->carts->resolveOrCreate(
                isset($body['customer_id']) ? (string) $body['customer_id'] : $this->queryCustomer($request),
                isset($body['session_key']) ? (string) $body['session_key'] : $this->querySession($request),
                isset($body['currency']) ? (string) $body['currency'] : null,
            );
        } catch (\Throwable $e) {
            return ApiResponse::error('error', $e->getMessage(), [], 422);
        }

        return ApiResponse::data($this->carts->serialize($cart), 201);
    }

    public function addItem(Request $request): JsonResponse
    {
        $data = $this->validateBody($request, 'shop.cart.item.add');
        try {
            $cart = $this->resolveCart($request, requireOpen: true);
            $item = $this->carts->addItem($cart, trim((string) $data['product_id']), (string) ($data['qty'] ?? '1'));
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return ApiResponse::error('validation_error', $e->getMessage(), [], 422);
        }

        return ApiResponse::data([
            'item' => $this->carts->serializeItem($item),
            'cart' => $this->carts->serialize($cart),
        ], 201);
    }

    public function updateItem(Request $request, string $id): JsonResponse
    {
        $data = $this->validateBody($request, 'shop.cart.item.update');
        try {
            $cart = $this->resolveCart($request, requireOpen: true);
            $item = $this->carts->updateItem($cart, $id, (string) $data['qty']);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return ApiResponse::error('validation_error', $e->getMessage(), [], 422);
        }

        return ApiResponse::data([
            'item' => $item !== null ? $this->carts->serializeItem($item) : null,
            'removed' => $item === null,
            'cart' => $this->carts->serialize($cart),
        ]);
    }

    public function removeItem(Request $request, string $id): JsonResponse
    {
        try {
            $cart = $this->resolveCart($request, requireOpen: true);
            $this->carts->removeItem($cart, $id);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return ApiResponse::error('validation_error', $e->getMessage(), [], 422);
        }

        return ApiResponse::data([
            'id' => $id,
            'deleted' => true,
            'cart' => $this->carts->serialize($cart),
        ]);
    }

    private function resolveCart(Request $request, bool $requireOpen = false): Cart
    {
        $body = $this->body($request);
        $cartId = (string) ($request->query->get('cart_id') ?: ($body['cart_id'] ?? ''));
        if ($cartId !== '') {
            $cart = $this->carts->find($cartId);
            if ($cart === null) {
                throw new \InvalidArgumentException('shop.error.cart_not_found');
            }
            if ($requireOpen && !$cart->isOpen()) {
                throw new \RuntimeException('shop.error.cart_not_open');
            }

            return $cart;
        }

        $cart = $this->carts->resolveOrCreate(
            $this->queryCustomer($request) ?: (isset($body['customer_id']) ? (string) $body['customer_id'] : null),
            $this->querySession($request) ?: (isset($body['session_key']) ? (string) $body['session_key'] : null),
            isset($body['currency']) ? (string) $body['currency'] : null,
        );
        if ($requireOpen && !$cart->isOpen()) {
            throw new \RuntimeException('shop.error.cart_not_open');
        }

        return $cart;
    }

    private function queryCustomer(Request $request): ?string
    {
        $v = trim((string) $request->query->get('customer_id', ''));

        return $v !== '' ? $v : null;
    }

    private function querySession(Request $request): ?string
    {
        $v = trim((string) ($request->headers->get('X-Session-Key') ?: $request->query->get('session_key', '')));

        return $v !== '' ? $v : null;
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
