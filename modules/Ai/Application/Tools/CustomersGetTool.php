<?php

declare(strict_types=1);

namespace Modules\Ai\Application\Tools;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Ai\Application\Contracts\AiTool;
use Modules\Ai\Application\Dto\AiActor;
use Modules\Ai\Application\Dto\AiToolResult;
use Modules\Customers\Domain\Customer;

final class CustomersGetTool implements AiTool
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function name(): string
    {
        return 'customers.get';
    }

    public function description(): string
    {
        return 'Get a customer by id.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'string']],
            'required' => ['id'],
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
        $c = $this->em->find(Customer::class, (string) ($args['id'] ?? ''));
        if (!$c instanceof Customer) {
            return AiToolResult::fail('Customer not found');
        }

        return AiToolResult::ok([
            'id' => $c->id(),
            'name' => method_exists($c, 'name') ? $c->name() : null,
            'email' => method_exists($c, 'email') ? $c->email() : null,
            'phone' => method_exists($c, 'phone') ? $c->phone() : null,
        ]);
    }
}
