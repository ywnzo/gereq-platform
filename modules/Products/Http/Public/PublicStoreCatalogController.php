<?php

declare(strict_types=1);

namespace Modules\Products\Http\Public;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\ApiResponse;
use Gereq\Http\Controller;
use Gereq\Http\PublicCors;
use Gereq\Http\Router;
use Modules\Products\Domain\Product;
use Modules\Products\Domain\ProductCategory;
use Modules\Products\Domain\ProductImage;
use Modules\Products\Domain\ProductPrice;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/** Public catalog API for Vue storefront (no Bearer token). */
final class PublicStoreCatalogController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function options(): Response
    {
        return $this->cors(new Response('', 204), 'GET, OPTIONS');
    }

    public function categories(Request $request): JsonResponse
    {
        $items = $this->em->getRepository(ProductCategory::class)->findBy([], ['name' => 'ASC']);

        return $this->cors(ApiResponse::collection(array_map(
            static fn (ProductCategory $c): array => [
                'id' => $c->id(),
                'name' => $c->name(),
                'slug' => $c->slug(),
            ],
            $items,
        )));
    }

    public function index(Request $request): JsonResponse
    {
        $qb = $this->em->getRepository(Product::class)->createQueryBuilder('p')
            ->andWhere('p.status = :status')
            ->setParameter('status', 'active')
            ->orderBy('p.name', 'ASC');

        $category = trim((string) $request->query->get('category', ''));
        if ($category !== '') {
            $qb->innerJoin('p.categories', 'c')
                ->andWhere('c.slug = :cat OR c.id = :catId')
                ->setParameter('cat', $category)
                ->setParameter('catId', $category);
        }

        $q = trim((string) $request->query->get('q', ''));
        if ($q !== '') {
            self_repair_search($qb, 'p', ['name', 'sku', 'description'], $q);
        }

        $limit = max(1, min(100, (int) $request->query->get('limit', 48)));
        /** @var list<Product> $products */
        $products = $qb->setMaxResults($limit)->getQuery()->getResult();

        return $this->cors(ApiResponse::collection(
            array_map(fn (Product $p): array => $this->serializeStore($p), $products),
            ['total' => count($products)],
        ));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $product = $this->em->find(Product::class, $id);
        if (!$product instanceof Product || $product->status() !== 'active') {
            $bySku = $this->em->getRepository(Product::class)->findOneBy(['sku' => strtoupper($id)]);
            $product = $bySku instanceof Product && $bySku->status() === 'active' ? $bySku : null;
        }
        if ($product === null) {
            return $this->cors(ApiResponse::notFound());
        }

        return $this->cors(ApiResponse::data($this->serializeStore($product, true)));
    }

    /** @return array<string, mixed> */
    private function serializeStore(Product $product, bool $detail = false): array
    {
        $price = $this->currentPrice($product->id(), 'CZK');
        $image = $this->primaryImageUrl($product->id());

        $row = [
            'id' => $product->id(),
            'sku' => $product->sku(),
            'name' => $product->name(),
            'description' => $product->description() ?? '',
            'unit' => $product->unit(),
            'price' => [
                'amount' => $price,
                'currency_code' => 'CZK',
            ],
            'image_url' => $image,
            'categories' => array_map(
                static fn (ProductCategory $c): array => [
                    'id' => $c->id(),
                    'name' => $c->name(),
                    'slug' => $c->slug(),
                ],
                $product->categories()->toArray(),
            ),
        ];

        if ($detail) {
            $row['images'] = $this->allImageUrls($product->id());
        }

        return $row;
    }

    private function currentPrice(string $productId, string $currency): string
    {
        $prices = $this->em->getRepository(ProductPrice::class)->findBy(
            ['productId' => $productId, 'currencyCode' => $currency],
            ['validFrom' => 'DESC'],
        );
        $now = new \DateTimeImmutable('today');
        foreach ($prices as $price) {
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

    private function primaryImageUrl(string $productId): ?string
    {
        $images = $this->em->getRepository(ProductImage::class)->findBy(
            ['productId' => $productId],
            ['isPrimary' => 'DESC', 'sortOrder' => 'ASC'],
            1,
        );
        $img = $images[0] ?? null;

        return $img instanceof ProductImage ? '/media/' . $img->mediaAssetId() . '/file' : null;
    }

    /** @return list<array{url: string, alt: ?string, is_primary: bool}> */
    private function allImageUrls(string $productId): array
    {
        $images = $this->em->getRepository(ProductImage::class)->findBy(
            ['productId' => $productId],
            ['isPrimary' => 'DESC', 'sortOrder' => 'ASC'],
        );
        $out = [];
        foreach ($images as $image) {
            $out[] = [
                'url' => '/media/' . $image->mediaAssetId() . '/file',
                'alt' => $image->alt(),
                'is_primary' => $image->isPrimary(),
            ];
        }

        return $out;
    }

    private function cors(Response $response, string $methods = 'GET, OPTIONS'): Response
    {
        return PublicCors::apply($response, $this->request(), $methods);
    }
}
