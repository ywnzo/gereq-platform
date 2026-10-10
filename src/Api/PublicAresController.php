<?php

declare(strict_types=1);

namespace Gereq\Api;

use Gereq\Ares\AresException;
use Gereq\Ares\AresService;
use Gereq\Http\Controller;
use Gereq\Http\PublicCors;
use Gereq\Http\Router;
use Gereq\Security\RateLimiter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/** Public ARES proxy (addresses) for the storefront — rate limited. */
final class PublicAresController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly AresService $ares,
        private readonly RateLimiter $limiter,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function options(): Response
    {
        return $this->cors(new Response('', 204));
    }

    public function addresses(Request $request): JsonResponse
    {
        if (!$this->limiter->hit('ares:public:' . $request->getClientIp(), 60, 60)) {
            return $this->cors(ApiResponse::error('rate_limited', 'Příliš mnoho požadavků na ARES.', [], 429));
        }

        $q = trim((string) $request->query->get('q', ''));
        $city = trim((string) $request->query->get('city', ''));
        $limit = (int) $request->query->get('limit', 8);

        try {
            return $this->cors(ApiResponse::collection(
                $this->ares->searchAddresses($q, $limit, $city !== '' ? $city : null),
                ['q' => $q, 'city' => $city !== '' ? $city : null],
            ));
        } catch (AresException $e) {
            $status = $e->status();
            if ($status < 400 || $status > 499) {
                $status = 422;
            }

            return $this->cors(ApiResponse::error('ares_error', $e->getMessage(), [], $status));
        }
    }

    /**
     * @template T of Response
     * @param T $response
     * @return T
     */
    private function cors(Response $response): Response
    {
        return PublicCors::apply($response, $this->request(), 'GET, OPTIONS');
    }
}
