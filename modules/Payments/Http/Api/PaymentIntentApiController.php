<?php

declare(strict_types=1);

namespace Modules\Payments\Http\Api;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\ApiResponse;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Modules\Payments\Application\PaymentService;
use Modules\Payments\Domain\PaymentIntent;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

final class PaymentIntentApiController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly PaymentService $payments,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function show(string $id): JsonResponse
    {
        $intent = $this->em->find(PaymentIntent::class, $id);
        if (!$intent instanceof PaymentIntent) {
            return ApiResponse::notFound();
        }

        return ApiResponse::data($this->payments->serialize($intent));
    }

    public function markPaid(string $id): JsonResponse
    {
        $intent = $this->em->find(PaymentIntent::class, $id);
        if (!$intent instanceof PaymentIntent) {
            return ApiResponse::notFound();
        }
        $this->payments->markPaid($intent);

        return ApiResponse::data($this->payments->serialize($intent));
    }

    public function forOrder(string $orderId): JsonResponse
    {
        $items = $this->em->getRepository(PaymentIntent::class)->findBy(
            ['orderId' => $orderId],
            ['createdAt' => 'DESC'],
            20,
        );

        return ApiResponse::data(array_map(
            fn (PaymentIntent $i): array => $this->payments->serialize($i),
            $items,
        ));
    }
}
