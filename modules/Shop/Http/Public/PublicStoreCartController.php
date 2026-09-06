<?php

declare(strict_types=1);

namespace Modules\Shop\Http\Public;

use Gereq\Api\ApiResponse;
use Gereq\Http\Controller;
use Gereq\Http\PublicCors;
use Gereq\Http\Router;
use Modules\Shop\Application\CartService;
use Modules\Shop\Application\CheckoutService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/** Public cart + checkout for Vue storefront (session_key, no Bearer). */
final class PublicStoreCartController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly CartService $carts,
        private readonly CheckoutService $checkout,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function options(): Response
    {
        return $this->cors(new Response('', 204), 'GET, POST, PATCH, DELETE, OPTIONS');
    }

    public function show(Request $request): JsonResponse
    {
        try {
            $cart = $this->carts->resolveOrCreate(null, $this->sessionKey($request));
        } catch (\Throwable $e) {
            return $this->cors(ApiResponse::error('error', $e->getMessage(), [], 422));
        }

        return $this->cors(ApiResponse::data($this->storeCart($cart)));
    }

    public function store(Request $request): JsonResponse
    {
        $body = $this->body($request);
        try {
            $cart = $this->carts->resolveOrCreate(
                null,
                isset($body['session_key']) ? (string) $body['session_key'] : $this->sessionKey($request),
                isset($body['currency']) ? (string) $body['currency'] : null,
            );
        } catch (\Throwable $e) {
            return $this->cors(ApiResponse::error('error', $e->getMessage(), [], 422));
        }

        return $this->cors(ApiResponse::data($this->storeCart($cart), 201));
    }

    public function addItem(Request $request): JsonResponse
    {
        $body = $this->body($request);
        $productId = trim((string) ($body['product_id'] ?? ''));
        $qty = (string) ($body['qty'] ?? '1');
        $note = isset($body['note']) ? trim((string) $body['note']) : null;
        $variantId = isset($body['variant_id']) ? trim((string) $body['variant_id']) : null;
        $uploadIds = $body['upload_ids'] ?? [];
        if (!is_array($uploadIds)) {
            $uploadIds = [];
        }
        if ($productId === '') {
            return $this->cors(ApiResponse::error('validation_error', 'product_id required', [], 422));
        }
        try {
            $cart = $this->carts->resolveOrCreate(null, $this->sessionKey($request, $body));
            $this->carts->addItem(
                $cart,
                $productId,
                $qty,
                $note,
                array_map('strval', $uploadIds),
                $variantId !== '' ? $variantId : null,
            );
        } catch (\Throwable $e) {
            $key = $e->getMessage();
            $message = str_starts_with($key, 'products.') || str_starts_with($key, 'shop.')
                ? $this->translator->trans($key)
                : $key;

            return $this->cors(ApiResponse::error('validation_error', $message, [], 422));
        }

        return $this->cors(ApiResponse::data($this->storeCart($cart), 201));
    }

    public function updateItem(Request $request, string $id): JsonResponse
    {
        $body = $this->body($request);
        try {
            $cart = $this->carts->resolveOrCreate(null, $this->sessionKey($request, $body));
            $updateNote = array_key_exists('note', $body);
            $this->carts->updateItem(
                $cart,
                $id,
                (string) ($body['qty'] ?? '0'),
                $updateNote ? trim((string) ($body['note'] ?? '')) : null,
                $updateNote,
            );
        } catch (\Throwable $e) {
            $key = $e->getMessage();
            $message = str_starts_with($key, 'products.') || str_starts_with($key, 'shop.')
                ? $this->translator->trans($key)
                : $key;

            return $this->cors(ApiResponse::error('validation_error', $message, [], 422));
        }

        return $this->cors(ApiResponse::data($this->storeCart($cart)));
    }

    public function removeItem(Request $request, string $id): JsonResponse
    {
        try {
            $cart = $this->carts->resolveOrCreate(null, $this->sessionKey($request));
            $this->carts->removeItem($cart, $id);
        } catch (\Throwable $e) {
            return $this->cors(ApiResponse::error('validation_error', $e->getMessage(), [], 422));
        }

        return $this->cors(ApiResponse::data($this->storeCart($cart)));
    }

    public function checkout(Request $request): JsonResponse
    {
        $body = $this->body($request);
        $cartId = trim((string) ($body['cart_id'] ?? ''));
        if ($cartId === '') {
            return $this->cors(ApiResponse::error('validation_error', 'cart_id required', [], 422));
        }
        $sessionKey = $this->sessionKey($request, $body);
        if ($sessionKey === null || $sessionKey === '') {
            return $this->cors(ApiResponse::error('unauthorized', 'session_key required', [], 401));
        }
        $cart = $this->carts->find($cartId);
        if ($cart === null) {
            return $this->cors(ApiResponse::notFound());
        }
        $cartSession = $cart->sessionKey();
        if ($cartSession === null || $cartSession === '' || !hash_equals($cartSession, $sessionKey)) {
            return $this->cors(ApiResponse::notFound());
        }

        $customer = is_array($body['customer'] ?? null) ? $body['customer'] : [];
        $shipping = is_array($body['shipping'] ?? null) ? $body['shipping'] : [];
        $name = trim((string) ($customer['name'] ?? ''));
        $email = trim((string) ($customer['email'] ?? ''));
        $phone = trim((string) ($customer['phone'] ?? ''));
        $addressLine = trim((string) ($customer['address'] ?? $shipping['address'] ?? ''));

        $address = [
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'street' => $addressLine,
            'city' => '',
            'zip' => '',
            'country' => 'CZ',
        ];

        try {
            $result = $this->checkout->checkout(
                $cart,
                isset($shipping['method']) ? (string) $shipping['method'] : 'PICKUP',
                null,
                isset($body['note']) ? trim((string) $body['note']) : null,
                null,
                isset($body['payment_method']) ? (string) $body['payment_method'] : null,
                null,
                $address,
                $address,
                null,
                null,
                $email !== '' ? $email : null,
                $name !== '' ? $name : null,
            );
        } catch (\Throwable $e) {
            $key = $e->getMessage();
            $message = str_starts_with($key, 'shop.') ? $this->translator->trans($key) : $key;

            return $this->cors(ApiResponse::error('checkout_error', $message, [], 422));
        }

        return $this->cors(ApiResponse::data([
            'order_id' => $result['order_id'],
            'order_number' => $result['order_number'],
            'access_token' => $result['access_token'] ?? null,
        ], 201), 'GET, POST, PATCH, DELETE, OPTIONS');
    }

    /** @return array<string, mixed> */
    private function storeCart(\Modules\Shop\Domain\Cart $cart): array
    {
        $raw = $this->carts->serialize($cart);
        $items = [];
        foreach ($raw['items'] ?? [] as $item) {
            $items[] = [
                'id' => $item['id'],
                'product_id' => $item['product_id'],
                'variant_id' => $item['variant_id'] ?? null,
                'name' => $item['title'] ?? $item['sku'] ?? '',
                'sku' => $item['sku'] ?? null,
                'qty' => (float) ($item['qty'] ?? 0),
                'unit_price' => $item['unit_price'],
                'line_total' => $item['line_total'],
                'note' => $item['note'] ?? null,
                'upload_ids' => $item['upload_ids'] ?? [],
                'image_url' => $item['image_url'] ?? null,
                'customer_note_mode' => $item['customer_note_mode'] ?? 'optional',
            ];
        }

        return [
            'id' => $raw['id'],
            'currency' => $raw['currency'],
            'items' => $items,
            'totals' => [
                'items' => $raw['totals']['subtotal'] ?? '0',
                'grand' => $raw['totals']['total'] ?? '0',
            ],
        ];
    }

    /** @param array<string, mixed>|null $body */
    private function sessionKey(Request $request, ?array $body = null): ?string
    {
        $fromBody = is_array($body) ? trim((string) ($body['session_key'] ?? '')) : '';
        if ($fromBody !== '') {
            return $fromBody;
        }
        $fromQuery = trim((string) $request->query->get('session_key', ''));

        return $fromQuery !== '' ? $fromQuery : null;
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

    private function cors(Response $response, string $methods = 'GET, POST, PATCH, DELETE, OPTIONS'): Response
    {
        return PublicCors::apply($response, $this->request(), $methods);
    }
}
