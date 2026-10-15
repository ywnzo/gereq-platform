<?php

declare(strict_types=1);

namespace Gereq\Admin\Http;

use Gereq\Ares\AresException;
use Gereq\Ares\AresService;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

final class AresController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly AresService $ares,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function subjects(Request $request): JsonResponse
    {
        $q = trim((string) $request->query->get('q', ''));
        $limit = (int) $request->query->get('limit', 10);

        try {
            return $this->json([
                'data' => $this->ares->searchSubjects($q, $limit),
                'meta' => ['q' => $q],
            ]);
        } catch (AresException $e) {
            return $this->jsonError('ares_error', $e->getMessage(), [], $e->status());
        }
    }

    public function addresses(Request $request): JsonResponse
    {
        $q = trim((string) $request->query->get('q', ''));
        $city = trim((string) $request->query->get('city', ''));
        $limit = max(1, min(30, (int) $request->query->get('limit', 10)));
        $district = $city !== '' ? $city : null;

        try {
            $data = $this->ares->searchAddresses($q, $limit, $district);
        } catch (AresException $e) {
            return $this->jsonError('ares_error', $e->getMessage(), [], $e->status());
        }

        $districtFallback = false;
        if ($data === [] && $district !== null) {
            $districtFallback = true;
            try {
                $data = $this->ares->searchAddresses($q, $limit, null);
            } catch (AresException $e) {
                return $this->jsonError('ares_error', $e->getMessage(), [], $e->status());
            }
        }

        return $this->json([
            'data' => $data,
            'meta' => [
                'q' => $q,
                'city' => $district,
                'district_fallback' => $districtFallback,
            ],
        ]);
    }
}
