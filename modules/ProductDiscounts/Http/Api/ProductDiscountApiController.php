<?php

declare(strict_types=1);

namespace Modules\ProductDiscounts\Http\Api;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\ApiResponse;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Validation\ValidationRegistry;
use Modules\ProductDiscounts\Application\ProductDiscountsProvider;
use Modules\ProductDiscounts\Domain\ProductDiscount;
use Modules\Products\Domain\Product;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

final class ProductDiscountApiController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly ProductDiscountsProvider $discounts,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(): JsonResponse
    {
        $items = $this->em->getRepository(ProductDiscount::class)->findBy([], ['updatedAt' => 'DESC']);

        return ApiResponse::collection(array_map(
            fn (ProductDiscount $d): array => $this->discounts->serialize($d) ?? [],
            array_values(array_filter($items, static fn ($d): bool => $d instanceof ProductDiscount)),
        ));
    }

    public function showForProduct(string $productId): JsonResponse
    {
        $product = $this->em->find(Product::class, $productId);
        if (!$product instanceof Product) {
            return ApiResponse::notFound();
        }
        $payload = $this->discounts->serialize($this->discounts->findForProduct($productId));

        return ApiResponse::data($payload ?? ['product_id' => $productId, 'active' => false]);
    }

    public function upsertForProduct(Request $request, string $productId): JsonResponse
    {
        $product = $this->em->find(Product::class, $productId);
        if (!$product instanceof Product) {
            return ApiResponse::notFound();
        }
        $data = gereq()->make(ValidationRegistry::class)->validate('product_discounts.save', $this->body($request));
        $discount = $this->discounts->upsert($productId, [
            'kind' => (string) ($data['kind'] ?? 'percent'),
            'value' => (string) ($data['value'] ?? '0'),
            'description' => isset($data['description']) ? (string) $data['description'] : null,
            'starts_at' => $this->parseDate($data['starts_at'] ?? null),
            'ends_at' => $this->parseDate($data['ends_at'] ?? null),
            'active' => !isset($data['active']) || $data['active'] === true || $data['active'] === '1' || $data['active'] === 1,
        ]);

        return ApiResponse::data($this->discounts->serialize($discount) ?? []);
    }

    public function destroyForProduct(string $productId): JsonResponse
    {
        $product = $this->em->find(Product::class, $productId);
        if (!$product instanceof Product) {
            return ApiResponse::notFound();
        }
        $this->discounts->deleteForProduct($productId);

        return ApiResponse::data(['deleted' => true, 'product_id' => $productId]);
    }

    private function parseDate(mixed $value): ?\DateTimeImmutable
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($raw);
        } catch (\Throwable) {
            return null;
        }
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
