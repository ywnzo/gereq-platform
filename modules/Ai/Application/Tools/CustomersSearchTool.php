<?php

declare(strict_types=1);

namespace Modules\Ai\Application\Tools;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Ai\Application\Contracts\AiTool;
use Modules\Ai\Application\Dto\AiActor;
use Modules\Ai\Application\Dto\AiToolResult;
use Modules\Customers\Domain\Customer;

final class CustomersSearchTool implements AiTool
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function name(): string
    {
        return 'customers.search';
    }

    public function description(): string
    {
        return 'Search customers by name, email or phone.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20],
            ],
            'required' => ['query'],
        ];
    }

    public function permission(): ?string
    {
        return 'customers.view';
    }

    public function surfaces(): array
    {
        return ['admin'];
    }

    public function invoke(array $args, AiActor $actor): AiToolResult
    {
        if (!class_exists(Customer::class)) {
            return AiToolResult::fail('Customers module not available');
        }
        $q = trim((string) ($args['query'] ?? ''));
        $limit = min(20, max(1, (int) ($args['limit'] ?? 10)));
        $qb = $this->em->getRepository(Customer::class)->createQueryBuilder('c');
        if ($q !== '') {
            $qb->andWhere('LOWER(c.name) LIKE :q OR LOWER(c.email) LIKE :q OR c.phone LIKE :q')
                ->setParameter('q', '%' . mb_strtolower($q) . '%');
        }
        $items = $qb->setMaxResults($limit)->getQuery()->getResult();
        $out = [];
        foreach ($items as $c) {
            if (!$c instanceof Customer) {
                continue;
            }
            $out[] = [
                'id' => $c->id(),
                'name' => method_exists($c, 'name') ? $c->name() : null,
                'email' => method_exists($c, 'email') ? $c->email() : null,
                'phone' => method_exists($c, 'phone') ? $c->phone() : null,
            ];
        }

        return AiToolResult::ok($out);
    }
}
