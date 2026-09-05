<?php

declare(strict_types=1);

namespace Modules\Shop\Http\Public;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\ApiResponse;
use Gereq\Http\Controller;
use Gereq\Http\PublicCors;
use Gereq\Http\Router;
use Modules\Shop\Domain\ShippingMethod;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/** Public shipping methods for Vue storefront (no Bearer). */
final class PublicStoreShippingController extends Controller
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

    public function index(Request $request): JsonResponse
    {
        /** @var list<ShippingMethod> $methods */
        $methods = $this->em->getRepository(ShippingMethod::class)->createQueryBuilder('s')
            ->andWhere('s.active = true')
            ->andWhere('s.type != :parcel')
            ->setParameter('parcel', 'parcel_shop')
            ->orderBy('s.sortOrder', 'ASC')
            ->addOrderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->cors(ApiResponse::collection(array_map(
            static fn (ShippingMethod $m): array => [
                'code' => $m->code(),
                'name' => $m->name(),
                'type' => $m->type(),
                'description' => $m->description(),
                'price' => $m->price(),
                'currency' => $m->currency(),
                'estimated_days' => $m->estimatedDays(),
            ],
            $methods,
        )));
    }

    private function cors(Response $response, string $methods = 'GET, OPTIONS'): Response
    {
        return PublicCors::apply($response, $this->request(), $methods);
    }
}
