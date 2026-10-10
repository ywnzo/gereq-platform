<?php

declare(strict_types=1);

namespace Gereq\Admin\Http\Api;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\ApiResponse;
use Gereq\Customers\Domain\Customer;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Support\AdminPath;
use Modules\Events\Domain\CatalogEvent;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

final class AdminDashboardApiController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function show(): JsonResponse
    {
        $eventsByStatus = [
            'pending' => 0,
            'published' => 0,
            'archived' => 0,
            'rejected' => 0,
        ];

        if (class_exists(CatalogEvent::class)) {
            $rows = $this->em->createQueryBuilder()
                ->select('e.status AS status, COUNT(e.id) AS total')
                ->from(CatalogEvent::class, 'e')
                ->groupBy('e.status')
                ->getQuery()
                ->getArrayResult();
            foreach ($rows as $row) {
                $status = (string) ($row['status'] ?? '');
                if (array_key_exists($status, $eventsByStatus)) {
                    $eventsByStatus[$status] = (int) $row['total'];
                }
            }
        }

        $customersTotal = 0;
        if (class_exists(Customer::class)) {
            $customersTotal = (int) $this->em->createQueryBuilder()
                ->select('COUNT(c.id)')
                ->from(Customer::class, 'c')
                ->getQuery()
                ->getSingleScalarResult();
        }

        return ApiResponse::data([
            'events' => [
                'by_status' => $eventsByStatus,
                'total' => array_sum($eventsByStatus),
            ],
            'customers' => [
                'total' => $customersTotal,
            ],
            'links' => [
                'platform_admin' => AdminPath::base(),
                'product_admin' => '/admin',
            ],
        ]);
    }
}
