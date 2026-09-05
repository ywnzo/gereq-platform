<?php

declare(strict_types=1);

namespace Modules\Customers\Http\Api;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\ApiResponse;
use Gereq\Http\Router;
use Modules\Customers\Domain\Customer;
use Modules\Customers\Domain\CustomerNote;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

final class CustomerActivityApiController extends AbstractCustomerApiController
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(string $id): JsonResponse
    {
        $customer = $this->em->find(Customer::class, $id);
        if (!$customer instanceof Customer) {
            return ApiResponse::notFound();
        }

        /** @var list<array<string, mixed>> $items */
        $items = [];

        /** @var list<CustomerNote> $notes */
        $notes = $this->em->getRepository(CustomerNote::class)->findBy(
            ['customerId' => $id],
            ['createdAt' => 'DESC'],
        );
        foreach ($notes as $note) {
            $items[] = [
                'source' => 'note',
                'id' => $note->id(),
                'type' => 'note',
                'subject' => null,
                'body' => $note->body(),
                'created_by_user_id' => $note->createdByUserId(),
                'created_at' => $note->createdAt()->format(\DateTimeInterface::ATOM),
                'updated_at' => $note->updatedAt()->format(\DateTimeInterface::ATOM),
            ];
        }

        if (class_exists(\Modules\CRM\Domain\Activity::class)) {
            /** @var list<object> $activities */
            $activities = $this->em->getRepository(\Modules\CRM\Domain\Activity::class)->findBy(
                ['customerId' => $id],
                ['createdAt' => 'DESC'],
            );
            foreach ($activities as $activity) {
                if (!method_exists($activity, 'id') || !method_exists($activity, 'createdAt')) {
                    continue;
                }
                /** @var \DateTimeImmutable $createdAt */
                $createdAt = $activity->createdAt();
                $updatedAt = method_exists($activity, 'updatedAt') ? $activity->updatedAt() : $createdAt;
                $items[] = [
                    'source' => 'crm_activity',
                    'id' => (string) $activity->id(),
                    'type' => method_exists($activity, 'type') ? (string) $activity->type() : 'activity',
                    'subject' => method_exists($activity, 'subject') ? (string) $activity->subject() : null,
                    'body' => method_exists($activity, 'body') ? $activity->body() : null,
                    'deal_id' => method_exists($activity, 'dealId') ? $activity->dealId() : null,
                    'completed_at' => method_exists($activity, 'completedAt') && $activity->completedAt() instanceof \DateTimeImmutable
                        ? $activity->completedAt()->format(\DateTimeInterface::ATOM)
                        : null,
                    'created_at' => $createdAt->format(\DateTimeInterface::ATOM),
                    'updated_at' => $updatedAt instanceof \DateTimeImmutable
                        ? $updatedAt->format(\DateTimeInterface::ATOM)
                        : $createdAt->format(\DateTimeInterface::ATOM),
                ];
            }
        }

        usort(
            $items,
            static fn (array $a, array $b): int => strcmp((string) $b['created_at'], (string) $a['created_at']),
        );

        return ApiResponse::collection($items, ['customer_id' => $id, 'total' => count($items)]);
    }
}
