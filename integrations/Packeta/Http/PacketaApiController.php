<?php

declare(strict_types=1);

namespace Integrations\Packeta\Http;

use Gereq\Api\ApiResponse;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Integrations\Packeta\Application\PacketaCarrier;
use Modules\Shop\Application\ShippingCarrierRegistry;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

/** Public Packeta widget config for any storefront. */
final class PacketaApiController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly ShippingCarrierRegistry $carriers,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function widget(Request $request): JsonResponse
    {
        $carrier = $this->carriers->get('packeta') ?? $this->carriers->get('zasilkovna');
        if (!$carrier instanceof PacketaCarrier) {
            return ApiResponse::error('not_configured', 'Packeta carrier not registered', [], 404);
        }
        $country = (string) $request->query->get('country', 'cz');

        return ApiResponse::data($carrier->widgetConfig($country));
    }
}
