<?php

declare(strict_types=1);

namespace Modules\Reviews\Http\Public;

use Gereq\Api\ApiResponse;
use Gereq\Http\Controller;
use Gereq\Http\PublicCors;
use Gereq\Http\Router;
use Modules\Reviews\Application\ReviewsProvider;
use Modules\Reviews\Domain\ProductReview;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/** Public storefront API (no Bearer token) for testimonials. */
final class PublicReviewsController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly ReviewsProvider $provider,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function options(Request $request): Response
    {
        return $this->cors(new Response('', 204), $request);
    }

    public function index(Request $request): Response
    {
        $productId = trim((string) $request->query->get('product_id', ProductReview::SITE_PRODUCT_ID));
        if ($productId === '*' || $productId === 'all') {
            $productId = null;
        }
        $locale = trim((string) $request->query->get('locale', ''));
        $limit = (int) $request->query->get('limit', 12);

        $items = $this->provider->approved(
            $productId,
            $limit,
            $locale !== '' ? $locale : null,
        );
        $avg = $this->provider->averageRating($productId);

        return $this->cors(ApiResponse::collection(
            array_map(fn (ProductReview $r): array => $this->provider->serializePublic($r), $items),
            [
                'total' => count($items),
                'average_rating' => $avg,
                'product_id' => $productId ?? 'all',
            ],
        ), $request);
    }

    private function cors(Response $response, Request $request): Response
    {
        return PublicCors::apply($response, $request, 'GET, OPTIONS');
    }
}
